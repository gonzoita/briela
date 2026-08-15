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

        return Inertia::render('Logistica/Remisiones/Index', [
            'remisiones' => $remisiones,
            'orden' => $orden,
            'filtros'    => $request->only(['estado', 'tipo']),
        ]);
    }

    public function create(Request $request)
    {
        $op = null;
        if ($request->filled('op_id')) {
            $opRevisar = Op::find($request->op_id);
            if ($opRevisar && ! $opRevisar->calidad_aprobada_at) {
                return redirect("/produccion/ops/{$opRevisar->id}")
                    ->with('error', 'Esta OP no puede remisionarse todavía: falta la aprobación de control de calidad.');
            }

            $op = Op::with([
                'items' => function ($q) {
                    $q->whereHas('trabajos', function ($q2) {
                        $q2->where('porcentaje_avance', 100)->where('remisionado', false);
                    })->orderBy('orden');
                },
                'items.trabajos',
                'cliente',
            ])->findOrFail($request->op_id);

            $op->items->each(function ($item) {
                $item->unidades_disponibles  = $item->cantidadDisponible();
                $item->unidades_completadas  = $item->unidadesCompletadas();
                $item->unidades_remisionadas = $item->unidadesRemisionadas();
                $item->total                 = (int) $item->cantidad;
            });
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

        if ($request->filled('op_id')) {
            $opRevisar = Op::find($request->op_id);
            if ($opRevisar && ! $opRevisar->calidad_aprobada_at) {
                return back()->withErrors([
                    'op_id' => 'Esta OP no puede remisionarse todavía: falta la aprobación de control de calidad.',
                ]);
            }
        }

        // Validar unidades disponibles para ítems de OP
        foreach ($request->items as $idx => $item) {
            if (!empty($item['op_item_id'])) {
                $opItem = OpItem::with('trabajos')->find($item['op_item_id']);
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

        DB::transaction(function () use ($request) {
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

                        // Recalcular campos del ítem
                        $totalRemisionados = $opItem->trabajos()->where('remisionado', true)->count();
                        $totalTrabajos     = $opItem->trabajos()->count();
                        $estaCompleto      = $totalTrabajos > 0 && $opItem->trabajos()->disponiblesParaRemision()->count() === 0
                                             && $opItem->trabajos()->where('porcentaje_avance', 100)->count() > 0;

                        $opItem->update([
                            'cantidad_remisionada' => $totalRemisionados,
                            'remisionado'          => $estaCompleto,
                            'remision_id'          => $estaCompleto ? $remision->id : $opItem->remision_id,
                        ]);
                    }
                }
            }

            if ($hayOpItems && $request->op_id) {
                $this->revisarEstadoOp($request->op_id);
            }
        });

        return redirect('/logistica/remisiones')->with('success', 'Remisión creada correctamente.');
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

        if ($nuevoEstado === 'anulada') {
            $this->desbloquearItems($remision);
        }

        $remision->update($data);

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

        return back()->with('success', $mensaje);
    }

    public function destroy(Remision $remision)
    {
        DB::transaction(function () use ($remision) {
            $this->desbloquearItems($remision);
            $remision->delete();
        });

        return redirect('/logistica/remisiones')->with('success', 'Remisión eliminada.');
    }

    public function generarPdf(Remision $remision)
    {
        $remision->load(['op:id,numero', 'cliente', 'items.opItem', 'creadoPor:id,name']);

        $pdf = Pdf::loadView('pdf.remision', ['remision' => $remision])
            ->setPaper('letter', 'portrait');

        return $pdf->stream("remision-{$remision->numero}.pdf");
    }

    // ─── Búsqueda de OPs para el formulario ───────────────────────────────────
    public function buscarOp(Request $request)
    {
        $ops = Op::with(['cliente:id,nombre,apellido,tipo', 'items' => function ($q) {
            $q->whereHas('trabajos', function ($q2) {
                $q2->where('porcentaje_avance', 100)->where('remisionado', false);
            });
        }])
        ->where(function ($q) use ($request) {
            $q->where('numero', 'like', "%{$request->q}%")
              ->orWhereHas('cliente', fn ($c) => $c->where('nombre', 'like', "%{$request->q}%"));
        })
        ->whereIn('estado', ['en_produccion', 'calidad', 'despachada'])
        ->limit(10)
        ->get()
        ->map(fn ($op) => [
            'id'            => $op->id,
            'numero'        => $op->numero,
            'cliente_nombre'=> $op->cliente?->nombreCompleto() ?? '—',
            'items_count'   => $op->items->count(),
        ]);

        return response()->json($ops);
    }

    public function itemsOp(Op $op)
    {
        $items = $op->items()
            ->whereHas('trabajos', function ($q) {
                $q->where('porcentaje_avance', 100)->where('remisionado', false);
            })
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

            // Recalcular campos del ítem
            $totalRemisionados = $opItem->trabajos()->where('remisionado', true)->count();
            $opItem->update([
                'cantidad_remisionada' => $totalRemisionados,
                'remisionado'          => false,
                'remision_id'          => $totalRemisionados > 0 ? $opItem->remision_id : null,
            ]);
        }

        if ($remision->op_id) {
            $op = Op::find($remision->op_id);
            if ($op && $op->estado === 'despachada') {
                $op->update(['estado' => 'en_produccion']);
            }
        }
    }

    private function revisarEstadoOp(int $opId): void
    {
        $op = Op::with('items')->find($opId);
        if (!$op) return;

        $totalItems   = $op->items->count();
        $remisionados = $op->items->filter(
            fn ($i) => (float) $i->cantidad_remisionada >= (float) $i->cantidad
        )->count();

        if ($totalItems > 0 && $remisionados === $totalItems && $op->estado !== 'despachada') {
            $op->update(['estado' => 'despachada']);
            $op->consumirMaterialesInventario();
        }
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
