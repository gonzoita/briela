{{-- Un informe del motor (leads, ventas, cartera…): cifras clave, análisis, resúmenes y el detalle. Tablas y no flex: dompdf no entiende flex. --}}
@php
    $fmt = function ($v, $tipo) {
        if ($v === null || $v === '') return '—';
        return match (true) {
            $tipo === 'bool'                          => $v ? 'Sí' : 'No',
            $tipo === 'moneda' && is_numeric($v)      => '$ '.number_format((float) $v, 0, ',', '.'),
            $tipo === 'pct' && is_numeric($v)         => str_replace('.', ',', (string) $v).' %',
            $tipo === 'numero' && is_numeric($v)      => number_format((float) $v, 1, ',', '.'),
            $tipo === 'entero' && is_numeric($v)      => number_format((float) $v, 0, ',', '.'),
            $tipo === 'fecha'                         => \Illuminate\Support\Carbon::parse($v)->format('d/m/Y'),
            default                                   => (string) $v,
        };
    };
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
    * { margin: 0; padding: 0; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 8.5px; color: #1F2937; padding: 18px 22px; }
    h1 { font-size: 15px; margin-bottom: 2px; }
    h2 { font-size: 10.5px; margin: 14px 0 5px; color: {{ $marcaColor ?? '#2563EB' }}; }
    .sub { color: #6B7280; font-size: 8.5px; margin-bottom: 8px; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #F3F4F6; text-align: left; padding: 4px 5px; font-size: 8px; border-bottom: 1px solid #D1D5DB; }
    td { padding: 3px 5px; border-bottom: 1px solid #F3F4F6; vertical-align: top; }
    td.num, th.num { text-align: right; }
    tr.total td { font-weight: bold; border-top: 1px solid #9CA3AF; }
    .cifras td { width: 25%; padding: 6px; border: 1px solid #E5E7EB; }
    .cifras .l { color: #6B7280; font-size: 7.5px; }
    .cifras .v { font-size: 11px; font-weight: bold; margin-top: 2px; }
    .analisis { white-space: pre-line; line-height: 1.45; font-size: 9px; }
</style>
</head>
<body>
    <h1>{{ $informe->nombre }}</h1>
    <p class="sub">{{ $r['fuente_etiqueta'] }} · {{ $r['periodo'] }} · {{ number_format($r['total_filas'], 0, ',', '.') }} filas · generado el {{ $fecha }}</p>
    @if($informe->pregunta)<p class="sub">Pregunta: {{ $informe->pregunta }}</p>@endif

    <h2>Cifras clave</h2>
    <table class="cifras">
        @foreach(array_chunk($r['metricas'], 4) as $fila)
        <tr>
            @foreach($fila as $m)
            <td><div class="l">{{ $m['etiqueta'] }}</div><div class="v">{{ $fmt($m['valor'], $m['tipo']) }}</div></td>
            @endforeach
            @for($i = count($fila); $i < 4; $i++)<td></td>@endfor
        </tr>
        @endforeach
    </table>

    @if($informe->analisis)
    <h2>Análisis</h2>
    <div class="analisis">{{ $informe->analisis }}</div>
    @endif

    @foreach($r['resumenes'] as $res)
    <h2>{{ \Illuminate\Support\Str::ucfirst($res['titulo']) }}</h2>
    <table>
        <tr>@foreach($res['columnas'] as $c)<th class="{{ $c['tipo'] !== 'texto' ? 'num' : '' }}">{{ $c['etiqueta'] }}</th>@endforeach</tr>
        @foreach($res['filas'] as $f)
        <tr>@foreach($res['columnas'] as $c)<td class="{{ $c['tipo'] !== 'texto' ? 'num' : '' }}">{{ $fmt($f[$c['clave']] ?? null, $c['tipo']) }}</td>@endforeach</tr>
        @endforeach
        <tr class="total">@foreach($res['columnas'] as $c)<td class="{{ $c['tipo'] !== 'texto' ? 'num' : '' }}">{{ $fmt($res['total'][$c['clave']] ?? null, $c['tipo']) }}</td>@endforeach</tr>
    </table>
    @endforeach

    <h2>Detalle{{ count($filas) < $r['total_filas'] ? ' (primeras '.count($filas).' de '.number_format($r['total_filas'], 0, ',', '.').'; el resto en el Excel)' : '' }}</h2>
    <table>
        <tr>@foreach($r['columnas'] as $c)<th class="{{ in_array($c['tipo'], ['moneda','numero','entero','pct']) ? 'num' : '' }}">{{ $c['etiqueta'] }}</th>@endforeach</tr>
        @foreach($filas as $f)
        <tr>@foreach($r['columnas'] as $c)<td class="{{ in_array($c['tipo'], ['moneda','numero','entero','pct']) ? 'num' : '' }}">{{ $fmt($f[$c['clave']] ?? null, $c['tipo']) }}</td>@endforeach</tr>
        @endforeach
    </table>
</body>
</html>
