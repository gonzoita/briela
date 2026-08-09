<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Instalación de Briela — paso {{ $paso ?? 1 }} de 3</title>

    {{--
        El estilo va aquí dentro a propósito: el asistente tiene que verse bien
        aunque el bundle de Vite no esté compilado, y no puede depender de la
        configuración de marca, que todavía no existe. Mobile-first.
    --}}
    <style>
        :root { --marca: #2563EB; --marca-oscuro: #1E4FBF; --texto: #111827; --suave: #6B7280; --linea: #E5E7EB; --fondo: #F8F9FA; --ok: #059669; --mal: #DC2626; }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--fondo); color: var(--texto); font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 15px; line-height: 1.5; }
        .envoltura { max-width: 560px; margin: 0 auto; padding: 24px 16px 48px; }
        .marca { text-align: center; margin-bottom: 20px; }
        .marca strong { font-size: 22px; letter-spacing: -0.4px; color: var(--marca); }
        .marca span { display: block; font-size: 13px; color: var(--suave); margin-top: 2px; }
        .pasos { display: flex; gap: 6px; margin-bottom: 18px; }
        .pasos div { flex: 1; height: 4px; border-radius: 2px; background: var(--linea); }
        .pasos div.hecho { background: var(--marca); }
        .tarjeta { background: #fff; border: 1px solid var(--linea); border-radius: 14px; padding: 22px 18px; }
        h1 { font-size: 19px; margin: 0 0 4px; }
        .sub { color: var(--suave); font-size: 14px; margin: 0 0 18px; }
        label { display: block; font-size: 13px; font-weight: 600; margin: 14px 0 5px; }
        input { width: 100%; padding: 11px 12px; border: 1px solid var(--linea); border-radius: 9px; font-size: 15px; font-family: inherit; }
        input:focus { outline: 2px solid var(--marca); outline-offset: -1px; border-color: var(--marca); }
        .fila { display: flex; gap: 10px; }
        .fila > div { flex: 1; }
        .ayuda { font-size: 12px; color: var(--suave); margin-top: 5px; }
        button { width: 100%; margin-top: 22px; padding: 13px; border: 0; border-radius: 10px; background: var(--marca); color: #fff; font-size: 15px; font-weight: 600; font-family: inherit; cursor: pointer; }
        button:hover { background: var(--marca-oscuro); }
        button[disabled] { background: #9CA3AF; cursor: not-allowed; }
        ul.chequeo { list-style: none; margin: 0; padding: 0; }
        ul.chequeo li { display: flex; align-items: flex-start; gap: 10px; padding: 9px 0; border-bottom: 1px solid var(--linea); }
        ul.chequeo li:last-child { border-bottom: 0; }
        .icono { flex-shrink: 0; width: 18px; height: 18px; border-radius: 50%; color: #fff; font-size: 12px; line-height: 18px; text-align: center; font-weight: 700; }
        .icono.si { background: var(--ok); }
        .icono.no { background: var(--mal); }
        .icono.aviso { background: #D97706; }
        .nombre { font-size: 14px; }
        .detalle { font-size: 12px; color: var(--suave); }
        .aviso-caja { background: #FEF2F2; border: 1px solid #FECACA; color: #991B1B; border-radius: 10px; padding: 12px 14px; font-size: 13px; margin-bottom: 16px; }
        .aviso-caja.neutro { background: #EFF6FF; border-color: #BFDBFE; color: #1E40AF; }
        .errores { background: #FEF2F2; border: 1px solid #FECACA; border-radius: 10px; padding: 12px 14px; margin-bottom: 16px; }
        .errores p { margin: 0; font-size: 13px; color: #991B1B; }
        .pie { text-align: center; font-size: 12px; color: var(--suave); margin-top: 18px; }
        code { background: #F3F4F6; padding: 2px 6px; border-radius: 5px; font-size: 12px; }
        @media (min-width: 640px) { .envoltura { padding-top: 56px; } .tarjeta { padding: 28px; } }
    </style>
</head>
<body>
    <div class="envoltura">
        <div class="marca">
            <strong>Briela</strong>
            <span>Instalación</span>
        </div>

        <div class="pasos">
            @for ($i = 1; $i <= 3; $i++)
                <div class="{{ $i <= ($paso ?? 1) ? 'hecho' : '' }}"></div>
            @endfor
        </div>

        <div class="tarjeta">
            @if ($errors->any())
                <div class="errores">
                    @foreach ($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            @yield('contenido')
        </div>

        <p class="pie">Paso {{ $paso ?? 1 }} de 3</p>
    </div>
</body>
</html>
