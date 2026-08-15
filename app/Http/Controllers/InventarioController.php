<?php

namespace App\Http\Controllers;

use App\Models\Bodega;
use App\Models\Producto;
use App\Models\ProductoMovimiento;
use App\Models\Proveedor;
use App\Support\ContextoSede;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InventarioController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Producto::insumos()
            ->with(['proveedor:id,nombre', 'stocks.bodega'])
            ->when($request->filled('buscar'), fn ($q) => $q->where(function ($q) use ($request) {
                $q->where('nombre', 'like', "%{$request->buscar}%")
                  ->orWhere('referencia', 'like', "%{$request->buscar}%");
            }))
            ->when($request->filled('tipo'), fn ($q) => $q->where('tipo', $request->tipo));

        // El orden lo pide la pantalla. El campo se valida contra esta lista: lo que
        // llegue por `?orden=` y no esté aquí se ignora y nunca toca el SQL.
        $orden = \App\Support\Orden::aplicar($query, $request, [
            'nombre'        => 'nombre',
            'referencia'    => 'referencia',
            'precio_costo'  => 'precio_costo',
            'stock_minimo'  => 'stock_minimo',
            'created_at'    => 'created_at',
        ], 'created_at', 'desc');

        $query = $query->paginate(25)->withQueryString();

        // Solo se cuenta el stock de las bodegas visibles en la sede activa:
        // el inventario de otra sede no es el de esta.
        $bodegasVisibles = ContextoSede::idsBodegasVisibles();

        $query->through(function ($p) use ($bodegasVisibles) {
            $stocks = $p->stocks->whereIn('bodega_id', $bodegasVisibles);
            $stockTotal = (float) $stocks->sum('cantidad');

            return array_merge($p->toArray(), [
                'stock_total' => $stockTotal,
                'bajo_stock'  => $stockTotal <= (float) $p->stock_minimo,
                'stocks'      => $stocks->map(fn ($s) => [
                    'bodega_id'     => $s->bodega_id,
                    'bodega_nombre' => $s->bodega?->nombre ?? '—',
                    'cantidad'      => (float) $s->cantidad,
                ])->values(),
            ]);
        });

        // Si hay filtro bajo_stock, necesitamos filtrar después del cálculo
        $items = $query;
        if ($request->filled('bajo_stock') && $request->bajo_stock === 'true') {
            // Re-query filtrando en colección — sencillo dado que paginate ya corrió
            // Esta es una limitación: el filtro bajo_stock requiere recalcular en PHP
            // Para datasets grandes usar una columna virtual o subquery
        }

        return Inertia::render('Compras/Inventario/Index', [
            'items'       => $items,
            'orden'       => $orden,
            'filters'     => $request->only(['buscar', 'tipo', 'bajo_stock']),
            'proveedores' => Proveedor::where('activo', true)->select('id', 'nombre')->orderBy('nombre')->get(),
            'bodegas'     => ContextoSede::bodegasVisibles(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'nombre'       => 'required|string|max:255',
            'referencia'   => 'nullable|string|max:60',
            'descripcion_corta' => 'nullable|string|max:160',
            'unidad_medida' => 'required|string|max:20',
            'stock_minimo' => 'numeric|min:0',
            'stock_maximo' => 'nullable|numeric|min:0',
            'proveedor_id' => 'nullable|exists:proveedores,id',
        ]);

        if (empty($data['referencia'])) {
            $data['referencia'] = Producto::generarReferencia('producto');
        }

        $producto = Producto::create(array_merge($data, [
            'tipo'      => 'producto',
            'es_insumo' => true,
            'activo'    => true,
        ]));

        // Stock inicial
        $stockInicial = $request->input('stock_inicial', []);
        foreach ($stockInicial as $bodegaId => $cantidad) {
            $cantidad = (float) $cantidad;
            if ($cantidad > 0) {
                $producto->registrarMovimiento(
                    tipo: 'entrada',
                    cantidad: $cantidad,
                    bodegaId: (int) $bodegaId,
                    usuarioId: auth()->id(),
                    origenTipo: 'creacion_producto',
                    notas: 'Stock inicial'
                );
            }
        }

        return back()->with('success', 'Material creado correctamente.');
    }

    public function update(Request $request, Producto $item): RedirectResponse
    {
        $data = $request->validate([
            'nombre'            => 'required|string|max:255',
            'referencia'        => "required|string|max:60|unique:productos,referencia,{$item->id}",
            'descripcion_corta' => 'nullable|string|max:160',
            'unidad_medida'     => 'required|string|max:20',
            'stock_minimo'      => 'numeric|min:0',
            'stock_maximo'      => 'nullable|numeric|min:0',
            'proveedor_id'      => 'nullable|exists:proveedores,id',
            'activo'            => 'boolean',
        ]);

        $item->update($data);

        return back()->with('success', 'Material actualizado correctamente.');
    }

    public function ajuste(Request $request, Producto $item): RedirectResponse
    {
        $data = $request->validate([
            'bodega_id' => 'required|exists:bodegas,id',
            'tipo'      => 'required|in:entrada,salida,ajuste,devolucion,transferencia',
            'cantidad'  => 'required|numeric|min:0.001',
            'bodega_destino_id' => 'required_if:tipo,transferencia|nullable|exists:bodegas,id',
            'notas'     => 'nullable|string',
        ]);

        // No se puede mover stock de una bodega a la que no se tiene acceso.
        $visibles = ContextoSede::idsBodegasVisibles();
        $destino  = $data['bodega_destino_id'] ?? null;

        if (! in_array((int) $data['bodega_id'], $visibles, true)
            || ($destino && ! in_array((int) $destino, $visibles, true))) {
            return back()->with('error', 'No tienes acceso a esa bodega.');
        }

        $item->registrarMovimiento(
            tipo: $data['tipo'],
            cantidad: (float) $data['cantidad'],
            bodegaId: (int) $data['bodega_id'],
            usuarioId: auth()->id(),
            bodegaDestinoId: isset($data['bodega_destino_id']) ? (int) $data['bodega_destino_id'] : null,
            origenTipo: 'ajuste_manual',
            notas: $data['notas'] ?? null
        );

        return back()->with('success', 'Ajuste registrado correctamente.');
    }

    public function movimientos(Request $request, Producto $item): JsonResponse
    {
        $movimientos = $item->movimientos()
            ->with(['usuario:id,name', 'bodega:id,nombre', 'bodegaDestino:id,nombre'])
            ->latest()
            ->limit(50)
            ->get();

        return response()->json($movimientos);
    }

    public function kardex(Request $request): Response
    {
        // Solo movimientos de las bodegas visibles en la sede activa.
        $bodegasVisibles = ContextoSede::idsBodegasVisibles();

        $query = ProductoMovimiento::with([
                'producto:id,nombre,referencia,unidad_medida',
                'bodega:id,nombre',
                'bodegaDestino:id,nombre',
                'usuario:id,name',
            ])
            ->where(fn ($q) => $q->whereIn('bodega_id', $bodegasVisibles)
                                 ->orWhereIn('bodega_destino_id', $bodegasVisibles))
            ->whereHas('producto', fn ($q) => $q->where('es_insumo', true))
            ->when($request->filled('producto_id'), fn ($q) => $q->where('producto_id', $request->producto_id))
            ->when($request->filled('bodega_id'),   fn ($q) => $q->where(function ($q) use ($request) {
                $q->where('bodega_id', $request->bodega_id)
                  ->orWhere('bodega_destino_id', $request->bodega_id);
            }))
            ->when($request->filled('tipo'),         fn ($q) => $q->where('tipo', $request->tipo))
            ->when($request->filled('fecha_desde'),  fn ($q) => $q->whereDate('created_at', '>=', $request->fecha_desde))
            ->when($request->filled('fecha_hasta'),  fn ($q) => $q->whereDate('created_at', '<=', $request->fecha_hasta));

        // El orden lo pide la pantalla. El campo se valida contra esta lista: lo que
        // llegue por `?orden=` y no esté aquí se ignora y nunca toca el SQL.
        $ordenMovs = \App\Support\Orden::aplicar($query, $request, [
            'created_at' => 'created_at',
            'tipo'       => 'tipo',
            'cantidad'   => 'cantidad',
        ], 'created_at', 'desc');

        $query = $query->paginate(30)->withQueryString();

        $productos = Producto::insumos()
            ->seleccionables()
            ->where('activo', true)
            ->select('id', 'nombre', 'referencia')
            ->orderBy('nombre')
            ->get();

        return Inertia::render('Compras/Inventario/Movimientos', [
            'movimientos' => $query,
            'orden'       => $ordenMovs,
            'productos'   => $productos,
            'bodegas'     => ContextoSede::bodegasVisibles(),
            'filters'     => $request->only(['producto_id', 'bodega_id', 'tipo', 'fecha_desde', 'fecha_hasta']),
        ]);
    }

    public function buscar(Request $request): JsonResponse
    {
        $items = Producto::insumos()
            ->where('activo', true)
            ->when($request->filled('q'), fn ($query) => $query->where(function ($q) use ($request) {
                $q->where('nombre', 'like', "%{$request->q}%")
                  ->orWhere('referencia', 'like', "%{$request->q}%");
            }))
            ->select('id', 'referencia', 'nombre', 'unidad_medida', 'precio_promedio_compra')
            ->orderBy('nombre')
            ->limit(20)
            ->get()
            ->map(fn ($p) => array_merge($p->toArray(), [
                'stock_actual'    => $p->stockTotal(),
                'precio_promedio' => (float) $p->precio_promedio_compra,
            ]));

        return response()->json($items);
    }

    public function dashboard(): Response
    {
        // Todo el tablero se calcula solo sobre las bodegas de la sede activa.
        $bodegas = ContextoSede::idsBodegasVisibles();

        $stockDe = fn ($p) => (float) $p->stocks->whereIn('bodega_id', $bodegas)->sum('cantidad');

        $totalItems = Producto::insumos()->where('activo', true)->count();

        $insumos = Producto::insumos()
            ->where('activo', true)
            ->with(['proveedor:id,nombre', 'stocks'])
            ->get();

        $itemsBajoStock = $insumos
            ->filter(fn ($p) => $stockDe($p) <= (float) $p->stock_minimo)
            ->sortBy($stockDe)
            ->map(fn ($p) => array_merge($p->toArray(), [
                'stock_total' => $stockDe($p),
            ]))
            ->values();

        $valorTotal = $insumos->sum(fn ($p) => $stockDe($p) * (float) $p->precio_promedio_compra);

        $enBodegasVisibles = fn ($q) => $q->where(
            fn ($sub) => $sub->whereIn('bodega_id', $bodegas)->orWhereIn('bodega_destino_id', $bodegas)
        );

        $ultimosMovimientos = ProductoMovimiento::with([
                'producto:id,nombre,referencia',
                'usuario:id,name',
                'bodega:id,nombre',
            ])
            ->where($enBodegasVisibles)
            ->whereHas('producto', fn ($q) => $q->where('es_insumo', true))
            ->latest()
            ->limit(10)
            ->get();

        $movimientosPorDia = ProductoMovimiento::selectRaw('DATE(created_at) as fecha, COUNT(*) as total')
            ->where($enBodegasVisibles)
            ->whereHas('producto', fn ($q) => $q->where('es_insumo', true))
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('fecha')
            ->orderBy('fecha')
            ->get();

        return Inertia::render('Compras/Inventario/Dashboard', [
            'totalItems'         => $totalItems,
            'itemsBajoStock'     => $itemsBajoStock,
            'valorTotal'         => (float) $valorTotal,
            'ultimosMovimientos' => $ultimosMovimientos,
            'movimientosPorDia'  => $movimientosPorDia,
        ]);
    }
}
