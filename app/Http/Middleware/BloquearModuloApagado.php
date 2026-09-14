<?php

namespace App\Http\Middleware;

use App\Support\Modulos;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Corta las rutas de un módulo apagado, estén o no protegidas por permiso.
 *
 * Los permisos ya cubren casi todo, pero no lo que no pide permiso: los portales públicos
 * (seguimiento de una orden, aprobar una cotización, verificar un certificado), el QR del
 * operario, la pantalla de planta, los formularios del CRM. Esos se cortan aquí, por el prefijo
 * de la URL que declara cada módulo en `Modulos::catalogo()`.
 *
 * Una página pública responde 404: para quien llega de afuera, el enlace ya no existe, y decir
 * «módulo desactivado» le cuenta algo que no le importa. Quien está dentro vuelve al tablero con
 * el motivo, para que no crea que es un error.
 */
class BloquearModuloApagado
{
    public function handle(Request $request, Closure $next): Response
    {
        $modulo = Modulos::apagadoParaRuta($request->path());

        if ($modulo === null) {
            return $next($request);
        }

        $label   = Modulos::catalogo()[$modulo]['label'];
        $mensaje = "El módulo «{$label}» está desactivado en esta instalación.";

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $mensaje], 404);
        }

        if (! $request->user()) {
            abort(404);
        }

        return redirect('/dashboard')->with('error', $mensaje);
    }
}
