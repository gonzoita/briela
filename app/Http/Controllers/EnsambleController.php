<?php

namespace App\Http\Controllers;

use App\Models\CategoriaProducto;
use App\Models\Ensamble;
use App\Models\PlantillaEnsamble;
use App\Services\FormulaEvaluatorService;
use App\Services\ArchivoServidorService;
use App\Services\GoogleDriveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class EnsambleController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Ensamble::with(['plantilla', 'creadoPor', 'categoria'])
            ->when($request->filled('plantilla_id'), fn ($q) => $q->where('plantilla_id', $request->plantilla_id))
            ->when($request->filled('buscar'), fn ($q) => $q->where('nombre', 'like', "%{$request->buscar}%"));

        // El orden lo pide la pantalla. `Orden::aplicar` valida el campo contra esta
        // lista: lo que llegue por `?orden=` y no esté aquí se ignora, así que el
        // parámetro nunca toca el SQL.
        $orden = \App\Support\Orden::aplicar($query, $request, [
            'nombre'       => 'nombre',
            'precio_costo' => 'precio_costo',
            'created_at'   => 'created_at',
        ]);


        $ensambles = $query->paginate(15)->withQueryString();

        $ensambles->through(fn ($e) => [
            ...$e->toArray(),
            'plantilla_nombre'  => $e->plantilla?->nombre,
            'creado_por_nombre' => $e->creadoPor?->name,
            'categoria_nombre'  => $e->categoria?->nombre,
            'categoria_color'   => $e->categoria?->color,
        ]);

        $plantillas = PlantillaEnsamble::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']);
        $categorias = CategoriaProducto::orderBy('nombre')->get(['id', 'nombre', 'color']);

        return Inertia::render('Ensambles/Index', [
            'ensambles'  => $ensambles,
            'orden'      => $orden,
            'plantillas' => $plantillas,
            'categorias' => $categorias,
            'filters'    => $request->only(['plantilla_id', 'buscar']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Ensambles/Create', [
            'plantillas' => PlantillaEnsamble::with(['campos', 'componentes', 'templateTrabajo.pasos'])
                ->where('activo', true)
                ->orderBy('nombre')
                ->get(),
            'categorias' => CategoriaProducto::orderBy('nombre')->get(['id', 'nombre', 'color']),
            // Los canales configurados, para que el ensamble tenga precio por canal igual que
            // un producto. Antes tenía tres cajas fijas que escribían solo las columnas
            // antiguas, invisibles para la cotización.
            'canales'    => app(\App\Services\PreciosPorCanalService::class)->paraFormulario(null),
        ]);
    }

    /**
     * Abre el formulario de creación ya lleno con los datos de otro ensamble.
     *
     * Mismo criterio que en productos: la forma real de crear el segundo ensamble parecido es
     * copiar el primero y cambiar dos cosas, no escribirlo todo otra vez. Copia la receta
     * completa —con plantilla o directa—, las descripciones y los precios por canal. **No**
     * copia las imágenes: se suben contra un ensamble ya guardado, y compartir el archivo
     * haría que borrar la foto de uno la borrara del otro.
     */
    public function duplicar(int $id): Response
    {
        $ensamble = Ensamble::with(['plantilla.campos', 'preciosPorCanal'])->findOrFail($id);

        $base = collect($ensamble->toArray())->only([
            'tipo_armado', 'plantilla_id', 'categoria_id', 'unidad_medida',
            'descripcion_corta', 'descripcion_larga', 'descripcion_cotizacion',
            'variables', 'componentes_resultado', 'precio_costo',
            'margen_aplicado', 'utilidad_minima_empresa_pct',
        ])->all();

        // Los decimales de MySQL llegan como texto ('50000.00'), y la pantalla hace cuentas
        // con ellos: un '0.00' es verdadero en JavaScript, y ahí empiezan los márgenes
        // calculados sobre un costo que la pantalla cree que existe.
        foreach ($base as $campo => $valor) {
            if (is_string($valor) && is_numeric($valor)) {
                $base[$campo] = (float) $valor;
            }
        }

        $base['nombre'] = mb_substr($ensamble->nombre.' (copia)', 0, 150);

        return Inertia::render('Ensambles/Create', [
            'plantillas' => PlantillaEnsamble::with(['campos', 'componentes', 'templateTrabajo.pasos'])
                ->where('activo', true)
                ->orderBy('nombre')
                ->get(),
            'categorias' => CategoriaProducto::orderBy('nombre')->get(['id', 'nombre', 'color']),
            // Los precios por canal del original, incluidos los canales que no tenga
            // cargados: se copia lo que hay y lo demás queda listo para llenar.
            'canales'    => app(\App\Services\PreciosPorCanalService::class)->paraFormulario($ensamble),
            'base'       => $base,
            'origen'     => ['id' => $ensamble->id, 'nombre' => $ensamble->nombre],
            // El flujo de produccion tambien se copia: un ensamble parecido se fabrica igual.
            'pasos_trabajo' => $this->pasosTrabajoDe($ensamble),
            'checks_calidad' => $this->checksCalidadDe($ensamble),
        ]);
    }

    public function store(Request $request, FormulaEvaluatorService $svc): RedirectResponse
    {
        $this->desempacar($request);

        $request->validate([
            // Las imágenes se pueden elegir antes de guardar, igual que en productos: la
            // primera queda como principal y las demás como secundarias.
            'imagenes'                  => 'nullable|array|max:10',
            'imagenes.*'                => 'image|max:5120',
            // Con plantilla se exigen plantilla y variables; directo exige sus líneas. Antes
            // «plantilla_id» era obligatoria siempre, que es lo que impedía armar un ensamble
            // a mano.
            'tipo_armado'               => 'nullable|in:plantilla,directo',
            'plantilla_id'              => 'required_if:tipo_armado,plantilla|nullable|exists:plantillas_ensamble,id',
            'nombre'                    => 'required|string|max:150',
            // La referencia se genera si no la escriben, igual que en productos.
            'referencia'                => 'nullable|string|max:60',
            'unidad_medida'             => 'nullable|string|max:30',
            // Si de este ensamble se guardan unidades armadas en bodega.
            'maneja_stock'              => 'nullable|boolean',
            'variables'                 => 'required_if:tipo_armado,plantilla|nullable|array',
            // Sin `min:1`: la pantalla manda `lineas: []` cuando el ensamble es con
            // plantilla, y `min:1` lo rechazaba aunque las líneas no vinieran al caso.
            // `required_if` ya cubre lo que importa — para Laravel un arreglo vacío es
            // ausente, así que un ensamble directo sin líneas tampoco pasa.
            'lineas'                    => 'required_if:tipo_armado,directo|nullable|array',
            'lineas.*.producto_id'      => 'nullable|exists:productos,id',
            'lineas.*.concepto'         => 'nullable|string|max:150',
            'lineas.*.cantidad'         => 'required_with:lineas|numeric|min:0',
            'lineas.*.precio_unit'      => 'nullable|numeric|min:0',
            'lineas.*.unidad'           => 'nullable|string|max:30',
            // Los precios por canal, igual que en productos.
            'canales'                   => 'nullable|array',
            'canales.*.segmentacion_opcion_id' => 'required_with:canales|integer',
            'canales.*.margen_pct'      => 'nullable|numeric|min:0|max:99',
            'canales.*.precio'          => 'nullable|numeric|min:0',
            'canales.*.comision_min_pct' => 'nullable|numeric|min:0|max:100',
            'canales.*.comision_max_pct' => 'nullable|numeric|min:0|max:100',
            'canales.*.descuento_max_pct' => 'nullable|numeric|min:0|max:100',
            'precio_costo'              => 'nullable|numeric|min:0',
            'precio_mayorista'          => 'nullable|numeric|min:0',
            'precio_distribuidor'       => 'nullable|numeric|min:0',
            'precio_cliente_final'      => 'nullable|numeric|min:0',
            'margen_aplicado'           => 'nullable|numeric|min:0|max:100',
            'categoria_id'              => 'nullable|exists:categorias_producto,id',
            'descripcion_corta'         => 'nullable|string|max:1000',
            'descripcion_larga'         => 'nullable|string|max:60000',
            'descripcion_cotizacion'    => 'nullable|string|max:600',
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
            // El flujo de produccion es obligatorio: un ensamble sin pasos llega a la OP
            // como un trabajo vacio, sin nada que el operario pueda marcar.
            'pasos_trabajo'                     => 'required|array|min:1',
            'pasos_trabajo.*.nombre'            => 'required|string|max:150',
            'pasos_trabajo.*.objetivo'          => 'nullable|string|max:255',
            'pasos_trabajo.*.descripcion'       => 'nullable|string|max:2000',
            'pasos_trabajo.*.peso_porcentaje'   => 'nullable|numeric|min:0|max:100',
            'pasos_trabajo.*.orden'             => 'nullable|integer|min:0',
            'pasos_trabajo.*.nivel_dificultad'  => 'nullable|integer|min:1|max:5',
            'pasos_trabajo.*.depende_de'        => 'nullable|array',
            'pasos_trabajo.*.es_paso_final'     => 'nullable|boolean',
            'pasos_trabajo.*.bodega_destino_id' => 'nullable|exists:bodegas,id',
            'pasos_trabajo.*.imagen'            => 'nullable|string|max:255',
            'pasos_trabajo.*.archivo_plano'     => 'nullable|string|max:255',
            // La revisión de calidad es opcional: no todo lo que se fabrica se revisa punto
            // por punto, y obligarla dejaría sin guardar a quien todavía no la definió.
            'checks_calidad'                    => 'nullable|array',
            'checks_calidad.*.titulo'           => 'required|string|max:150',
            'checks_calidad.*.descripcion'      => 'nullable|string|max:2000',
            'checks_calidad.*.orden'            => 'nullable|integer|min:0',
            'checks_calidad.*.exige_foto'       => 'nullable|boolean',
            'checks_calidad.*.es_critico'       => 'nullable|boolean',
        ]);

        $esDirecto = $request->input('tipo_armado', 'plantilla') === 'directo';

        if ($esDirecto) {
            // Sin plantilla y sin fórmulas: las líneas son la receta. Se guardan con la misma
            // forma que los componentes calculados, y por eso la OP, el consumo de inventario
            // y los PDF no distinguen un ensamble de otro.
            $directo     = app(\App\Services\EnsambleDirectoService::class);
            $componentes = $directo->componentes($request->input('lineas', []));
            $totalCosto  = $directo->costo($componentes);
            $plantillaId = null;
            $variables   = [];
        } else {
            $plantillaId = (int) $request->plantilla_id;
            $variables   = $request->variables;
            $componentes = $svc->calcularPlantilla($plantillaId, $variables);
            $totalCosto  = $svc->totalCosto($componentes);
        }

        $ensamble = Ensamble::create([
            'plantilla_id'              => $plantillaId,
            'tipo_armado'               => $esDirecto ? 'directo' : 'plantilla',
            'maneja_stock'              => $request->boolean('maneja_stock'),
            'nombre'                    => $request->nombre,
            // Si no la escriben, se genera: un ensamble sin código no se puede buscar ni
            // dictar por teléfono, y era la única línea sin referencia en una cotización.
            'referencia'                => $request->filled('referencia')
                ? $request->referencia
                : Ensamble::generarReferencia(),
            'unidad_medida'             => $request->unidad_medida ?: 'unidad',
            'categoria_id'              => $request->categoria_id,
            'descripcion_corta'         => $request->descripcion_corta,
            'descripcion_larga'         => $request->descripcion_larga,
            'descripcion_cotizacion'    => $request->descripcion_cotizacion,
            'variables'                 => $variables,
            'componentes_resultado'     => $componentes,
            'precio_costo'              => $totalCosto,
            'precio_mayorista'          => $request->precio_mayorista     ?? 0,
            'precio_distribuidor'       => $request->precio_distribuidor  ?? 0,
            'precio_cliente_final'      => $request->precio_cliente_final ?? 0,
            'margen_aplicado'           => $request->margen_aplicado      ?? 32.5,
            'utilidad_minima_empresa_pct' => $request->utilidad_minima_empresa_pct ?? 15,
            // Las comisiones y los descuentos por canal NO se escriben aquí: los pone
            // `espejarEnColumnasViejas()` desde las filas por canal, un instante después.
            // Estaban en esta lista leyendo campos que la pantalla dejó de mandar, así que
            // llegaban en null a diez columnas NOT NULL y guardar reventaba con un 500.
            'creado_por'                  => auth()->id(),
        ]);

        $this->guardarCanales($request, $ensamble);
        $this->guardarPasosTrabajo($ensamble, $request->input('pasos_trabajo', []));
        $this->guardarChecksCalidad($ensamble, $request->input('checks_calidad', []));
        $this->guardarImagenes($request, $ensamble);
        // Si se guarda en bodega, nace su producto terminado y con él todo el inventario.
        $ensamble->sincronizarProductoTerminado();

        return redirect("/ensambles/{$ensamble->id}")->with('success', $esDirecto
            ? 'Ensamble creado con su lista de materiales.'
            : 'Ensamble creado correctamente.');
    }

    public function show(int $id): Response
    {
        $ensamble = Ensamble::with(['plantilla.campos', 'creadoPor', 'categoria', 'preciosPorCanal'])->findOrFail($id);

        return Inertia::render('Ensambles/Show', [
            'ensamble' => [
                ...$ensamble->toArray(),
                // Un ensamble directo no tiene plantilla: se dice cómo está armado, en vez
                // de dejar el subtítulo en blanco.
                'plantilla_nombre'  => $ensamble->plantilla?->nombre
                    ?? ($ensamble->esDirecto() ? 'Ensamble directo, sin plantilla' : null),
                'creado_por_nombre' => $ensamble->creadoPor?->name,
                'categoria_nombre'  => $ensamble->categoria?->nombre,
                'categoria_color'   => $ensamble->categoria?->color,
            ],
            // Cuántas unidades alcanzan a armarse con lo que hay en bodega, y qué material
            // es el que primero se agota. Es la respuesta honesta a «¿está disponible?»
            // para algo que se fabrica: un ensamble no vive en un estante.
            'disponibilidad' => $ensamble->unidadesArmables(\App\Support\ContextoSede::idsBodegasVisibles()),
            // Y lo complementario: cuántas hay YA armadas y en qué bodega. Sale del producto
            // terminado, que es donde vive el stock de lo que la empresa fabrica y guarda.
            // Las dos cifras responden preguntas distintas: «cuántas puedo armar hoy» y
            // «cuántas tengo listas para despachar».
            'stock' => $this->stockDelEnsamble($ensamble),
            // Los canales configurados con el precio EFECTIVO de este ensamble en cada uno:
            // lo guardado o, si falta, la columna antigua. La tabla de precios mostraba tres
            // nombres escritos en la pantalla —mayorista, distribuidor, cliente final—, así
            // que en una instalación con canales propios enseñaba nombres que no existen y
            // dejaba por fuera los canales que la empresa creó. Mismo arreglo que en la ficha
            // del producto.
            'canales' => app(\App\Services\CanalesPrecioService::class)->canales()
                ->map(function ($canal) use ($ensamble) {
                    $fila = app(\App\Services\PreciosPorCanalService::class)->filaEfectiva($ensamble, $canal);

                    return [
                        'segmentacion_opcion_id' => $canal->id,
                        'etiqueta'               => $canal->etiqueta,
                        'es_canal_base'          => (bool) $canal->es_canal_base,
                        'es_precio_publico'      => (bool) $canal->es_precio_publico,
                        'precio'                 => $fila['precio'],
                    ];
                })->values(),
            // Para el interruptor de publicación en el sitio web: sin precio público, la
            // ficha del sitio sale sin cifra, y conviene decirlo antes de publicar.
            'web' => [
                'sin_precio' => app(\App\Services\PublicacionWebService::class)->precioParaWeb($ensamble) === null,
            ],
        ]);
    }

    /**
     * El stock del producto terminado de un ensamble, bodega por bodega.
     *
     * Devuelve null si el ensamble no se guarda en bodega: ahí la pregunta no aplica y una
     * tarjeta en cero se leería como un faltante.
     *
     * @return array{total: float, minimo: float, por_bodega: array<int, array<string, mixed>>}|null
     */
    private function stockDelEnsamble(Ensamble $ensamble): ?array
    {
        if (! $ensamble->maneja_stock) {
            return null;
        }

        $producto = $ensamble->productoTerminado;

        if (! $producto) {
            return null;
        }

        $bodegas = \App\Support\ContextoSede::idsBodegasVisibles();

        $filas = $producto->stocks()->with('bodega:id,nombre')
            ->when($bodegas !== [], fn ($q) => $q->whereIn('bodega_id', $bodegas))
            ->get();

        return [
            'producto_id' => $producto->id,
            'referencia'  => $producto->referencia,
            'total'       => (float) $filas->sum('cantidad'),
            'minimo'      => (float) $producto->stock_minimo,
            'por_bodega'  => $filas->map(fn ($f) => [
                'bodega'   => $f->bodega?->nombre ?? '—',
                'cantidad' => (float) $f->cantidad,
            ])->values()->all(),
        ];
    }

    public function edit(int $id): Response
    {
        $ensamble = Ensamble::with(['plantilla.campos'])->findOrFail($id);

        return Inertia::render('Ensambles/Create', [
            'ensamble'   => $ensamble,
            // El flujo de produccion que ya tiene, para poder verlo y cambiarlo aqui mismo.
            'pasos_trabajo' => $this->pasosTrabajoDe($ensamble),
            'checks_calidad' => $this->checksCalidadDe($ensamble),
            'plantillas' => PlantillaEnsamble::with(['campos', 'componentes', 'templateTrabajo.pasos'])
                ->where('activo', true)
                ->orderBy('nombre')
                ->get(),
            'categorias' => CategoriaProducto::orderBy('nombre')->get(['id', 'nombre', 'color']),
            'canales'    => app(\App\Services\PreciosPorCanalService::class)->paraFormulario($ensamble),
        ]);
    }

    /**
     * Los pasos de produccion que le corresponden a un ensamble.
     *
     * Directo: los suyos. Con plantilla: los de la plantilla, que comparten todos los
     * ensambles que la usan. Se leen sin crear nada — abrir una ficha no debe escribir.
     *
     * @return array<int, array<string, mixed>>
     */
    private function pasosTrabajoDe(Ensamble $ensamble): array
    {
        $template = $ensamble->esDirecto()
            ? $ensamble->templateTrabajo
            : $ensamble->plantilla?->templateTrabajo;

        return $template
            ? $template->pasos()->orderBy('orden')->get()->toArray()
            : [];
    }

    /**
     * La lista de revisión de calidad que le corresponde a un ensamble.
     *
     * Directo: la suya. Con plantilla: la de la plantilla, compartida con todos los ensambles
     * que la usan — la misma regla que los pasos de producción.
     *
     * @return array<int, array<string, mixed>>
     */
    private function checksCalidadDe(Ensamble $ensamble): array
    {
        $dueno = $ensamble->esDirecto() ? $ensamble : $ensamble->plantilla;

        return $dueno ? $dueno->checksCalidad()->get()->toArray() : [];
    }

    /**
     * Guarda la lista de revisión de calidad.
     *
     * Borra y reescribe, como los pasos. Lo ya revisado no se toca: cada unidad guarda su copia
     * del punto —título, descripción y si exigía foto— justo para que cambiar la plantilla no
     * reescriba un historial de calidad.
     */
    private function guardarChecksCalidad(Ensamble $ensamble, array $checks): void
    {
        $dueno = $ensamble->esDirecto() ? $ensamble : $ensamble->plantilla;

        if (! $dueno) {
            return;
        }

        $checks = array_values(array_filter($checks, fn ($c) => trim((string) ($c['titulo'] ?? '')) !== ''));

        $dueno->checksCalidad()->delete();

        foreach ($checks as $i => $check) {
            $dueno->checksCalidad()->create([
                'titulo'      => $check['titulo'],
                'descripcion' => $check['descripcion'] ?? null,
                'orden'       => $i,
                'exige_foto'  => filter_var($check['exige_foto'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'es_critico'  => filter_var($check['es_critico'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'activo'      => true,
            ]);
        }
    }

    /**
     * Guarda el flujo de produccion del ensamble.
     *
     * Antes un ensamble se podia guardar sin ninguno. El servidor le inventaba un paso unico
     * la primera vez que una OP lo necesitaba —y solo si era directo—: los de plantilla
     * llegaban a produccion con el trabajo vacio, sin nada que el operario pudiera marcar y
     * con la OP quieta en «confirmada» sin explicacion. Ahora la ficha los exige.
     *
     * Solo escribe si algo cambio. `sincronizarPasos()` borra y recrea las filas, y cada
     * recreacion deja en null el `template_paso_id` de los trabajos que esten en curso: no
     * pierden sus pasos —cada uno guarda su copia— pero si el hilo con la plantilla.
     */
    private function guardarPasosTrabajo(Ensamble $ensamble, array $pasos): void
    {
        $pasos = array_values(array_filter($pasos, fn ($p) => trim((string) ($p['nombre'] ?? '')) !== ''));

        if ($pasos === []) {
            return;
        }

        $template = $ensamble->esDirecto()
            ? $ensamble->obtenerOCrearTemplateTrabajo()
            : $ensamble->plantilla?->obtenerOCrearTemplateTrabajo();

        if (! $template) {
            return;
        }

        $pasos = $this->normalizarPasos($pasos);

        if ($this->mismosPasos($template, $pasos)) {
            return;
        }

        $template->sincronizarPasos($pasos);
    }

    /**
     * Ordena la lista y deja un unico paso final.
     *
     * El paso final es el que entrega la unidad a bodega: `EntregaAlmacenService` descuenta
     * los materiales y registra el producto terminado al cerrarlo. Con dos marcados la
     * entrega se haria dos veces, y con ninguno no se haria nunca.
     *
     * @param  array<int, array<string, mixed>>  $pasos
     * @return array<int, array<string, mixed>>
     */
    private function normalizarPasos(array $pasos): array
    {
        $final = null;

        foreach ($pasos as $i => $paso) {
            if (filter_var($paso['es_paso_final'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $final = $i;
            }
        }

        $final ??= count($pasos) - 1;

        foreach ($pasos as $i => $paso) {
            $pasos[$i]['orden']           = $i;
            $pasos[$i]['peso_porcentaje'] = (float) ($paso['peso_porcentaje'] ?? 0);
            $pasos[$i]['es_paso_final']   = $i === $final;
        }

        return $pasos;
    }

    /**
     * Si la lista que llega dice lo mismo que la guardada.
     *
     * @param  array<int, array<string, mixed>>  $pasos
     */
    private function mismosPasos(\App\Models\TemplateTrabajo $template, array $pasos): bool
    {
        $guardados = $template->pasos()->orderBy('orden')->get();

        if ($guardados->count() !== count($pasos)) {
            return false;
        }

        foreach ($guardados as $i => $g) {
            $p = $pasos[$i];

            $igual = $g->nombre === ($p['nombre'] ?? null)
                && (string) $g->descripcion === (string) ($p['descripcion'] ?? '')
                && (string) $g->objetivo === (string) ($p['objetivo'] ?? '')
                && abs((float) $g->peso_porcentaje - (float) ($p['peso_porcentaje'] ?? 0)) < 0.01
                && (bool) $g->es_paso_final === (bool) ($p['es_paso_final'] ?? false)
                && (int) $g->nivel_dificultad === (int) ($p['nivel_dificultad'] ?? 1)
                && $g->bodega_destino_id == ($p['bodega_destino_id'] ?? null)
                && (string) $g->imagen === (string) ($p['imagen'] ?? '')
                && (string) $g->archivo_plano === (string) ($p['archivo_plano'] ?? '');

            if (! $igual) {
                return false;
            }
        }

        return true;
    }

    /**
     * Guarda los precios por canal, en el formato nuevo o en el viejo.
     *
     * Mismo criterio que en productos: mientras alguna pantalla siga mandando
     * `precio_mayorista` y compañía, esos campos tienen que llegar igual a las filas nuevas —
     * que es de donde lee la cotización.
     */
    private function guardarCanales(Request $request, Ensamble $ensamble): void
    {
        $servicio = app(\App\Services\PreciosPorCanalService::class);
        $filas    = $request->input('canales');

        $servicio->guardar(
            $ensamble,
            is_array($filas) && $filas !== []
                ? $filas
                : $servicio->desdeCamposViejos($request->all())
        );
    }

    /**
     * Deshace el JSON de los campos estructurados cuando el envío trae archivos.
     *
     * Con imágenes adjuntas, Inertia manda el formulario como `multipart/form-data`, y ahí
     * todo viaja como texto: un `true` llega como `'1'` y un número como `'2400'`. Eso
     * importa porque `variables` se guarda tal cual y la pantalla de editar la vuelve a
     * leer — un campo de sí/no con `'0'` quedaría marcado, porque `'0'` es una cadena no
     * vacía y en JavaScript eso es verdadero.
     *
     * Así que la pantalla manda esos tres como JSON y aquí se devuelven a su forma antes de
     * validar. Cuando no hay archivos llegan como arreglos normales y esto no hace nada.
     */
    private function desempacar(Request $request): void
    {
        foreach (['variables', 'lineas', 'canales', 'pasos_trabajo', 'checks_calidad'] as $campo) {
            $valor = $request->input($campo);

            if (is_string($valor)) {
                $request->merge([$campo => json_decode($valor, true) ?? []]);
            }
        }
    }

    /**
     * Guarda las imágenes elegidas al crear el ensamble.
     *
     * La primera queda como principal y el resto como secundarias, que es el orden en que
     * las eligió el usuario. Antes solo se podían subir desde la pantalla de editar, así
     * que había que guardar el ensamble, volver a entrar y subirlas — y el aviso que lo
     * explicaba era lo único que había.
     */
    private function guardarImagenes(Request $request, Ensamble $ensamble): void
    {
        $archivos = $request->file('imagenes');

        if (! $archivos) {
            return;
        }

        $secundarias = [];

        foreach (array_values($archivos) as $i => $archivo) {
            $subida = ArchivoServidorService::subir($archivo, 'ensambles');

            // Ruta relativa, no URL completa: las vistas arman el src como
            // `/storage/{ruta}`, y una URL con dominio quedaría apuntando al sitio
            // anterior el día que el sistema se monte en otro dominio.
            if ($i === 0) {
                $ensamble->imagen_principal          = $subida['ruta'];
                $ensamble->imagen_principal_drive_id = $subida['id'];
            } else {
                $secundarias[] = $subida['ruta'];
            }
        }

        $ensamble->imagenes_secundarias = $secundarias;
        $ensamble->save();
    }

    public function update(Request $request, int $id, FormulaEvaluatorService $svc): RedirectResponse
    {
        $ensamble = Ensamble::findOrFail($id);

        $this->desempacar($request);

        $request->validate([
            'nombre'                    => 'required|string|max:150',
            'referencia'                => 'nullable|string|max:60',
            'unidad_medida'             => 'nullable|string|max:30',
            // Un ensamble directo no tiene variables: tiene líneas.
            'variables'                 => 'nullable|array',
            'lineas'                    => 'nullable|array',
            'lineas.*.producto_id'      => 'nullable|exists:productos,id',
            'lineas.*.concepto'         => 'nullable|string|max:150',
            'lineas.*.cantidad'         => 'required_with:lineas|numeric|min:0',
            'lineas.*.precio_unit'      => 'nullable|numeric|min:0',
            'lineas.*.unidad'           => 'nullable|string|max:30',
            'canales'                   => 'nullable|array',
            'canales.*.segmentacion_opcion_id' => 'required_with:canales|integer',
            'canales.*.margen_pct'      => 'nullable|numeric|min:0|max:99',
            'canales.*.precio'          => 'nullable|numeric|min:0',
            'canales.*.comision_min_pct' => 'nullable|numeric|min:0|max:100',
            'canales.*.comision_max_pct' => 'nullable|numeric|min:0|max:100',
            'canales.*.descuento_max_pct' => 'nullable|numeric|min:0|max:100',
            'precio_costo'              => 'nullable|numeric|min:0',
            'precio_mayorista'          => 'nullable|numeric|min:0',
            'precio_distribuidor'       => 'nullable|numeric|min:0',
            'precio_cliente_final'      => 'nullable|numeric|min:0',
            'margen_aplicado'           => 'nullable|numeric|min:0|max:100',
            'categoria_id'              => 'nullable|exists:categorias_producto,id',
            'descripcion_corta'         => 'nullable|string|max:1000',
            'descripcion_larga'         => 'nullable|string|max:60000',
            'descripcion_cotizacion'    => 'nullable|string|max:600',
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
            // El flujo de produccion es obligatorio: un ensamble sin pasos llega a la OP
            // como un trabajo vacio, sin nada que el operario pueda marcar.
            'pasos_trabajo'                     => 'required|array|min:1',
            'pasos_trabajo.*.nombre'            => 'required|string|max:150',
            'pasos_trabajo.*.objetivo'          => 'nullable|string|max:255',
            'pasos_trabajo.*.descripcion'       => 'nullable|string|max:2000',
            'pasos_trabajo.*.peso_porcentaje'   => 'nullable|numeric|min:0|max:100',
            'pasos_trabajo.*.orden'             => 'nullable|integer|min:0',
            'pasos_trabajo.*.nivel_dificultad'  => 'nullable|integer|min:1|max:5',
            'pasos_trabajo.*.depende_de'        => 'nullable|array',
            'pasos_trabajo.*.es_paso_final'     => 'nullable|boolean',
            'pasos_trabajo.*.bodega_destino_id' => 'nullable|exists:bodegas,id',
            'pasos_trabajo.*.imagen'            => 'nullable|string|max:255',
            'pasos_trabajo.*.archivo_plano'     => 'nullable|string|max:255',
            // La revisión de calidad es opcional: no todo lo que se fabrica se revisa punto
            // por punto, y obligarla dejaría sin guardar a quien todavía no la definió.
            'checks_calidad'                    => 'nullable|array',
            'checks_calidad.*.titulo'           => 'required|string|max:150',
            'checks_calidad.*.descripcion'      => 'nullable|string|max:2000',
            'checks_calidad.*.orden'            => 'nullable|integer|min:0',
            'checks_calidad.*.exige_foto'       => 'nullable|boolean',
            'checks_calidad.*.es_critico'       => 'nullable|boolean',
        ]);

        if ($ensamble->esDirecto()) {
            $directo     = app(\App\Services\EnsambleDirectoService::class);
            $componentes = $directo->componentes($request->input('lineas', []));
            $totalCosto  = $directo->costo($componentes);
            $variables   = [];
        } else {
            $variables   = $request->input('variables', []);
            $componentes = $svc->calcularPlantilla($ensamble->plantilla_id, $variables);
            $totalCosto  = $svc->totalCosto($componentes);
        }

        $ensamble->update([
            'nombre'               => $request->nombre,
            'referencia'           => $request->filled('referencia')
                ? $request->referencia
                : ($ensamble->referencia ?: Ensamble::generarReferencia()),
            'unidad_medida'        => $request->unidad_medida ?: ($ensamble->unidad_medida ?: 'unidad'),
            'categoria_id'         => $request->categoria_id,
            'descripcion_corta'    => $request->descripcion_corta,
            'descripcion_larga'    => $request->descripcion_larga,
            'descripcion_cotizacion' => $request->descripcion_cotizacion,
            'maneja_stock'         => $request->boolean('maneja_stock'),
            'variables'            => $variables,
            'componentes_resultado'=> $componentes,
            'precio_costo'         => $totalCosto,
            'precio_mayorista'     => $request->precio_mayorista     ?? $ensamble->precio_mayorista,
            'precio_distribuidor'  => $request->precio_distribuidor  ?? $ensamble->precio_distribuidor,
            'precio_cliente_final' => $request->precio_cliente_final ?? $ensamble->precio_cliente_final,
            'margen_aplicado'      => $request->margen_aplicado      ?? $ensamble->margen_aplicado,
            'comision_pct_minima'         => $request->comision_pct_minima         ?? $ensamble->comision_pct_minima,
            'comision_pct_maxima'         => $request->comision_pct_maxima         ?? $ensamble->comision_pct_maxima,
            'comision_min_distribuidor'   => $request->comision_min_distribuidor   ?? $ensamble->comision_min_distribuidor,
            'comision_max_distribuidor'   => $request->comision_max_distribuidor   ?? $ensamble->comision_max_distribuidor,
            'comision_min_cliente_final'  => $request->comision_min_cliente_final  ?? $ensamble->comision_min_cliente_final,
            'comision_max_cliente_final'  => $request->comision_max_cliente_final  ?? $ensamble->comision_max_cliente_final,
            'utilidad_minima_empresa_pct' => $request->utilidad_minima_empresa_pct ?? $ensamble->utilidad_minima_empresa_pct,
            'descuento_max_cliente_final' => $request->descuento_max_cliente_final ?? $ensamble->descuento_max_cliente_final,
            'descuento_max_distribuidor'  => $request->descuento_max_distribuidor  ?? $ensamble->descuento_max_distribuidor,
            'descuento_max_mayorista'     => $request->descuento_max_mayorista     ?? $ensamble->descuento_max_mayorista,
        ]);

        $this->guardarCanales($request, $ensamble);
        $this->guardarPasosTrabajo($ensamble, $request->input('pasos_trabajo', []));
        $this->guardarChecksCalidad($ensamble, $request->input('checks_calidad', []));
        $ensamble->sincronizarProductoTerminado();

        return redirect("/ensambles/{$ensamble->id}")->with('success', 'Ensamble actualizado.');
    }

    public function destroy(int $id): RedirectResponse
    {
        Ensamble::findOrFail($id)->delete();

        return redirect('/ensambles')->with('success', 'Ensamble eliminado.');
    }

    public function recalcular(int $id, FormulaEvaluatorService $svc): RedirectResponse
    {
        $ensamble = Ensamble::with('plantilla')->findOrFail($id);

        if ($ensamble->esDirecto()) {
            // Un ensamble directo no tiene fórmulas que volver a correr: lo que se releen son
            // los costos de sus productos. Los conceptos libres se quedan como están, porque
            // no hay de dónde releerlos. Sin esta rama, `calcularPlantilla(null, …)` se
            // llevaba la receta entera.
            $directo     = app(\App\Services\EnsambleDirectoService::class);
            $componentes = $directo->recalcular((array) $ensamble->componentes_resultado);
            $totalCosto  = $directo->costo($componentes);
        } else {
            $componentes = $svc->calcularPlantilla($ensamble->plantilla_id, $ensamble->variables);
            $totalCosto  = $svc->totalCosto($componentes);
        }

        $ensamble->update([
            'componentes_resultado' => $componentes,
            'precio_costo'          => $totalCosto,
        ]);

        // Los precios se rearman desde el margen guardado de cada canal, que es de donde lee
        // la cotización. Antes se escribían solo las tres columnas antiguas —y con
        // `costo * (1 + margen)`, que no es el margen sobre la venta sino un recargo sobre el
        // costo: con 35% daba 13.500 donde correspondían 15.000.
        $precios = app(\App\Services\PreciosPorCanalService::class);
        $filas   = collect($precios->paraFormulario($ensamble))
            ->map(function (array $fila) use ($totalCosto) {
                $margen = (float) $fila['margen_pct'];

                $fila['precio'] = $margen > 0 && $margen < 100
                    ? app(\App\Services\PreciosPorCanalService::class)->precioDesdeCosto($totalCosto, $margen)
                    : $fila['precio'];

                return $fila;
            })
            ->all();

        $precios->guardar($ensamble, $filas);

        return back()->with('success', 'Ensamble recalculado con precios actualizados.');
    }

    // ── Imágenes ──────────────────────────────────────────────────────────────────

    public function subirImagenPrincipal(Request $request, Ensamble $ensamble): JsonResponse
    {
        $request->validate(['imagen' => 'required|image|max:5120']);

        if ($ensamble->imagen_principal_drive_id) {
            GoogleDriveService::delete($ensamble->imagen_principal_drive_id);
        } else {
            ArchivoServidorService::borrar($ensamble->imagen_principal);
        }

        $resultado = ArchivoServidorService::subir($request->file('imagen'), 'ensambles');
        $ensamble->update([
            // Se guarda la RUTA relativa, no la URL completa: las vistas de
            // ensambles arman el src como `/storage/${ruta}`, y además una URL
            // con dominio quedaría apuntando al sitio anterior si el sistema
            // se monta en otro dominio.
            'imagen_principal'          => $resultado['ruta'],
            'imagen_principal_drive_id' => $resultado['id'],
        ]);

        return response()->json(['ruta' => $resultado['ruta'], 'url' => $resultado['url']]);
    }

    public function eliminarImagenPrincipal(Ensamble $ensamble): JsonResponse
    {
        if ($ensamble->imagen_principal_drive_id) {
            GoogleDriveService::delete($ensamble->imagen_principal_drive_id);
        } else {
            ArchivoServidorService::borrar($ensamble->imagen_principal);
        }

        $ensamble->update([
            'imagen_principal'          => null,
            'imagen_principal_drive_id' => null,
        ]);

        return response()->json(['ok' => true]);
    }

    public function agregarImagenSecundaria(Request $request, Ensamble $ensamble): JsonResponse
    {
        $request->validate(['imagen' => 'required|image|max:5120']);

        $resultado = ArchivoServidorService::subir($request->file('imagen'), 'ensambles');
        $imagenes  = $ensamble->imagenes_secundarias ?? [];
        // Ruta relativa, igual que la imagen principal (ver comentario allá).
        $imagenes[] = $resultado['ruta'];
        $ensamble->update(['imagenes_secundarias' => $imagenes]);

        return response()->json([
            'ruta'     => $resultado['ruta'],
            'url'      => $resultado['url'],
            'imagenes' => $imagenes,
        ]);
    }

    public function eliminarImagenSecundaria(Request $request, Ensamble $ensamble): JsonResponse
    {
        $request->validate(['ruta' => 'required|string']);
        $ruta = $request->ruta;

        $driveId = GoogleDriveService::extraerFileId($ruta);
        if ($driveId) {
            GoogleDriveService::delete($driveId);
        } else {
            ArchivoServidorService::borrar($ruta);
        }

        $imagenes = collect($ensamble->imagenes_secundarias ?? [])
            ->filter(fn ($r) => $r !== $ruta)
            ->values()
            ->toArray();
        $ensamble->update(['imagenes_secundarias' => $imagenes]);

        return response()->json(['ok' => true]);
    }

    // ── API para cotizador ────────────────────────────────────────────────────────

    public function calcular(Request $request, FormulaEvaluatorService $svc): JsonResponse
    {
        $data = $request->validate([
            'plantilla_id' => 'required|exists:plantillas_ensamble,id',
            'variables'    => 'required|array',
        ]);

        $plantilla   = PlantillaEnsamble::findOrFail((int) $data['plantilla_id']);
        $componentes = $svc->calcularPlantilla((int) $data['plantilla_id'], $data['variables']);
        $totalCosto  = $svc->totalCosto($componentes);
        $conf        = $plantilla->config_salida ?? [];

        // Los márgenes son los que la empresa puso en Segmentación, no tres números escritos
        // aquí. Y el precio se saca con la misma cuenta que el resto del sistema: este método
        // usaba `costo * (1 + margen)`, que es un recargo sobre el costo y no un margen sobre
        // la venta — con 32,5 % daba un precio distinto al que mostraba la ficha del mismo
        // ensamble.
        $precios = app(\App\Services\PreciosPorCanalService::class);
        $canales = app(\App\Services\CanalesPrecioService::class)->canales();
        $base    = $canales->firstWhere('es_canal_base', true);
        $publico = $canales->firstWhere('es_precio_publico', true);
        // El del medio es el primero que no es ninguno de los dos, en el orden que puso la
        // empresa: la misma regla de `PreciosPorCanalService::columnaDe()`.
        $medio   = $canales->reject(fn ($c) => $c->es_canal_base || $c->es_precio_publico)->first() ?? $base;

        $margenDe = fn ($canal) => $canal ? (float) $canal->margen_sugerido : 0.0;

        return response()->json([
            'componentes'          => $componentes,
            // El costo solo viaja si quien pregunta puede verlo. Esconderlo en la pantalla
            // y mandarlo igual lo deja a la vista en el código fuente de la página.
            'total_costo'          => auth()->user()?->tienePermiso('costos.ver') ? $totalCosto : null,
            'precio_mayorista'     => $precios->precioDesdeCosto($totalCosto, $margenDe($base)),
            'precio_distribuidor'  => $precios->precioDesdeCosto($totalCosto, $margenDe($medio)),
            'precio_cliente_final' => $precios->precioDesdeCosto($totalCosto, $margenDe($publico)),
            'margen_mayorista'     => $margenDe($base),
            'margen_distribuidor'  => $margenDe($medio),
            'margen_cliente_final' => $margenDe($publico),
            'precio_por_defecto'   => $conf['precio_defecto_cotizar'] ?? 'distribuidor',
        ]);
    }

    public function variablesInstancia(Ensamble $ensamble): JsonResponse
    {
        $campos = $ensamble->plantilla?->campos()
            ->where('tipo_campo', 'variable_instancia')
            ->orderBy('orden')
            ->get(['id', 'nombre', 'etiqueta', 'subtipo_variable', 'opciones_selector', 'valor_defecto', 'ayuda', 'imagen_referencia', 'imagen_referencia_titulo'])
            ?? collect();

        $imagenesReferencia = $ensamble->plantilla?->campos()
            ->whereNotNull('imagen_referencia')
            ->orderBy('orden')
            ->get(['id', 'nombre', 'etiqueta', 'imagen_referencia', 'imagen_referencia_titulo'])
            ?? collect();

        return response()->json([
            'campos'              => $campos,
            'imagenes_referencia' => $imagenesReferencia,
        ]);
    }

    public function buscar(Request $request): JsonResponse
    {
        $q = $request->get('q', '');

        $puedeVerCosto = (bool) auth()->user()?->tienePermiso('costos.ver');

        $ensambles = Ensamble::with(['plantilla', 'categoria', 'preciosPorCanal'])
            ->where('nombre', 'like', "%{$q}%")
            ->latest()
            ->limit(15)
            ->get()
            ->map(fn ($e) => [
                'id'                         => $e->id,
                'nombre'                     => $e->nombre,
                'referencia'                 => $e->referencia,
                'unidad_medida'              => $e->unidad_medida,
                'plantilla_nombre'           => $e->plantilla?->nombre,
                'categoria_nombre'           => $e->categoria?->nombre,
                'imagen_principal'           => $e->imagen_principal
                    ? (str_starts_with($e->imagen_principal, 'http')
                        ? $e->imagen_principal
                        : asset('storage/' . $e->imagen_principal))
                    : null,
                'descripcion_corta'          => $e->descripcion_corta,
                // Mismo criterio que en productos: al cotizar va el texto técnico corto.
                'descripcion_larga'          => $e->descripcion_cotizacion ?: $e->descripcion_corta,
                // Igual que en `calcular()`: sin permiso, el costo no sale de aquí.
                'precio_costo'               => $puedeVerCosto ? (float) $e->precio_costo : null,
                'precio_mayorista'           => (float) $e->precio_mayorista,
                'precio_distribuidor'        => (float) $e->precio_distribuidor,
                'precio_cliente_final'       => (float) $e->precio_cliente_final,
                // Los canales configurados. Las tres claves de arriba quedan mientras haya
                // pantallas que las lean; el cotizador ya usa esto.
                'canales' => $e->preciosPorCanal->map(fn ($c) => [
                    'segmentacion_opcion_id' => $c->segmentacion_opcion_id,
                    'precio'                 => (float) $c->precio,
                    'comision_min_pct'       => (float) $c->comision_min_pct,
                    'comision_max_pct'       => (float) $c->comision_max_pct,
                    'descuento_max_pct'      => (float) $c->descuento_max_pct,
                ])->values(),
                'variables'                  => $e->variables,
                'componentes_resultado'      => $e->componentes_resultado,
                'comision_pct_minima'         => (float) ($e->comision_pct_minima ?? 0),
                'comision_pct_maxima'         => (float) ($e->comision_pct_maxima ?? 0),
                'comision_min_distribuidor'   => (float) ($e->comision_min_distribuidor ?? 0),
                'comision_max_distribuidor'   => (float) ($e->comision_max_distribuidor ?? 0),
                'comision_min_cliente_final'  => (float) ($e->comision_min_cliente_final ?? 0),
                'comision_max_cliente_final'  => (float) ($e->comision_max_cliente_final ?? 0),
                'descuento_max_cliente_final' => (float) ($e->descuento_max_cliente_final ?? 0),
                'descuento_max_distribuidor' => (float) ($e->descuento_max_distribuidor ?? 0),
                'descuento_max_mayorista'    => (float) ($e->descuento_max_mayorista ?? 0),
            ]);

        return response()->json($ensambles);
    }
}
