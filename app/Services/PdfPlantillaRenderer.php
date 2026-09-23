<?php

namespace App\Services;

use App\Models\PdfPlantilla;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Convierte una plantilla (guardada o en edición) en PDF. Es el ÚNICO camino:
 * la vista previa y los documentos reales pasan por aquí.
 *
 * Mientras fueron dos, la vista previa armaba los bloques visuales y el papel
 * personalizado, y la cotización y la OP leían solo `html` con `setPaper()`:
 * una plantilla hecha en modo visual salía en blanco y una etiqueta de 10×15
 * reventaba dompdf. Lo que se veía en el editor no era lo que salía.
 *
 * El motor es dompdf, así que el CSS es el de dompdf: CSS 2.1, tablas en vez de
 * flex/grid, `position: fixed` para lo que se repite en cada página, y los
 * contador `counter(page)` para numerar (el total de páginas se calcula aparte).
 */
class PdfPlantillaRenderer
{
    public const PAPELES = ['a4', 'a5', 'a3', 'letter', 'legal', 'half-letter', 'etiqueta-10x13',
        'etiqueta-10x15', 'ticket-80', 'tarjeta', 'personalizado'];

    /** Los campos de una plantilla que importan para dibujarla. */
    public static function desdeModelo(PdfPlantilla $p): array
    {
        return $p->only(['modo_editor', 'html', 'html_header', 'html_footer',
            'bloques_header', 'bloques_body', 'bloques_footer',
            'alto_header_mm', 'alto_footer_mm', 'margen_mm',
            'papel', 'orientacion', 'ancho_mm', 'alto_mm']);
    }

    /** @return array{html: string, errores: string[], desconocidas: string[]} */
    public static function html(array $p, array $datos): array
    {
        // `modo_editor` nació con 'visual' por omisión: las plantillas de código
        // anteriores a esa columna quedaron marcadas como visuales y sin bloques.
        $sinBloques = empty($p['bloques_header']) && empty($p['bloques_body']) && empty($p['bloques_footer']);
        $visual = ($p['modo_editor'] ?? 'codigo') === 'visual' && ! ($sinBloques && trim($p['html'] ?? '') !== '');

        if ($visual) {
            $partes = [
                BloquesHtmlService::toHtml($p['bloques_header'] ?? []),
                BloquesHtmlService::toHtml($p['bloques_body'] ?? []),
                BloquesHtmlService::toHtml($p['bloques_footer'] ?? []),
            ];
            $cssBase = BloquesHtmlService::css();
        } else {
            $partes  = [$p['html_header'] ?? '', $p['html'] ?? '', $p['html_footer'] ?? ''];
            $cssBase = '';
        }

        $errores = [];
        $desconocidas = [];
        $render = [];
        foreach (['encabezado', 'cuerpo', 'pie'] as $i => $slot) {
            $r = PdfPlantillaMotor::analizar((string) $partes[$i], $datos);
            $render[] = $r['html'];
            foreach ($r['errores'] as $e) $errores[] = ucfirst($slot) . ': ' . $e;
            $desconocidas = [...$desconocidas, ...$r['desconocidas']];
        }

        return [
            'html'         => static::componer($render[0], $render[1], $render[2], $p, $cssBase),
            'errores'      => $errores,
            'desconocidas' => array_values(array_unique($desconocidas)),
        ];
    }

    /**
     * Arma el documento con encabezado y pie fijos.
     *
     * dompdf repite en cada página lo que va en `position: fixed`, siempre que
     * esté ANTES del contenido en el <body>. El margen de la página deja el hueco
     * donde se dibujan, y por eso encabezado y pie van en coordenadas negativas.
     * Si el cuerpo trae su propio documento completo (<html>…), sus estilos se
     * conservan y su <body> se mete adentro.
     */
    public static function componer(string $header, string $body, string $footer, array $p = [], string $cssBase = ''): string
    {
        $margen = (int) ($p['margen_mm'] ?? 12);

        $cssDoc = '';
        if (preg_match('/<body[^>]*>(.*)<\/body>/is', $body, $m)) {
            preg_match_all('/<style[^>]*>(.*?)<\/style>/is', $body, $estilos);
            $cssDoc = implode("\n", $estilos[1]);
            $body   = $m[1];
        }
        foreach ([&$header, &$footer] as &$slot) {
            preg_match_all('/<style[^>]*>(.*?)<\/style>/is', $slot, $estilos);
            $cssDoc .= "\n" . implode("\n", $estilos[1]);
            $slot = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $slot);
        }
        unset($slot);

        $hayHeader = trim(strip_tags($header, '<img><svg><table><span>')) !== '';
        $hayFooter = trim(strip_tags($footer, '<img><svg><table><span>')) !== '';
        $altoH = $hayHeader ? (int) ($p['alto_header_mm'] ?? 25) : 0;
        $altoF = $hayFooter ? (int) ($p['alto_footer_mm'] ?? 15) : 0;

        // 12 mm es el margen que dompdf ponía por su cuenta: las plantillas viejas,
        // sin encabezado ni pie, salen exactamente igual que antes.
        $top    = $margen + $altoH;
        $bottom = $margen + $altoF;

        $fijos = ($hayHeader ? "<div id=\"pdf-encabezado\">{$header}</div>" : '')
            . ($hayFooter ? "<div id=\"pdf-pie\">{$footer}</div>" : '');

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8"/>
<style>
@page { margin: {$top}mm {$margen}mm {$bottom}mm {$margen}mm; }
body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 10px; color: #1a1a1a; }
#pdf-encabezado { position: fixed; top: -{$altoH}mm; left: 0; right: 0; height: {$altoH}mm; overflow: hidden; }
#pdf-pie { position: fixed; bottom: -{$altoF}mm; left: 0; right: 0; height: {$altoF}mm; overflow: hidden; }
.salto-pagina { page-break-after: always; height: 0; }
.evitar-corte, tr { page-break-inside: avoid; }
thead { display: table-header-group; }
.pdf-pagina:after { content: counter(page); }
{$cssBase}
{$cssDoc}
</style>
</head>
<body>
{$fijos}
{$body}
</body>
</html>
HTML;
    }

    /** El PDF listo para descargar o mostrar. */
    public static function pdf(array $p, array $datos): \Barryvdh\DomPDF\PDF
    {
        $html = static::html($p, $datos)['html'];
        $pdf  = static::cargar($html, $p);

        // dompdf pinta `counter(pages)` en cero. El total se averigua dibujando una
        // vez, y se escribe como número en la segunda. Solo cuando se usa.
        $marca = '<span class="pdf-total-paginas"></span>';
        if (str_contains($html, $marca)) {
            $pdf->render();
            $total = $pdf->getDomPDF()->getCanvas()->get_page_count();
            $pdf = static::cargar(str_replace($marca, (string) $total, $html), $p);
        }

        return $pdf;
    }

    private static function cargar(string $html, array $p): \Barryvdh\DomPDF\PDF
    {
        $pdf = Pdf::loadHtml($html);
        static::aplicarPapel($pdf, $p['papel'] ?? 'a4', $p['orientacion'] ?? 'portrait', $p['ancho_mm'] ?? null, $p['alto_mm'] ?? null);
        return $pdf;
    }

    /** Busca la plantilla del módulo y arma el PDF del registro; null si no hay plantilla. */
    public static function paraRegistro(string $modulo, mixed $registro, ?int $plantillaId = null): ?\Barryvdh\DomPDF\PDF
    {
        $plantilla = $plantillaId
            ? PdfPlantilla::where('modulo', $modulo)->find($plantillaId)
            : PdfPlantilla::defaultParaModulo($modulo);

        if (! $plantilla) {
            return null;
        }

        return static::pdf(static::desdeModelo($plantilla), PdfVariablesEngine::prepararDatos($modulo, $registro));
    }

    public static function aplicarPapel($pdf, string $papel, string $orientacion, ?int $anchoMm, ?int $altoMm): void
    {
        if (in_array($papel, ['a4', 'a5', 'a3', 'letter', 'legal'], true)) {
            $pdf->setPaper($papel, $orientacion);
            return;
        }

        [$ancho, $alto] = match ($papel) {
            'etiqueta-10x13' => [100, 130],
            'etiqueta-10x15' => [100, 150],
            'ticket-80'      => [80, 200],
            'tarjeta'        => [85, 55],
            'half-letter'    => [140, 216],
            default          => [$anchoMm ?: 210, $altoMm ?: 297],
        };

        $mm = 72 / 25.4;
        $pdf->setPaper([0, 0, round($ancho * $mm), round($alto * $mm)], $orientacion);
    }
}
