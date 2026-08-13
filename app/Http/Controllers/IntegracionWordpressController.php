<?php

namespace App\Http\Controllers;

use App\Models\Configuracion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configuración → Integraciones → WordPress.
 *
 * Genera y muestra el token que el plugin "Briela Connect" usa para
 * autenticarse contra /api/wp/*. Ver docs/plugin-wordpress-contexto.md
 * sección 3 — es un token propio por instalación, no depende de la Fase 2
 * de licenciamiento por serial.
 */
class IntegracionWordpressController extends Controller
{
    private const CLAVE = 'integracion_wordpress_token';

    public function index(): Response
    {
        $token = Configuracion::get(self::CLAVE, '');

        $publicacion = app(\App\Services\PublicacionWebService::class);

        return Inertia::render('Configuracion/Integraciones/Wordpress', [
            'url_base'      => rtrim(config('app.url'), '/'),
            'configurado'   => $token !== '' && $token !== null,
            'token_parcial' => $this->tokenParcial($token),
            // El estado del catálogo: es lo primero que se pregunta cuando algo «no
            // aparece en la web», y sin esto la respuesta hay que buscarla en la base.
            'catalogo'      => [
                'conteo'         => $publicacion->conteo(),
                'sitio'          => $publicacion->sitio(),
                'ultima_lectura' => $publicacion->ultimaLectura(),
            ],
        ]);
    }

    /**
     * Genera un token nuevo y lo devuelve completo, una sola vez: es la
     * única oportunidad de copiarlo para pegarlo en el plugin. Después de
     * esto, la pantalla solo vuelve a mostrar el final.
     */
    public function generarToken(): JsonResponse
    {
        $token = Str::random(48);
        Configuracion::set(self::CLAVE, $token);

        return response()->json([
            'ok'            => true,
            'token'         => $token,
            'token_parcial' => $this->tokenParcial($token),
        ]);
    }

    /**
     * Revoca el token: cualquier plugin conectado con el valor anterior deja
     * de poder llamar al ERP hasta que se pegue uno nuevo.
     */
    public function revocarToken(): RedirectResponse
    {
        Configuracion::set(self::CLAVE, '');

        return back()->with('success', 'Token revocado. El plugin de WordPress dejará de poder conectarse hasta que generes uno nuevo.');
    }

    private function tokenParcial(?string $token): ?string
    {
        return blank($token) ? null : '···' . substr($token, -6);
    }
}
