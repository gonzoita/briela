<!DOCTYPE html>
<html lang="es">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 9px; color: #111; background: #fff; }

.page-break { page-break-after: always; }
.pagenum:before { content: counter(page); }

/* ── Header ── */
.header { background: {{ $marcaColor }}; color: #fff; padding: 12px 18px; }
.header-table { display: table; width: 100%; }
.header-brand { display: table-cell; vertical-align: middle; width: 110px; }
.header-brand-text { font-size: 20px; font-weight: bold; letter-spacing: 1px; color: #fff; }
.header-center { display: table-cell; vertical-align: middle; text-align: center; padding: 0 10px; }
.header-center h1 { font-size: 9px; font-weight: bold; letter-spacing: 0.6px; text-transform: uppercase; opacity: 0.80; }
.header-center h2 { font-size: 18px; font-weight: bold; letter-spacing: 0.2px; margin-top: 2px; }
.header-right { display: table-cell; vertical-align: middle; text-align: right; font-size: 8.5px; line-height: 1.75; width: 190px; }

/* ── Bloque producto ── */
.producto-block { padding: 10px 18px; border-bottom: 1px solid #E5E7EB; }
.two-col { display: table; width: 100%; }
.col-left { display: table-cell; vertical-align: top; width: 58%; padding-right: 14px; }
.col-right { display: table-cell; vertical-align: top; width: 42%; text-align: center; }

.product-name { font-size: 13px; font-weight: bold; color: {{ $marcaColor }}; margin-bottom: 4px; }
.product-ref { font-size: 9px; color: #6B7280; margin-bottom: 8px; }

.field-row { display: table; width: 100%; margin-bottom: 4px; }
.field-label { display: table-cell; width: 110px; font-size: 8px; color: #9CA3AF; font-weight: bold; text-transform: uppercase; letter-spacing: 0.3px; padding-top: 1px; }
.field-value { display: table-cell; font-size: 9px; color: #111; font-weight: bold; }

.ficha-box { background: #F9FAFB; border: 1px solid #E5E7EB; border-radius: 3px; padding: 7px 9px; margin-top: 7px; margin-bottom: 7px; }
.ficha-title { font-size: 8px; font-weight: bold; color: #6B7280; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 4px; }
.ficha-text { font-size: 9px; color: #374151; line-height: 1.5; }

.vars-title { font-size: 8px; font-weight: bold; color: #6B7280; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 4px; margin-top: 7px; }
table.vars { width: 100%; border-collapse: collapse; }
table.vars td { border: 1px solid #E5E7EB; padding: 4px 7px; font-size: 9px; vertical-align: middle; }
table.vars td:first-child { background: #F3F4F6; font-weight: bold; color: #374151; width: 45%; }
table.vars td:last-child { color: #111; }
.var-img { width: 60px; height: 60px; object-fit: contain; border-radius: 3px; margin-top: 3px; display: block; }

.img-product { max-width: 100%; max-height: 220px; object-fit: contain; border: 1px solid #E5E7EB; border-radius: 3px; margin-bottom: 10px; }
.qr-label { font-size: 8px; color: #9CA3AF; text-transform: uppercase; font-weight: bold; letter-spacing: 0.3px; margin-bottom: 4px; }
.qr-img { width: 100px; height: 100px; }

/* ── Materiales ── */
.section-block { padding: 8px 18px; }
.section-title { font-size: 9px; font-weight: bold; color: #374151; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 6px; padding-bottom: 4px; border-bottom: 1px solid #E5E7EB; }

.seccion-header { background: {{ $marcaColor }}; color: #fff; padding: 4px 8px; font-size: 8px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.4px; margin-top: 6px; margin-bottom: 0; border-radius: 2px 2px 0 0; }

table.materiales { width: 100%; border-collapse: collapse; }
table.materiales th { background: #F3F4F6; border: 1px solid #D1D5DB; padding: 4px 7px; font-size: 8px; font-weight: bold; text-align: left; text-transform: uppercase; color: #374151; }
table.materiales td { border: 1px solid #E5E7EB; padding: 4px 7px; font-size: 9px; }
table.materiales td.num { text-align: right; }

/* ── Footer ── */
.footer { position: fixed; bottom: 0; left: 0; right: 0; border-top: 1px solid #E5E7EB; padding: 5px 18px; font-size: 7.5px; color: #9CA3AF; display: table; width: 100%; }
.footer-left { display: table-cell; }
.footer-right { display: table-cell; text-align: right; }
</style>
</head>
<body>

<div class="footer">
    <span class="footer-left">SGI Interfrigo &mdash; Generado: {{ $fecha }}</span>
    <span class="footer-right">Página <span class="pagenum"></span></span>
</div>

@foreach($itemsData as $idx => $entry)
@php
    $item            = $entry['item'];
    $comps           = $entry['compsPorSeccion'];
    $camposIndexados = $entry['camposIndexados'];
    $codigoItem      = $entry['codigo_item'];
    $esUltimo        = $loop->last;
@endphp

<div @if(!$esUltimo) class="page-break" @endif>

    {{-- HEADER --}}
    <div class="header">
        <div class="header-table">
            <div class="header-brand">
                <span class="header-brand-text">INTERFRIGO</span>
            </div>
            <div class="header-center">
                <h1>ORDEN DE PRODUCCIÓN</h1>
                <h2>{{ $op->numero }}</h2>
            </div>
            <div class="header-right">
                Cliente: {{ $op->cliente ? trim($op->cliente->nombre . ' ' . ($op->cliente->apellido ?? '')) : '—' }}<br>
                Código: {{ $codigoItem }}<br>
                Fecha: {{ now()->setTimezone('America/Bogota')->format('d/m/Y') }}<br>
                Ítem {{ $loop->iteration }} de {{ $itemsData->count() }}
            </div>
        </div>
    </div>

    {{-- BLOQUE PRODUCTO --}}
    <div class="producto-block">
        <div class="two-col">
            <div class="col-left">
                <div class="product-name">{{ $item->descripcion }}</div>
                @if($item->ensamble?->descripcion_corta)
                <div class="product-ref">Ref: {{ $item->ensamble->descripcion_corta }}</div>
                @endif

                <div class="field-row">
                    <span class="field-label">N.º de serie:</span>
                    <span class="field-value">{{ $item->numero_serie ?? 'N/A' }}</span>
                </div>
                <div class="field-row">
                    <span class="field-label">Cantidad:</span>
                    <span class="field-value">{{ (float) $item->cantidad }}</span>
                </div>

                @if($item->descripcion_larga)
                <div class="ficha-box">
                    <div class="ficha-title">Ficha Técnica</div>
                    <div class="ficha-text">{{ strip_tags($item->descripcion_larga) }}</div>
                </div>
                @endif

                @if($item->variables_instancia && count($item->variables_instancia))
                <div class="vars-title">Variables del Producto</div>
                <table class="vars">
                    @foreach($item->variables_instancia as $key => $val)
                    @php
                        $campo       = $camposIndexados->get($key);
                        $etiqueta    = $campo?->etiqueta ?? $key;
                        $opcSelector = $campo?->opciones_selector;
                        if (is_string($opcSelector)) { $opcSelector = json_decode($opcSelector, true); }
                        $opcionImg   = null;
                        if (is_array($opcSelector) && count($opcSelector) > 0) {
                            $opcionEncontrada = collect($opcSelector)->firstWhere('valor', $val);
                            if (!empty($opcionEncontrada['imagen'])) {
                                $opcionImg = public_path('storage/' . $opcionEncontrada['imagen']);
                            }
                        }
                    @endphp
                    <tr>
                        <td>{{ $etiqueta }}</td>
                        <td>
                            {{ is_array($val) ? implode(', ', $val) : $val }}
                            @if($opcionImg)
                            <img src="{{ $opcionImg }}" class="var-img" alt="{{ $etiqueta }}">
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </table>
                @endif

                @if($item->notas_item)
                <div class="ficha-box" style="margin-top:6px;">
                    <div class="ficha-title">Notas</div>
                    <div class="ficha-text">{{ $item->notas_item }}</div>
                </div>
                @endif
            </div>

            <div class="col-right">
                @if($item->ensamble?->imagen_principal)
                <img class="img-product"
                     src="{{ public_path('storage/' . $item->ensamble->imagen_principal) }}"
                     alt="{{ $item->descripcion }}">
                @endif

                <div class="qr-label">QR Seguimiento</div>
                <img class="qr-img" src="{{ $qrOp }}" alt="QR">
                <div style="font-size:7px;color:#9CA3AF;margin-top:2px;">{{ $op->numero }}</div>
            </div>
        </div>
    </div>

    {{-- MATERIALES REQUERIDOS --}}
    @if($comps->count())
    <div class="section-block">
        <div class="section-title">Materiales Requeridos</div>

        @foreach($comps as $seccion => $padresList)
        <div class="seccion-header">{{ strtoupper($seccion) }}</div>
        <table class="materiales">
            <thead>
                <tr>
                    <th style="width:42%">Descripción</th>
                    <th style="width:22%">Referencia</th>
                    <th style="width:14%;text-align:right">Cantidad</th>
                    <th style="width:22%">Unidad</th>
                </tr>
            </thead>
            <tbody>
                @foreach($padresList as $padre)
                <tr>
                    <td style="font-weight:bold;">{{ strtoupper($padre->nombre) }}</td>
                    <td>{{ $padre->producto?->referencia ?? '' }}</td>
                    <td class="num">{{ rtrim(rtrim(number_format((float)$padre->cantidad, 3, '.', ''), '0'), '.') }}</td>
                    <td>{{ $padre->unidad ?? '—' }}</td>
                </tr>
                @foreach($padre->hijos as $hijo)
                <tr style="background:#f9fafb;">
                    <td style="padding-left:16px;color:#6B7280;">&#8627; {{ $hijo->nombre }}</td>
                    <td style="color:#9CA3AF;"></td>
                    <td class="num" style="color:#6B7280;">{{ rtrim(rtrim(number_format((float)$hijo->cantidad, 3, '.', ''), '0'), '.') }}</td>
                    <td style="color:#9CA3AF;">{{ $hijo->unidad ?? '—' }}</td>
                </tr>
                @endforeach
                @endforeach
            </tbody>
        </table>
        @endforeach
    </div>
    @endif

</div>
@endforeach

</body>
</html>
