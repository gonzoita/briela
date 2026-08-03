<?php

namespace App\Http\Controllers;

use App\Models\Op;
use App\Models\OrdenCompra;
use App\Models\OrdenCompraItem;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\SolicitudCompra;
use App\Models\SolicitudCompraItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SolicitudCompraController extends Controller
{
    public function index(Request $request): Response
    {
        $solicitudes = \App\Support\ContextoSede::aplicar(SolicitudCompra::query())
            ->with(['solicitadoPor:id,name', 'aprobadoPor:id,name', 'op:id,numero', 'sede:id,nombre'])
            ->when($request->filled('estado'), fn ($q) => $q->where('estado', $request->estado))
            ->when($request->filled('buscar'), fn ($q) => $q->where('numero', 'like', "%{$request->buscar}%"))
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('created_at', '>=', $request->desde))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('created_at', '<=', $request->hasta))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Compras/Solicitudes/Index', [
            'solicitudes' => $solicitudes,
            'filters'     => $request->only(['estado', 'buscar', 'desde', 'hasta']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Compras/Solicitudes/Create', [
            'ops'   => Op::select('id', 'numero')->latest()->limit(50)->get(),
            // Insumos del inventario real (productos), no la tabla paralela
            // inventario_items. Se normaliza a la misma forma que espera el
            // Vue (codigo/nombre/unidad) para no tocar el frontend.
            'items' => Producto::insumos()->where('activo', true)
                ->orderBy('nombre')
                ->get(['id', 'referencia', 'nombre', 'unidad_medida'])
                ->map(fn ($p) => [
                    'id'     => $p->id,
                    'codigo' => $p->referencia,
                    'nombre' => $p->nombre,
                    'unidad' => $p->unidad_medida,
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'motivo'         => 'nullable|string',
            'fecha_requerida'=> 'nullable|date',
            'notas'          => 'nullable|string',
            'op_id'          => 'nullable|exists:ops,id',
            'items'          => 'required|array|min:1',
            'items.*.descripcion'     => 'required|string',
            'items.*.cantidad'        => 'required|numeric|min:0.001',
            'items.*.unidad'          => 'required|string',
            'items.*.item_id'         => 'nullable|exists:productos,id',
            'items.*.precio_estimado' => 'nullable|numeric|min:0',
            'items.*.notas'           => 'nullable|string',
        ]);

        $solicitud = SolicitudCompra::create([
            'estado'          => 'borrador',
            'solicitado_por'  => auth()->id(),
            'motivo'          => $data['motivo'] ?? null,
            'fecha_requerida' => $data['fecha_requerida'] ?? null,
            'notas'           => $data['notas'] ?? null,
            'op_id'           => $data['op_id'] ?? null,
        ]);

        foreach ($data['items'] as $itemData) {
            SolicitudCompraItem::create([
                'solicitud_id'   => $solicitud->id,
                'item_id'        => $itemData['item_id'] ?? null,
                'descripcion'    => $itemData['descripcion'],
                'cantidad'       => $itemData['cantidad'],
                'unidad'         => $itemData['unidad'],
                'precio_estimado'=> $itemData['precio_estimado'] ?? null,
                'notas'          => $itemData['notas'] ?? null,
            ]);
        }

        // Aviso a quien aprueba compras (admin/jefe de producción).
        app(\App\Services\NotificacionService::class)->paraRol(
            ['administrador', 'jefe_produccion'],
            'solicitud_compra',
            "Solicitud de compra {$solicitud->numero}",
            'Hay una nueva solicitud de compra por revisar.',
            '/compras/solicitudes',
            excluirUserId: auth()->id(),
        );

        return redirect('/compras/solicitudes')->with('success', "Solicitud {$solicitud->numero} creada correctamente.");
    }

    public function update(Request $request, SolicitudCompra $solicitud): RedirectResponse
    {
        if (!in_array($solicitud->estado, ['borrador'])) {
            return back()->with('error', 'Solo se pueden editar solicitudes en borrador.');
        }

        $data = $request->validate([
            'motivo'          => 'nullable|string',
            'fecha_requerida' => 'nullable|date',
            'notas'           => 'nullable|string',
            'op_id'           => 'nullable|exists:ops,id',
            'estado'          => 'nullable|in:borrador,pendiente',
            'items'           => 'nullable|array',
            'items.*.id'               => 'nullable|exists:solicitudes_compra_items,id',
            'items.*.descripcion'      => 'required|string',
            'items.*.cantidad'         => 'required|numeric|min:0.001',
            'items.*.unidad'           => 'required|string',
            'items.*.item_id'          => 'nullable|exists:productos,id',
            'items.*.precio_estimado'  => 'nullable|numeric|min:0',
            'items.*.notas'            => 'nullable|string',
        ]);

        $solicitud->update([
            'motivo'          => $data['motivo'] ?? $solicitud->motivo,
            'fecha_requerida' => $data['fecha_requerida'] ?? $solicitud->fecha_requerida,
            'notas'           => $data['notas'] ?? $solicitud->notas,
            'op_id'           => $data['op_id'] ?? $solicitud->op_id,
            'estado'          => $data['estado'] ?? $solicitud->estado,
        ]);

        if (!empty($data['items'])) {
            $solicitud->items()->delete();
            foreach ($data['items'] as $itemData) {
                SolicitudCompraItem::create([
                    'solicitud_id'    => $solicitud->id,
                    'item_id'         => $itemData['item_id'] ?? null,
                    'descripcion'     => $itemData['descripcion'],
                    'cantidad'        => $itemData['cantidad'],
                    'unidad'          => $itemData['unidad'],
                    'precio_estimado' => $itemData['precio_estimado'] ?? null,
                    'notas'           => $itemData['notas'] ?? null,
                ]);
            }
        }

        return back()->with('success', 'Solicitud actualizada correctamente.');
    }

    public function aprobar(SolicitudCompra $solicitud): RedirectResponse
    {
        $solicitud->update([
            'estado'           => 'aprobada',
            'aprobado_por'     => auth()->id(),
            'fecha_aprobacion' => now(),
        ]);

        return back()->with('success', "Solicitud {$solicitud->numero} aprobada.");
    }

    public function rechazar(Request $request, SolicitudCompra $solicitud): RedirectResponse
    {
        $request->validate(['notas' => 'nullable|string']);

        $solicitud->update([
            'estado' => 'rechazada',
            'notas'  => $request->notas ?? $solicitud->notas,
        ]);

        return back()->with('success', "Solicitud {$solicitud->numero} rechazada.");
    }

    public function convertirAOrden(Request $request, SolicitudCompra $solicitud): RedirectResponse
    {
        if ($solicitud->estado !== 'aprobada') {
            return back()->with('error', 'Solo se pueden convertir solicitudes aprobadas.');
        }

        $data = $request->validate([
            'proveedor_id' => 'required|exists:proveedores,id',
        ]);

        $orden = OrdenCompra::create([
            'estado'       => 'borrador',
            'proveedor_id' => $data['proveedor_id'],
            'solicitud_id' => $solicitud->id,
            'creado_por'   => auth()->id(),
        ]);

        foreach ($solicitud->items as $scItem) {
            OrdenCompraItem::create([
                'orden_id'       => $orden->id,
                'item_id'        => $scItem->item_id,
                'descripcion'    => $scItem->descripcion,
                'cantidad'       => $scItem->cantidad,
                'unidad'         => $scItem->unidad,
                'precio_unitario'=> $scItem->precio_estimado ?? 0,
                'impuesto_pct'   => 0,
                'total_linea'    => ($scItem->precio_estimado ?? 0) * $scItem->cantidad,
            ]);
        }

        $solicitud->update(['estado' => 'en_proceso']);

        return redirect("/compras/ordenes/{$orden->id}")->with('success', "Orden {$orden->numero} creada desde {$solicitud->numero}.");
    }
}
