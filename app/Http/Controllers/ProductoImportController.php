<?php

namespace App\Http\Controllers;

use App\Models\Bodega;
use App\Models\CategoriaProducto;
use App\Models\Producto;
use App\Models\Proveedor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProductoImportController extends Controller
{
    /**
     * Columnas del CSV, en el orden exacto en que van en la plantilla.
     * Todas son opcionales excepto "nombre" — el resto cae a un valor por
     * defecto al crear, o se deja igual que estaba al actualizar.
     */
    private const COLUMNAS = [
        'nombre', 'tipo', 'referencia', 'categoria', 'proveedor', 'unidad_medida',
        'descripcion_corta', 'descripcion_larga',
        'es_vendible', 'es_insumo', 'inventariable', 'activo',
        'precio_costo',
        'margen_mayorista', 'margen_distribuidor', 'margen_cliente_final',
        'precio_mayorista', 'precio_distribuidor', 'precio_cliente_final',
        'comision_pct_minima', 'comision_pct_maxima',
        'comision_min_distribuidor', 'comision_max_distribuidor',
        'comision_min_cliente_final', 'comision_max_cliente_final',
        'utilidad_minima_empresa_pct',
        'descuento_max_cliente_final', 'descuento_max_distribuidor', 'descuento_max_mayorista',
        'stock_minimo', 'stock_maximo', 'stock_inicial', 'bodega',
        'es_padre', 'producto_padre', 'atributo_variante', 'valor_variante',
    ];

    public function index(): Response
    {
        return Inertia::render('Productos/Importar', [
            'columnas' => self::COLUMNAS,
        ]);
    }

    public function plantilla(): HttpResponse
    {
        $filas = [
            self::COLUMNAS,
            [
                'Cuarto frío modular 3x3', 'producto', '', 'Cuartos fríos', 'Proveedor de ejemplo', 'unidad',
                'Cuarto frío modular panel inyectado', '', 'Si', 'No', 'Si', 'Si',
                '3500000', '25', '30', '35',
                '', '', '',
                '', '', '', '', '', '',
                '15',
                '3', '5', '8',
                '1', '0', '5', 'Bodega principal',
                'No', '', '', '',
            ],
            [
                'Instalación de cuarto frío', 'servicio', '', 'Servicios', '', 'unidad',
                '', '', 'Si', 'No', 'No', 'Si',
                '', '', '', '',
                '800000', '', '',
                '', '', '', '', '', '',
                '',
                '', '', '',
                '', '', '', '',
                'No', '', '', '',
            ],
            [
                'Puerta batiente', 'producto', 'PROD-0100', 'Puertas', '', 'unidad',
                '', '', 'Si', 'No', 'Si', 'Si',
                '', '', '', '',
                '', '', '',
                '', '', '', '', '', '',
                '',
                '', '', '',
                '', '', '', '',
                'Si', '', 'Color', '',
            ],
            [
                'Puerta batiente — Blanco', 'producto', '', '', '', '',
                '', '', '', '', '', '',
                '', '', '', '',
                '', '', '',
                '', '', '', '', '', '',
                '',
                '', '', '',
                '', '', '3', 'Bodega principal',
                'No', 'PROD-0100', '', 'Blanco',
            ],
            [
                'Puerta batiente — Verde', 'producto', '', '', '', '',
                '', '', '', '', '', '',
                '', '', '', '',
                '', '', '',
                '', '', '', '', '', '',
                '',
                '', '', '',
                '', '', '5', 'Bodega principal',
                'No', 'PROD-0100', '', 'Verde',
            ],
        ];

        $handle = fopen('php://temp', 'w+');
        fwrite($handle, "\xEF\xBB\xBF"); // BOM — para que Excel abra los acentos bien
        foreach ($filas as $fila) {
            fputcsv($handle, $fila, ';');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="plantilla-productos.csv"',
        ]);
    }

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

        // Los padres/productos sueltos se procesan primero para que las
        // variantes (que dependen de "producto_padre") ya los encuentren.
        $normales  = array_filter($filas, fn ($f) => trim($f['datos']['producto_padre'] ?? '') === '');
        $variantes = array_filter($filas, fn ($f) => trim($f['datos']['producto_padre'] ?? '') !== '');

        foreach ($normales as $f) {
            $this->procesarFila($f['datos'], $f['numero'], $resultado);
        }
        foreach ($variantes as $f) {
            $this->procesarFila($f['datos'], $f['numero'], $resultado);
        }

        return response()->json($resultado);
    }

    private function esSi(?string $valor, bool $default): bool
    {
        if ($valor === null || trim($valor) === '') return $default;
        return in_array(mb_strtolower(trim($valor)), ['si', 'sí', '1', 'true', 'x'], true);
    }

    private function num(?string $valor, $existente, $default): float
    {
        if ($valor !== null && trim($valor) !== '') {
            return (float) str_replace(',', '.', $valor);
        }
        return (float) ($existente ?? $default);
    }

    private function texto(?string $valor, $existente, $default = null)
    {
        if ($valor !== null && trim($valor) !== '') {
            return trim($valor);
        }
        return $existente ?? $default;
    }

    private function procesarFila(array $d, int $numero, array &$resultado): void
    {
        try {
            DB::transaction(function () use ($d, &$resultado) {
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

                // Categoría / proveedor: por nombre de texto, se crean solos si no existen.
                $categoriaId = $producto?->categoria_id;
                $catNombre   = trim($d['categoria'] ?? '');
                if ($catNombre !== '') {
                    $cat = CategoriaProducto::firstOrCreate(['nombre' => $catNombre], ['activa' => true]);
                    if ($cat->wasRecentlyCreated) $resultado['categorias_creadas'][] = $catNombre;
                    $categoriaId = $cat->id;
                } elseif ($esNuevo && $esVariante) {
                    $categoriaId = $padre->categoria_id;
                }

                $proveedorId = $producto?->proveedor_id;
                $provNombre  = trim($d['proveedor'] ?? '');
                if ($provNombre !== '') {
                    $prov = Proveedor::firstOrCreate(['nombre' => $provNombre], ['activo' => true]);
                    if ($prov->wasRecentlyCreated) $resultado['proveedores_creados'][] = $provNombre;
                    $proveedorId = $prov->id;
                } elseif ($esNuevo && $esVariante) {
                    $proveedorId = $padre->proveedor_id;
                }

                $esPadre = $this->esSi($d['es_padre'] ?? null, $producto?->es_padre ?? false);

                $datos = [
                    'tipo'                => $tipo,
                    'categoria_id'        => $categoriaId,
                    'proveedor_id'        => $proveedorId,
                    'nombre'              => $nombre,
                    'unidad_medida'       => $this->texto($d['unidad_medida'] ?? null, $producto?->unidad_medida, 'unidad'),
                    'descripcion_corta'   => $this->texto($d['descripcion_corta'] ?? null, $producto?->descripcion_corta),
                    'descripcion_larga'   => $this->texto($d['descripcion_larga'] ?? null, $producto?->descripcion_larga),
                    'es_vendible'         => $this->esSi($d['es_vendible'] ?? null, $producto?->es_vendible ?? true),
                    'es_insumo'           => $this->esSi($d['es_insumo'] ?? null, $producto?->es_insumo ?? false),
                    'inventariable'       => $esPadre ? false : $this->esSi($d['inventariable'] ?? null, $producto?->inventariable ?? ($tipo === 'producto')),
                    'activo'              => $this->esSi($d['activo'] ?? null, $producto?->activo ?? true),
                    'stock_minimo'                => $this->num($d['stock_minimo'] ?? null, $producto?->stock_minimo, 0),
                    'stock_maximo'                => $this->num($d['stock_maximo'] ?? null, $producto?->stock_maximo, 0),
                    'precio_costo'                => $this->num($d['precio_costo'] ?? null, $producto?->precio_costo, 0),
                    'margen_mayorista'            => $this->num($d['margen_mayorista'] ?? null, $producto?->margen_mayorista, 25),
                    'margen_distribuidor'         => $this->num($d['margen_distribuidor'] ?? null, $producto?->margen_distribuidor, 30),
                    'margen_cliente_final'        => $this->num($d['margen_cliente_final'] ?? null, $producto?->margen_cliente_final, 35),
                    'precio_mayorista'            => $this->num($d['precio_mayorista'] ?? null, $producto?->precio_mayorista, 0),
                    'precio_distribuidor'         => $this->num($d['precio_distribuidor'] ?? null, $producto?->precio_distribuidor, 0),
                    'precio_cliente_final'        => $this->num($d['precio_cliente_final'] ?? null, $producto?->precio_cliente_final, 0),
                    'comision_pct_minima'         => $this->num($d['comision_pct_minima'] ?? null, $producto?->comision_pct_minima, 0),
                    'comision_pct_maxima'         => $this->num($d['comision_pct_maxima'] ?? null, $producto?->comision_pct_maxima, 0),
                    'comision_min_distribuidor'   => $this->num($d['comision_min_distribuidor'] ?? null, $producto?->comision_min_distribuidor, 0),
                    'comision_max_distribuidor'   => $this->num($d['comision_max_distribuidor'] ?? null, $producto?->comision_max_distribuidor, 0),
                    'comision_min_cliente_final'  => $this->num($d['comision_min_cliente_final'] ?? null, $producto?->comision_min_cliente_final, 0),
                    'comision_max_cliente_final'  => $this->num($d['comision_max_cliente_final'] ?? null, $producto?->comision_max_cliente_final, 0),
                    'utilidad_minima_empresa_pct' => $this->num($d['utilidad_minima_empresa_pct'] ?? null, $producto?->utilidad_minima_empresa_pct, 15),
                    'descuento_max_cliente_final' => $this->num($d['descuento_max_cliente_final'] ?? null, $producto?->descuento_max_cliente_final, 3),
                    'descuento_max_distribuidor'  => $this->num($d['descuento_max_distribuidor'] ?? null, $producto?->descuento_max_distribuidor, 5),
                    'descuento_max_mayorista'     => $this->num($d['descuento_max_mayorista'] ?? null, $producto?->descuento_max_mayorista, 8),
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
                    $stockInicial = (float) str_replace(',', '.', $d['stock_inicial'] ?? '0');
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
            });
        } catch (\Throwable $e) {
            $resultado['errores'][] = ['fila' => $numero, 'motivo' => $e->getMessage()];
        }
    }
}
