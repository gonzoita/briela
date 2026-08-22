<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\ComisionVendedor;
use App\Models\ConfiguracionPuerta;
use App\Models\Cotizacion;
use App\Models\CotizacionItem;
use App\Models\Ensamble;
use App\Models\PlantillaEnsamble;
use App\Models\Producto;
use App\Models\User;
use App\Rules\ProductoSeleccionable;
use App\Services\FormulaEvaluatorService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use App\Services\PreciosPorCanalService;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use App\Support\Marca;

class CotizacionController extends Controller
{
    public function index(Request $request): Response
    {
        $query = \App\Support\ContextoSede::aplicar(Cotizacion::query())
            ->with(['cliente', 'responsable', 'seguimientos', 'sede:id,nombre'])
            ->withCount('items')
            ->when($request->filled('estado'), function ($q) use ($request) {
                if ($request->estado === 'en_produccion') {
                    $q->where('en_produccion', true);
                } else {
                    $q->where('estado', $request->estado)->where('en_produccion', false);
                }
            })
            ->when($request->filled('responsable_id'), fn ($q) => $q->where('responsable_id', $request->responsable_id))
            ->when($request->filled('buscar'), fn ($q) => $q->where(function ($q) use ($request) {
                $q->where('numero', 'like', "%{$request->buscar}%")
                  ->orWhereHas('cliente', fn ($q2) => $q2->where('nombre', 'like', "%{$request->buscar}%"));
            }))
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('fecha_creacion', '>=', $request->desde))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('fecha_creacion', '<=', $request->hasta))
;

        // El orden lo pide la pantalla. `Orden::aplicar` valida el campo contra esta
        // lista: lo que llegue por `?orden=` y no esté aquí se ignora, así que el
        // parámetro nunca toca el SQL.
        $orden = \App\Support\Orden::aplicar($query, $request, [
            'numero'          => 'numero',
            'fecha_creacion'  => 'fecha_creacion',
            'total'           => 'total',
            'estado'          => 'estado',
            'created_at'      => 'created_at',
        ]);

        $cotizaciones = $query->paginate(15)->withQueryString();

        $cotizaciones->getCollection()->transform(fn ($c) => [
            ...$c->toArray(),
            'en_produccion'      => (bool) $c->en_produccion,
            'dias_sin_respuesta' => $c->diasSinRespuesta(),
            'estado_badge'       => $c->en_produccion
                ? ['label' => 'En Producción', 'bg' => '#DBEAFE', 'text' => Marca::color()]
                : $c->estadoBadge(),
        ]);

        $UMBRAL_DIAS_SEGUIMIENTO = 5;

        // Se listan las cotizaciones específicas que necesitan seguimiento
        // (no solo el conteo) para poder enlazarlas directo desde el aviso.
        $cotizacionesSeguimiento = Cotizacion::where('estado', 'enviada')
            ->with('seguimientos')
            ->get()
            ->map(fn ($c) => ['id' => $c->id, 'numero' => $c->numero, 'dias' => $c->diasSinRespuesta()])
            ->filter(fn ($c) => $c['dias'] !== null && $c['dias'] >= $UMBRAL_DIAS_SEGUIMIENTO)
            ->sortByDesc('dias')
            ->values();

        $metricas = [
            'borradores'                => Cotizacion::where('estado', 'borrador')->count(),
            'enviadas'                  => Cotizacion::where('estado', 'enviada')->count(),
            'necesitan_seguimiento'     => $cotizacionesSeguimiento->count(),
            'cotizaciones_seguimiento'  => $cotizacionesSeguimiento,
            'aprobadas'  => Cotizacion::where('estado', 'aprobada')->count(),
            'total_mes'  => Cotizacion::where('estado', 'aprobada')
                ->whereMonth('updated_at', now()->month)
                ->whereYear('updated_at', now()->year)
                ->sum('total'),
            'por_mes'    => collect(range(5, 0))->map(function ($i) {
                $d = now()->subMonths($i);
                return [
                    'mes'   => $d->locale('es')->isoFormat('MMM'),
                    'total' => (float) Cotizacion::where('estado', 'aprobada')
                        ->whereMonth('updated_at', $d->month)
                        ->whereYear('updated_at', $d->year)
                        ->sum('total'),
                ];
            })->values(),
        ];

        return Inertia::render('Cotizaciones/Index', [
            'orden'      => $orden,
            'cotizaciones' => $cotizaciones,
            'filters'      => $request->only(['estado', 'responsable_id', 'buscar', 'desde', 'hasta']),
            'responsables' => User::whereIn('rol', ['administrador', 'jefe_produccion', 'vendedor'])
                ->where('activo', true)->get(['id', 'name']),
            'metricas'     => $metricas,
        ]);
    }

    public function create(Request $request): Response
    {
        $lead = null;
        if ($request->filled('lead_id')) {
            $lead = \App\Models\CrmLead::with('cliente.contactos')->find($request->integer('lead_id'));
        }

        return Inertia::render('Cotizaciones/Create', [
            'responsables'        => User::whereIn('rol', ['administrador', 'jefe_produccion', 'vendedor'])
                ->where('activo', true)->get(['id', 'name']),
            'usuario_actual'      => auth()->id(),
            'condiciones_default' => self::condicionesGenerales(),
            'plantillas'          => PlantillaEnsamble::with('campos')
                ->where('activo', true)->orderBy('nombre')->get(),
            // Los canales de precio, en orden de prioridad. La pantalla ya no compara
            // nombres de canal a mano: pregunta cuál de estos tiene el cliente.
            'canales'             => app(\App\Services\CanalesPrecioService::class)->canales()
                ->map(fn ($c) => [
                    'id'                => $c->id,
                    'valor'             => $c->valor,
                    'etiqueta'          => $c->etiqueta,
                    'color'             => $c->color,
                    'es_canal_base'     => (bool) $c->es_canal_base,
                    'es_precio_publico' => (bool) $c->es_precio_publico,
                ])->values(),
            // Las listas de segmentación, para el modal que crea un cliente sin salir de
            // aquí. Van con `define_precio` porque el modal advierte si el tipo elegido no
            // define precio: sin eso, la cotización no mostraría precios y nadie sabría
            // por qué.
            'segmentacion_opciones' => \App\Models\SegmentacionOpcion::where('activo', true)
                ->orderBy('tipo')->orderBy('orden')
                ->get(['id', 'tipo', 'valor', 'etiqueta', 'color', 'define_precio'])
                ->groupBy('tipo'),
            'lead_preseleccionado' => $lead ? [
                'id'      => $lead->id,
                'titulo'  => $lead->titulo,
                'cliente' => $lead->cliente,
            ] : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validarForm($request);

        $cot = Cotizacion::create([
            'lead_id'                  => $data['lead_id'] ?? null,
            'cliente_id'               => $data['cliente_id'],
            'contacto_id'              => $data['contacto_id'],
            'nombre_contacto_override' => $data['nombre_contacto_override'],
            'moneda'                   => $data['moneda'],
            'tasa_cambio'              => $data['tasa_cambio'],
            'fecha_creacion'           => $data['fecha_creacion'],
            'fecha_validez'            => $data['fecha_validez'],
            'responsable_id'           => $data['responsable_id'],
            'estado'                   => 'borrador',
            'condiciones_comerciales'  => $data['condiciones_comerciales'],
            'notas_internas'           => $data['notas_internas'],
        ]);

        $this->syncItems($cot, $data['items'] ?? []);
        $cot->load('items');
        $cot->recalcularTotales();
        $this->sincronizarComision($cot);

        return redirect("/cotizaciones/{$cot->id}")
            ->with('success', "Cotización {$cot->numero} creada.");
    }

    public function show(Cotizacion $cotizacion): Response
    {
        $cotizacion->load([
            'cliente.contactos', 'contacto', 'responsable', 'lead:id,titulo', 'comision',
            'seguimientos.user:id,name',
            'items.producto', 'items.configuracionPuerta', 'items.ensamble.plantilla',
        ]);

        return Inertia::render('Cotizaciones/Show', [
            'cotizacion'   => [
                ...$cotizacion->toArray(),
                'estado_badge'       => $cotizacion->estadoBadge(),
                'dias_sin_respuesta' => $cotizacion->diasSinRespuesta(),
            ],
            'responsables' => User::whereIn('rol', ['administrador', 'jefe_produccion', 'vendedor'])
                ->where('activo', true)->get(['id', 'name']),
            // Para elegir en qué fábrica se produce al generar la OP.
            'sedesFabrica' => \App\Models\Sede::activas()
                ->conProduccion()
                ->orderByDesc('es_principal')
                ->get(['id', 'nombre']),
        ]);
    }

    public function agregarSeguimiento(Request $request, Cotizacion $cotizacion): RedirectResponse
    {
        $data = $request->validate(['nota' => 'required|string|max:1000']);

        $cotizacion->seguimientos()->create([
            'user_id' => auth()->id(),
            'nota'    => $data['nota'],
        ]);

        return back()->with('success', 'Seguimiento registrado.');
    }

    public function edit(Cotizacion $cotizacion): Response
    {
        $cotizacion->load(['cliente.contactos', 'contacto', 'items.producto', 'items.configuracionPuerta', 'items.ensamble']);

        return Inertia::render('Cotizaciones/Create', [
            // Los ítems van con el stock de HOY, no con el que había cuando se cotizó: una
            // cotización se reabre días después, y lo que importa entonces es si todavía
            // hay con qué cumplirla. Los ítems no guardan stock — es una ayuda de pantalla,
            // y el inventario de verdad se comprueba al despachar.
            'cotizacion'          => $this->conStockActual($cotizacion),
            'responsables'        => User::whereIn('rol', ['administrador', 'jefe_produccion', 'vendedor'])
                ->where('activo', true)->get(['id', 'name']),
            'usuario_actual'      => auth()->id(),
            'condiciones_default' => self::condicionesGenerales(),
            'plantillas'          => PlantillaEnsamble::with('campos')
                ->where('activo', true)->orderBy('nombre')->get(),
            // Los canales de precio, en orden de prioridad. La pantalla ya no compara
            // nombres de canal a mano: pregunta cuál de estos tiene el cliente.
            'canales'             => app(\App\Services\CanalesPrecioService::class)->canales()
                ->map(fn ($c) => [
                    'id'                => $c->id,
                    'valor'             => $c->valor,
                    'etiqueta'          => $c->etiqueta,
                    'color'             => $c->color,
                    'es_canal_base'     => (bool) $c->es_canal_base,
                    'es_precio_publico' => (bool) $c->es_precio_publico,
                ])->values(),
            // Las listas de segmentación, para el modal que crea un cliente sin salir de
            // aquí. Van con `define_precio` porque el modal advierte si el tipo elegido no
            // define precio: sin eso, la cotización no mostraría precios y nadie sabría
            // por qué.
            'segmentacion_opciones' => \App\Models\SegmentacionOpcion::where('activo', true)
                ->orderBy('tipo')->orderBy('orden')
                ->get(['id', 'tipo', 'valor', 'etiqueta', 'color', 'define_precio'])
                ->groupBy('tipo'),
        ]);
    }

    /**
     * Agrega a cada ítem el stock actual de su producto, para la etiqueta de disponibles.
     *
     * Se hace con una sola consulta para todos los productos del documento: preguntarle el
     * stock a cada ítem por separado son veinte consultas en una cotización de veinte
     * líneas, y esta pantalla ya carga bastante.
     */
    private function conStockActual(Cotizacion $cotizacion): array
    {
        $datos = $cotizacion->toArray();

        $ids = collect($cotizacion->items)->pluck('producto_id')->filter()->unique();

        $productos = $ids->isEmpty()
            ? collect()
            : \App\Models\Producto::with('preciosPorCanal')->whereIn('id', $ids)
                ->get(['id', 'nombre', 'stock_minimo', 'inventariable', 'es_padre'])
                ->keyBy('id');

        // Los ensambles también: sus comisiones y descuentos viven en las filas por canal, y
        // la pantalla los necesita para poder negociar la comisión al reabrir la cotización.
        $ensambles = collect($cotizacion->items)->pluck('ensamble_id')->filter()->unique();

        $ensambles = $ensambles->isEmpty()
            ? collect()
            : \App\Models\Ensamble::with('preciosPorCanal')->whereIn('id', $ensambles)
                ->get(['id', 'nombre'])->keyBy('id');

        // Mismo criterio que el buscador y el inventario: solo las bodegas de esta sede.
        $bodegas = \App\Support\ContextoSede::idsBodegasVisibles();

        $datos['items'] = collect($datos['items'])->map(function (array $item) use ($productos, $ensambles, $bodegas) {
            $producto = $item['producto_id'] ? $productos->get($item['producto_id']) : null;

            // Las filas por canal del producto o del ensamble, con la MISMA forma que manda
            // el buscador al agregar el ítem. Sin esto, al reabrir la cotización la pantalla
            // leía las comisiones de las columnas antiguas —que quedaron en cero al pasar a
            // canales—, el rango salía de 0 a 0 y la barra de negociar no se podía mover.
            $fuente = $producto ?? ($item['ensamble_id'] ? $ensambles->get($item['ensamble_id']) : null);

            $item['canales'] = $fuente
                ? $fuente->preciosPorCanal->map(fn ($c) => [
                    'segmentacion_opcion_id' => $c->segmentacion_opcion_id,
                    'precio'                 => (float) $c->precio,
                    'comision_min_pct'       => (float) $c->comision_min_pct,
                    'comision_max_pct'       => (float) $c->comision_max_pct,
                    'descuento_max_pct'      => (float) $c->descuento_max_pct,
                ])->values()->all()
                : [];

            // Va el dato crudo, sin decidir aquí si se muestra: esa regla vive en
            // `EtiquetaStock.vue`, y tenerla en dos sitios es tenerla en ninguno.
            $item['stock_disponible'] = $producto ? $producto->stockEnBodegas($bodegas) : null;
            $item['stock_minimo']     = (float) ($producto->stock_minimo ?? 0);
            $item['inventariable']    = (bool) ($producto->inventariable ?? false);

            // Si este ítem ya no se puede guardar, se dice AQUÍ y no en un aviso al final de
            // la pantalla. Pasa sin que nadie haga nada raro: se cotiza un producto simple y
            // meses después alguien le agrega variantes, o lo borran. El aviso general no
            // decía en qué línea, así que se revisaba lo último que se había agregado.
            $item['problema'] = match (true) {
                ! $item['producto_id'] => null,
                ! $producto            => 'Este producto ya no existe. Bórralo de la lista y agrégalo de nuevo.',
                (bool) $producto->es_padre => 'Este producto pasó a tener variantes, y lo que se vende es una '
                    .'variante concreta. Bórralo y elige la que va.',
                default => null,
            };

            return $item;
        })->all();

        return $datos;
    }

    public function update(Request $request, Cotizacion $cotizacion): RedirectResponse
    {
        abort_if($cotizacion->en_produccion, 403, 'Esta cotización está en producción y no puede modificarse.');

        $data = $this->validarForm($request);

        $cotizacion->update([
            'cliente_id'               => $data['cliente_id'],
            'contacto_id'              => $data['contacto_id'],
            'nombre_contacto_override' => $data['nombre_contacto_override'],
            'moneda'                   => $data['moneda'],
            'tasa_cambio'              => $data['tasa_cambio'],
            'fecha_creacion'           => $data['fecha_creacion'],
            'fecha_validez'            => $data['fecha_validez'],
            'responsable_id'           => $data['responsable_id'],
            'condiciones_comerciales'  => $data['condiciones_comerciales'],
            'notas_internas'           => $data['notas_internas'],
        ]);

        $this->syncItems($cotizacion, $data['items'] ?? []);
        $cotizacion->load('items');
        $cotizacion->recalcularTotales();
        $this->sincronizarComision($cotizacion);

        return redirect("/cotizaciones/{$cotizacion->id}")
            ->with('success', 'Cotización actualizada.');
    }

    public function cambiarEstado(Request $request, Cotizacion $cotizacion): RedirectResponse
    {
        abort_if($cotizacion->en_produccion, 403, 'Esta cotización está en producción y no puede modificarse.');

        $request->validate([
            'estado' => 'required|in:borrador,enviada,aprobada,rechazada,vencida',
        ]);

        $cotizacion->update(['estado' => $request->estado]);

        if ($request->estado === 'aprobada') {
            ComisionVendedor::where('cotizacion_id', $cotizacion->id)
                ->update(['estado' => 'confirmada']);
        }

        return back()->with('success', 'Estado actualizado.');
    }

    public function duplicar(Cotizacion $cotizacion): RedirectResponse
    {
        $cotizacion->load('items');

        $nueva = $cotizacion->replicate(['numero', 'estado']);
        $nueva->estado        = 'borrador';
        $nueva->fecha_creacion = now()->toDateString();
        $nueva->fecha_validez  = now()->addDays(30)->toDateString();
        $nueva->save();

        foreach ($cotizacion->items as $item) {
            $nueva->items()->create($item->only([
                'tipo', 'producto_id', 'configuracion_puerta_id', 'ensamble_id',
                'variables_snapshot', 'componentes_snapshot', 'variables_instancia',
                'descripcion_larga', 'orden',
                'descripcion', 'cantidad', 'precio_unitario', 'precio_mayorista_base', 'descuento_pct',
                'subtotal', 'impuesto_pct', 'impuesto_valor', 'total_linea',
                'comision_pct_aplicada', 'comision_valor',
            ]));
        }

        $nueva->recalcularTotales();

        return redirect("/cotizaciones/{$nueva->id}")
            ->with('success', "Cotización duplicada como {$nueva->numero}.");
    }

    public function pdf(Cotizacion $cotizacion): HttpResponse
    {
        // Plantilla PDF personalizada
        $plantillaId = request()->input('plantilla_id');
        $plantilla   = $plantillaId
            ? \App\Models\PdfPlantilla::find($plantillaId)
            : \App\Models\PdfPlantilla::defaultParaModulo('cotizacion');

        if ($plantilla) {
            $cotizacion->loadMissing(['cliente', 'items.producto', 'items.ensamble', 'responsable']);
            $datos = \App\Services\PdfVariablesEngine::prepararDatos('cotizacion', $cotizacion);
            $html  = \App\Services\PdfVariablesEngine::render($plantilla->html, $datos);
            $pdf   = \Barryvdh\DomPDF\Facade\Pdf::loadHtml($html)
                        ->setPaper($plantilla->papel ?? 'a4', $plantilla->orientacion ?? 'portrait');
            return $pdf->download("cotizacion-{$cotizacion->numero}.pdf");
        }

        // Fallback: plantilla Blade original
        $cotizacion->load(['cliente', 'contacto', 'responsable', 'items.producto.imagenes', 'items.ensamble']);
        $cotizacion->estado_badge = $cotizacion->estadoBadge();

        foreach ($cotizacion->items as $item) {
            if ($item->ensamble?->imagen_principal) {
                $item->imagen_url = public_path('storage/' . $item->ensamble->imagen_principal);
            } elseif ($item->producto) {
                $img = $item->producto->imagenes->firstWhere('es_principal', true)
                    ?? $item->producto->imagenes->first();
                $item->imagen_url = $img ? public_path('storage/' . $img->ruta) : null;
            } else {
                $item->imagen_url = null;
            }
        }

        $pdf = Pdf::loadView('pdf.cotizacion', ['cotizacion' => $cotizacion])
            ->setPaper('a4', 'portrait');

        return $pdf->download("cotizacion-{$cotizacion->numero}.pdf");
    }

    public function destroy(Cotizacion $cotizacion): RedirectResponse
    {
        $cotizacion->delete();

        return redirect('/cotizaciones')->with('success', 'Cotización eliminada.');
    }

    // ── API ───────────────────────────────────────────────────────────────────────

    public function buscarClientes(Request $request): JsonResponse
    {
        $buscar = $request->get('q', '');

        return response()->json(
            Cliente::where('activo', true)
                ->where(fn ($q) => $q
                    ->where('nombre', 'like', "%{$buscar}%")
                    ->orWhere('apellido', 'like', "%{$buscar}%")
                    ->orWhere('numero_identificacion', 'like', "%{$buscar}%")
                )
                ->with('contactos')
                ->take(10)
                ->get()
        );
    }

    /**
     * El texto de condiciones comerciales con el que nace una cotización.
     *
     * Estaba escrito dos veces en este controlador, así que cambiar «Validez 30 días»
     * exigía tocar código —y en un producto instalable, eso significa parchear la
     * instalación de un cliente—. Ahora lo pone la empresa desde la propia cotización.
     *
     * El texto de fábrica se queda como respaldo: una instalación nueva no debería
     * empezar con el bloque de condiciones en blanco.
     */
    public static function condicionesGenerales(): string
    {
        $guardado = \App\Models\Configuracion::get('cotizacion_condiciones', '');

        return filled($guardado)
            ? $guardado
            : 'Precios en pesos colombianos. Validez 30 días. Anticipo 50% para inicio de producción. '
                .'Tiempo de entrega: 15 días hábiles.';
    }

    /**
     * Guarda el texto de arriba como el general de la empresa.
     *
     * Se hace desde la cotización y no desde una pantalla de configuración aparte a
     * propósito: es donde se ve el texto y donde uno se da cuenta de que hay que
     * cambiarlo. Las cotizaciones ya hechas no se tocan — cada una guardó el suyo.
     */
    public function guardarCondicionesGenerales(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'condiciones' => 'nullable|string|max:4000',
        ]);

        \App\Models\Configuracion::set('cotizacion_condiciones', trim((string) ($datos['condiciones'] ?? '')));

        return response()->json([
            'ok'      => true,
            'mensaje' => 'Guardado. Las cotizaciones nuevas arrancarán con este texto; las que ya existen no se tocan.',
        ]);
    }

    public function buscarProductos(Request $request): JsonResponse
    {
        $buscar = $request->get('q', '');

        // Los canales se leen una vez, no una por producto: son los mismos para todos.
        $precios             = app(PreciosPorCanalService::class);
        $canalesConfigurados = app(\App\Services\CanalesPrecioService::class)->canales();

        return response()->json(
            Producto::with(['padre:id,nombre,atributo_variante', 'preciosPorCanal'])
                ->seleccionables()
                ->where('activo', true)
                ->whereIn('tipo', ['producto', 'servicio'])
                ->where(fn ($q) => $q
                    ->where('nombre', 'like', "%{$buscar}%")
                    ->orWhere('referencia', 'like', "%{$buscar}%")
                    ->orWhereHas('padre', fn ($q2) => $q2->where('nombre', 'like', "%{$buscar}%"))
                )
                ->take(20)
                ->get()
                ->map(fn ($p) => [
                    'id'                   => $p->id,
                    'nombre'               => $p->nombre,
                    'nombre_completo'      => $p->nombre_completo,
                    'referencia'           => $p->referencia,
                    'tipo'                 => $p->tipo,
                    'padre_nombre'         => $p->padre?->nombre,
                    'atributo_variante'    => $p->padre?->atributo_variante,
                    'valor_variante'       => $p->valor_variante,
                    'stock_total'          => $p->stockTotal(),
                    'unidad_medida'        => $p->unidad_medida,
                    'descripcion_corta'    => $p->descripcion_corta,
                    // El bloque que se imprime debajo del ítem en la cotización y en la OP
                    // es el texto TÉCNICO CORTO, no la ficha completa: una cotización de
                    // tres ítems salía de cuatro páginas con veinte viñetas por producto.
                    // Si el producto todavía no lo tiene, cae a la descripción comercial,
                    // que también es corta. La ficha larga sigue en el catálogo y la web.
                    'descripcion_larga'    => $p->descripcion_cotizacion ?: $p->descripcion_corta,
                    'precio_costo'                => (float) $p->precio_costo,
                    'precio_mayorista'            => (float) $p->precio_mayorista,
                    'precio_distribuidor'         => (float) $p->precio_distribuidor,
                    'precio_cliente_final'        => (float) $p->precio_cliente_final,
                    'comision_pct_minima'         => (float) $p->comision_pct_minima,
                    'comision_pct_maxima'         => (float) $p->comision_pct_maxima,
                    'comision_min_distribuidor'   => (float) ($p->comision_min_distribuidor  ?? 0),
                    'comision_max_distribuidor'   => (float) ($p->comision_max_distribuidor  ?? 0),
                    'comision_min_cliente_final'  => (float) ($p->comision_min_cliente_final ?? 0),
                    'comision_max_cliente_final'  => (float) ($p->comision_max_cliente_final ?? 0),
                    'descuento_max_cliente_final' => (float) $p->descuento_max_cliente_final,
                    'descuento_max_distribuidor'  => (float) $p->descuento_max_distribuidor,
                    'descuento_max_mayorista'     => (float) $p->descuento_max_mayorista,
                    // Una fila por canal CONFIGURADO, no solo por canal con fila guardada.
                    //
                    // Antes se mandaban únicamente las filas de `canal_precios`, y la
                    // pantalla busca la del canal del cliente: si el producto no la tenía
                    // —porque se guardó desde una pantalla que manda las columnas viejas, o
                    // porque el canal se creó después del producto— el ítem se cotizaba en
                    // CERO sin decir nada. Un precio en cero que nadie pidió no es un error
                    // que se note: es un error que se firma.
                    //
                    // `filaEfectiva` cae a las columnas de siempre cuando no hay fila, y
                    // marca `sin_precio` cuando de verdad no hay de dónde sacarlo.
                    'canales' => $canalesConfigurados->map(function ($canal) use ($p, $precios) {
                        $fila = $precios->filaEfectiva($p, $canal);

                        return array_merge($fila, [
                            'segmentacion_opcion_id' => $canal->id,
                            'etiqueta'               => $canal->etiqueta,
                            'sin_precio'             => $fila['precio'] <= 0,
                        ]);
                    })->values(),
                ])
        );
    }

    /**
     * El precio de un ensamble a medida, para cada canal configurado.
     *
     * A diferencia de un producto, el precio de un ensamble instanciado no está guardado:
     * se calcula ahí mismo desde el costo de sus componentes y un margen. Y ese margen vive
     * en la plantilla, no en el ensamble.
     *
     * La plantilla tiene margen para los tres canales originales. Para un canal que la
     * empresa cree, se usa el margen sugerido según su papel —el más bajo si es el canal
     * base, el más alto si es el precio público— hasta que las plantillas puedan llevar un
     * margen por canal. Es lo siguiente que hay que hacer configurable aquí; mientras, el
     * precio sale razonable en vez de salir en cero.
     *
     * @param  array<string, mixed>  $conf  El config_salida de la plantilla.
     */
    /**
     * Los canales de un ensamble ya medido, con su precio recalculado.
     *
     * El margen sale de `PreciosPorCanalService::margenDe()`: primero el que el ensamble tenga
     * guardado para ese canal, y si no, el que la empresa puso en Segmentación. Antes salía de
     * un mapa de tres claves internas con 30/32,5/35 de respaldo, y ese respaldo era lo que
     * terminaba aplicándose siempre que la empresa hubiera creado sus propios canales.
     *
     * Las comisiones y el descuento máximo **no** dependen de las medidas: son la política
     * comercial del ensamble y se toman de sus filas guardadas. Sin esto, un ensamble medido
     * llegaba a la cotización sin rango de comisión y la barra de negociar no tenía qué mover.
     */
    private function canalesDeInstancia(float $costo, ?Ensamble $ensamble = null): array
    {
        $precios   = app(\App\Services\PreciosPorCanalService::class);
        $guardadas = $ensamble
            ? $ensamble->preciosPorCanal()->get()->keyBy('segmentacion_opcion_id')
            : collect();

        return app(\App\Services\CanalesPrecioService::class)->canales()
            ->map(function ($canal) use ($costo, $ensamble, $precios, $guardadas) {
                $margen = $precios->margenDe($ensamble, $canal);
                $fila   = $guardadas->get($canal->id);

                return [
                    'segmentacion_opcion_id' => $canal->id,
                    'etiqueta'               => $canal->etiqueta,
                    'es_canal_base'          => (bool) $canal->es_canal_base,
                    'margen_pct'             => $margen,
                    'precio'                 => $precios->precioDesdeCosto($costo, $margen),
                    'comision_min_pct'       => (float) ($fila->comision_min_pct ?? 0),
                    'comision_max_pct'       => (float) ($fila->comision_max_pct ?? 0),
                    'descuento_max_pct'      => (float) ($fila->descuento_max_pct ?? 0),
                ];
            })->values()->all();
    }

    /**
     * Los precios recalculados, indexados por la columna vieja que le toca a cada canal.
     *
     * La correspondencia la decide `PreciosPorCanalService::columnaDe()`, que la resuelve por
     * el PAPEL del canal y no por su nombre: una empresa que renombró sus canales sigue
     * llenando las tres claves correctas.
     *
     * @param  array<int, array<string, mixed>>  $canales  Lo que devuelve canalesDeInstancia().
     * @return array<string, float>
     */
    private function preciosPorColumnaVieja(array $canales): array
    {
        $precios = app(\App\Services\PreciosPorCanalService::class);
        $porId   = collect($canales)->keyBy('segmentacion_opcion_id');
        $mapa    = [];

        foreach (app(\App\Services\CanalesPrecioService::class)->canales() as $canal) {
            $columna = $precios->columnaDe($canal);

            if ($columna && isset($porId[$canal->id])) {
                $mapa[$columna] = (float) $porId[$canal->id]['precio'];
            }
        }

        return $mapa;
    }

    public function calcularEnsamble(Request $request, FormulaEvaluatorService $svc): JsonResponse
    {
        // Modo ensamble instancia: ensamble_id + variables_instancia
        if ($request->filled('ensamble_id')) {
            $request->validate([
                'ensamble_id'         => 'required|exists:ensambles,id',
                'variables_instancia' => 'nullable|array',
            ]);
            $ensamble  = Ensamble::with('plantilla')->findOrFail($request->ensamble_id);
            $variables = array_merge((array) ($ensamble->variables ?? []), (array) ($request->variables_instancia ?? []));

            $componentes = $svc->calcularPlantilla($ensamble->plantilla_id, $variables);
            $totalCosto  = $svc->totalCosto($componentes);
            $canales     = $this->canalesDeInstancia($totalCosto, $ensamble);

            // Las tres claves de siempre siguen viajando por compatibilidad, pero ya no salen
            // de márgenes escritos en el código: son el precio del canal que hace ese papel,
            // con la misma regla que usa `PreciosPorCanalService::columnaDe()` para el puente
            // con las columnas viejas. Si no hay canal para alguna, va en cero — mejor que un
            // número inventado con un margen que nadie configuró.
            $viejas = $this->preciosPorColumnaVieja($canales);

            return response()->json([
                'componentes'          => $componentes,
                'precio_costo'         => $totalCosto,
                'total_costo'          => $totalCosto,
                'precio_mayorista'     => $viejas['mayorista'] ?? 0,
                'precio_distribuidor'  => $viejas['distribuidor'] ?? 0,
                'precio_cliente_final' => $viejas['cliente_final'] ?? 0,
                'canales'              => $canales,
            ]);
        }

        // Modo plantilla directa (sin ensamble)
        $data = $request->validate([
            'plantilla_id'    => 'required|exists:plantillas_ensamble,id',
            'variables'       => 'required|array',
            'margen_aplicado' => 'nullable|numeric|min:0|max:100',
        ]);
        $componentes = $svc->calcularPlantilla((int) $data['plantilla_id'], $data['variables']);
        $totalCosto  = $svc->totalCosto($componentes);
        $margen      = (float) ($data['margen_aplicado'] ?? 30);

        return response()->json([
            'componentes'         => $componentes,
            'total_costo'         => $totalCosto,
            'precio_mayorista'    => round($totalCosto * (1 + $margen / 100), 0),
            'precio_distribuidor' => round($totalCosto * (1 + ($margen + 2.5) / 100), 0),
            'precio_cliente_final'=> round($totalCosto * (1 + ($margen + 5) / 100), 0),
            'margen_aplicado'     => $margen,
        ]);
    }

    // ── Privados ──────────────────────────────────────────────────────────────────

    private function validarForm(Request $request): array
    {
        return $request->validate([
            'lead_id'                  => 'nullable|exists:crm_leads,id',
            'cliente_id'               => 'nullable|exists:clientes,id',
            'contacto_id'              => 'nullable|exists:contactos_cliente,id',
            'nombre_contacto_override' => 'nullable|string|max:150',
            'moneda'                   => 'required|in:COP,USD,EUR',
            'tasa_cambio'              => 'required|numeric|min:1',
            'fecha_creacion'           => 'required|date',
            'fecha_validez'            => 'required|date|after_or_equal:fecha_creacion',
            'responsable_id'           => 'required|exists:users,id',
            'condiciones_comerciales'  => 'nullable|string',
            'notas_internas'           => 'nullable|string',
            'items'                    => 'nullable|array',
            'items.*.tipo'             => 'required|in:producto,servicio,configuracion_puerta,ensamble,texto_libre',
            'items.*.descripcion'      => 'required|string',
            'items.*.descripcion_larga'=> 'nullable|string',
            'items.*.cantidad'         => 'required|numeric|min:0.001',
            'items.*.precio_unitario'  => 'required|numeric|min:0',
            'items.*.precio_mayorista_base' => 'nullable|numeric|min:0',
            'items.*.descuento_pct'    => 'nullable|numeric|min:0|max:100',
            'items.*.impuesto_pct'     => 'nullable|numeric|min:0|max:100',
            'items.*.orden'            => 'nullable|integer',
            'items.*.producto_id'              => ['nullable', new ProductoSeleccionable],
            'items.*.configuracion_puerta_id'  => 'nullable|exists:configuraciones_puerta,id',
            'items.*.ensamble_id'              => 'nullable|exists:ensambles,id',
            'items.*.variables_snapshot'       => 'nullable|array',
            'items.*.componentes_snapshot'     => 'nullable|array',
            'items.*.variables_instancia'      => 'nullable|array',
            'items.*.imagenes_instancia'       => 'nullable|array',
            'items.*.comision_pct_aplicada'    => 'nullable|numeric|min:0',
            'items.*.comision_valor'           => 'nullable|numeric|min:0',
        ]);
    }

    private function syncItems(Cotizacion $cot, array $items): void
    {
        $idsEnviados = [];

        foreach ($items as $i => $datos) {
            $item = isset($datos['id']) ? CotizacionItem::find($datos['id']) : null;

            $base     = $datos['cantidad'] * $datos['precio_unitario'];
            $desc     = $base * (($datos['descuento_pct'] ?? 0) / 100);
            $baseDesc = $base - $desc;
            $impuesto = $baseDesc * (($datos['impuesto_pct'] ?? 0) / 100);

            $fill = [
                'cotizacion_id'           => $cot->id,
                'tipo'                    => $datos['tipo'] === 'servicio' ? 'texto_libre' : ($datos['tipo'] ?? 'producto'),
                'producto_id'             => $datos['producto_id'] ?? null,
                'configuracion_puerta_id' => $datos['configuracion_puerta_id'] ?? null,
                'ensamble_id'             => $datos['ensamble_id'] ?? null,
                'variables_snapshot'      => $datos['variables_snapshot'] ?? null,
                'componentes_snapshot'    => $datos['componentes_snapshot'] ?? null,
                'variables_instancia'     => $datos['variables_instancia'] ?? null,
                'imagenes_instancia'      => $datos['imagenes_instancia'] ?? null,
                'descripcion_larga'       => $datos['descripcion_larga'] ?? null,
                'orden'                   => $datos['orden'] ?? $i,
                'descripcion'             => $datos['descripcion'],
                'cantidad'                => $datos['cantidad'],
                'precio_unitario'         => $datos['precio_unitario'],
                'precio_mayorista_base'   => $datos['precio_mayorista_base'] ?? 0,
                'descuento_pct'           => $datos['descuento_pct'] ?? 0,
                'subtotal'                => $base,
                'impuesto_pct'            => $datos['impuesto_pct'] ?? 0,
                'impuesto_valor'          => $impuesto,
                'total_linea'             => $baseDesc + $impuesto,
                'comision_pct_aplicada'   => $datos['comision_pct_aplicada'] ?? 0,
                // La comisión es un porcentaje DEL PRECIO de venta: 5 % de 1.428.000 son
                // 71.400. Se dice así porque es como la lee un vendedor, y porque con la
                // misma unidad el descuento que puede dar es una resta —su tope menos lo que
                // se conforme cobrar— en vez de una regla de tres.
                //
                // De dónde sale esa plata no cambió: del excedente por encima del canal base,
                // que es la utilidad garantizada de la empresa. Por eso el canal base no paga
                // comisión, y por eso el tope de cada canal se propone como una parte de su
                // excedente. Hasta el 17 ago 2026 el porcentaje se multiplicaba por el
                // excedente en vez de por el precio.
                'comision_valor'          => (float) ($datos['precio_unitario'] ?? 0)
                                             * (float) ($datos['cantidad'] ?? 1)
                                             * ((float) ($datos['comision_pct_aplicada'] ?? 0) / 100),
            ];

            if ($item && $item->cotizacion_id === $cot->id) {
                $item->update($fill);
                $idsEnviados[] = $item->id;
            } else {
                $nuevo = CotizacionItem::create($fill);
                $idsEnviados[] = $nuevo->id;
            }
        }

        $cot->items()->whereNotIn('id', $idsEnviados)->delete();
    }

    public function uploadImagenInstancia(Request $request): JsonResponse
    {
        $request->validate([
            'imagen' => 'required|image|max:10240',
            'titulo' => 'nullable|string|max:255',
        ]);

        $ruta = $request->file('imagen')->store('cotizaciones/instancia', 'public');

        return response()->json([
            'ruta'   => $ruta,
            'titulo' => $request->titulo ?? null,
        ]);
    }

    private function sincronizarComision(Cotizacion $cot): void
    {
        // Misma regla que en syncItems(): la comisión es un porcentaje del precio de venta.
        $totalComision = $cot->items->sum(fn ($item) =>
            (float) $item->precio_unitario
            * (float) $item->cantidad
            * ((float) $item->comision_pct_aplicada / 100)
        );

        // Antes solo se creaba el registro si había comisión > 0 — eso
        // dejaba a la cotización sin ningún dato de comisión que mostrar
        // (ni siquiera "sin comisión"), lo cual confundía en la vista.
        // Ahora siempre queda un registro, así se pueda ver su estado real.
        $existente = ComisionVendedor::where('cotizacion_id', $cot->id)->first();

        ComisionVendedor::updateOrCreate(
            ['cotizacion_id' => $cot->id],
            [
                'user_id'        => $cot->responsable_id,
                'total_comision' => $totalComision,
                'estado'         => $existente->estado ?? 'proyectada',
                'periodo_mes'    => \Carbon\Carbon::parse($cot->fecha_creacion)->format('Y-m'),
            ]
        );
    }
}
