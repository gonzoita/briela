<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1F2937; }
    .header { background-color: #0A4283; color: white; padding: 16px 20px; display: flex; justify-content: space-between; align-items: center; }
    .header-left { display: flex; align-items: center; gap: 12px; }
    .header-logo { width: 80px; background: white; padding: 4px; border-radius: 4px; }
    .header-empresa p { font-size: 9px; opacity: 0.8; margin-top: 2px; }
    .header-right { text-align: right; }
    .header-right .numero { font-size: 16px; font-weight: bold; }
    .header-right .fecha { font-size: 9px; opacity: 0.8; margin-top: 4px; }
    .estado-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 9px; font-weight: bold; margin-top: 4px; }
    .seccion { padding: 12px 20px; border-bottom: 1px solid #E5E7EB; }
    .seccion-titulo { font-size: 8px; font-weight: bold; color: #6B7280; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 8px; }
    .grid-2 { display: flex; gap: 20px; }
    .grid-2 > div { flex: 1; }
    .campo label { font-size: 8px; color: #9CA3AF; display: block; margin-bottom: 2px; }
    .campo p { font-size: 10px; color: #1F2937; font-weight: 500; }
    table { width: 100%; border-collapse: collapse; }
    thead th { background-color: #0A4283; color: white; padding: 6px 8px; text-align: left; font-size: 8px; font-weight: bold; text-transform: uppercase; }
    tbody tr:nth-child(even) { background-color: #F9FAFB; }
    tbody td { padding: 6px 8px; font-size: 9px; border-bottom: 1px solid #F3F4F6; vertical-align: top; }
    .text-right { text-align: right; }
    .totales { padding: 12px 20px; }
    .totales-tabla { width: 280px; margin-left: auto; }
    .totales-fila { display: flex; justify-content: space-between; padding: 4px 0; font-size: 10px; }
    .totales-fila.total { font-weight: bold; font-size: 13px; border-top: 2px solid #0A4283; padding-top: 8px; margin-top: 4px; color: #0A4283; }
    .condiciones { padding: 12px 20px; }
    .condiciones p { font-size: 8px; color: #6B7280; line-height: 1.5; }
    .footer { padding: 10px 20px; background: #F9FAFB; display: flex; justify-content: space-between; font-size: 8px; color: #9CA3AF; }
    .descripcion-larga { font-size:8.5px; color:#4B5563; font-style:italic; margin-top:3px; line-height:1.5; }
    .descripcion-larga p { margin:0 0 2px; }
    .descripcion-larga ul, .descripcion-larga ol { margin:0 0 2px; padding-left:14px; }
    .descripcion-larga li { margin-bottom:1px; }
    .item-img { width:48px; height:48px; object-fit:cover; border-radius:4px; margin-right:6px; vertical-align:top; float:left; }
</style>
</head>
<body>

<!-- Header -->
<div class="header">
    <div class="header-left">
        @if(file_exists(public_path('img/logo-interfrigo.png')))
        <img src="{{ public_path('img/logo-interfrigo.png') }}" alt="Interfrigo" style="max-height:50px; background:white; padding:4px; border-radius:4px;" />
        @endif
        <div class="header-empresa">
            <strong style="font-size: 14px;">INTERFRIGO SAS</strong>
            <p>NIT: 900.000.000-0</p>
            <p>Fabricación e instalación de cuartos fríos y puertas refrigeradas</p>
            <p>Colombia | sgi.interfrigo.com.co</p>
        </div>
    </div>
    <div class="header-right">
        <div class="numero">{{ $cotizacion->numero }}</div>
        <div class="fecha">Fecha: {{ $cotizacion->fecha_creacion->format('d/m/Y') }}</div>
        <div class="fecha">Válida hasta: {{ $cotizacion->fecha_validez->format('d/m/Y') }}</div>
        <div class="estado-badge" style="background-color: {{ $cotizacion->estado_badge['bg'] }}; color: {{ $cotizacion->estado_badge['text'] }};">
            {{ $cotizacion->estado_badge['label'] }}
        </div>
    </div>
</div>

<!-- Cliente y responsable -->
<div class="seccion">
    <div class="grid-2">
        <div>
            <div class="seccion-titulo">Cliente</div>
            <div class="campo">
                <label>Nombre</label>
                <p>{{ $cotizacion->cliente ? $cotizacion->cliente->nombreCompleto() : ($cotizacion->nombre_contacto_override ?? '—') }}</p>
            </div>
            @if($cotizacion->cliente)
            <div class="campo" style="margin-top: 6px;">
                <label>Identificación</label>
                <p>{{ $cotizacion->cliente->tipo_identificacion }}: {{ $cotizacion->cliente->numero_identificacion }}</p>
            </div>
            @if($cotizacion->cliente->ciudad)
            <div class="campo" style="margin-top: 6px;">
                <label>Ciudad</label>
                <p>{{ $cotizacion->cliente->ciudad }}</p>
            </div>
            @endif
            @endif
            @if($cotizacion->contacto)
            <div class="campo" style="margin-top: 6px;">
                <label>Contacto</label>
                <p>{{ $cotizacion->contacto->nombre }} {{ $cotizacion->contacto->apellido }}
                   @if($cotizacion->contacto->cargo) — {{ $cotizacion->contacto->cargo }}@endif</p>
                @if($cotizacion->contacto->email)<p style="color: #6B7280;">{{ $cotizacion->contacto->email }}</p>@endif
            </div>
            @endif
        </div>
        <div>
            <div class="seccion-titulo">Detalles de cotización</div>
            <div class="campo">
                <label>Responsable</label>
                <p>{{ $cotizacion->responsable->name }}</p>
            </div>
            <div class="campo" style="margin-top: 6px;">
                <label>Moneda</label>
                <p>{{ $cotizacion->moneda }}@if($cotizacion->moneda !== 'COP') (Tasa: {{ number_format($cotizacion->tasa_cambio, 2) }})@endif</p>
            </div>
        </div>
    </div>
</div>

<!-- Tabla de ítems -->
<div class="seccion" style="border-bottom: none; padding-bottom: 0;">
    <div class="seccion-titulo">Ítems de la cotización</div>
    <table>
        <thead>
            <tr>
                <th style="width: 30px;">#</th>
                <th>Descripción</th>
                <th style="width: 50px;" class="text-right">Cant.</th>
                <th style="width: 80px;" class="text-right">Precio unit.</th>
                <th style="width: 50px;" class="text-right">Desc. %</th>
                <th style="width: 50px;" class="text-right">IVA %</th>
                <th style="width: 90px;" class="text-right">Total línea</th>
            </tr>
        </thead>
        <tbody>
            @foreach($cotizacion->items as $i => $item)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>
                    @if($item->imagen_url ?? false)
                    <img src="{{ $item->imagen_url }}" class="item-img" alt="" />
                    @endif
                    {{ $item->descripcion }}
                    @if($item->descripcion_larga)
                    <div class="descripcion-larga">{!! $item->descripcion_larga !!}</div>
                    @endif
                </td>
                <td class="text-right">{{ number_format($item->cantidad, 2) }}</td>
                <td class="text-right">{{ '$' . number_format($item->precio_unitario, 0, ',', '.') }}</td>
                <td class="text-right">{{ $item->descuento_pct > 0 ? number_format($item->descuento_pct, 1) . '%' : '—' }}</td>
                <td class="text-right">{{ $item->impuesto_pct > 0 ? number_format($item->impuesto_pct, 0) . '%' : '—' }}</td>
                <td class="text-right" style="font-weight: 600;">${{ number_format($item->total_linea, 0, ',', '.') }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>

<!-- Totales -->
<div class="totales">
    <div class="totales-tabla">
        <div class="totales-fila">
            <span>Subtotal</span>
            <span>${{ number_format($cotizacion->subtotal, 0, ',', '.') }}</span>
        </div>
        @if($cotizacion->descuento_total > 0)
        <div class="totales-fila">
            <span>Descuento</span>
            <span>- ${{ number_format($cotizacion->descuento_total, 0, ',', '.') }}</span>
        </div>
        @endif
        @if($cotizacion->impuesto_total > 0)
        <div class="totales-fila">
            <span>IVA</span>
            <span>${{ number_format($cotizacion->impuesto_total, 0, ',', '.') }}</span>
        </div>
        @endif
        <div class="totales-fila total">
            <span>TOTAL {{ $cotizacion->moneda }}</span>
            <span>${{ number_format($cotizacion->total, 0, ',', '.') }}</span>
        </div>
    </div>
</div>

<!-- Condiciones comerciales -->
@if($cotizacion->condiciones_comerciales)
<div class="condiciones">
    <div class="seccion-titulo">Condiciones comerciales</div>
    <p>{{ $cotizacion->condiciones_comerciales }}</p>
</div>
@endif

<!-- Footer -->
<div class="footer">
    <span>Generado el {{ now()->format('d/m/Y H:i') }}</span>
    <span>{{ $cotizacion->numero }} — Válida hasta {{ $cotizacion->fecha_validez->format('d/m/Y') }}</span>
    <span>Responsable: {{ $cotizacion->responsable->name }}</span>
</div>

</body>
</html>
