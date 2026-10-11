<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Op;
use App\Models\OpItem;
use App\Models\OpItemTrabajo;
use App\Models\Remision;
use App\Models\RemisionItem;
use App\Rules\ProductoSeleccionable;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class RemisionController extends Controller
{
    public function index(Request $request)
    {
        $query = \App\Support\ContextoSede::aplicar(Remision::query())
            ->with(['op:id,numero', 'cliente:id,nombre,apellido', 'items', 'sede:id,nombre']);

        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }
        if ($request->filled('tipo')) {
            $query->where('tipo', $request->tipo);
        }

        // El orden lo pide la pantalla. El campo se valida contra esta lista: lo que
        // llegue por `?orden=` y no esté aquí se ignora y nunca toca el SQL.
        $orden = \App\Support\Orden::aplicar($query, $request, [
            'numero'     => 'numero',
            'estado'     => 'estado',
            'created_at' => 'created_at',
        ]);

        $remisiones = $query->paginate(20)->withQueryString();

        $pendientes = $this->pendientesPorRemisionar();

        return Inertia::render('Logistica/Remisiones/Index', [
            'remisiones' => $remisiones,
            'orden' => $orden,
            'filtros'    => $request->only(['estado', 'tipo']),
            // Lo que ya está listo y todavía no tiene remisión: la otra mitad del trabajo de
            // despacho. Sin esto había que entrar orden por orden a ver qué se podía mandar.
            'pendientes' => $pendientes,
            'vista'      => in_array($request->query('vista'), ['pendientes', 'creadas'], true)
                ? $request->query('vista')
                : ($pendientes->isNotEmpty() ? 'pendientes' : 'creadas'),
        ]);
    }

    public function create(Request $request)
    {
        $op = null;
        if ($request->filled('op_id')) {
            // Lo que se ofrece es lo que está listo: productos alistados y unidades firmadas por
            // calidad. Antes solo se miraban las unidades, así que **una orden de productos
            // alistados no ofrecía nada** y se devolvía a la orden con un error.
            $op = Op::with([
                'items' => fn ($q) => $this->itemsRemisionables($q)->orderBy('orden'),
                'items.trabajos',
                'cliente',
            ])->findOrFail($request->op_id);

            $op->items->each(function ($item) {
                $item->unidades_disponibles  = $item->cantidadDisponible();
                $item->unidades_completadas  = $item->unidadesCompletadas();
                $item->unidades_remisionadas = $item->unidadesRemisionadas();
                $item->total                 = (int) $item->cantidad;
                // Un producto que nadie alistó todavía: la remisión lo alista sola al guardarse.
                $item->sin_alistar           = $item->sinTrabajos() && $item->estado_item !== 'terminado';
            });

            // Ninguna unidad lista. Se dice por qué —está a medio fabricar, o esperando
            // calidad— en vez de mostrar una pantalla vacía que parece un error.
            if ($op->items->isEmpty()) {
                $fabricadas = OpItemTrabajo::whereHas('opItem', fn ($q) => $q->where('op_id', $op->id))
                    ->where('porcentaje_avance', 100)->where('remisionado', false)->count();

                return redirect("/produccion/ops/{$op->id}")->with('error', $fabricadas > 0
                    ? "Esta orden tiene {$fabricadas} unidad(es) fabricadas, pero ninguna pasó control de calidad "
                        . 'todavía. Revísalas en Calidad y vuelve: se puede remisionar lo que ya esté aprobado, '
                        . 'sin esperar al resto.'
                    : 'Esta orden no tiene nada para remisionar todavía: lo que pasa por planta se remisiona al '
                        . 'terminar sus pasos y pasar calidad, y lo que ya salió no vuelve a ofrecerse.');
            }
        }

        $clientes = Cliente::select('id', 'nombre', 'apellido', 'tipo')
            ->orderBy('nombre')->get()
            ->map(fn ($c) => ['id' => $c->id, 'nombre' => $c->nombreCompleto()]);

        return Inertia::render('Logistica/Remisiones/Create', [
            'op'       => $op,
            'clientes' => $clientes,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'tipo'           => 'required|in:op,manual',
            'op_id'          => 'nullable|exists:ops,id',
            'cliente_id'     => 'nullable|exists:clientes,id',
            'fecha_remision' => 'nullable|date',
            'notas'          => 'nullable|string',
            'items'          => 'required|array|min:1',
            'items.*.descripcion'       => 'required|string',
            'items.*.cantidad'          => 'required|numeric|min:0.001',
            'items.*.cantidad_unidades' => 'nullable|integer|min:1',
            'items.*.unidad'            => 'nullable|string',
            'items.*.numero_serie'      => 'nullable|string',
            'items.*.notas'             => 'nullable|string',
            'items.*.op_item_id'        => 'nullable|exists:op_items,id',
            'items.*.producto_id'       => ['nullable', new ProductoSeleccionable],
        ]);

        // Validar unidades disponibles para ítems de OP
        foreach ($request->items as $idx => $item) {
            if (!empty($item['op_item_id'])) {
                $opItem = OpItem::with('trabajos')->find($item['op_item_id']);

                // Un ítem de otra orden, o un servicio, no viaja en esta remisión.
                if ($opItem && ((int) $opItem->op_id !== (int) $request->op_id || in_array($opItem->tipo, Op::TIPOS_SIN_DESPACHO, true))) {
                    return back()->withErrors([
                        "items.{$idx}.op_item_id" => 'Este ítem no se puede remisionar con esta orden.',
                    ])->withInput();
                }

                if ($opItem) {
                    $disponible = $opItem->cantidadDisponible();
                    $solicitado = (int) ($item['cantidad_unidades'] ?? $item['cantidad']);
                    if ($solicitado > $disponible) {
                        return back()->withErrors([
                            "items.{$idx}.cantidad_unidades" => "Las unidades solicitadas ({$solicitado}) superan las disponibles ({$disponible}).",
                        ])->withInput();
                    }
                }
            }
        }

        $remision = DB::transaction(function () use ($request) {
            $remision = Remision::create([
                'tipo'           => $request->tipo,
                'op_id'          => $request->op_id,
                'cliente_id'     => $request->cliente_id,
                'fecha_remision' => $request->fecha_remision ?? today(),
                'notas'          => $request->notas,
                'created_by'     => auth()->id(),
            ]);

            $hayOpItems = false;
            foreach ($request->items as $item) {
                RemisionItem::create([
                    'remision_id'  => $remision->id,
                    'op_item_id'   => $item['op_item_id'] ?? null,
                    'producto_id'  => $item['producto_id'] ?? null,
                    'descripcion'  => $item['descripcion'],
                    'cantidad'     => $item['cantidad_unidades'] ?? $item['cantidad'],
                    'unidad'       => $item['unidad'] ?? 'und',
                    'numero_serie' => $item['numero_serie'] ?? null,
                    'notas'        => $item['notas'] ?? null,
                ]);

                if (!empty($item['op_item_id'])) {
                    $hayOpItems      = true;
                    $cantidadUnidades = (int) ($item['cantidad_unidades'] ?? $item['cantidad']);
                    $opItem           = OpItem::with('trabajos')->find($item['op_item_id']);

                    if ($opItem && $cantidadUnidades > 0) {
                        // Marcar los N trabajos completados no remisionados como remisionados
                        $trabajosARemisionar = $opItem->trabajos()
                            ->disponiblesParaRemision()
                            ->orderBy('numero_unidad')
                            ->take($cantidadUnidades)
                            ->get();

                        foreach ($trabajosARemisionar as $t) {
                            $t->update(['remisionado' => true]);
                        }

                        // Recalcular campos del ítem. Un producto no tiene trabajos que
                        // contar, así que lleva su propia cuenta: si se contara por trabajos,
                        // su `cantidad_remisionada` volvería a cero en cada remisión y se
                        // podría despachar la misma unidad muchas veces.
                        $totalTrabajos     = $opItem->trabajos()->count();
                        $totalRemisionados = $totalTrabajos > 0
                            ? $opItem->trabajos()->where('remisionado', true)->count()
                            : (int) $opItem->cantidad_remisionada + $cantidadUnidades;
                        // «Completo» es que **no quede ninguna unidad sin remisionar**, y no que
                        // no queden disponibles. Desde que el candado de calidad es por unidad,
                        // «sin disponibles» también significa «las que faltan siguen en
                        // revisión»: con el criterio viejo, despachar tres de diez marcaba el
                        // ítem entero como remisionado y las otras siete desaparecían del
                        // remisionador.
                        $estaCompleto      = $totalTrabajos > 0
                            ? $opItem->trabajos()->where('remisionado', false)->doesntExist()
                            : $totalRemisionados >= (int) $opItem->cantidad;

                        $opItem->update([
                            'cantidad_remisionada' => $totalRemisionados,
                            'remisionado'          => $estaCompleto,
                            'remision_id'          => $estaCompleto ? $remision->id : $opItem->remision_id,
                            // Remisionar un producto es la prueba de que está listo: queda
                            // alistado sin que nadie tenga que pasar antes por Alistamiento.
                            'estado_item'          => $totalTrabajos === 0 ? 'terminado' : $opItem->estado_item,
                        ]);
                    }
                }
            }

            // Crear la remisión no despacha la orden: se despacha cuando la remisión **sale**.
            if ($hayOpItems && $request->op_id) {
                Op::find($request->op_id)?->sincronizarEstado();
            }

            return $remision;
        });

        // A la remisión recién creada: lo siguiente es confirmarla, firmarla y despacharla, y
        // devolver a la lista obligaba a buscarla para seguir.
        return redirect("/logistica/remisiones/{$remision->id}")->with('success', 'Remisión creada. Confírmala y márcala en camino cuando salga.');
    }

    public function show(Remision $remision)
    {
        $remision->load([
            'op:id,numero,estado',
            'cliente:id,nombre,apellido,tipo',
            'items.opItem',
            'creadoPor:id,name',
        ]);

        return Inertia::render('Logistica/Remisiones/Show', [
            'remision' => $this->formatRemision($remision),
        ]);
    }

    public function edit(Remision $remision)
    {
        abort_if($remision->estado !== 'borrador', 403, 'Solo se puede editar en borrador.');

        $remision->load(['op.items', 'cliente', 'items']);

        $clientes = Cliente::select('id', 'nombre', 'apellido', 'tipo')
            ->orderBy('nombre')->get()
            ->map(fn ($c) => ['id' => $c->id, 'nombre' => $c->nombreCompleto()]);

        return Inertia::render('Logistica/Remisiones/Edit', [
            'remision' => $this->formatRemision($remision),
            'clientes' => $clientes,
        ]);
    }

    public function update(Request $request, Remision $remision)
    {
        abort_if($remision->estado !== 'borrador', 403);

        $request->validate([
            'fecha_remision'       => 'nullable|date',
            'notas'                => 'nullable|string',
            'transportista'        => 'nullable|string|max:255',
            'celular_transportista'=> 'nullable|string|max:30',
            'placa'                => 'nullable|string|max:20',
            'costo_flete'          => 'nullable|numeric|min:0',
        ]);

        $remision->update($request->only([
            'fecha_remision', 'notas', 'transportista',
            'celular_transportista', 'placa', 'costo_flete',
        ]));

        return redirect("/logistica/remisiones/{$remision->id}")->with('success', 'Remisión actualizada.');
    }

    public function cambiarEstado(Request $request, Remision $remision)
    {
        $request->validate([
            'estado'               => 'required|in:confirmada,en_camino,entregada,anulada',
            'transportista'        => 'nullable|string|max:255',
            'celular_transportista'=> 'nullable|string|max:30',
            'placa'                => 'nullable|string|max:20',
            'costo_flete'          => 'nullable|numeric|min:0',
            'nombre_receptor'      => 'nullable|string|max:255',
            'fecha_salida'         => 'nullable|date',
            'fecha_entrega'        => 'nullable|date',
        ]);

        $nuevoEstado = $request->estado;

        // Una remisión entregada o anulada ya no cambia; una anulada no «revive» a en camino.
        if (! $remision->puedePasarA($nuevoEstado)) {
            return back()->with('error', "Una remisión {$remision->estadoBadge()['label']} no puede pasar a ese estado.");
        }

        $data = ['estado' => $nuevoEstado];

        if ($nuevoEstado === 'en_camino') {
            $data['fecha_salida'] = $request->fecha_salida ?? now();
            if ($request->filled('transportista'))         $data['transportista']         = $request->transportista;
            if ($request->filled('celular_transportista')) $data['celular_transportista'] = $request->celular_transportista;
            if ($request->filled('placa'))                 $data['placa']                 = $request->placa;
            if ($request->filled('costo_flete'))           $data['costo_flete']           = $request->costo_flete;
        }

        if ($nuevoEstado === 'entregada') {
            $data['fecha_entrega']   = $request->fecha_entrega ?? now();
            $data['nombre_receptor'] = $request->nombre_receptor;
        }

        DB::transaction(function () use ($remision, $data, $nuevoEstado) {
            if ($nuevoEstado === 'anulada') {
                $this->desbloquearItems($remision);
                $remision->devolverAlInventario();
            }

            $remision->update($data);

            $this->alSalir($remision);
        });

        return back()->with('success', 'Estado actualizado correctamente.');
    }

    public function guardarFirma(Request $request, Remision $remision)
    {
        $request->validate([
            'tipo'  => 'required|in:despacho,recibido',
            'firma' => 'required|string',
        ]);

        $campo = $request->tipo === 'despacho' ? 'firma_despacho' : 'firma_recibido';
        $data  = [$campo => $request->firma];
        $mensaje = 'Firma guardada.';

        // La firma de "recibido" es la prueba de entrega: al guardarla, si la
        // remisión iba en camino, pasa sola a "entregada" con su fecha —
        // antes había que firmar Y además apretar "Marcar entregada" aparte.
        if ($request->tipo === 'recibido' && $remision->estado === 'en_camino') {
            $data['estado']        = 'entregada';
            $data['fecha_entrega'] = $remision->fecha_entrega ?? now();
            $mensaje = 'Firma guardada — remisión marcada como entregada.';
        }

        $remision->update($data);
        $this->alSalir($remision);

        return back()->with('success', $mensaje);
    }

    public function destroy(Remision $remision)
    {
        // Lo que ya salió no se borra: se anula, que deja el rastro y devuelve el inventario.
        if (in_array($remision->estado, ['en_camino', 'entregada'], true)) {
            return back()->with('error', 'Esta remisión ya salió: anúlala en vez de eliminarla.');
        }

        DB::transaction(function () use ($remision) {
            // Una anulada ya liberó sus ítems al anularse. Liberarlos otra vez soltaba unidades
            // de OTRAS remisiones de la misma orden.
            if ($remision->estado !== 'anulada') {
                $this->desbloquearItems($remision);
            }
            $remision->delete();
        });

        return redirect('/logistica/remisiones')->with('success', 'Remisión eliminada.');
    }

    public function generarPdf(Remision $remision)
    {
        // Plantilla PDF personalizada, si la empresa hizo una para remisiones.
        $pdf = \App\Services\PdfPlantillaRenderer::paraRegistro(
            'remision', $remision, request()->integer('plantilla_id') ?: null
        );
        if ($pdf) {
            return $pdf->stream("remision-{$remision->numero}.pdf");
        }

        $remision->load(['op:id,numero', 'cliente', 'items.opItem', 'creadoPor:id,name']);

        $pdf = Pdf::loadView('pdf.remision', ['remision' => $remision])
            ->setPaper('letter', 'portrait');

        return $pdf->stream("remision-{$remision->numero}.pdf");
    }

    /**
     * Qué ítems de una OP se pueden remisionar.
     *
     * Dos casos, y hasta el 18 ago 2026 solo existía el primero: un ensamble tiene un trabajo
     * por unidad y se remisiona cuando el trabajo está terminado; un producto o un concepto
     * libre no genera trabajos —nadie los fabrica, salen de bodega— y se remisionan cuando el
     * ítem quedó marcado como alistado en la OP. Sin la segunda rama, **los productos no
     * aparecían nunca** en el remisionador: solo salían los ensambles.
     */
    /**
     * Los ítems que se pueden remisionar: unidades terminadas y firmadas, o productos sin
     * planta con algo por despachar. Con `$soloListos`, los productos solo si ya se alistaron:
     * es lo que muestra la pestaña «Por remisionar».
     */
    private function itemsRemisionables($q, bool $soloListos = false)
    {
        return $q
            // Un servicio se alista —alguien declara que está hecho— pero no viaja: no hay
            // nada que cargar en el camión, y ofrecerlo en la remisión solo confunde.
            ->whereNotIn('tipo', Op::TIPOS_SIN_DESPACHO)
            ->where(function ($q) use ($soloListos) {
                // Las MISMAS unidades que deja escoger el remisionador: armadas, sin remisionar y
                // firmadas por calidad. Antes aquí bastaba con estar armadas, así que salían
                // órdenes en la lista que al abrirlas no tenían nada que despachar.
                $q->whereHas('trabajos', fn ($t) => $t->disponiblesParaRemision())
                  ->orWhere(fn ($q2) => $q2->doesntHave('trabajos')
                      ->when($soloListos, fn ($q3) => $q3->where('estado_item', 'terminado'))
                      ->whereRaw('COALESCE(cantidad_remisionada, 0) < cantidad'));
            });
    }

    // ─── Búsqueda de OPs para el formulario ───────────────────────────────────
    /**
     * Las órdenes que tienen algo listo para despachar.
     *
     * **Sin escribir nada devuelve las que hay**, que es lo que se necesita al abrir la
     * pantalla: antes había que adivinar un número de OP para que apareciera algo, teniendo el
     * sistema la lista completa. Con texto, filtra por número de orden o por nombre de cliente.
     *
     * Y solo salen las que de verdad tienen ítems remisionables. Antes se listaban órdenes en
     * producción aunque no tuvieran ni una unidad terminada, y al elegirlas la pantalla quedaba
     * vacía sin explicar por qué.
     */
    public function buscarOp(Request $request)
    {
        $ops = Op::with([
                'cliente:id,nombre,apellido,tipo',
                'items' => fn ($q) => $this->itemsRemisionables($q),
            ])
            ->whereHas('items', fn ($q) => $this->itemsRemisionables($q))
            ->when($request->filled('q'), function ($q) use ($request) {
                $texto = $request->q;

                $q->where(function ($q2) use ($texto) {
                    $q2->where('numero', 'like', "%{$texto}%")
                       ->orWhereHas('cliente', fn ($c) => $c->where('nombre', 'like', "%{$texto}%")
                           ->orWhere('apellido', 'like', "%{$texto}%"));
                });
            })
            ->whereIn('estado', ['borrador', 'confirmada', 'en_produccion', 'calidad', 'reproceso', 'despachada'])
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn ($op) => [
                'id'             => $op->id,
                'numero'         => $op->numero,
                'estado'         => $op->estado,
                'cliente_nombre' => $op->cliente?->nombreCompleto() ?? '—',
                'items_count'    => $op->items->count(),
            ]);

        return response()->json($ops);
    }

    public function itemsOp(Op $op)
    {
        $items = $this->itemsRemisionables($op->items())
            ->with('trabajos')
            ->orderBy('orden')
            ->get()
            ->map(fn ($i) => [
                'id'                   => $i->id,
                'descripcion'          => $i->descripcion,
                'cantidad'             => (int) $i->cantidad,
                'unidades_disponibles' => $i->cantidadDisponible(),
                'unidades_completadas' => $i->unidadesCompletadas(),
                'unidades_remisionadas'=> $i->unidadesRemisionadas(),
                'sin_alistar'          => $i->sinTrabajos() && $i->estado_item !== 'terminado',
                'total'                => (int) $i->cantidad,
                'numero_serie'         => $i->numero_serie,
                'tipo'                 => $i->tipo,
            ]);

        return response()->json([
            'op'      => ['id' => $op->id, 'numero' => $op->numero],
            'cliente' => $op->cliente ? ['id' => $op->cliente->id, 'nombre' => $op->cliente->nombreCompleto()] : null,
            'items'   => $items,
        ]);
    }

    // ─── Helpers privados ──────────────────────────────────────────────────────

    private function desbloquearItems(Remision $remision): void
    {
        $remisionItems = $remision->items()->whereNotNull('op_item_id')->get();

        if ($remisionItems->isEmpty()) return;

        foreach ($remisionItems as $remisionItem) {
            $opItem = OpItem::with('trabajos')->find($remisionItem->op_item_id);
            if (!$opItem) continue;

            // Desmarcar los trabajos remisionados correspondientes a esta remisión
            // (los más recientes en orden descendente de numero_unidad)
            $cantidadADesmarcar = (int) $remisionItem->cantidad;
            $trabajosRemisionados = $opItem->trabajos()
                ->where('remisionado', true)
                ->orderByDesc('numero_unidad')
                ->take($cantidadADesmarcar)
                ->get();

            foreach ($trabajosRemisionados as $t) {
                $t->update(['remisionado' => false]);
            }

            // Recalcular campos del ítem. Un producto no tiene unidades: se le resta lo que
            // llevaba esta remisión. Antes se recontaba por unidades —cero— y anular una de dos
            // remisiones de un producto lo devolvía entero, como si la otra no hubiera salido.
            $totalRemisionados = $opItem->sinTrabajos()
                ? max(0, (float) $opItem->cantidad_remisionada - (float) $remisionItem->cantidad)
                : $opItem->trabajos()->where('remisionado', true)->count();

            $opItem->update([
                'cantidad_remisionada' => $totalRemisionados,
                'remisionado'          => false,
                'remision_id'          => $totalRemisionados > 0 ? $opItem->remision_id : null,
            ]);
        }

        // Si esta remisión había despachado la orden, la orden vuelve a donde estaba antes de
        // salir —calidad si todo está listo— y no a «en producción» a secas.
        if ($remision->op_id) {
            Op::find($remision->op_id)?->revertirDespacho();
        }
    }

    /** La remisión salió (en camino o entregada): sale del inventario y la orden se pone al día. */
    private function alSalir(Remision $remision): void
    {
        if (! in_array($remision->estado, ['en_camino', 'entregada'], true)) {
            return;
        }

        $remision->sacarDelInventario();

        if ($remision->op_id) {
            Op::find($remision->op_id)?->sincronizarEstado();
        }
    }

    /**
     * Las órdenes con algo listo para remisionar, con lo que tiene cada una.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function pendientesPorRemisionar()
    {
        return \App\Support\ContextoSede::aplicar(Op::query())
            ->with([
                'cliente:id,nombre,apellido,tipo',
                'items' => fn ($q) => $this->itemsRemisionables($q, true)->with('trabajos')->orderBy('orden'),
            ])
            ->whereHas('items', fn ($q) => $this->itemsRemisionables($q, true))
            ->whereIn('estado', ['borrador', 'confirmada', 'en_produccion', 'calidad', 'reproceso'])
            ->orderBy('fecha_entrega_estimada')
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->map(fn (Op $op) => [
                'id'             => $op->id,
                'numero'         => $op->numero,
                'estado'         => $op->estado,
                'estado_label'   => $op->estadoBadge()['label'],
                'cliente'        => $op->cliente?->nombreCompleto() ?? '—',
                'fecha_entrega'  => $op->fecha_entrega_estimada?->toDateString(),
                'items'          => $op->items->map(fn (OpItem $i) => [
                    'descripcion' => $i->descripcion,
                    'tipo'        => $i->tipo,
                    'disponibles' => $i->cantidadLista(),
                    'total'       => (int) $i->cantidad,
                ])->filter(fn ($i) => $i['disponibles'] > 0)->values(),
            ])
            ->filter(fn ($op) => $op['items']->isNotEmpty())
            ->values();
    }

    private function formatRemision(Remision $remision): array
    {
        $badge = $remision->estadoBadge();
        return [
            'id'                   => $remision->id,
            'numero'               => $remision->numero,
            'tipo'                 => $remision->tipo,
            'estado'               => $remision->estado,
            'estado_label'         => $badge['label'],
            'estado_bg'            => $badge['bg'],
            'estado_text'          => $badge['text'],
            'fecha_remision'       => $remision->fecha_remision?->format('Y-m-d'),
            'notas'                => $remision->notas,
            'transportista'        => $remision->transportista,
            'celular_transportista'=> $remision->celular_transportista,
            'placa'                => $remision->placa,
            'costo_flete'          => $remision->costo_flete,
            'fecha_salida'         => $remision->fecha_salida?->toISOString(),
            'fecha_entrega'        => $remision->fecha_entrega?->toISOString(),
            'firma_despacho'       => $remision->firma_despacho,
            'firma_recibido'       => $remision->firma_recibido,
            'nombre_receptor'      => $remision->nombre_receptor,
            'op'                   => $remision->op ? ['id' => $remision->op->id, 'numero' => $remision->op->numero] : null,
            'cliente'              => $remision->cliente ? ['id' => $remision->cliente->id, 'nombre' => $remision->cliente->nombreCompleto()] : null,
            'creado_por'           => $remision->creadoPor?->name,
            'created_at'           => $remision->created_at?->toISOString(),
            'items'                => $remision->items->map(fn ($item) => [
                'id'           => $item->id,
                'descripcion'  => $item->descripcion,
                'cantidad'     => $item->cantidad,
                'unidad'       => $item->unidad,
                'numero_serie' => $item->numero_serie,
                'notas'        => $item->notas,
                'op_item_id'   => $item->op_item_id,
                'producto_id'  => $item->producto_id,
            ])->values()->all(),
        ];
    }
}
