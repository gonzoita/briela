<!DOCTYPE html>
<html lang="es">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: DejaVu Sans, Arial, sans-serif;
    font-size: 9px;
    color: #111;
    background: #fff;
    width: 283.46pt;
}

.header {
    background: #0A4283;
    color: #fff;
    text-align: center;
    padding: 5px 6px 4px;
    font-size: 11px;
    font-weight: bold;
    letter-spacing: 1.5px;
}
.header img { height: 22px; width: auto; vertical-align: middle; margin-right: 6px; }

.product-name {
    font-size: 12px;
    font-weight: bold;
    color: #0A4283;
    text-align: center;
    padding: 6px 8px 2px;
    line-height: 1.3;
}

.op-line {
    text-align: center;
    font-size: 8px;
    color: #6B7280;
    padding: 0 8px 5px;
}

.divider { border-top: 1px solid #E5E7EB; margin: 0 8px; }

/* Variables */
.vars-block { padding: 5px 8px; }
table.vars { width: 100%; border-collapse: collapse; }
table.vars td { padding: 2px 4px; font-size: 8px; vertical-align: top; border-bottom: 1px solid #F3F4F6; }
table.vars td:first-child { color: #6B7280; font-weight: bold; width: 45%; }
table.vars td:last-child { color: #111; font-weight: bold; }

/* QR */
.qr-block { text-align: center; padding: 6px 8px 4px; }
.qr-block img { width: 130px; height: 130px; }

/* Serie */
.serie-block {
    text-align: center;
    padding: 3px 8px 6px;
    font-size: 8.5px;
    color: #374151;
}
.serie-block strong { font-size: 10px; display: block; color: #0A4283; margin-top: 1px; }

/* Referencia */
.ref-block {
    text-align: center;
    font-size: 7.5px;
    color: #9CA3AF;
    padding-bottom: 5px;
}
</style>
</head>
<body>

<div class="header">
    <img src="{{ $logoPath }}" alt=""> INTERFRIGO
</div>

<div class="product-name">{{ $item->descripcion }}</div>

<div class="op-line">
    OP: <strong>{{ $op->numero }}</strong>
    &nbsp;|&nbsp;
    Cliente: {{ $op->cliente?->nombre }} {{ $op->cliente?->apellido }}
</div>

@if($item->ensamble?->descripcion_corta)
<div class="ref-block">Ref: {{ $item->ensamble->descripcion_corta }}</div>
@endif

<div class="divider"></div>

@if($item->variables_instancia && count($item->variables_instancia))
<div class="vars-block">
    <table class="vars">
        @foreach($item->variables_instancia as $key => $val)
        <tr>
            <td>{{ $key }}</td>
            <td>{{ $val }}</td>
        </tr>
        @endforeach
    </table>
</div>
<div class="divider"></div>
@endif

<div class="qr-block">
    <img src="{{ $qrOp }}" alt="QR">
</div>

@if($item->numero_serie)
<div class="divider"></div>
<div class="serie-block">
    Serie
    <strong>{{ $item->numero_serie }}</strong>
</div>
@endif

</body>
</html>
