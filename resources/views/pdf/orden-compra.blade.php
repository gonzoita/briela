<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Orden de Compra {{ $orden->numero }}</title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #333; }
    .header { background: {{ $marcaColor }}; color: white; padding: 20px; display: flex; justify-content: space-between; align-items: center; }
    .header h1 { font-size: 20px; font-weight: bold; }
    .header p { font-size: 11px; opacity: 0.8; margin-top: 2px; }
    .numero { font-size: 18px; font-weight: bold; text-align: right; }
    .section { padding: 16px 20px; }
    .section-title { font-weight: bold; color: {{ $marcaColor }}; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #ddd; padding-bottom: 4px; margin-bottom: 10px; }
    .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .info-block label { font-size: 9px; color: #666; text-transform: uppercase; display: block; }
    .info-block p { font-weight: 600; font-size: 11px; margin-top: 2px; }
    table { width: 100%; border-collapse: collapse; }
    thead { background: #f8f9fa; }
    th { text-align: left; padding: 8px 10px; font-size: 10px; font-weight: 700; color: #555; text-transform: uppercase; border-bottom: 2px solid #ddd; }
    th.right, td.right { text-align: right; }
    td { padding: 7px 10px; border-bottom: 1px solid #eee; font-size: 11px; }
    .totales { padding: 12px 20px; border-top: 2px solid #eee; }
    .total-row { display: flex; justify-content: space-between; padding: 3px 0; }
    .total-row.final { font-size: 14px; font-weight: bold; color: {{ $marcaColor }}; border-top: 1px solid #ccc; padding-top: 6px; margin-top: 4px; }
    .footer { padding: 16px 20px; border-top: 1px solid #eee; color: #666; font-size: 10px; }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: 10px; font-weight: 600; }
    .badge-borrador   { background: #f3f4f6; color: #374151; }
    .badge-enviada    { background: #dbeafe; color: #1d4ed8; }
    .badge-recibida   { background: #d1fae5; color: #065f46; }
</style>
</head>
<body>

<div class="header">
    <div>
        <h1>Interfrigo SAS</h1>
        <p>Cuartos fríos y puertas refrigeradas</p>
    </div>
    <div>
        <div class="numero">{{ $orden->numero }}</div>
        <div style="font-size:11px; opacity:0.8; text-align:right;">Orden de Compra</div>
    </div>
</div>

<div class="section">
    <div class="section-title">Información general</div>
    <div class="info-grid">
        <div class="info-block">
            <label>Proveedor</label>
            <p>{{ $orden->proveedor->nombre }}</p>
            @if($orden->proveedor->nit)
                <p style="font-size:10px;color:#666;">NIT: {{ $orden->proveedor->nit }}</p>
            @endif
        </div>
        <div class="info-block">
            <label>Estado</label>
            <p><span class="badge badge-{{ $orden->estado }}">{{ Str::ucfirst(str_replace('_', ' ', $orden->estado)) }}</span></p>
        </div>
        <div class="info-block">
            <label>Creado por</label>
            <p>{{ $orden->creadoPor->name }}</p>
        </div>
        <div class="info-block">
            <label>Fecha de creación</label>
            <p>{{ $orden->created_at->format('d/m/Y') }}</p>
        </div>
        @if($orden->fecha_entrega_esperada)
        <div class="info-block">
            <label>Fecha entrega esperada</label>
            <p>{{ $orden->fecha_entrega_esperada->format('d/m/Y') }}</p>
        </div>
        @endif
        @if($orden->solicitud)
        <div class="info-block">
            <label>Solicitud de compra</label>
            <p>{{ $orden->solicitud->numero }}</p>
        </div>
        @endif
    </div>
</div>

<div class="section">
    <div class="section-title">Ítems</div>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Descripción</th>
                <th class="right">Cantidad</th>
                <th>Unidad</th>
                <th class="right">Precio Unit.</th>
                <th class="right">IVA %</th>
                <th class="right">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($orden->items as $i => $item)
            <tr>
                <td style="color:#999;">{{ $i + 1 }}</td>
                <td>
                    {{ $item->descripcion }}
                    @if($item->item)
                        <br><span style="font-size:9px;color:#999;">{{ $item->item->referencia }}</span>
                    @endif
                </td>
                <td class="right">{{ number_format($item->cantidad, 2) }}</td>
                <td>{{ $item->unidad }}</td>
                <td class="right">$ {{ number_format($item->precio_unitario, 0, ',', '.') }}</td>
                <td class="right">{{ number_format($item->impuesto_pct, 0) }}%</td>
                <td class="right" style="font-weight:600;">$ {{ number_format($item->total_linea, 0, ',', '.') }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>

<div class="totales">
    <div class="total-row">
        <span>Subtotal</span>
        <span>$ {{ number_format($orden->subtotal, 0, ',', '.') }}</span>
    </div>
    <div class="total-row">
        <span>Impuesto</span>
        <span>$ {{ number_format($orden->impuesto, 0, ',', '.') }}</span>
    </div>
    <div class="total-row final">
        <span>TOTAL</span>
        <span>$ {{ number_format($orden->total, 0, ',', '.') }}</span>
    </div>
</div>

@if($orden->condiciones || $orden->notas)
<div class="section" style="padding-top:0;">
    @if($orden->condiciones)
    <p style="font-size:10px;color:#555;"><strong>Condiciones:</strong> {{ $orden->condiciones }}</p>
    @endif
    @if($orden->notas)
    <p style="font-size:10px;color:#555;margin-top:4px;"><strong>Notas:</strong> {{ $orden->notas }}</p>
    @endif
</div>
@endif

<div class="footer">
    <p>Generado el {{ now()->format('d/m/Y H:i') }} · Interfrigo SAS · sgi.interfrigo.com.co</p>
</div>

</body>
</html>
