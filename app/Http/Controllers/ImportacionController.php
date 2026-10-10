<?php

namespace App\Http\Controllers;

use App\Models\Configuracion;
use App\Models\Importacion;
use App\Models\ImportacionCosto;
use App\Models\OrdenCompra;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Services\CanalesPrecioService;
use App\Services\LiquidacionImportacionService;
use App\Services\PreciosPorCanalService;
use App\Services\TasaCambioService;
use App\Support\ContextoSede;
use App\Support\Monedas;
use App\Support\Orden;
use App\Support\Paises;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Compras → Importaciones: cuánto cuesta de verdad lo que se trae de afuera.
 *
 * Un embarque se arma en borrador (se calcula en vivo, con la cuenta del servidor), se
 * liquida, y al liquidarse el costo puesto en bodega de cada producto pasa a ser su costo y
 * reprecia lo que salía de la cuenta. Ver `LiquidacionImportacionService`.
 */
class ImportacionController extends Controller
{
    public function __construct(private LiquidacionImportacionService $liquidacion) {}

    public function index(Request $request): Response
    {
        $query = ContextoSede::aplicar(Importacion::query()->with('proveedor:id,nombre'))
            ->when($request->filled('buscar'), fn ($q) => $q->where(function ($q) use ($request) {
                $q->where('numero', 'like', "%{$request->buscar}%")
                  ->orWhere('referencia', 'like', "%{$request->buscar}%")
                  ->orWhereHas('proveedor', fn ($p) => $p->where('nombre', 'like', "%{$request->buscar}%"));
            }))
            ->when($request->filled('estado'), fn ($q) => $q->where('estado', $request->estado));

        $orden = Orden::aplicar($query, $request, [
            'numero'      => 'numero',
            'fecha'       => 'fecha',
            'estado'      => 'estado',
            'total_costo' => 'total_costo',
        ], 'fecha', 'desc');

        return Inertia::render('Compras/Importaciones/Index', [
            'importaciones' => $query->paginate(20)->withQueryString()->through(fn (Importacion $i) => [
                'id' => $i->id, 'numero' => $i->numero, 'estado' => $i->estado, 'fecha' => $i->fecha?->toDateString(),
                'referencia' => $i->referencia, 'proveedor' => $i->proveedor?->nombre, 'moneda' => $i->moneda,
                'pais_origen' => $i->pais_origen, 'total_fob' => $i->total_fob, 'total_costo' => $i->total_costo,
            ]),
            'orden'    => $orden,
            'filters'  => $request->only(['buscar', 'estado']),
            'criterio' => LiquidacionImportacionService::criterioCosto(),
            'base'     => Monedas::base(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Compras/Importaciones/Form', ['importacion' => null] + $this->propsFormulario());
    }

    public function edit(Importacion $importacion): Response|RedirectResponse
    {
        if (! $importacion->esBorrador()) {
            return redirect("/compras/importaciones/{$importacion->id}")->with('error', 'Una importación liquidada no se edita.');
        }

        return Inertia::render('Compras/Importaciones/Form', [
            'importacion' => $this->serializar($importacion->load(['items', 'costos'])),
        ] + $this->propsFormulario());
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validar($request);

        $importacion = Importacion::create($this->cabecera($data) + ['estado' => 'borrador', 'creado_por' => auth()->id()]);
        $this->guardarLineas($importacion, $data);

        return redirect("/compras/importaciones/{$importacion->id}")->with('success', "Importación {$importacion->numero} guardada. Revisa los números y liquídala.");
    }

    public function update(Request $request, Importacion $importacion): RedirectResponse
    {
        if (! $importacion->esBorrador()) {
            return back()->with('error', 'Una importación liquidada no se edita.');
        }

        $data = $this->validar($request);

        $importacion->update($this->cabecera($data));
        $this->guardarLineas($importacion, $data);

        return redirect("/compras/importaciones/{$importacion->id}")->with('success', 'Importación actualizada.');
    }

    public function show(Importacion $importacion): Response
    {
        $importacion->load(['items.producto:id,nombre,referencia', 'costos', 'proveedor:id,nombre', 'ordenCompra:id,numero']);

        // Liquidada: lo guardado. Borrador: la cuenta de hoy.
        $r = $importacion->esBorrador()
            ? $this->liquidacion->calcular($this->liquidacion->entradaDe($importacion))
            : null;

        $canales = app(CanalesPrecioService::class)->canales();
        $precios = app(PreciosPorCanalService::class);

        $items = $importacion->items->map(function ($item, $k) use ($r, $canales, $precios) {
            $fila = $r ? $r['items'][$k] : $item->only(['fob', 'flete_seguro', 'cif', 'arancel', 'iva', 'gastos', 'costo_total', 'costo_unitario']);
            $unitario = $fila['costo_unitario'] !== null ? (float) $fila['costo_unitario'] : null;

            return [
                'id' => $item->id, 'descripcion' => $item->descripcion, 'producto' => $item->producto?->only(['id', 'nombre', 'referencia']),
                'cantidad' => (float) $item->cantidad, 'valor_unitario' => (float) $item->valor_unitario, 'peso_kg' => $item->peso_kg,
                'codigo_hs' => $item->codigo_hs, 'arancel_pct' => (float) $item->arancel_pct, 'iva_pct' => (float) $item->iva_pct,
                'aplicado' => $item->aplicado_at !== null,
            ] + array_map(fn ($v) => $v === null ? null : (float) $v, $fila) + [
                // Cuánto valdría vender lo importado con el margen de cada canal.
                'precios' => $unitario === null ? [] : $canales->mapWithKeys(fn ($c) => [
                    $c->id => $precios->precioDesdeCosto($unitario, $precios->margenDe($item->producto, $c)),
                ])->all(),
            ];
        });

        return Inertia::render('Compras/Importaciones/Show', [
            'importacion' => $this->serializar($importacion) + [
                'proveedor' => $importacion->proveedor?->nombre,
                'orden_compra' => $importacion->ordenCompra?->numero,
                'liquidada_at' => $importacion->liquidada_at?->toIso8601String(),
            ],
            'items'   => $items,
            'totales' => $r ? $r['totales'] : [
                'fob' => (float) $importacion->total_fob, 'flete_seguro' => (float) $importacion->total_flete_seguro,
                'arancel' => (float) $importacion->total_arancel, 'iva' => (float) $importacion->total_iva,
                'gastos' => (float) $importacion->total_gastos, 'costo_total' => (float) $importacion->total_costo,
            ],
            'factor'  => $r ? $r['factor'] : ((float) $importacion->total_fob > 0 ? round((float) $importacion->total_costo / (float) $importacion->total_fob, 4) : null),
            'avisos'  => $r['avisos'] ?? [],
            'canales' => $canales->map(fn ($c) => ['id' => $c->id, 'etiqueta' => $c->etiqueta])->values(),
            'criterio' => LiquidacionImportacionService::criterioCosto(),
            'base'    => Monedas::base(),
            'incoterms' => Importacion::INCOTERMS,
        ]);
    }

    public function liquidar(Importacion $importacion): RedirectResponse
    {
        try {
            $cambiados = $this->liquidacion->liquidar($importacion, auth()->id());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Importación {$importacion->numero} liquidada."
            . ($cambiados > 0 ? " El costo de {$cambiados} producto(s) quedó actualizado y se repreciaron sus canales." : ''));
    }

    public function destroy(Importacion $importacion): RedirectResponse
    {
        if (! $importacion->esBorrador()) {
            return back()->with('error', 'Una importación liquidada es historia de costos y no se elimina.');
        }

        $importacion->delete();

        return redirect('/compras/importaciones')->with('success', 'Importación eliminada.');
    }

    /** La cuenta en vivo, para la pantalla mientras se escribe. */
    public function calcular(Request $request): JsonResponse
    {
        $d = $request->validate([
            'tasa' => 'nullable|numeric', 'metodo' => 'nullable|string', 'iva_descontable' => 'nullable|boolean',
            'items' => 'nullable|array|max:300', 'items.*' => 'array',
            'costos' => 'nullable|array|max:100', 'costos.*' => 'array',
        ]);

        return response()->json($this->liquidacion->calcular($d + ['items' => [], 'costos' => [], 'tasa' => 0]));
    }

    /** Productos para agregar al embarque, con su arancel habitual. */
    public function productos(Request $request): JsonResponse
    {
        $q = (string) $request->query('q', '');

        return response()->json(
            Producto::query()->seleccionables()->where('activo', true)->where('tipo', '!=', 'servicio')
                ->where(fn ($w) => $w->where('nombre', 'like', "%{$q}%")->orWhere('referencia', 'like', "%{$q}%"))
                ->limit(15)->get()
                ->map(fn ($p) => [
                    'id' => $p->id, 'nombre' => $p->nombre_completo ?? $p->nombre, 'referencia' => $p->referencia,
                    'codigo_hs' => $p->codigo_hs, 'arancel_pct' => $p->arancel_pct !== null ? (float) $p->arancel_pct : null,
                ])
        );
    }

    /** Cómo se fija el costo de un producto que se importa varias veces. */
    public function ajustes(Request $request): RedirectResponse
    {
        $d = $request->validate(['criterio' => 'required|in:ultimo,promedio']);

        Configuracion::set('importacion_costo_vigente', $d['criterio']);

        return back()->with('success', 'Guardado. Se aplica desde la próxima liquidación.');
    }

    // ─── Apoyo ───────────────────────────────────────────────────────────────

    private function propsFormulario(): array
    {
        $perfil = Paises::perfil(Paises::activo());

        return [
            'proveedores' => Proveedor::where('activo', true)->orderBy('nombre')->get(['id', 'nombre', 'pais']),
            'ordenes'     => OrdenCompra::with('proveedor:id,nombre')->latest()->limit(50)->get()
                ->map(fn ($o) => ['id' => $o->id, 'numero' => $o->numero, 'proveedor' => $o->proveedor?->nombre]),
            'paises'      => Paises::lista(),
            'monedas'     => collect(Monedas::CATALOGO)->except(Monedas::base())->map(fn ($m, $c) => ['codigo' => $c, 'nombre' => $m['nombre']])->values(),
            'base'        => Monedas::base(),
            'tasas'       => app(TasaCambioService::class)->paraInterfaz(),
            'incoterms'   => Importacion::INCOTERMS,
            'metodos'     => LiquidacionImportacionService::METODOS,
            'tipos_costo' => ImportacionCosto::TIPOS,
            // Lo que propone el país de la sede: el nombre y la tarifa del impuesto y si se recupera.
            'impuesto'    => [
                'nombre'          => $perfil['impuestos'][0]['nombre'] ?? 'Impuesto',
                'defecto'         => (float) ($perfil['impuestos'][0]['defecto'] ?? 0),
                'tarifas'         => Paises::tarifas($perfil['iso']),
                'iva_descontable' => $perfil['importacion']['iva_descontable'],
                'nota'            => $perfil['importacion']['nota'],
            ],
        ];
    }

    private function validar(Request $request): array
    {
        return $request->validate([
            'proveedor_id'        => 'nullable|exists:proveedores,id',
            'orden_compra_id'     => 'nullable|exists:ordenes_compra,id',
            'fecha'               => 'required|date',
            'referencia'          => 'nullable|string|max:100',
            'pais_origen'         => ['nullable', 'string', 'size:2', Rule::in(array_keys(Paises::CATALOGO))],
            'incoterm'            => ['nullable', 'string', Rule::in(array_keys(Importacion::INCOTERMS))],
            'moneda'              => ['required', 'string', Rule::in(array_keys(Monedas::CATALOGO))],
            'tasa'                => 'required|numeric|gt:0',
            'tasa_fecha'          => 'nullable|date',
            'metodo_prorrateo'    => ['required', Rule::in(array_keys(LiquidacionImportacionService::METODOS))],
            'iva_descontable'     => 'required|boolean',
            'notas'               => 'nullable|string|max:2000',
            'items'               => 'required|array|min:1|max:300',
            'items.*.producto_id' => 'nullable|exists:productos,id',
            'items.*.descripcion' => 'required|string|max:255',
            'items.*.cantidad'    => 'required|numeric|gt:0',
            'items.*.valor_unitario' => 'required|numeric|min:0',
            'items.*.peso_kg'     => 'nullable|numeric|min:0',
            'items.*.codigo_hs'   => 'nullable|string|max:12',
            'items.*.arancel_pct' => 'required|numeric|min:0|max:100',
            'items.*.iva_pct'     => 'required|numeric|min:0|max:100',
            'costos'              => 'nullable|array|max:100',
            'costos.*.tipo'       => ['required', Rule::in(array_keys(ImportacionCosto::TIPOS))],
            'costos.*.concepto'   => 'required|string|max:120',
            'costos.*.valor'      => 'required|numeric|min:0',
            'costos.*.en_moneda_base' => 'boolean',
        ], [
            'items.required' => 'Agrega al menos un producto.',
            'tasa.gt'        => 'La tasa tiene que ser mayor que cero.',
        ]);
    }

    private function cabecera(array $d): array
    {
        return [
            'proveedor_id' => $d['proveedor_id'] ?? null, 'orden_compra_id' => $d['orden_compra_id'] ?? null,
            'fecha' => $d['fecha'], 'referencia' => $d['referencia'] ?? null, 'pais_origen' => $d['pais_origen'] ?? null,
            'incoterm' => $d['incoterm'] ?? null, 'moneda' => $d['moneda'],
            // En la moneda base la tasa es 1: no hay nada que convertir.
            'tasa' => $d['moneda'] === Monedas::base() ? 1 : $d['tasa'],
            'tasa_fecha' => $d['tasa_fecha'] ?? null, 'metodo_prorrateo' => $d['metodo_prorrateo'],
            'iva_descontable' => (bool) $d['iva_descontable'], 'notas' => $d['notas'] ?? null,
        ];
    }

    private function guardarLineas(Importacion $imp, array $d): void
    {
        $imp->items()->delete();
        $imp->costos()->delete();

        foreach ($d['items'] as $k => $i) {
            $imp->items()->create([
                'producto_id' => $i['producto_id'] ?? null, 'descripcion' => $i['descripcion'], 'cantidad' => $i['cantidad'],
                'valor_unitario' => $i['valor_unitario'], 'peso_kg' => $i['peso_kg'] ?? null,
                'codigo_hs' => $i['codigo_hs'] ?? null, 'arancel_pct' => $i['arancel_pct'], 'iva_pct' => $i['iva_pct'], 'orden' => $k,
            ]);
        }

        foreach ($d['costos'] ?? [] as $k => $c) {
            $imp->costos()->create([
                'tipo' => $c['tipo'], 'concepto' => $c['concepto'], 'valor' => $c['valor'],
                'en_moneda_base' => (bool) ($c['en_moneda_base'] ?? false), 'orden' => $k,
            ]);
        }
    }

    private function serializar(Importacion $i): array
    {
        return [
            'id' => $i->id, 'numero' => $i->numero, 'estado' => $i->estado, 'proveedor_id' => $i->proveedor_id,
            'orden_compra_id' => $i->orden_compra_id, 'fecha' => $i->fecha?->toDateString(), 'referencia' => $i->referencia,
            'pais_origen' => $i->pais_origen, 'incoterm' => $i->incoterm, 'moneda' => $i->moneda, 'tasa' => (float) $i->tasa,
            'tasa_fecha' => $i->tasa_fecha?->toDateString(), 'metodo_prorrateo' => $i->metodo_prorrateo,
            'iva_descontable' => $i->iva_descontable, 'notas' => $i->notas,
            'items' => $i->relationLoaded('items') ? $i->items->map(fn ($x) => [
                'producto_id' => $x->producto_id, 'descripcion' => $x->descripcion, 'cantidad' => (float) $x->cantidad,
                'valor_unitario' => (float) $x->valor_unitario, 'peso_kg' => $x->peso_kg !== null ? (float) $x->peso_kg : null,
                'codigo_hs' => $x->codigo_hs, 'arancel_pct' => (float) $x->arancel_pct, 'iva_pct' => (float) $x->iva_pct,
            ])->values() : [],
            'costos' => $i->relationLoaded('costos') ? $i->costos->map(fn ($c) => [
                'tipo' => $c->tipo, 'concepto' => $c->concepto, 'valor' => (float) $c->valor, 'en_moneda_base' => $c->en_moneda_base,
            ])->values() : [],
        ];
    }
}
