<?php

namespace App\Http\Middleware;

use App\Support\Instalacion;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mientras no esté instalada, todo lleva al asistente.
 *
 * Va de primero en el grupo web, antes que los middleware que consultan la base
 * de datos para saber la marca de la empresa y el SMTP: si se dejara para después,
 * la primera visita a una instalación recién subida mostraría un error de conexión
 * en vez del asistente.
 */
class ExigirInstalacion
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Instalacion::estaInstalada() || $request->is('instalar', 'instalar/*', 'up')) {
            return $next($request);
        }

        // Una petición que espera datos no debe recibir una redirección a HTML.
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Briela todavía no está instalada.'], 503);
        }

        return redirect('/instalar');
    }
}
