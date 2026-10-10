<?php

namespace App\Services\Rrss;

use App\Exceptions\RrssApiException;
use App\Models\Archivo;
use App\Models\CuentaRrss;
use App\Support\Canales;
use App\Support\UrlPublica;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Mandar mensajes y responder comentarios en Instagram y Facebook.
 *
 * Hermano de `MetaRrssService`, que publica en el muro. Se separan porque son dos permisos
 * distintos del lado de Meta y dos cosas distintas del lado del negocio: publicar es marketing
 * y contestar es atención. Mezclarlos en un servicio obligaría a que una empresa que solo
 * quiere contestar mensajes pidiera también los permisos de publicar.
 *
 * **Lo que más sorprende de estas APIs**, y por lo que la bandeja no las puede tratar igual:
 *
 * - **Un comentario no se «manda» a nadie**: se cuelga de otro comentario, y el endpoint es
 *   distinto en cada red (`/replies` en Instagram, `/comments` en Facebook).
 * - **Un directo sí se manda**, pero al identificador de la persona dentro de ESA página: el
 *   mismo ser humano tiene un identificador distinto en el Instagram y en el Facebook de la
 *   empresa, y otro más en la página de otra empresa.
 * - **Los adjuntos no se suben: se enlazan.** Meta pide una URL y viene a descargarla. Si la
 *   instalación no es alcanzable desde internet, no hay forma de mandar un archivo, y lo
 *   honesto es decirlo antes de intentarlo.
 */
class MetaMensajeriaService
{
    private const GRAPH_VERSION = 'v21.0';
    private const GRAPH_URL = 'https://graph.facebook.com/' . self::GRAPH_VERSION;

    // ─── Directos ────────────────────────────────────────────────────────────

    /**
     * Manda un mensaje directo.
     *
     * `messaging_type: RESPONSE` le dice a Meta que esto responde a algo que la persona
     * escribió, que es lo único permitido dentro de la ventana de servicio. Sin ese campo, Meta
     * lo trata como mensaje promocional y lo rechaza.
     *
     * @return string el identificador del mensaje en Meta
     */
    public function enviarDirecto(CuentaRrss $cuenta, string $destinatario, string $texto): string
    {
        return $this->llamarMensaje($cuenta, [
            'recipient'      => ['id' => $destinatario],
            'messaging_type' => 'RESPONSE',
            'message'        => ['text' => $texto],
        ]);
    }

    /**
     * Manda un archivo por mensaje directo.
     *
     * **Exige que la instalación sea alcanzable desde internet**, porque Meta no recibe el
     * archivo: recibe una URL y la va a descargar. En una instalación dentro de la red de la
     * empresa esto no puede funcionar, y la API devuelve un error que no lo explica.
     */
    public function enviarAdjuntoDirecto(CuentaRrss $cuenta, string $destinatario, Archivo $archivo): string
    {
        if (! UrlPublica::instalacionAlcanzable()) {
            throw new RrssApiException(
                'Para mandar archivos por Instagram o Messenger, Meta tiene que poder descargarlos de '
                . 'esta instalación, y esta dirección solo existe en la red local. Por ahora manda el '
                . 'texto y comparte el archivo por otro medio.'
            );
        }

        return $this->llamarMensaje($cuenta, [
            'recipient'      => ['id' => $destinatario],
            'messaging_type' => 'RESPONSE',
            'message' => [
                'attachment' => [
                    'type' => $this->tipoDeAdjunto($archivo),
                    'payload' => [
                        'url' => $archivo->url,
                        // No reutilizable: el archivo es de una conversación concreta y
                        // guardarlo en Meta para siempre no le sirve a nadie.
                        'is_reusable' => false,
                    ],
                ],
            ],
        ]);
    }

    /**
     * Qué clase de adjunto le corresponde a un archivo nuestro.
     *
     * Meta solo entiende cuatro, y un mime que no caiga en los tres primeros va como `file`.
     */
    private function tipoDeAdjunto(Archivo $archivo): string
    {
        $mime = trim(explode(';', (string) $archivo->tipo_mime)[0]);

        return match (true) {
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'video/') => 'video',
            str_starts_with($mime, 'audio/') => 'audio',
            default                          => 'file',
        };
    }

    /**
     * El POST a `/{id}/messages`.
     *
     * `$cuenta->cuenta_id_externo` es la página de Facebook o la cuenta de Instagram, según de
     * cuál sea la conversación. Es el mismo endpoint para las dos redes, y por eso esta función
     * es una sola.
     *
     * @param  array<string, mixed>  $cuerpo
     */
    private function llamarMensaje(CuentaRrss $cuenta, array $cuerpo): string
    {
        $respuesta = Http::timeout(20)->post(
            self::GRAPH_URL . "/{$cuenta->cuenta_id_externo}/messages",
            $cuerpo + ['access_token' => $cuenta->access_token],
        );

        $this->lanzarSiFalla($respuesta, 'No se pudo enviar el mensaje');

        return (string) ($respuesta->json('message_id') ?? $respuesta->json('id') ?? '');
    }

    // ─── Comentarios ─────────────────────────────────────────────────────────

    /**
     * Responde un comentario, colgando la respuesta de ese mismo comentario.
     *
     * El endpoint cambia por red y no es intercambiable: en Instagram las respuestas de un
     * comentario van a `/replies`, y en Facebook un comentario de un comentario va a
     * `/comments`. Usar el de la otra red devuelve un 404 sin explicación.
     */
    public function responderComentario(CuentaRrss $cuenta, string $comentarioId, string $texto, string $canal): string
    {
        $camino = Canales::red($canal) === 'instagram' ? 'replies' : 'comments';

        $respuesta = Http::asForm()->timeout(20)->post(
            self::GRAPH_URL . "/{$comentarioId}/{$camino}",
            ['message' => $texto, 'access_token' => $cuenta->access_token],
        );

        $this->lanzarSiFalla($respuesta, 'No se pudo responder el comentario');

        return (string) ($respuesta->json('id') ?? '');
    }

    // ─── Quién escribe, y de qué publicación ─────────────────────────────────

    /**
     * El nombre de quien escribe un directo.
     *
     * Meta **no siempre lo da**: depende de los permisos de la app y de la configuración del
     * perfil. Devuelve nulos en vez de reventar, porque una conversación sin nombre se atiende
     * igual —aparece como «Sin nombre»— y perder el mensaje por no saber cómo se llama la
     * persona sería absurdo.
     *
     * @return array{nombre: ?string, usuario: ?string}
     */
    public function perfil(CuentaRrss $cuenta, string $identificador): array
    {
        try {
            $respuesta = Http::timeout(10)->get(self::GRAPH_URL . "/{$identificador}", [
                'fields'       => 'name,username',
                'access_token' => $cuenta->access_token,
            ]);

            if (! $respuesta->successful()) {
                return ['nombre' => null, 'usuario' => null];
            }

            return [
                'nombre'  => $respuesta->json('name'),
                'usuario' => $respuesta->json('username'),
            ];
        } catch (\Throwable $e) {
            Log::info('Meta mensajería: no se pudo leer el perfil.', ['error' => $e->getMessage()]);

            return ['nombre' => null, 'usuario' => null];
        }
    }

    /**
     * De qué publicación salió un comentario: su enlace y un pedazo del texto.
     *
     * Hace falta de verdad: «¿cuánto vale?» en la bandeja no se puede responder sin saber qué
     * foto estaba mirando quien lo escribió.
     *
     * @return array{permalink: ?string, texto: ?string}
     */
    public function publicacion(CuentaRrss $cuenta, string $publicacionId): array
    {
        try {
            $respuesta = Http::timeout(10)->get(self::GRAPH_URL . "/{$publicacionId}", [
                // `message` es de Facebook y `caption` de Instagram: se piden los dos y se usa
                // el que venga, en vez de dos llamadas distintas según la red.
                'fields'       => 'permalink_url,permalink,message,caption',
                'access_token' => $cuenta->access_token,
            ]);

            if (! $respuesta->successful()) {
                return ['permalink' => null, 'texto' => null];
            }

            return [
                'permalink' => $respuesta->json('permalink_url') ?? $respuesta->json('permalink'),
                'texto'     => $respuesta->json('message') ?? $respuesta->json('caption'),
            ];
        } catch (\Throwable $e) {
            Log::info('Meta mensajería: no se pudo leer la publicación.', ['error' => $e->getMessage()]);

            return ['permalink' => null, 'texto' => null];
        }
    }

    // ─── Errores ─────────────────────────────────────────────────────────────

    /**
     * Los errores de Meta que de verdad aparecen, dichos en términos de qué hacer.
     *
     * El 2534037 es el que más confunde: Meta habla de «messaging window» y lo que pasó es que
     * se vencieron las 24 horas, igual que en WhatsApp pero con otro número de error.
     */
    private function lanzarSiFalla($respuesta, string $contexto): void
    {
        if ($respuesta->successful()) {
            return;
        }

        $datos  = $respuesta->json() ?? [];
        $codigo = (int) ($datos['error']['code'] ?? 0);
        $sub    = (int) ($datos['error']['error_subcode'] ?? 0);

        Log::error('Meta mensajería: ' . $contexto, [
            'status'    => $respuesta->status(),
            'respuesta' => $datos,
        ]);

        $explicacion = match (true) {
            $sub === 2534037, $codigo === 10 && $sub === 2018278 =>
                'pasaron más de 24 horas desde el último mensaje de esta persona, y Meta no permite '
                . 'escribir primero por este canal. Hay que esperar a que escriban otra vez.',
            $codigo === 190 =>
                'el permiso de esta cuenta venció o fue revocado. Hay que volver a conectarla en '
                . 'Redes Sociales → Cuentas.',
            $codigo === 200 =>
                'a la aplicación de Meta le faltan permisos para contestar por este canal '
                . '(pages_messaging para Messenger, instagram_manage_messages para Instagram).',
            $codigo === 100 =>
                'Meta no reconoce a quién se le está escribiendo. Suele pasar cuando la conversación '
                . 'es de otra cuenta conectada, o cuando la persona borró el mensaje.',
            default => $datos['error']['message'] ?? $respuesta->body(),
        };

        throw new RrssApiException("{$contexto}: {$explicacion}", $datos);
    }
}
