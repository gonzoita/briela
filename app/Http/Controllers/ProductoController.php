<?php

namespace App\Http\Controllers;

use App\Models\Bodega;
use App\Models\CategoriaProducto;
use App\Models\ImagenProducto;
use App\Models\Producto;
use App\Models\RemisionItem;
use App\Models\ProductoMovimiento;
use App\Models\OrdenCompra;
use App\Models\Op;
use App\Models\Proveedor;
use App\Services\ArchivoServidorService;
use App\Services\PreciosPorCanalService;
use App\Services\TasaCambioService;
use App\Support\Monedas;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProductoController extends Controller
{
    /**
     * Los productos que muestra el listado con los filtros de la petición.
     *
     * Es de aquí de donde sale también «eliminar todos los del filtro»: si esa acción armara
     * su propia consulta, un filtro que se agregue al listado y se olvide allá borraría más
     * de lo que la persona estaba viendo.
     */
    private function consultaFiltrada(Request $request)
    {
        return Producto::query()
            ->whereNull('producto_padre_id')
            ->when($request->filled('tipo'), fn ($q) => $q->where('tipo', $request->tipo))
            ->when($request->filled('categoria'), fn ($q) => $q->where('categoria_id', $request->categoria))
            ->when($request->filled('es_vendible'), fn ($q) => $q->where('es_vendible', true))
            ->when($request->filled('es_insumo'), fn ($q) => $q->where('es_insumo', true))
            ->when($request->filled('buscar'), fn ($q) => $q->where(function ($q) use ($request) {
                $q->where('nombre', 'like', "%{$request->buscar}%")
                  ->orWhere('referencia', 'like', "%{$request->buscar}%");
            }));
    }

    public function index(Request $request): Response
    {
        $query = $this->consultaFiltrada($request)
            ->with(['categoria', 'imagenes', 'stocks.bodega', 'variantes.stocks']);

        // El orden lo pide la pantalla. `Orden::aplicar` valida el campo contra esta lista:
        // lo que llegue por `?orden=` y no esté aquí se ignora, así que el parámetro no puede
        // tocar el SQL.
        $orden = \App\Support\Orden::aplicar($query, $request, [
            'nombre'       => 'nombre',
            'referencia'   => 'referencia',
            'precio_costo' => 'precio_costo',
            'created_at'   => 'created_at',
        ], 'created_at', 'desc');

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
            'orden'      => $orden,
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
            ...$this->propsMonedas(),
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
            'precio_costo', 'moneda_costo', 'costo_moneda',
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
            ...$this->propsMonedas(),
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
        $this->aplicarMonedaCosto($request);

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
            'moneda_costo'                => $request->moneda_costo,
            'costo_moneda'                => $request->costo_moneda,
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

        $imagenesDeVariantes = [];

        $producto = DB::transaction(function () use ($request, $datosBase, $esPadre, &$imagenesDeVariantes) {
            Producto::liberarReferencia($request->referencia);

            $producto = Producto::create(array_merge($datosBase, [
                'es_padre'          => $esPadre,
                'atributo_variante' => $esPadre ? $request->atributo_variante : null,
                'inventariable'     => $esPadre ? false : $datosBase['inventariable'],
            ]));

            $this->guardarCanales($request, $producto);
            $this->guardarProveedores($request, $producto);

            if ($esPadre) {
                $imagenesDeVariantes = $this->crearVariantes($request, $producto, $datosBase);
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
        $this->subirImagenesDeVariantes($imagenesDeVariantes);

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
            'proveedores.proveedor:id,nombre',
            'padre.imagenes',
            'stocks.bodega',
            'variantes.stocks',
        ])->findOrFail($id);

        $imagenes = $producto->imagenesVisibles()->map(fn ($img) => array_merge($img->toArray(), [
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

        // Un padre no tiene stock propio: sus movimientos son los de cada variante.
        $movimientos = $producto->es_padre ? ['data' => [], 'hay_mas' => false] : $this->movimientosDe($producto);

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
                'movimientos_recientes' => $movimientos['data'],
                'movimientos_hay_mas'   => $movimientos['hay_mas'],
                'remisiones'            => $producto->es_padre ? [] : $this->remisionesDe($producto),
                // La comparación de proveedores, ya resuelta: la ficha muestra quién lo
                // vende más barato y cuánto se ahorra. Antes solo salía el último al que se
                // le compró, y comparar era abrir un cuaderno.
                'proveedores_precios'   => $producto->proveedores->map(fn ($pp) => [
                    'proveedor_id'         => $pp->proveedor_id,
                    'proveedor_nombre'     => $pp->proveedor?->nombre,
                    'referencia_proveedor' => $pp->referencia_proveedor,
                    'precio'               => (float) $pp->precio,
                    'dias_entrega'         => $pp->dias_entrega,
                    'minimo_compra'        => $pp->minimo_compra !== null ? (float) $pp->minimo_compra : null,
                    'es_preferido'         => (bool) $pp->es_preferido,
                    'actualizado_el'       => $pp->actualizado_el?->toDateString(),
                    'dias_desde'           => $pp->diasDesdeActualizacion(),
                    'notas'                => $pp->notas,
                ])->values(),
                'ahorro_proveedores'    => $producto->ahorroEntreProveedores(),
            ]),
            'categorias' => CategoriaProducto::where('activa', true)->orderBy('nombre')->get(),
            'bodegas'    => Bodega::where('activa', true)->orderByDesc('es_principal')->orderBy('nombre')->get(),
            // Los canales configurados con el precio EFECTIVO de este producto en cada
            // uno: lo guardado o, si falta, lo que haya en la columna vieja. La lista de
            // precios del visor mostraba tres nombres escritos en la pantalla, así que en
            // una instalación con canales propios enseñaba nombres que no existen y dejaba
            // por fuera los canales que la empresa creó.
            'canales'    => app(\App\Services\CanalesPrecioService::class)->canales()
                ->map(function ($canal) use ($producto) {
                    $fila = app(PreciosPorCanalService::class)->filaEfectiva($producto, $canal);

                    return [
                        'segmentacion_opcion_id' => $canal->id,
                        'etiqueta'               => $canal->etiqueta,
                        'es_canal_base'          => (bool) $canal->es_canal_base,
                        'es_precio_publico'      => (bool) $canal->es_precio_publico,
                        'precio'                 => $fila['precio'],
                    ];
                })->values(),
            // Para el interruptor de publicación: si no hay precio público, la ficha del
            // sitio sale sin cifra, y eso se avisa antes de publicar y no después.
            'web'        => [
                'sin_precio' => app(\App\Services\PublicacionWebService::class)->precioParaWeb($producto) === null,
            ],
        ]);
    }

    public function edit(int $id): Response
    {
        $producto = Producto::with(['categoria', 'imagenes', 'stocks.bodega', 'padre:id,nombre', 'variantes.stocks', 'proveedores'])->findOrFail($id);

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
            ...$this->propsMonedas(),
            'producto'    => array_merge($producto->toArray(), [
                'stock_total' => $producto->stockTotal(),
                'imagenes'    => $imagenes,
                'stocks'      => $stocks,
                'variantes'   => $variantes,
                // Los proveedores con su precio, para el comparador del formulario.
                'proveedores_precios' => $producto->proveedores->map(fn ($pp) => [
                    'proveedor_id'         => (string) $pp->proveedor_id,
                    'referencia_proveedor' => $pp->referencia_proveedor,
                    'precio'               => (float) $pp->precio,
                    'dias_entrega'         => $pp->dias_entrega,
                    'minimo_compra'        => $pp->minimo_compra !== null ? (float) $pp->minimo_compra : null,
                    'es_preferido'         => (bool) $pp->es_preferido,
                    'actualizado_el'       => $pp->actualizado_el?->toDateString(),
                    'notas'                => $pp->notas,
                ])->values(),
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
                'variantes.*.referencia'       => ['nullable', 'string', 'max:60', 'distinct', $this->referenciaLibre()],
                'variantes.*.imagen'           => 'nullable|image|max:5120',
            'variantes.*.stock_inicial'    => 'nullable|array',
                'variantes.*.stock_inicial.*'  => 'nullable|numeric|min:0',
            ]);

            try {
                $imagenesDeVariantes = [];

                DB::transaction(function () use ($request, $producto, &$imagenesDeVariantes) {
                    $producto->update([
                        'nombre'            => $request->nombre,
                        'categoria_id'       => $request->categoria_id ?: null,
                        'atributo_variante' => $request->atributo_variante,
                    ]);

                    $datosBase = collect($producto->toArray())->only([
                        'tipo', 'categoria_id', 'proveedor_id', 'nombre', 'unidad_medida',
                        'descripcion_corta', 'descripcion_larga', 'descripcion_cotizacion', 'es_vendible', 'es_insumo',
                        'inventariable', 'stock_minimo', 'stock_maximo',
                        'precio_costo', 'moneda_costo', 'costo_moneda', 'precio_promedio_compra', 'precio_ultimo_compra',
                        'margen_mayorista', 'margen_distribuidor', 'margen_cliente_final',
                        'precio_mayorista', 'precio_distribuidor', 'precio_cliente_final',
                        'comision_pct_minima', 'comision_pct_maxima',
                        'comision_min_distribuidor', 'comision_max_distribuidor',
                        'comision_min_cliente_final', 'comision_max_cliente_final',
                        'utilidad_minima_empresa_pct',
                        'descuento_max_cliente_final', 'descuento_max_distribuidor', 'descuento_max_mayorista',
                    ])->toArray();
                    $datosBase['inventariable'] = true;

                    $imagenesDeVariantes = $this->crearVariantes($request, $producto, $datosBase);
                });

                $this->subirImagenesDeVariantes($imagenesDeVariantes);
            } catch (\Exception $e) {
                return back()->withErrors(['error' => $e->getMessage()]);
            }

            return redirect("/productos/{$producto->id}")->with('success', 'Producto padre actualizado.');
        }

        $request->validate($this->reglas($tipo, $id));
        $this->aplicarMonedaCosto($request);

        try {
            Producto::liberarReferencia($request->referencia, $producto->id);

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
                'moneda_costo'                => $request->moneda_costo,
                'costo_moneda'                => $request->costo_moneda,
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
            $this->guardarProveedores($request, $producto);
            $this->procesarImagenes($request, $producto);
        } catch (\Exception $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        return redirect("/productos/{$producto->id}")->with('success', 'Producto actualizado.');
    }

    /**
     * Guarda la lista de proveedores del producto y sincroniza la columna de siempre.
     *
     * `productos.proveedor_id` NO se retira: las órdenes de compra y varias pantallas la
     * leen, y al otro lado hay bases de clientes con versiones anteriores (regla 2). Queda
     * apuntando al **preferido**, así que el código viejo sigue viendo un proveedor correcto
     * mientras el nuevo compara la lista completa.
     */
    private function guardarProveedores(Request $request, Producto $producto): void
    {
        $filas = $request->input('proveedores_precios');

        // Sin la clave, la pantalla no maneja proveedores: no se toca nada. Es lo que
        // permite que la importación y la pantalla de variantes sigan funcionando sin
        // borrarle los proveedores a un producto que ya los tenía.
        if (! is_array($filas)) {
            return;
        }

        $vistos     = [];
        $preferido  = null;

        foreach ($filas as $fila) {
            $proveedorId = (int) ($fila['proveedor_id'] ?? 0);

            if (! $proveedorId || in_array($proveedorId, $vistos, true)) {
                continue;
            }

            $vistos[] = $proveedorId;

            \App\Models\ProductoProveedor::updateOrCreate(
                ['producto_id' => $producto->id, 'proveedor_id' => $proveedorId],
                [
                    'referencia_proveedor' => $fila['referencia_proveedor'] ?? null,
                    'precio'               => (float) ($fila['precio'] ?? 0),
                    // Con `??`, no con un `!== null` a secas: la fila no siempre trae las
                    // seis claves. La importación y la API mandan solo lo que tienen, y
                    // leer una clave ausente es un warning que Laravel convierte en
                    // excepción — un 500 al guardar un producto con proveedor.
                    'dias_entrega'         => ($fila['dias_entrega'] ?? null) !== null && $fila['dias_entrega'] !== ''
                        ? (int) $fila['dias_entrega'] : null,
                    'minimo_compra'        => ($fila['minimo_compra'] ?? null) !== null && $fila['minimo_compra'] !== ''
                        ? (float) $fila['minimo_compra'] : null,
                    'es_preferido'         => (bool) ($fila['es_preferido'] ?? false),
                    'actualizado_el'       => $fila['actualizado_el'] ?? null,
                    'notas'                => $fila['notas'] ?? null,
                ]
            );

            if ($fila['es_preferido'] ?? false) {
                $preferido = $proveedorId;
            }
        }

        // Las que ya no están en la pantalla se van: se quitaron a propósito.
        $producto->proveedores()->whereNotIn('proveedor_id', $vistos ?: [0])->delete();

        // Si nadie quedó marcado, manda el más barato: es la elección que la persona haría
        // igual, y dejar la columna vieja en null rompería las órdenes de compra.
        if (! $preferido) {
            $preferido = $producto->proveedores()->where('precio', '>', 0)
                ->orderBy('precio')->value('proveedor_id');
        }

        if ($preferido && (int) $producto->proveedor_id !== (int) $preferido) {
            $producto->newQuery()->whereKey($producto->getKey())->update(['proveedor_id' => $preferido]);
        }
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
        $this->eliminarConVariantes(Producto::findOrFail($id));

        return redirect('/productos')->with('success', 'Producto eliminado.');
    }

    /**
     * Elimina varios productos de una vez: los marcados, o todos los del filtro.
     *
     * Con «todos los del filtro» no viajan ids sino los mismos filtros del listado, y la
     * consulta sale de `consultaFiltrada()`: se borra exactamente lo que la persona estaba
     * viendo, aunque sean cinco páginas.
     *
     * Es un borrado suave, igual que el de uno solo: las cotizaciones, órdenes y movimientos
     * que ya usaron esos productos los siguen mostrando.
     */
    public function eliminarVarios(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'ids'              => 'array|required_without:todos_del_filtro',
            'ids.*'            => 'integer',
            'todos_del_filtro' => 'boolean',
        ]);

        $query = ($datos['todos_del_filtro'] ?? false)
            ? $this->consultaFiltrada($request)
            // Solo padres o sueltos, igual que en el listado: una variante no se elige por
            // separado, se va con su padre.
            : Producto::whereIn('id', $datos['ids'] ?? [])->whereNull('producto_padre_id');

        $total = 0;

        DB::transaction(function () use ($query, &$total) {
            $query->with('variantes')->chunkById(200, function ($productos) use (&$total) {
                foreach ($productos as $producto) {
                    $this->eliminarConVariantes($producto);
                    $total++;
                }
            });
        });

        return back()->with('success', $total === 1
            ? 'Se eliminó 1 producto.'
            : "Se eliminaron {$total} productos.");
    }

    /**
     * Un padre se lleva sus variantes.
     *
     * Antes se borraba solo el padre, y sus variantes quedaban vivas: el listado ya no las
     * mostraba —cuelgan de un padre que no existe—, pero seguían apareciendo en el buscador
     * de la cotización, con su precio, como si nada.
     */
    private function eliminarConVariantes(Producto $producto): void
    {
        $producto->variantes->each->delete();
        $producto->delete();
    }

    /** Cuántos movimientos se traen por vez. */
    private const MOVIMIENTOS_POR_PAGINA = 30;

    /**
     * Más movimientos de un producto, hacia atrás: la ficha trae los primeros y pide el resto
     * por tandas. Un cursor (`antes` = el id del último que se ve) y no un número de página:
     * si entra un movimiento mientras alguien lee, la página 2 repetiría el último de la 1.
     */
    public function movimientos(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'antes' => 'nullable|integer|min:1',
            'tipo'  => 'nullable|in:entrada,salida,ajuste,transferencia,devolucion,consumo_ensamble,venta',
        ]);

        $producto = Producto::findOrFail($id);

        return response()->json($this->movimientosDe(
            $producto,
            $request->filled('antes') ? $request->integer('antes') : null,
            $request->input('tipo'),
        ));
    }

    /**
     * Los movimientos de un producto, con su origen y su papel ya resueltos.
     *
     * @return array{data: list<array<string, mixed>>, hay_mas: bool}
     */
    private function movimientosDe(Producto $producto, ?int $antes = null, ?string $tipo = null): array
    {
        $filas = $producto->movimientos()
            ->with(['bodega:id,nombre', 'bodegaDestino:id,nombre', 'usuario:id,name'])
            ->when($antes, fn ($q) => $q->where('id', '<', $antes))
            ->when($tipo, fn ($q) => $q->where('tipo', $tipo))
            ->orderByDesc('id')
            ->limit(self::MOVIMIENTOS_POR_PAGINA + 1)
            ->get();

        $hayMas = $filas->count() > self::MOVIMIENTOS_POR_PAGINA;
        $filas  = $filas->take(self::MOVIMIENTOS_POR_PAGINA);

        // Los orígenes en bloque: una consulta por tipo, no una por movimiento.
        $ordenes = OrdenCompra::with('proveedor:id,nombre')
            ->whereIn('id', $filas->where('origen_tipo', 'orden_compra')->pluck('origen_id')->filter())
            ->get(['id', 'numero', 'proveedor_id'])->keyBy('id');

        $ops = Op::withTrashed()
            ->whereIn('id', $filas->where('origen_tipo', 'op')->pluck('origen_id')->filter())
            ->get(['id', 'numero'])->keyBy('id');

        return [
            'data'    => $filas->map(fn (ProductoMovimiento $m) => $this->filaMovimiento($m, $ordenes, $ops))->values()->all(),
            'hay_mas' => $hayMas,
        ];
    }

    /** @return array<string, mixed> */
    private function filaMovimiento(ProductoMovimiento $m, $ordenes, $ops): array
    {
        $origen = match ($m->origen_tipo) {
            null                => null,
            'orden_compra'      => ($oc = $ordenes->get($m->origen_id))
                ? ['etiqueta' => "Orden {$oc->numero}".($oc->proveedor ? " · {$oc->proveedor->nombre}" : ''), 'url' => "/compras/ordenes/{$oc->id}"]
                : ['etiqueta' => 'Orden de compra', 'url' => null],
            'op'                => ($op = $ops->get($m->origen_id))
                ? ['etiqueta' => "Orden de producción {$op->numero}", 'url' => "/ops/{$op->id}"]
                : ['etiqueta' => 'Orden de producción', 'url' => null],
            'ajuste_manual'     => ['etiqueta' => 'Ajuste manual', 'url' => null],
            'creacion_producto' => ['etiqueta' => 'Stock inicial', 'url' => null],
            'importacion_csv'   => ['etiqueta' => 'Importación', 'url' => null],
            'corte'             => ['etiqueta' => 'Corte de material', 'url' => null],
            default             => ['etiqueta' => ucfirst(str_replace('_', ' ', $m->origen_tipo)), 'url' => null],
        };

        return [
            'id'              => $m->id,
            'created_at'      => $m->created_at?->toIso8601String(),
            'tipo'            => $m->tipo,
            'cantidad'        => (float) $m->cantidad,
            'stock_anterior'  => (float) $m->stock_anterior,
            'stock_nuevo'     => (float) $m->stock_nuevo,
            'precio_unitario' => $m->precio_unitario !== null ? (float) $m->precio_unitario : null,
            'bodega'          => $m->bodega ? ['nombre' => $m->bodega->nombre] : null,
            'bodega_destino'  => $m->bodegaDestino ? ['nombre' => $m->bodegaDestino->nombre] : null,
            'usuario'         => $m->usuario ? ['name' => $m->usuario->name] : null,
            'notas'           => $m->notas,
            'origen'          => $origen,
            // El papel que lo respalda: factura, remisión u otro, con su número y su fecha.
            'documento'       => filled($m->documento_numero) ? [
                'etiqueta' => ['factura' => 'Factura', 'remision' => 'Remisión'][$m->documento_tipo] ?? 'Documento',
                'numero'   => $m->documento_numero,
                'fecha'    => $m->documento_fecha?->toDateString(),
            ] : null,
        ];
    }

    /**
     * Las remisiones en las que salió este producto: las últimas diez.
     *
     * @return list<array<string, mixed>>
     */
    private function remisionesDe(Producto $producto): array
    {
        return RemisionItem::where('producto_id', $producto->id)
            ->with(['remision:id,numero,estado,fecha_remision,cliente_id', 'remision.cliente:id,nombre'])
            ->latest('id')
            ->limit(10)
            ->get()
            ->filter(fn (RemisionItem $i) => $i->remision)
            ->map(fn (RemisionItem $i) => [
                'id'       => $i->remision->id,
                'numero'   => $i->remision->numero,
                'estado'   => $i->remision->estado,
                'fecha'    => $i->remision->fecha_remision ? \Illuminate\Support\Carbon::parse($i->remision->fecha_remision)->toDateString() : null,
                'cliente'  => $i->remision->cliente?->nombre,
                'cantidad' => (float) $i->cantidad,
                'unidad'   => $i->unidad,
            ])
            ->values()
            ->all();
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
            // El papel que respalda el ajuste, si lo hay: una entrada sin factura ni remisión
            // es un número que nadie puede comprobar.
            'documento_tipo'   => 'nullable|in:factura,remision,otro',
            'documento_numero' => 'nullable|string|max:60|required_with:documento_tipo',
            'documento_fecha'  => 'nullable|date',
        ]);

        $producto->registrarMovimiento(
            tipo: $data['tipo'],
            cantidad: (float) $data['cantidad'],
            bodegaId: (int) $data['bodega_id'],
            usuarioId: auth()->id(),
            bodegaDestinoId: isset($data['bodega_destino_id']) ? (int) $data['bodega_destino_id'] : null,
            precioUnitario: isset($data['precio_unitario']) ? (float) $data['precio_unitario'] : null,
            origenTipo: 'ajuste_manual',
            notas: $data['notas'] ?? null,
            documentoTipo: $data['documento_tipo'] ?? null,
            documentoNumero: $data['documento_numero'] ?? null,
            documentoFecha: $data['documento_fecha'] ?? null,
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

        // Solo el stock de las bodegas visibles en la sede activa: el inventario de otra
        // sede no es el de esta, y quien cotiza necesita saber con qué cuenta él.
        $bodegas = \App\Support\ContextoSede::idsBodegasVisibles();

        $productos = Producto::with(['imagenes', 'padre.imagenes'])
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
            ->map(function ($p) use ($bodegas) {
                $img = $p->imagenVisible();
                return [
                    'id'                   => $p->id,
                    'nombre'               => $p->nombre,
                    'nombre_completo'      => $p->nombre_completo,
                    'referencia'           => $p->referencia,
                    'tipo'                 => $p->tipo,
                    'padre_nombre'         => $p->padre?->nombre,
                    'atributo_variante'    => $p->padre?->atributo_variante,
                    'valor_variante'       => $p->valor_variante,
                    'stock_total'          => $p->stockEnBodegas($bodegas),
                    // El mínimo y si lleva inventario: sin los dos, quien cotiza ve un
                    // número sin saber si es poco. Un servicio no tiene stock que mirar.
                    'stock_minimo'         => (float) ($p->stock_minimo ?? 0),
                    'inventariable'        => (bool) $p->inventariable,
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

    /**
     * Crea las variantes que llegan en la petición y devuelve sus imágenes pendientes.
     *
     * La imagen NO se sube aquí, que es dentro de la transacción: si algo falla después y se
     * deshace, el archivo quedaría en el disco sin que nada lo apunte. Se sube al terminar.
     * Tampoco viaja con `input('variantes')`: los archivos no vienen ahí, se piden por su
     * posición con `file()`.
     *
     * @return list<array{0: Producto, 1: \Illuminate\Http\UploadedFile}>
     */
    private function crearVariantes(Request $request, Producto $padre, array $datosBase): array
    {
        $pendientes = [];

        foreach ($request->input('variantes', []) as $i => $variante) {
            $hijo = $this->crearVariante($padre, $datosBase, $variante);

            if ($archivo = $request->file("variantes.{$i}.imagen")) {
                $pendientes[] = [$hijo, $archivo];
            }
        }

        return $pendientes;
    }

    /** @param list<array{0: Producto, 1: \Illuminate\Http\UploadedFile}> $pendientes */
    private function subirImagenesDeVariantes(array $pendientes): void
    {
        foreach ($pendientes as [$variante, $archivo]) {
            $this->guardarImagenes($variante, [$archivo]);
        }
    }

    /**
     * Crea una variante con los datos del padre y su propia referencia.
     *
     * **Los precios por canal se guardan también en la variante.** Lo que se cotiza es la
     * variante, no el padre —`Producto::scopeSeleccionables()` deja fuera a los padres—,
     * así que una variante sin filas en `canal_precios` se cotizaba en **cero** por
     * cualquier canal que la empresa hubiera creado por su cuenta: los tres de fábrica
     * tenían el respaldo de las columnas viejas, que sí se copian, y el cuarto no tenía
     * nada de dónde salir. Un precio en cero que nadie pidió se firma.
     */
    private function crearVariante(Producto $padre, array $datosBase, array $variante): Producto
    {
        $referencia = ($variante['referencia'] ?? null) ?: Producto::generarReferenciaVariante($padre, $variante['valor_variante']);

        Producto::liberarReferencia($referencia);

        $hijo = Producto::create(array_merge($datosBase, [
            'referencia'         => $referencia,
            'es_padre'           => false,
            'producto_padre_id'  => $padre->id,
            'atributo_variante'  => null,
            'valor_variante'     => $variante['valor_variante'],
        ]));

        app(PreciosPorCanalService::class)->copiar($padre, $hijo);

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

    /** Las tasas vigentes y el colchón, para que el formulario muestre el costo en pesos. */
    private function propsMonedas(): array
    {
        return [
            'tasas'       => app(TasaCambioService::class)->paraInterfaz(),
            'colchon_pct' => Monedas::colchonPct(),
        ];
    }

    /**
     * Si el producto se compra en otra moneda, el costo en pesos lo calcula el servidor.
     *
     * La pantalla lo muestra calculado mientras se escribe, pero con la tasa que tenía al
     * abrirse; si la tasa cambió entre tanto, manda la de ahora. Y los precios de cada canal
     * se rehacen con ese costo, que es la misma cuenta de la pantalla: un precio calculado
     * sobre un costo distinto del guardado no se podría reproducir después.
     */
    private function aplicarMonedaCosto(Request $request): void
    {
        $moneda = $request->input('moneda_costo') ?: Monedas::LOCAL;

        if ($moneda === Monedas::LOCAL) {
            $request->merge(['moneda_costo' => Monedas::LOCAL, 'costo_moneda' => null]);

            return;
        }

        $costo = (float) $request->input('costo_moneda');

        if ($costo <= 0) {
            throw ValidationException::withMessages(['costo_moneda' => "Escribe cuánto cuesta en {$moneda}."]);
        }

        $pesos = app(TasaCambioService::class)->costoEnPesos($costo, $moneda);

        if ($pesos === null) {
            throw ValidationException::withMessages([
                'costo_moneda' => "No hay tasa de {$moneda} guardada. Regístrala en Configuración → Monedas.",
            ]);
        }

        $cambios = ['precio_costo' => $pesos];
        $filas   = $request->input('canales');

        if (is_array($filas)) {
            $precios = app(PreciosPorCanalService::class);

            foreach ($filas as $i => $fila) {
                $margen = (float) ($fila['margen_pct'] ?? 0);

                if ($margen > 0) {
                    $filas[$i]['precio'] = $precios->precioDesdeCosto($pesos, $margen);
                }
            }

            $cambios['canales'] = $filas;
        }

        $request->merge($cambios);
    }

    /**
     * Que la referencia no la tenga ya otro producto VIVO, diciendo CUÁL la tiene.
     *
     * Antes era `unique:productos,referencia` y el mensaje decía «El campo
     * variantes.0.referencia ya está en uso»: ni de quién, ni dónde. Además contaba los
     * productos eliminados, que la persona ya no ve.
     *
     * Lo eliminado no bloquea: al guardar, `Producto::liberarReferencia()` le cambia la
     * referencia al eliminado y deja la original para quien la pide.
     */
    private function referenciaLibre(?int $ignoreId = null): \Closure
    {
        return function (string $atributo, mixed $valor, \Closure $falla) use ($ignoreId) {
            if (! is_string($valor) || $valor === '') {
                return;
            }

            $dueno = Producto::where('referencia', $valor)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->first(['id', 'nombre']);

            if (! $dueno) {
                return;
            }

            $de = preg_match('/variantes\.(\d+)\./', $atributo, $m)
                ? 'La referencia de la variante '.($m[1] + 1)
                : 'Esa referencia';

            $falla("{$de} ya la usa «{$dueno->nombre}». Déjala vacía para que se genere sola, o escribe otra.");
        };
    }

    private function reglas(string $tipo, ?int $ignoreId = null, bool $esPadre = false): array
    {
        $referenciaRule = ['required', 'string', 'max:60', $this->referenciaLibre($ignoreId)];

        return [
            'nombre'              => 'required|string|max:200',
            'referencia'          => $referenciaRule,
            'unidad_medida'       => 'nullable|string|max:30',
            'categoria_id'        => 'nullable|exists:categorias_producto,id',
            'proveedor_id'        => 'nullable|exists:proveedores,id',
            // La lista de proveedores con precio, para comparar antes de comprar. La
            // columna de arriba queda apuntando al preferido.
            'proveedores_precios'                        => 'nullable|array|max:20',
            'proveedores_precios.*.proveedor_id'         => 'nullable|exists:proveedores,id',
            'proveedores_precios.*.referencia_proveedor' => 'nullable|string|max:80',
            'proveedores_precios.*.precio'               => 'nullable|numeric|min:0',
            'proveedores_precios.*.dias_entrega'         => 'nullable|integer|min:0|max:3650',
            'proveedores_precios.*.minimo_compra'        => 'nullable|numeric|min:0',
            'proveedores_precios.*.es_preferido'         => 'nullable|boolean',
            'proveedores_precios.*.actualizado_el'       => 'nullable|date',
            'proveedores_precios.*.notas'                => 'nullable|string|max:500',
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
            'variantes.*.referencia'       => ['nullable', 'string', 'max:60', 'distinct', $this->referenciaLibre()],
            'variantes.*.imagen'           => 'nullable|image|max:5120',
            'variantes.*.stock_inicial'    => 'nullable|array',
            'variantes.*.stock_inicial.*'  => 'nullable|numeric|min:0',
            'precio_costo'                => 'nullable|numeric|min:0',
            'moneda_costo'                => 'nullable|in:' . implode(',', array_keys(Monedas::CATALOGO)),
            'costo_moneda'                => 'nullable|numeric|min:0',
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

        $this->guardarImagenes($producto, $request->file('imagenes'));
    }

    /** @param array<int, \Illuminate\Http\UploadedFile> $archivos */
    private function guardarImagenes(Producto $producto, array $archivos): void
    {
        $orden = $producto->imagenes()->max('orden') ?? 0;

        foreach ($archivos as $archivo) {
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
