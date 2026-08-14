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
            ->when($request->filled('buscar'), fn ($q) => $q->where('nombre', 'like', "%{$request->buscar}%"))
            ->latest();

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
            'plantillas' => $plantillas,
            'categorias' => $categorias,
            'filters'    => $request->only(['plantilla_id', 'buscar']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Ensambles/Create', [
            'plantillas' => PlantillaEnsamble::with(['campos', 'componentes'])
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
            'tipo_armado', 'plantilla_id', 'categoria_id',
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
            'plantillas' => PlantillaEnsamble::with(['campos', 'componentes'])
                ->where('activo', true)
                ->orderBy('nombre')
                ->get(),
            'categorias' => CategoriaProducto::orderBy('nombre')->get(['id', 'nombre', 'color']),
            // Los precios por canal del original, incluidos los canales que no tenga
            // cargados: se copia lo que hay y lo demás queda listo para llenar.
            'canales'    => app(\App\Services\PreciosPorCanalService::class)->paraFormulario($ensamble),
            'base'       => $base,
            'origen'     => ['id' => $ensamble->id, 'nombre' => $ensamble->nombre],
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
            'nombre'                    => $request->nombre,
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
        $this->guardarImagenes($request, $ensamble);

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

    public function edit(int $id): Response
    {
        $ensamble = Ensamble::with(['plantilla.campos'])->findOrFail($id);

        return Inertia::render('Ensambles/Create', [
            'ensamble'   => $ensamble,
            'plantillas' => PlantillaEnsamble::with(['campos', 'componentes'])
                ->where('activo', true)
                ->orderBy('nombre')
                ->get(),
            'categorias' => CategoriaProducto::orderBy('nombre')->get(['id', 'nombre', 'color']),
            'canales'    => app(\App\Services\PreciosPorCanalService::class)->paraFormulario($ensamble),
        ]);
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
        foreach (['variables', 'lineas', 'canales'] as $campo) {
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
            'categoria_id'         => $request->categoria_id,
            'descripcion_corta'    => $request->descripcion_corta,
            'descripcion_larga'    => $request->descripcion_larga,
            'descripcion_cotizacion' => $request->descripcion_cotizacion,
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
                    ? ceil($totalCosto / (1 - $margen / 100) / 1000) * 1000
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
        $mmay        = (float) ($conf['margen_mayorista']    ?? 30);
        $mdist       = (float) ($conf['margen_distribuidor']  ?? 32.5);
        $mfinal      = (float) ($conf['margen_cliente_final'] ?? 35);

        return response()->json([
            'componentes'          => $componentes,
            'total_costo'          => $totalCosto,
            'precio_mayorista'     => round($totalCosto * (1 + $mmay   / 100), 0),
            'precio_distribuidor'  => round($totalCosto * (1 + $mdist  / 100), 0),
            'precio_cliente_final' => round($totalCosto * (1 + $mfinal / 100), 0),
            'margen_mayorista'     => $mmay,
            'margen_distribuidor'  => $mdist,
            'margen_cliente_final' => $mfinal,
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

        $ensambles = Ensamble::with(['plantilla', 'categoria', 'preciosPorCanal'])
            ->where('nombre', 'like', "%{$q}%")
            ->latest()
            ->limit(15)
            ->get()
            ->map(fn ($e) => [
                'id'                         => $e->id,
                'nombre'                     => $e->nombre,
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
                'precio_costo'               => (float) $e->precio_costo,
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
