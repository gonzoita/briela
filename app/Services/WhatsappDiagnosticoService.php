<?php

namespace App\Services;

use App\Models\WhatsappNumero;
use App\Support\CredencialesRrss;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Los probadores de la conexión con WhatsApp.
 *
 * Antes solo había una forma de saber si la conexión servía: mandarle un
 * mensaje a alguien de verdad y ver qué pasaba. Cuando fallaba, no había cómo
 * saber cuál de las cuatro piezas estaba mal — el token, el número, el webhook
 * o el App Secret—, y todas fallan igual de callado.
 *
 * Cada prueba responde por UNA pieza y dice qué hacer si falla. Ninguna manda
 * mensajes a terceros, salvo la de envío real, que pide el número a mano.
 */
class WhatsappDiagnosticoService
{
    private function version(): string
    {
        return config('services.whatsapp.api_version', 'v21.0');
    }

    // ─── El semáforo de la pantalla ───────────────────────────────────────────

    /**
     * Qué falta para que la conexión funcione, en el orden en que hay que
     * resolverlo. La pantalla lo pinta como una lista de chulos.
     */
    public function estado(): array
    {
        $token   = CredencialesRrss::valor('whatsapp', 'secret') !== '';
        $verify  = CredencialesRrss::valor('whatsapp', 'redirect') !== '';
        $secreto = CredencialesRrss::valor('meta', 'secret') !== '';
        $numeros = WhatsappNumero::activos()->count();
        $url     = url('/webhook/whatsapp');

        return [
            'tiene_token'      => $token,
            'tiene_verify'     => $verify,
            'tiene_app_secret' => $secreto,
            'numeros_activos'  => $numeros,
            'url_webhook'      => $url,
            'url_alcanzable'   => $this->urlEsPublica($url),
            // "Conectado" es poder mandar y recibir: token de la app, token del
            // webhook y al menos un número. El App Secret queda aparte porque
            // sin él se recibe igual (solo se pierde la firma del webhook).
            'lista'            => $token && $verify && $numeros > 0,
        ];
    }

    /**
     * Meta llama al webhook desde internet. Una URL local nunca le va a servir,
     * y es justo lo que aparece mientras se desarrolla o se prueba en Laragon.
     */
    private function urlEsPublica(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST) ?: '';

        if ($host === '') {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        foreach (['localhost', '.local', '.test', '.localhost'] as $sufijo) {
            if ($host === $sufijo || str_ends_with($host, $sufijo)) {
                return false;
            }
        }

        return str_contains($host, '.');
    }

    // ─── 1. Probar un número ──────────────────────────────────────────────────

    /**
     * Le pregunta a Meta por ESE identificador y compara lo que responde con lo
     * que hay escrito en el sistema. Es la prueba que atrapa el error más
     * costoso: un Phone Number ID pegado en el número equivocado, que manda los
     * mensajes desde otra línea sin que nadie se entere.
     */
    public function probarNumero(WhatsappNumero $numero): array
    {
        $token = CredencialesRrss::valor('whatsapp', 'secret');

        if ($token === '') {
            return $this->falla('Falta el token de acceso. Cárgalo arriba, en la conexión con WhatsApp.');
        }

        if (trim((string) $numero->phone_number_id) === '') {
            return $this->falla('Este número no tiene identificador de Meta (Phone Number ID).');
        }

        try {
            $resp = Http::withToken($token)->timeout(15)
                ->get("https://graph.facebook.com/{$this->version()}/{$numero->phone_number_id}", [
                    'fields' => 'display_phone_number,verified_name,quality_rating',
                ]);
        } catch (\Throwable $e) {
            return $this->falla('No se pudo contactar a Meta: ' . $e->getMessage());
        }

        if (! $resp->successful()) {
            return $this->falla(
                'Meta rechazó la consulta: ' . ($resp->json('error.message') ?? $resp->body()),
                $this->pistaDeMeta($resp->json('error.code'))
            );
        }

        $enMeta   = (string) ($resp->json('display_phone_number') ?? '');
        $nombre   = (string) ($resp->json('verified_name') ?? '');
        $calidad  = (string) ($resp->json('quality_rating') ?? '');
        $coincide = $this->mismoNumero($enMeta, (string) $numero->numero_telefono);

        return [
            'ok'      => true,
            'mensaje' => trim("Responde: {$nombre} {$enMeta}") . '.',
            'detalle' => array_values(array_filter([
                $calidad !== '' ? "Calidad de la línea según Meta: {$calidad}." : null,
                $coincide
                    ? null
                    : "Ojo: en el sistema este número está escrito como «{$numero->numero_telefono}», "
                      . "y Meta dice que el identificador corresponde a «{$enMeta}». "
                      . 'Los mensajes saldrían desde la línea de Meta, no desde la escrita acá.',
            ])),
            // Si el número no coincide la consulta funcionó, pero hay algo que
            // corregir: se avisa sin decir que todo está bien.
            'aviso'   => ! $coincide,
        ];
    }

    /** Compara por los últimos 10 dígitos: nadie escribe los teléfonos igual. */
    private function mismoNumero(string $a, string $b): bool
    {
        $limpiar = fn (string $v) => substr(preg_replace('/\D+/', '', $v) ?: '', -10);

        $a = $limpiar($a);
        $b = $limpiar($b);

        return $a !== '' && $a === $b;
    }

    // ─── 2. Probar el webhook ─────────────────────────────────────────────────

    /**
     * Repite lo que hace Meta al suscribirse: llama a la propia URL con el token
     * de verificación guardado y comprueba que le devuelva el desafío.
     *
     * Si la URL es local no se llama a nada: no es que la prueba falle, es que
     * Meta jamás va a poder llegar ahí, y decirlo claro ahorra media hora de
     * buscar el error en el lado equivocado.
     */
    public function probarWebhook(): array
    {
        $estado = $this->estado();

        if (! $estado['tiene_verify']) {
            return $this->falla('Falta el token de verificación del webhook. Genéralo arriba y pégalo también en Meta.');
        }

        if (! $estado['url_alcanzable']) {
            return $this->falla(
                "La URL del webhook es {$estado['url_webhook']}, que solo existe dentro de este computador.",
                'Meta necesita una dirección pública con HTTPS. Configura el webhook desde el servidor '
                . 'donde está instalado el sistema, o usa un túnel (ngrok) mientras pruebas en local.'
            );
        }

        $desafio = (string) random_int(100000, 999999);

        try {
            $resp = Http::timeout(10)->get($estado['url_webhook'], [
                'hub_mode'         => 'subscribe',
                'hub_verify_token' => CredencialesRrss::valor('whatsapp', 'redirect'),
                'hub_challenge'    => $desafio,
            ]);
        } catch (\Throwable $e) {
            return $this->falla(
                'La URL del webhook no respondió: ' . $e->getMessage(),
                'Revisa que el sitio esté publicado y que el certificado HTTPS sea válido.'
            );
        }

        if (trim($resp->body()) !== $desafio) {
            return $this->falla(
                'El webhook respondió, pero no devolvió el desafío (código ' . $resp->status() . ').',
                'Suele ser que el token de verificación guardado acá no es el mismo que pegaste en Meta.'
            );
        }

        return [
            'ok'      => true,
            'mensaje' => 'El webhook responde y el token de verificación coincide.',
            'detalle' => array_values(array_filter([
                'Falta lo que solo se puede hacer en Meta: suscribir el webhook al campo «messages». '
                . 'Sin eso la URL funciona pero no llega ningún mensaje.',
                $estado['tiene_app_secret']
                    ? null
                    : 'No hay App Secret guardado, así que los mensajes entrantes se aceptan sin verificar la firma. '
                      . 'Cárgalo en Marketing → Redes Sociales → Cuentas (es el de la misma app de Meta): '
                      . 'sin él, cualquiera que sepa esta URL puede inventar mensajes y meter leads falsos al CRM.',
            ])),
            'aviso'   => ! $estado['tiene_app_secret'],
        ];
    }

    // ─── 3. Enviar un mensaje real ────────────────────────────────────────────

    /**
     * La única prueba que recorre el circuito completo. Manda un mensaje de
     * verdad, así que el destino lo escribe una persona.
     */
    public function enviarPrueba(WhatsappNumero $numero, string $destino, string $texto): array
    {
        $digitos = preg_replace('/\D+/', '', $destino) ?: '';

        if (strlen($digitos) < 10) {
            return $this->falla('Ese número no parece completo. Escríbelo con indicativo de país, por ejemplo 573001234567.');
        }

        try {
            $resultado = app(WhatsAppService::class)->enviarMensaje($numero, $digitos, $texto);
        } catch (\App\Exceptions\WhatsAppApiException $e) {
            return $this->falla($e->getMessage(), $this->pistaDeEnvio($e->getMessage()));
        } catch (\Throwable $e) {
            return $this->falla($e->getMessage());
        }

        return [
            'ok'      => true,
            'mensaje' => "Mensaje enviado a {$digitos} desde «{$numero->nombre}».",
            'detalle' => [
                'Si no llega en unos segundos, casi siempre es la ventana de 24 horas: Meta solo deja '
                . 'escribir libremente a quien te escribió en las últimas 24 horas. Para iniciar una '
                . 'conversación en frío hace falta una plantilla aprobada.',
                'Identificador del mensaje: ' . ($resultado['mensaje']->wa_message_id ?? 'sin identificador'),
            ],
        ];
    }

    // ─── Pistas ───────────────────────────────────────────────────────────────

    private function pistaDeMeta(mixed $codigo): ?string
    {
        return match ((int) $codigo) {
            190 => 'El token venció o fue revocado. Si generaste el token en la pantalla de pruebas de Meta, '
                 . 'dura 24 horas: hay que crear uno permanente desde un Usuario del Sistema del Business Manager.',
            100 => 'El identificador no existe o el token no tiene permiso sobre él. Verifica que copiaste el '
                 . '«Identificador del número de teléfono» y no el de la cuenta de WhatsApp Business.',
            200, 10 => 'Al token le faltan permisos. El Usuario del Sistema necesita whatsapp_business_messaging.',
            default => null,
        };
    }

    private function pistaDeEnvio(string $error): ?string
    {
        $e = mb_strtolower($error);

        if (str_contains($e, '24') && str_contains($e, 'window')) {
            return 'Es la ventana de 24 horas: pídele a ese número que te escriba primero y vuelve a intentar.';
        }

        if (str_contains($e, 'template')) {
            return 'Meta exige una plantilla aprobada para iniciar la conversación. Pídele a ese número que escriba primero.';
        }

        if (str_contains($e, 'recipient')) {
            return 'Revisa el número: va con indicativo de país y sin signos, por ejemplo 573001234567.';
        }

        return null;
    }

    private function falla(string $mensaje, ?string $pista = null): array
    {
        return [
            'ok'      => false,
            'mensaje' => $mensaje,
            'detalle' => array_values(array_filter([$pista])),
        ];
    }

    /** Un token de verificación decente, para no obligar a inventarse uno. */
    public static function tokenSugerido(): string
    {
        return 'briela-' . Str::lower(Str::random(24));
    }
}
