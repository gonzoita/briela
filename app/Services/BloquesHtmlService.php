<?php

namespace App\Services;

use App\Support\Marca;

class BloquesHtmlService
{
    public static function toHtml(array $bloques): string
    {
        $html = '';
        foreach ($bloques as $bloque) {
            $html .= static::bloqueToHtml($bloque);
        }
        return $html;
    }

    public static function plantillaToHtml(array $header, array $body, array $footer): string
    {
        $htmlHeader = static::toHtml($header);
        $htmlBody   = static::toHtml($body);
        $htmlFooter = static::toHtml($footer);

        // El color sale de la configuración de la instalación: estas plantillas
        // las imprime dompdf, que no resuelve variables CSS.
        $marcaColor = Marca::color();

        return "<!DOCTYPE html>
<html>
<head>
<meta charset='utf-8'/>
<style>
  body { font-family: Arial, sans-serif; font-size: 10px; color: #1a1a1a; margin: 0; padding: 0; }
  .seccion-header { padding: 16px 20px 8px; border-bottom: 2px solid {$marcaColor}; }
  .seccion-body   { padding: 12px 20px; }
  .seccion-footer { padding: 8px 20px 16px; border-top: 1px solid #ddd; margin-top: 16px; }
  table { width: 100%; border-collapse: collapse; }
  th { background: {$marcaColor}; color: white; padding: 6px 8px; text-align: left; font-size: 9px; }
  td { padding: 5px 8px; border-bottom: 1px solid #eee; font-size: 9px; }
  .col-wrap { display: table; width: 100%; }
  .col-2-cell { display: table-cell; width: 50%; vertical-align: top; padding: 4px; }
  .col-3-cell { display: table-cell; width: 33.33%; vertical-align: top; padding: 4px; }
  .firma-box { border-top: 1px solid #333; margin-top: 40px; padding-top: 4px; text-align: center; font-size: 9px; }
  .totales-box { text-align: right; margin-top: 8px; }
  .qr-box { text-align: center; }
  .separador { border: none; border-top: 1px solid #ddd; margin: 8px 0; }
  .sello { border: 2px solid {$marcaColor}; border-radius: 50%; width: 80px; height: 80px; display: inline-block; text-align: center; line-height: 80px; font-size: 9px; color: {$marcaColor}; }
</style>
</head>
<body>
<div class='seccion-header'>{$htmlHeader}</div>
<div class='seccion-body'>{$htmlBody}</div>
<div class='seccion-footer'>{$htmlFooter}</div>
</body>
</html>";
    }

    private static function bloqueToHtml(array $bloque): string
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

            'tabla' => static::tablaToHtml($bloque),

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
                $html .= static::bloqueToHtml($subBloque);
            }
            $html .= "</div>";
        }
        $html .= "</div>";
        return $html;
    }

    private static function tablaToHtml(array $bloque): string
    {
        $props   = $bloque['props'] ?? [];
        $columnas = $props['columnas'] ?? [
            ['etiqueta' => '#',           'variable' => 'index'],
            ['etiqueta' => 'Descripción', 'variable' => 'descripcion'],
            ['etiqueta' => 'Cant.',       'variable' => 'cantidad'],
            ['etiqueta' => 'P. Unit.',    'variable' => 'precio_unitario|moneda'],
            ['etiqueta' => 'Total',       'variable' => 'total_linea|moneda'],
        ];

        $ths = implode('', array_map(fn ($c) => '<th' . (!empty($c['ancho']) ? " style='width:{$c['ancho']}px;'" : '') . '>' . htmlspecialchars($c['etiqueta'] ?? '', ENT_QUOTES) . '</th>', $columnas));
        $tds = implode('', array_map(fn ($c) => '<td' . (!empty($c['ancho']) ? " style='width:{$c['ancho']}px;'" : '') . '>{{' . ($c['variable'] ?? '') . '}}</td>', $columnas));

        return "<table>
  <thead><tr>{$ths}</tr></thead>
  <tbody>
    {{#items}}
    <tr>{$tds}</tr>
    {{/items}}
  </tbody>
</table>";
    }

    private static function totalesToHtml(array $bloque): string
    {
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
