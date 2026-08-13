<?php

use App\Http\Controllers\Api\WordpressIntegracionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas API
|--------------------------------------------------------------------------
|
| Namespace pequeño y dedicado para integraciones externas. Hoy solo el
| plugin de WordPress "Briela Connect" (ver docs/plugin-wordpress-contexto.md).
| No depende de Sanctum ni de la Fase 2 de licenciamiento por serial —
| cada llamada se protege con un token opaco por instalación
| (middleware `integracion.wordpress`, ver VerificarTokenIntegracion).
|
| Si más adelante se construye una API general, este namespace puede
| quedar debajo de ella sin romper nada.
|
*/

Route::prefix('wp')->middleware('integracion.wordpress')->group(function () {
    Route::post('/leads', [WordpressIntegracionController::class, 'leads'])->name('api.wp.leads');

    // El catálogo publicado, para que el plugin cree o actualice las fichas del sitio.
    // Productos y ensambles en una sola lista: en WordPress no hay diferencia.
    Route::get('/catalogo', [WordpressIntegracionController::class, 'catalogo'])->name('api.wp.catalogo');
});
