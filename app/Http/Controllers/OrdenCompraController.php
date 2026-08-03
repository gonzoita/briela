<?php

namespace App\Http\Controllers;

use App\Models\OrdenCompra;
use App\Models\OrdenCompraItem;
use App\Models\Producto;
use App\Models\Proveedor;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class OrdenCompraController extends Controller
{
    public function index(Request $request): Response
    {
        $ordenes = \App\Support\ContextoSede::aplicar(OrdenCompra::query())
            ->with(['proveedor:id,nombre', 'creadoPor:id,name', 'sede:id,nombre'])
            ->when($request->filled('estado'), fn ($q) => $q->where('estado', $request->estado))
            ->when($request->filled('proveedor_id'), fn ($q) => $q->where('proveedor_id', $request->proveedor_id))
            ->when($request->filled('buscar'), fn ($q) => $q->where('numero', 'like', "%{$request->buscar}%"))
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('created_at', '>=', $request->desde))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('created_at', '<=', $request->hasta))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Compras/Ordenes/Index', [
            'ordenes'     => $ordenes,
            'filters'     => $request->only(['estado', 'proveedor_id', 'buscar', 'desde', 'hasta']),
            'proveedores' => Proveedor::where('activo', true)->select('id', 'nombre')->orderBy('nombre')->get(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Compras/Ordenes/Create', [
            'proveedores' => Proveedor::where('activo', true)->select('id', 'nombre')->orderBy('nombre')->get(),
            // Insumos del inventario real (productos). Se normaliza a la
            // forma que espera el Vue (codigo/nombre/unidad/precio_promedio).
            'items'       => Producto::insumos()->where('activo', true)
                ->orderBy('nombre')
                ->get(['id', 'referencia', 'nombre', 'unidad_medida', 'precio_promedio_compra'])
                ->map(fn ($p) => [
                    'id'              => $p->id,
                    'codigo'          => $p->referencia,
                    'nombre'          => $p->nombre,
                    'unidad'          => $p->unidad_medida,
                    'precio_promedio' => (float) $p->precio_promedio_compra,
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'proveedor_id'           => 'required|exists:proveedores,id',
            'solicitud_id'           => 'nullable|exists:solicitudes_compra,id',
            'fecha_entrega_esperada' => 'nullable|date',
            'condiciones'            => 'nullable|string',
            'notas'                  => 'nullable|string',
            'items'                  => 'required|array|min:1',
            'items.*.item_id'        => 'nullable|exists:productos,id',
            'items.*.descripcion'    => 'required|string',
            'items.*.cantidad'       => 'required|numeric|min:0.001',
            'items.*.unidad'         => 'required|string',
            'items.*.precio_unitario'=> 'required|numeric|min:0',
            'items.*.impuesto_pct'   => 'numeric|min:0|max:100',
        ]);

        $orden = OrdenCompra::create([
            'estado'                 => 'borrador',
            'proveedor_id'           => $data['proveedor_id'],
            'solicitud_id'           => $data['solicitud_id'] ?? null,
            'creado_por'             => auth()->id(),
            'fecha_entrega_esperada' => $data['fecha_entrega_esperada'] ?? null,
            'condiciones'            => $data['condiciones'] ?? null,
            'notas'                  => $data['notas'] ?? null,
        ]);

        foreach ($data['items'] as $itemData) {
            $totalLinea = $itemData['cantidad'] * $itemData['precio_unitario'];
            OrdenCompraItem::create([
                'orden_id'        => $orden->id,
                'item_id'         => $itemData['item_id'] ?? null,
                'descripcion'     => $itemData['descripcion'],
                'cantidad'        => $itemData['cantidad'],
                'unidad'          => $itemData['unidad'],
                'precio_unitario' => $itemData['precio_unitario'],
                'impuesto_pct'    => $itemData['impuesto_pct'] ?? 0,
                'total_linea'     => $totalLinea,
            ]);
        }

        $orden->recalcularTotales();

        return redirect("/compras/ordenes/{$orden->id}")->with('success', "Orden {$orden->numero} creada.");
    }

    public function show(OrdenCompra $orden): Response
    {
        $orden->load([
            'proveedor',
            'creadoPor:id,name',
            'solicitud:id,numero',
            // 'item' ahora apunta a productos: el código es 'referencia'.
            'items.item:id,nombre,referencia',
        ]);

        return Inertia::render('Compras/Ordenes/Show', [
            'orden' => $orden,
        ]);
    }

    public function update(Request $request, OrdenCompra $orden): RedirectResponse
    {
        if (!in_array($orden->estado, ['borrador'])) {
            return back()->with('error', 'Solo se pueden editar órdenes en borrador.');
        }

        $data = $request->validate([
            'proveedor_id'           => 'required|exists:proveedores,id',
            'fecha_entrega_esperada' => 'nullable|date',
            'condiciones'            => 'nullable|string',
            'notas'                  => 'nullable|string',
            'items'                  => 'nullable|array',
            'items.*.item_id'        => 'nullable|exists:productos,id',
            'items.*.descripcion'    => 'required|string',
            'items.*.cantidad'       => 'required|numeric|min:0.001',
            'items.*.unidad'         => 'required|string',
            'items.*.precio_unitario'=> 'required|numeric|min:0',
            'items.*.impuesto_pct'   => 'numeric|min:0|max:100',
        ]);

        $orden->update([
            'proveedor_id'           => $data['proveedor_id'],
            'fecha_entrega_esperada' => $data['fecha_entrega_esperada'] ?? null,
            'condiciones'            => $data['condiciones'] ?? null,
            'notas'                  => $data['notas'] ?? null,
        ]);

        if (!empty($data['items'])) {
            $orden->items()->delete();
            foreach ($data['items'] as $itemData) {
                $totalLinea = $itemData['cantidad'] * $itemData['precio_unitario'];
                OrdenCompraItem::create([
                    'orden_id'        => $orden->id,
                    'item_id'         => $itemData['item_id'] ?? null,
                    'descripcion'     => $itemData['descripcion'],
                    'cantidad'        => $itemData['cantidad'],
                    'unidad'          => $itemData['unidad'],
                    'precio_unitario' => $itemData['precio_unitario'],
                    'impuesto_pct'    => $itemData['impuesto_pct'] ?? 0,
                    'total_linea'     => $totalLinea,
                ]);
            }
            $orden->recalcularTotales();
        }

        return back()->with('success', 'Orden actualizada correctamente.');
    }

    public function enviar(OrdenCompra $orden): RedirectResponse
    {
        if ($orden->estado !== 'borrador') {
            return back()->with('error', 'La orden no está en borrador.');
        }

        $orden->update(['estado' => 'enviada']);

        return back()->with('success', "Orden {$orden->numero} enviada al proveedor.");
    }

    public function recibir(Request $request, OrdenCompra $orden): RedirectResponse
    {
        if (!in_array($orden->estado, ['enviada', 'confirmada', 'recibida_parcial'])) {
            return back()->with('error', 'La orden no está en un estado válido para recibir.');
        }

        $data = $request->validate([
            'items'                    => 'required|array|min:1',
            'items.*.id'               => 'required|exists:ordenes_compra_items,id',
            'items.*.cantidad_recibida'=> 'required|numeric|min:0',
        ]);

        $orden->recibir($data['items'], auth()->id());

        // Aviso a producción: llegó mercancía (puede resolver un faltante).
        app(\App\Services\NotificacionService::class)->paraRol(
            ['administrador', 'jefe_produccion'],
            'mercancia_recibida',
            "Mercancía recibida — OC {$orden->numero}",
            'Se registró la recepción de una orden de compra. El stock se actualizó.',
            '/inventario',
            excluirUserId: auth()->id(),
        );

        return back()->with('success', 'Recepción registrada correctamente.');
    }

    public function pdf(OrdenCompra $orden): HttpResponse
    {
        $orden->load(['proveedor', 'creadoPor:id,name', 'items.item:id,nombre,referencia']);

        $pdf = Pdf::loadView('pdf.orden-compra', compact('orden'))
            ->setPaper('letter', 'portrait');

        return $pdf->download("OC-{$orden->numero}.pdf");
    }
}
