<?php

namespace App\Http\Controllers;

use App\Models\Bodega;
use App\Models\ComisionVendedor;
use App\Models\Configuracion;
use App\Models\Cotizacion;
use App\Models\Ensamble;
use App\Models\Op;
use App\Models\OpCuota;
use App\Models\OpItem;
use App\Models\OpPago;
use App\Models\Operario;
use App\Models\Producto;
use App\Models\TemplateTrabajo;
use App\Models\User;
use App\Rules\ProductoSeleccionable;
use App\Services\FormulaEvaluatorService;
use App\Services\TrabajoAutoGeneratorService;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use App\Support\Marca;

class OpController extends Controller
{
    public function index(Request $request): Response
    {
        try {
            $rojosDias    = (int) (\DB::table('configuraciones')->where('clave', 'alerta_op_rojo_dias')->value('valor') ?? 0);
            $amarilloDias = (int) (\DB::table('configuraciones')->where('clave', 'alerta_op_amarillo_dias')->value('valor') ?? 2);
        } catch (\Throwable) {
            $rojosDias    = 0;
            $amarilloDias = 2;
        }

        $diasAlerta = (int) Configuracion::get('semaforo_dias_alerta', 5);
        $hoyStr     = now()->setTimezone('America/Bogota')->toDateString();
        $umbralStr  = now()->setTimezone('America/Bogota')->addDays($diasAlerta)->toDateString();

        $query = \App\Support\ContextoSede::aplicar(Op::query())
            ->with(['cliente', 'responsable', 'cotizacion', 'sede:id,nombre'])
            ->withSum('pagos as pagos_sum', 'valor')
            ->when($request->filled('estado'),        fn ($q) => $q->where('estado', $request->estado))
            ->when($request->filled('responsable_id'),fn ($q) => $q->where('responsable_id', $request->responsable_id))
            ->when($request->filled('desde'),         fn ($q) => $q->whereDate('fecha_creacion', '>=', $request->desde))
            ->when($request->filled('hasta'),         fn ($q) => $q->whereDate('fecha_creacion', '<=', $request->hasta))
            ->when($request->filled('buscar'),        fn ($q) => $q->where(function ($q2) use ($request) {
                $q2->where('numero', 'like', "%{$request->buscar}%")
                   ->orWhereHas('cliente', fn ($q3) => $q3->where('nombre', 'like', "%{$request->buscar}%"));
            }))
            // Entrega vencida: se pasó la fecha estimada y todavía no sale.
            // Lo usa el aviso del dashboard, para que al tocarlo se abra la
            // lista con exactamente las mismas OPs que se contaron.
            ->when($request->input('entrega') === 'vencida', fn ($q) => $q
                ->whereNotNull('fecha_entrega_estimada')
                ->whereDate('fecha_entrega_estimada', '<', now()->toDateString())
                ->whereNotIn('estado', ['despachada', 'cerrada', 'rechazada']))
;

        // El orden lo pide la pantalla. `Orden::aplicar` valida el campo contra esta
        // lista: lo que llegue por `?orden=` y no esté aquí se ignora, así que el
        // parámetro nunca toca el SQL.
        $orden = \App\Support\Orden::aplicar($query, $request, [
            'numero'                  => 'numero',
            'estado'                  => 'estado',
            'porcentaje_avance'       => 'porcentaje_avance',
            'fecha_entrega_estimada'  => 'fecha_entrega_estimada',
            'created_at'              => 'created_at',
        ]);

        $ops = $query->paginate(20)->withQueryString();

        // Cargar datos de cuotas en batch (2 queries para toda la página)
        $opIds = $ops->getCollection()->pluck('id')->toArray();
        $cuotasVencidasSet = array_flip(
            OpCuota::whereIn('op_id', $opIds)
                ->whereIn('estado', ['pendiente', 'parcial'])
                ->whereNotNull('fecha_vencimiento')
                ->where('fecha_vencimiento', '<', $hoyStr)
                ->distinct()->pluck('op_id')->toArray()
        );
        $cuotasPorVencerSet = array_flip(
            OpCuota::whereIn('op_id', $opIds)
                ->whereIn('estado', ['pendiente', 'parcial'])
                ->whereNotNull('fecha_vencimiento')
                ->whereBetween('fecha_vencimiento', [$hoyStr, $umbralStr])
                ->distinct()->pluck('op_id')->toArray()
        );

        $ops->getCollection()->transform(function ($op) use ($rojosDias, $amarilloDias, $cuotasVencidasSet, $cuotasPorVencerSet) {
            $pagosSumVal = (float) ($op->pagos_sum ?? 0);
            $totalOp     = (float) ($op->total ?? 0);
            $saldo       = $totalOp - $pagosSumVal;

            $semaforo = 'gris';
            if (isset($cuotasVencidasSet[$op->id])) {
                $semaforo = 'rojo';
            } elseif (isset($cuotasPorVencerSet[$op->id])) {
                $semaforo = 'amarillo';
            } elseif ($totalOp > 0 && $saldo <= 0) {
                $semaforo = 'verde';
            }

            return [
                'id'                     => $op->id,
                'numero'                 => $op->numero,
                'estado'                 => $op->estado,
                'cotizacion_id'          => $op->cotizacion_id,
                'fecha_creacion'         => $op->fecha_creacion,
                'fecha_entrega_estimada' => $op->fecha_entrega_estimada,
                'estado_badge'           => $op->estadoBadge(),
                'cliente_nombre'         => $op->cliente ? trim($op->cliente->nombre . ' ' . $op->cliente->apellido) : null,
                'responsable_nombre'     => $op->responsable?->name,
                'items_count'            => $op->items()->count(),
                'porcentaje_avance'      => (float) $op->porcentaje_avance,
                'total'                  => $totalOp,
                'total_pagado'           => $pagosSumVal,
                'saldo_pendiente'        => $saldo,
                'semaforo_cartera'       => $semaforo,
                'alerta_entrega'         => (function () use ($op, $rojosDias, $amarilloDias) {
                    if (!$op->fecha_entrega_estimada) return null;
                    $hoy           = now()->startOfDay();
                    $entrega       = $op->fecha_entrega_estimada->copy()->startOfDay();
                    $diasRestantes = (int) $hoy->diffInDays($entrega, false);
                    if ($diasRestantes <= $rojosDias)    return 'rojo';
                    if ($diasRestantes <= $amarilloDias) return 'amarillo';
                    return null;
                })(),
                'dias_para_entrega'      => $op->fecha_entrega_estimada
                    ? (int) now()->startOfDay()->diffInDays($op->fecha_entrega_estimada->copy()->startOfDay(), false)
                    : null,
            ];
        });

        $metricas = [
            'borrador'        => Op::where('estado', 'borrador')->count(),
            'confirmada'      => Op::where('estado', 'confirmada')->count(),
            'en_produccion'   => Op::where('estado', 'en_produccion')->count(),
            'calidad'         => Op::where('estado', 'calidad')->count(),
            'reproceso'       => Op::where('estado', 'reproceso')->count(),
            'despachada'      => Op::where('estado', 'despachada')->count(),
            'despachadas_mes' => Op::where('estado', 'despachada')->whereMonth('updated_at', now()->month)->whereYear('updated_at', now()->year)->count(),
            'nuevas_mes'      => Op::whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->count(),
        ];

        return Inertia::render('Produccion/Ops/Index', [
            'orden'      => $orden,
            'ops'          => $ops,
            'filters'      => $request->only(['estado', 'responsable_id', 'buscar', 'desde', 'hasta', 'entrega']),
            'responsables' => User::whereIn('rol', ['administrador', 'jefe_produccion', 'vendedor'])
                ->where('activo', true)->get(['id', 'name']),
            'metricas'     => $metricas,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Produccion/Ops/Create', [
            'responsables'   => User::whereIn('rol', ['administrador', 'jefe_produccion', 'vendedor'])->where('activo', true)->get(['id', 'name']),
            'operarios'      => User::where('rol', 'operario')->where('activo', true)->get(['id', 'name']),
            // Las bodegas de la sede activa. Una OP de una sede no entrega en la bodega de
            // otra. Si la sede no filtra ninguna —bodegas sin sede asignada— salen todas las
            // activas: un selector vacío aquí impediría confirmar cualquier orden.
            'bodegas'        => \App\Support\ContextoSede::bodegasParaElegir()
                ->sortBy([['es_principal', 'desc'], ['nombre', 'asc']])
                ->map(fn ($b) => ['id' => $b->id, 'nombre' => $b->nombre, 'es_principal' => (bool) $b->es_principal])
                ->values(),
            'usuario_actual' => auth()->id(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validarForm($request);

        $op = Op::create([
            'cliente_id'              => $data['cliente_id'] ?? null,
            'cotizacion_id'           => $data['cotizacion_id'] ?? null,
            'responsable_id'          => $data['responsable_id'],
            'bodega_entrega_id'       => $data['bodega_entrega_id'] ?? null,
            'bodega_material_id'      => $data['bodega_material_id'] ?? null,
            'estado'                  => $data['estado'] ?? 'borrador',
            'fecha_creacion'          => $data['fecha_creacion'],
            'fecha_entrega_estimada'  => $data['fecha_entrega_estimada'] ?? null,
            'anticipo'                => $data['anticipo'] ?? null,
            'condiciones'             => $data['condiciones'] ?? null,
            'notas_internas'          => $data['notas_internas'] ?? null,
        ]);

        if (! $op->itemsBloqueados()) {
            $this->syncItems($op, $data['items'] ?? []);
            $op->recalcularTotales();
        }

        return redirect("/produccion/ops/{$op->id}")
            ->with('success', "Orden {$op->numero} creada.");
    }

    public function show(Op $op): Response
    {
        $op->load([
            'cliente', 'responsable', 'cotizacion',
            'items.producto',
            'items.ensamble.plantilla.campos',
            'items.ensamble.plantilla.secciones.componentes',
            'items.operario',
            'items.componentes.producto',
            'items.trabajos.template',
            'items.trabajos.checks.revisadoPor:id,name',
            'archivos' => fn ($q) => $q->where('categoria', 'foto_calidad'),
        ]);

        return Inertia::render('Produccion/Ops/Show', [
            'op'           => [
                ...$op->toArray(),
                'estado_badge'      => $op->estadoBadge(),
                'tiene_cuotas'      => $op->cuotas()->exists(),
                'fotos_calidad'     => $op->archivos->map(fn ($a) => [
                    'id'     => $a->id,
                    'url'    => $a->url,
                    'nombre' => $a->nombre_original,
                ])->values(),
                'cliente_nombre'    => $op->cliente ? ($op->cliente->nombre . ' ' . $op->cliente->apellido) : null,
                'responsable_nombre'=> $op->responsable?->name,
                // Aviso no bloqueante de material faltante — ya no aplica
                // una vez despachada (el consumo de inventario ya pasó).
                'insumos_faltantes' => $op->estado !== 'despachada' ? $op->insumosFaltantes() : [],
                'items'             => $op->items->map(function ($item, $idx) use ($op) {
                    // Mapa producto_id → sección desde la plantilla
                    $secciones     = $item->ensamble?->plantilla?->secciones ?? collect();
                    $mapaSecciones = collect();
                    foreach ($secciones->sortBy('orden') as $seccion) {
                        foreach ($seccion->componentes as $plantComp) {
                            if ($plantComp->producto_id) {
                                $mapaSecciones->put($plantComp->producto_id, [
                                    'nombre' => $seccion->nombre,
                                    'orden'  => $seccion->orden,
                                ]);
                            }
                        }
                    }

                    return [
                        // La revisión de calidad de este ítem, unidad por unidad.
                        //
                        // Calidad era una decisión de una sola pieza sobre la orden entera: una
                        // foto, un comentario y aprobar. En una orden de diez puertas eso no dice
                        // nada. Ahora cada ítem trae su lista —la que definió la plantilla— y la
                        // orden se aprueba cuando no queda nada por resolver.
                        'unidades_calidad' => $item->trabajos->map(fn ($t) => [
                            'trabajo_id' => $t->id,
                            'numero'     => $t->numero_unidad,
                            'total'      => $t->total_unidades,
                            'checks'     => $t->checks->map(
                                fn ($c) => app(\App\Http\Controllers\CalidadCheckController::class)->fila($c)
                            )->values(),
                        ])->filter(fn ($u) => count($u['checks']) > 0)->values(),
                        ...$item->toArray(),
                        'codigo_item' => $op->numero . '-' . str_pad($idx + 1, 2, '0', STR_PAD_LEFT),
                        'descripcion_larga_texto' => $item->descripcion_larga
                            ? strip_tags($item->descripcion_larga)
                            : null,
                        'tiene_secciones' => $mapaSecciones->isNotEmpty(),
                        'componentes' => $item->componentes->map(function ($c) use ($mapaSecciones) {
                            $secInfo = !$c->parent_componente_id
                                ? $mapaSecciones->get($c->producto_id)
                                : null;
                            return [
                                'id'                   => $c->id,
                                'nombre'               => $c->nombre,
                                'referencia'           => $c->producto?->referencia ?? null,
                                'cantidad'             => (float) $c->cantidad,
                                'unidad'               => $c->unidad,
                                'observacion'          => $c->observacion,
                                'parent_componente_id' => $c->parent_componente_id,
                                'seccion_nombre'       => $secInfo['nombre'] ?? null,
                                'seccion_orden'        => $secInfo['orden'] ?? 9999,
                            ];
                        })->sort(fn ($a, $b) => $a['seccion_orden'] !== $b['seccion_orden']
                            ? $a['seccion_orden'] <=> $b['seccion_orden']
                            : $a['id'] <=> $b['id']
                        )->values(),
                        'campos_plantilla' => $item->ensamble?->plantilla?->campos
                            ->where('tipo_campo', 'entrada')
                            ->sortBy('orden')
                            ->map(fn ($campo) => [
                                'nombre'                   => $campo->nombre,
                                'etiqueta'                 => $campo->etiqueta ?? $campo->nombre,
                                'imagen_referencia'        => $campo->imagen_referencia
                                    ? '/storage/' . $campo->imagen_referencia
                                    : null,
                                'imagen_referencia_titulo' => $campo->imagen_referencia_titulo,
                            ])->values() ?? collect(),
                        'trabajos' => $item->trabajos
                            ->sortBy('numero_unidad')
                            ->map(fn ($t) => [
                                'id'                => $t->id,
                                'token_trabajo'     => $t->token_trabajo,
                                'numero_unidad'     => $t->numero_unidad ?? 1,
                                'total_unidades'    => $t->total_unidades ?? 1,
                                'porcentaje_avance' => (float) $t->porcentaje_avance,
                                'template_nombre'   => $t->template?->nombre,
                            ])->values(),
                    ];
                })->values(),
            ],
            'responsables'  => User::where('activo', true)->get(['id', 'name', 'rol']),
            'operarios'     => Operario::all(['id', 'nombre']),
            'templates'     => TemplateTrabajo::where('activo', true)->withCount('pasos')->get(['id', 'nombre']),
        ]);
    }

    public function edit(Op $op): Response
    {
        $op->load(['cliente', 'items.producto', 'items.ensamble', 'items.componentes.producto']);

        $opData = $op->toArray();
        $opData['items'] = $op->items->map(fn ($item) => [
            ...$item->toArray(),
            'componentes_bd' => $item->componentes->map(fn ($c) => [
                'id'         => $c->id,
                'nombre'     => $c->nombre,
                'referencia' => $c->producto?->referencia ?? null,
                'cantidad'   => (float) $c->cantidad,
                'unidad'     => $c->unidad,
                'observacion'=> $c->observacion,
                'parent_componente_id' => $c->parent_componente_id,
            ])->values()->toArray(),
        ])->values()->toArray();

        return Inertia::render('Produccion/Ops/Create', [
            'op'            => $opData,
            'responsables'  => User::whereIn('rol', ['administrador', 'jefe_produccion', 'vendedor'])->where('activo', true)->get(['id', 'name']),
            'operarios'     => User::where('rol', 'operario')->where('activo', true)->get(['id', 'name']),
            // Las bodegas de la sede activa. Una OP de una sede no entrega en la bodega de
            // otra. Si la sede no filtra ninguna —bodegas sin sede asignada— salen todas las
            // activas: un selector vacío aquí impediría confirmar cualquier orden.
            'bodegas'        => \App\Support\ContextoSede::bodegasParaElegir()
                ->sortBy([['es_principal', 'desc'], ['nombre', 'asc']])
                ->map(fn ($b) => ['id' => $b->id, 'nombre' => $b->nombre, 'es_principal' => (bool) $b->es_principal])
                ->values(),
            'usuario_actual'=> auth()->id(),
        ]);
    }

    public function update(Request $request, Op $op): RedirectResponse
    {
        $data = $this->validarForm($request);

        // Una OP que ya arrancó no cambia sus ítems. En cuanto hay trabajo hecho, cambiar la
        // receta de un ítem dejaría los pasos, los tiempos y las fotos de los operarios
        // apuntando a algo que ya no es lo que se está fabricando; y si una unidad ya entró a
        // bodega, cambiarla descuadraría el inventario hacia atrás.
        //
        // El candado va aquí y no solo en la pantalla: una pantalla se puede saltar.
        if ($op->itemsBloqueados() && $request->filled('items')) {
            return back()->with('error',
                'Esta orden ya está en producción: sus ítems no se pueden modificar. '
                .'Los cambios de fechas, responsable y notas sí se guardaron.');
        }

        $op->update([
            'cliente_id'             => $data['cliente_id'] ?? null,
            'responsable_id'         => $data['responsable_id'],
            'bodega_entrega_id'      => $data['bodega_entrega_id'] ?? $op->bodega_entrega_id,
            'bodega_material_id'     => $data['bodega_material_id'] ?? $op->bodega_material_id,
            'estado'                 => $data['estado'] ?? $op->estado,
            'fecha_creacion'         => $data['fecha_creacion'],
            'fecha_entrega_estimada' => $data['fecha_entrega_estimada'] ?? null,
            'anticipo'               => $data['anticipo'] ?? null,
            'condiciones'            => $data['condiciones'] ?? null,
            'notas_internas'         => $data['notas_internas'] ?? null,
        ]);

        if (! $op->itemsBloqueados()) {
            $this->syncItems($op, $data['items'] ?? []);
            $op->recalcularTotales();
        }

        return redirect("/produccion/ops/{$op->id}")
            ->with('success', 'Orden actualizada.');
    }

    public function destroy(Op $op): RedirectResponse
    {
        $op->delete();

        return redirect('/produccion/ops')->with('success', 'Orden eliminada.');
    }

    public function cambiarEstado(Request $request, Op $op)
    {
        $request->validate(['estado' => 'required|in:borrador,confirmada,en_produccion,calidad,reproceso,despachada']);
        $estadoAnterior = $op->estado;
        $nuevoEstado    = $request->estado;

        // El control de calidad es obligatorio: no se puede saltar
        // manualmente a "despachada" sin que la OP haya sido aprobada.
        if ($nuevoEstado === 'despachada' && ! $op->calidad_aprobada_at) {
            $mensaje = 'Esta OP no puede despacharse todavía: falta la aprobación de control de calidad.';
            if ($request->wantsJson()) {
                return response()->json(['error' => $mensaje], 422);
            }
            return back()->withErrors(['estado' => $mensaje]);
        }

        // Confirmar es decir «esto se fabrica», y todo lo que se fabrica queda en una bodega.
        // Se exige aquí y no antes: en borrador todavía se está armando la orden y obligar a
        // elegir bodega para guardar un borrador estorba. Las OPs viejas, que nacieron sin el
        // campo, no se bloquean si ya pasaron de borrador.
        if ($nuevoEstado === 'confirmada') {
            $faltan = [];

            if (! $op->bodega_material_id) {
                $faltan['bodega_material_id'] = 'Elige de qué bodega sale el material que consume esta orden.';
            }

            if (! $op->bodega_entrega_id) {
                $faltan['bodega_entrega_id'] = 'Elige a qué bodega entra lo que fabrique esta orden.';
            }

            if ($faltan) {
                $mensaje = implode(' ', $faltan) . ' Se editan en la orden, junto al responsable.';

                if ($request->wantsJson()) {
                    return response()->json(['error' => $mensaje], 422);
                }

                return back()->withErrors($faltan);
            }
        }

        $op->update(['estado' => $nuevoEstado]);

        // Al confirmar la OP (planos verificados + anticipo registrado si
        // aplica) no queda ningún paso humano pendiente antes de que
        // producción arranque — los trabajos ya se generaron al crear la OP.
        // Antes había que entrar de nuevo a "Cambiar estado" y elegir "En
        // producción" a mano; ahora "Confirmada" pasa directo.
        if ($nuevoEstado === 'confirmada') {
            $nuevoEstado = 'en_produccion';
            $op->update(['estado' => $nuevoEstado]);
        }

        // Consumo automático de materiales al despachar
        if ($nuevoEstado === 'despachada' && $estadoAnterior !== 'despachada') {
            $op->consumirMaterialesInventario();
        }

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }
        return back()->with('success', 'Estado actualizado.');
    }

    /**
     * Decisión de control de calidad. Aprobar YA NO despacha directo: solo
     * marca `calidad_aprobada_at`, que es el requisito obligatorio para que
     * almacén pueda generar la remisión (RemisionController) — el despacho
     * real de la OP ocurre cuando esa remisión queda completa. Rechazar
     * manda la OP a "reproceso" con el motivo obligatorio.
     */
    public function registrarCalidad(Request $request, Op $op): RedirectResponse
    {
        $data = $request->validate([
            'accion'                 => 'required|in:aprobar,rechazar',
            'observaciones_calidad'  => 'nullable|string',
            'motivo_rechazo'         => 'required_if:accion,rechazar|nullable|string',
        ]);

        $estadoAnterior = $op->estado;

        // Con lista de revisión, calidad deja de ser una decisión de una sola pieza: cada
        // unidad tiene que estar revisada. Se cuenta lo que falta para poder decirlo con
        // números, que es lo único que sirve cuando son diez puertas.
        if ($data['accion'] === 'aprobar') {
            $pendientes = \App\Models\OpItemTrabajoCheck::whereHas(
                'trabajo.opItem', fn ($q) => $q->where('op_id', $op->id)
            )->where(function ($q) {
                $q->where('resultado', 'pendiente')
                  ->orWhere(fn ($q2) => $q2->where('resultado', 'falla')->where('es_critico', true));
            })->count();

            if ($pendientes > 0) {
                return back()->withErrors([
                    'accion' => "Faltan {$pendientes} punto(s) de revisión por resolver en las unidades de esta OP. "
                        . 'Revísalos en cada trabajo: un punto crítico que falla no deja aprobar.',
                ]);
            }
        }

        if ($data['accion'] === 'aprobar') {
            $op->update([
                'observaciones_calidad' => $data['observaciones_calidad'] ?? $op->observaciones_calidad,
                'motivo_rechazo'        => null,
                'calidad_aprobada_at'   => now(),
            ]);
            $mensaje = 'Calidad aprobada — ya se puede generar la remisión y despachar.';

            // Aviso a logística/almacén: la OP ya se puede remisionar.
            app(\App\Services\NotificacionService::class)->paraRol(
                ['administrador', 'jefe_produccion'],
                'op_lista_despacho',
                "OP {$op->numero} lista para despachar",
                'Calidad aprobada. Ya se puede generar la remisión.',
                "/produccion/ops/{$op->id}",
            );
        } else {
            $op->update([
                'observaciones_calidad' => $data['observaciones_calidad'] ?? $op->observaciones_calidad,
                'motivo_rechazo'        => $data['motivo_rechazo'],
                'calidad_aprobada_at'   => null,
                'estado'                => 'reproceso',
            ]);
            $mensaje = 'Calidad rechazada — la OP volvió a reproceso.';
        }

        return back()->with('success', $mensaje);
    }

    public function marcarTerminado(Op $op, OpItem $item): RedirectResponse
    {
        abort_if($item->op_id !== $op->id, 404);
        $item->update(['estado_item' => 'terminado']);
        return back()->with('success', 'Ítem marcado como terminado.');
    }

    /**
     * Decide en qué sede se fabrica una OP.
     *
     * Si el usuario eligió una, se respeta siempre que esa sede tenga fábrica.
     * Si no eligió, se usa la sede activa cuando produce; y si la sede activa
     * es de solo ventas, cae a la primera sede con producción (la principal).
     */
    private function resolverSedeFabrica(?int $elegida): ?int
    {
        if ($elegida) {
            $sede = \App\Models\Sede::find($elegida);

            if ($sede && $sede->tiene_produccion && $sede->activa) {
                return $sede->id;
            }
        }

        $activa = \App\Support\ContextoSede::actual();

        if ($activa && $activa->tiene_produccion) {
            return $activa->id;
        }

        return \App\Models\Sede::activas()
            ->conProduccion()
            ->orderByDesc('es_principal')
            ->value('id');
    }

    public function generarDesdeCotizacion(Request $request, Cotizacion $cotizacion): RedirectResponse
    {
        abort_if($cotizacion->en_produccion, 422, 'Esta cotización ya tiene una OP generada.');

        $request->validate([
            'anticipo'             => 'nullable|numeric|min:0',
            'anticipo_medio_pago'  => 'nullable|in:efectivo,transferencia,cheque',
            'anticipo_fecha_pago'  => 'nullable|date',
            'anticipo_referencia'  => 'nullable|string|max:200',
            'sede_id'              => 'nullable|exists:sedes,id',
        ]);

        // ¿En qué fábrica se produce? Una sede de solo ventas (ej. Cúcuta)
        // debe mandar la OP a una sede con producción.
        $sedeFabrica = $this->resolverSedeFabrica($request->integer('sede_id') ?: null);

        if (! $sedeFabrica) {
            return back()->with('error', 'No hay ninguna sede con producción configurada para fabricar esta OP.');
        }

        $cotizacion->load(['items.ensamble.plantilla', 'items.producto']);

        $svc = app(FormulaEvaluatorService::class);

        $op = Op::create([
            'sede_id'       => $sedeFabrica,
            'cliente_id'    => $cotizacion->cliente_id,
            'cotizacion_id' => $cotizacion->id,
            'responsable_id'=> auth()->id(),
            'estado'        => 'borrador',
            'fecha_creacion'=> now()->toDateString(),
            'anticipo'      => $request->anticipo ?? null,
        ]);

        foreach ($cotizacion->items as $i => $item) {
            $opItem = OpItem::create([
                'op_id'               => $op->id,
                'tipo'                => $item->tipo === 'texto_libre' ? 'servicio' : ($item->tipo === 'configuracion_puerta' ? 'producto' : $item->tipo),
                'producto_id'         => $item->producto_id,
                'ensamble_id'         => $item->ensamble_id,
                'orden'               => $i,
                'descripcion'         => $item->descripcion,
                'descripcion_larga'   => $item->descripcion_larga,
                'cantidad'            => $item->cantidad,
                'precio_unitario'     => $item->precio_unitario,
                'descuento_pct'       => $item->descuento_pct,
                'subtotal'            => $item->subtotal,
                'impuesto_pct'        => $item->impuesto_pct,
                'impuesto_valor'      => $item->impuesto_valor,
                'total_linea'         => $item->total_linea,
                'variables_instancia'  => $item->variables_instancia,
                'imagenes_instancia'   => $item->imagenes_instancia,
                'componentes_snapshot' => $this->enriquecerSnapshot(
                    $item->componentes_snapshot,
                    $item->ensamble_id,
                    $item->variables_instancia ?? [],
                    $svc
                ),
                'estado_item'          => 'pendiente',
            ]);

            // Fusión Plantillas de Ensamble <-> Trabajo: genera automáticamente
            // el/los OpItemTrabajo (uno por unidad) con sus pasos, sin que
            // nadie tenga que entrar a producción a elegir un template a mano.
            if ($opItem->ensamble_id) {
                app(TrabajoAutoGeneratorService::class)->generarParaItem($opItem);
            }

            // Generar componentes desde snapshot (solo los marcados visible_op)
            $snapshotEnriquecido = $this->enriquecerSnapshot(
                $item->componentes_snapshot,
                $item->ensamble_id,
                $item->variables_instancia ?? [],
                $svc
            );
            $snapshotParaOp = $snapshotEnriquecido ?? $item->componentes_snapshot;
            if ($snapshotParaOp) {
                $varsBase = array_merge(
                    (array) ($item->ensamble?->variables ?? []),
                    (array) ($item->variables_instancia ?? [])
                );

                // Obtener plantilla_id desde el ensamble ya eager-loaded con plantilla
                $plantillaId = $item->ensamble?->plantilla_id;

                $varsCompletas = $plantillaId
                    ? $svc->construirContexto($plantillaId, $varsBase)
                    : $varsBase;

                foreach ($snapshotParaOp as $comp) {
                    $visibleOp = $comp['visible_op'] ?? true;
                    if (!$visibleOp) continue;

                    if (!empty($comp['sub_formulas'])) {
                        // Padre: cantidad = formula_real si existe, sino formula
                        $cantidadPadre = ($comp['tiene_formula_real'] ?? false)
                            ? ($comp['cantidad_real'] ?? $comp['cantidad'] ?? 0)
                            : ($comp['cantidad'] ?? 0);

                        $padre = $opItem->componentes()->create([
                            'producto_id'    => $comp['producto_id'] ?? null,
                            'nombre'         => $comp['nombre'] ?? '',
                            'cantidad'       => $cantidadPadre,
                            'unidad'         => $comp['unidad'] ?? null,
                            'es_informativo' => true,
                            'seccion'        => $comp['seccion'] ?? null,
                        ]);

                        // Hijos: cada sub-fórmula evaluada con contexto completo
                        foreach ($comp['sub_formulas'] as $sub) {
                            $formulaHijo    = !empty($sub['formula_real']) ? $sub['formula_real'] : ($sub['formula'] ?? '');
                            $cantidadHijo   = $formulaHijo ? $svc->evaluar($formulaHijo, $varsCompletas) : 0;
                            $opItem->componentes()->create([
                                'producto_id'          => $comp['producto_id'] ?? null,
                                'nombre'               => $sub['etiqueta'],
                                'cantidad'             => $cantidadHijo,
                                'unidad'               => $sub['unidad'] ?? $comp['unidad'] ?? null,
                                'es_informativo'       => false,
                                'parent_componente_id' => $padre->id,
                            ]);
                        }
                    } else {
                        $opItem->componentes()->create([
                            'producto_id'    => $comp['producto_id'] ?? null,
                            'nombre'         => $comp['nombre'] ?? '',
                            'cantidad'       => $comp['cantidad'] ?? 0,
                            'unidad'         => $comp['unidad'] ?? null,
                            'es_informativo' => true,
                            'seccion'        => $comp['seccion'] ?? null,
                        ]);
                    }
                }
            }
        }

        $op->recalcularTotales();

        // Si se registró un anticipo al generar la OP, se convierte de inmediato
        // en una cuota + pago reales dentro del sistema financiero — así no hay
        // que volver a pedirlo cuando la OP se confirme.
        if ($request->filled('anticipo') && (float) $request->anticipo > 0) {
            DB::transaction(function () use ($op, $request) {
                $cuota = OpCuota::create([
                    'op_id'               => $op->id,
                    'numero_cuota'        => 1,
                    'concepto'            => 'Anticipo',
                    'valor'               => $request->anticipo,
                    'fecha_vencimiento'   => null,
                    'estado'              => 'pendiente',
                    'valor_pagado'        => 0,
                    'es_saldo_automatico' => false,
                ]);

                $pago = OpPago::create([
                    'op_id'          => $op->id,
                    'cuota_id'       => $cuota->id,
                    'numero_recibo'  => 'REC-TMP',
                    'valor'          => $request->anticipo,
                    'medio_pago'     => $request->anticipo_medio_pago ?? 'efectivo',
                    'fecha_pago'     => $request->anticipo_fecha_pago ?? now()->toDateString(),
                    'referencia'     => $request->anticipo_referencia,
                    'notas'          => 'Anticipo registrado al generar la OP desde la cotización.',
                    'registrado_por' => auth()->id(),
                ]);
                $pago->numero_recibo = 'REC-' . str_pad($pago->id, 4, '0', STR_PAD_LEFT);
                $pago->save();

                $cuota->valor_pagado = $cuota->valor;
                $cuota->estado       = 'pagado';
                $cuota->save();
            });

            $op->sincronizarSaldoPendiente();
        }

        $cotizacion->update([
            'estado'        => 'aprobada',
            'en_produccion' => true,
        ]);

        // Confirma la comisión del vendedor — antes solo pasaba si el estado
        // se cambiaba a mano desde la cotización; por este camino (el normal,
        // "Generar OP") se quedaba parada en "proyectada" para siempre.
        ComisionVendedor::where('cotizacion_id', $cotizacion->id)
            ->update(['estado' => 'confirmada']);

        // ── Notificaciones internas ──────────────────────────────────────
        $notif = app(\App\Services\NotificacionService::class);

        // Aviso a producción: hay una OP nueva para arrancar.
        $notif->paraRol(
            ['administrador', 'jefe_produccion'],
            'op_nueva',
            "Nueva OP {$op->numero}",
            "Cliente: " . ($op->cliente->nombre ?? '—') . ". Lista para producción.",
            "/produccion/ops/{$op->id}",
        );

        // Aviso si la OP ya nace con material faltante (para compras).
        $faltantes = $op->insumosFaltantes();
        if (! empty($faltantes)) {
            $notif->paraRol(
                ['administrador', 'jefe_produccion'],
                'material_faltante',
                "Falta material en la OP {$op->numero}",
                count($faltantes) . " insumo(s) por debajo del stock necesario.",
                "/produccion/ops/{$op->id}",
            );
        }

        return redirect("/produccion/ops/{$op->id}")
            ->with('success', "Orden {$op->numero} generada desde cotización {$cotizacion->numero}.");
    }

    public function generarPdf(Op $op): HttpResponse
    {
        // Plantilla PDF personalizada
        $plantillaId = request()->input('plantilla_id');
        $plantilla   = $plantillaId
            ? \App\Models\PdfPlantilla::find($plantillaId)
            : \App\Models\PdfPlantilla::defaultParaModulo('op');

        if ($plantilla) {
            $op->loadMissing(['cliente', 'items', 'responsable']);
            $datos = \App\Services\PdfVariablesEngine::prepararDatos('op', $op);
            $html  = \App\Services\PdfVariablesEngine::render($plantilla->html, $datos);
            $pdf   = \Barryvdh\DomPDF\Facade\Pdf::loadHtml($html)
                        ->setPaper($plantilla->papel ?? 'a4', $plantilla->orientacion ?? 'portrait');
            return $pdf->download("OP-{$op->numero}.pdf");
        }

        // Fallback: plantilla Blade original
        $op->load([
            'cliente',
            'items.ensamble.plantilla.campos',
            'items.ensamble.plantilla.secciones.componentes',
            'items.componentes.producto',
        ]);

        $fecha = now()->setTimezone('America/Bogota')->format('d/m/Y H:i');

        $urlOp = url('/op/' . $op->token_publico);
        $qrOp  = 'data:image/svg+xml;base64,' . base64_encode(
            QrCode::format('svg')->size(130)->generate($urlOp)
        );

        $itemsData = $op->items->values()->map(function ($item, $idx) use ($op) {
            $componentes = $item->componentes;

            // Mapa producto_id → nombre de sección desde la plantilla
            $secciones     = $item->ensamble?->plantilla?->secciones ?? collect();
            $mapaSecciones = collect();
            foreach ($secciones->sortBy('orden') as $seccion) {
                foreach ($seccion->componentes as $plantComp) {
                    if ($plantComp->producto_id) {
                        $mapaSecciones->put($plantComp->producto_id, $seccion->nombre);
                    }
                }
            }

            $padres = $componentes->whereNull('parent_componente_id')->values();
            $hijos  = $componentes->whereNotNull('parent_componente_id')
                                  ->groupBy('parent_componente_id');

            $jerarquia = $padres->map(function ($padre) use ($hijos, $mapaSecciones) {
                $padre->setRelation('hijos', $hijos->get($padre->id, collect()));
                $padre->seccion_resuelta = $mapaSecciones->get($padre->producto_id)
                    ?? ($padre->seccion ?? 'General');
                return $padre;
            });

            // Preservar orden de plantilla_secciones; General siempre al final
            $compsPorSeccion    = $jerarquia->groupBy(fn ($c) => $c->seccion_resuelta);
            $seccionesOrdenadas = $secciones->sortBy('orden')->pluck('nombre');
            $ordered            = collect();
            foreach ($seccionesOrdenadas as $nombre) {
                if ($compsPorSeccion->has($nombre)) {
                    $ordered->put($nombre, $compsPorSeccion->get($nombre));
                }
            }
            foreach ($compsPorSeccion as $key => $val) {
                if (!$ordered->has($key) && $key !== 'General') {
                    $ordered->put($key, $val);
                }
            }
            if ($compsPorSeccion->has('General')) {
                $ordered->put('General', $compsPorSeccion->get('General'));
            }

            $campos          = $item->ensamble?->plantilla?->campos ?? collect();
            $camposIndexados = $campos->keyBy('nombre');

            return [
                'item'            => $item,
                'compsPorSeccion' => $ordered,
                'camposIndexados' => $camposIndexados,
                'codigo_item'     => $op->numero . '-' . str_pad($idx + 1, 2, '0', STR_PAD_LEFT),
            ];
        });

        $pdf = Pdf::loadView('pdf.op', compact('op', 'itemsData', 'fecha', 'qrOp'))
            ->setPaper('letter', 'portrait');

        return $pdf->download('OP-' . $op->numero . '.pdf');
    }

    public function generarPdfItem(Op $op, OpItem $item): HttpResponse
    {
        $op->load(['cliente']);
        $item->load([
            'ensamble.plantilla.campos',
            'ensamble.plantilla.secciones.componentes',
            'componentes.producto',
        ]);

        $fecha = now()->setTimezone('America/Bogota')->format('d/m/Y H:i');

        $urlOp = url('/op/' . $op->token_publico);
        $qrOp  = 'data:image/svg+xml;base64,' . base64_encode(
            QrCode::format('svg')->size(130)->generate($urlOp)
        );

        $componentes = $item->componentes;

        $secciones     = $item->ensamble?->plantilla?->secciones ?? collect();
        $mapaSecciones = collect();
        foreach ($secciones->sortBy('orden') as $seccion) {
            foreach ($seccion->componentes as $plantComp) {
                if ($plantComp->producto_id) {
                    $mapaSecciones->put($plantComp->producto_id, $seccion->nombre);
                }
            }
        }

        $padres = $componentes->whereNull('parent_componente_id')->values();
        $hijos  = $componentes->whereNotNull('parent_componente_id')
                              ->groupBy('parent_componente_id');

        $jerarquia = $padres->map(function ($padre) use ($hijos, $mapaSecciones) {
            $padre->setRelation('hijos', $hijos->get($padre->id, collect()));
            $padre->seccion_resuelta = $mapaSecciones->get($padre->producto_id)
                ?? ($padre->seccion ?? 'General');
            return $padre;
        });

        $compsPorSeccion    = $jerarquia->groupBy(fn ($c) => $c->seccion_resuelta);
        $seccionesOrdenadas = $secciones->sortBy('orden')->pluck('nombre');
        $ordered            = collect();
        foreach ($seccionesOrdenadas as $nombre) {
            if ($compsPorSeccion->has($nombre)) {
                $ordered->put($nombre, $compsPorSeccion->get($nombre));
            }
        }
        foreach ($compsPorSeccion as $key => $val) {
            if (!$ordered->has($key) && $key !== 'General') {
                $ordered->put($key, $val);
            }
        }
        if ($compsPorSeccion->has('General')) {
            $ordered->put('General', $compsPorSeccion->get('General'));
        }

        $campos          = $item->ensamble?->plantilla?->campos ?? collect();
        $camposIndexados = $campos->keyBy('nombre');

        $todosItems = $op->items()->orderBy('orden')->pluck('id')->values();
        $idx        = $todosItems->search($item->id);
        $codigoItem = $op->numero . '-' . str_pad(($idx === false ? $item->orden : $idx) + 1, 2, '0', STR_PAD_LEFT);

        $itemsData = collect([[
            'item'            => $item,
            'compsPorSeccion' => $ordered,
            'camposIndexados' => $camposIndexados,
            'codigo_item'     => $codigoItem,
        ]]);

        $pdf = Pdf::loadView('pdf.op', compact('op', 'itemsData', 'fecha', 'qrOp'))
            ->setPaper('letter', 'portrait');

        return $pdf->download('OP-' . $op->numero . '-' . $codigoItem . '.pdf');
    }

    public function generarEtiqueta(Op $op, OpItem $item): HttpResponse
    {
        $op->load('cliente');
        $item->load('ensamble');

        $logoPath = Marca::logoPath();

        $urlOp = url('/op/' . $op->token_publico);
        $qrOp  = 'data:image/svg+xml;base64,' . base64_encode(
            QrCode::format('svg')->size(200)->generate($urlOp)
        );

        // 10cm x 13cm en puntos PDF (1cm ≈ 28.346pt)
        $pdf = Pdf::loadView('pdf.etiqueta_op', compact('op', 'item', 'logoPath', 'qrOp'))
            ->setPaper([0, 0, 283.46, 368.50], 'portrait');

        return $pdf->download('Etiqueta-' . $op->numero . '-Item' . $item->id . '.pdf');
    }

    // ── Privados ──────────────────────────────────────────────────────────────────

    private function enriquecerSnapshot(
        ?array $snapshot,
        ?int $ensambleId,
        array $variablesInstancia,
        FormulaEvaluatorService $svc
    ): ?array {
        if (!$snapshot || !$ensambleId) return $snapshot;

        try {
            $ensamble = \App\Models\Ensamble::with('plantilla')->find($ensambleId);
            if (!$ensamble?->plantilla_id) return $snapshot;

            // Merge variables del ensamble + instancia
            $vars = array_merge(
                (array) ($ensamble->variables ?? []),
                (array) $variablesInstancia
            );

            // Recalcular con formula_real
            $componentesRecalculados = $svc->calcularPlantilla(
                $ensamble->plantilla_id,
                $vars
            );

            // Enriquecer el snapshot original con cantidad_real y subtotal_real
            return collect($snapshot)->map(function ($snapComp) use ($componentesRecalculados) {
                $match = collect($componentesRecalculados)
                    ->firstWhere('nombre', $snapComp['nombre'] ?? '');

                if ($match) {
                    $snapComp['cantidad_real']      = $match['cantidad_real'];
                    $snapComp['subtotal_real']      = $match['subtotal_real'];
                    $snapComp['tiene_formula_real'] = $match['tiene_formula_real'];
                } else {
                    // Si no hay match, real = cotizado
                    $snapComp['cantidad_real']      = $snapComp['cantidad'] ?? 0;
                    $snapComp['subtotal_real']      = $snapComp['subtotal'] ?? 0;
                    $snapComp['tiene_formula_real'] = false;
                }
                return $snapComp;
            })->toArray();
        } catch (\Throwable $e) {
            \Log::error('enriquecerSnapshot error', ['error' => $e->getMessage()]);
            return $snapshot;
        }
    }

    private function validarForm(Request $request): array
    {
        return $request->validate([
            'cliente_id'             => 'nullable|exists:clientes,id',
            'cotizacion_id'          => 'nullable|exists:cotizaciones,id',
            'responsable_id'         => 'required|exists:users,id',
            'bodega_entrega_id'      => 'nullable|exists:bodegas,id',
            'bodega_material_id'     => 'nullable|exists:bodegas,id',
            'estado'                 => 'nullable|in:borrador,confirmada,en_produccion,calidad,reproceso,despachada',
            'fecha_creacion'         => 'required|date',
            'fecha_entrega_estimada' => 'nullable|date',
            'anticipo'               => 'nullable|numeric|min:0',
            'condiciones'            => 'nullable|string',
            'notas_internas'         => 'nullable|string',
            'items'                  => 'nullable|array',
            'items.*.tipo'           => 'required|in:producto,ensamble,servicio',
            'items.*.descripcion'    => 'required|string',
            'items.*.descripcion_larga' => 'nullable|string',
            'items.*.cantidad'       => 'required|numeric|min:0.001',
            'items.*.precio_unitario'=> 'nullable|numeric|min:0',
            'items.*.descuento_pct'  => 'nullable|numeric|min:0|max:100',
            'items.*.impuesto_pct'   => 'nullable|numeric|min:0|max:100',
            'items.*.orden'          => 'nullable|integer',
            'items.*.producto_id'    => ['nullable', new ProductoSeleccionable],
            'items.*.ensamble_id'    => 'nullable|exists:ensambles,id',
            'items.*.variables_instancia'  => 'nullable|array',
            'items.*.componentes_snapshot' => 'nullable|array',
            'items.*.numero_serie'   => 'nullable|string|max:100',
            'items.*.operario_id'    => 'nullable|exists:users,id',
            'items.*.estado_item'    => 'nullable|in:pendiente,en_proceso,terminado',
            'items.*.notas_item'     => 'nullable|string',
        ]);
    }

    private function syncItems(Op $op, array $items): void
    {
        $idsEnviados = [];

        foreach ($items as $i => $datos) {
            $item = isset($datos['id']) ? OpItem::find($datos['id']) : null;

            $precio   = $datos['precio_unitario'] ?? 0;
            $base     = $datos['cantidad'] * $precio;
            $desc     = $base * (($datos['descuento_pct'] ?? 0) / 100);
            $baseDesc = $base - $desc;
            $impuesto = $baseDesc * (($datos['impuesto_pct'] ?? 0) / 100);

            $fill = [
                'op_id'               => $op->id,
                'tipo'                => $datos['tipo'],
                'producto_id'         => $datos['producto_id'] ?? null,
                'ensamble_id'         => $datos['ensamble_id'] ?? null,
                'orden'               => $datos['orden'] ?? $i,
                'descripcion'         => $datos['descripcion'],
                'descripcion_larga'   => $datos['descripcion_larga'] ?? null,
                'cantidad'            => $datos['cantidad'],
                'precio_unitario'     => $precio,
                'descuento_pct'       => $datos['descuento_pct'] ?? 0,
                'subtotal'            => $base,
                'impuesto_pct'        => $datos['impuesto_pct'] ?? 0,
                'impuesto_valor'      => $impuesto,
                'total_linea'         => $baseDesc + $impuesto,
                'variables_instancia' => $datos['variables_instancia'] ?? null,
                'componentes_snapshot'=> $datos['componentes_snapshot'] ?? null,
                'numero_serie'        => $datos['numero_serie'] ?? null,
                'operario_id'         => $datos['operario_id'] ?? null,
                'estado_item'         => $datos['estado_item'] ?? 'pendiente',
                'notas_item'          => $datos['notas_item'] ?? null,
            ];

            if ($item && $item->op_id === $op->id) {
                $item->update($fill);
                $idsEnviados[] = $item->id;
            } else {
                $nuevo = OpItem::create($fill);
                $idsEnviados[] = $nuevo->id;

                // Igual que en generarDesdeCotizacion: genera el trabajo
                // automáticamente para ítems nuevos vinculados a un ensamble.
                if ($nuevo->ensamble_id) {
                    app(TrabajoAutoGeneratorService::class)->generarParaItem($nuevo);
                }
            }
        }

        $op->items()->whereNotIn('id', $idsEnviados)->delete();
    }
}
