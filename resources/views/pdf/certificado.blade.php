<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8" />
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color: #111; background: white; }

  .header {
    background-color: {{ $marcaColor }};
    color: white;
    padding: 16px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
  }
  .header-left h1 { font-size: 16px; font-weight: bold; letter-spacing: 1px; }
  .header-left p  { font-size: 10px; margin-top: 2px; opacity: 0.85; }
  .header-right   { text-align: right; }
  .header-right img { width: 80px; height: 80px; }

  .section { padding: 14px 20px; border-bottom: 1px solid #E5E7EB; }
  .section-title { font-size: 9px; font-weight: bold; color: #6B7280; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }

  .grid-2 { display: flex; gap: 20px; }
  .grid-2 .col { flex: 1; }
  .field { margin-bottom: 6px; }
  .field-label { font-size: 9px; color: #6B7280; }
  .field-value { font-size: 11px; font-weight: 600; color: #111; }

  table { width: 100%; border-collapse: collapse; margin-top: 4px; }
  th {
    background: #F3F4F6;
    border: 1px solid #D1D5DB;
    padding: 6px 8px;
    font-size: 9px;
    font-weight: bold;
    text-align: left;
    text-transform: uppercase;
    color: #374151;
  }
  td {
    border: 1px solid #E5E7EB;
    padding: 6px 8px;
    font-size: 10px;
    vertical-align: top;
  }
  tr:nth-child(even) td { background: #F9FAFB; }

  .serie-mono { font-family: DejaVu Sans Mono, monospace; font-weight: bold; color: {{ $marcaColor }}; }

  .checks { padding: 14px 20px; }
  .check-item { display: flex; align-items: center; gap: 6px; margin-bottom: 6px; font-size: 11px; }
  .check-icon { color: {{ $marcaColor }}; font-weight: bold; }

  .obs-box {
    background: #F9FAFB;
    border: 1px solid #E5E7EB;
    border-radius: 4px;
    padding: 10px;
    font-size: 10px;
    color: #374151;
    margin-top: 4px;
    min-height: 32px;
  }

  .firma-section {
    padding: 16px 20px;
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
  }
  .firma-box { text-align: center; }
  .firma-line { border-top: 1px solid #374151; width: 180px; margin: 0 auto; padding-top: 4px; font-size: 9px; color: #6B7280; }
  .firma-nombre { font-size: 10px; font-weight: bold; margin-top: 2px; }

  .footer {
    background: #F3F4F6;
    padding: 10px 20px;
    text-align: center;
    font-size: 9px;
    color: #6B7280;
    border-top: 1px solid #E5E7EB;
  }

  .qr-verify { text-align: center; }
  .qr-verify p { font-size: 9px; color: #6B7280; margin-top: 4px; }
</style>
</head>
<body>

{{-- ── Header ────────────────────────────────────────────────────────────── --}}
<div class="header">
  <div class="header-left">
    <h1>CERTIFICADO DE CALIDAD</h1>
    <p>{{ $op->numero_op }} &nbsp;|&nbsp; Fecha: {{ $fecha }}</p>
    <p style="margin-top:4px;font-size:11px;font-weight:bold;">{{ $marcaNombre }}</p>
  </div>
  <div class="header-right">
    <div class="qr-verify">
      <img src="{{ $qrBase64 }}" style="width:72px;height:72px;" />
      <p style="color:rgba(255,255,255,0.8);">Verificar autenticidad</p>
    </div>
  </div>
</div>

{{-- ── Datos generales ────────────────────────────────────────────────────── --}}
<div class="section">
  <div class="section-title">Información del pedido</div>
  <div class="grid-2">
    <div class="col">
      <div class="field">
        <div class="field-label">Cliente</div>
        <div class="field-value">{{ $op->cliente }}</div>
      </div>
      <div class="field">
        <div class="field-label">Vendedor</div>
        <div class="field-value">{{ $op->vendedor }}</div>
      </div>
    </div>
    <div class="col">
      <div class="field">
        <div class="field-label">Estado</div>
        <div class="field-value">{{ $op->estadoLabel() }}</div>
      </div>
      @if($op->numero_remision)
      <div class="field">
        <div class="field-label">Remisión</div>
        <div class="field-value">{{ $op->numero_remision }}</div>
      </div>
      @endif
    </div>
  </div>
</div>

{{-- ── Ítems inspeccionados ───────────────────────────────────────────────── --}}
<div class="section">
  <div class="section-title">Ítems inspeccionados</div>
  <table>
    <thead>
      <tr>
        <th>Serie</th>
        <th>Tipo</th>
        <th>Especificaciones</th>
        <th>Estado</th>
      </tr>
    </thead>
    <tbody>
      @forelse($items as $item)
      <tr>
        <td><span class="serie-mono">{{ $item->numero_serie ?? '—' }}</span></td>
        <td>{{ $item->tipoLabel() }}</td>
        <td>{{ $item->resumen() }}</td>
        <td>{{ $item->estadoSerieLabel() }}</td>
      </tr>
      @empty
      <tr><td colspan="4" style="text-align:center;color:#9CA3AF;">Sin ítems registrados</td></tr>
      @endforelse
    </tbody>
  </table>
</div>

{{-- ── Checklist de calidad ───────────────────────────────────────────────── --}}
<div class="checks">
  <div class="section-title">Verificaciones realizadas</div>
  <div class="check-item"><span class="check-icon">✓</span> Medidas verificadas y conformes con especificaciones</div>
  <div class="check-item"><span class="check-icon">✓</span> Acabados aprobados — superficies sin defectos</div>
  <div class="check-item"><span class="check-icon">✓</span> Hermeticidad comprobada</div>
  <div class="check-item"><span class="check-icon">✓</span> Componentes y herrajes completos</div>

  @if($op->observaciones_calidad)
  <div style="margin-top:10px;">
    <div class="section-title">Observaciones</div>
    <div class="obs-box">{{ $op->observaciones_calidad }}</div>
  </div>
  @endif
</div>

{{-- ── Firma ──────────────────────────────────────────────────────────────── --}}
<div class="firma-section">
  <div class="firma-box">
    <div class="firma-line">Aprobado por</div>
    <div class="firma-nombre">Jefe de Producción</div>
    <div style="font-size:9px;color:#9CA3AF;margin-top:2px;">{{ $marcaNombre }}</div>
  </div>
  <div style="text-align:right;max-width:200px;">
    <div class="qr-verify">
      <p style="font-size:9px;color:#6B7280;margin-bottom:4px;">Escanea para verificar<br>la autenticidad</p>
      <img src="{{ $qrBase64 }}" style="width:56px;height:56px;" />
    </div>
  </div>
</div>

{{-- ── Footer ─────────────────────────────────────────────────────────────── --}}
<div class="footer">
  {{ $marcaNombre }}@if($marcaEmail) &nbsp;·&nbsp; {{ $marcaEmail }}@endif
  &nbsp;·&nbsp; Documento generado el {{ $fecha }}
</div>

</body>
</html>
