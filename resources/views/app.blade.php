<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @php
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
    @endphp
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="SGI">
    <meta name="application-name" content="SGI">
    <meta name="theme-color" content="{{ $colorMarca }}">
    <meta name="msapplication-TileColor" content="{{ $colorMarca }}">
    <meta name="msapplication-TileImage" content="/icons/icon-144.png">

    <!-- Open Graph — controla cómo se ve el link al compartirlo por WhatsApp,
         Facebook, etc. Sin esto, WhatsApp cae al <title> genérico de Laravel. -->
    <meta property="og:site_name" content="{{ $empresaNombre }}">
    <meta property="og:title" content="{{ $tituloApp }}">
    <meta property="og:type" content="website">
    <meta property="og:image" content="{{ $empresaLogo }}">

    <!-- app.js lee esto para armar el título de cada pestaña. Va aquí y no en
         el bundle porque así se cambia desde Ajustes sin recompilar. -->
    <meta name="app-empresa" content="{{ $empresaNombre }}">
    <meta name="app-titulo-plantilla" content="{{ \App\Support\Marca::plantillaTitulo() }}">

    <title inertia>{{ $tituloApp }}</title>

    <!-- Paleta de la marca. Todo el sistema usa var(--marca) en vez de un
         color fijo, así que cambiar este valor repinta la interfaz entera. -->
    <style>{!! $paletaCss !!}</style>

    <!-- Íconos -->
    <link rel="icon" href="{{ $favicon }}">
    <link rel="apple-touch-icon" sizes="152x152" href="/icons/icon-152.png">
    <link rel="apple-touch-icon" sizes="192x192" href="/icons/icon-192.png">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet"/>

    <!-- Vite -->
    @vite(['resources/js/app.js'])
    @inertiaHead

    <style>
        .slide-up-enter-active,.slide-up-leave-active{transition:all .3s ease}
        .slide-up-enter-from,.slide-up-leave-to{transform:translateY(100px);opacity:0}
    </style>
</head>
<body class="font-sans antialiased bg-gray-50">
    @inertia
</body>
</html>

