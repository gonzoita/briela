<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8"/>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color: #1a1a1a; background: #fff; }
        .page { padding: 28px 32px; }

        /* Header */
        .header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid {{ $marcaColor }}; padding-bottom: 14px; margin-bottom: 20px; }
        .logo-text { font-size: 22px; font-weight: 700; color: {{ $marcaColor }}; letter-spacing: -0.5px; }
        .logo-sub { font-size: 9px; color: #555; margin-top: 2px; }
        .doc-title { text-align: right; }
        .doc-title h2 { font-size: 16px; font-weight: 700; color: {{ $marcaColor }}; }
        .doc-title .numero { font-size: 13px; font-weight: 600; color: #333; margin-top: 4px; }

        /* Datos */
        .datos-grid { display: flex; gap: 20px; margin-bottom: 18px; }
        .datos-box { flex: 1; background: #F8F9FA; border-radius: 6px; padding: 10px 14px; }
        .datos-box .label { font-size: 8px; font-weight: 700; text-transform: uppercase; color: #888; letter-spacing: 0.5px; margin-bottom: 3px; }
        .datos-box .valor { font-size: 12px; font-weight: 600; color: #1a1a1a; }
        .datos-box .valor-sub { font-size: 10px; color: #555; margin-top: 2px; }

        /* Tabla items */
        .section-title { font-size: 9px; font-weight: 700; text-transform: uppercase; color: {{ $marcaColor }}; letter-spacing: 0.5px; margin-bottom: 6px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        thead tr { background: {{ $marcaColor }}; }
        thead th { color: white; font-size: 9px; font-weight: 600; text-transform: uppercase; padding: 7px 10px; text-align: left; }
        tbody tr { border-bottom: 1px solid #E5E7EB; }
        tbody tr:last-child { border-bottom: none; }
        tbody tr:nth-child(even) { background: #F9FAFB; }
        tbody td { padding: 7px 10px; font-size: 10.5px; color: #333; }
        .td-center { text-align: center; }

        /* Despacho */
        .despacho-grid { display: flex; gap: 16px; margin-bottom: 18px; }
        .despacho-item { flex: 1; }
        .despacho-item .label { font-size: 8px; font-weight: 700; text-transform: uppercase; color: #888; letter-spacing: 0.5px; }
        .despacho-item .valor { font-size: 11px; font-weight: 600; color: #1a1a1a; border-bottom: 1px solid #E5E7EB; padding-bottom: 4px; margin-top: 2px; }

        /* Firmas */
        .firmas { display: flex; gap: 30px; margin-top: 30px; }
        .firma-box { flex: 1; text-align: center; }
        .firma-box .firma-img { width: 100%; height: 80px; border: 1px solid #E5E7EB; border-radius: 6px; background: #F9FAFB; object-fit: contain; }
        .firma-box .firma-vacio { width: 100%; height: 80px; border: 1px solid #E5E7EB; border-radius: 6px; background: #F9FAFB; }
        .firma-box .firma-label { font-size: 9px; font-weight: 700; text-transform: uppercase; color: #555; margin-top: 6px; letter-spacing: 0.5px; }
        .firma-box .firma-nombre { font-size: 10px; color: #333; margin-top: 2px; }

        /* Footer */
        .footer { margin-top: 24px; padding-top: 10px; border-top: 1px solid #E5E7EB; display: flex; justify-content: space-between; font-size: 8.5px; color: #888; }

        .badge { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: 9px; font-weight: 600; }
        .badge-op { background: #EDE9FE; color: #5B21B6; }
        .badge-manual { background: #E0F2FE; color: #0369A1; }
    </style>
</head>
<body>
<div class="page">

    <!-- Header -->
    <div class="header">
        <div>
            <div class="logo-text">{{ strtoupper($marcaNombre) }}</div>
            <div class="logo-sub">Cuartos fríos y puertas refrigeradas</div>
        </div>
        <div class="doc-title">
            <h2>REMISIÓN DE MERCANCÍA</h2>
            <div class="numero">{{ $remision->numero }}</div>
            <div style="margin-top:4px;">
                <span class="badge {{ $remision->tipo === 'op' ? 'badge-op' : 'badge-manual' }}">
                    {{ $remision->tipo === 'op' ? 'Desde OP' : 'Manual' }}
                </span>
            </div>
        </div>
    </div>

    <!-- Datos generales -->
    <div class="datos-grid">
        <div class="datos-box">
            <div class="label">Cliente</div>
            <div class="valor">{{ $remision->cliente?->nombreCompleto() ?? '—' }}</div>
        </div>
        <div class="datos-box">
            <div class="label">Fecha de remisión</div>
            <div class="valor">{{ $remision->fecha_remision?->format('d/m/Y') ?? '—' }}</div>
        </div>
        @if($remision->op)
        <div class="datos-box">
            <div class="label">Referencia OP</div>
            <div class="valor">{{ $remision->op->numero }}</div>
        </div>
        @endif
    </div>

    @if($remision->notas)
    <div style="background:#FEF3C7;border-radius:6px;padding:8px 12px;margin-bottom:16px;font-size:10px;color:#78350F;">
        <strong>Notas:</strong> {{ $remision->notas }}
    </div>
    @endif

    <!-- Tabla de ítems -->
    <div class="section-title">Detalle de Mercancía</div>
    <table>
        <thead>
            <tr>
                <th style="width:38%">Descripción</th>
                <th style="width:15%">Referencia</th>
                <th style="width:10%" class="td-center">Cantidad</th>
                <th style="width:12%">Unidad</th>
                <th style="width:25%">N° Serie</th>
            </tr>
        </thead>
        <tbody>
            @forelse($remision->items as $item)
            <tr>
                <td>{{ $item->descripcion }}</td>
                <td>{{ $item->opItem?->codigo_item ?? '—' }}</td>
                <td class="td-center">{{ number_format($item->cantidad, 2, '.', '') }}</td>
                <td>{{ $item->unidad ?? '—' }}</td>
                <td style="font-family:monospace;font-size:9.5px;">{{ $item->numero_serie ?? '—' }}</td>
            </tr>
            @empty
            <tr><td colspan="5" style="text-align:center;padding:16px;color:#888;">Sin ítems registrados.</td></tr>
            @endforelse
        </tbody>
    </table>

    <!-- Despacho -->
    @if($remision->transportista || $remision->placa || $remision->fecha_salida)
    <div class="section-title">Datos de Transporte</div>
    <div class="despacho-grid">
        <div class="despacho-item">
            <div class="label">Transportista</div>
            <div class="valor">{{ $remision->transportista ?? '—' }}</div>
        </div>
        <div class="despacho-item">
            <div class="label">Celular</div>
            <div class="valor">{{ $remision->celular_transportista ?? '—' }}</div>
        </div>
        <div class="despacho-item">
            <div class="label">Placa</div>
            <div class="valor">{{ $remision->placa ?? '—' }}</div>
        </div>
        <div class="despacho-item">
            <div class="label">Fecha salida</div>
            <div class="valor">{{ $remision->fecha_salida?->format('d/m/Y H:i') ?? '—' }}</div>
        </div>
    </div>
    @endif

    <!-- Firmas -->
    <div class="firmas">
        <div class="firma-box">
            @if($remision->firma_despacho)
                <img class="firma-img" src="{{ $remision->firma_despacho }}" alt="Firma despacho"/>
            @else
                <div class="firma-vacio"></div>
            @endif
            <div class="firma-label">Despachado por</div>
            <div class="firma-nombre">{{ $remision->creadoPor?->name ?? '—' }}</div>
        </div>
        <div class="firma-box">
            @if($remision->firma_recibido)
                <img class="firma-img" src="{{ $remision->firma_recibido }}" alt="Firma recibido"/>
            @else
                <div class="firma-vacio"></div>
            @endif
            <div class="firma-label">Recibido por</div>
            <div class="firma-nombre">{{ $remision->nombre_receptor ?? '—' }}</div>
        </div>
    </div>

    <!-- Footer -->
    <div class="footer">
        <span>Generado el {{ now()->format('d/m/Y H:i') }} — {{ $remision->numero }}</span>
        <span>{{ strtoupper($marcaNombre) }}</span>
    </div>

</div>
</body>
</html>
