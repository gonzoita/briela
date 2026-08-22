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
        // Desde qué bodega se mira el inventario. El id llega del navegador, así que **jamás**
        // entra tal cual a la consulta: solo se acepta si está entre las visibles de la sede.
        // Sin ese filtro, cambiar un número en la URL dejaría ver el inventario de otra sede.
        $bodegas  = ContextoSede::bodegasParaElegir();
        $visibles = $bodegas->pluck('id')->all();
        $elegida  = (int) $request->query('bodega_id', 0);
        $mirando  = in_array($elegida, $visibles, true) ? $elegida : 0;

        // Contra qué bodegas se cuenta el stock: la elegida, o todas las visibles. Es el mismo
        // conjunto que después arma la columna, para que el filtro y el número que se ve digan
        // lo mismo.
        $bodegasContadas = $mirando ? [$mirando] : $visibles;

        $query = Producto::insumos()
            ->with(['proveedor:id,nombre', 'stocks.bodega'])
            ->when($request->filled('buscar'), fn ($q) => $q->where(function ($q) use ($request) {
                $q->where('nombre', 'like', "%{$request->buscar}%")
                  ->orWhere('referencia', 'like', "%{$request->buscar}%");
            }))
            ->when($request->filled('tipo'), fn ($q) => $q->where('tipo', $request->tipo))
            // Mirando una bodega, la lista **es** el contenido de esa bodega: lo que tiene cero
            // ahí no está ahí. El filtro va en la consulta y no sobre la página ya traída, o la
            // paginación contaría filas que después desaparecen.
            ->when($mirando, fn ($q) => $q->whereHas(
                'stocks',
                fn ($s) => $s->where('bodega_id', $mirando)->where('cantidad', '>', 0)
            ))
            // «Solo bajo stock»: lo que hay es menor o igual al mínimo del producto.
            //
            // Va en la consulta, contra la suma de las bodegas que se están mirando. Estuvo
            // escrito como un `if` **vacío** después de paginar, con un comentario que decía
            // que faltaba: el botón existía, se marcaba, y no filtraba nada. Filtrar después
            // de `paginate()` tampoco habría servido — la página ya viene con 25 filas
            // elegidas, así que se irían quedando páginas de dos y tres renglones y el
            // contador diría otra cosa.
            //
            // El mínimo en cero no cuenta: un producto sin mínimo definido no está bajo, está
            // sin configurar. Sin esta condición, todo lo que nadie configuró saldría en rojo.
            ->when($request->bajo_stock === 'true', fn ($q) => $q
                ->where('stock_minimo', '>', 0)
                ->whereRaw(
                    '(select coalesce(sum(ps.cantidad), 0) from producto_stock ps
                        where ps.producto_id = productos.id and ps.bodega_id in ('
                        . implode(',', array_map('intval', $bodegasContadas ?: [0])) . ')) <= productos.stock_minimo'
                ));

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

        // El inventario de otra sede no es el de esta, y si hay una bodega elegida, la columna
        // es la de ella. Misma lista con la que se filtró arriba.
        $bodegasVisibles = $bodegasContadas;

        $query->through(function ($p) use ($bodegasVisibles) {
            $stocks = $p->stocks->whereIn('bodega_id', $bodegasVisibles);
            $stockTotal = (float) $stocks->sum('cantidad');

            return array_merge($p->toArray(), [
                'stock_total' => $stockTotal,
                // Sin mínimo definido no está bajo: está sin configurar. Con `<=` a secas,
                // todo producto con mínimo en cero salía resaltado en rojo —incluido uno con
                // 250 unidades en bodega—, y un tablero que siempre grita deja de leerse.
                'bajo_stock'  => (float) $p->stock_minimo > 0 && $stockTotal <= (float) $p->stock_minimo,
                'stocks'      => $stocks->map(fn ($s) => [
                    'bodega_id'     => $s->bodega_id,
                    'bodega_nombre' => $s->bodega?->nombre ?? '—',
                    'cantidad'      => (float) $s->cantidad,
                ])->values(),
            ]);
        });

        return Inertia::render('Compras/Inventario/Index', [
            'items'       => $query,
            'orden'       => $orden,
            'filters'     => $request->only(['buscar', 'tipo', 'bajo_stock']),
            'proveedores' => Proveedor::where('activo', true)->select('id', 'nombre')->orderBy('nombre')->get(),
            'bodegas'     => $bodegas->values(),
            // Cuál se está mirando. Cero significa «todas», que es como abre la pantalla.
            'bodega_id'   => $mirando,
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
