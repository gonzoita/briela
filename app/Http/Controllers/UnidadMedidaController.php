<?php

namespace App\Http\Controllers;

use App\Models\UnidadMedida;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Las unidades de medida, administrables desde la interfaz.
 *
 * Responde JSON: se usa tanto desde la pantalla de Configuración como desde el «+» que
 * está al lado del selector en el formulario de producto, igual que las categorías. Nadie
 * debería tener que salir del producto que está creando para agregar «rollos».
 */
class UnidadMedidaController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            UnidadMedida::orderBy('orden')->orderBy('etiqueta')->get()
                ->map(fn (UnidadMedida $u) => array_merge($u->toArray(), [
                    'productos_count' => $u->productosQueLaUsan(),
                ]))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'etiqueta' => 'required|string|max:60',
            'clave'    => 'nullable|string|max:30',
            'tipo'     => 'required|in:producto,servicio,ambos',
        ]);

        // La clave se deriva de la etiqueta si no la dan: es lo que se guarda en el
        // producto y lo que se lee junto a una cantidad, así que va corta y sin espacios.
        // `nullable` no agrega la clave al arreglo si no vino en la petición: sin el `??`
        // esto avisa «Undefined array key» cada vez que alguien crea una unidad sin clave,
        // que es el caso normal.
        $clave = Str::slug(($datos['clave'] ?? '') ?: $datos['etiqueta'], '_');
        $clave = Str::limit($clave, 30, '');

        if ($clave === '') {
            return response()->json(['message' => 'Esa etiqueta no deja armar una clave. Escribe una clave a mano.'], 422);
        }

        if (UnidadMedida::where('clave', $clave)->exists()) {
            return response()->json([
                'message' => "Ya existe una unidad con la clave «{$clave}». Cambia la etiqueta o escribe otra clave.",
            ], 422);
        }

        $unidad = UnidadMedida::create([
            'clave'    => $clave,
            'etiqueta' => $datos['etiqueta'],
            'tipo'     => $datos['tipo'],
            'orden'    => (int) UnidadMedida::max('orden') + 1,
            'activo'   => true,
        ]);

        return response()->json($unidad, 201);
    }

    /**
     * Se cambian la etiqueta, el tipo y si está activa. **La clave no.**
     *
     * Los productos guardaron esa clave en texto: cambiarla los dejaría apuntando a una
     * unidad que no existe, y no hay llave foránea que avise. Es la misma decisión que en
     * las listas de segmentación.
     */
    public function update(Request $request, UnidadMedida $unidad): JsonResponse
    {
        $datos = $request->validate([
            'etiqueta' => 'sometimes|required|string|max:60',
            'tipo'     => 'sometimes|required|in:producto,servicio,ambos',
            'activo'   => 'nullable|boolean',
        ]);

        $unidad->update($datos);

        return response()->json($unidad->fresh());
    }

    /**
     * Borrar una unidad no toca los productos que la usaban: conservan su texto y siguen
     * mostrándola. Deja de ofrecerse para los nuevos, nada más. Se dice cuántos son antes,
     * para que la decisión se tome con el dato a la vista.
     */
    public function destroy(UnidadMedida $unidad): JsonResponse
    {
        $unidad->delete();

        return response()->json(['ok' => true]);
    }

    public function reordenar(Request $request): JsonResponse
    {
        $request->validate([
            'items'         => 'required|array',
            'items.*.id'    => 'required|integer|exists:unidades_medida,id',
            'items.*.orden' => 'required|integer|min:0',
        ]);

        foreach ($request->items as $item) {
            UnidadMedida::where('id', $item['id'])->update(['orden' => $item['orden']]);
        }

        return response()->json(['ok' => true]);
    }
}
