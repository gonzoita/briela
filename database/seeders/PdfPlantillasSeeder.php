<?php

namespace Database\Seeders;

use App\Models\PdfPlantilla;
use Illuminate\Database\Seeder;

class PdfPlantillasSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->plantillas() as $data) {
            PdfPlantilla::updateOrCreate(
                ['modulo' => $data['modulo'], 'nombre' => $data['nombre']],
                $data
            );
        }
    }

    private function plantillas(): array
    {
        return [
            ['modulo' => 'cotizacion',       'nombre' => 'Cotización Corporativa',   'descripcion' => 'Encabezado azul, tabla de items, totales y firmas', 'papel' => 'a4', 'orientacion' => 'portrait',  'es_default' => true, 'html' => $this->cotizacion()],
            ['modulo' => 'op',               'nombre' => 'Orden de Producción',       'descripcion' => 'OP con items serializados y estado',                'papel' => 'a4', 'orientacion' => 'portrait',  'es_default' => true, 'html' => $this->op()],
            ['modulo' => 'remision',         'nombre' => 'Nota de Remisión',          'descripcion' => 'Remisión con transporte y cajas de firma',          'papel' => 'a4', 'orientacion' => 'portrait',  'es_default' => true, 'html' => $this->remision()],
            ['modulo' => 'recibo_pago',      'nombre' => 'Recibo de Caja',            'descripcion' => 'Recibo formal con valor destacado',                 'papel' => 'a4', 'orientacion' => 'portrait',  'es_default' => true, 'html' => $this->reciboPago()],
            ['modulo' => 'trabajo',          'nombre' => 'Hoja de Trabajo',           'descripcion' => 'Pasos de producción con seguimiento',               'papel' => 'a4', 'orientacion' => 'portrait',  'es_default' => true, 'html' => $this->trabajo()],
            ['modulo' => 'op_etiqueta',      'nombre' => 'Etiqueta de Item',          'descripcion' => 'Etiqueta compacta A5 con serie y OP',               'papel' => 'a5', 'orientacion' => 'landscape', 'es_default' => true, 'html' => $this->etiquetaOp()],
            ['modulo' => 'llamado_atencion', 'nombre' => 'Llamado de Atención',       'descripcion' => 'Carta formal de llamado verbal o escrito',          'papel' => 'a4', 'orientacion' => 'portrait',  'es_default' => true, 'html' => $this->llamadoAtencion()],
            ['modulo' => 'comision',         'nombre' => 'Liquidación de Comisiones', 'descripcion' => 'Detalle de comisiones por vendedor',                'papel' => 'a4', 'orientacion' => 'portrait',  'es_default' => true, 'html' => $this->comision()],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    private function cotizacion(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8"/>
<style>
body{font-family:Arial,sans-serif;font-size:10px;color:#1a1a1a;margin:0;padding:24px 28px;}
.row{display:table;width:100%;}
.col{display:table-cell;vertical-align:top;}
.nombre-empresa{font-size:17px;font-weight:bold;color:{{empresa.color}};margin:0 0 2px 0;}
.info-empresa{font-size:9px;color:#666;margin:0;}
.badge{background:{{empresa.color}};color:white;font-size:14px;font-weight:bold;padding:5px 14px;display:inline-block;}
.hr-azul{border:none;border-top:3px solid {{empresa.color}};margin:10px 0 14px 0;}
.caja-cliente{background:#EEF4FF;border-left:3px solid {{empresa.color}};padding:9px 11px;}
.etiq{font-size:8px;color:#888;font-weight:bold;text-transform:uppercase;margin:0 0 2px 0;}
.v-bold{font-size:11px;font-weight:bold;margin:0 0 2px 0;}
.v-sm{font-size:9px;color:#555;margin:0 0 1px 0;}
.sec{font-size:9px;font-weight:bold;color:{{empresa.color}};text-transform:uppercase;border-bottom:1px solid {{empresa.color}};padding-bottom:3px;margin:14px 0 6px 0;}
table{width:100%;border-collapse:collapse;margin-bottom:10px;}
th{background:{{empresa.color}};color:white;padding:6px 7px;font-size:9px;text-align:left;}
td{padding:5px 7px;border-bottom:1px solid #eee;font-size:9px;vertical-align:top;}
.tr{text-align:right;}
.tc{text-align:center;}
.tl-row{display:table;width:100%;padding:3px 0;border-bottom:1px solid #eee;}
.tl-lbl{display:table-cell;font-size:9px;color:#666;}
.tl-val{display:table-cell;text-align:right;font-size:9px;}
.total-bar{background:{{empresa.color}};padding:0;}
.tb-lbl{display:table-cell;font-size:11px;font-weight:bold;color:white;padding:7px 10px;}
.tb-val{display:table-cell;text-align:right;font-size:14px;font-weight:bold;color:white;padding:7px 10px;}
.caja-info{background:#f8f9fa;border:1px solid #e9ecef;padding:8px;font-size:9px;color:#333;margin-bottom:8px;}
.firma-linea{border-top:1px solid #555;padding-top:4px;text-align:center;font-size:8px;color:#555;margin:0 16px;}
.footer{border-top:1px solid #ddd;margin-top:16px;padding-top:6px;font-size:8px;color:#aaa;}
</style>
</head>
<body>

<div class="row" style="padding-bottom:10px;">
  <div class="col">
    <p class="nombre-empresa">{{empresa.nombre}}</p>
    <p class="info-empresa">NIT: {{empresa.nit}} &nbsp;|&nbsp; {{empresa.ciudad}} &nbsp;|&nbsp; Tel: {{empresa.tel}}</p>
  </div>
  <div class="col" style="text-align:right;">
    <div class="badge">COTIZACIÓN</div>
    <p style="margin:4px 0 1px 0;font-size:13px;font-weight:bold;color:{{empresa.color}};">{{cotizacion.numero}}</p>
    <p style="margin:0;font-size:9px;color:#666;">Fecha: {{cotizacion.fecha|fecha}} &nbsp;|&nbsp; Válida {{cotizacion.validez}} días</p>
  </div>
</div>
<hr class="hr-azul"/>

<div class="row" style="margin-bottom:14px;">
  <div class="col" style="width:60%;">
    <div class="caja-cliente">
      <p class="etiq">Dirigida a</p>
      <p class="v-bold">{{cliente.nombre}}</p>
      <p class="v-sm">NIT/CC: {{cliente.nit}}</p>
      <p class="v-sm">{{cliente.direccion}} &mdash; {{cliente.ciudad}}</p>
      <p class="v-sm">Tel: {{cliente.telefono}} &nbsp;|&nbsp; Cel: {{cliente.celular}}</p>
      <p class="v-sm">{{cliente.email}}</p>
    </div>
  </div>
  <div class="col" style="width:40%;padding-left:16px;">
    <p class="etiq">Asesor comercial</p>
    <p style="font-size:10px;font-weight:bold;color:{{empresa.color}};margin:2px 0 8px 0;">{{vendedor.nombre}}</p>
    <p class="etiq">Empresa</p>
    <p class="v-sm" style="margin-top:2px;">{{empresa.nombre}}</p>
    <p class="v-sm">{{empresa.ciudad}}</p>
  </div>
</div>

<p class="sec">Productos y Servicios</p>
<table>
  <thead>
    <tr>
      <th style="width:4%;" class="tc">#</th>
      <th style="width:46%;">Descripción</th>
      <th style="width:8%;text-align:right;">Cant.</th>
      <th style="width:14%;text-align:right;">P. Unit.</th>
      <th style="width:8%;text-align:right;">Desc%</th>
      <th style="width:14%;text-align:right;">Total</th>
    </tr>
  </thead>
  <tbody>
    {{#items}}
    <tr>
      <td class="tc">{{index}}</td>
      <td>{{descripcion}}</td>
      <td class="tr">{{cantidad}}</td>
      <td class="tr">{{precio_unitario|moneda}}</td>
      <td class="tr">{{descuento_pct}}%</td>
      <td class="tr"><strong>{{total_linea|moneda}}</strong></td>
    </tr>
    {{/items}}
  </tbody>
</table>

<div class="row">
  <div class="col" style="width:55%;">
    {{#if cotizacion.condiciones}}
    <p class="etiq" style="margin-bottom:4px;">Condiciones de pago</p>
    <div class="caja-info">{{cotizacion.condiciones}}</div>
    {{/if}}
    {{#if cotizacion.notas}}
    <p class="etiq" style="margin-bottom:4px;">Notas adicionales</p>
    <div class="caja-info">{{cotizacion.notas}}</div>
    {{/if}}
  </div>
  <div class="col" style="width:45%;padding-left:20px;">
    <div class="tl-row"><div class="tl-lbl">Subtotal:</div><div class="tl-val">{{cotizacion.subtotal|moneda}}</div></div>
    <div class="tl-row"><div class="tl-lbl">Descuento:</div><div class="tl-val">- {{cotizacion.descuento_total|moneda}}</div></div>
    <div class="tl-row"><div class="tl-lbl">IVA:</div><div class="tl-val">{{cotizacion.impuesto_total|moneda}}</div></div>
    <div class="total-bar" style="margin-top:6px;">
      <div class="row"><div class="tb-lbl">TOTAL:</div><div class="tb-val">{{cotizacion.total|moneda}}</div></div>
    </div>
  </div>
</div>

<div class="row" style="margin-top:36px;">
  <div class="col" style="width:45%;">
    <div style="height:30px;"></div>
    <div class="firma-linea">
      <p style="margin:2px 0;font-weight:bold;">{{vendedor.nombre}}</p>
      <p style="margin:0;color:#888;">Asesor Comercial &mdash; {{empresa.nombre}}</p>
    </div>
  </div>
  <div class="col" style="width:10%;"></div>
  <div class="col" style="width:45%;">
    <div style="height:30px;"></div>
    <div class="firma-linea">
      <p style="margin:2px 0;font-weight:bold;">{{cliente.nombre}}</p>
      <p style="margin:0;color:#888;">NIT/CC: {{cliente.nit}}</p>
    </div>
  </div>
</div>

<div class="footer">
  <div class="row">
    <div class="col">{{empresa.nombre}} &nbsp;|&nbsp; {{empresa.ciudad}} &nbsp;|&nbsp; Tel: {{empresa.tel}}</div>
    <div class="col" style="text-align:right;">{{cotizacion.numero}} &nbsp;|&nbsp; {{empresa.nombre}}</div>
  </div>
</div>

</body>
</html>
HTML;
    }

    // ─────────────────────────────────────────────────────────────────────────
    private function op(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8"/>
<style>
body{font-family:Arial,sans-serif;font-size:10px;color:#1a1a1a;margin:0;padding:22px 26px;}
.row{display:table;width:100%;}
.col{display:table-cell;vertical-align:top;}
.nombre{font-size:17px;font-weight:bold;color:{{empresa.color}};margin:0 0 2px 0;}
.info{font-size:9px;color:#666;margin:0;}
.badge-op{background:{{empresa.color}};color:white;font-size:20px;font-weight:bold;padding:6px 18px;display:inline-block;letter-spacing:1px;}
.hr{border:none;border-top:3px solid {{empresa.color}};margin:10px 0 14px 0;}
.caja{background:#f4f7fb;border:1px solid #d0dff0;padding:9px 11px;margin-bottom:10px;}
.etiq{font-size:8px;color:#888;font-weight:bold;text-transform:uppercase;margin:0 0 2px 0;}
.v-bold{font-size:11px;font-weight:bold;margin:0 0 2px 0;}
.v-sm{font-size:9px;color:#555;margin:0 0 1px 0;}
.dato-row{display:table;width:100%;margin-bottom:3px;}
.dato-lbl{display:table-cell;width:38%;font-size:9px;color:#888;}
.dato-val{display:table-cell;font-size:9px;font-weight:bold;color:#333;}
.sec{font-size:9px;font-weight:bold;color:{{empresa.color}};text-transform:uppercase;border-bottom:1px solid {{empresa.color}};padding-bottom:3px;margin:12px 0 6px 0;}
table{width:100%;border-collapse:collapse;margin-bottom:10px;}
th{background:{{empresa.color}};color:white;padding:6px 7px;font-size:9px;text-align:left;}
td{padding:5px 7px;border-bottom:1px solid #eee;font-size:9px;vertical-align:top;}
.estado-chip{background:#FEF9C3;color:#854D0E;padding:2px 8px;font-size:8px;font-weight:bold;}
.anticipo-box{background:#ECFDF5;border-left:3px solid #059669;padding:8px 10px;margin-top:10px;}
.footer{border-top:1px solid #ddd;margin-top:16px;padding-top:6px;font-size:8px;color:#aaa;}
</style>
</head>
<body>

<div class="row" style="padding-bottom:10px;">
  <div class="col">
    <p class="nombre">{{empresa.nombre}}</p>
    <p class="info">NIT: {{empresa.nit}} &nbsp;|&nbsp; {{empresa.ciudad}} &nbsp;|&nbsp; Tel: {{empresa.tel}}</p>
  </div>
  <div class="col" style="text-align:right;">
    <div class="badge-op">{{op.numero}}</div>
    <p style="margin:4px 0 0 0;font-size:9px;color:#666;">Orden de Producción</p>
    <p style="margin:2px 0 0 0;font-size:9px;color:#666;">Creada: {{op.fecha_creacion|fecha}}</p>
  </div>
</div>
<hr class="hr"/>

<div class="row" style="margin-bottom:14px;">
  <div class="col" style="width:55%;">
    <div class="caja">
      <p class="etiq">Cliente</p>
      <p class="v-bold">{{cliente.nombre}}</p>
      <p class="v-sm">{{cliente.ciudad}}</p>
      <p class="v-sm">Tel: {{cliente.telefono}} &nbsp;|&nbsp; Cel: {{cliente.celular}}</p>
    </div>
  </div>
  <div class="col" style="width:45%;padding-left:14px;">
    <div class="dato-row"><div class="dato-lbl">Responsable:</div><div class="dato-val">{{responsable.nombre}}</div></div>
    <div class="dato-row"><div class="dato-lbl">Fecha entrega:</div><div class="dato-val">{{op.fecha_entrega|fecha}}</div></div>
    <div class="dato-row"><div class="dato-lbl">Estado:</div>
      <div class="dato-val"><span class="estado-chip">{{op.estado}}</span></div>
    </div>
    <div class="dato-row" style="margin-top:6px;"><div class="dato-lbl">Total:</div><div class="dato-val" style="color:{{empresa.color}};font-size:11px;">{{op.total|moneda}}</div></div>
  </div>
</div>

<p class="sec">Ítems de Producción</p>
<table>
  <thead>
    <tr>
      <th style="width:18%;">Código</th>
      <th style="width:38%;">Descripción</th>
      <th style="width:8%;text-align:center;">Cant.</th>
      <th style="width:20%;">Serie</th>
      <th style="width:16%;">Estado</th>
    </tr>
  </thead>
  <tbody>
    {{#items}}
    <tr>
      <td style="font-family:Courier,monospace;font-size:8px;">{{codigo}}</td>
      <td>{{descripcion}}</td>
      <td style="text-align:center;">{{cantidad}}</td>
      <td style="font-family:Courier,monospace;font-size:8px;">{{serie}}</td>
      <td>{{estado_item}}</td>
    </tr>
    {{/items}}
  </tbody>
</table>

<div class="anticipo-box">
  <div class="row">
    <div class="col" style="width:50%;">
      <p class="etiq">Anticipo recibido</p>
      <p style="font-size:14px;font-weight:bold;color:#059669;margin:2px 0 0 0;">{{op.anticipo|moneda}}</p>
    </div>
    <div class="col" style="width:50%;">
      {{#if op.condiciones}}
      <p class="etiq">Condiciones</p>
      <p style="font-size:9px;color:#333;margin:2px 0 0 0;">{{op.condiciones}}</p>
      {{/if}}
    </div>
  </div>
</div>

{{#if op.notas_internas}}
<p class="sec" style="margin-top:10px;">Observaciones internas</p>
<div style="background:#fffbeb;border:1px solid #fde68a;padding:8px;font-size:9px;color:#92400e;">{{op.notas_internas}}</div>
{{/if}}

<div class="footer">
  <div class="row">
    <div class="col">{{empresa.nombre}} &nbsp;|&nbsp; {{empresa.ciudad}}</div>
    <div class="col" style="text-align:right;">{{op.numero}} &nbsp;|&nbsp; {{empresa.nombre}}</div>
  </div>
</div>

</body>
</html>
HTML;
    }

    // ─────────────────────────────────────────────────────────────────────────
    private function remision(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8"/>
<style>
body{font-family:Arial,sans-serif;font-size:10px;color:#1a1a1a;margin:0;padding:22px 26px;}
.row{display:table;width:100%;}
.col{display:table-cell;vertical-align:top;}
.nombre{font-size:16px;font-weight:bold;color:{{empresa.color}};margin:0 0 2px 0;}
.info{font-size:9px;color:#666;margin:0;}
.titulo{font-size:20px;font-weight:bold;color:{{empresa.color}};text-align:right;margin:0 0 2px 0;}
.hr{border:none;border-top:3px solid {{empresa.color}};margin:10px 0 14px 0;}
.etiq{font-size:8px;color:#888;font-weight:bold;text-transform:uppercase;margin:0 0 2px 0;}
.v-bold{font-size:10px;font-weight:bold;margin:0 0 2px 0;}
.v-sm{font-size:9px;color:#555;margin:0 0 1px 0;}
.caja{border:1px solid #d0d0d0;padding:9px 11px;}
.sec{font-size:9px;font-weight:bold;color:{{empresa.color}};text-transform:uppercase;border-bottom:1px solid {{empresa.color}};padding-bottom:3px;margin:12px 0 6px 0;}
table{width:100%;border-collapse:collapse;margin-bottom:12px;}
th{background:{{empresa.color}};color:white;padding:6px 7px;font-size:9px;text-align:left;}
td{padding:5px 7px;border-bottom:1px solid #eee;font-size:9px;}
.firma-box{border:1px solid #555;padding:6px 10px;text-align:center;}
.footer{border-top:1px solid #ddd;margin-top:16px;padding-top:6px;font-size:8px;color:#aaa;}
</style>
</head>
<body>

<div class="row" style="padding-bottom:10px;">
  <div class="col" style="width:55%;">
    <p class="nombre">{{empresa.nombre}}</p>
    <p class="info">NIT: {{empresa.nit}} &nbsp;|&nbsp; {{empresa.ciudad}} &nbsp;|&nbsp; Tel: {{empresa.tel}}</p>
  </div>
  <div class="col" style="width:45%;">
    <p class="titulo">NOTA DE REMISIÓN</p>
    <p style="text-align:right;margin:0;font-size:13px;font-weight:bold;color:#333;">{{remision.numero}}</p>
    <p style="text-align:right;margin:2px 0 0 0;font-size:9px;color:#666;">Fecha: {{remision.fecha|fecha}}</p>
  </div>
</div>
<hr class="hr"/>

<div class="row" style="margin-bottom:14px;">
  <div class="col" style="width:50%;padding-right:10px;">
    <div class="caja">
      <p class="etiq">Destinatario</p>
      <p class="v-bold">{{cliente.nombre}}</p>
      <p class="v-sm">{{cliente.direccion}}</p>
      <p class="etiq" style="margin-top:6px;">OP relacionada</p>
      <p class="v-bold">{{op.numero}}</p>
    </div>
  </div>
  <div class="col" style="width:50%;padding-left:10px;">
    <div class="caja">
      <p class="etiq">Transporte</p>
      <p class="v-bold">{{remision.transportista}}</p>
      <p class="etiq" style="margin-top:5px;">Placa vehículo</p>
      <p class="v-bold">{{remision.placa}}</p>
      <p class="etiq" style="margin-top:5px;">Tipo</p>
      <p class="v-sm">{{remision.tipo}}</p>
    </div>
  </div>
</div>

<p class="sec">Artículos Remisionados</p>
<table>
  <thead>
    <tr>
      <th style="width:4%;text-align:center;">#</th>
      <th style="width:52%;">Descripción</th>
      <th style="width:12%;text-align:center;">Cant.</th>
      <th style="width:32%;">N° Serie / Referencia</th>
    </tr>
  </thead>
  <tbody>
    {{#items}}
    <tr>
      <td style="text-align:center;">{{index}}</td>
      <td>{{descripcion}}</td>
      <td style="text-align:center;">{{cantidad}}</td>
      <td style="font-family:Courier,monospace;font-size:8px;">{{serie}}</td>
    </tr>
    {{/items}}
  </tbody>
</table>

{{#if remision.notas}}
<div style="background:#f8f9fa;border:1px solid #e9ecef;padding:8px;margin-bottom:14px;font-size:9px;">
  <strong>Observaciones:</strong> {{remision.notas}}
</div>
{{/if}}

<div class="row" style="margin-top:28px;">
  <div class="col" style="width:45%;">
    <div style="height:36px;"></div>
    <div class="firma-box">
      <p style="margin:2px 0;font-weight:bold;">{{empresa.nombre}}</p>
      <p style="margin:0;font-size:8px;color:#666;">Entrega conforme</p>
    </div>
  </div>
  <div class="col" style="width:10%;"></div>
  <div class="col" style="width:45%;">
    <div style="height:36px;"></div>
    <div class="firma-box">
      <p style="margin:2px 0;font-weight:bold;">{{cliente.nombre}}</p>
      <p style="margin:0;font-size:8px;color:#666;">Recibe conforme &mdash; C.C.: _____________</p>
    </div>
  </div>
</div>

<div class="footer">
  <div class="row">
    <div class="col">{{empresa.nombre}} &nbsp;|&nbsp; {{empresa.ciudad}}</div>
    <div class="col" style="text-align:right;">{{remision.numero}} &nbsp;|&nbsp; {{empresa.nombre}}</div>
  </div>
</div>

</body>
</html>
HTML;
    }

    // ─────────────────────────────────────────────────────────────────────────
    private function reciboPago(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8"/>
<style>
body{font-family:Arial,sans-serif;font-size:10px;color:#1a1a1a;margin:0;padding:22px 28px;}
.row{display:table;width:100%;}
.col{display:table-cell;vertical-align:top;}
.encabezado{background:{{empresa.color}};color:white;padding:14px 18px;margin-bottom:18px;}
.enc-titulo{font-size:20px;font-weight:bold;margin:0 0 2px 0;letter-spacing:1px;}
.enc-sub{font-size:11px;margin:0;opacity:0.85;}
.enc-num{font-size:24px;font-weight:bold;text-align:right;}
.monto-box{background:#EEF4FF;border:2px solid {{empresa.color}};padding:14px 20px;text-align:center;margin-bottom:18px;}
.monto-etiq{font-size:9px;color:#666;font-weight:bold;text-transform:uppercase;margin:0 0 4px 0;}
.monto-val{font-size:28px;font-weight:bold;color:{{empresa.color}};margin:0;}
.dato-row{display:table;width:100%;border-bottom:1px solid #eee;padding:5px 0;}
.dato-lbl{display:table-cell;width:35%;font-size:9px;color:#888;font-weight:bold;text-transform:uppercase;}
.dato-val{display:table-cell;font-size:10px;color:#333;}
.texto-legal{font-size:9px;color:#666;text-align:center;margin:16px 0;border-top:1px dashed #ccc;border-bottom:1px dashed #ccc;padding:8px 0;}
.firma-linea{border-top:1px solid #555;padding-top:4px;text-align:center;font-size:8px;color:#555;margin:0 24px;}
.footer{border-top:1px solid #ddd;margin-top:16px;padding-top:6px;font-size:8px;color:#aaa;text-align:center;}
</style>
</head>
<body>

<div class="encabezado">
  <div class="row">
    <div class="col">
      <p class="enc-titulo">RECIBO DE CAJA</p>
      <p class="enc-sub">{{empresa.nombre}} &mdash; NIT: {{empresa.nit}}</p>
    </div>
    <div class="col" style="text-align:right;">
      <p class="enc-num">{{pago.numero_recibo}}</p>
      <p style="margin:0;font-size:9px;opacity:0.85;">Fecha: {{pago.fecha_pago|fecha}}</p>
    </div>
  </div>
</div>

<div class="monto-box">
  <p class="monto-etiq">Valor recibido</p>
  <p class="monto-val">{{pago.valor|moneda}}</p>
</div>

<div class="dato-row">
  <div class="dato-lbl">Recibido de:</div>
  <div class="dato-val" style="font-weight:bold;">{{cliente.nombre}}</div>
</div>
<div class="dato-row">
  <div class="dato-lbl">Concepto:</div>
  <div class="dato-val">{{cuota.concepto}}</div>
</div>
<div class="dato-row">
  <div class="dato-lbl">OP relacionada:</div>
  <div class="dato-val" style="font-family:Courier,monospace;font-weight:bold;">{{op.numero}}</div>
</div>
<div class="dato-row">
  <div class="dato-lbl">Medio de pago:</div>
  <div class="dato-val">{{pago.medio_pago}}</div>
</div>
<div class="dato-row">
  <div class="dato-lbl">Referencia:</div>
  <div class="dato-val">{{pago.referencia}}</div>
</div>
<div class="dato-row">
  <div class="dato-lbl">Registrado por:</div>
  <div class="dato-val">{{registrado_por.nombre}}</div>
</div>

{{#if pago.notas}}
<div class="dato-row">
  <div class="dato-lbl">Notas:</div>
  <div class="dato-val">{{pago.notas}}</div>
</div>
{{/if}}

<div class="texto-legal">
  El presente recibo es prueba de pago parcial o total de la obligación mencionada.<br/>
  Conserve este documento como comprobante de su transacción.
</div>

<div class="row" style="margin-top:20px;">
  <div class="col" style="width:45%;">
    <div style="height:30px;"></div>
    <div class="firma-linea">
      <p style="margin:2px 0;font-weight:bold;">{{registrado_por.nombre}}</p>
      <p style="margin:0;color:#888;">Quien recibe &mdash; {{empresa.nombre}}</p>
    </div>
  </div>
  <div class="col" style="width:10%;"></div>
  <div class="col" style="width:45%;">
    <div style="height:30px;"></div>
    <div class="firma-linea">
      <p style="margin:2px 0;font-weight:bold;">{{cliente.nombre}}</p>
      <p style="margin:0;color:#888;">Quien paga</p>
    </div>
  </div>
</div>

<div class="footer">{{empresa.nombre}} &nbsp;|&nbsp; {{empresa.ciudad}} &nbsp;|&nbsp; {{pago.numero_recibo}} &nbsp;|&nbsp; {{empresa.nombre}}</div>

</body>
</html>
HTML;
    }

    // ─────────────────────────────────────────────────────────────────────────
    private function trabajo(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8"/>
<style>
body{font-family:Arial,sans-serif;font-size:10px;color:#1a1a1a;margin:0;padding:20px 24px;}
.row{display:table;width:100%;}
.col{display:table-cell;vertical-align:top;}
.nombre{font-size:15px;font-weight:bold;color:{{empresa.color}};margin:0 0 1px 0;}
.info{font-size:9px;color:#666;margin:0;}
.hr{border:none;border-top:3px solid {{empresa.color}};margin:8px 0 12px 0;}
.etiq{font-size:8px;color:#888;font-weight:bold;text-transform:uppercase;margin:0 0 1px 0;}
.v-bold{font-size:10px;font-weight:bold;margin:0 0 1px 0;}
.v-sm{font-size:9px;color:#555;margin:0 0 1px 0;}
.item-box{background:#f0f5ff;border-left:3px solid {{empresa.color}};padding:8px 11px;}
.operario-box{background:#f0fdf4;border-left:3px solid #059669;padding:8px 11px;}
.badge-trabajo{background:{{empresa.color}};color:white;font-size:9px;font-weight:bold;padding:2px 8px;display:inline-block;font-family:Courier,monospace;}
.sec{font-size:9px;font-weight:bold;color:{{empresa.color}};text-transform:uppercase;border-bottom:1px solid {{empresa.color}};padding-bottom:3px;margin:12px 0 6px 0;}
table{width:100%;border-collapse:collapse;margin-bottom:10px;}
th{background:{{empresa.color}};color:white;padding:6px 7px;font-size:9px;text-align:left;}
td{padding:5px 7px;border-bottom:1px solid #eee;font-size:9px;vertical-align:middle;}
.check-box{display:inline-block;width:12px;height:12px;border:1px solid #aaa;vertical-align:middle;}
.firma-linea{border-top:1px solid #555;padding-top:4px;text-align:center;font-size:8px;color:#555;margin:0 20px;}
.footer{border-top:1px solid #ddd;margin-top:14px;padding-top:5px;font-size:8px;color:#aaa;}
</style>
</head>
<body>

<div class="row" style="padding-bottom:8px;">
  <div class="col">
    <p class="nombre">{{empresa.nombre}}</p>
    <p class="info">NIT: {{empresa.nit}} &nbsp;|&nbsp; {{empresa.ciudad}}</p>
  </div>
  <div class="col" style="text-align:right;">
    <div class="badge-trabajo">{{trabajo.codigo}}</div>
    <p style="margin:3px 0 0 0;font-size:11px;font-weight:bold;color:{{empresa.color}};">HOJA DE TRABAJO</p>
    <p style="margin:1px 0 0 0;font-size:9px;color:#666;">OP: {{op.numero}}</p>
  </div>
</div>
<hr class="hr"/>

<div class="row" style="margin-bottom:12px;">
  <div class="col" style="width:60%;padding-right:10px;">
    <div class="item-box">
      <p class="etiq">Item a producir</p>
      <p class="v-bold">{{item.descripcion}}</p>
      <p class="v-sm" style="font-family:Courier,monospace;">{{item.codigo}}</p>
      <p class="v-sm">OP: <strong>{{op.numero}}</strong></p>
    </div>
  </div>
  <div class="col" style="width:40%;">
    <div class="operario-box">
      <p class="etiq">Operario asignado</p>
      <p class="v-bold">{{operario.nombre}}</p>
      <p class="etiq" style="margin-top:6px;">Trabajo</p>
      <p class="v-sm">{{trabajo.nombre}}</p>
    </div>
  </div>
</div>

<p class="sec">Pasos a Ejecutar</p>
<table>
  <thead>
    <tr>
      <th style="width:5%;text-align:center;">#</th>
      <th style="width:30%;">Paso</th>
      <th style="width:45%;">Instrucciones</th>
      <th style="width:12%;text-align:center;">Peso</th>
      <th style="width:8%;text-align:center;">OK</th>
    </tr>
  </thead>
  <tbody>
    {{#pasos}}
    <tr>
      <td style="text-align:center;color:#888;">{{index}}</td>
      <td><strong>{{nombre}}</strong></td>
      <td style="color:#555;">{{descripcion_resuelta}}</td>
      <td style="text-align:center;">{{peso_porcentaje}}%</td>
      <td style="text-align:center;"><span class="check-box">&nbsp;</span></td>
    </tr>
    {{/pasos}}
  </tbody>
</table>

<div style="background:#f8f9fa;border:1px solid #eee;padding:8px 10px;margin-top:8px;">
  <p class="etiq" style="margin-bottom:4px;">Observaciones del operario</p>
  <div style="height:36px;border-bottom:1px dashed #ccc;"></div>
</div>

<div class="row" style="margin-top:28px;">
  <div class="col" style="width:45%;">
    <div style="height:30px;"></div>
    <div class="firma-linea">
      <p style="margin:2px 0;font-weight:bold;">{{operario.nombre}}</p>
      <p style="margin:0;color:#888;">Operario</p>
    </div>
  </div>
  <div class="col" style="width:10%;"></div>
  <div class="col" style="width:45%;">
    <div style="height:30px;"></div>
    <div class="firma-linea">
      <p style="margin:2px 0;font-weight:bold;">Jefe de Producción</p>
      <p style="margin:0;color:#888;">{{empresa.nombre}}</p>
    </div>
  </div>
</div>

<div class="footer">
  <div class="row">
    <div class="col">{{empresa.nombre}} &nbsp;|&nbsp; {{empresa.ciudad}}</div>
    <div class="col" style="text-align:right;">{{trabajo.codigo}} &nbsp;|&nbsp; {{empresa.nombre}}</div>
  </div>
</div>

</body>
</html>
HTML;
    }

    // ─────────────────────────────────────────────────────────────────────────
    private function etiquetaOp(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8"/>
<style>
body{font-family:Arial,sans-serif;font-size:10px;color:#1a1a1a;margin:0;padding:12px 14px;}
.etiqueta{border:2px solid {{empresa.color}};padding:10px 12px;max-width:380px;}
.row{display:table;width:100%;}
.col{display:table-cell;vertical-align:top;}
.op-numero{font-size:22px;font-weight:bold;color:{{empresa.color}};margin:0 0 2px 0;letter-spacing:1px;font-family:Courier,monospace;}
.empresa{font-size:9px;color:#666;margin:0 0 8px 0;}
.hr{border:none;border-top:2px solid {{empresa.color}};margin:6px 0;}
.etiq{font-size:7px;color:#888;font-weight:bold;text-transform:uppercase;margin:0 0 1px 0;}
.serie{font-size:16px;font-weight:bold;font-family:Courier,monospace;color:#1a1a1a;margin:2px 0;}
.descripcion{font-size:10px;font-weight:bold;color:#333;margin:0 0 2px 0;}
.cliente{font-size:9px;color:#555;margin:0;}
.fecha{font-size:9px;color:{{empresa.color}};font-weight:bold;margin:0;}
.codigo-item{font-size:8px;font-family:Courier,monospace;color:#666;margin:0;}
</style>
</head>
<body>
<div class="etiqueta">

  <p class="op-numero">{{op.numero}}</p>
  <p class="empresa">{{empresa.nombre}}</p>

  <hr class="hr"/>

  <div class="row" style="margin-bottom:6px;">
    <div class="col" style="width:65%;">
      <p class="etiq">Descripción</p>
      <p class="descripcion">{{item.descripcion}}</p>
      <p class="codigo-item">{{item.codigo}}</p>
    </div>
    <div class="col" style="width:35%;text-align:right;">
      <p class="etiq">Entrega</p>
      <p class="fecha">{{op.fecha_entrega|fecha}}</p>
    </div>
  </div>

  <hr class="hr"/>

  <div class="row">
    <div class="col" style="width:60%;">
      <p class="etiq">N° de serie</p>
      <p class="serie">{{item.serie}}</p>
    </div>
    <div class="col" style="width:40%;padding-left:8px;">
      <p class="etiq">Cliente</p>
      <p class="cliente">{{cliente.nombre}}</p>
    </div>
  </div>

  <div style="background:{{empresa.color}};color:white;text-align:center;padding:3px;margin-top:8px;font-size:7px;font-weight:bold;letter-spacing:0.5px;">
    {{empresa.nombre}}
  </div>

</div>
</body>
</html>
HTML;
    }

    // ─────────────────────────────────────────────────────────────────────────
    private function llamadoAtencion(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8"/>
<style>
body{font-family:Arial,sans-serif;font-size:10px;color:#1a1a1a;margin:0;padding:28px 34px;}
.row{display:table;width:100%;}
.col{display:table-cell;vertical-align:top;}
.nombre{font-size:16px;font-weight:bold;color:{{empresa.color}};margin:0 0 1px 0;}
.info{font-size:9px;color:#666;margin:0;}
.hr-azul{border:none;border-top:3px solid {{empresa.color}};margin:10px 0 18px 0;}
.titulo{font-size:16px;font-weight:bold;color:{{empresa.color}};text-align:center;text-transform:uppercase;letter-spacing:1px;margin:0 0 4px 0;}
.subtitulo{font-size:10px;text-align:center;color:#666;margin:0 0 18px 0;}
.tipo-badge{display:inline-block;padding:4px 14px;font-size:10px;font-weight:bold;text-transform:uppercase;border:2px solid {{empresa.color}};color:{{empresa.color}};margin:0 0 18px 0;}
.dato-row{display:table;width:100%;border-bottom:1px solid #eee;padding:5px 0;margin-bottom:2px;}
.dato-lbl{display:table-cell;width:28%;font-size:9px;color:#888;font-weight:bold;text-transform:uppercase;}
.dato-val{display:table-cell;font-size:10px;color:#333;font-weight:bold;}
.cuerpo{font-size:10px;color:#333;line-height:16px;margin:0 0 14px 0;}
.caja-motivo{background:#FFF7ED;border-left:3px solid #EA580C;padding:10px 12px;margin:12px 0;}
.caja-desc{background:#f8f9fa;border:1px solid #e9ecef;padding:10px 12px;margin:8px 0;}
.firma-linea{border-top:1px solid #333;padding-top:5px;text-align:center;font-size:9px;color:#333;margin:0 10px;}
.footer{border-top:1px solid #ddd;margin-top:20px;padding-top:6px;font-size:8px;color:#aaa;text-align:center;}
</style>
</head>
<body>

<div class="row" style="padding-bottom:10px;">
  <div class="col">
    <p class="nombre">{{empresa.nombre}}</p>
    <p class="info">NIT: {{empresa.nit}} &nbsp;|&nbsp; {{empresa.ciudad}}</p>
  </div>
  <div class="col" style="text-align:right;">
    <p class="info">{{empresa.ciudad}}, {{llamado.fecha|fecha}}</p>
  </div>
</div>
<hr class="hr-azul"/>

<p class="titulo">Llamado de Atención</p>
<p class="subtitulo">Comunicado Disciplinario Interno</p>
<div style="text-align:center;margin-bottom:16px;">
  <span class="tipo-badge">Tipo: {{llamado.tipo}}</span>
</div>

<div class="dato-row"><div class="dato-lbl">Colaborador:</div><div class="dato-val">{{colaborador.nombre}}</div></div>
<div class="dato-row"><div class="dato-lbl">Cargo:</div><div class="dato-val">{{colaborador.cargo}}</div></div>
<div class="dato-row"><div class="dato-lbl">Fecha:</div><div class="dato-val">{{llamado.fecha|fecha}}</div></div>

<p style="font-size:9px;font-weight:bold;color:{{empresa.color}};text-transform:uppercase;margin:16px 0 4px 0;border-bottom:1px solid {{empresa.color}};padding-bottom:3px;">Motivo</p>
<div class="caja-motivo">
  <p style="margin:0;font-size:10px;font-weight:bold;color:#EA580C;">{{llamado.motivo}}</p>
</div>

<p style="font-size:9px;font-weight:bold;color:{{empresa.color}};text-transform:uppercase;margin:14px 0 4px 0;border-bottom:1px solid {{empresa.color}};padding-bottom:3px;">Descripción de los hechos</p>
<div class="caja-desc">
  <p style="margin:0;font-size:10px;color:#333;line-height:16px;">{{llamado.descripcion}}</p>
</div>

<p class="cuerpo" style="margin-top:14px;">
  Por lo anterior, la empresa <strong>{{empresa.nombre}}</strong> realiza el presente llamado de atención
  de carácter <strong>{{llamado.tipo}}</strong> al colaborador <strong>{{colaborador.nombre}}</strong>,
  instándole a corregir la situación descrita y a cumplir con el reglamento interno de trabajo.
  La reincidencia podrá acarrear medidas disciplinarias de mayor gravedad.
</p>

<div class="row" style="margin-top:32px;">
  <div class="col" style="width:45%;">
    <div style="height:34px;"></div>
    <div class="firma-linea">
      <p style="margin:2px 0;font-weight:bold;">{{firmante.nombre}}</p>
      <p style="margin:0;color:#666;">{{empresa.nombre}} &mdash; Representante</p>
    </div>
  </div>
  <div class="col" style="width:10%;"></div>
  <div class="col" style="width:45%;">
    <div style="height:34px;"></div>
    <div class="firma-linea">
      <p style="margin:2px 0;font-weight:bold;">{{colaborador.nombre}}</p>
      <p style="margin:0;color:#666;">{{colaborador.cargo}} &mdash; C.C.: _______________</p>
    </div>
  </div>
</div>

<p style="font-size:8px;color:#888;text-align:center;margin-top:16px;font-style:italic;">
  La firma del colaborador no implica aceptación de los hechos, sino constancia de haber recibido este comunicado.
</p>

<div class="footer">{{empresa.nombre}} &nbsp;|&nbsp; {{empresa.ciudad}} &nbsp;|&nbsp; Documento interno &nbsp;|&nbsp; {{empresa.nombre}}</div>

</body>
</html>
HTML;
    }

    // ─────────────────────────────────────────────────────────────────────────
    private function comision(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8"/>
<style>
body{font-family:Arial,sans-serif;font-size:10px;color:#1a1a1a;margin:0;padding:22px 26px;}
.row{display:table;width:100%;}
.col{display:table-cell;vertical-align:top;}
.nombre{font-size:16px;font-weight:bold;color:{{empresa.color}};margin:0 0 1px 0;}
.info{font-size:9px;color:#666;margin:0;}
.badge{background:{{empresa.color}};color:white;font-size:14px;font-weight:bold;padding:5px 14px;display:inline-block;}
.hr{border:none;border-top:3px solid {{empresa.color}};margin:10px 0 14px 0;}
.vendedor-box{background:#EEF4FF;border-left:3px solid {{empresa.color}};padding:10px 12px;margin-bottom:14px;}
.etiq{font-size:8px;color:#888;font-weight:bold;text-transform:uppercase;margin:0 0 2px 0;}
.v-bold{font-size:12px;font-weight:bold;color:#1a1a1a;margin:0 0 2px 0;}
.v-sm{font-size:9px;color:#555;margin:0;}
.sec{font-size:9px;font-weight:bold;color:{{empresa.color}};text-transform:uppercase;border-bottom:1px solid {{empresa.color}};padding-bottom:3px;margin:0 0 6px 0;}
table{width:100%;border-collapse:collapse;margin-bottom:12px;}
th{background:{{empresa.color}};color:white;padding:6px 7px;font-size:9px;text-align:left;}
td{padding:5px 7px;border-bottom:1px solid #eee;font-size:9px;vertical-align:top;}
.tl-row{display:table;width:100%;padding:4px 0;border-bottom:1px solid #eee;}
.tl-lbl{display:table-cell;font-size:9px;color:#666;}
.tl-val{display:table-cell;text-align:right;font-size:9px;}
.total-bar{background:{{empresa.color}};padding:0;margin-top:6px;}
.tb-lbl{display:table-cell;font-size:12px;font-weight:bold;color:white;padding:8px 12px;}
.tb-val{display:table-cell;text-align:right;font-size:15px;font-weight:bold;color:white;padding:8px 12px;}
.firma-linea{border-top:1px solid #555;padding-top:4px;text-align:center;font-size:8px;color:#555;margin:0 20px;}
.footer{border-top:1px solid #ddd;margin-top:16px;padding-top:6px;font-size:8px;color:#aaa;}
</style>
</head>
<body>

<div class="row" style="padding-bottom:10px;">
  <div class="col">
    <p class="nombre">{{empresa.nombre}}</p>
    <p class="info">NIT: {{empresa.nit}} &nbsp;|&nbsp; {{empresa.ciudad}}</p>
  </div>
  <div class="col" style="text-align:right;">
    <div class="badge">COMISIONES</div>
    <p style="margin:4px 0 1px 0;font-size:12px;font-weight:bold;color:{{empresa.color}};">{{comision.numero}}</p>
    <p style="margin:0;font-size:9px;color:#666;">Período: {{comision.periodo}}</p>
  </div>
</div>
<hr class="hr"/>

<div class="vendedor-box">
  <div class="row">
    <div class="col" style="width:60%;">
      <p class="etiq">Vendedor</p>
      <p class="v-bold">{{vendedor.nombre}}</p>
    </div>
    <div class="col" style="width:40%;">
      <p class="etiq">Período liquidado</p>
      <p class="v-sm" style="font-size:10px;font-weight:bold;">{{comision.periodo}}</p>
    </div>
  </div>
</div>

<p class="sec">Detalle de Comisiones</p>
<table>
  <thead>
    <tr>
      <th style="width:4%;text-align:center;">#</th>
      <th style="width:68%;">Concepto / Operación</th>
      <th style="width:28%;text-align:right;">Valor comisión</th>
    </tr>
  </thead>
  <tbody>
    {{#items}}
    <tr>
      <td style="text-align:center;">{{index}}</td>
      <td>{{descripcion}}</td>
      <td style="text-align:right;font-weight:bold;">{{valor|moneda}}</td>
    </tr>
    {{/items}}
  </tbody>
</table>

<div style="width:55%;float:right;">
  <div class="total-bar">
    <div class="row"><div class="tb-lbl">TOTAL COMISIÓN:</div><div class="tb-val">{{comision.total|moneda}}</div></div>
  </div>
</div>
<div style="clear:both;height:18px;"></div>

<div style="background:#fffbeb;border:1px solid #fde68a;padding:8px 10px;margin-top:8px;font-size:9px;color:#92400e;">
  Este documento es una liquidación de comisiones para el período <strong>{{comision.periodo}}</strong>.
  Los valores aquí indicados corresponden a las ventas efectivamente cobradas y liquidadas según política comercial.
</div>

<div class="row" style="margin-top:32px;">
  <div class="col" style="width:45%;">
    <div style="height:30px;"></div>
    <div class="firma-linea">
      <p style="margin:2px 0;font-weight:bold;">{{vendedor.nombre}}</p>
      <p style="margin:0;color:#888;">Vendedor &mdash; C.C.: _______________</p>
    </div>
  </div>
  <div class="col" style="width:10%;"></div>
  <div class="col" style="width:45%;">
    <div style="height:30px;"></div>
    <div class="firma-linea">
      <p style="margin:2px 0;font-weight:bold;">Gerencia</p>
      <p style="margin:0;color:#888;">{{empresa.nombre}}</p>
    </div>
  </div>
</div>

<div class="footer">
  <div class="row">
    <div class="col">{{empresa.nombre}} &nbsp;|&nbsp; {{empresa.ciudad}}</div>
    <div class="col" style="text-align:right;">{{comision.numero}} &nbsp;|&nbsp; {{empresa.nombre}}</div>
  </div>
</div>

</body>
</html>
HTML;
    }
}
