<!DOCTYPE html>
<html lang="es">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family: DejaVu Sans, sans-serif; font-size:10px; color:#1f2937; }

.header { background:{{ $marcaColor }}; padding:14px 20px; }
.header-inner { display:table; width:100%; }
.header-left { display:table-cell; vertical-align:middle; }
.header-right { display:table-cell; vertical-align:middle; text-align:right; }
.logo-sub { color:#93c5fd; font-size:7px; margin-top:2px; }
.header-doc { color:white; font-size:9px; font-weight:bold; }
.header-right p { color:#bfdbfe; font-size:7px; line-height:1.6; }

.info-grid { display:table; width:100%; padding:14px 20px;
             border-bottom:1px solid #e5e7eb; }
.info-row { display:table-row; }
.info-box { display:table-cell; border:1px solid #e5e7eb; border-radius:6px;
            padding:8px 12px; width:20%; }
.info-label { font-size:7px; color:#9ca3af; text-transform:uppercase;
              letter-spacing:0.3px; margin-bottom:3px; }
.info-value { font-size:10px; font-weight:bold; color:#111827; }
.info-value-blue { font-size:10px; font-weight:bold; color:{{ $marcaColor }}; }

.section { padding:12px 20px; }
.section-title { font-size:8px; font-weight:bold; color:{{ $marcaColor }};
                 text-transform:uppercase; letter-spacing:0.5px;
                 margin-bottom:8px; padding-bottom:4px;
                 border-bottom:1px solid #dbeafe; }

table { width:100%; border-collapse:collapse; }
th { background:#f9fafb; text-align:right; padding:6px 8px;
     font-size:7px; color:#6b7280; text-transform:uppercase;
     border-bottom:1px solid #e5e7eb; }
th.left { text-align:left; }
td { padding:6px 8px; font-size:9px; border-bottom:1px solid #f3f4f6;
     text-align:right; color:#374151; }
td.left { text-align:left; }
tr:last-child td { border-bottom:none; }

.tfoot-row td { background:#fffbeb; border-top:2px solid #fcd34d;
                font-weight:bold; color:#92400e; font-size:10px; }

.badge { display:inline; padding:1px 6px; border-radius:8px;
         font-size:7px; font-weight:bold; }
.badge-ensamble { background:#fff7ed; color:#ea580c; }
.badge-producto { background:#eff6ff; color:#2563eb; }

.estado { display:inline; padding:2px 8px; border-radius:10px;
          font-size:8px; font-weight:bold; }
.estado-proyectada { background:#dbeafe; color:#1d4ed8; }
.estado-confirmada { background:#fef3c7; color:#b45309; }
.estado-ejecutada  { background:#dcfce7; color:#15803d; }
.estado-liquidada  { background:#f3f4f6; color:#4b5563; }

.total-box { background:#fffbeb; border:2px solid #fcd34d;
             border-radius:8px; padding:12px 16px; margin:12px 20px;
             text-align:right; }
.total-label { font-size:9px; color:#92400e; margin-bottom:4px; }
.total-value { font-size:20px; font-weight:bold; color:#78350f; }

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
      @if($marcaLogoPath)
      <img src="{{ $marcaLogoPath }}"
           style="height:26px;" alt="{{ $marcaNombre }}"/>
      @endif
      <p class="logo-sub">{{ $marcaNombre }}
         de cuartos fríos y puertas refrigeradas</p>
    </div>
    <div class="header-right">
      <p class="header-doc">COMPROBANTE DE COMISIÓN</p>
      <p>{{ $comision->cotizacion->numero ?? 'N/A' }}</p>
      <p>Generado: {{ now()->format('d/m/Y H:i') }}</p>
    </div>
  </div>
</div>

<!-- INFO GRID -->
<div style="padding:14px 20px; border-bottom:1px solid #e5e7eb;">
  <table>
    <tr>
      <td style="border:1px solid #e5e7eb; border-radius:6px; padding:8px 12px; width:20%;">
        <p class="info-label">Vendedor</p>
        <p class="info-value">{{ $comision->user->name }}</p>
      </td>
      <td style="width:4px;"></td>
      <td style="border:1px solid #e5e7eb; border-radius:6px; padding:8px 12px; width:25%;">
        <p class="info-label">Cliente</p>
        <p class="info-value">{{ $comision->cotizacion->cliente->nombre }}</p>
      </td>
      <td style="width:4px;"></td>
      <td style="border:1px solid #e5e7eb; border-radius:6px; padding:8px 12px; width:18%;">
        <p class="info-label">Cotización</p>
        <p class="info-value-blue">{{ $comision->cotizacion->numero }}</p>
      </td>
      <td style="width:4px;"></td>
      <td style="border:1px solid #e5e7eb; border-radius:6px; padding:8px 12px; width:15%;">
        <p class="info-label">Período</p>
        <p class="info-value">
          @php
            $partes = explode('-', substr($comision->periodo_mes, 0, 7));
            $meses = ['','Ene','Feb','Mar','Abr','May','Jun',
                      'Jul','Ago','Sep','Oct','Nov','Dic'];
            echo ($meses[(int)$partes[1]] ?? '') . ' ' . $partes[0];
          @endphp
        </p>
      </td>
      <td style="width:4px;"></td>
      <td style="border:1px solid #e5e7eb; border-radius:6px; padding:8px 12px; width:15%;">
        <p class="info-label">Estado</p>
        <p class="info-value">
          <span class="estado estado-{{ $comision->estado }}">
            {{ ucfirst($comision->estado) }}
          </span>
        </p>
      </td>
    </tr>
  </table>
</div>

<!-- DESGLOSE -->
<div class="section">
  <p class="section-title">Desglose por ítem</p>
  <table>
    <thead>
      <tr>
        <th class="left">Producto / Servicio</th>
        <th>Cant.</th>
        <th>P.Unitario</th>
        <th>Dto%</th>
        <th>Com%</th>
        <th>Comisión</th>
      </tr>
    </thead>
    <tbody>
      @foreach($desglose as $item)
      <tr>
        <td class="left">
          {{ $item['descripcion'] }}
          <br/>
          <span class="badge badge-{{ $item['tipo'] === 'ensamble' ? 'ensamble' : 'producto' }}">
            {{ strtoupper($item['tipo']) }}
          </span>
        </td>
        <td>{{ number_format($item['cantidad'], 2) }}</td>
        <td>${{ number_format($item['precio_unitario'], 0, ',', '.') }}</td>
        <td>{{ $item['descuento_pct'] > 0 ? $item['descuento_pct'] . '%' : '—' }}</td>
        <td style="color:#d97706; font-weight:bold;">{{ $item['comision_pct'] }}%</td>
        <td style="font-weight:bold;">${{ number_format($item['comision_valor'], 0, ',', '.') }}</td>
      </tr>
      @endforeach
      @if(count($desglose) === 0)
      <tr>
        <td colspan="6" class="left" style="padding:16px 8px; color:#9ca3af; font-style:italic;">
          Esta cotización no tiene ítems con comisión configurada
        </td>
      </tr>
      @endif
    </tbody>
    <tfoot>
      <tr class="tfoot-row">
        <td colspan="5" style="text-align:right;">TOTAL COMISIÓN</td>
        <td>${{ number_format($comision->total_comision, 0, ',', '.') }}</td>
      </tr>
    </tfoot>
  </table>
</div>

<!-- TOTAL DESTACADO -->
<div class="total-box">
  <p class="total-label">Total a recibir por esta cotización</p>
  <p class="total-value">${{ number_format($comision->total_comision, 0, ',', '.') }} COP</p>
</div>

<!-- FOOTER -->
<div class="footer-bar">
  <div class="footer-inner">
    <div class="footer-left">{{ $marcaNombre }} · {{ now()->format('d/m/Y') }}</div>
    <div class="footer-right">{{ $marcaWeb }}</div>
  </div>
</div>

</body>
</html>
