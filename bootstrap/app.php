<?php

use App\Http\Middleware\Authenticate;
use App\Http\Middleware\CerrarInstaladorSiYaEstaInstalada;
use App\Http\Middleware\ExigirInstalacion;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\VerificarPermiso;
use App\Http\Middleware\VerificarRol;
use App\Http\Middleware\VerificarTokenIntegracion;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // El asistente de instalación va con su propio middleware mínimo:
            // sesión y CSRF, y nada que consulte la base de datos, porque tiene
            // que dibujarse cuando la base todavía no existe.
            Route::middleware([
                \Illuminate\Cookie\Middleware\EncryptCookies::class,
                \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
                \Illuminate\Session\Middleware\StartSession::class,
                \Illuminate\View\Middleware\ShareErrorsFromSession::class,
                \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            ])->group(base_path('routes/instalador.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // De primero: mientras no esté instalada, todo lleva al asistente. Tiene
        // que cortar antes que los middleware que consultan la base.
        $middleware->web(prepend: [
            ExigirInstalacion::class,
        ]);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
            \App\Http\Middleware\AplicarSmtpConfig::class,
        ]);

        $middleware->alias([
            'rol'                 => VerificarRol::class,
            'permiso'             => VerificarPermiso::class,
            'auth'                => Authenticate::class,
            'integracion.wordpress' => VerificarTokenIntegracion::class,
            'instalada'           => CerrarInstaladorSiYaEstaInstalada::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'webhook/whatsapp',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
