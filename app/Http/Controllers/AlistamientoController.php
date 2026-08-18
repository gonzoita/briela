<?php

namespace App\Http\Controllers;

use App\Models\OpItem;
use App\Models\PlantillaEnsamble;
use App\Support\ContextoSede;
use App\Support\Orden;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Alistamiento: lo que el almacenista tiene que dejar listo antes de que salga el camión.
 *
 * Antes esto vivía dentro de cada orden de producción, con un botón por ítem. El almacenista
 * tenía que saber en qué orden estaba cada cosa y entrar una por una — y lo que se despacha no
 * es una orden, son ítems: cinco bisagras de una y una puerta de otra pueden salir en el mismo
 * viaje.
 *
 * Aquí llegan **todos** los ítems de todas las órdenes, se filtran por lo que haga falta
 * —orden, cliente, tipo, plantilla del ensamble, estado— y se alistan de a uno. Lo que queda
 * alistado es exactamente lo que el remisionador deja escoger.
 *
 * Los **servicios** también se alistan —alguien tiene que declarar que están hechos— pero no
 * viajan: no aparecen en la remisión porque no hay nada que cargar.
 */
class AlistamientoController extends Controller
{
    /** Las órdenes que ya se pueden alistar. Un borrador todavía se está armando. */
    private const ESTADOS_OP = ['confirmada', 'en_produccion', 'calidad', 'reproceso', 'despachada'];

    public function index(Request $request): Response
    {
        $query = OpItem::query()
            ->with([
                'op:id,numero,estado,cliente_id,sede_id',
                'op.cliente:id,nombre,apellido',
                'ensamble:id,nombre,plantilla_id',
                'ensamble.plantilla:id,nombre',
            ])
            ->whereHas('op', function ($q) {
                $q->whereIn('estado', self::ESTADOS_OP);

                // La sede activa manda, igual que en el resto del sistema.
                ContextoSede::aplicar($q);
            });

        if ($request->filled('q')) {
            $texto = $request->q;

            $query->where(function ($q) use ($texto) {
                $q->where('descripcion', 'like', "%{$texto}%")
                  ->orWhere('numero_serie', 'like', "%{$texto}%")
                  ->orWhereHas('op', fn ($o) => $o
                      ->where('numero', 'like', "%{$texto}%")
                      ->orWhereHas('cliente', fn ($c) => $c->where('nombre', 'like', "%{$texto}%")));
            });
        }

        if ($request->filled('op_id')) {
            $query->where('op_id', $request->op_id);
        }

        // El tipo que ve el usuario no es el de la base: «texto_libre» es un servicio y
        // «configuracion_puerta» es un producto. Se traduce aquí para que el filtro diga lo
        // mismo que la columna de la lista.
        if ($request->filled('tipo')) {
            $query->whereIn('tipo', match ($request->tipo) {
                'servicio' => ['texto_libre', 'servicio'],
                'producto' => ['producto', 'configuracion_puerta'],
                default    => [$request->tipo],
            });
        }

        if ($request->filled('plantilla')) {
            $query->whereHas('ensamble', fn ($e) => $e->where('plantilla_id', $request->plantilla));
        }

        if ($request->estado === 'alistado') {
            $query->where('estado_item', 'terminado');
        } elseif ($request->estado === 'pendiente') {
            $query->where('estado_item', '!=', 'terminado');
        }

        $orden = Orden::aplicar(
            $query,
            $request,
            ['descripcion', 'estado_item', 'cantidad', 'created_at'],
            'created_at',
            'desc'
        );

        return Inertia::render('Alistamiento/Index', [
            'items'        => $query->paginate(30)->withQueryString()->through(fn ($i) => $this->fila($i)),
            'filters'      => $request->only(['q', 'estado', 'tipo', 'op_id', 'plantilla']),
            'orden'        => $orden,
            'resumen'      => $this->resumen(),
            'plantillas'   => PlantillaEnsamble::orderBy('nombre')->get(['id', 'nombre']),
            'puedeAlistar' => (bool) auth()->user()?->tienePermiso('alistamiento.alistar'),
        ]);
    }

    /**
     * El tablero: lo que hay que mirar antes de entrar a la lista.
     *
     * Se calcula sobre las mismas órdenes que la lista pero **sin sus filtros**: un tablero que
     * cambia cuando se filtra no sirve para saber cómo va el día.
     */
    private function resumen(): array
    {
        $base = fn () => OpItem::whereHas('op', function ($q) {
            $q->whereIn('estado', self::ESTADOS_OP);
            ContextoSede::aplicar($q);
        });

        return [
            'pendientes'    => $base()->where('estado_item', '!=', 'terminado')->count(),
            'alistados'     => $base()->where('estado_item', 'terminado')->count(),
            'alistados_hoy' => $base()->where('estado_item', 'terminado')->whereDate('updated_at', today())->count(),
            'por_despachar' => $base()->where('estado_item', 'terminado')->where('remisionado', false)->count(),
        ];
    }

    private function fila(OpItem $i): array
    {
        // El tipo, en las palabras del usuario.
        $tipo = match ($i->tipo) {
            'texto_libre'          => 'servicio',
            'configuracion_puerta' => 'producto',
            default                => $i->tipo,
        };

        $trabajos = $i->trabajos()->count();

        return [
            'id'           => $i->id,
            'descripcion'  => $i->descripcion,
            'tipo'         => $tipo,
            'cantidad'     => (float) $i->cantidad,
            'numero_serie' => $i->numero_serie,
            'alistado'     => $i->estado_item === 'terminado',
            // Un servicio se alista pero no se despacha: no hay nada que cargar en el camión.
            'despachable'  => $tipo !== 'servicio',
            'remisionado'  => (bool) $i->remisionado,
            'disponible'   => $i->cantidadDisponible(),
            // Un ítem sin trabajos no tiene avance que mostrar: nadie lo fabrica, sale de
            // bodega. Va en null y la pantalla enseña un guion, no un 0 % que asusta.
            'avance'       => $trabajos > 0 ? (int) round($i->trabajos()->avg('porcentaje_avance') ?? 0) : null,
            'op'           => [
                'id'      => $i->op?->id,
                'numero'  => $i->op?->numero,
                'estado'  => $i->op?->estado,
                'cliente' => $i->op?->cliente?->nombreCompleto(),
            ],
            'plantilla'    => $i->ensamble?->plantilla?->nombre,
        ];
    }

    /**
     * Marca o desmarca un ítem como alistado.
     *
     * Desmarcar es a propósito: alistar de más es un error que se comete, y sin forma de
     * deshacerlo el almacenista tendría que pedir que le editen la base. Lo que no se puede es
     * desalistar algo que **ya salió**: eso no está en la bodega.
     */
    public function alternar(Request $request, OpItem $item): RedirectResponse
    {
        $alistar = $request->boolean('alistado', true);

        if (! $alistar && $item->unidadesRemisionadas() > 0) {
            return back()->withErrors([
                'alistado' => 'Este ítem ya tiene unidades remisionadas: no se puede devolver a pendiente.',
            ]);
        }

        $item->update(['estado_item' => $alistar ? 'terminado' : 'pendiente']);

        return back()->with('success', $alistar
            ? 'Ítem alistado, listo para remisionar.'
            : 'Ítem devuelto a pendiente.');
    }
}
