<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Las plantillas PDF de fábrica imprimen las firmas.
 *
 * La remisión, la cotización y la orden de producción salían con cajas de firma vacías: la
 * firma de la remisión se guardaba y el PDF no la ponía, porque la plantilla de fábrica no
 * tenía dónde. Esto cambia **solo los trozos de fábrica**, y solo si siguen tal cual: si la
 * empresa ya editó su plantilla, no se toca —su versión manda—, y puede agregar las firmas
 * desde el editor con `{{!vendedor.firma}}`, `{{!responsable.firma}}`, `{{!firma.despacho}}` y
 * `{{!firma.recibido}}`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pdf_plantillas')) {
            return;
        }

        foreach (self::cambios() as $modulo => $reemplazos) {
            foreach (DB::table('pdf_plantillas')->where('modulo', $modulo)->get(['id', 'html']) as $plantilla) {
                $html = (string) $plantilla->html;

                foreach ($reemplazos as [$antes, $despues]) {
                    if (str_contains($html, $antes)) {
                        $html = str_replace($antes, $despues, $html);
                    }
                }

                if ($html !== $plantilla->html) {
                    DB::table('pdf_plantillas')->where('id', $plantilla->id)->update(['html' => $html, 'updated_at' => now()]);
                }
            }
        }
    }

    public function down(): void
    {
        // Hacia adelante solamente.
    }

    /** @return array<string, array<int, array{0:string,1:string}>> */
    public static function cambios(): array
    {
        return [
            'remision' => [
                [
                    <<<'HTML'
    <div style="height:36px;"></div>
    <div class="firma-box">
      <p style="margin:2px 0;font-weight:bold;">{{empresa.nombre}}</p>
      <p style="margin:0;font-size:8px;color:#666;">Entrega conforme</p>
HTML,
                    <<<'HTML'
    <div style="height:58px;text-align:center;">{{!firma.despacho}}</div>
    <div class="firma-box">
      <p style="margin:2px 0;font-weight:bold;">{{despachado_por.nombre}}</p>
      <p style="margin:0;font-size:8px;color:#666;">Entrega conforme &mdash; {{empresa.nombre}}</p>
HTML,
                ],
                [
                    <<<'HTML'
    <div style="height:36px;"></div>
    <div class="firma-box">
      <p style="margin:2px 0;font-weight:bold;">{{cliente.nombre}}</p>
      <p style="margin:0;font-size:8px;color:#666;">Recibe conforme &mdash; C.C.: _____________</p>
HTML,
                    <<<'HTML'
    <div style="height:58px;text-align:center;">{{!firma.recibido}}</div>
    <div class="firma-box">
      <p style="margin:2px 0;font-weight:bold;">{{cliente.nombre}}</p>
      <p style="margin:0;font-size:8px;color:#666;">Recibe conforme{{#if recibido_por.nombre}}: {{recibido_por.nombre}}{{else}} &mdash; C.C.: _____________{{/if}}</p>
HTML,
                ],
                [
                    '      <td style="font-family:Courier,monospace;font-size:8px;">{{serie}}</td>',
                    '      <td style="font-family:Courier,monospace;font-size:8px;">{{serie}}{{#if pendiente}}<div style="font-family:Helvetica,Arial,sans-serif;color:#92400e;font-weight:bold;">{{pendiente}}</div>{{/if}}</td>',
                ],
            ],
            'cotizacion' => [
                [
                    <<<'HTML'
    <div style="height:30px;"></div>
    <div class="firma-linea">
      <p style="margin:2px 0;font-weight:bold;">{{vendedor.nombre}}</p>
HTML,
                    <<<'HTML'
    <div style="height:56px;text-align:center;">{{!vendedor.firma}}</div>
    <div class="firma-linea">
      <p style="margin:2px 0;font-weight:bold;">{{vendedor.nombre}}</p>
HTML,
                ],
            ],
            'op' => [
                [
                    <<<'HTML'
<div class="footer">
  <div class="row">
    <div class="col">{{empresa.nombre}} &nbsp;|&nbsp; {{empresa.ciudad}}</div>
    <div class="col" style="text-align:right;">{{op.numero}} &nbsp;|&nbsp; {{empresa.nombre}}</div>
HTML,
                    <<<'HTML'
<div class="row" style="margin-top:24px;">
  <div class="col" style="width:45%;">
    <div style="height:56px;text-align:center;">{{!responsable.firma}}</div>
    <div style="border-top:1px solid #555;padding-top:4px;text-align:center;font-size:8px;color:#555;margin:0 16px;">
      <p style="margin:2px 0;font-weight:bold;">{{responsable.nombre}}</p>
      <p style="margin:0;color:#888;">Responsable de la orden</p>
    </div>
  </div>
</div>

<div class="footer">
  <div class="row">
    <div class="col">{{empresa.nombre}} &nbsp;|&nbsp; {{empresa.ciudad}}</div>
    <div class="col" style="text-align:right;">{{op.numero}} &nbsp;|&nbsp; {{empresa.nombre}}</div>
HTML,
                ],
            ],
        ];
    }
};
