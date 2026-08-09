<?php

use App\Http\Controllers\InstaladorController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Asistente de instalación
|--------------------------------------------------------------------------
|
| Estas rutas van aparte de web.php porque tienen que funcionar cuando todavía
| no hay base de datos: no pasan por los middleware de la aplicación, que
| consultan la configuración de la empresa y el SMTP.
|
| El middleware 'instalada' las cierra en cuanto la instalación termina.
|
*/

Route::middleware('instalada')->group(function () {
    Route::get('/instalar', [InstaladorController::class, 'requisitos']);

    Route::get('/instalar/base-datos', [InstaladorController::class, 'baseDatos']);
    Route::post('/instalar/base-datos', [InstaladorController::class, 'guardarBaseDatos']);

    Route::get('/instalar/base-datos/migrar', [InstaladorController::class, 'pantallaMigrar']);
    Route::post('/instalar/base-datos/migrar', [InstaladorController::class, 'migrar']);

    Route::get('/instalar/cuenta', [InstaladorController::class, 'cuenta']);
    Route::post('/instalar/cuenta', [InstaladorController::class, 'guardarCuenta']);
});

// Fuera del grupo: es la única pantalla que se muestra con la instalación hecha.
Route::get('/instalar/listo', [InstaladorController::class, 'listo']);
