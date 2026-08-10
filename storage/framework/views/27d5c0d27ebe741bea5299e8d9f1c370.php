<!DOCTYPE html>
<html lang="<?php echo e(str_replace('_', '-', app()->getLocale())); ?>">
<head>
    <?php
        // Toda la identidad visual sale de Ajustes > Marca y Ajustes > Empresa.
        // Nada de esto está en el código ni en el .env: se edita desde la app y
        // se aplica al recargar, sin recompilar.
        //
        // Antes el nombre no estaba conectado a nada y por eso los links
        // compartidos (WhatsApp, etc.) mostraban "Laravel" en vez del nombre de la empresa.
        $empresaNombre = \App\Support\Marca::nombreEmpresa();
        $empresaLogo   = \App\Support\Marca::logoUrl();
        $tituloApp     = \App\Support\Marca::tituloBase();
        $favicon       = \App\Support\Marca::faviconUrl();
        $paletaCss     = \App\Support\Marca::comoCss();
        $colorMarca    = \App\Support\Marca::color();
    ?>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?php echo e($empresaNombre); ?>">
    <meta name="application-name" content="<?php echo e($empresaNombre); ?>">
    <meta name="theme-color" content="<?php echo e($colorMarca); ?>">
    <meta name="msapplication-TileColor" content="<?php echo e($colorMarca); ?>">
    <meta name="msapplication-TileImage" content="/icons/icon-144.png">

    <!-- Open Graph — controla cómo se ve el link al compartirlo por WhatsApp,
         Facebook, etc. Sin esto, WhatsApp cae al <title> genérico de Laravel. -->
    <meta property="og:site_name" content="<?php echo e($empresaNombre); ?>">
    <meta property="og:title" content="<?php echo e($tituloApp); ?>">
    <meta property="og:type" content="website">
    <meta property="og:image" content="<?php echo e($empresaLogo); ?>">

    <!-- app.js lee esto para armar el título de cada pestaña. Va aquí y no en
         el bundle porque así se cambia desde Ajustes sin recompilar. -->
    <meta name="app-empresa" content="<?php echo e($empresaNombre); ?>">
    <meta name="app-titulo-plantilla" content="<?php echo e(\App\Support\Marca::plantillaTitulo()); ?>">

    <title inertia><?php echo e($tituloApp); ?></title>

    <!-- Paleta de la marca. Todo el sistema usa var(--marca) en vez de un
         color fijo, así que cambiar este valor repinta la interfaz entera. -->
    <style><?php echo $paletaCss; ?></style>

    <!-- Íconos -->
    <link rel="icon" href="<?php echo e($favicon); ?>">
    <link rel="apple-touch-icon" sizes="152x152" href="/icons/icon-152.png">
    <link rel="apple-touch-icon" sizes="192x192" href="/icons/icon-192.png">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">

    

    <!-- Vite -->
    <?php echo app('Illuminate\Foundation\Vite')(['resources/js/app.js']); ?>
    <?php if (!isset($__inertiaSsrDispatched)) { $__inertiaSsrDispatched = true; $__inertiaSsrResponse = app(\Inertia\Ssr\Gateway::class)->dispatch($page); }  if ($__inertiaSsrResponse) { echo $__inertiaSsrResponse->head; } ?>

    <style>
        .slide-up-enter-active,.slide-up-leave-active{transition:all .3s ease}
        .slide-up-enter-from,.slide-up-leave-to{transform:translateY(100px);opacity:0}

        /* Texto más parejo en pantallas de alta densidad, como en las interfaces
           de Apple: sin esto la tipografía del sistema se ve más gruesa en Mac. */
        body { -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; text-rendering: optimizeLegibility; }

        /* Detalles que no se piensan pero se sienten. En un ERP se pasa el día
           entero desplazando listas y seleccionando datos para copiarlos: la
           barra de scroll gruesa y la selección azul del navegador son dos de las
           cosas que más delatan una aplicación web genérica. */
        * { scrollbar-width: thin; scrollbar-color: var(--scroll) transparent; }
        ::-webkit-scrollbar { width: 10px; height: 10px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb {
            background: var(--scroll);
            border-radius: 999px;
            border: 3px solid transparent;
            background-clip: content-box;
        }
        ::-webkit-scrollbar-thumb:hover { background: var(--scroll-hover); background-clip: content-box; }

        ::selection { background: var(--marca-medio); color: var(--texto); }

        /* El resaltado azul al tocar en móvil: sobra cuando cada elemento ya
           responde con su propio estado. */
        * { -webkit-tap-highlight-color: transparent; }

        /* Que el foco se vea solo cuando se navega con teclado, no al hacer clic. */
        :focus:not(:focus-visible) { outline: none; }

        /* ── Los campos, en modo de noche ──────────────────────────────────────
           El plugin de formularios de Tailwind les pone fondo blanco fijo, así que
           en el modo oscuro quedaban blancos con el texto claro encima: ilegibles.
           Se corrigen aquí, en un solo lugar, en vez de en cada uno de los cientos
           de campos del sistema. */
        html[data-tema="oscuro"] input:not([type="checkbox"]):not([type="radio"]):not([type="range"]):not([type="color"]),
        html[data-tema="oscuro"] select,
        html[data-tema="oscuro"] textarea {
            background-color: var(--superficie-2) !important;
            border-color: var(--borde);
            color: var(--texto);
        }
        html[data-tema="oscuro"] input::placeholder,
        html[data-tema="oscuro"] textarea::placeholder { color: var(--texto-3); }

        /* Las opciones del desplegable las dibuja el sistema operativo: sin esto
           salen negras sobre blanco por su cuenta. */
        html[data-tema="oscuro"] select option {
            background-color: var(--superficie-2);
            color: var(--texto);
        }

        /* Los campos de fecha y hora traen su propio icono, negro por defecto. */
        html[data-tema="oscuro"] input[type="date"]::-webkit-calendar-picker-indicator,
        html[data-tema="oscuro"] input[type="time"]::-webkit-calendar-picker-indicator,
        html[data-tema="oscuro"] input[type="datetime-local"]::-webkit-calendar-picker-indicator {
            filter: invert(1) opacity(.6);
        }

        /* Lo que el navegador rellena solo se pinta de amarillo claro y borra el
           texto en modo oscuro. */
        html[data-tema="oscuro"] input:-webkit-autofill,
        html[data-tema="oscuro"] input:-webkit-autofill:focus {
            -webkit-text-fill-color: var(--texto);
            -webkit-box-shadow: 0 0 0 1000px var(--superficie-2) inset;
        }
    </style>
</head>
<body class="font-sans antialiased bg-lienzo text-tinta-900">
    <?php if (!isset($__inertiaSsrDispatched)) { $__inertiaSsrDispatched = true; $__inertiaSsrResponse = app(\Inertia\Ssr\Gateway::class)->dispatch($page); }  if ($__inertiaSsrResponse) { echo $__inertiaSsrResponse->body; } elseif (config('inertia.use_script_element_for_initial_page')) { ?><script data-page="app" type="application/json"><?php echo json_encode($page); ?></script><div id="app"></div><?php } else { ?><div id="app" data-page="<?php echo e(json_encode($page)); ?>"></div><?php } ?>
</body>
</html>

<?php /**PATH C:\laragon\www\briela\resources\views/app.blade.php ENDPATH**/ ?>