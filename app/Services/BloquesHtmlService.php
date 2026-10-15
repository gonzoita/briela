<?php

namespace App\Services;

use App\Support\Marca;

class BloquesHtmlService
{
    /**
     * `$opciones` son las de la plantilla (agrupar_secciones, mostrar_fotos, mostrar_precios) y
     * `secciones`, cuántas secciones trae la cotización. Sin opciones, sale como siempre.
     */
    public static function toHtml(array $bloques, array $opciones = []): string
    {
        $html = '';
        foreach ($bloques as $bloque) {
            // Sin precios, el bloque de totales no tiene nada que decir.
            if (($bloque['tipo'] ?? '') === 'totales' && ! ($opciones['mostrar_precios'] ?? true)) {
                continue;
            }
            $html .= static::bloqueToHtml($bloque, $opciones);
        }
        return $html;
    }

    /** Los estilos de los bloques visuales. */
    public static function css(): string
    {
        // El color sale de la configuración de la instalación: estas plantillas
        // las imprime dompdf, que no resuelve variables CSS.
        $marcaColor = Marca::color();

        return "
  table { width: 100%; border-collapse: collapse; }
  th { background: {$marcaColor}; color: white; padding: 6px 8px; text-align: left; font-size: 9px; }
  td { padding: 5px 8px; border-bottom: 1px solid #eee; font-size: 9px; }
  #pdf-encabezado { border-bottom: 2px solid {$marcaColor}; }
  #pdf-pie { border-top: 1px solid #ddd; }
  .col-wrap { display: table; width: 100%; }
  .col-2-cell { display: table-cell; width: 50%; vertical-align: top; padding: 4px; }
  .col-3-cell { display: table-cell; width: 33.33%; vertical-align: top; padding: 4px; }
  .firma-box { border-top: 1px solid #333; margin-top: 40px; padding-top: 4px; text-align: center; font-size: 9px; }
  .totales-box { text-align: right; margin-top: 8px; }
  .qr-box { text-align: center; }
  .separador { border: none; border-top: 1px solid #ddd; margin: 8px 0; }
  .sello { border: 2px solid {$marcaColor}; border-radius: 50%; width: 80px; height: 80px; display: inline-block; text-align: center; line-height: 80px; font-size: 9px; color: {$marcaColor}; }";
    }

    private static function bloqueToHtml(array $bloque, array $opciones = []): string
    {
        $tipo   = $bloque['tipo'] ?? 'texto';
        $props  = $bloque['props'] ?? [];
        $estilo = static::estiloInline($props);

        return match ($tipo) {
            'texto' => "<p style='margin:4px 0;{$estilo}'>" . htmlspecialchars($props['contenido'] ?? '', ENT_QUOTES) . "</p>",

            'titulo' => (function () use ($props, $estilo) {
                $nivel     = (int) ($props['nivel'] ?? 2);
                $contenido = htmlspecialchars($props['contenido'] ?? '', ENT_QUOTES);
                return "<h{$nivel} style='color:{$marcaColor};margin:8px 0 4px;{$estilo}'>{$contenido}</h{$nivel}>";
            })(),

            'imagen' => !empty($props['src'])
                ? (function () use ($props) {
                    $src = $props['src'];
                    if (!str_starts_with($src, 'data:') && !str_starts_with($src, 'http')) {
                        $src = config('app.url') . $src;
                    }
                    $alin  = $props['alineacion'] ?? 'left';
                    $ancho = $props['ancho'] ?? 200;
                    $alto  = !empty($props['alto'])
                        ? "height:{$props['alto']}px;object-fit:cover;"
                        : 'height:auto;';
                    return "<div style='text-align:{$alin};margin:8px 0;'>"
                        . "<img src=\"{$src}\" style='width:{$ancho}px;{$alto}'/>"
                        . "</div>";
                })()
                : '',

            'separador' => "<hr style='border:none;border-top:" . ($props['grosor'] ?? 1) . "px solid " . ($props['color'] ?? '#dddddd') . ";margin:8px 0;'/>",

            'spacer' => "<div style='height:" . ($props['alto'] ?? 20) . "px;'></div>",

            'variable' => "<span style='{$estilo}'>{{{$props['variable']}}}</span>",

            'columnas' => static::columnaToHtml($bloque),

            'tabla' => static::tablaToHtml($bloque, $opciones),

            'totales' => static::totalesToHtml($bloque),

            'qr' => "<div style='text-align:" . ($props['alineacion'] ?? 'center') . ";margin:8px 0;'>"
                  . "<img src='{{!op.qr_imagen}}' style='width:" . ($props['tamanio'] ?? 80) . "px;height:" . ($props['tamanio'] ?? 80) . "px;'/>"
                  . (!empty($props['etiqueta']) ? "<p style='font-size:8px;margin:2px 0;'>" . htmlspecialchars($props['etiqueta'], ENT_QUOTES) . "</p>" : '')
                  . "</div>",

            'firma' => "<div style='margin-top:" . ($props['espacio_superior'] ?? 40) . "px;'>"
                     . "<div class='firma-box'>"
                     . (!empty($props['nombre']) ? "<p>" . htmlspecialchars($props['nombre'], ENT_QUOTES) . "</p>" : '')
                     . (!empty($props['cargo']) ? "<p style='color:#666;'>" . htmlspecialchars($props['cargo'], ENT_QUOTES) . "</p>" : '')
                     . "</div></div>",

            'sello' => "<div style='text-align:" . ($props['alineacion'] ?? 'center') . ";margin:8px 0;'>"
                     . "<div class='sello'>" . htmlspecialchars($props['texto'] ?? '', ENT_QUOTES) . "</div>"
                     . "</div>",

            'lista_items' => "{{#items}}<p style='margin:2px 0;font-size:9px;'>• {{descripcion}} — Cant: {{cantidad}}</p>{{/items}}",

            default => '',
        };
    }

    private static function columnaToHtml(array $bloque): string
    {
        $props   = $bloque['props'] ?? [];
        $numCols = (int) ($props['columnas'] ?? 2);
        $clase   = $numCols === 3 ? 'col-3-cell' : 'col-2-cell';
        $cols    = $props['contenido_columnas'] ?? [];
        $html    = "<div class='col-wrap'>";
        foreach ($cols as $col) {
            $html .= "<div class='{$clase}'>";
            foreach ($col['bloques'] ?? [] as $subBloque) {
                $html .= static::bloqueToHtml($subBloque, $opciones);
            }
            $html .= "</div>";
        }
        $html .= "</div>";
        return $html;
    }

    /**
     * La tabla de productos. Con `agrupar_secciones`, un bloque por sección —su título, sus líneas y
     * su subtotal—, armado con bucles propios (`seccion_1_items`…) que prepara `PdfVariablesEngine`.
     * Así no hace falta un bucle anidado. Sin foto o sin precios se quitan esas columnas.
     */
    private static function tablaToHtml(array $bloque, array $opciones = []): string
    {
        $props   = $bloque['props'] ?? [];
        $columnas = $props['columnas'] ?? [
            ['etiqueta' => '#',           'variable' => 'index'],
            ['etiqueta' => 'Descripción', 'variable' => 'descripcion'],
            ['etiqueta' => 'Cant.',       'variable' => 'cantidad'],
            ['etiqueta' => 'P. Unit.',    'variable' => 'precio_unitario|moneda'],
            ['etiqueta' => 'Total',       'variable' => 'total_linea|moneda'],
        ];

        if (! ($opciones['mostrar_fotos'] ?? true)) {
            $columnas = array_values(array_filter($columnas, fn ($c) => ! str_starts_with((string) ($c['variable'] ?? ''), 'imagen')));
        }
        $conPrecios = $opciones['mostrar_precios'] ?? true;
        if (! $conPrecios) {
            $columnas = array_values(array_filter($columnas, fn ($c) => ! preg_match('/precio|total|subtotal|descuento|comision|impuesto/', (string) ($c['variable'] ?? ''))));
        }
        if ($columnas === []) {
            $columnas = [['etiqueta' => 'Descripción', 'variable' => 'descripcion']];
        }
        $n = count($columnas);

        $ths = implode('', array_map(fn ($c) => '<th' . (!empty($c['ancho']) ? " style='width:{$c['ancho']}px;'" : '') . '>' . htmlspecialchars($c['etiqueta'] ?? '', ENT_QUOTES) . '</th>', $columnas));
        // Debajo de la primera descripción va, si la hay, la entrega de lo que viene en tránsito.
        $conEntrega = false;
        $tds = implode('', array_map(function ($c) use (&$conEntrega) {
            $var  = (string) ($c['variable'] ?? '');
            $mas  = '';
            if (! $conEntrega && str_starts_with($var, 'descripcion')) {
                $mas = '{{!entrega_html}}';
                $conEntrega = true;
            }

            return '<td' . (!empty($c['ancho']) ? " style='width:{$c['ancho']}px;'" : '') . '>{{' . $var . '}}' . $mas . '</td>';
        }, $columnas));

        $cuerpo = "{{#items}}\n    <tr>{$tds}</tr>\n    {{/items}}";

        $secciones = (int) ($opciones['secciones'] ?? 0);
        if (($opciones['agrupar_secciones'] ?? false) && $secciones > 0) {
            $cuerpo = '';
            for ($k = 1; $k <= $secciones; $k++) {
                $cuerpo .= "<tr class='seccion-titulo'><td colspan='{$n}' style='background:#f3f4f6;font-weight:bold;'>{{seccion_{$k}_nombre}}</td></tr>"
                    . "{{#seccion_{$k}_items}}<tr>{$tds}</tr>{{/seccion_{$k}_items}}";
                if ($conPrecios) {
                    $cuerpo .= "<tr class='seccion-subtotal'><td colspan='" . max(1, $n - 1) . "' style='text-align:right;font-style:italic;'>Subtotal {{seccion_{$k}_nombre}}</td>"
                        . "<td style='font-weight:bold;'>{{seccion_{$k}_subtotal|moneda}}</td></tr>";
                }
            }
        }

        return "<table>
  <thead><tr>{$ths}</tr></thead>
  <tbody>
    {$cuerpo}
  </tbody>
</table>";
    }

    private static function totalesToHtml(array $bloque): string
    {
        $marcaColor = Marca::color();
        $props = $bloque['props'] ?? [];
        $filas = $props['filas'] ?? [
            ['etiqueta' => 'Subtotal', 'variable' => 'cotizacion.subtotal|moneda', 'destacado' => false],
            ['etiqueta' => 'TOTAL',    'variable' => 'cotizacion.total|moneda',    'destacado' => true],
        ];

        $html = "<div class='totales-box'>";
        foreach ($filas as $fila) {
            $estilo = !empty($fila['destacado'])
                ? "font-size:13px;font-weight:bold;color:{$marcaColor};"
                : "font-size:9px;";
            $etiqueta = htmlspecialchars($fila['etiqueta'] ?? '', ENT_QUOTES);
            $variable = $fila['variable'] ?? '';
            $html .= "<p style='margin:2px 0;{$estilo}'>{$etiqueta}: <strong>{{{$variable}}}</strong></p>";
        }
        // En otra moneda, a qué tasa: un precio en dólares sin la tasa es una discusión segura.
        $html .= "{{#if cotizacion.nota_moneda}}<p style='margin:4px 0 0;font-size:8px;color:#6B7280;'>{{cotizacion.nota_moneda}}</p>{{/if}}";
        $html .= "</div>";
        return $html;
    }

    private static function estiloInline(array $props): string
    {
        $estilos = [];
        if (!empty($props['color']))          $estilos[] = "color:{$props['color']}";
        if (!empty($props['font_size']))      $estilos[] = "font-size:{$props['font_size']}px";
        if (!empty($props['alineacion']))     $estilos[] = "text-align:{$props['alineacion']}";
        if (!empty($props['negrita']))        $estilos[] = "font-weight:bold";
        if (!empty($props['italica']))        $estilos[] = "font-style:italic";
        if (isset($props['margin_top']))      $estilos[] = "margin-top:{$props['margin_top']}px";
        if (isset($props['margin_bottom']))   $estilos[] = "margin-bottom:{$props['margin_bottom']}px";
        return implode(';', $estilos);
    }
}
