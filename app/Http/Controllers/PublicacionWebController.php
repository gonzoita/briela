<?php

namespace App\Http\Controllers;

use App\Models\Ensamble;
use App\Models\Producto;
use App\Services\PublicacionWebService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Publicar y despublicar en el sitio web del cliente.
 *
 * Responde JSON, no una redirección de Inertia: el interruptor de la ficha y la
 * selección múltiple del listado no deberían recargar la pantalla para cambiar una
 * marca. Ver `PublicacionWebService` para la regla de negocio.
 */
class PublicacionWebController extends Controller
{
    public function __construct(private PublicacionWebService $publicacion) {}

    /** Un solo ítem, desde su ficha. */
    public function alternar(Request $request, string $tipo, int $id): JsonResponse
    {
        $datos = $request->validate(['publicar' => 'required|boolean']);

        $item = $this->buscar($tipo, $id);

        if (! $item) {
            return response()->json(['ok' => false, 'mensaje' => 'No existe ese ítem.'], 404);
        }

        if ($motivo = $this->publicacion->marcar($item, (bool) $datos['publicar'])) {
            return response()->json(['ok' => false, 'mensaje' => $motivo], 422);
        }

        $aviso = $this->publicacion->avisarAlSitio();

        return response()->json([
            'ok'               => true,
            'publicado_web'    => (bool) $item->publicado_web,
            'publicado_web_at' => $item->publicado_web_at?->toIso8601String(),
            'mensaje'          => ($datos['publicar'] ? 'Publicado. ' : 'Retirado de la web. ').$aviso['mensaje'],
        ]);
    }

    /**
     * Varios de una vez, desde el listado.
     *
     * Los que no se pueden publicar no tumban a los demás: se publica lo que se puede y
     * se devuelve la lista de los que quedaron fuera con su motivo. Cargar el catálogo la
     * primera vez son cincuenta ítems, y que el número cincuenta cancele los cuarenta y
     * nueve anteriores es la manera de no volver a usar el botón.
     */
    public function masivo(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'tipo'     => 'required|in:producto,ensamble',
            'ids'      => 'required|array|min:1',
            'ids.*'    => 'required|integer',
            'publicar' => 'required|boolean',
        ]);

        // La ruta pide `productos.editar` porque el middleware no sabe qué tipo viene en el
        // cuerpo. Para ensambles el permiso que corresponde es el suyo, y se comprueba aquí:
        // quien puede editar productos no necesariamente puede tocar ensambles.
        $permiso = $datos['tipo'] === 'ensamble' ? 'ensambles.editar' : 'productos.editar';

        if (! in_array($permiso, $request->user()->permisos(), true)) {
            return response()->json([
                'ok'      => false,
                'mensaje' => 'No tienes permiso para publicar '.($datos['tipo'] === 'ensamble' ? 'ensambles' : 'productos').'.',
            ], 403);
        }

        $publicar = (bool) $datos['publicar'];
        $hechos   = 0;
        $fallidos = [];

        foreach ($datos['ids'] as $id) {
            $item = $this->buscar($datos['tipo'], (int) $id);

            if (! $item) {
                continue;
            }

            if ($motivo = $this->publicacion->marcar($item, $publicar)) {
                $fallidos[] = ['id' => (int) $id, 'nombre' => $item->nombre, 'motivo' => $motivo];
                continue;
            }

            $hechos++;
        }

        $aviso = $hechos > 0
            ? $this->publicacion->avisarAlSitio()
            : ['avisado' => false, 'mensaje' => ''];

        return response()->json([
            'ok'       => true,
            'hechos'   => $hechos,
            'fallidos' => $fallidos,
            'mensaje'  => trim(($publicar ? "{$hechos} publicado(s). " : "{$hechos} retirado(s). ").$aviso['mensaje']),
        ]);
    }

    private function buscar(string $tipo, int $id): Producto|Ensamble|null
    {
        return $tipo === 'ensamble'
            ? Ensamble::find($id)
            : Producto::find($id);
    }
}
