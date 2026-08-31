<?php

namespace App\Http\Controllers;

use App\Models\Op;
use App\Models\OpItemTrabajo;
use App\Models\OpItemTrabajoCheck;
use App\Services\NotificacionService;
use App\Support\Urgencia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El módulo de Calidad: la revisión que hay que pasar antes de poder remisionar.
 *
 * Calidad se revisa **por unidad física**, no por orden: una OP de diez puertas tiene diez
 * unidades y cada una se mira aparte. Por eso el tablero es una ficha grande por unidad, con
 * un botón por punto de revisión — el mismo gesto de la planta, marcar y seguir, sin abrir un
 * formulario para cada cosa.
 *
 * Lo que ya existía y aquí se reutiliza: `OpItemTrabajoCheck` guarda lo revisado de cada
 * unidad y `CalidadCheckController` marca punto por punto. Este controlador pone encima el
 * tablero, la ficha de verificación con toda la información del proyecto, y los dos cierres:
 * el de una unidad y el de la orden entera.
 */
class CalidadController extends Controller
{
    /**
     * El tablero: una ficha grande por unidad que espera revisión.
     *
     * Entra lo que ya se fabricó —el trabajo llegó al 100 %— de órdenes que todavía no se
     * despacharon. Una unidad a medio fabricar no se revisa: no hay qué mirar.
     */
    public function index(Request $request): Response
    {
        $unidades = $this->unidades($request);

        return Inertia::render('Calidad/Index', [
            'fichas'   => $unidades->map(fn ($t) => $this->ficha($t))->values(),
            'ops'      => $this->resumenPorOp($unidades),
            'filtros'  => [
                'op_numero' => $request->input('op_numero', ''),
                'estado'    => $request->input('estado', 'pendientes'),
            ],
            'metricas' => $this->metricas($request),
        ]);
    }

    /** El mismo tablero, en JSON: filtrar y refrescar no tienen por qué recargar la pantalla. */
    public function datos(Request $request): JsonResponse
    {
        $unidades = $this->unidades($request);

        return response()->json([
            'fichas'   => $unidades->map(fn ($t) => $this->ficha($t))->values(),
            'ops'      => $this->resumenPorOp($unidades),
            'metricas' => $this->metricas($request),
        ]);
    }

    /**
     * La ficha de verificación de UNA unidad: todo lo que hace falta para poder juzgarla.
     *
     * Aquí va lo que en el tablero no cabe —las medidas de esta unidad, la receta con la que
     * se armó, quién hizo cada paso y las fotos que dejó—, porque una revisión de calidad sin
     * la información del proyecto es marcar casillas a ciegas.
     */
    public function show(OpItemTrabajo $trabajo): Response
    {
        $trabajo->load([
            'opItem.op.cliente',
            'opItem.op.responsable:id,name',
            'opItem.producto',
            'opItem.ensamble.plantilla.campos',
            'template',
            'pasos.operario',
            'pasos.operarios.operario',
            'checks.revisadoPor:id,name',
        ]);

        $op   = $trabajo->opItem?->op;
        $item = $trabajo->opItem;

        return Inertia::render('Calidad/Show', [
            'ficha' => $this->ficha($trabajo),
            'op'    => $op ? [
                'id'                    => $op->id,
                'numero'                => $op->numero,
                'estado'                => $op->estado,
                'cliente'               => $op->cliente?->nombre,
                'responsable'           => $op->responsable?->name,
                'fecha_creacion'        => $op->fecha_creacion?->format('d/m/Y'),
                'fecha_entrega'         => $op->fecha_entrega_estimada?->format('d/m/Y'),
                'calidad_aprobada_at'   => $op->calidad_aprobada_at?->format('d/m/Y H:i'),
                'observaciones_calidad' => $op->observaciones_calidad,
                'motivo_rechazo'        => $op->motivo_rechazo,
                'condiciones'           => $op->condiciones,
                'notas_internas'        => $op->notas_internas,
            ] : null,
            'item' => $item ? [
                'descripcion'       => $item->descripcion,
                'descripcion_larga' => $item->descripcion_larga,
                'notas'             => $item->notas_item,
                'numero_serie'      => $item->numero_serie,
                'ensamble'          => $item->ensamble?->nombre,
                'producto'          => $item->producto?->nombre,
                'imagenes'          => collect($item->imagenes_instancia ?? [])
                    ->map(fn ($f) => is_string($f) && str_starts_with($f, 'http') ? $f : Storage::url($f))
                    ->values()->all(),
                // La receta con la que se armó, ya congelada. Es contra esto que calidad
                // compara la unidad que tiene enfrente.
                'componentes'       => collect($item->componentes_snapshot ?? [])
                    ->map(fn ($c) => [
                        'nombre'   => $c['nombre'] ?? '—',
                        'cantidad' => $c['cantidad_real'] ?? $c['cantidad'] ?? null,
                        'unidad'   => $c['unidad'] ?? null,
                    ])->values()->all(),
            ] : null,
            'pasos' => $trabajo->pasos->map(fn ($p) => [
                'id'            => $p->id,
                'nombre'        => $p->nombre,
                'descripcion'   => $p->descripcion_resuelta,
                'completado'    => (bool) $p->completado,
                'completado_at' => $p->completado_at?->format('d/m/Y H:i'),
                'duracion'      => $p->duracionRealMinutos(),
                'es_paso_final' => (bool) $p->es_paso_final,
                'operarios'     => $p->operarios->map(fn ($o) => $o->operario?->nombre)->filter()->values(),
                'operario'      => $p->operario?->nombre,
                'fotos'         => collect($p->fotos ?? [])->map(fn ($f) => Storage::url($f))->all(),
            ])->values(),
        ]);
    }

    /**
     * Cierra la revisión de UNA unidad: los puntos que quedaban sin mirar quedan en cumple.
     *
     * Es el botón «Terminar» del tablero, y existe porque revisar una puerta es mirarla entera
     * de una vez: obligar a marcar ocho casillas idénticas una por una hace que se marquen sin
     * mirar, que es justo lo contrario de lo que sirve.
     *
     * Un punto que exige foto **no entra**: se devuelve la lista de los que faltan para que la
     * pantalla pida la foto y vuelva a intentar. Ese es el punto que después se discute con el
     * cliente, y una palabra contra otra no resuelve nada.
     */
    public function terminarUnidad(OpItemTrabajo $trabajo): JsonResponse
    {
        $pendientes = $trabajo->checks()->where('resultado', 'pendiente')->get();

        $sinFoto = $pendientes->filter(fn ($c) => $c->exige_foto && empty($c->fotos));

        if ($sinFoto->isNotEmpty()) {
            return response()->json([
                'message' => $sinFoto->count() === 1
                    ? 'Falta la foto de «' . $sinFoto->first()->titulo . '» para poder cerrar esta unidad.'
                    : 'Faltan las fotos de ' . $sinFoto->count() . ' puntos para poder cerrar esta unidad.',
                'exigen_foto' => $sinFoto
                    ->map(fn ($c) => app(CalidadCheckController::class)->fila($c))
                    ->values(),
            ], 422);
        }

        foreach ($pendientes as $check) {
            $check->update([
                'resultado'    => 'cumple',
                'revisado_por' => auth()->id(),
                'revisado_at'  => now(),
            ]);
        }

        // La firma es lo que abre el despacho, y va aunque la unidad no tenga ni un punto que
        // revisar: alguien la miró y la dio por buena, que es de lo que se trata.
        $trabajo->firmarCalidad();

        $this->sellarSiTermino($trabajo->opItem?->op);

        return response()->json([
            'ficha' => $this->ficha($this->recargar($trabajo)),
        ]);
    }

    /** Devuelve una unidad a pendiente entera: se revisó por error, o el reproceso la cambió. */
    public function reabrirUnidad(OpItemTrabajo $trabajo): JsonResponse
    {
        $trabajo->checks()->update([
            'resultado'    => 'pendiente',
            'revisado_por' => null,
            'revisado_at'  => null,
        ]);

        $trabajo->firmarCalidad(false);

        // El sello de la orden se cae con ella: decir que está aprobada cuando una de sus
        // unidades volvió a estar sin revisar es mentir en el único sitio donde no se puede.
        $trabajo->opItem?->op?->update(['calidad_aprobada_at' => null]);

        return response()->json([
            'ficha' => $this->ficha($this->recargar($trabajo)),
        ]);
    }

    /**
     * Cierra la revisión de la ORDEN entera y sella `calidad_aprobada_at`.
     *
     * Ese sello es el candado del despacho: sin él no hay remisión. Por eso no se puede poner
     * con puntos sin resolver, y se dice con números cuántos faltan — en una orden de diez
     * puertas, «falta algo» no le sirve a nadie.
     */
    public function terminarOp(Request $request, Op $op): RedirectResponse
    {
        $request->validate(['observaciones_calidad' => 'nullable|string|max:2000']);

        $bloquean = $this->checksQueBloquean($op);

        if ($bloquean > 0) {
            return back()->withErrors([
                'calidad' => "Faltan {$bloquean} punto(s) por resolver en las unidades de esta orden. "
                    . 'Un punto crítico que falla tampoco deja cerrar: mándala a reproceso.',
            ]);
        }

        $op->update([
            'observaciones_calidad' => $request->input('observaciones_calidad') ?: $op->observaciones_calidad,
            'motivo_rechazo'        => null,
            'calidad_aprobada_at'   => now(),
        ]);

        // Cerrar la orden firma sus unidades: es el atajo de la orden entera, igual que
        // «Terminar» lo es de una. Sin esto el sello de la orden no abriría ningún despacho,
        // porque quien decide eso es la firma de cada unidad.
        foreach (OpItemTrabajo::whereHas('opItem', fn ($q) => $q->where('op_id', $op->id))->get() as $unidad) {
            $unidad->firmarCalidad();
        }

        app(NotificacionService::class)->paraRol(
            ['administrador', 'jefe_produccion'],
            'op_lista_despacho',
            "OP {$op->numero} lista para despachar",
            'Calidad aprobada. Ya se puede generar la remisión.',
            "/produccion/ops/{$op->id}",
        );

        return back()->with('success', "Calidad cerrada en {$op->numero} — ya se puede remisionar.");
    }

    /**
     * Devuelve la orden a reproceso, y **reabre las unidades que fallaron**.
     *
     * Mandar a reproceso era cambiar una etiqueta: la orden decía «reproceso» y en planta no
     * pasaba nada. Las unidades seguían al 100 %, así que no aparecían como trabajo pendiente
     * en ningún lado, y volver a producción dependía de que alguien se acordara.
     *
     * Ahora se reabre el paso de entrega de cada unidad con una falla. Con eso la unidad baja
     * del 100 %, vuelve a salir en Trabajos con su código QR, y sale del listado de lo que se
     * puede despachar — que es lo que de verdad significa «hay que rehacerla».
     *
     * **Las fallas no se borran.** La observación de qué salió mal es justo lo que quien
     * corrige necesita leer, y lo que hace falta si el cliente reclama. Calidad las cambia a
     * cumplido cuando vuelva a mirar la unidad, y ahí la orden se sella sola.
     *
     * Lo que **no** hace: devolver la unidad de la bodega ni reponer el material. Si ya se
     * había entregado, la puerta existe en un estante y su material se gastó. Lo que consuma
     * la reparación no queda registrado — eso es un ajuste de inventario, y es una decisión de
     * quien cuenta el estante.
     */
    public function reprocesar(Request $request, Op $op): RedirectResponse
    {
        $data = $request->validate(['motivo_rechazo' => 'required|string|max:2000']);

        $unidades = $op->unidadesEnReproceso();

        $op->update([
            'motivo_rechazo'      => $data['motivo_rechazo'],
            'calidad_aprobada_at' => null,
            'estado'              => 'reproceso',
        ]);

        $cierre = app(\App\Services\CierrePasoService::class);

        foreach ($unidades as $unidad) {
            $final = $unidad->pasos()->where('es_paso_final', true)->where('completado', true)->first();

            if ($final) {
                $cierre->reabrir($final);
            }

            // Y pierde la firma de calidad: una unidad que vuelve a planta no está aprobada.
            $unidad->firmarCalidad(false);
        }

        $cuantas = $unidades->count();

        app(NotificacionService::class)->paraRol(
            ['administrador', 'jefe_produccion'],
            'op_a_reproceso',
            "OP {$op->numero} volvió a reproceso",
            $cuantas > 0
                ? "{$cuantas} unidad(es) para rehacer. Motivo: {$data['motivo_rechazo']}"
                : "Motivo: {$data['motivo_rechazo']}",
            "/trabajos?op_numero={$op->numero}",
        );

        return back()->with('success', $cuantas > 0
            ? "{$op->numero} volvió a reproceso: {$cuantas} unidad(es) reabiertas en Trabajos."
            : "{$op->numero} volvió a reproceso.");
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Interno
    // ─────────────────────────────────────────────────────────────────────────────

    /**
     * Las unidades que le tocan a calidad.
     *
     * Se filtra por la sede activa como todo lo demás; si algo «no aparece», la sede es lo
     * primero que hay que mirar. El orden lo pone la fecha de entrega —lo que se despacha
     * antes se revisa antes— y se resuelve sobre la colección: son las unidades fabricadas y
     * sin despachar de una empresa, no una tabla histórica.
     */
    private function unidades(Request $request, bool $conFiltroEstado = true): Collection
    {
        $query = OpItemTrabajo::with([
                'opItem.op.cliente',
                'opItem.ensamble.plantilla.campos',
                'template',
                'checks.revisadoPor:id,name',
            ])
            ->where('porcentaje_avance', 100)
            ->whereHas('opItem.op', fn ($q) => $q->whereNotIn('estado', ['borrador', 'despachada']));

        if ($sede = \App\Support\ContextoSede::id()) {
            $query->whereHas('opItem.op', fn ($q) => $q->where('sede_id', $sede));
        }

        if ($request->filled('op_numero')) {
            $query->whereHas('opItem.op', fn ($q) => $q->where('numero', 'like', '%' . $request->input('op_numero') . '%'));
        }

        // Por omisión el tablero muestra lo que falta por revisar: es la bandeja de trabajo,
        // no el archivo. Lo ya cerrado se pide aparte.
        $estado = $conFiltroEstado ? $request->input('estado', 'pendientes') : 'todas';

        // «Pendiente» es **sin firmar**, y no «con puntos pendientes». La diferencia se lo
        // llevaba todo: una unidad sin lista de revisión no tiene puntos, así que nunca
        // entraba al tablero — y como no entraba, no había dónde aprobarla ni forma de
        // despacharla. En una instalación real esas son la mayoría.
        if ($estado === 'pendientes') {
            $query->whereNull('calidad_revisada_at');
        } elseif ($estado === 'fallas') {
            $query->whereHas('checks', fn ($q) => $q->where('resultado', 'falla'));
        } elseif ($estado === 'listas') {
            $query->whereNotNull('calidad_revisada_at');
        }

        return $query->limit(300)->get()->sortBy([
            fn ($a, $b) => $this->claveFecha($a) <=> $this->claveFecha($b),
            fn ($a, $b) => ($a->op_item_id <=> $b->op_item_id),
            fn ($a, $b) => (($a->numero_unidad ?? 1) <=> ($b->numero_unidad ?? 1)),
        ])->values();
    }

    /** Sin fecha de entrega va al final: no es urgente, es que nadie la puso. */
    private function claveFecha(OpItemTrabajo $trabajo): string
    {
        return $trabajo->opItem?->op?->fecha_entrega_estimada?->format('Y-m-d') ?? '9999-12-31';
    }

    /**
     * Las métricas cuentan **todo lo que le toca a calidad**, no lo que dejó ver el filtro.
     *
     * Las tarjetas son botones de filtro: si contaran el resultado del filtro activo, «Revisadas»
     * marcaría cero mientras se miran las pendientes, y ese cero se lee como «no hay ninguna
     * revisada» en vez de «no estás viendo ninguna».
     */
    private function metricas(Request $request): array
    {
        $unidades = $this->unidades($request, false);

        return [
            'unidades'   => $unidades->count(),
            'pendientes' => $unidades->filter(fn ($t) => ! $t->calidad_revisada_at)->count(),
            'fallas'     => $unidades->filter(fn ($t) => $t->checks->contains(fn ($c) => $c->resultado === 'falla'))->count(),
            'listas'     => $unidades->filter(fn ($t) => (bool) $t->calidad_revisada_at)->count(),
            'ops'        => $unidades->map(fn ($t) => $t->opItem?->op_id)->filter()->unique()->count(),
        ];
    }

    /** El resumen por orden: es donde vive el botón que cierra la revisión completa. */
    private function resumenPorOp(Collection $unidades): array
    {
        return $unidades
            ->groupBy(fn ($t) => $t->opItem?->op_id)
            ->map(function ($grupo) {
                $op     = $grupo->first()->opItem?->op;
                $checks = $grupo->flatMap->checks;

                return [
                    'id'                  => $op?->id,
                    'numero'              => $op?->numero,
                    'cliente'             => $op?->cliente?->nombre,
                    'estado'              => $op?->estado,
                    'fecha_entrega'       => $op?->fecha_entrega_estimada?->format('d/m/Y'),
                    'urgencia'            => Urgencia::de($op?->fecha_entrega_estimada),
                    'calidad_aprobada_at' => $op?->calidad_aprobada_at?->format('d/m/Y H:i'),
                    'unidades'            => $grupo->count(),
                    'puntos'              => $checks->count(),
                    'resueltos'           => $checks->where('resultado', '!=', 'pendiente')->count(),
                    'bloquean'            => $checks->filter(fn ($c) => $c->bloquea())->count(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Una ficha del tablero.
     *
     * Las medidas salen de `variables_instancia` con la etiqueta que les puso la plantilla:
     * «ancho_vano» no le dice nada a nadie en planta, «Ancho del vano» sí.
     */
    private function ficha(OpItemTrabajo $trabajo): array
    {
        $item   = $trabajo->opItem;
        $op     = $item?->op;
        $campos = $item?->ensamble?->plantilla?->campos ?? collect();
        $checks = $trabajo->checks;

        return [
            'id'                  => $trabajo->id,
            'op_id'               => $op?->id,
            'op_numero'           => $op?->numero,
            'op_estado'           => $op?->estado,
            'cliente'             => $op?->cliente?->nombre,
            'titulo'              => $item?->descripcion ?? $item?->ensamble?->nombre ?? 'Unidad',
            'ensamble'            => $item?->ensamble?->nombre,
            'numero_unidad'       => $trabajo->numero_unidad ?? 1,
            'total_unidades'      => $trabajo->total_unidades ?? 1,
            'fecha_entrega'       => $op?->fecha_entrega_estimada?->format('d/m/Y'),
            'urgencia'            => Urgencia::de($op?->fecha_entrega_estimada),
            'calidad_aprobada_at' => $op?->calidad_aprobada_at?->format('d/m/Y H:i'),
            'variables'           => collect($item?->variables_instancia ?? [])
                ->map(fn ($valor, $clave) => [
                    'clave'    => $clave,
                    'etiqueta' => ($campos->firstWhere('nombre', $clave))?->etiqueta ?? $clave,
                    'valor'    => is_array($valor) ? implode(' · ', $valor) : $valor,
                ])->values()->all(),
            'checks'       => $checks->map(fn ($c) => app(CalidadCheckController::class)->fila($c))->values(),
            'total_checks' => $checks->count(),
            'resueltos'    => $checks->where('resultado', '!=', 'pendiente')->count(),
            'fallas'       => $checks->where('resultado', 'falla')->count(),
            // Una unidad que calidad rechazó y que planta está rehaciendo. No es un campo
            // guardado: la revisión ya lo dice, punto por punto.
            'en_reproceso' => $op?->estado === 'reproceso' && $checks->where('resultado', 'falla')->isNotEmpty(),
            // La firma de calidad de esta unidad: es lo que decide si el botón dice «Terminar»
            // o «Terminada», y lo que abre su despacho.
            'revisada'     => (bool) $trabajo->calidad_revisada_at,
            'revisada_at'  => $trabajo->calidad_revisada_at?->format('d/m/Y H:i'),
            // Cuándo llegó a calidad es cuándo salió de producción: es el mismo instante.
            'recibida_at'  => $trabajo->terminado_at?->format('d/m/Y H:i'),
            'iniciado_at'  => $trabajo->iniciado_at?->format('d/m/Y H:i'),
            'bloquean'     => $checks->filter(fn ($c) => $c->bloquea())->count(),
            'porcentaje'   => $checks->count() > 0
                ? (int) round($checks->where('resultado', '!=', 'pendiente')->count() / $checks->count() * 100)
                : 0,
        ];
    }

    /** Lo que impide dar la orden por buena: sin revisar, o crítico que falló. */
    private function checksQueBloquean(Op $op): int
    {
        return OpItemTrabajoCheck::whereHas(
            'trabajo.opItem', fn ($q) => $q->where('op_id', $op->id)
        )->where(function ($q) {
            $q->where('resultado', 'pendiente')
              ->orWhere(fn ($q2) => $q2->where('resultado', 'falla')->where('es_critico', true));
        })->count();
    }

    /** Cuando ya no queda nada por resolver en la orden, el sello se pone solo. */
    private function sellarSiTermino(?Op $op): void
    {
        if (! $op) {
            return;
        }

        if ($this->checksQueBloquean($op) === 0 && ! $op->calidad_aprobada_at) {
            $op->update(['calidad_aprobada_at' => now()]);
        }
    }

    private function recargar(OpItemTrabajo $trabajo): OpItemTrabajo
    {
        return $trabajo->fresh([
            'opItem.op.cliente',
            'opItem.ensamble.plantilla.campos',
            'checks.revisadoPor:id,name',
        ]);
    }
}
