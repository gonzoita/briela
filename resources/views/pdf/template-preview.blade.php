<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: {{ $fuente ?? 'Arial' }}, sans-serif; font-size: {{ $tamanio_fuente ?? 10 }}px; color: {{ $color_texto ?? '#1A1A1A' }}; }

    .header {
        background-color: {{ $color_primario ?? '{{ $marcaColor }}' }};
        color: white;
        padding: 16px 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .header-left { display: flex; align-items: center; gap: 12px; }
    .header-logo img { height: 40px; background: white; padding: 3px; border-radius: 4px; }
    .header-title { font-size: 16px; font-weight: bold; }
    .header-subtitle { font-size: 9px; opacity: 0.8; margin-top: 3px; }
    .header-right { text-align: right; }
    .header-right .doc-number { font-size: 18px; font-weight: bold; }
    .header-right .doc-date { font-size: 9px; opacity: 0.8; margin-top: 3px; }

    .badge {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 20px;
        font-size: 9px;
        font-weight: bold;
        background-color: {{ $color_secundario ?? '#F8FAFC' }};
        color: {{ $color_primario ?? '{{ $marcaColor }}' }};
        margin-top: 5px;
    }

    .section { padding: 14px 20px; border-bottom: 1px solid #E5E7EB; }
    .section-title {
        font-size: 8px;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #6B7280;
        margin-bottom: 10px;
    }
    .grid-2 { display: flex; gap: 20px; }
    .grid-2 > div { flex: 1; }
    .field label { font-size: 8px; color: #9CA3AF; display: block; margin-bottom: 2px; }
    .field p { font-size: 10px; color: {{ $color_texto ?? '#1A1A1A' }}; font-weight: 500; }

    table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    thead th {
        background-color: {{ $color_primario ?? '{{ $marcaColor }}' }};
        color: white;
        padding: 6px 8px;
        text-align: left;
        font-size: 8px;
        font-weight: bold;
        text-transform: uppercase;
    }
    tbody tr:nth-child(even) { background-color: {{ $color_secundario ?? '#F8FAFC' }}; }
    tbody td { padding: 7px 8px; font-size: 9px; border-bottom: 1px solid #F3F4F6; }

    .totals { padding: 14px 20px; }
    .totals-table { width: 260px; margin-left: auto; }
    .totals-row { display: flex; justify-content: space-between; padding: 4px 0; font-size: 10px; }
    .totals-row.grand { font-weight: bold; font-size: 13px; border-top: 2px solid {{ $color_primario ?? '{{ $marcaColor }}' }}; padding-top: 8px; margin-top: 4px; color: {{ $color_primario ?? '{{ $marcaColor }}' }}; }

    .footer {
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        padding: 8px 20px;
        border-top: 1px solid #E5E7EB;
        font-size: 8px;
        color: #9CA3AF;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .footer .brand { color: {{ $color_primario ?? '{{ $marcaColor }}' }}; font-weight: bold; }

    .preview-notice {
        background-color: #FEF3C7;
        border: 1px solid #F59E0B;
        color: #92400E;
        font-size: 8px;
        padding: 4px 12px;
        text-align: center;
    }

    .section-tag {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 4px;
        font-size: 8px;
        background-color: {{ $color_secundario ?? '#F8FAFC' }};
        color: {{ $color_primario ?? '{{ $marcaColor }}' }};
        border: 1px solid {{ $color_primario ?? '{{ $marcaColor }}' }}33;
        margin: 2px 2px;
    }
</style>
</head>
<body>

<div class="preview-notice">
    Vista previa — Plantilla: {{ $label ?? $modulo }}
</div>

<!-- Encabezado -->
@if($mostrar_encabezado ?? true)
<div class="header">
    <div class="header-left">
        @if(($mostrar_logo ?? true) && ($logo_url ?? null))
        <div class="header-logo">
            <img src="{{ $logo_url }}" alt="Logo" style="width: {{ $logo_ancho ?? 120 }}px; height: auto;" />
        </div>
        @elseif($mostrar_logo ?? true)
        <div style="background:white; padding: 6px 10px; border-radius:4px; color: {{ $color_primario ?? '{{ $marcaColor }}' }}; font-weight:bold; font-size:11px;">
            {{ strtoupper($marcaNombre) }}
        </div>
        @endif
        <div>
            <div class="header-title">{{ $encabezado_titulo ?? $marcaNombre }}</div>
            <div class="header-subtitle">{{ $encabezado_subtitulo ?? 'Cuartos Fríos y Puertas Refrigeradas' }}</div>
        </div>
    </div>
    <div class="header-right">
        <div class="doc-number">{{ strtoupper($modulo) }}-2026-001</div>
        <div class="doc-date">Fecha: 28/06/2026</div>
        <div class="badge">MUESTRA</div>
    </div>
</div>
@endif

<!-- Datos del destinatario -->
<div class="section">
    <div class="section-title">Datos del destinatario</div>
    <div class="grid-2">
        <div>
            <div class="field"><label>Cliente</label><p>Cliente de Ejemplo S.A.S.</p></div>
            <div class="field" style="margin-top:6px;"><label>NIT / CC</label><p>900.123.456-1</p></div>
        </div>
        <div>
            <div class="field"><label>Teléfono</label><p>+57 300 123 4567</p></div>
            <div class="field" style="margin-top:6px;"><label>Ciudad</label><p>Bogotá, Colombia</p></div>
        </div>
    </div>
</div>

<!-- Tabla de ítems de muestra -->
<div class="section">
    <div class="section-title">Ítems / Productos</div>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Descripción</th>
                <th style="text-align:right;">Cant.</th>
                <th style="text-align:right;">V. Unit.</th>
                <th style="text-align:right;">Total</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>1</td>
                <td>Panel modular 1.20m × 2.40m × 75mm — Acero Inoxidable</td>
                <td style="text-align:right;">4</td>
                <td style="text-align:right;">$1.250.000</td>
                <td style="text-align:right;">$5.000.000</td>
            </tr>
            <tr>
                <td>2</td>
                <td>Puerta corrediza 0.90m × 2.10m — Pivotante izquierda</td>
                <td style="text-align:right;">1</td>
                <td style="text-align:right;">$2.800.000</td>
                <td style="text-align:right;">$2.800.000</td>
            </tr>
            <tr>
                <td>3</td>
                <td>Sistema de herrajes y accesorios de montaje</td>
                <td style="text-align:right;">1</td>
                <td style="text-align:right;">$450.000</td>
                <td style="text-align:right;">$450.000</td>
            </tr>
        </tbody>
    </table>
</div>

<!-- Totales -->
<div class="totals">
    <div class="totals-table">
        <div class="totals-row"><span>Subtotal</span><span>$8.250.000</span></div>
        <div class="totals-row"><span>IVA (19%)</span><span>$1.567.500</span></div>
        <div class="totals-row grand"><span>TOTAL</span><span>$9.817.500</span></div>
    </div>
</div>

<!-- Secciones configurables -->
<div class="section">
    <div class="section-title">Secciones del documento ({{ $label ?? $modulo }})</div>
    <div>
        @foreach($secciones ?? [] as $seccion)
        <span class="section-tag">{{ $seccion }}</span>
        @endforeach
    </div>
</div>

<!-- Colores y tipografía actuales -->
<div class="section">
    <div class="section-title">Configuración aplicada</div>
    <div class="grid-2">
        <div>
            <div class="field"><label>Color primario</label><p>{{ $color_primario ?? '{{ $marcaColor }}' }}</p></div>
            <div class="field" style="margin-top:5px;"><label>Color secundario</label><p>{{ $color_secundario ?? '#F8FAFC' }}</p></div>
            <div class="field" style="margin-top:5px;"><label>Color texto</label><p>{{ $color_texto ?? '#1A1A1A' }}</p></div>
        </div>
        <div>
            <div class="field"><label>Fuente</label><p>{{ $fuente ?? 'Arial' }}</p></div>
            <div class="field" style="margin-top:5px;"><label>Tamaño fuente</label><p>{{ $tamanio_fuente ?? 10 }}px</p></div>
            <div class="field" style="margin-top:5px;"><label>Logo</label><p>{{ ($mostrar_logo ?? true) ? 'Visible' : 'Oculto' }}</p></div>
        </div>
    </div>
</div>

<!-- Pie de página -->
@if($mostrar_pie ?? true)
<div class="footer">
    @if($pie_html ?? null)
        {!! $pie_html !!}
    @else
        <span>{{ $pie_texto ?? $marcaNombre }}</span>
        <span class="brand">{{ $marcaWeb }}</span>
    @endif
</div>
@endif

</body>
</html>
