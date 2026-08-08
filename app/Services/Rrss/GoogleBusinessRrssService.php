<?php

namespace App\Services\Rrss;

use App\Exceptions\RrssApiException;
use App\Models\CuentaRrss;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Integración con Google Business Profile (antes "Google My Business") para
 * publicar "novedades" (Local Posts) en la ficha de la empresa en Google Maps
 * / Búsqueda.
 *
 * IMPORTANTE: la Business Profile API NO es de acceso libre. Hay que llenar
 * el formulario de acceso de Google (soporte de Business Profile > "Solicitar
 * acceso a la API"), usando un correo del dominio de la empresa y con el
 * sitio web activo. La revisión de Google tarda ~14 días y no siempre se
 * aprueba. Mientras no haya acceso, cualquier llamado a la API devuelve
 * PERMISSION_DENIED aunque el OAuth funcione.
 *
 * Requiere en .env: GOOGLE_RRSS_CLIENT_ID, GOOGLE_RRSS_CLIENT_SECRET,
 * GOOGLE_RRSS_REDIRECT_URI.
 */
class GoogleBusinessRrssService
{
    private const OAUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    // Google partió su API de Business Profile en varias: cada recurso vive en
    // un host distinto y hay que habilitarlas por separado en Google Cloud.
    // Listar las CUENTAS es de Account Management; listar las UBICACIONES es de
    // Business Information. Pedir /accounts al host equivocado devuelve un
    // error confuso diciendo que "la API no está habilitada".
    private const ACCOUNT_MGMT_URL = 'https://mybusinessaccountmanagement.googleapis.com/v1';
    private const BUSINESS_INFO_URL = 'https://mybusinessbusinessinformation.googleapis.com/v1';
    private const BUSINESS_POSTS_URL = 'https://mybusiness.googleapis.com/v4'; // Local Posts sigue en v4

    private function clientId(): string
    {
        return \App\Support\CredencialesRrss::valor('google', 'id');
    }

    private function clientSecret(): string
    {
        return \App\Support\CredencialesRrss::valor('google', 'secret');
    }

    private function redirectUri(): string
    {
        return \App\Support\CredencialesRrss::valor('google', 'redirect');
    }

    public function urlAutorizacion(): string
    {
        $scopes = implode(' ', ['https://www.googleapis.com/auth/business.manage']);

        $params = http_build_query([
            'client_id'     => $this->clientId(),
            'redirect_uri'  => $this->redirectUri(),
            'response_type' => 'code',
            'scope'         => $scopes,
            'access_type'   => 'offline', // para obtener refresh_token
            'prompt'        => 'consent',
        ]);

        return self::OAUTH_URL . "?{$params}";
    }

    /**
     * @return CuentaRrss[]
     */
    public function manejarCallback(string $code, ?int $userId): array
    {
        $resp = Http::asForm()->post(self::TOKEN_URL, [
            'code'          => $code,
            'client_id'     => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'redirect_uri'  => $this->redirectUri(),
            'grant_type'    => 'authorization_code',
        ]);
        $this->lanzarSiFalla($resp, 'Error cambiando el código por un token de acceso de Google');

        $token = $resp->json('access_token');
        $refreshToken = $resp->json('refresh_token');
        $expiraEnSegundos = $resp->json('expires_in');

        // Las cuentas se piden a Account Management (NO a Business Information).
        $respCuentas = Http::withToken($token)->get(self::ACCOUNT_MGMT_URL . '/accounts');
        $this->lanzarSiFalla($respCuentas, 'Error listando las cuentas de Google Business Profile. Revisa que en Google Cloud estén habilitadas "My Business Account Management API" y "My Business Business Information API", y que Google ya te haya aprobado el acceso');

        $cuentas = [];
        foreach ($respCuentas->json('accounts', []) as $cuentaGoogle) {
            $respLocations = Http::withToken($token)
                ->get(self::BUSINESS_INFO_URL . "/{$cuentaGoogle['name']}/locations", [
                    'readMask' => 'name,title',
                ]);
            if (!$respLocations->successful()) {
                continue;
            }

            foreach ($respLocations->json('locations', []) as $location) {
                // $location['name'] tiene el formato "locations/1234567890"
                $locationId = str_replace('locations/', '', $location['name']);

                $cuentas[] = CuentaRrss::updateOrCreate(
                    ['red' => 'google_business', 'cuenta_id_externo' => $locationId],
                    [
                        'nombre_cuenta'        => $location['title'] ?? "Ubicación {$locationId}",
                        'cuenta_id_secundario' => $cuentaGoogle['name'], // accounts/xxxx
                        'access_token'         => $token,
                        'refresh_token'        => $refreshToken,
                        'token_expira_en'      => now()->addSeconds((int) $expiraEnSegundos),
                        'activa'               => true,
                        'conectada_por'        => $userId,
                        'ultimo_error'         => null,
                    ]
                );
            }
        }

        return $cuentas;
    }

    /**
     * Renueva el access_token con el refresh_token cuando venció (los de
     * Google duran ~1 hora).
     */
    public function renovarToken(CuentaRrss $cuenta): void
    {
        if (empty($cuenta->refresh_token)) {
            throw new RrssApiException('La cuenta de Google Business Profile no tiene refresh_token; hay que reconectarla.');
        }

        $resp = Http::asForm()->post(self::TOKEN_URL, [
            'refresh_token' => $cuenta->refresh_token,
            'client_id'     => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'grant_type'    => 'refresh_token',
        ]);
        $this->lanzarSiFalla($resp, 'Error renovando el token de Google Business Profile');

        $cuenta->update([
            'access_token'    => $resp->json('access_token'),
            'token_expira_en' => now()->addSeconds((int) $resp->json('expires_in')),
        ]);
    }

    public function publicar(CuentaRrss $cuenta, string $contenido, ?string $urlImagen = null): array
    {
        if ($cuenta->token_expira_en && $cuenta->token_expira_en->isPast()) {
            $this->renovarToken($cuenta);
            $cuenta->refresh();
        }

        $payload = [
            'languageCode' => 'es',
            'summary'      => $contenido,
            'topicType'    => 'STANDARD',
        ];

        if ($urlImagen) {
            $payload['media'] = [[
                'mediaFormat' => 'PHOTO',
                'sourceUrl'   => $urlImagen,
            ]];
        }

        $cuentaGoogle = $cuenta->cuenta_id_secundario; // accounts/xxxx
        $resp = Http::withToken($cuenta->access_token)
            ->post(self::BUSINESS_POSTS_URL . "/{$cuentaGoogle}/locations/{$cuenta->cuenta_id_externo}/localPosts", $payload);
        $this->lanzarSiFalla($resp, 'Error publicando en Google Business Profile');

        $nombrePost = $resp->json('name'); // ej. accounts/x/locations/y/localPosts/z

        return [
            'id_publicacion_externa' => $nombrePost,
            'url_publicacion'        => $resp->json('searchUrl'),
        ];
    }

    private function lanzarSiFalla($resp, string $mensaje): void
    {
        if ($resp->successful()) {
            return;
        }

        $data = $resp->json() ?? [];
        Log::error('Google Business RRSS: ' . $mensaje, ['status' => $resp->status(), 'respuesta' => $data]);

        $detalle = $data['error']['message'] ?? $resp->body();

        // Google no dice "te falta la aprobación": deja la cuota en CERO y
        // responde "Quota exceeded", que se lee como si hubiéramos hecho
        // demasiadas peticiones. Se traduce para no mandar a nadie a buscar
        // un problema de tráfico que no existe.
        if ($resp->status() === 429 || str_contains($detalle, 'Quota exceeded')) {
            throw new RrssApiException(
                'Google todavía NO ha aprobado el acceso de este proyecto a Business Profile. '
                . 'La API está habilitada pero con cuota en cero, y por eso responde "Quota exceeded" '
                . '(no es que hayas hecho demasiadas peticiones). Hay que solicitar el acceso en '
                . 'support.google.com/business/contact/api_default y esperar la aprobación, '
                . 'que suele tardar unas dos semanas.',
                $data
            );
        }

        throw new RrssApiException("{$mensaje}: {$detalle}", $data);
    }
}
