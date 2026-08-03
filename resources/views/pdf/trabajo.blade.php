<!DOCTYPE html>
<html lang="es">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<style>
@page { margin: 12mm 15mm 12mm 15mm; }
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 10px; color: #111; margin: 0 5px; }

.header { background: {{ $marcaColor }}; color: white; padding: 8px 12px; }
.header-inner { display: table; width: 100%; }
.header-text { display: table-cell; vertical-align: middle; }
.header-logo { display: table-cell; width: 80px; text-align: right; vertical-align: middle; }
.header h1 { font-size: 12px; font-weight: bold; }
.header p { font-size: 9px; opacity: 0.85; margin-top: 2px; }
.header img { height: 48px; width: auto; }

.section { padding: 8px 15px; border-bottom: 1px solid #E5E7EB; }
.section-title { font-size: 8px; font-weight: bold; color: #6B7280; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 3px; }

.grid-2 { display: table; width: 100%; }
.col { display: table-cell; width: 50%; vertical-align: top; padding-right: 10px; }
.col:last-child { padding-right: 0; padding-left: 10px; }

.field { margin-bottom: 5px; }
.field-label { font-size: 8px; color: #9CA3AF; }
.field-value { font-size: 11px; font-weight: bold; color: #111; }

.variable-box { border: 1px solid #FDE68A; background: #FFFBEB; padding: 4px 6px; margin-bottom: 3px; border-radius: 4px; display: table; width: 100%; }
.variable-text { display: table-cell; vertical-align: middle; }
.variable-label { font-size: 8px; color: #92400E; font-weight: bold; text-transform: uppercase; }
.variable-value { font-size: 11px; font-weight: bold; color: #78350F; }
.variable-img { display: table-cell; width: 65px; vertical-align: middle; text-align: right; }
.variable-img img { width: 60px; height: 60px; object-fit: contain; }

table.componentes { width: 100%; border-collapse: collapse; margin-top: 4px; }
table.componentes th { background: #F3F4F6; border: 1px solid #D1D5DB; padding: 3px 6px; font-size: 8px; font-weight: bold; text-align: left; text-transform: uppercase; color: #374151; }
table.componentes td { border: 1px solid #E5E7EB; padding: 3px 6px; font-size: 9px; }
table.componentes tr:nth-child(even) td { background: #F9FAFB; }

.paso-row { border: 1px solid #E5E7EB; padding: 5px 8px; margin-bottom: 3px; border-radius: 4px; display: table; width: 100%; }
.paso-check { display: table-cell; width: 20px; vertical-align: top; padding-top: 1px; }
.paso-check .box { width: 14px; height: 14px; border: 2px solid #D1D5DB; border-radius: 2px; }
.paso-body { display: table-cell; vertical-align: top; padding-left: 8px; }
.paso-nombre { font-size: 9px; font-weight: bold; color: #111; }
.paso-desc { font-size: 9px; color: #6B7280; margin-top: 2px; }
.paso-peso { display: table-cell; width: 35px; text-align: right; vertical-align: top; font-size: 9px; color: #9CA3AF; }
.paso-horas { display: table; width: 100%; margin-top: 6px; border-top: 1px dashed #E5E7EB; padding-top: 5px; }
.paso-hora-col { display: table-cell; width: 50%; }
.hora-label { font-size: 8px; color: #9CA3AF; }
.hora-line { border-bottom: 1px solid #D1D5DB; margin-top: 10px; width: 80%; }

.obs-box { border: 1px dashed #D1D5DB; padding: 6px 8px; margin-top: 4px; min-height: 30px; border-radius: 3px; }
.obs-label { font-size: 8px; color: #9CA3AF; }

.qr-section { display: table; width: 100%; padding: 6px 0; }
.qr-col { display: table-cell; width: 50%; vertical-align: top; text-align: center; padding: 5px; }
.qr-label { font-size: 8px; color: #6B7280; margin-bottom: 4px; font-weight: bold; text-transform: uppercase; }

.firma-section { display: table; width: 100%; margin-top: 10px; padding: 0 20px; }
.firma-col { display: table-cell; width: 50%; padding: 0 10px; text-align: center; }
.firma-line { border-top: 1px solid #374151; margin-top: 20px; padding-top: 4px; font-size: 9px; color: #374151; }

.serie-mono { font-family: DejaVu Sans Mono, monospace; font-weight: bold; color: {{ $marcaColor }}; }
</style>
</head>
<body>

{{-- HEADER --}}
<div class="header">
    <div class="header-inner">
        <div class="header-text">
            <h1>Orden de Trabajo — Interfrigo SAS</h1>
            <p>{{ $op->numero }} &nbsp;·&nbsp; Unidad {{ $trabajo->numero_unidad }}/{{ $trabajo->total_unidades }} &nbsp;·&nbsp; Generado: {{ $fecha }}</p>
        </div>
        @if(file_exists($logoPath))
        <div class="header-logo">
            <img src="{{ $logoPath }}" alt="Interfrigo"/>
        </div>
        @endif
    </div>
</div>

{{-- INFO GENERAL --}}
<div class="section">
    <div class="grid-2">
        <div class="col">
            <div class="field">
                <div class="field-label">Orden de Producción</div>
                <div class="field-value">{{ $op->numero }}</div>
            </div>
            <div class="field">
                <div class="field-label">Cliente</div>
                <div class="field-value">{{ $op->cliente?->nombre ?? '—' }}</div>
            </div>
            <div class="field">
                <div class="field-label">Ítem</div>
                <div class="field-value">{{ $item->descripcion }}</div>
            </div>
        </div>
        <div class="col">
            <div class="field">
                <div class="field-label">Template de trabajo</div>
                <div class="field-value">{{ $trabajo->template?->nombre ?? '—' }}</div>
            </div>
            <div class="field">
                <div class="field-label">Unidad</div>
                <div class="field-value">{{ $trabajo->numero_unidad }} de {{ $trabajo->total_unidades }}</div>
            </div>
            <div class="field">
                <div class="field-label">Número de serie</div>
                <div class="field-value"><span class="serie-mono">{{ $item->numero_serie ?? '—' }}</span></div>
            </div>
        </div>
    </div>
</div>

{{-- VARIABLES --}}
@if($item->variables_instancia && count($item->variables_instancia))
<div class="section">
    <div class="section-title">Variables del producto</div>
    @foreach($item->variables_instancia as $key => $val)
        @php $campo = collect($camposPlantilla)->firstWhere('nombre', $key); @endphp
        <div class="variable-box">
            <div class="variable-text">
                <div class="variable-label">{{ $campo['etiqueta'] ?? $key }}</div>
                <div class="variable-value">{{ $val }}</div>
            </div>
            @if($campo && !empty($campo['imagen']) && file_exists($campo['imagen']))
            <div class="variable-img">
                <img src="{{ $campo['imagen'] }}" alt="{{ $campo['etiqueta'] ?? $key }}"/>
            </div>
            @endif
        </div>
    @endforeach
</div>
@endif

{{-- COMPONENTES --}}
@if($item->componentes->count())
<div class="section">
    <div class="section-title">Materiales requeridos</div>
    <table class="componentes">
        <thead>
            <tr>
                <th>Material</th>
                <th>Referencia</th>
                <th style="width:60px; text-align:center;">Cantidad</th>
                <th style="width:40px;">Und.</th>
                <th style="width:120px;">Observación</th>
            </tr>
        </thead>
        <tbody>
            @foreach($componentesOrdenados as $fila)
            @php $comp = $fila['comp']; $esHijo = $fila['es_hijo']; @endphp
            @if($esHijo)
            <tr style="background:#F9FAFB;">
                <td style="padding-left:20px; color:#6B7280; font-size:8px;">&#8627; {{ strip_tags($comp->nombre) }}</td>
                <td style="color:#D1D5DB;">—</td>
                <td style="text-align:center; color:#374151;">{{ rtrim(rtrim(number_format((float)$comp->cantidad, 3), '0'), '.') }}</td>
                <td style="color:#9CA3AF;">{{ $comp->unidad }}</td>
                <td></td>
            </tr>
            @else
            <tr>
                <td style="font-weight:bold;">{{ strip_tags($comp->nombre) }}</td>
                <td style="font-family:DejaVu Sans Mono,monospace; color:#6B7280;">{{ $comp->producto?->referencia ?? '—' }}</td>
                <td style="text-align:center; font-weight:bold;">{{ rtrim(rtrim(number_format((float)$comp->cantidad, 3), '0'), '.') }}</td>
                <td>{{ $comp->unidad }}</td>
                <td style="color:#9CA3AF; font-size:8px;">{{ $comp->observacion ?? '' }}</td>
            </tr>
            @endif
            @endforeach
        </tbody>
    </table>
</div>
@endif

{{-- PASOS --}}
<div class="section">
    <div class="section-title">Pasos del trabajo</div>
    @foreach($trabajo->pasos as $paso)
    <div class="paso-row">
        <div class="paso-check">
            <div class="box"></div>
        </div>
        <div class="paso-body">
            <div class="paso-nombre">{{ $paso->nombre }}</div>
            @if($paso->descripcion_resuelta)
            <div class="paso-desc">{{ $paso->descripcion_resuelta }}</div>
            @endif
            @if($paso->fotos && count($paso->fotos) > 0)
            <div style="margin-top:5px;">
                <div style="font-size:8px;color:#9CA3AF;margin-bottom:3px;">Evidencias fotográficas:</div>
                <div style="display:flex;flex-wrap:wrap;gap:3px;">
                    @foreach($paso->fotos as $fotoPath)
                    @php
                        $fullPath = storage_path('app/public/' . $fotoPath);
                    @endphp
                    @if(file_exists($fullPath))
                    @php
                        $ext   = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
                        $mime  = match($ext) { 'png' => 'image/png', 'webp' => 'image/webp', default => 'image/jpeg' };
                        $b64   = base64_encode(file_get_contents($fullPath));
                    @endphp
                    <img src="data:{{ $mime }};base64,{{ $b64 }}"
                         style="width:72px;height:72px;object-fit:cover;border-radius:3px;border:1px solid #E5E7EB;" />
                    @endif
                    @endforeach
                </div>
            </div>
            @endif
            <div class="paso-horas">
                <div class="paso-hora-col">
                    <div class="hora-label">Hora inicio</div>
                    <div class="hora-line"></div>
                </div>
                <div class="paso-hora-col">
                    <div class="hora-label">Hora fin</div>
                    <div class="hora-line"></div>
                </div>
            </div>
        </div>
        <div class="paso-peso">{{ $paso->peso_porcentaje }}%</div>
    </div>
    @endforeach
</div>

{{-- OBSERVACIONES --}}
<div class="section">
    <div class="section-title">Observaciones generales</div>
    <div class="obs-box">
        <div class="obs-label">Escribir aquí...</div>
    </div>
</div>

{{-- FIRMAS --}}
<div class="section" style="border-bottom:none;">
    <div class="section-title">Firmas</div>
    <div class="firma-section">
        <div class="firma-col">
            <div class="firma-line">Operario responsable</div>
        </div>
        <div class="firma-col">
            <div class="firma-line">Jefe de producción</div>
        </div>
    </div>
</div>

{{-- QR CODES --}}
<div class="qr-section">
    <div class="qr-col">
        <div class="qr-label">Estado de este trabajo</div>
        @if($trabajo->token_trabajo)
        <img src="{{ $qrTrabajo }}" width="90" height="90" alt="QR Trabajo"/>
        <div style="font-size:8px; color:#9CA3AF; margin-top:3px;">Escanea para ver avance</div>
        @endif
    </div>
    <div class="qr-col">
        <div class="qr-label">Estado general OP</div>
        @if($op->token_publico)
        <img src="{{ $qrOp }}" width="90" height="90" alt="QR OP"/>
        <div style="font-size:8px; color:#9CA3AF; margin-top:3px;">{{ $op->numero }}</div>
        @endif
    </div>
</div>

</body>
</html>
