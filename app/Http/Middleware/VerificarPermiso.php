<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protege una ruta por permiso en vez de por rol.
 *
 * Uso: ->middleware('permiso:clientes.crear')
 * Con varios, basta con tener uno: ->middleware('permiso:ops.ver,ops.editar')
 */
class VerificarPermiso
{
    public function handle(Request $request, Closure $next, string ...$permisos): Response
    {
        $user = $request->user();

        if (! $user || ! $user->tieneAlgunPermiso($permisos)) {
            abort(403, 'No tienes permiso para acceder a esta sección.');
        }

        return $next($request);
    }
}
