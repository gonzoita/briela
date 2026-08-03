<!DOCTYPE html>
<html lang="es">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family: DejaVu Sans, sans-serif; font-size:10px; color:#1f2937; }

.header { background:{{ $marcaColor }}; padding:14px 20px; }
.header-inner { display:table; width:100%; }
.header-left  { display:table-cell; vertical-align:middle; }
.header-right { display:table-cell; vertical-align:middle; text-align:right; }
.logo-sub { color:#93c5fd; font-size:7px; margin-top:2px; }
.header-doc { color:white; font-size:9px; font-weight:bold; }
.header-right p { color:#bfdbfe; font-size:7px; line-height:1.6; }

.resumen-header { padding:12px 20px; background:#f9fafb;
                  border-bottom:2px solid #e5e7eb; }
.rh-inner { display:table; width:100%; }
.rh-left  { display:table-cell; vertical-align:middle; }
.rh-right { display:table-cell; vertical-align:middle; text-align:right; }
.mes-titulo { font-size:14px; font-weight:bold; color:{{ $marcaColor }}; }
.vendedor-nombre { font-size:10px; color:#6b7280; margin-top:2px; }
.total-mes-badge { background:{{ $marcaColor }}; color:white;
                   display:inline-block; padding:8px 16px;
                   border-radius:8px; text-align:right; }
.total-mes-label { font-size:7px; color:#93c5fd; }
.total-mes-valor { font-size:14px; font-weight:bold; color:white; }

.cotizacion-block { margin:10px 20px; border:1px solid #e5e7eb;
                    border-radius:8px; overflow:hidden; }
.cot-header { background:#f9fafb; padding:8px 12px;
              border-bottom:1px solid #e5e7eb; }
.cot-header-inner { display:table; width:100%; }
.cot-left  { display:table-cell; vertical-align:middle; }
.cot-right { display:table-cell; vertical-align:middle; text-align:right; }
.cot-numero { font-weight:bold; color:{{ $marcaColor }}; font-size:10px; }
.cot-cliente { font-size:9px; color:#6b7280; }
.cot-estado { font-size:7px; padding:2px 6px; border-radius:8px;
              font-weight:bold; display:inline; }
.estado-confirmada { background:#fef3c7; color:#b45309; }
.estado-ejecutada  { background:#dcfce7; color:#15803d; }
.estado-liquidada  { background:#f3f4f6; color:#4b5563; }

table.items { width:100%; border-collapse:collapse; }
table.items th { text-align:right; padding:5px 8px; font-size:7px;
     color:#6b7280; text-transform:uppercase;
     background:#f9fafb; border-bottom:1px solid #e5e7eb; }
table.items th.left { text-align:left; }
table.items td { padding:5px 8px; font-size:8.5px;
     border-bottom:1px solid #f9fafb; text-align:right; color:#374151; }
table.items td.left { text-align:left; }
.subtotal-row td { background:#fffbeb; font-weight:bold;
                   color:#92400e; border-top:1px solid #fcd34d; }

.separador { height:1px; background:#e5e7eb; margin:4px 20px; }

.grand-total { margin:12px 20px; padding:14px 20px;
               background:{{ $marcaColor }}; border-radius:8px; }
.gt-inner { display:table; width:100%; }
.gt-left  { display:table-cell; vertical-align:middle; }
.gt-right { display:table-cell; vertical-align:middle; text-align:right; }
.grand-label { color:#bfdbfe; font-size:9px; }
.grand-sub   { color:#93c5fd; font-size:8px; margin-top:2px; }
.grand-valor { color:white; font-size:18px; font-weight:bold; }

.nota { padding:8px 20px; font-size:7px; color:#9ca3af; font-style:italic; }

.footer-bar { padding:10px 20px; background:#f9fafb;
              border-top:1px solid #e5e7eb; }
.footer-inner { display:table; width:100%; }
.footer-left  { display:table-cell; font-size:7px; color:#9ca3af; }
.footer-right { display:table-cell; text-align:right; font-size:7px; color:#9ca3af; }
</style>
</head>
<body>

<!-- HEADER -->
<div class="header">
  <div class="header-inner">
    <div class="header-left">
      @if(file_exists(public_path('img/logo-interfrigo.png')))
      <img src="{{ public_path('img/logo-interfrigo.png') }}"
           style="height:26px;" alt="Interfrigo"/>
      @endif
      <p class="logo-sub">Interfrigo SAS · Fabricación e instalación
         de cuartos fríos y puertas refrigeradas</p>
    </div>
    <div class="header-right">
      <p class="header-doc">RESUMEN DE COMISIONES</p>
      <p>Solo cotizaciones confirmadas</p>
      <p>Generado: {{ now()->format('d/m/Y H:i') }}</p>
    </div>
  </div>
</div>

<!-- RESUMEN HEADER -->
@php
  $partes = explode('-', substr($mes, 0, 7));
  $meses = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio',
            'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
  $mesTitulo = ($meses[(int)$partes[1]] ?? '') . ' ' . $partes[0];
@endphp
<div class="resumen-header">
  <div class="rh-inner">
    <div class="rh-left">
      <p class="mes-titulo">{{ $mesTitulo }}</p>
      <p class="vendedor-nombre">
        Vendedor: {{ $vendedor?->name ?? 'Todos los vendedores' }}
      </p>
      <p style="font-size:8px; color:#6b7280; margin-top:2px;">
        {{ $comisiones->count() }} cotizaciones confirmadas
      </p>
    </div>
    <div class="rh-right">
      <div class="total-mes-badge">
        <p class="total-mes-label">TOTAL DEL MES</p>
        <p class="total-mes-valor">${{ number_format($totalMes, 0, ',', '.') }}</p>
      </div>
    </div>
  </div>
</div>

<!-- COTIZACIONES -->
@foreach($comisiones as $comision)
<div class="cotizacion-block">
  <div class="cot-header">
    <div class="cot-header-inner">
      <div class="cot-left">
        <span class="cot-numero">{{ $comision->cotizacion->numero }}</span>
        <span class="cot-cliente" style="margin-left:8px;">
          · {{ $comision->cotizacion->cliente->nombre }}
        </span>
        <span class="cot-cliente" style="margin-left:8px;">
          · {{ \Carbon\Carbon::parse($comision->cotizacion->fecha_creacion)->format('d/m/Y') }}
        </span>
      </div>
      <div class="cot-right">
        <span class="cot-estado estado-{{ $comision->estado }}">
          {{ ucfirst($comision->estado) }}
        </span>
        <span style="font-weight:bold; color:{{ $marcaColor }}; font-size:10px; margin-left:8px;">
          ${{ number_format($comision->total_comision, 0, ',', '.') }}
        </span>
      </div>
    </div>
  </div>

  @if($comision->desglose->count() > 0)
  <table class="items">
    <thead>
      <tr>
        <th class="left">Producto / Servicio</th>
        <th>Cant.</th>
        <th>P.Unit</th>
        <th>Dto%</th>
        <th>Com%</th>
        <th>Comisión</th>
      </tr>
    </thead>
    <tbody>
      @foreach($comision->desglose as $item)
      <tr>
        <td class="left">{{ $item['descripcion'] }}</td>
        <td>{{ number_format($item['cantidad'], 2) }}</td>
        <td>${{ number_format($item['precio_unitario'], 0, ',', '.') }}</td>
        <td>{{ $item['descuento_pct'] > 0 ? $item['descuento_pct'] . '%' : '—' }}</td>
        <td style="color:#d97706; font-weight:bold;">{{ $item['comision_pct'] }}%</td>
        <td style="font-weight:bold;">${{ number_format($item['comision_valor'], 0, ',', '.') }}</td>
      </tr>
      @endforeach
    </tbody>
    <tfoot>
      <tr class="subtotal-row">
        <td colspan="5" style="text-align:right;">
          Subtotal comisión {{ $comision->cotizacion->numero }}
        </td>
        <td>${{ number_format($comision->total_comision, 0, ',', '.') }}</td>
      </tr>
    </tfoot>
  </table>
  @else
  <p style="padding:8px 12px; font-size:8px; color:#9ca3af; font-style:italic;">
    Sin ítems con comisión configurada
  </p>
  @endif
</div>

@if(!$loop->last)
<div class="separador"></div>
@endif
@endforeach

<!-- GRAN TOTAL -->
<div class="grand-total">
  <div class="gt-inner">
    <div class="gt-left">
      <p class="grand-label">TOTAL COMISIONES DEL MES</p>
      <p class="grand-sub">{{ $mesTitulo }} · Solo cotizaciones confirmadas</p>
    </div>
    <div class="gt-right">
      <p class="grand-valor">${{ number_format($totalMes, 0, ',', '.') }} COP</p>
    </div>
  </div>
</div>

<p class="nota">
  * Este documento incluye únicamente cotizaciones en estado
  Confirmada, Ejecutada o Liquidada. Las cotizaciones proyectadas
  no están incluidas en este resumen.
</p>

<!-- FOOTER -->
<div class="footer-bar">
  <div class="footer-inner">
    <div class="footer-left">Interfrigo SAS · Generado el {{ now()->format('d/m/Y H:i') }}</div>
    <div class="footer-right">interfrigo.com.co</div>
  </div>
</div>

</body>
</html>
