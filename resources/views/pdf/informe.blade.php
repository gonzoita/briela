<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1F2937; }

    .header {
        background-color: {{ $marcaColor }};
        color: white;
        padding: 14px 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .header-left { display: flex; align-items: center; gap: 12px; }
    .header-logo { width: 70px; background: white; padding: 4px; border-radius: 4px; }
    .header-empresa { }
    .header-empresa .titulo { font-size: 13px; font-weight: bold; }
    .header-empresa .subtitulo { font-size: 9px; opacity: 0.75; margin-top: 2px; }
    .header-right { text-align: right; }
    .header-right .nombre { font-size: 13px; font-weight: bold; }
    .header-right .fecha { font-size: 9px; opacity: 0.75; margin-top: 2px; }

    .meta {
        padding: 10px 20px;
        background: #F9FAFB;
        border-bottom: 1px solid #E5E7EB;
        display: flex;
        gap: 20px;
        flex-wrap: wrap;
    }
    .meta-item label { font-size: 8px; color: #9CA3AF; display: block; }
    .meta-item p { font-size: 9px; font-weight: 600; color: #374151; }

    .badge {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 10px;
        font-size: 8px;
        font-weight: bold;
        background: #DBEAFE;
        color: #1D4ED8;
    }

    .contenido { padding: 14px 20px; }

    table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    thead th {
        background-color: {{ $marcaColor }};
        color: white;
        padding: 6px 8px;
        text-align: left;
        font-size: 8px;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }
    tbody tr:nth-child(even) { background-color: #F9FAFB; }
    tbody td {
        padding: 5px 8px;
        font-size: 8.5px;
        border-bottom: 1px solid #F3F4F6;
        vertical-align: top;
    }

    .footer {
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        padding: 6px 20px;
        border-top: 1px solid #E5E7EB;
        display: flex;
        justify-content: space-between;
        font-size: 7.5px;
        color: #9CA3AF;
    }

    .vacio { text-align: center; padding: 30px; color: #9CA3AF; font-size: 10px; }
</style>
</head>
<body>

    <!-- Header -->
    <div class="header">
        <div class="header-left">
            <img
                src="{{ public_path('images/logo.png') }}"
                class="header-logo"
                alt="{{ $marcaNombre }}"
                onerror="this.style.display='none'"
            />
            <div class="header-empresa">
                <div class="titulo">{{ $marcaNombre }}</div>
                <div class="subtitulo">Sistema de Gestión Integral</div>
            </div>
        </div>
        <div class="header-right">
            <div class="nombre">{{ $informe->nombre }}</div>
            <div class="fecha">Generado: {{ $fecha }}</div>
        </div>
    </div>

    <!-- Metadatos -->
    <div class="meta">
        <div class="meta-item">
            <label>Fuente</label>
            <p>
                @php
                $fuenteLabels = [
                    'ops'           => 'Órdenes de Producción',
                    'colaboradores' => 'Colaboradores',
                    'cotizaciones'  => 'Cotizaciones',
                    'pasos'         => 'Pasos de Trabajo',
                ];
                @endphp
                {{ $fuenteLabels[$informe->fuente] ?? $informe->fuente }}
            </p>
        </div>
        <div class="meta-item">
            <label>Total registros</label>
            <p>{{ count($datos) }}</p>
        </div>
        @if($informe->descripcion)
        <div class="meta-item">
            <label>Descripción</label>
            <p>{{ $informe->descripcion }}</p>
        </div>
        @endif
        @if($informe->publico)
        <div class="meta-item">
            <label>Visibilidad</label>
            <p><span class="badge">Público</span></p>
        </div>
        @endif
    </div>

    <!-- Contenido / Tabla -->
    <div class="contenido">
        @if(count($datos) === 0)
            <div class="vacio">No se encontraron registros con los filtros configurados.</div>
        @else
            <table>
                <thead>
                    <tr>
                        @foreach($informe->campos as $campo)
                            <th>{{ $etiquetas[$campo] ?? $campo }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($datos as $fila)
                        <tr>
                            @foreach($informe->campos as $campo)
                                <td>{{ $fila[$campo] ?? '—' }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
                @if(!empty($totales))
                <tfoot>
                    <tr style="background:#EFF6FF; font-weight:bold; color:{{ $marcaColor }};">
                        @foreach($informe->campos as $i => $campo)
                            <td style="padding:6px 8px; border-top:2px solid {{ $marcaColor }}; font-size:8.5px;">
                                @if(isset($totales[$campo]))
                                    <span style="font-size:7px; color:#6B7280; text-transform:uppercase;">{{ $totales[$campo]['tipo'] === 'promedio' ? 'Prom.' : 'Total' }}</span>
                                    {{ $totales[$campo]['texto'] }}
                                @elseif($i === 0)
                                    Totales
                                @endif
                            </td>
                        @endforeach
                    </tr>
                </tfoot>
                @endif
            </table>
        @endif
    </div>

    <!-- Footer -->
    <div class="footer">
        <span>{{ $marcaNombre }}</span>
        <span>{{ $fecha }}</span>
    </div>

</body>
</html>
