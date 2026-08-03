<!DOCTYPE html>
<html lang="es">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
  font-family: DejaVu Sans, sans-serif;
  font-size: 11px;
  color: #1a1a1a;
  background: #fff;
}

/* HEADER */
.header {
  background: #0A4283;
  color: #fff;
  padding: 14px 24px;
}
.header-inner {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.header-logo {
  display: flex;
  align-items: center;
  gap: 10px;
}
.header-logo img {
  height: 38px;
  object-fit: contain;
  background: white;
  border-radius: 5px;
  padding: 3px 6px;
}
.header-empresa { font-size: 14px; font-weight: bold; }
.header-sub { font-size: 9px; opacity: 0.75; margin-top: 2px; }
.header-right { text-align: right; font-size: 9px; opacity: 0.8; }

/* HERO */
.hero {
  background: linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%);
  border-bottom: 3px solid #0A4283;
  padding: 16px 24px 14px;
}
.hero-inner { display: flex; gap: 18px; align-items: flex-start; }
.hero-img { width: 180px; flex-shrink: 0; }
.hero-img img {
  width: 180px; height: 165px;
  object-fit: contain;
  border-radius: 8px;
  border: 1px solid #bfdbfe;
  background: #fff;
}
.hero-img-placeholder {
  width: 180px; height: 165px;
  background: #e0e7ef;
  border-radius: 8px;
  border: 1px solid #bfdbfe;
  display: flex; align-items: center; justify-content: center;
  font-size: 10px; color: #9ca3af;
}
.hero-info { flex: 1; }
.hero-badge {
  display: inline-block;
  background: #0A4283; color: #fff;
  border-radius: 3px; padding: 2px 8px;
  font-size: 9px; font-weight: bold;
  text-transform: uppercase; letter-spacing: 0.5px;
  margin-bottom: 6px;
}
.hero-nombre {
  font-size: 19px; font-weight: bold; color: #0A4283;
  line-height: 1.25; margin-bottom: 6px;
}
.hero-ref {
  font-size: 10px; color: #6b7280;
  font-family: monospace; margin-bottom: 10px;
}
.hero-desc {
  font-size: 11px; color: #374151; line-height: 1.6;
  font-style: italic;
  padding: 8px 10px;
  background: rgba(255,255,255,0.65);
  border-left: 3px solid #0A4283;
  border-radius: 0 5px 5px 0;
}

/* GRID INFO */
.info-grid {
  display: flex; gap: 0;
  border: 1px solid #e5e7eb;
  border-radius: 6px; overflow: hidden;
  margin: 14px 24px 0;
}
.info-cell {
  flex: 1;
  padding: 8px 12px;
  border-right: 1px solid #e5e7eb;
  background: #f9fafb;
}
.info-cell:last-child { border-right: none; }
.info-label { font-size: 8px; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.6px; margin-bottom: 2px; }
.info-value { font-size: 11px; font-weight: bold; color: #111827; }

/* CUERPO */
.body { padding: 14px 24px 0; }

/* PRECIOS */
.precios-section { margin-bottom: 14px; }
.seccion-titulo {
  font-size: 9px; font-weight: bold; color: #6b7280;
  text-transform: uppercase; letter-spacing: 0.8px;
  border-bottom: 1px solid #e5e7eb;
  padding-bottom: 3px; margin-bottom: 8px;
}
.precios-grid { display: flex; gap: 8px; }
.precio-card {
  flex: 1; text-align: center;
  padding: 8px 4px; border-radius: 6px;
  border: 1px solid #e5e7eb;
}
.precio-card.highlight { border: 2px solid #0A4283; background: #EFF6FF; }
.precio-canal { font-size: 8px; color: #6b7280; text-transform: uppercase; margin-bottom: 4px; }
.precio-valor { font-size: 13px; font-weight: bold; color: #111827; }
.precio-card.highlight .precio-canal { color: #0A4283; font-weight: bold; }
.precio-card.highlight .precio-valor { color: #0A4283; }
.precio-nota { font-size: 8px; color: #9ca3af; margin-top: 10px; font-style: italic; }

/* TÉCNICOS */
.seccion { margin-bottom: 14px; }
.seccion-texto { font-size: 11px; color: #374151; line-height: 1.7; }
.seccion-texto p { margin: 0 0 5px; }
.seccion-texto ul, .seccion-texto ol { margin: 3px 0 5px 14px; padding: 0; }
.seccion-texto li { margin-bottom: 2px; }
.seccion-texto strong { font-weight: 700; }
.seccion-texto em { font-style: italic; }
.seccion-texto h1, .seccion-texto h2, .seccion-texto h3 { font-size: 12px; font-weight: 700; margin: 6px 0 3px; }

/* GALERÍA */
.galeria { margin-bottom: 14px; }
.galeria-grid { display: flex; flex-wrap: wrap; gap: 6px; }
.galeria-grid img {
  width: 130px; height: 130px;
  object-fit: contain;
  border-radius: 5px;
  border: 1px solid #e5e7eb;
  background: #f9fafb;
}

/* FOOTER */
.footer {
  background: #0A4283;
  color: rgba(255,255,255,0.85);
  text-align: center;
  font-size: 9px;
  padding: 8px 24px;
  margin-top: 18px;
}
</style>
</head>
<body>

<!-- HEADER -->
<div class="header">
  <div class="header-inner">
    <div class="header-logo">
      @php $logoPath = public_path('img/logo-interfrigo.png'); @endphp
      @if($logoPath && file_exists($logoPath))
      <img src="{{ $logoPath }}" />
      @endif
      <div>
        <div class="header-empresa">Interfrigo SAS</div>
        <div class="header-sub">Fabricación e instalación de cuartos fríos y puertas refrigeradas</div>
      </div>
    </div>
    <div class="header-right">
      Ficha de Producto<br>
      {{ now()->format('d/m/Y') }}<br>
      interfrigo.com.co
    </div>
  </div>
</div>

<!-- HERO -->
<div class="hero">
  <div class="hero-inner">
    <div class="hero-img">
      @if($imagenPath && file_exists($imagenPath))
        <img src="{{ $imagenPath }}" alt="{{ $producto->nombre }}" />
      @else
        <div class="hero-img-placeholder">Sin imagen</div>
      @endif
    </div>
    <div class="hero-info">
      @if($producto->categoria)
      <div class="hero-badge">{{ $producto->categoria->nombre }}</div>
      @endif
      <div class="hero-nombre">{{ $producto->nombre }}</div>
      <div class="hero-ref">Ref: {{ $producto->referencia }} &nbsp;·&nbsp; {{ $producto->unidad_medida }}</div>
      @if($producto->descripcion_corta)
      <div class="hero-desc">{{ $producto->descripcion_corta }}</div>
      @endif
    </div>
  </div>
</div>

<!-- GRID INFO -->
<div class="info-grid">
  <div class="info-cell">
    <div class="info-label">Referencia</div>
    <div class="info-value">{{ $producto->referencia }}</div>
  </div>
  <div class="info-cell">
    <div class="info-label">Unidad</div>
    <div class="info-value">{{ $producto->unidad_medida }}</div>
  </div>
  <div class="info-cell">
    <div class="info-label">Estado</div>
    <div class="info-value">{{ $producto->activo ? 'Activo' : 'Inactivo' }}</div>
  </div>
  @if($producto->tipo ?? null)
  <div class="info-cell">
    <div class="info-label">Tipo</div>
    <div class="info-value">{{ ucfirst($producto->tipo) }}</div>
  </div>
  @endif
</div>

<div class="body">

  <!-- PRECIOS -->
  @if($mostrarPrecio ?? true)
  <div class="precios-section" style="margin-top:14px;">
    <div class="seccion-titulo">Precios de referencia</div>
    <div class="precios-grid">
      <div class="precio-card">
        <div class="precio-canal">Mayorista</div>
        <div class="precio-valor">${{ number_format($producto->precio_mayorista, 0, ',', '.') }}</div>
      </div>
      <div class="precio-card">
        <div class="precio-canal">Distribuidor</div>
        <div class="precio-valor">${{ number_format($producto->precio_distribuidor, 0, ',', '.') }}</div>
      </div>
      <div class="precio-card highlight">
        <div class="precio-canal">Cliente final</div>
        <div class="precio-valor">${{ number_format($producto->precio_cliente_final, 0, ',', '.') }}</div>
      </div>
    </div>
    <div class="precio-nota">* Precios en COP · IVA no incluido · Sujetos a cambio sin aviso previo</div>
  </div>
  @endif

  <!-- DETALLES TÉCNICOS -->
  @if($producto->descripcion_larga)
  <div class="seccion" style="{{ ($mostrarPrecio ?? true) ? '' : 'margin-top:14px;' }}">
    <div class="seccion-titulo">Detalles técnicos</div>
    <div class="seccion-texto">{!! $producto->descripcion_larga !!}</div>
  </div>
  @endif

  <!-- GALERÍA -->
  @if(!empty($imagenesSecundarias))
  <div class="galeria">
    <div class="seccion-titulo">Galería</div>
    <div class="galeria-grid">
      @foreach($imagenesSecundarias as $imgPath)
        @if(file_exists($imgPath))
        <img src="{{ $imgPath }}" />
        @endif
      @endforeach
    </div>
  </div>
  @endif

</div>

<div class="footer">
  Interfrigo SAS &nbsp;·&nbsp; Cuartos fríos y puertas refrigeradas &nbsp;·&nbsp; interfrigo.com.co &nbsp;·&nbsp; Documento generado el {{ now()->format('d/m/Y') }}
</div>

</body>
</html>
