<?php

namespace App\Services\Rrss;

use App\Exceptions\RrssApiException;
use App\Models\CuentaRrss;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Integración con Meta Graph API para publicar en Facebook (páginas propias)
 * e Instagram (cuentas profesionales ligadas a una página de Facebook).
 *
 * Como la app solo va a manejar las páginas propias de la empresa (Standard
 * Access), NO requiere App Review de Meta — basta con que el usuario que
 * conecta la cuenta sea administrador de la página en Meta Business Suite.
 *
 * Requiere en .env: META_APP_ID, META_APP_SECRET, META_REDIRECT_URI.
 */
class MetaRrssService
{
    private const GRAPH_VERSION = 'v21.0';
    private const GRAPH_URL = 'https://graph.facebook.com/' . self::GRAPH_VERSION;

    private function appId(): string
    {
        return \App\Support\CredencialesRrss::valor('meta', 'id');
    }

    private function appSecret(): string
    {
        return \App\Support\CredencialesRrss::valor('meta', 'secret');
    }

    private function redirectUri(): string
    {
        return \App\Support\CredencialesRrss::valor('meta', 'redirect');
    }

    /**
     * URL del diálogo de login de Facebook para conectar páginas + Instagram.
     */
    public function urlAutorizacion(): string
    {
        $scopes = implode(',', [
            'pages_show_list',
            'pages_read_engagement',
            'pages_manage_posts',
            'instagram_basic',
            'instagram_content_publish',
            'business_management',
        ]);

        $params = http_build_query([
            'client_id'     => $this->appId(),
            'redirect_uri'  => $this->redirectUri(),
            'scope'         => $scopes,
            'response_type' => 'code',
        ]);

        return "https://www.facebook.com/{$this->apiVersionSinPrefijo()}/dialog/oauth?{$params}";
    }

    private function apiVersionSinPrefijo(): string
    {
        return self::GRAPH_VERSION;
    }

    /**
     * Procesa el "code" que Facebook devuelve en el callback: lo cambia por
     * un token de usuario de larga duración, y crea/actualiza una fila en
     * cuentas_rrss por cada página (+ su Instagram ligado, si tiene).
     *
     * @return CuentaRrss[] cuentas creadas o actualizadas
     */
    public function manejarCallback(string $code, ?int $userId): array
    {
        // 1) code -> token de usuario de corta duración
        $resp = Http::get(self::GRAPH_URL . '/oauth/access_token', [
            'client_id'     => $this->appId(),
            'client_secret' => $this->appSecret(),
            'redirect_uri'  => $this->redirectUri(),
            'code'          => $code,
        ]);
        $this->lanzarSiFalla($resp, 'Error cambiando el código por un token de acceso');
        $tokenCorto = $resp->json('access_token');

        // 2) token corto -> token de usuario de larga duración (~60 días)
        $resp = Http::get(self::GRAPH_URL . '/oauth/access_token', [
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => $this->appId(),
            'client_secret'     => $this->appSecret(),
            'fb_exchange_token' => $tokenCorto,
        ]);
        $this->lanzarSiFalla($resp, 'Error generando el token de larga duración');
        $tokenLargo = $resp->json('access_token');
        $expiraEnSegundos = $resp->json('expires_in'); // normalmente ~5184000 (60 días)

        // 3) listar páginas administradas por el usuario (el token de página
        //    que devuelve este endpoint ya hereda la duración larga).
        $resp = Http::get(self::GRAPH_URL . '/me/accounts', [
            'access_token' => $tokenLargo,
            'fields'       => 'id,name,access_token,instagram_business_account{id,username}',
        ]);
        $this->lanzarSiFalla($resp, 'Error obteniendo la lista de páginas de Facebook');

        $cuentas = [];

        foreach ($resp->json('data', []) as $pagina) {
            $cuentaFb = CuentaRrss::updateOrCreate(
                ['red' => 'facebook', 'cuenta_id_externo' => $pagina['id']],
                [
                    'nombre_cuenta'   => $pagina['name'],
                    'access_token'    => $pagina['access_token'],
                    'token_expira_en' => now()->addSeconds((int) $expiraEnSegundos),
                    'activa'          => true,
                    'conectada_por'   => $userId,
                    'ultimo_error'    => null,
                ]
            );
            $cuentas[] = $cuentaFb;

            if (!empty($pagina['instagram_business_account']['id'])) {
                $ig = $pagina['instagram_business_account'];
                $cuentas[] = CuentaRrss::updateOrCreate(
                    ['red' => 'instagram', 'cuenta_id_externo' => $ig['id']],
                    [
                        'nombre_cuenta'        => '@' . ($ig['username'] ?? $pagina['name']),
                        'cuenta_id_secundario' => $pagina['id'], // página de FB a la que está ligada
                        'access_token'         => $pagina['access_token'], // IG publica con el token de la página
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
     * Estira el vencimiento del token de una cuenta de Meta.
     *
     * **Por qué existe.** El token que Meta entrega al conectar dura unos 60 días. Hasta oct
     * 2026 no se renovaba ni se avisaba: un día, sin más, las publicaciones de Facebook e
     * Instagram empezaban a fallar y había que adivinar que era eso. Estaba anotado como
     * pendiente en el manual del módulo.
     *
     * **Cómo se renueva.** Se vuelve a cambiar el token actual por uno de larga duración
     * (`fb_exchange_token`). Eso solo funciona mientras el token siga vivo, así que no sirve
     * para rescatar uno ya vencido: por eso la revisión corre todos los días y renueva con
     * semanas de antelación, en vez de esperar a que falle una publicación.
     *
     * Las dos cuentas de una misma página —Facebook y el Instagram ligado— comparten el token,
     * así que renovar una renueva la otra.
     *
     * @throws RrssApiException si Meta no lo renueva (token ya vencido o revocado)
     */
    public function renovarToken(CuentaRrss $cuenta): void
    {
        $resp = Http::get(self::GRAPH_URL . '/oauth/access_token', [
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => $this->appId(),
            'client_secret'     => $this->appSecret(),
            'fb_exchange_token' => $cuenta->access_token,
        ]);
        $this->lanzarSiFalla($resp, 'Error renovando el token de Meta');

        $nuevo = $resp->json('access_token');
        $expira = (int) $resp->json('expires_in');

        $cuenta->update([
            'access_token' => $nuevo,
            // Sin `expires_in` el token es de los que no vencen; se anota a 60 días para
            // seguir revisándolo, porque Meta puede revocarlo por su cuenta.
            'token_expira_en' => now()->addSeconds($expira > 0 ? $expira : 60 * 86400),
            'ultimo_error' => null,
        ]);

        // El Instagram ligado a esta página publica con el mismo token de página.
        //
        // Se recorren los modelos en vez de hacer un `update` de consulta: `access_token` va
        // cifrado (`casts`), y un update de consulta escribe el valor en claro sin pasar por
        // el cast. El token quedaba guardado sin cifrar y al leerlo reventaba con «The payload
        // is invalid», justo en la cuenta que acababa de renovarse.
        CuentaRrss::where('red', 'instagram')
            ->where('cuenta_id_secundario', $cuenta->cuenta_id_externo)
            ->get()
            ->each(fn (CuentaRrss $ligada) => $ligada->update([
                'access_token'    => $nuevo,
                'token_expira_en' => $cuenta->token_expira_en,
                'ultimo_error'    => null,
            ]));
    }

    /**
     * Publica en el feed de una página de Facebook. $urlImagen debe ser una
     * URL pública (no localhost) — usamos la URL pública del archivo ya subido.
     */
    public function publicarFacebook(CuentaRrss $cuenta, string $contenido, ?string $urlImagen = null): array
    {
        $endpoint = $urlImagen
            ? self::GRAPH_URL . "/{$cuenta->cuenta_id_externo}/photos"
            : self::GRAPH_URL . "/{$cuenta->cuenta_id_externo}/feed";

        $payload = $urlImagen
            ? ['url' => $urlImagen, 'caption' => $contenido]
            : ['message' => $contenido];

        $resp = Http::asForm()->post($endpoint, $payload + ['access_token' => $cuenta->access_token]);
        $this->lanzarSiFalla($resp, 'Error publicando en Facebook');

        $idExterno = $resp->json('post_id') ?? $resp->json('id');

        return [
            'id_publicacion_externa' => $idExterno,
            'url_publicacion'        => "https://www.facebook.com/{$idExterno}",
        ];
    }

    /**
     * Publica en Instagram. La API de Instagram exige al menos una imagen
     * (no permite publicaciones de solo texto): primero crea el "contenedor
     * de media" y luego lo publica.
     */
    public function publicarInstagram(CuentaRrss $cuenta, string $contenido, string $urlImagen): array
    {
        $resp = Http::asForm()->post(self::GRAPH_URL . "/{$cuenta->cuenta_id_externo}/media", [
            'image_url'    => $urlImagen,
            'caption'      => $contenido,
            'access_token' => $cuenta->access_token,
        ]);
        $this->lanzarSiFalla($resp, 'Error creando el contenedor de media en Instagram');
        $creationId = $resp->json('id');

        $resp = Http::asForm()->post(self::GRAPH_URL . "/{$cuenta->cuenta_id_externo}/media_publish", [
            'creation_id'  => $creationId,
            'access_token' => $cuenta->access_token,
        ]);
        $this->lanzarSiFalla($resp, 'Error publicando el contenedor en Instagram');
        $idExterno = $resp->json('id');

        return [
            'id_publicacion_externa' => $idExterno,
            'url_publicacion'        => null, // Graph API no devuelve el permalink directo aquí
        ];
    }

    private function lanzarSiFalla($resp, string $mensaje): void
    {
        if ($resp->successful()) {
            return;
        }

        $data = $resp->json() ?? [];
        Log::error('Meta RRSS: ' . $mensaje, ['status' => $resp->status(), 'respuesta' => $data]);

        $detalle = $data['error']['message'] ?? $resp->body();
        throw new RrssApiException("{$mensaje}: {$detalle}", $data);
    }
}
