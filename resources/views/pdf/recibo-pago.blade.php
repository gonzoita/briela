<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8"/>
  <style>
    body { font-family: Arial, sans-serif; font-size: 12px; color: #1a1a1a; margin: 0; padding: 20px; }
    .header { text-align: center; border-bottom: 2px solid {{ $marcaColor }}; padding-bottom: 12px; margin-bottom: 16px; }
    .header h1 { color: {{ $marcaColor }}; font-size: 20px; margin: 0 0 4px; }
    .header p { margin: 2px 0; color: #555; font-size: 11px; }
    .badge { display: inline-block; background: {{ $marcaColor }}; color: white; padding: 4px 12px; border-radius: 4px; font-size: 14px; font-weight: bold; margin-bottom: 16px; }
    .grid { width: 100%; margin-bottom: 12px; border-collapse: collapse; }
    .grid td { padding: 5px 8px; border-bottom: 1px solid #eee; }
    .label { color: #666; font-size: 11px; width: 40%; }
    .value { font-weight: bold; }
    .total { background: #f0f5ff; border-radius: 6px; padding: 12px; text-align: center; margin-top: 16px; }
    .total .monto { font-size: 24px; font-weight: bold; color: {{ $marcaColor }}; }
    .footer { margin-top: 24px; border-top: 1px solid #ddd; padding-top: 12px; font-size: 10px; color: #999; text-align: center; }
  </style>
</head>
<body>
  <div class="header">
    <h1>INTERFRIGO SAS</h1>
    <p>NIT: 900.XXX.XXX-X</p>
    <p>Bogotá, Colombia</p>
  </div>

  <div style="text-align:center;">
    <div class="badge">RECIBO DE CAJA {{ $pago->numero_recibo }}</div>
  </div>

  <table class="grid">
    <tr>
      <td class="label">Fecha de pago</td>
      <td class="value">{{ $pago->fecha_pago->format('d/m/Y') }}</td>
    </tr>
    <tr>
      <td class="label">Cliente</td>
      <td class="value">{{ $pago->op->cliente?->nombre ?? '—' }}</td>
    </tr>
    <tr>
      <td class="label">Orden de Producción</td>
      <td class="value">{{ $pago->op->numero ?? '—' }}</td>
    </tr>
    <tr>
      <td class="label">Cuota</td>
      <td class="value">{{ $pago->cuota?->concepto ?? 'Pago general' }}</td>
    </tr>
    <tr>
      <td class="label">Medio de pago</td>
      <td class="value">{{ ucfirst($pago->medio_pago) }}</td>
    </tr>
    @if($pago->referencia)
    <tr>
      <td class="label">Referencia</td>
      <td class="value">{{ $pago->referencia }}</td>
    </tr>
    @endif
    @if($pago->notas)
    <tr>
      <td class="label">Notas</td>
      <td class="value">{{ $pago->notas }}</td>
    </tr>
    @endif
    <tr>
      <td class="label">Registrado por</td>
      <td class="value">{{ $pago->registradoPor?->name ?? '—' }}</td>
    </tr>
  </table>

  <div class="total">
    <div style="font-size:11px;color:#666;margin-bottom:4px;">VALOR RECIBIDO</div>
    <div class="monto">$ {{ number_format($pago->valor, 0, ',', '.') }}</div>
  </div>

  <div class="footer">
    Documento generado por SGI Interfrigo · {{ now()->setTimezone('America/Bogota')->format('d/m/Y H:i') }}
  </div>
</body>
</html>
