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
                $item->sin_alistar           = $item->sinTrabajos() && $item->cantidadLista() === 0;
                // Lo alistado y sin salir: la remisión propone eso, no todo lo vendido.
                $item->unidades_listas       = $item->cantidadLista();
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

        $servicio = app(\App\Services\RemisionService::class);

        if ($errores = $servicio->errores($request->op_id, $request->items)) {
            return back()->withErrors($errores)->withInput();
        }

        // Crear la remisión saca del almacén lo que lleva: ver RemisionService e InventarioRemisionService.
        $remision = $servicio->crear($request->only(['tipo', 'op_id', 'cliente_id', 'fecha_remision', 'notas', 'items']));

        // A la remisión recién creada: lo siguiente es confirmarla, firmarla y despacharla, y
        // devolver a la lista obligaba a buscarla para seguir.
        return redirect("/logistica/remisiones/{$remision->id}")->with('success', 'Remisión creada: lo que lleva ya salió del almacén. Confírmala y márcala en camino cuando salga.');
    }

    public function show(Remision $remision)
    {
        $remision->load([
            'op:id,numero,estado,total',
            'cliente:id,nombre,apellido,tipo',
            'items.opItem',
            'creadoPor:id,name',
            'firmadoDespachoPor:id,name',
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

        $motivo = app(\App\Services\RemisionService::class)->cambiarEstado($remision, $request->estado, $request->only([
            'transportista', 'celular_transportista', 'placa', 'costo_flete', 'nombre_receptor', 'fecha_salida', 'fecha_entrega',
        ]));

        if ($motivo) {
            return back()->with('error', $motivo);
        }

        return back()->with('success', 'Estado actualizado correctamente.');
    }

    public function guardarFirma(Request $request, Remision $remision)
    {
        $request->validate([
            'tipo'            => 'required|in:despacho,recibido',
            // Solo una imagen dibujada o subida, nunca otra cosa: va tal cual al PDF.
            'firma'           => ['required', 'string', 'max:700000', 'regex:/^data:image\/(png|jpeg);base64,[A-Za-z0-9+\/=]+$/'],
            'nombre_receptor' => 'nullable|string|max:150',
        ], [
            'firma.required' => 'Firma en el recuadro o sube la imagen de la firma.',
            'firma.regex'    => 'La firma tiene que ser una imagen PNG o JPG.',
            'firma.max'      => 'La imagen de la firma es muy pesada.',
        ]);

        $despacho = $request->tipo === 'despacho';

        // Despacho, desde que se confirma; recibido, desde que salió. Una anulada no se firma.
        $permitidos = $despacho ? ['confirmada', 'en_camino', 'entregada'] : ['en_camino', 'entregada'];
        if (! in_array($remision->estado, $permitidos, true)) {
            return back()->with('error', $despacho
                ? 'La firma de despacho se pone cuando la remisión está confirmada.'
                : 'La firma de recibido se pone cuando la remisión ya salió.');
        }

        if (! $despacho && ! trim((string) ($request->nombre_receptor ?: $remision->nombre_receptor))) {
            return back()->withErrors(['nombre_receptor' => 'Escribe el nombre de quien recibe.']);
        }

        $data = $despacho
            ? ['firma_despacho' => $request->firma, 'firma_despacho_por' => auth()->id(), 'firma_despacho_at' => now()]
            : ['firma_recibido' => $request->firma, 'firma_recibido_at' => now(),
               'nombre_receptor' => trim((string) ($request->nombre_receptor ?: $remision->nombre_receptor))];
        $mensaje = 'Firma guardada.';

        // La firma de "recibido" es la prueba de entrega: al guardarla, si la
        // remisión iba en camino, pasa sola a "entregada" con su fecha —
        // antes había que firmar Y además apretar "Marcar entregada" aparte.
        if (! $despacho && $remision->estado === 'en_camino') {
            $data['estado']        = 'entregada';
            $data['fecha_entrega'] = $remision->fecha_entrega ?? now();
            $mensaje = 'Firma guardada — remisión marcada como entregada.';
        }

        $servicio = app(\App\Services\RemisionService::class);
        $remision->update($data);
        $servicio->alSalir($remision);
        $servicio->cerrarSiCorresponde($remision);

        return back()->with('success', $mensaje);
    }

    public function destroy(Remision $remision)
    {
        // Lo que ya salió no se borra: se anula, que deja el rastro y devuelve el inventario.
        if (in_array($remision->estado, Remision::SALIDAS, true)) {
            return back()->with('error', 'Esta remisión ya salió: anúlala en vez de eliminarla.');
        }

        DB::transaction(function () use ($remision) {
            // Una anulada ya liberó sus ítems al anularse. Liberarlos otra vez soltaba unidades
            // de OTRAS remisiones de la misma orden.
            // Borrarla suelta lo suyo y devuelve al almacén lo que había sacado.
            if ($remision->estado !== 'anulada') {
                app(\App\Services\RemisionService::class)->liberar($remision);
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

        $remision->load(['op:id,numero', 'cliente', 'items.opItem', 'creadoPor:id,name,firma', 'firmadoDespachoPor:id,name,firma']);

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
                'sin_alistar'          => $i->sinTrabajos() && $i->cantidadLista() === 0,
                'unidades_listas'      => $i->cantidadLista(),
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
            'firma_despacho_por'   => $remision->firmadoDespachoPor?->name,
            'firma_despacho_at'    => $remision->firma_despacho_at?->toISOString(),
            'firma_recibido_at'    => $remision->firma_recibido_at?->toISOString(),
            'cerrada_at'           => $remision->cerrada_at?->toISOString(),
            // La firma guardada en el perfil de quien mira, para «Usar mi firma».
            'mi_firma'             => auth()->user()?->firma,
            // Lo que falta cobrar de la orden: es lo que deja la remisión entregada sin cerrar.
            'cobro'                => $remision->op ? [
                'pagada'        => $remision->op->pagadaYVerificada(),
                'por_verificar' => max(0, (float) $remision->op->total - $remision->op->totalVerificado()),
            ] : null,
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
                'cantidad_pendiente' => $item->cantidad_pendiente !== null ? (float) $item->cantidad_pendiente : null,
                'op_item_id'   => $item->op_item_id,
                'producto_id'  => $item->producto_id,
            ])->values()->all(),
        ];
    }
}
