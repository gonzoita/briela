<?php

namespace App\Http\Middleware;

use App\Support\Instalacion;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cierra el asistente en cuanto la instalación está hecha.
 *
 * Sin esto, cualquiera podría volver a entrar a /instalar y reescribir el .env de
 * una instalación en funcionamiento, apuntándola a otra base de datos.
 */
class CerrarInstaladorSiYaEstaInstalada
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Instalacion::estaInstalada()) {
            return redirect('/login');
        }

        return $next($request);
    }
}
