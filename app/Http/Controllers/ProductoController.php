<?php

namespace App\Http\Controllers;

use App\Models\Bodega;
use App\Models\CategoriaProducto;
use App\Models\ImagenProducto;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Services\ArchivoServidorService;
use App\Services\PreciosPorCanalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProductoController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Producto::with(['categoria', 'imagenes', 'stocks.bodega', 'variantes.stocks'])
            ->whereNull('producto_padre_id')
            ->when($request->filled('tipo'), fn ($q) => $q->where('tipo', $request->tipo))
            ->when($request->filled('categoria'), fn ($q) => $q->where('categoria_id', $request->categoria))
            ->when($request->filled('es_vendible'), fn ($q) => $q->where('es_vendible', true))
            ->when($request->filled('es_insumo'), fn ($q) => $q->where('es_insumo', true))
            ->when($request->filled('buscar'), fn ($q) => $q->where(function ($q) use ($request) {
                $q->where('nombre', 'like', "%{$request->buscar}%")
                  ->orWhere('referencia', 'like', "%{$request->buscar}%");
            }))
            ->latest();

        $productos = $query->paginate(12)->withQueryString();

        $productos->through(function ($p) {
            $principal = $p->imagenes->firstWhere('es_principal', true) ?? $p->imagenes->first();
            $variantes = $p->variantes->map(fn ($v) => [
                'id'                   => $v->id,
                'nombre'               => $v->nombre,
                'nombre_completo'      => $v->nombre_completo,
                'valor_variante'       => $v->valor_variante,
                'referencia'           => $v->referencia,
                'stock_total'          => (float) $v->stocks->sum('cantidad'),
                'precio_costo'         => (float) $v->precio_costo,
                'precio_mayorista'     => (float) $v->precio_mayorista,
                'precio_cliente_final' => (float) $v->precio_cliente_final,
            ]);

            return array_merge($p->toArray(), [
                'stock_total'      => $p->es_padre ? (float) $variantes->sum('stock_total') : $p->stockTotal(),
                'imagen_url'       => $principal?->url,
                'tipo_label'       => $p->tipoLabel(),
                'tipo_color'       => $p->tipoColor(),
                'categoria_nombre' => $p->categoria?->nombre,
                'categoria_color'  => $p->categoria?->color,
                'variantes'        => $variantes,
            ]);
        });

        $categorias = CategoriaProducto::orderBy('nombre')->get()->unique('id')->values();

        return Inertia::render('Productos/Index', [
            'productos'  => $productos,
            'categorias' => $categorias,
            'filters'    => $request->only(['buscar', 'tipo', 'categoria', 'es_vendible', 'es_insumo']),
        ]);
    }

    public function create(Request $request): Response
    {
        $categorias = CategoriaProducto::where('activa', true)->orderBy('nombre')->get();
        $bodegas    = Bodega::where('activa', true)->orderByDesc('es_principal')->orderBy('nombre')->get();
        $proveedores = Proveedor::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']);

        return Inertia::render('Productos/Create', [
            'tipo'        => $request->query('tipo', ''),
            'categorias'  => $categorias,
            'bodegas'     => $bodegas,
            'proveedores' => $proveedores,
            // Los canales que la empresa configuró en Segmentación. Antes eran tres cajas
            // fijas en la pantalla; ahora la pantalla dibuja los que existan.
            'canales'     => app(PreciosPorCanalService::class)->paraFormulario(null),
        ]);
    }

    /**
     * Abre el formulario de creación con los datos de otro producto ya cargados.
     *
     * No crea nada: llena la pantalla y el usuario revisa, cambia lo que sea distinto y
     * guarda. Duplicar de una vez en la base dejaría productos a medio nombrar cada vez que
     * alguien toca el botón por curiosidad, y con referencias que hay que corregir después.
     *
     * Lo que NO se copia, a propósito:
     * - **La referencia**: es única en la base. Se genera nueva sola.
     * - **El stock**: el inventario es de cada producto, no del molde.
     * - **Las imágenes**: son archivos en el servidor; copiarlas duplica el peso del disco
     *   en cada instalación del cliente. Se suben las del producto nuevo.
     */
    public function duplicar(int $id): Response
    {
        $producto = Producto::with('variantes')->findOrFail($id);

        $base = collect($producto->toArray())->only([
            'tipo', 'categoria_id', 'proveedor_id', 'unidad_medida',
            'descripcion_corta', 'descripcion_larga', 'descripcion_cotizacion',
            'inventariable', 'es_vendible', 'es_insumo',
            'stock_minimo', 'stock_maximo',
            'precio_costo',
            'margen_mayorista', 'margen_distribuidor', 'margen_cliente_final',
            'precio_mayorista', 'precio_distribuidor', 'precio_cliente_final',
            'comision_pct_minima', 'comision_pct_maxima',
            'comision_min_distribuidor', 'comision_max_distribuidor',
            'comision_min_cliente_final', 'comision_max_cliente_final',
            'utilidad_minima_empresa_pct',
            'descuento_max_cliente_final', 'descuento_max_distribuidor', 'descuento_max_mayorista',
            'es_padre', 'atributo_variante',
        ])->all();

        // Los decimales de MySQL llegan como texto ('50000.00'), y el formulario hace
        // cuentas con ellos: un '0.00' es verdadero en JavaScript, y ahí empiezan los
        // márgenes calculados sobre un costo que la pantalla cree que existe.
        foreach ($base as $campo => $valor) {
            if (is_string($valor) && is_numeric($valor)) {
                $base[$campo] = (float) $valor;
            }
        }

        // El nombre llega con el aviso de que es una copia: guardar dos productos con el
        // mismo nombre y distinta referencia es la forma de no volver a encontrar ninguno.
        $base['nombre']    = mb_substr($producto->nombre.' (copia)', 0, 200);
        $base['variantes'] = $producto->variantes->map(fn ($v) => [
            'valor_variante' => $v->valor_variante,
        ])->values()->all();

        return Inertia::render('Productos/Create', [
            'tipo'        => $producto->tipo,
            'categorias'  => CategoriaProducto::where('activa', true)->orderBy('nombre')->get(),
            'bodegas'     => Bodega::where('activa', true)->orderByDesc('es_principal')->orderBy('nombre')->get(),
            'proveedores' => Proveedor::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            // Los precios por canal del original, incluidos los canales que no tenga
            // cargados: se copia lo que hay y lo demás queda listo para llenar.
            'canales'     => app(PreciosPorCanalService::class)->paraFormulario($producto),
            'base'        => $base,
            'origen'      => ['id' => $producto->id, 'nombre' => $producto->nombre],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tipo    = $request->input('tipo', 'producto');
        $esPadre = $request->boolean('es_padre');

        if (! $request->filled('referencia')) {
            $request->merge(['referencia' => Producto::generarReferencia($tipo)]);
        }

        $request->validate($this->reglas($tipo, null, $esPadre));

        $datosBase = [
            'tipo'                => $tipo,
            'categoria_id'        => $request->categoria_id ?: null,
            'proveedor_id'        => $request->proveedor_id ?: null,
            'nombre'              => $request->nombre,
            'referencia'          => $request->referencia,
            'unidad_medida'       => $request->unidad_medida ?? 'unidad',
            'descripcion_corta'   => $request->descripcion_corta,
            'descripcion_larga'   => $request->descripcion_larga,
            'descripcion_cotizacion' => $request->descripcion_cotizacion,
            'inventariable'       => $tipo === 'producto' ? (bool) $request->inventariable : false,
            'es_vendible'         => (bool) $request->es_vendible,
            'es_insumo'           => (bool) $request->es_insumo,
            'stock_minimo'        => $request->stock_minimo ?? 0,
            'stock_maximo'        => $request->stock_maximo ?? 0,
            'precio_costo'                => $request->precio_costo ?? 0,
            'margen_mayorista'            => $request->margen_mayorista ?? 25,
            'margen_distribuidor'         => $request->margen_distribuidor ?? 30,
            'margen_cliente_final'        => $request->margen_cliente_final ?? 35,
            'precio_mayorista'            => $request->precio_mayorista ?? 0,
            'precio_distribuidor'         => $request->precio_distribuidor ?? 0,
            'precio_cliente_final'        => $request->precio_cliente_final ?? 0,
            'comision_pct_minima'         => $request->comision_pct_minima ?? 0,
            'comision_pct_maxima'         => $request->comision_pct_maxima ?? 0,
            'comision_min_distribuidor'   => $request->comision_min_distribuidor ?? 0,
            'comision_max_distribuidor'   => $request->comision_max_distribuidor ?? 0,
            'comision_min_cliente_final'  => $request->comision_min_cliente_final ?? 0,
            'comision_max_cliente_final'  => $request->comision_max_cliente_final ?? 0,
            'utilidad_minima_empresa_pct' => $request->utilidad_minima_empresa_pct ?? 15,
            'descuento_max_cliente_final' => $request->descuento_max_cliente_final ?? 3,
            'descuento_max_distribuidor'  => $request->descuento_max_distribuidor ?? 5,
            'descuento_max_mayorista'     => $request->descuento_max_mayorista ?? 8,
        ];

        $producto = DB::transaction(function () use ($request, $datosBase, $esPadre) {
            $producto = Producto::create(array_merge($datosBase, [
                'es_padre'          => $esPadre,
                'atributo_variante' => $esPadre ? $request->atributo_variante : null,
                'inventariable'     => $esPadre ? false : $datosBase['inventariable'],
            ]));

            $this->guardarCanales($request, $producto);

            if ($esPadre) {
                foreach ($request->input('variantes', []) as $variante) {
                    $this->crearVariante($producto, $datosBase, $variante);
                }
            } else {
                // Stock inicial por bodega
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
                            notas: 'Stock inicial al crear producto'
                        );
                    }
                }
            }

            return $producto;
        });

        $this->procesarImagenes($request, $producto);

        if ($request->boolean('crear_otro')) {
            return redirect('/productos/crear')->with('success', 'Producto creado. Agrega otro.');
        }

        return redirect("/productos/{$producto->id}")->with('success', $esPadre
            ? 'Producto padre y variantes creados correctamente.'
            : 'Producto creado correctamente.');
    }

    public function show(int $id): Response
    {
        $producto = Producto::with([
            'categoria',
            'imagenes',
            'proveedor:id,nombre',
            'padre:id,nombre',
            'stocks.bodega',
            'variantes.stocks',
            'movimientos' => fn ($q) => $q->latest()->limit(20)->with([
                'bodega:id,nombre',
                'bodegaDestino:id,nombre',
                'usuario:id,name',
            ]),
        ])->findOrFail($id);

        $imagenes = $producto->imagenes->map(fn ($img) => array_merge($img->toArray(), [
            'url' => $img->url,
        ]));

        $stocks = $producto->stocks->map(fn ($s) => [
            'bodega_id'     => $s->bodega_id,
            'bodega_nombre' => $s->bodega?->nombre ?? 'Sin bodega',
            'es_principal'  => (bool) ($s->bodega?->es_principal ?? false),
            'cantidad'      => (float) $s->cantidad,
        ]);

        $variantes = $producto->variantes->map(fn ($v) => [
            'id'              => $v->id,
            'nombre'          => $v->nombre,
            'valor_variante'  => $v->valor_variante,
            'referencia'      => $v->referencia,
            'stock_total'     => (float) $v->stocks->sum('cantidad'),
        ]);

        return Inertia::render('Productos/Show', [
            'producto'   => array_merge($producto->toArray(), [
                'stock_total'          => $producto->stockTotal(),
                'tipo_label'           => $producto->tipoLabel(),
                'tipo_color'           => $producto->tipoColor(),
                'categoria_nombre'     => $producto->categoria?->nombre,
                'categoria_color'      => $producto->categoria?->color,
                'imagenes'             => $imagenes,
                'stocks'               => $stocks,
                'variantes'            => $variantes,
                'movimientos_recientes' => $producto->es_padre ? [] : $producto->movimientos,
            ]),
            'categorias' => CategoriaProducto::where('activa', true)->orderBy('nombre')->get(),
            'bodegas'    => Bodega::where('activa', true)->orderByDesc('es_principal')->orderBy('nombre')->get(),
            // Para el interruptor de publicación: si no hay precio público, la ficha del
            // sitio sale sin cifra, y eso se avisa antes de publicar y no después.
            'web'        => [
                'sin_precio' => app(\App\Services\PublicacionWebService::class)->precioParaWeb($producto) === null,
            ],
        ]);
    }

    public function edit(int $id): Response
    {
        $producto = Producto::with(['categoria', 'imagenes', 'stocks.bodega', 'padre:id,nombre', 'variantes.stocks'])->findOrFail($id);

        $imagenes = $producto->imagenes->map(fn ($img) => array_merge($img->toArray(), [
            'url' => $img->url,
        ]));

        $stocks = $producto->stocks->map(fn ($s) => [
            'bodega_id'     => $s->bodega_id,
            'bodega_nombre' => $s->bodega?->nombre ?? 'Sin bodega',
            'cantidad'      => (float) $s->cantidad,
        ]);

        $variantes = $producto->variantes->map(fn ($v) => [
            'id'             => $v->id,
            'nombre'         => $v->nombre,
            'valor_variante' => $v->valor_variante,
            'referencia'     => $v->referencia,
            'stock_total'    => (float) $v->stocks->sum('cantidad'),
        ]);

        return Inertia::render('Productos/Edit', [
            'producto'    => array_merge($producto->toArray(), [
                'stock_total' => $producto->stockTotal(),
                'imagenes'    => $imagenes,
                'stocks'      => $stocks,
                'variantes'   => $variantes,
            ]),
            'categorias'  => CategoriaProducto::where('activa', true)->orderBy('nombre')->get(),
            'proveedores' => Proveedor::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'bodegas'     => Bodega::where('activa', true)->orderByDesc('es_principal')->orderBy('nombre')->get(),
            // Con lo que ya tenga guardado, y en cero los canales creados después de este
            // producto: así aparecen para poder llenarlos.
            'canales'     => app(PreciosPorCanalService::class)->paraFormulario($producto),
        ]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $producto = Producto::findOrFail($id);
        $tipo     = $producto->tipo;

        if ($producto->es_padre) {
            $request->validate([
                'nombre'                       => 'required|string|max:200',
                'categoria_id'                 => 'nullable|exists:categorias_producto,id',
                'atributo_variante'            => 'nullable|string|max:60',
                'variantes'                    => 'nullable|array',
                'variantes.*.valor_variante'   => 'required_with:variantes|string|max:60',
                'variantes.*.referencia'       => 'nullable|string|max:60|distinct|unique:productos,referencia',
                'variantes.*.stock_inicial'    => 'nullable|array',
                'variantes.*.stock_inicial.*'  => 'nullable|numeric|min:0',
            ]);

            try {
                DB::transaction(function () use ($request, $producto) {
                    $producto->update([
                        'nombre'            => $request->nombre,
                        'categoria_id'       => $request->categoria_id ?: null,
                        'atributo_variante' => $request->atributo_variante,
                    ]);

                    $datosBase = collect($producto->toArray())->only([
                        'tipo', 'categoria_id', 'proveedor_id', 'nombre', 'unidad_medida',
                        'descripcion_corta', 'descripcion_larga', 'descripcion_cotizacion', 'es_vendible', 'es_insumo',
                        'inventariable', 'stock_minimo', 'stock_maximo',
                        'precio_costo', 'precio_promedio_compra', 'precio_ultimo_compra',
                        'margen_mayorista', 'margen_distribuidor', 'margen_cliente_final',
                        'precio_mayorista', 'precio_distribuidor', 'precio_cliente_final',
                        'comision_pct_minima', 'comision_pct_maxima',
                        'comision_min_distribuidor', 'comision_max_distribuidor',
                        'comision_min_cliente_final', 'comision_max_cliente_final',
                        'utilidad_minima_empresa_pct',
                        'descuento_max_cliente_final', 'descuento_max_distribuidor', 'descuento_max_mayorista',
                    ])->toArray();
                    $datosBase['inventariable'] = true;

                    foreach ($request->input('variantes', []) as $variante) {
                        $this->crearVariante($producto, $datosBase, $variante);
                    }
                });
            } catch (\Exception $e) {
                return back()->withErrors(['error' => $e->getMessage()]);
            }

            return redirect("/productos/{$producto->id}")->with('success', 'Producto padre actualizado.');
        }

        $request->validate($this->reglas($tipo, $id));

        try {
            $producto->update([
                'categoria_id'         => $request->categoria_id ?: null,
                'proveedor_id'         => $request->proveedor_id ?: null,
                'nombre'               => $request->nombre,
                'referencia'           => $request->referencia,
                'unidad_medida'        => $request->unidad_medida ?? 'unidad',
                'descripcion_corta'    => $request->descripcion_corta,
                'descripcion_larga'    => $request->descripcion_larga,
                'descripcion_cotizacion' => $request->descripcion_cotizacion,
                'inventariable'        => $tipo === 'producto' ? (bool) $request->inventariable : false,
                'es_vendible'          => (bool) $request->es_vendible,
                'es_insumo'            => (bool) $request->es_insumo,
                'stock_minimo'         => $request->stock_minimo ?? 0,
                'stock_maximo'         => $request->stock_maximo ?? 0,
                'precio_costo'                => $request->precio_costo ?? 0,
                'margen_mayorista'            => $request->margen_mayorista ?? 25,
                'margen_distribuidor'         => $request->margen_distribuidor ?? 30,
                'margen_cliente_final'        => $request->margen_cliente_final ?? 35,
                'precio_mayorista'            => $request->precio_mayorista ?? 0,
                'precio_distribuidor'         => $request->precio_distribuidor ?? 0,
                'precio_cliente_final'        => $request->precio_cliente_final ?? 0,
                'comision_pct_minima'         => $request->comision_pct_minima ?? 0,
                'comision_pct_maxima'         => $request->comision_pct_maxima ?? 0,
                'comision_min_distribuidor'   => $request->comision_min_distribuidor ?? 0,
                'comision_max_distribuidor'   => $request->comision_max_distribuidor ?? 0,
                'comision_min_cliente_final'  => $request->comision_min_cliente_final ?? 0,
                'comision_max_cliente_final'  => $request->comision_max_cliente_final ?? 0,
                'utilidad_minima_empresa_pct' => $request->utilidad_minima_empresa_pct ?? 15,
                'descuento_max_cliente_final' => $request->descuento_max_cliente_final ?? 3,
                'descuento_max_distribuidor'  => $request->descuento_max_distribuidor ?? 5,
                'descuento_max_mayorista'     => $request->descuento_max_mayorista ?? 8,
            ]);

            $this->guardarCanales($request, $producto);
            $this->procesarImagenes($request, $producto);
        } catch (\Exception $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        return redirect("/productos/{$producto->id}")->with('success', 'Producto actualizado.');
    }

    /**
     * Guarda los precios por canal, en el formato nuevo o en el viejo.
     *
     * Acepta los dos porque las pantallas se cambian una por una: mientras la de variantes
     * o la de importación sigan mandando `precio_mayorista` y compañía, esos campos tienen
     * que llegar igual a las filas nuevas. Cambiar todo en el mismo commit es la forma de
     * que un error se lleve tres pantallas a la vez.
     *
     * Cuando ya nadie mande el formato viejo, se borra la segunda rama.
     */
    private function guardarCanales(Request $request, Producto $producto): void
    {
        $servicio = app(PreciosPorCanalService::class);
        $filas    = $request->input('canales');

        $servicio->guardar(
            $producto,
            is_array($filas) && $filas !== []
                ? $filas
                : $servicio->desdeCamposViejos($request->all())
        );
    }

    public function destroy(int $id): RedirectResponse
    {
        Producto::findOrFail($id)->delete();

        return redirect('/productos')->with('success', 'Producto eliminado.');
    }

    public function ajusteStock(Request $request, int $id): RedirectResponse
    {
        $producto = Producto::findOrFail($id);

        if ($producto->es_padre) {
            return back()->withErrors(['error' => 'No se puede ajustar stock de un producto padre. Selecciona una variante.']);
        }

        $data = $request->validate([
            'bodega_id'        => 'required|exists:bodegas,id',
            'tipo'             => 'required|in:entrada,salida,ajuste,transferencia,devolucion',
            'cantidad'         => 'required|numeric|min:0.001',
            'bodega_destino_id'=> 'required_if:tipo,transferencia|nullable|exists:bodegas,id|different:bodega_id',
            'precio_unitario'  => 'nullable|numeric|min:0',
            'notas'            => 'nullable|string|max:500',
        ]);

        $producto->registrarMovimiento(
            tipo: $data['tipo'],
            cantidad: (float) $data['cantidad'],
            bodegaId: (int) $data['bodega_id'],
            usuarioId: auth()->id(),
            bodegaDestinoId: isset($data['bodega_destino_id']) ? (int) $data['bodega_destino_id'] : null,
            precioUnitario: isset($data['precio_unitario']) ? (float) $data['precio_unitario'] : null,
            origenTipo: 'ajuste_manual',
            notas: $data['notas'] ?? null
        );

        return back()->with('success', 'Ajuste de stock registrado.');
    }

    /**
     * Edición rápida de precio de costo directo desde el listado, sin pasar
     * por el formulario completo de edición.
     */
    public function actualizarCosto(Request $request, int $id): JsonResponse
    {
        $producto = Producto::findOrFail($id);

        if ($producto->es_padre) {
            return response()->json(['message' => 'Un producto padre no tiene precio de costo propio — edita cada variante.'], 422);
        }

        $data = $request->validate(['precio_costo' => 'required|numeric|min:0']);
        $producto->update(['precio_costo' => $data['precio_costo']]);

        return response()->json(['precio_costo' => (float) $producto->precio_costo]);
    }

    public function umbrales(Request $request, int $id): RedirectResponse
    {
        $producto = Producto::findOrFail($id);

        $request->validate([
            'stock_minimo' => 'required|numeric|min:0',
            'stock_maximo' => 'required|numeric|min:0',
        ]);

        $producto->update([
            'stock_minimo' => $request->stock_minimo,
            'stock_maximo' => $request->stock_maximo,
        ]);

        return back()->with('success', 'Umbrales de stock actualizados.');
    }

    public function buscar(Request $request): JsonResponse
    {
        $q = $request->query('q', '');

        $productos = Producto::with(['imagenes', 'padre:id,nombre'])
            ->seleccionables()
            ->where('activo', true)
            ->whereIn('tipo', ['producto', 'servicio'])
            ->where(function ($query) use ($q) {
                $query->where('nombre', 'like', "%{$q}%")
                      ->orWhere('referencia', 'like', "%{$q}%")
                      ->orWhereHas('padre', function ($q2) use ($q) {
                          $q2->where('nombre', 'like', "%{$q}%");
                      });
            })
            ->limit(20)
            ->get()
            ->map(function ($p) {
                $img = $p->imagenes->firstWhere('es_principal', true) ?? $p->imagenes->first();
                return [
                    'id'                   => $p->id,
                    'nombre'               => $p->nombre,
                    'nombre_completo'      => $p->nombre_completo,
                    'referencia'           => $p->referencia,
                    'tipo'                 => $p->tipo,
                    'padre_nombre'         => $p->padre?->nombre,
                    'atributo_variante'    => $p->padre?->atributo_variante,
                    'valor_variante'       => $p->valor_variante,
                    'stock_total'          => $p->stockTotal(),
                    'precio_costo'                => (float) $p->precio_costo,
                    'precio_mayorista'            => (float) $p->precio_mayorista,
                    'precio_distribuidor'         => (float) $p->precio_distribuidor,
                    'precio_cliente_final'        => (float) $p->precio_cliente_final,
                    'comision_pct_minima'         => (float) $p->comision_pct_minima,
                    'comision_pct_maxima'         => (float) $p->comision_pct_maxima,
                    'comision_min_distribuidor'   => (float) ($p->comision_min_distribuidor ?? 0),
                    'comision_max_distribuidor'   => (float) ($p->comision_max_distribuidor ?? 0),
                    'comision_min_cliente_final'  => (float) ($p->comision_min_cliente_final ?? 0),
                    'comision_max_cliente_final'  => (float) ($p->comision_max_cliente_final ?? 0),
                    'descuento_max_cliente_final' => (float) $p->descuento_max_cliente_final,
                    'descuento_max_distribuidor'  => (float) $p->descuento_max_distribuidor,
                    'descuento_max_mayorista'     => (float) $p->descuento_max_mayorista,
                    'unidad_medida'               => $p->unidad_medida,
                    'imagen_url'                  => $img?->url,
                ];
            });

        return response()->json($productos);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────────

    private function crearVariante(Producto $padre, array $datosBase, array $variante): Producto
    {
        $referencia = ($variante['referencia'] ?? null) ?: Producto::generarReferenciaVariante($padre, $variante['valor_variante']);

        $hijo = Producto::create(array_merge($datosBase, [
            'referencia'         => $referencia,
            'es_padre'           => false,
            'producto_padre_id'  => $padre->id,
            'atributo_variante'  => null,
            'valor_variante'     => $variante['valor_variante'],
        ]));

        foreach (($variante['stock_inicial'] ?? []) as $bodegaId => $cantidad) {
            $cantidad = (float) $cantidad;
            if ($cantidad > 0) {
                $hijo->registrarMovimiento(
                    tipo: 'entrada',
                    cantidad: $cantidad,
                    bodegaId: (int) $bodegaId,
                    usuarioId: auth()->id(),
                    origenTipo: 'creacion_producto',
                    notas: 'Stock inicial al crear variante'
                );
            }
        }

        return $hijo;
    }

    private function reglas(string $tipo, ?int $ignoreId = null, bool $esPadre = false): array
    {
        $referenciaRule = $ignoreId
            ? "required|string|max:60|unique:productos,referencia,{$ignoreId}"
            : 'required|string|max:60|unique:productos,referencia';

        return [
            'nombre'              => 'required|string|max:200',
            'referencia'          => $referenciaRule,
            'unidad_medida'       => 'nullable|string|max:30',
            'categoria_id'        => 'nullable|exists:categorias_producto,id',
            'proveedor_id'        => 'nullable|exists:proveedores,id',
            // 1000 y no 160: es lo que dice el contador de la pantalla y lo que ya
            // aceptaba `ensambles.descripcion_corta`. Con 160, una ficha generada con IA
            // —hasta 380 caracteres de introducción— se veía bien y reventaba al guardar.
            'descripcion_corta'   => 'nullable|string|max:1000',
            // La columna es TEXT: 65.535 bytes. El tope explícito existe para que pasarse
            // dé un mensaje claro en vez de un error de base de datos, y va por debajo del
            // límite real porque un carácter acentuado ocupa más de un byte.
            'descripcion_larga'   => 'nullable|string|max:60000',
            // El técnico corto: cotizaciones y órdenes de producción.
            'descripcion_cotizacion' => 'nullable|string|max:600',
            'es_vendible'         => 'nullable|boolean',
            'es_insumo'           => 'nullable|boolean',
            'es_padre'            => 'nullable|boolean',
            'atributo_variante'   => 'nullable|string|max:60',
            'variantes'                    => ($esPadre ? 'required' : 'nullable') . '|array' . ($esPadre ? '|min:1' : ''),
            'variantes.*.valor_variante'   => 'required_with:variantes|string|max:60',
            'variantes.*.referencia'       => 'nullable|string|max:60|distinct|unique:productos,referencia',
            'variantes.*.stock_inicial'    => 'nullable|array',
            'variantes.*.stock_inicial.*'  => 'nullable|numeric|min:0',
            'precio_costo'                => 'nullable|numeric|min:0',
            'margen_mayorista'            => 'nullable|numeric|min:1|max:99',
            'margen_distribuidor'         => 'nullable|numeric|min:1|max:99',
            'margen_cliente_final'        => 'nullable|numeric|min:1|max:99',
            'precio_mayorista'            => 'nullable|numeric|min:0',
            'precio_distribuidor'         => 'nullable|numeric|min:0',
            'precio_cliente_final'        => 'nullable|numeric|min:0',
            'comision_pct_minima'         => 'nullable|numeric|min:0|max:100',
            'comision_pct_maxima'         => 'nullable|numeric|min:0|max:100',
            'comision_min_distribuidor'   => 'nullable|numeric|min:0|max:100',
            'comision_max_distribuidor'   => 'nullable|numeric|min:0|max:100',
            'comision_min_cliente_final'  => 'nullable|numeric|min:0|max:100',
            'comision_max_cliente_final'  => 'nullable|numeric|min:0|max:100',
            'utilidad_minima_empresa_pct' => 'nullable|numeric|min:0|max:100',
            'descuento_max_cliente_final' => 'nullable|numeric|min:0|max:100',
            'descuento_max_distribuidor'  => 'nullable|numeric|min:0|max:100',
            'descuento_max_mayorista'     => 'nullable|numeric|min:0|max:100',
            'imagenes.*'                  => 'nullable|image|max:5120',
            'stock_inicial'               => 'nullable|array',
            'stock_inicial.*'             => 'nullable|numeric|min:0',
        ];
    }

    private function procesarImagenes(Request $request, Producto $producto): void
    {
        if (! $request->hasFile('imagenes')) return;

        $orden = $producto->imagenes()->max('orden') ?? 0;

        foreach ($request->file('imagenes') as $archivo) {
            $resultado = ArchivoServidorService::subir($archivo, 'productos');
            $orden++;

            ImagenProducto::create([
                'producto_id'  => $producto->id,
                'ruta'         => $resultado['url'],
                'drive_id'     => $resultado['id'],
                'es_principal' => $producto->imagenes()->count() === 0 && $orden === 1,
                'orden'        => $orden,
            ]);
        }
    }
}
