<?php

namespace App\Http\Middleware;

use App\Models\Configuracion;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protege el namespace de integraciones externas (hoy: el plugin de
 * WordPress "Briela Connect"). No usa Sanctum ni sesión: cada instalación
 * de Briela tiene un único token opaco guardado en `configuraciones`
 * (clave `integracion_wordpress_token`), generado desde
 * Configuración → Integraciones → WordPress.
 *
 * Como Briela no es multi-tenant (una instalación por cliente), un solo
 * token por instalación alcanza — no hace falta identificar "cuál cliente"
 * llama, solo si la llamada es legítima.
 *
 * Camino de mejora futuro: cuando exista la Fase 2 (licencias por serial),
 * este token puede derivarse del serial en vez de generarse aparte.
 */
class VerificarTokenIntegracion
{
    public function handle(Request $request, Closure $next): Response
    {
        $tokenConfigurado = Configuracion::get('integracion_wordpress_token', '');

        if ($tokenConfigurado === '' || $tokenConfigurado === null) {
            return response()->json([
                'ok'      => false,
                'mensaje' => 'La integración con WordPress todavía no se ha activado en este ERP.',
            ], 403);
        }

        $tokenRecibido = $request->bearerToken();

        if (! $tokenRecibido || ! hash_equals($tokenConfigurado, $tokenRecibido)) {
            return response()->json([
                'ok'      => false,
                'mensaje' => 'Token de integración inválido.',
            ], 401);
        }

        return $next($request);
    }
}
