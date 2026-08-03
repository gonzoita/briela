<?php

namespace App\Services\Rrss;

use App\Exceptions\RrssApiException;
use App\Models\CuentaRrss;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Integración con LinkedIn para publicar en la página de empresa de
 * Interfrigo (no en perfiles personales).
 *
 * IMPORTANTE: publicar en una página de EMPRESA requiere que LinkedIn
 * apruebe el producto "Community Management API" para la app — esto lo debe
 * solicitar Diego desde el LinkedIn Developer Portal (developer.linkedin.com),
 * asociando la app a la página de Interfrigo. La aprobación no es automática
 * y puede tardar o ser rechazada para empresas pequeñas. Mientras no esté
 * aprobado, el diálogo de login mostrará error o no ofrecerá los scopes de
 * organización, y publicarPost() devolverá el error que dé LinkedIn.
 *
 * Requiere en .env: LINKEDIN_CLIENT_ID, LINKEDIN_CLIENT_SECRET, LINKEDIN_REDIRECT_URI.
 */
class LinkedinRrssService
{
    private const API_URL = 'https://api.linkedin.com';
    private const LINKEDIN_VERSION = '202406'; // versión del API (formato YYYYMM)

    private function clientId(): string
    {
        return (string) config('services.linkedin_rrss.client_id');
    }

    private function clientSecret(): string
    {
        return (string) config('services.linkedin_rrss.client_secret');
    }

    private function redirectUri(): string
    {
        return (string) config('services.linkedin_rrss.redirect_uri');
    }

    public function urlAutorizacion(): string
    {
        // w_organization_social: publicar como la organización.
        // r_organization_admin: listar las organizaciones que administra el usuario.
        $scopes = implode(' ', ['w_organization_social', 'r_organization_admin']);

        $params = http_build_query([
            'response_type' => 'code',
            'client_id'     => $this->clientId(),
            'redirect_uri'  => $this->redirectUri(),
            'scope'         => $scopes,
        ]);

        return "https://www.linkedin.com/oauth/v2/authorization?{$params}";
    }

    /**
     * Cambia el "code" por un access token y crea/actualiza una CuentaRrss
     * por cada organización que el usuario administra.
     *
     * @return CuentaRrss[]
     */
    public function manejarCallback(string $code, ?int $userId): array
    {
        $resp = Http::asForm()->post(self::API_URL . '/oauth/v2/accessToken', [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $this->redirectUri(),
            'client_id'     => $this->clientId(),
            'client_secret' => $this->clientSecret(),
        ]);
        $this->lanzarSiFalla($resp, 'Error cambiando el código por un token de acceso de LinkedIn');

        $token = $resp->json('access_token');
        $expiraEnSegundos = $resp->json('expires_in');
        $refreshToken = $resp->json('refresh_token'); // solo si la app tiene "Refresh Token" habilitado

        // Organizaciones que el usuario administra (requiere r_organization_admin
        // aprobado — si no está aprobado, este llamado devuelve 403).
        $resp = Http::withToken($token)
            ->withHeaders(['LinkedIn-Version' => self::LINKEDIN_VERSION])
            ->get(self::API_URL . '/rest/organizationAcls', [
                'q'    => 'roleAssignee',
                'role' => 'ADMINISTRATOR',
            ]);
        $this->lanzarSiFalla($resp, 'Error obteniendo las organizaciones administradas en LinkedIn');

        $cuentas = [];
        foreach ($resp->json('elements', []) as $acl) {
            $orgUrn = $acl['organization'] ?? null; // ej. "urn:li:organization:12345678"
            if (!$orgUrn) {
                continue;
            }
            $orgId = str_replace('urn:li:organization:', '', $orgUrn);

            // Nombre legible de la organización.
            $respOrg = Http::withToken($token)
                ->withHeaders(['LinkedIn-Version' => self::LINKEDIN_VERSION])
                ->get(self::API_URL . "/rest/organizations/{$orgId}");
            $nombre = $respOrg->successful() ? ($respOrg->json('localizedName') ?? "Organización {$orgId}") : "Organización {$orgId}";

            $cuentas[] = CuentaRrss::updateOrCreate(
                ['red' => 'linkedin', 'cuenta_id_externo' => $orgId],
                [
                    'nombre_cuenta'   => $nombre,
                    'access_token'    => $token,
                    'refresh_token'   => $refreshToken,
                    'token_expira_en' => now()->addSeconds((int) $expiraEnSegundos),
                    'activa'          => true,
                    'conectada_por'   => $userId,
                    'ultimo_error'    => null,
                ]
            );
        }

        return $cuentas;
    }

    /**
     * Publica un post en la página de empresa. $urlImagen es opcional
     * (LinkedIn sí permite publicaciones de solo texto).
     */
    public function publicar(CuentaRrss $cuenta, string $contenido, ?string $urlImagen = null): array
    {
        $orgUrn = "urn:li:organization:{$cuenta->cuenta_id_externo}";

        $body = [
            'author'        => $orgUrn,
            'commentary'    => $contenido,
            'visibility'    => 'PUBLIC',
            'distribution'  => [
                'feedDistribution'             => 'MAIN_FEED',
                'targetEntities'               => [],
                'thirdPartyDistributionChannels' => [],
            ],
            'lifecycleState'    => 'PUBLISHED',
            'isReshareDisabledByAuthor' => false,
        ];

        if ($urlImagen) {
            $imagenUrn = $this->subirImagen($cuenta, $orgUrn, $urlImagen);
            $body['content'] = [
                'media' => [
                    'id' => $imagenUrn,
                ],
            ];
        }

        $resp = Http::withToken($cuenta->access_token)
            ->withHeaders([
                'LinkedIn-Version'          => self::LINKEDIN_VERSION,
                'X-Restli-Protocol-Version' => '2.0.0',
            ])
            ->post(self::API_URL . '/rest/posts', $body);
        $this->lanzarSiFalla($resp, 'Error publicando en LinkedIn');

        // LinkedIn devuelve el ID del post en el header "x-restli-id" (o x-linkedin-id).
        $idExterno = $resp->header('x-restli-id') ?? $resp->header('x-linkedin-id');

        return [
            'id_publicacion_externa' => $idExterno,
            'url_publicacion'        => $idExterno ? "https://www.linkedin.com/feed/update/{$idExterno}" : null,
        ];
    }

    private function subirImagen(CuentaRrss $cuenta, string $orgUrn, string $urlImagenPublica): string
    {
        // 1) Registrar la subida.
        $resp = Http::withToken($cuenta->access_token)
            ->withHeaders(['LinkedIn-Version' => self::LINKEDIN_VERSION])
            ->post(self::API_URL . '/rest/images?action=initializeUpload', [
                'initializeUploadRequest' => ['owner' => $orgUrn],
            ]);
        $this->lanzarSiFalla($resp, 'Error inicializando la subida de imagen a LinkedIn');

        $uploadUrl = $resp->json('value.uploadUrl');
        $imagenUrn = $resp->json('value.image');

        // 2) Descargar la imagen pública y subirla en binario a la uploadUrl.
        $binario = Http::get($urlImagenPublica)->body();
        Http::withToken($cuenta->access_token)
            ->withBody($binario, 'application/octet-stream')
            ->put($uploadUrl);

        return $imagenUrn;
    }

    private function lanzarSiFalla($resp, string $mensaje): void
    {
        if ($resp->successful()) {
            return;
        }

        $data = $resp->json() ?? [];
        Log::error('LinkedIn RRSS: ' . $mensaje, ['status' => $resp->status(), 'respuesta' => $data]);

        $detalle = $data['message'] ?? $resp->body();
        throw new RrssApiException("{$mensaje}: {$detalle}", $data);
    }
}
