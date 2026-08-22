<?php

namespace App\Http\Controllers;

use App\Models\OpItem;
use App\Models\Operario;
use App\Models\OpItemTrabajo;
use App\Models\OpItemTrabajoPaso;
use App\Models\OpItemTrabajoPasoOperario;
use App\Models\TemplateTrabajo;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Storage;

class TrabajoController extends Controller
{
    public function index(Request $request)
    {
        $query = OpItemTrabajo::with([
            'opItem.op.cliente',
            'opItem.op.items',
            'opItem.producto',
            'opItem.ensamble.plantilla.campos',
            'template',
            'pasos.operario',
        ]);

        // Solo los trabajos de las OPs que se fabrican en la sede activa.
        $sedeActiva = \App\Support\ContextoSede::id();
        if ($sedeActiva) {
            $query->whereHas('opItem.op', fn ($q) => $q->where('sede_id', $sedeActiva));
        }

        if ($request->filled('op_id')) {
            $query->whereHas('opItem.op', fn ($q) => $q->where('id', $request->op_id));
        }
        if ($request->filled('op_numero')) {
            $query->whereHas('opItem.op', fn ($q) => $q->where('numero', 'like', '%' . $request->op_numero . '%'));
        }
        if ($request->filled('template_id')) {
            $query->where('template_id', $request->template_id);
        }
        if ($request->filled('operario_id')) {
            $query->whereHas('pasos', fn ($q) => $q->where('operario_id', $request->operario_id));
        }
        // El estado se define por actividad real de los pasos (iniciado/completado),
        // no solo por porcentaje_avance — un paso con peso 0% puede estar
        // completado y aun así dejar el porcentaje en 0, lo que antes hacía
        // que el trabajo se viera "sin iniciar" aunque ya se hubiera arrancado.
        if ($request->filled('estado')) {
            match ($request->estado) {
                'sin_iniciar' => $query->whereDoesntHave('pasos', fn ($q) => $q->where('completado', true)->orWhereNotNull('iniciado_at')),
                'en_progreso' => $query->whereHas('pasos', fn ($q) => $q->where('completado', true)->orWhereNotNull('iniciado_at'))
                                        ->whereHas('pasos', fn ($q) => $q->where('completado', false)),
                'completado'  => $query->whereHas('pasos')
                                        ->whereDoesntHave('pasos', fn ($q) => $q->where('completado', false)),
                default       => null,
            };
        }
        if ($request->filled('variable')) {
            $var = $request->variable;
            $query->whereHas('opItem', fn ($q) => $q->whereRaw(
                "JSON_CONTAINS_PATH(variables_instancia, 'one', ?)",
                ['$."' . addslashes($var) . '"']
            ));
        }
        if ($request->filled('paso')) {
            $query->whereHas('pasos', fn ($q) => $q->where('nombre', $request->paso));
        }

        // El orden lo pide la pantalla. El campo se valida contra esta lista: lo que
        // llegue por `?orden=` y no esté aquí se ignora y nunca toca el SQL.
        $orden = \App\Support\Orden::aplicar($query, $request, [
            'updated_at'        => 'updated_at',
            'porcentaje_avance' => 'porcentaje_avance',
            'numero_unidad'     => 'numero_unidad',
        ], 'updated_at', 'desc');   // como estaba: lo ultimo que se movio

        $trabajos = $query->paginate(20)->through(function ($t) {
            $campos             = $t->opItem?->ensamble?->plantilla?->campos ?? collect();
            $variablesInstancia = $t->opItem?->variables_instancia ?? [];

            return [
                'id'                    => $t->id,
                'porcentaje_avance'     => (float) $t->porcentaje_avance,
                'numero_unidad'         => $t->numero_unidad ?? 1,
                'total_unidades'        => $t->total_unidades ?? 1,
                'op_numero'             => $t->opItem?->op?->numero .
                    ($t->total_unidades > 1
                        ? " [{$t->numero_unidad}/{$t->total_unidades}]"
                        : ''),
                'op_id'                 => $t->opItem?->op?->id,
                'cliente_nombre'        => $t->opItem?->op?->cliente?->nombre,
                'item_descripcion'      => $t->opItem?->descripcion,
                'op_item_codigo'        => $t->opItem && $t->opItem->op
                    ? $t->opItem->op->numero . '-' . str_pad(($t->opItem->orden ?? 0) + 1, 2, '0', STR_PAD_LEFT)
                    : null,
                'variables_etiquetadas' => collect($variablesInstancia)
                    ->map(fn ($valor, $clave) => [
                        'clave'    => $clave,
                        'etiqueta' => ($campos->firstWhere('nombre', $clave))?->etiqueta ?? $clave,
                        'valor'    => $valor,
                    ])
                    ->values()
                    ->all(),
                'template_nombre'       => $t->template?->nombre,
                'pasos_total'           => $t->pasos->count(),
                'pasos_completados'     => $t->pasos->where('completado', true)->count(),
                'iniciado'              => $t->pasos->contains(fn ($p) => $p->completado || $p->iniciado_at),
                'operarios'             => $t->pasos
                    ->whereNotNull('operario_id')
                    ->map(fn ($p) => ['id' => $p->operario?->id, 'nombre' => $p->operario?->nombre])
                    ->filter(fn ($o) => $o['id'])
                    ->unique('id')
                    ->values(),
            ];
        });

        if ($request->wantsJson()) {
            return response()->json($trabajos);
        }

        return Inertia::render('Trabajos/Index', [
            'orden' => $orden,
            'trabajos'   => $trabajos,
            'operarios'  => Operario::where('estado', 'activo')->get(['id', 'nombre']),
            'templates'  => TemplateTrabajo::where('activo', true)->get(['id', 'nombre']),
            'filters'              => $request->only(['op_id', 'op_numero', 'template_id', 'operario_id', 'estado', 'variable', 'paso']),
            'variables_disponibles'=> OpItem::whereHas('trabajos')
                ->whereNotNull('variables_instancia')
                ->pluck('variables_instancia')
                ->flatMap(fn ($vars) => array_keys((array) $vars))
                ->unique()
                ->sort()
                ->values()
                ->all(),
            'pasos_disponibles'    => OpItemTrabajoPaso::query()
                ->distinct()
                ->orderBy('nombre')
                ->pluck('nombre')
                ->filter()
                ->values()
                ->all(),
            'metricas'   => [
                'sin_iniciar'        => OpItemTrabajo::whereDoesntHave('pasos', fn ($q) => $q->where('completado', true)->orWhereNotNull('iniciado_at'))->count(),
                'en_progreso'        => OpItemTrabajo::whereHas('pasos', fn ($q) => $q->where('completado', true)->orWhereNotNull('iniciado_at'))
                                           ->whereHas('pasos', fn ($q) => $q->where('completado', false))->count(),
                'completados'        => OpItemTrabajo::whereHas('pasos')
                                           ->whereDoesntHave('pasos', fn ($q) => $q->where('completado', false))->count(),
                'pasos_pendientes'   => OpItemTrabajoPaso::where('completado', false)->count(),
                'pasos_completados'  => OpItemTrabajoPaso::where('completado', true)->count(),
                'top_operarios'      => OpItemTrabajoPasoOperario::query()
                    ->selectRaw('operario_id, SUM(tiempo_minutos) as total_minutos, COUNT(*) as pasos')
                    ->whereNotNull('operario_id')
                    ->groupBy('operario_id')
                    ->orderByDesc('total_minutos')
                    ->limit(5)
                    ->with('operario')
                    ->get()
                    ->map(fn ($r) => [
                        'nombre'        => $r->operario?->nombre ?? '—',
                        'total_minutos' => (int) $r->total_minutos,
                        'pasos'         => (int) $r->pasos,
                    ])
                    ->values(),
            ],
        ]);
    }

    public function destroy(OpItemTrabajo $trabajo): \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
    {
        abort_unless(
            in_array(auth()->user()->rol, ['administrador', 'jefe_produccion']),
            403
        );

        $trabajo->pasos()->each(function ($paso) {
            $paso->operarios()->delete();
            $paso->delete();
        });
        $trabajo->delete();

        $item = $trabajo->opItem;
        if ($item) {
            $trabajosRestantes = OpItemTrabajo::where('op_item_id', $item->id)->count();
            if ($trabajosRestantes === 0) {
                $item->update(['estado_item' => 'pendiente']);
            }
            $op = $item->op;
            if ($op) {
                $todosTrabajos = OpItemTrabajo::whereHas('opItem', fn ($q) => $q->where('op_id', $op->id))->get();
                if ($todosTrabajos->isNotEmpty()) {
                    $op->update(['porcentaje_avance' => round($todosTrabajos->avg('porcentaje_avance'), 2)]);
                } else {
                    $op->update(['porcentaje_avance' => 0]);
                }
            }
        }

        if (request()->wantsJson()) {
            return response()->json(['ok' => true]);
        }
        return back()->with('success', 'Trabajo eliminado.');
    }

    public function show(OpItemTrabajo $trabajo)
    {
        $trabajo->load([
            'opItem.op.cliente',
            'opItem.producto',
            'opItem.ensamble',
            'template',
            'pasos' => fn ($q) => $q->orderBy('orden'),
            'checks' => fn ($q) => $q->orderBy('orden'),
            'checks.revisadoPor:id,name',
            'pasos.operario',
            'pasos.operarios.operario',
        ]);

        return Inertia::render('Trabajos/Show', [
            // Para elegir a que bodega entra la unidad al cerrar el ultimo paso.
            'bodegas' => \App\Models\Bodega::orderBy('nombre')->get(['id', 'nombre']),
            'trabajo'   => [
                'id'                => $trabajo->id,
                'porcentaje_avance' => (float) $trabajo->porcentaje_avance,
                'numero_unidad'     => $trabajo->numero_unidad ?? 1,
                'total_unidades'    => $trabajo->total_unidades ?? 1,
                'op_numero'         => $trabajo->opItem?->op?->numero,
                'op_id'             => $trabajo->opItem?->op?->id,
                'op_estado'         => $trabajo->opItem?->op?->estado,
                // La bodega la declara la OP. Viaja para que la pantalla la muestre en vez de
                // pedirla: al operario solo se le pregunta en las órdenes viejas, nacidas
                // antes de que el campo existiera.
                'op_bodega_entrega' => $trabajo->opItem?->op?->bodegaEntrega?->nombre,
                'op_fecha'          => $trabajo->opItem?->op?->fecha_creacion?->format('d/m/Y'),
                'cliente_nombre'    => $trabajo->opItem?->op?->cliente?->nombre,
                'item_descripcion'  => $trabajo->opItem?->descripcion,
                'template_nombre'   => $trabajo->template?->nombre,
                // La revisión de calidad de ESTA unidad. Se copió de la plantilla al generar
                // el trabajo y se llena aquí, punto por punto.
                'checks'            => $trabajo->checks->map(
                    fn ($c) => app(\App\Http\Controllers\CalidadCheckController::class)->fila($c)
                )->values(),
                'pasos'             => $trabajo->pasos->map(fn ($p) => [
                    'id'                  => $p->id,
                    'nombre'              => $p->nombre,
                    'descripcion_resuelta'=> $p->descripcion_resuelta,
                    'peso_porcentaje'     => (float) $p->peso_porcentaje,
                    'completado'          => (bool) $p->completado,
                    'completado_at'       => $p->completado_at?->format('d/m/Y H:i'),
                    'completado_at_iso'   => $p->completado_at?->toIso8601String(),
                    'iniciado_at'         => $p->iniciado_at?->format('d/m/Y H:i'),
                    'iniciado_at_iso'     => $p->iniciado_at?->toIso8601String(),
                    'duracion_real_minutos' => $p->duracionRealMinutos(),
                    'operario_id'         => $p->operario_id,
                    'operario_nombre'     => $p->operario?->nombre,
                    'tiempo_minutos'      => $p->tiempo_minutos,
                    'es_extra'            => (bool) $p->es_extra,
                    'orden'               => $p->orden,
                    // El paso final es el que entrega la unidad a una bodega, y por eso es el
                    // unico que muestra el selector.
                    'es_paso_final'       => (bool) $p->es_paso_final,
                    'bodega_destino_id'   => $p->bodega_destino_id,
                    // Las fotos se guardan como ruta relativa —«pasos/2/foto.jpg»— y el
                    // navegador necesita la URL pública. Sin `Storage::url()` el `src` quedaba
                    // relativo a la dirección de la pantalla y resolvía a
                    // «/trabajos/pasos/2/foto.jpg», que no existe: la foto se veía rota al
                    // recargar, aunque al subirla se viera bien —el endpoint de subida sí
                    // devuelve la URL completa—.
                    'fotos'               => collect($p->fotos ?? [])->map(fn ($f) => Storage::url($f))->all(),
                    'operarios_pivot'     => $p->operarios->map(fn ($o) => [
                        'operario_id'    => $o->operario_id,
                        'nombre'         => $o->operario?->nombre,
                        'tiempo_minutos' => $o->tiempo_minutos,
                        'observaciones'  => $o->observaciones,
                    ])->values(),
                ]),
            ],
            'operarios' => Operario::where('estado', 'activo')->get(['id', 'nombre']),
        ]);
    }
}
