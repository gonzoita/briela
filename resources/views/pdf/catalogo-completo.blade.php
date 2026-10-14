<!DOCTYPE html>
<html lang="es">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
{{-- El catálogo completo, siempre sin precios (ver CatalogoPdfService). dompdf no entiende
     flexbox: el armado es con tablas. --}}
<style>
@page { margin: 14mm 12mm; }
* { box-sizing: border-box; }
body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; color: #1a1a1a; }
.portada { border-bottom: 3px solid {{ $marcaColor }}; padding-bottom: 8px; margin-bottom: 12px; }
.portada td { vertical-align: middle; }
.portada img { height: 40px; }
.empresa { font-size: 16px; font-weight: bold; color: {{ $marcaColor }}; }
.sub { font-size: 9px; color: #6b7280; }
h2 { font-size: 12px; color: #fff; background: {{ $marcaColor }}; padding: 5px 8px; margin: 14px 0 6px; }
table.ficha { width: 100%; border-collapse: collapse; border-bottom: 1px solid #e5e7eb; page-break-inside: avoid; }
table.ficha td { vertical-align: top; padding: 7px 4px; }
.foto { width: 78px; }
.foto img { width: 72px; height: 72px; object-fit: contain; border: 1px solid #e5e7eb; }
.sinfoto { width: 72px; height: 72px; border: 1px solid #e5e7eb; background: #f9fafb; }
.nombre { font-size: 11px; font-weight: bold; }
.ref { font-size: 8.5px; color: #6b7280; }
.dato { font-size: 8.5px; color: #374151; margin-top: 2px; }
.desc { margin-top: 3px; color: #374151; }
table.carac { border-collapse: collapse; margin-top: 4px; }
table.carac td { padding: 1px 8px 1px 0; font-size: 8.5px; }
.etiqueta { color: #6b7280; }
.variantes { margin-top: 4px; font-size: 8.5px; }
.pie { position: fixed; bottom: -8mm; left: 0; right: 0; font-size: 8px; color: #9ca3af; text-align: center; }
</style>
</head>
<body>
<div class="pie">{{ $marcaNombre }} · Catálogo sin precios · {{ $fecha }} · Para precios, pide una cotización.</div>

<table class="portada" width="100%"><tr>
    <td>
        <div class="empresa">{{ $marcaNombre }}</div>
        <div class="sub">Catálogo de productos · {{ $total }} {{ $total === 1 ? 'referencia' : 'referencias' }} · {{ $fecha }}</div>
    </td>
    @if (! empty($marcaLogoPath) && is_file($marcaLogoPath))
        <td style="text-align:right;"><img src="{{ $marcaLogoPath }}" alt=""></td>
    @endif
</tr></table>

@forelse ($grupos as $categoria => $fichas)
    <h2>{{ $categoria }}</h2>
    @foreach ($fichas as $f)
        <table class="ficha"><tr>
            <td class="foto">
                @if ($f['imagen'])<img src="{{ $f['imagen'] }}" alt="">@else<div class="sinfoto"></div>@endif
            </td>
            <td>
                <div class="nombre">{{ $f['nombre'] }}</div>
                <div class="ref">Ref. {{ $f['referencia'] }}</div>
                @if ($f['marca'] || $f['origen'])
                    <div class="dato">
                        @if ($f['marca'])<span class="etiqueta">Marca:</span> {{ $f['marca'] }}@endif
                        @if ($f['marca'] && $f['origen']) · @endif
                        @if ($f['origen'])<span class="etiqueta">Origen:</span> {{ $f['origen'] }}@endif
                    </div>
                @endif
                @if ($f['descripcion'])<div class="desc">{{ $f['descripcion'] }}</div>@endif
                @if (! empty($f['caracteristicas']))
                    <table class="carac">
                        @foreach ($f['caracteristicas'] as $c)
                            <tr><td class="etiqueta">{{ $c['nombre'] ?? '' }}</td><td>{{ $c['valor'] ?? '' }}</td></tr>
                        @endforeach
                    </table>
                @endif
                @if (! empty($f['variantes']))
                    <div class="variantes">
                        <span class="etiqueta">{{ $f['atributo'] ?: 'Opciones' }}:</span>
                        @foreach ($f['variantes'] as $v){{ $v['valor'] }}@if ($v['referencia']) ({{ $v['referencia'] }})@endif{{ $loop->last ? '' : ' · ' }}@endforeach
                    </div>
                @endif
            </td>
        </tr></table>
    @endforeach
@empty
    <p class="sub">No hay productos para mostrar.</p>
@endforelse
</body>
</html>
