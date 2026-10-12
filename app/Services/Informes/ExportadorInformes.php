<?php

namespace App\Services\Informes;

use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exporta un resultado del motor a CSV o a Excel.
 *
 * El CSV va como lo abre Excel en español sin preguntar nada: UTF-8 con BOM (si no, las tildes
 * salen rotas), separador `;` (con coma, Excel en Colombia mete todo en una sola columna) y los
 * decimales con coma. Las fechas en AAAA-MM-DD, que Excel reconoce en cualquier configuración.
 *
 * Un texto que empieza por `=`, `+`, `-` o `@` se escribe con un apóstrofo delante: un nombre que
 * alguien llenó en un formulario público como «=HIPERVINCULO(…)» sería una fórmula al abrir el
 * archivo (inyección de CSV).
 */
class ExportadorInformes
{
    /**
     * @param  string|null  $tabla  la dimensión de un resumen; null para el detalle
     */
    public function csv(array $resultado, string $nombre, ?string $tabla = null): StreamedResponse
    {
        [$columnas, $filas, $total] = $this->tablaDe($resultado, $tabla);

        $archivo = Str::slug($nombre).($tabla ? '-'.Str::slug($tabla) : '').'.csv';

        return response()->streamDownload(function () use ($columnas, $filas, $total) {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\xEF\xBB\xBF");

            fputcsv($salida, array_column($columnas, 'etiqueta'), ';');

            foreach ($filas as $fila) {
                fputcsv($salida, array_map(fn ($c) => self::valorCsv($fila[$c['clave']] ?? null, $c['tipo']), $columnas), ';');
            }

            if ($total) {
                fputcsv($salida, array_map(fn ($c) => self::valorCsv($total[$c['clave']] ?? null, $c['tipo']), $columnas), ';');
            }

            fclose($salida);
        }, $archivo, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * El libro completo: Resumen (cifras clave y análisis), Detalle y una hoja por cada resumen.
     * Devuelve null si el servidor no puede escribir .xlsx; quien llama ofrece el CSV.
     */
    public function xlsx(array $resultado, string $nombre, ?string $analisis = null, ?string $pregunta = null): ?Response
    {
        if (! EscritorXlsx::disponible()) {
            return null;
        }

        $libro = new EscritorXlsx();

        $encabezado = array_values(array_filter([
            $nombre,
            ($resultado['fuente_etiqueta'] ?? '').' · '.($resultado['periodo'] ?? ''),
            $pregunta ? "Pregunta: {$pregunta}" : null,
            'Generado el '.now()->format('d/m/Y H:i').' · '.number_format($resultado['total_filas'] ?? 0, 0, ',', '.').' filas'
                .(! empty($resultado['truncado']) ? ' (se muestran las primeras '.number_format(MotorInformes::MAX_FILAS, 0, ',', '.').')' : ''),
        ]));

        $libro->tabla('Resumen',
            [['clave' => 'etiqueta', 'etiqueta' => 'Cifra', 'tipo' => 'texto'], ['clave' => 'valor', 'etiqueta' => 'Valor', 'tipo' => 'mixto']],
            array_map(fn ($m) => ['etiqueta' => $m['etiqueta'], 'valor' => $m['valor']], $this->metricasTipadas($resultado['metricas'] ?? [])),
            null, $encabezado, false,
        );

        if ($analisis) {
            $libro->texto('Análisis', 'Análisis', preg_split('/\R{2,}|\R(?=[-•*] )/u', trim($analisis)) ?: [$analisis]);
        }

        $libro->tabla('Detalle', $resultado['columnas'] ?? [], $resultado['filas'] ?? []);

        foreach ($resultado['resumenes'] ?? [] as $r) {
            $libro->tabla(Str::ucfirst($r['titulo']), $r['columnas'], $r['filas'], $r['total'] ?? null);
        }

        return response($libro->contenido(), 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.Str::slug($nombre).'.xlsx"',
        ]);
    }

    /**
     * Convierte un informe de las fuentes de siempre (ops, cotizaciones, colaboradores, pasos)
     * a la forma del motor, para exportarlo igual.
     */
    public static function desdeLegado(string $fuente, array $campos, array $etiquetas, array $filas, array $totales): array
    {
        $columnas = array_map(fn ($c) => ['clave' => $c, 'etiqueta' => $etiquetas[$c] ?? $c, 'tipo' => 'texto'], $campos);

        return [
            'fuente'          => $fuente,
            'fuente_etiqueta' => Str::ucfirst($fuente),
            'periodo'         => 'Según los filtros del informe',
            'sobre'           => '',
            'columnas'        => $columnas,
            'filas'           => $filas,
            'total_filas'     => count($filas),
            'truncado'        => false,
            'metricas'        => collect($totales)->map(fn ($t, $c) => [
                'clave' => $c, 'etiqueta' => ($t['tipo'] === 'promedio' ? 'Promedio de ' : 'Total de ').mb_strtolower($etiquetas[$c] ?? $c),
                'valor' => $t['texto'], 'tipo' => 'texto',
            ])->values()->all(),
            'resumenes'       => [],
            // La fila de totales que el CSV de siempre traía al final.
            'total_detalle'   => $totales ? collect($campos)->mapWithKeys(fn ($c) => [$c => isset($totales[$c])
                ? ($totales[$c]['tipo'] === 'promedio' ? 'Prom: ' : 'Total: ').$totales[$c]['texto'] : ''])->all() : null,
        ];
    }

    // ─── Por dentro ──────────────────────────────────────────────────────────

    private function tablaDe(array $resultado, ?string $tabla): array
    {
        if ($tabla) {
            foreach ($resultado['resumenes'] ?? [] as $r) {
                if ($r['dimension'] === $tabla) {
                    return [$r['columnas'], $r['filas'], $r['total'] ?? null];
                }
            }
        }

        return [$resultado['columnas'] ?? [], $resultado['filas'] ?? [], $resultado['total_detalle'] ?? null];
    }

    /** Las cifras clave con su valor ya listo para una celda de Excel. */
    private function metricasTipadas(array $metricas): array
    {
        return array_map(function ($m) {
            $v = $m['valor'];

            $m['valor'] = match (true) {
                $v === null                              => '—',
                $m['tipo'] === 'moneda' && is_numeric($v) => '$ '.number_format((float) $v, 0, ',', '.'),
                $m['tipo'] === 'pct' && is_numeric($v)    => str_replace('.', ',', (string) $v).' %',
                $m['tipo'] === 'numero' && is_numeric($v) => number_format((float) $v, 1, ',', '.'),
                is_numeric($v)                            => number_format((float) $v, 0, ',', '.'),
                default                                   => (string) $v,
            };

            return $m;
        }, $metricas);
    }

    public static function valorCsv(mixed $valor, string $tipo): string
    {
        if ($valor === null) {
            return '';
        }

        if ($tipo === 'bool' || is_bool($valor)) {
            return $valor ? 'Sí' : 'No';
        }

        if (in_array($tipo, ['moneda', 'numero', 'entero', 'pct'], true) && is_numeric($valor)) {
            // Sin separador de miles y con coma decimal: Excel en español lo lee como número.
            $texto = (string) (0 + $valor);

            return str_replace('.', ',', $texto);
        }

        $texto = (string) $valor;

        return preg_match('/^[=+\-@\t\r]/', $texto) ? "'".$texto : $texto;
    }
}
