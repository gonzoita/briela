<?php

namespace App\Services\Meta;

use App\Models\CuentaRrss;
use App\Support\ConexionMeta;
use App\Support\CredencialesRrss;
use App\Support\UrlPublica;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Le dice a Meta que esta aplicación quiere recibir los webhooks de cada página y de la cuenta de
 * WhatsApp que se acaba de conectar.
 *
 * **Por qué existe.** Autorizar la aplicación no basta para que lleguen eventos: cada página y
 * cada cuenta de WhatsApp Business tiene que suscribir la aplicación a sus campos
 * (`POST /{id}/subscribed_apps`). Hasta ahora eso se le dejaba al administrador, que debía saber
 * que existía el paso y hacerlo a mano en el panel de Meta; si no, la conexión salía «bien» y no
 * entraba ni un mensaje. Es el error más callado de todo el módulo.
 *
 * **Nunca lanza excepción.** Conectar una cuenta no debe fallar porque la suscripción falló: la
 * cuenta queda conectada, el motivo se anota en `ultimo_error` y se puede reintentar. Perder el
 * token recién canjeado por un error de red en un paso accesorio sería peor.
 */
class SuscripcionesMeta
{
    /**
     * Los campos de una página de Facebook: mensajes directos, sus ecos y el muro (de donde
     * salen los comentarios). Los de Instagram llegan por la misma suscripción de la página
     * cuando la cuenta profesional está ligada a ella.
     */
    public const CAMPOS_PAGINA = [
        'messages',
        'messaging_postbacks',
        'message_echoes',
        'feed',
    ];

    /** Suscribe la aplicación a una página de Facebook (y al Instagram ligado a ella). */
    public function suscribirPagina(CuentaRrss $pagina): bool
    {
        try {
            $respuesta = Http::asForm()->timeout(15)->post(
                ConexionMeta::graph() . "/{$pagina->cuenta_id_externo}/subscribed_apps",
                [
                    'subscribed_fields' => implode(',', self::CAMPOS_PAGINA),
                    'access_token'      => $pagina->access_token,
                ],
            );
        } catch (\Throwable $e) {
            return $this->fallo($pagina, 'No se pudo contactar a Meta para suscribir la página: ' . $e->getMessage());
        }

        if (! $respuesta->successful() || ! $respuesta->json('success')) {
            return $this->fallo(
                $pagina,
                'Meta no dejó suscribir la página a los webhooks: ' . ($respuesta->json('error.message') ?? $respuesta->body())
                    . ' (hace falta el permiso pages_manage_metadata; reconecta la cuenta si es nuevo).'
            );
        }

        return true;
    }

    /**
     * Suscribe la aplicación a la cuenta de WhatsApp Business (WABA).
     *
     * Los campos no se piden aquí: los de WhatsApp —incluidos los de la coexistencia, que son los
     * mensajes que el negocio escribe desde la app del celular— se marcan una vez en el panel de
     * la aplicación (ver docs/manual/whatsapp.md), y esta llamada es la que enlaza ESTA cuenta
     * con la aplicación.
     *
     * @return array{ok: bool, mensaje: string}
     */
    public function suscribirWaba(string $wabaId, string $token): array
    {
        try {
            $respuesta = Http::withToken($token)->timeout(15)
                ->post(ConexionMeta::graph() . "/{$wabaId}/subscribed_apps");
        } catch (\Throwable $e) {
            return ['ok' => false, 'mensaje' => 'No se pudo contactar a Meta: ' . $e->getMessage()];
        }

        if (! $respuesta->successful() || ! $respuesta->json('success')) {
            Log::warning('Meta: no se pudo suscribir la cuenta de WhatsApp.', [
                'waba' => $wabaId, 'respuesta' => $respuesta->json(),
            ]);

            return ['ok' => false, 'mensaje' => $respuesta->json('error.message') ?? $respuesta->body()];
        }

        return ['ok' => true, 'mensaje' => 'Suscrita.'];
    }

    /**
     * Los campos que la aplicación pide por cada objeto de Meta. Lo de WhatsApp incluye los de la
     * coexistencia: `smb_message_echoes` (lo que el negocio escribe desde la app del celular),
     * `history` y `smb_app_state_sync` (el historial y los contactos) y `account_update`.
     */
    public const CAMPOS_APP = [
        'whatsapp_business_account' => ['messages', 'smb_message_echoes', 'history', 'smb_app_state_sync', 'account_update'],
        'page'                      => self::CAMPOS_PAGINA,
        'instagram'                 => ['messages', 'comments'],
    ];

    /**
     * Registra la dirección del webhook en la propia aplicación de Meta, para los tres objetos.
     *
     * Es el paso que antes se hacía a mano en el panel (Webhooks → pegar la URL y el token →
     * marcar los campos), y el que más se olvida. Va por la API de la aplicación
     * (`POST /{app-id}/subscriptions`), autenticada con `app_id|app_secret`.
     *
     * Meta llama a la dirección al instante para verificarla, así que una instalación que no se
     * alcanza desde internet no puede completarlo: se dice, en vez de dejar que Meta devuelva un
     * error que no explica nada. Y si rechaza algún campo de la coexistencia —porque la aplicación
     * todavía no tiene el producto o el permiso—, se reintenta solo con `messages`, que es lo
     * mínimo para recibir.
     *
     * @return array<string, array{ok: bool, mensaje: string}> el resultado de cada objeto
     */
    public function registrarWebhookDeLaApp(): array
    {
        $appId = ConexionMeta::appId();
        $secreto = CredencialesRrss::valor('meta', 'secret');
        $verify = CredencialesRrss::valor('whatsapp', 'redirect');
        $url = url('/webhook/meta');

        if ($appId === '' || $secreto === '' || $verify === '') {
            return ['app' => ['ok' => false, 'mensaje' => 'Faltan el identificador de la aplicación, su clave secreta o el token de verificación del webhook.']];
        }

        if (! UrlPublica::si($url)) {
            return ['app' => ['ok' => false, 'mensaje' => "Meta no puede llamar a {$url}: la instalación no es alcanzable desde internet."]];
        }

        $resultado = [];

        foreach (self::CAMPOS_APP as $objeto => $campos) {
            $resultado[$objeto] = $this->suscribirObjeto($appId, $secreto, $objeto, $url, $verify, $campos)
                ?? $this->suscribirObjeto($appId, $secreto, $objeto, $url, $verify, ['messages'], true)
                ?? ['ok' => false, 'mensaje' => 'Meta rechazó la suscripción.'];
        }

        return $resultado;
    }

    /** @return array{ok: bool, mensaje: string}|null  null si Meta la rechazó */
    private function suscribirObjeto(string $appId, string $secreto, string $objeto, string $url, string $verify, array $campos, bool $reducido = false): ?array
    {
        try {
            $respuesta = Http::asForm()->timeout(20)->post(ConexionMeta::graph() . "/{$appId}/subscriptions", [
                'object'       => $objeto,
                'callback_url' => $url,
                'fields'       => implode(',', $campos),
                'verify_token' => $verify,
                'access_token' => "{$appId}|{$secreto}",
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'mensaje' => 'No se pudo contactar a Meta: ' . $e->getMessage()];
        }

        if ($respuesta->successful() && $respuesta->json('success') !== false) {
            return ['ok' => true, 'mensaje' => $reducido ? 'Registrado solo con «messages»: Meta no aceptó los demás campos todavía.' : 'Registrado.'];
        }

        Log::warning('Meta: no se pudo registrar el webhook de la aplicación.', [
            'objeto' => $objeto, 'campos' => $campos, 'respuesta' => $respuesta->json(),
        ]);

        // Con los campos reducidos ya no hay a qué volver: se devuelve el motivo de Meta.
        return $reducido
            ? ['ok' => false, 'mensaje' => $respuesta->json('error.message') ?? $respuesta->body()]
            : null;
    }

    private function fallo(CuentaRrss $cuenta, string $mensaje): bool
    {
        Log::warning('Meta: ' . $mensaje, ['cuenta' => $cuenta->id, 'red' => $cuenta->red]);

        // Solo se anota el motivo: la cuenta sigue activa y puede publicar aunque no reciba.
        $cuenta->update(['ultimo_error' => $mensaje]);

        return false;
    }
}
