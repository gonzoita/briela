<?php

namespace App\Http\Controllers;

use App\Models\Bodega;
use App\Models\CategoriaProducto;
use App\Models\Producto;
use App\Models\ProductoProveedor;
use App\Models\Proveedor;
use App\Models\SegmentacionOpcion;
use App\Services\CanalesPrecioService;
use App\Services\PreciosPorCanalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Importación de productos desde CSV.
 *
 * **Las columnas de precio salen de los canales configurados en Segmentación**, no de una
 * lista escrita aquí. Antes eran fijas —`precio_mayorista`, `precio_distribuidor`,
 * `precio_cliente_final` y sus márgenes y comisiones— y escribían directo en esas columnas
 * viejas, que ya no son las que lee la cotización: un producto importado con precios se
 * cotizaba con los precios guardados en `canal_precios`, que el CSV nunca tocaba. Y un canal
 * que la empresa creara no tenía columna en la plantilla.
 *
 * Ahora cada canal trae sus columnas —`margen_<clave>`, `precio_<clave>`, y las de comisión y
 * descuento si el canal no es el base— y se guardan por `PreciosPorCanalService`, que es el
 * mismo camino de la ficha del producto: llena `canal_precios` y espeja las columnas viejas.
 *
 * Un CSV viejo sigue sirviendo: si una columna nueva viene vacía, se busca la vieja del mismo
 * papel (`precio_cliente_final` para el canal de precio público, por ejemplo).
 *
 * La guía de columnas de la pantalla sale de aquí mismo, así que nunca se desalinea de la
 * plantilla.
 */
class ProductoImportController extends Controller
{
    public function __construct(
        private CanalesPrecioService $canales,
        private PreciosPorCanalService $precios,
    ) {}

    /** Columnas fijas antes de los precios: [nombre => [descripción, obligatoria, grupo]]. */
    private const ANTES = [
        'nombre'                 => ['Nombre del producto o servicio.', true, 'Datos'],
        'tipo'                   => ['«producto» o «servicio». Vacío: producto.', false, 'Datos'],
        'referencia'             => ['Código único. Vacío: se genera solo. Si ya existe un producto con esa referencia, se actualiza en vez de crear otro.', false, 'Datos'],
        'categoria'              => ['Nombre de la categoría. Si no existe, se crea sola.', false, 'Datos'],
        'unidad_medida'          => ['Ej: unidad, m2, kg. Vacío: unidad.', false, 'Datos'],
        'descripcion_corta'      => ['Texto corto para el catálogo.', false, 'Datos'],
        'descripcion_larga'      => ['Descripción detallada.', false, 'Datos'],
        'descripcion_cotizacion' => ['Texto técnico corto que sale en cotizaciones y órdenes de producción.', false, 'Datos'],
        'es_vendible'            => ['«Si» o «No»: si aparece en cotizaciones. Vacío: Si.', false, 'Datos'],
        'es_insumo'              => ['«Si» o «No»: si se usa como insumo. Vacío: No.', false, 'Datos'],
        'inventariable'          => ['«Si» o «No»: si maneja stock. Vacío: Si en productos.', false, 'Datos'],
        'activo'                 => ['«Si» o «No». Vacío: Si.', false, 'Datos'],

        'proveedor'              => ['Nombre del proveedor. Si no existe, se crea solo, y queda en la lista de proveedores del producto.', false, 'Proveedor'],
        'referencia_proveedor'   => ['El código con el que ese proveedor conoce el producto.', false, 'Proveedor'],
        'precio_proveedor'       => ['Lo que cobra ese proveedor por unidad. Vacío: el precio de costo.', false, 'Proveedor'],

        'precio_costo'                => ['Precio de costo. Solo números, sin puntos de miles.', false, 'Costo y precios'],
        'utilidad_minima_empresa_pct' => ['% de utilidad mínima que exige la empresa. Vacío: 15.', false, 'Costo y precios'],
    ];

    /** Columnas fijas después de los precios. */
    private const DESPUES = [
        'stock_minimo'      => ['Umbral para la alerta de stock bajo.', false, 'Inventario'],
        'stock_maximo'      => ['Umbral máximo de stock.', false, 'Inventario'],
        'stock_inicial'     => ['Cantidad inicial. Solo al CREAR el producto: si reimportas el mismo archivo no se vuelve a sumar.', false, 'Inventario'],
        'bodega'            => ['Bodega del stock inicial. Vacío: la principal.', false, 'Inventario'],

        'es_padre'          => ['«Si» si la fila agrupa variantes (una puerta que viene en varios colores). Un padre no lleva stock propio.', false, 'Variantes'],
        'producto_padre'    => ['Referencia del padre, solo en las filas que son variantes. El padre debe existir o venir antes en el archivo.', false, 'Variantes'],
        'atributo_variante' => ['Solo en la fila del padre: qué varía, ej. «Color».', false, 'Variantes'],
        'valor_variante'    => ['Solo en filas de variante: el valor, ej. «Blanco».', false, 'Variantes'],
    ];

    /** Los campos de precio de un canal, en el orden de la plantilla. */
    private const CAMPOS_CANAL = ['margen', 'precio', 'comision_min', 'comision_max', 'descuento_max'];

    // ─── Columnas ────────────────────────────────────────────────────────────

    /**
     * Con qué clave va un canal en los encabezados.
     *
     * Su `valor` de Segmentación, salvo que choque con una columna fija: un canal llamado
     * «costo» daría `precio_costo`, que ya es otra cosa.
     */
    private function claveDe(SegmentacionOpcion $canal): string
    {
        $fijas = array_merge(array_keys(self::ANTES), array_keys(self::DESPUES));

        foreach (self::CAMPOS_CANAL as $campo) {
            if (in_array("{$campo}_{$canal->valor}", $fijas, true)) {
                return 'canal_' . $canal->valor;
            }
        }

        return $canal->valor;
    }

    /** El canal base no lleva comisión ni descuento: es el piso de utilidad. */
    private function camposDe(SegmentacionOpcion $canal): array
    {
        return $canal->es_canal_base ? ['margen', 'precio'] : self::CAMPOS_CANAL;
    }

    /**
     * Todas las columnas de la plantilla, en orden, con su explicación.
     *
     * @return list<array{columna: string, texto: string, obligatoria: bool, grupo: string}>
     */
    private function columnas(): array
    {
        $fila = fn ($col, $d) => ['columna' => $col, 'texto' => $d[0], 'obligatoria' => $d[1], 'grupo' => $d[2]];

        $columnas = [];
        foreach (self::ANTES as $col => $d) {
            $columnas[] = $fila($col, $d);
        }

        foreach ($this->canales->canales() as $canal) {
            $clave  = $this->claveDe($canal);
            $grupo  = 'Precio · ' . $canal->etiqueta;
            $papel  = $canal->es_canal_base ? ' Es el canal base: el piso de utilidad, sin comisión.'
                : ($canal->es_precio_publico ? ' Es el precio público: el del catálogo web.' : '');
            $sugerido = rtrim(rtrim(number_format((float) $canal->margen_sugerido, 2, '.', ''), '0'), '.');

            $textos = [
                'margen'        => "% de recargo sobre el costo para «{$canal->etiqueta}». Vacío: el que ya tenga el producto, o el {$sugerido} % de Segmentación.",
                'precio'        => "Precio de venta a «{$canal->etiqueta}». Vacío: costo más el margen, hacia arriba al millar. Si lo escribes, manda sobre el margen.{$papel}",
                'comision_min'  => "% del precio que gana el vendedor como mínimo en «{$canal->etiqueta}». Vacío en un producto nuevo: se sugiere, como el botón «Sugerir comisiones».",
                'comision_max'  => "% del precio que gana el vendedor como máximo en «{$canal->etiqueta}». Vacío en un producto nuevo: se sugiere.",
                'descuento_max' => "% máximo de descuento en «{$canal->etiqueta}». Vacío en un producto nuevo: se sugiere.",
            ];

            foreach ($this->camposDe($canal) as $campo) {
                $columnas[] = $fila("{$campo}_{$clave}", [$textos[$campo], false, $grupo]);
            }
        }

        foreach (self::DESPUES as $col => $d) {
            $columnas[] = $fila($col, $d);
        }

        return $columnas;
    }

    public function index(): Response
    {
        return Inertia::render('Productos/Importar', [
            'columnas' => $this->columnas(),
        ]);
    }

    // ─── Plantilla ───────────────────────────────────────────────────────────

    public function plantilla(): HttpResponse
    {
        $encabezados = array_column($this->columnas(), 'columna');
        $canales     = $this->canales->canales();
        $publico     = $this->canales->publico() ?? $canales->last();
        $bodega      = Bodega::principal()?->nombre ?? '';

        // Los márgenes de ejemplo son los de Segmentación: así el ejemplo sale con los
        // precios que la empresa ya usa, no con números inventados.
        $margenes = [];
        foreach ($canales as $canal) {
            $margenes['margen_' . $this->claveDe($canal)] = rtrim(rtrim(number_format((float) $canal->margen_sugerido, 2, '.', ''), '0'), '.');
        }

        // Las filas van por nombre de columna, no por posición: con columnas que dependen de
        // cuántos canales tenga la empresa, una fila escrita por posición sale corrida en
        // cuanto alguien crea un canal.
        $ejemplos = [
            array_merge([
                'nombre' => 'Cuarto frío modular 3x3', 'tipo' => 'producto', 'categoria' => 'Cuartos fríos',
                'unidad_medida' => 'unidad', 'descripcion_corta' => 'Cuarto frío modular panel inyectado',
                'es_vendible' => 'Si', 'es_insumo' => 'No', 'inventariable' => 'Si', 'activo' => 'Si',
                'proveedor' => 'Proveedor de ejemplo', 'referencia_proveedor' => 'CF-33', 'precio_proveedor' => '3500000',
                'precio_costo' => '3500000', 'utilidad_minima_empresa_pct' => '15',
                'stock_minimo' => '1', 'stock_inicial' => '5', 'bodega' => $bodega, 'es_padre' => 'No',
            ], $margenes),
            [
                'nombre' => 'Instalación de cuarto frío', 'tipo' => 'servicio', 'categoria' => 'Servicios',
                'unidad_medida' => 'unidad', 'es_vendible' => 'Si', 'es_insumo' => 'No', 'inventariable' => 'No',
                'activo' => 'Si', 'es_padre' => 'No',
            ] + ($publico ? ['precio_' . $this->claveDe($publico) => '800000'] : []),
            [
                'nombre' => 'Puerta batiente', 'tipo' => 'producto', 'referencia' => 'PROD-0100', 'categoria' => 'Puertas',
                'unidad_medida' => 'unidad', 'es_vendible' => 'Si', 'es_insumo' => 'No', 'inventariable' => 'Si',
                'activo' => 'Si', 'precio_costo' => '900000', 'es_padre' => 'Si', 'atributo_variante' => 'Color',
            ],
            [
                'nombre' => 'Puerta batiente — Blanco', 'stock_inicial' => '3', 'bodega' => $bodega,
                'es_padre' => 'No', 'producto_padre' => 'PROD-0100', 'valor_variante' => 'Blanco',
            ],
            [
                'nombre' => 'Puerta batiente — Verde', 'stock_inicial' => '5', 'bodega' => $bodega,
                'es_padre' => 'No', 'producto_padre' => 'PROD-0100', 'valor_variante' => 'Verde',
            ],
        ];

        $handle = fopen('php://temp', 'w+');
        fwrite($handle, "\xEF\xBB\xBF"); // BOM — para que Excel abra los acentos bien
        fputcsv($handle, $encabezados, ';');
        foreach ($ejemplos as $ejemplo) {
            fputcsv($handle, array_map(fn ($col) => $ejemplo[$col] ?? '', $encabezados), ';');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="plantilla-productos.csv"',
        ]);
    }

    // ─── Importar ────────────────────────────────────────────────────────────

    public function importar(Request $request): JsonResponse
    {
        $request->validate(['archivo' => 'required|file|max:10240']);

        $contenido = file_get_contents($request->file('archivo')->getRealPath());
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido);

        $delimitador = substr_count($contenido, ';') > substr_count($contenido, ',') ? ';' : ',';

        $lineas = array_values(array_filter(
            preg_split('/\r\n|\r|\n/', $contenido),
            fn ($l) => trim($l) !== ''
        ));

        if (count($lineas) < 2) {
            return response()->json(['message' => 'El archivo no tiene filas de datos.'], 422);
        }

        $header = array_map(fn ($h) => trim(mb_strtolower($h)), str_getcsv(array_shift($lineas), $delimitador));

        $filas = [];
        foreach ($lineas as $i => $linea) {
            $valores = str_getcsv($linea, $delimitador);
            $fila    = [];
            foreach ($header as $idx => $col) {
                $fila[$col] = isset($valores[$idx]) ? trim($valores[$idx]) : '';
            }
            $filas[] = ['numero' => $i + 2, 'datos' => $fila]; // fila 1 = encabezado
        }

        $resultado = [
            'creados'             => 0,
            'actualizados'        => 0,
            'errores'             => [],
            'categorias_creadas'  => [],
            'proveedores_creados' => [],
        ];

        // Los canales se leen una vez para todo el archivo: son los mismos en cada fila.
        $canales = $this->canales->canales();

        // Los padres/productos sueltos se procesan primero para que las
        // variantes (que dependen de "producto_padre") ya los encuentren.
        $normales  = array_filter($filas, fn ($f) => trim($f['datos']['producto_padre'] ?? '') === '');
        $variantes = array_filter($filas, fn ($f) => trim($f['datos']['producto_padre'] ?? '') !== '');

        foreach ([...$normales, ...$variantes] as $f) {
            $this->procesarFila($f['datos'], $f['numero'], $canales, $resultado);
        }

        return response()->json($resultado);
    }

    private function esSi(?string $valor, bool $default): bool
    {
        if ($valor === null || trim($valor) === '') return $default;
        return in_array(mb_strtolower(trim($valor)), ['si', 'sí', '1', 'true', 'x'], true);
    }

    /** Número de una celda, o null si viene vacía. Acepta coma decimal. */
    private function numero(?string $valor): ?float
    {
        if ($valor === null || trim($valor) === '') {
            return null;
        }

        return (float) str_replace(',', '.', trim($valor));
    }

    private function num(?string $valor, $existente, $default): float
    {
        return $this->numero($valor) ?? (float) ($existente ?? $default);
    }

    private function texto(?string $valor, $existente, $default = null)
    {
        if ($valor !== null && trim($valor) !== '') {
            return trim($valor);
        }
        return $existente ?? $default;
    }

    private function procesarFila(array $d, int $numero, $canales, array &$resultado): void
    {
        try {
            DB::transaction(function () use ($d, $canales, &$resultado) {
                $nombre = trim($d['nombre'] ?? '');
                if ($nombre === '') {
                    throw new \RuntimeException('Falta el nombre (columna obligatoria).');
                }

                $esVariante = trim($d['producto_padre'] ?? '') !== '';
                $tipo       = mb_strtolower(trim($d['tipo'] ?? ''));
                if (! in_array($tipo, ['producto', 'servicio'], true)) $tipo = 'producto';

                $referencia = trim($d['referencia'] ?? '');
                $producto   = $referencia !== '' ? Producto::where('referencia', $referencia)->first() : null;
                $esNuevo    = ! $producto;

                $padre = null;
                if ($esVariante) {
                    $refPadre = trim($d['producto_padre']);
                    $padre    = Producto::where('referencia', $refPadre)->first();
                    if (! $padre) {
                        throw new \RuntimeException("No se encontró el producto padre con referencia \"{$refPadre}\". Ese padre debe existir o venir en una fila anterior del mismo archivo.");
                    }
                }

                // Una variante nueva hereda del padre lo que su fila no traiga: categoría,
                // proveedor, costo y precios. Es lo mismo que hace la pantalla al crearlas.
                $heredaDe = $esNuevo && $esVariante ? $padre : null;

                // Categoría / proveedor: por nombre de texto, se crean solos si no existen.
                $categoriaId = $producto?->categoria_id ?? $heredaDe?->categoria_id;
                $catNombre   = trim($d['categoria'] ?? '');
                if ($catNombre !== '') {
                    $cat = CategoriaProducto::firstOrCreate(['nombre' => $catNombre], ['activa' => true]);
                    if ($cat->wasRecentlyCreated) $resultado['categorias_creadas'][] = $catNombre;
                    $categoriaId = $cat->id;
                }

                $proveedorId = $producto?->proveedor_id ?? $heredaDe?->proveedor_id;
                $proveedor   = null;
                $provNombre  = trim($d['proveedor'] ?? '');
                if ($provNombre !== '') {
                    $proveedor = Proveedor::firstOrCreate(['nombre' => $provNombre], ['activo' => true]);
                    if ($proveedor->wasRecentlyCreated) $resultado['proveedores_creados'][] = $provNombre;
                    $proveedorId = $proveedor->id;
                }

                $esPadre = $this->esSi($d['es_padre'] ?? null, $producto?->es_padre ?? false);
                $costo   = $this->num($d['precio_costo'] ?? null, $producto?->precio_costo ?? $heredaDe?->precio_costo, 0);

                // Los precios por canal no van aquí: los escribe PreciosPorCanalService más
                // abajo, que también espeja las columnas viejas.
                $datos = [
                    'tipo'                   => $tipo,
                    'categoria_id'           => $categoriaId,
                    'proveedor_id'           => $proveedorId,
                    'nombre'                 => $nombre,
                    'unidad_medida'          => $this->texto($d['unidad_medida'] ?? null, $producto?->unidad_medida ?? $heredaDe?->unidad_medida, 'unidad'),
                    'descripcion_corta'      => $this->texto($d['descripcion_corta'] ?? null, $producto?->descripcion_corta),
                    'descripcion_larga'      => $this->texto($d['descripcion_larga'] ?? null, $producto?->descripcion_larga),
                    'descripcion_cotizacion' => $this->texto($d['descripcion_cotizacion'] ?? null, $producto?->descripcion_cotizacion),
                    'es_vendible'            => $this->esSi($d['es_vendible'] ?? null, $producto?->es_vendible ?? true),
                    'es_insumo'              => $this->esSi($d['es_insumo'] ?? null, $producto?->es_insumo ?? false),
                    'inventariable'          => $esPadre ? false : $this->esSi($d['inventariable'] ?? null, $producto?->inventariable ?? ($tipo === 'producto')),
                    'activo'                 => $this->esSi($d['activo'] ?? null, $producto?->activo ?? true),
                    'stock_minimo'           => $this->num($d['stock_minimo'] ?? null, $producto?->stock_minimo, 0),
                    'stock_maximo'           => $this->num($d['stock_maximo'] ?? null, $producto?->stock_maximo, 0),
                    'precio_costo'           => $costo,
                    'utilidad_minima_empresa_pct' => $this->num($d['utilidad_minima_empresa_pct'] ?? null, $producto?->utilidad_minima_empresa_pct, 15),
                ];

                if ($esVariante) {
                    $datos['es_padre']          = false;
                    $datos['producto_padre_id'] = $padre->id;
                    $datos['atributo_variante'] = null;
                    $datos['valor_variante']    = $this->texto($d['valor_variante'] ?? null, $producto?->valor_variante);
                } elseif ($esPadre) {
                    $datos['es_padre']          = true;
                    $datos['producto_padre_id'] = null;
                    $datos['atributo_variante'] = $this->texto($d['atributo_variante'] ?? null, $producto?->atributo_variante);
                    $datos['valor_variante']    = null;
                    $datos['inventariable']     = false;
                } else {
                    $datos['es_padre']          = false;
                    $datos['producto_padre_id'] = null;
                    $datos['atributo_variante'] = null;
                    $datos['valor_variante']    = null;
                }

                if ($esNuevo) {
                    if ($referencia === '') {
                        $referencia = $esVariante
                            ? Producto::generarReferenciaVariante($padre, $datos['valor_variante'] ?? $nombre)
                            : Producto::generarReferencia($tipo);
                    }
                    $datos['referencia'] = $referencia;
                    $producto = Producto::create($datos);
                    $resultado['creados']++;

                    // Stock inicial solo al crear — si se reimporta el mismo
                    // archivo después, no se vuelve a sumar el stock.
                    $stockInicial = $this->numero($d['stock_inicial'] ?? null) ?? 0;
                    if ($stockInicial > 0 && ! $esPadre) {
                        $bodegaNombre = trim($d['bodega'] ?? '');
                        $bodega = $bodegaNombre !== ''
                            ? Bodega::where('nombre', $bodegaNombre)->first()
                            : Bodega::principal();
                        $bodega ??= Bodega::principal();

                        if ($bodega) {
                            $producto->registrarMovimiento(
                                tipo: 'entrada',
                                cantidad: $stockInicial,
                                bodegaId: $bodega->id,
                                usuarioId: auth()->id(),
                                origenTipo: 'importacion_csv',
                                notas: 'Stock inicial por importación CSV'
                            );
                        }
                    }
                } else {
                    $producto->update($datos);
                    $resultado['actualizados']++;
                }

                $this->guardarCanales($producto, $heredaDe, $d, $canales, $esNuevo);

                if ($proveedor) {
                    $this->guardarProveedor($producto, $proveedor, $d, $costo);
                }
            });
        } catch (\Throwable $e) {
            $resultado['errores'][] = ['fila' => $numero, 'motivo' => $e->getMessage()];
        }
    }

    /**
     * Arma y guarda los precios de cada canal para una fila.
     *
     * Por canal y por campo, lo que manda es:
     *
     * - **El precio**: el de la celda. Si no hay, se calcula con el costo y el margen, igual
     *   que la ficha. Si no cambió ni el costo ni el margen, se deja el que tenía.
     * - **El margen**: si la fila trae un precio y hay costo, se deduce de ese precio. Es a
     *   propósito: la ficha recalcula el precio desde el margen cada vez que se abre, así que
     *   un precio importado con un margen que no le corresponde cambiaría solo la próxima
     *   vez que alguien guarde el producto.
     * - **Comisiones y descuento**: los de la celda; si no, los que ya tenía; y en un producto
     *   nuevo, los que propone «Sugerir comisiones».
     */
    private function guardarCanales(Producto $producto, ?Producto $heredaDe, array $d, $canales, bool $esNuevo): void
    {
        if ($canales->isEmpty()) {
            return;
        }

        $costo       = (float) $producto->precio_costo;
        $costoEnFila = $this->numero($d['precio_costo'] ?? null) !== null;
        $guardadas   = ($heredaDe ?? $producto)->preciosPorCanal()->get()->keyBy('segmentacion_opcion_id');

        $filas  = [];
        $celdas = [];

        foreach ($canales as $canal) {
            $celda = fn (string $campo) => $this->celdaDeCanal($d, $canal, $campo);
            $fila  = $guardadas->get($canal->id);

            $precioCelda = $celda('precio');
            $margenCelda = $celda('margen');
            $margen      = $margenCelda ?? ($fila ? (float) $fila->margen_pct : (float) $canal->margen_sugerido);

            if ($precioCelda !== null) {
                $precio = $precioCelda;
                if ($costo > 0 && $precio > 0) {
                    $margen = round(max(0, ($precio / $costo - 1) * 100), 2);
                }
            } elseif ($costo > 0 && ($costoEnFila || $margenCelda !== null || ! $fila || (float) $fila->precio <= 0)) {
                $precio = $this->precios->precioDesdeCosto($costo, $margen);
            } else {
                $precio = $fila ? (float) $fila->precio : 0.0;
            }

            $filas[] = [
                'segmentacion_opcion_id' => $canal->id,
                'es_canal_base'          => (bool) $canal->es_canal_base,
                'es_precio_publico'      => (bool) $canal->es_precio_publico,
                'margen_pct'             => $margen,
                'precio'                 => $precio,
            ];

            $celdas[$canal->id] = [
                'fila'              => $fila,
                'comision_min_pct'  => $celda('comision_min'),
                'comision_max_pct'  => $celda('comision_max'),
                'descuento_max_pct' => $celda('descuento_max'),
            ];
        }

        $sugeridas = collect($this->precios->sugerirComisiones($filas))->keyBy('segmentacion_opcion_id');

        foreach ($filas as $i => $fila) {
            $c = $celdas[$fila['segmentacion_opcion_id']];

            foreach (['comision_min_pct', 'comision_max_pct', 'descuento_max_pct'] as $campo) {
                $filas[$i][$campo] = $c[$campo]
                    ?? ($c['fila'] && ! $esNuevo ? (float) $c['fila']->{$campo} : null)
                    ?? (float) ($sugeridas[$fila['segmentacion_opcion_id']][$campo] ?? 0);
            }
        }

        $this->precios->guardar($producto, $filas);
    }

    /**
     * El valor de un campo de precio para un canal: la columna nueva, o la vieja del mismo
     * papel si la nueva no está o viene vacía. Así un CSV hecho con la plantilla anterior
     * —`precio_cliente_final`, `margen_mayorista`— sigue importando bien.
     */
    private function celdaDeCanal(array $d, SegmentacionOpcion $canal, string $campo): ?float
    {
        $valor = $this->numero($d["{$campo}_{$this->claveDe($canal)}"] ?? null);

        if ($valor === null && ($sufijo = $this->precios->columnaDe($canal))) {
            $valor = $this->numero($d["{$campo}_{$sufijo}"] ?? null);
        }

        return $valor;
    }

    /**
     * El proveedor de la fila entra a la lista de proveedores del producto.
     *
     * Antes solo se llenaba la columna `proveedor_id`, así que el producto importado
     * aparecía sin proveedores en su ficha y el comparador de compras no lo encontraba.
     */
    private function guardarProveedor(Producto $producto, Proveedor $proveedor, array $d, float $costo): void
    {
        $existente = ProductoProveedor::where('producto_id', $producto->id)
            ->where('proveedor_id', $proveedor->id)->first();

        ProductoProveedor::updateOrCreate(
            ['producto_id' => $producto->id, 'proveedor_id' => $proveedor->id],
            [
                'referencia_proveedor' => $this->texto($d['referencia_proveedor'] ?? null, $existente?->referencia_proveedor),
                'precio'               => $this->numero($d['precio_proveedor'] ?? null) ?? ($existente ? (float) $existente->precio : $costo),
                // Si es el único, es el preferido: es el que las órdenes de compra usan.
                'es_preferido'         => $existente?->es_preferido
                    ?? ! ProductoProveedor::where('producto_id', $producto->id)->exists(),
                'actualizado_el'       => now()->toDateString(),
            ]
        );
    }
}
