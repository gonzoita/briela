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
    <meta name="apple-mobile-web-app-title" content="SGI">
    <meta name="application-name" content="SGI">
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
    </style>
</head>
<body class="font-sans antialiased bg-lienzo text-tinta-900">
    <?php if (!isset($__inertiaSsrDispatched)) { $__inertiaSsrDispatched = true; $__inertiaSsrResponse = app(\Inertia\Ssr\Gateway::class)->dispatch($page); }  if ($__inertiaSsrResponse) { echo $__inertiaSsrResponse->body; } elseif (config('inertia.use_script_element_for_initial_page')) { ?><script data-page="app" type="application/json"><?php echo json_encode($page); ?></script><div id="app"></div><?php } else { ?><div id="app" data-page="<?php echo e(json_encode($page)); ?>"></div><?php } ?>
</body>
</html>

<?php /**PATH C:\laragon\www\briela\resources\views/app.blade.php ENDPATH**/ ?>