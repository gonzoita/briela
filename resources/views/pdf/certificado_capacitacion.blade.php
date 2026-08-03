<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8" />
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: DejaVu Sans, Arial, sans-serif; color: #111; background: white; }

  .marco {
    border: 3px solid {{ $marcaColor }};
    padding: 30px;
    margin: 10px;
    min-height: 480px;
    position: relative;
  }

  .marco-interior {
    border: 1px solid #93C5FD;
    padding: 40px;
    text-align: center;
    min-height: 400px;
  }

  .logo { width: 90px; height: 90px; margin: 0 auto 10px; display: block; }

  .empresa { font-size: 12px; letter-spacing: 2px; color: {{ $marcaColor }}; font-weight: bold; text-transform: uppercase; }

  .titulo { font-size: 34px; color: {{ $marcaColor }}; font-weight: bold; margin-top: 18px; letter-spacing: 1px; }

  .subtitulo { font-size: 13px; color: #6B7280; margin-top: 6px; }

  .otorgado { font-size: 13px; color: #374151; margin-top: 30px; }

  .nombre { font-size: 28px; color: #111; font-weight: bold; margin-top: 10px; border-bottom: 1px solid #D1D5DB; display: inline-block; padding-bottom: 6px; min-width: 320px; }

  .descripcion { font-size: 13px; color: #374151; margin-top: 22px; line-height: 1.6; }

  .curso-nombre { font-size: 16px; color: {{ $marcaColor }}; font-weight: bold; }

  .pie {
    margin-top: 45px;
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    padding: 0 20px;
  }

  .firma { text-align: center; }
  .firma-line { border-top: 1px solid #374151; width: 180px; padding-top: 4px; font-size: 10px; color: #6B7280; }
  .firma-nombre { font-size: 11px; font-weight: bold; margin-top: 2px; }

  .verificacion { text-align: center; }
  .verificacion img { width: 70px; height: 70px; }
  .verificacion p { font-size: 9px; color: #6B7280; margin-top: 4px; }
  .codigo-mono { font-family: DejaVu Sans Mono, monospace; font-weight: bold; color: {{ $marcaColor }}; font-size: 10px; }
</style>
</head>
<body>

<div class="marco">
  <div class="marco-interior">

    @if($logoPath && file_exists($logoPath))
    <img src="{{ $logoPath }}" class="logo" />
    @endif

    <div class="empresa">{{ $marcaNombre }}</div>
    <div class="titulo">CERTIFICADO DE CAPACITACIÓN</div>
    <div class="subtitulo">Se otorga el presente certificado a</div>

    <div class="otorgado">
      <div class="nombre">{{ $nombreEstudiante }}</div>
    </div>

    <div class="descripcion">
      Por haber completado y aprobado satisfactoriamente el curso
      <br>
      <span class="curso-nombre">{{ $nombreCurso }}</span>
      <br>
      Emitido el {{ $fechaEmision }}
    </div>

    <div class="pie">
      <div class="firma">
        <div class="firma-line">{{ $marcaNombre }}</div>
        <div class="firma-nombre">Capacitación y Desarrollo</div>
      </div>

      <div class="verificacion">
        <img src="{{ $qrBase64 }}" />
        <p>Escanea o verifica en</p>
        <p class="codigo-mono">{{ $urlVerificacion ?? url('/verificar-certificado') }}</p>
        <p>Código: <span class="codigo-mono">{{ $codigoVerificacion }}</span></p>
      </div>
    </div>

  </div>
</div>

</body>
</html>
