<?php

namespace App\Services\Meta;

use App\Exceptions\RrssApiException;
use App\Models\Configuracion;
use App\Models\WhatsappNumero;
use App\Services\WhatsappDiagnosticoService;
use App\Support\ConexionMeta;
use App\Support\CredencialesRrss;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Termina la vinculación de WhatsApp que empezó el botón «Conectar WhatsApp» (Embedded Signup).
 *
 * El navegador entrega tres cosas: el `code` de autorización, el `waba_id` y el `phone_number_id`.
 * Aquí se canjea el código por el token —con el App Secret, que nunca sale del servidor—, se
 * guarda, se suscribe la aplicación a la cuenta y se deja el número listo en la bandeja. Todo lo
 * que antes eran tres pasos manuales en el panel de Meta (token, webhook, identificador).
 *
 * **Con coexistencia el número NO se registra otra vez.** Ya vive en la app WhatsApp Business del
 * celular; registrarlo en la API en la nube lo sacaría de ahí, que es justo lo que la
 * coexistencia evita. Un número nuevo sí se registra, con un PIN que se guarda cifrado.
 *
 * **Lo que llega del navegador no se cree.** El `waba_id` y el `phone_number_id` vienen de un
 * evento `message` de la página; cualquier script lo puede fabricar. Por eso el código se canjea
 * en el servidor y los identificadores se contrastan con lo que Meta dice que ese token puede
 * ver: un identificador que el token no alcanza se descarta.
 */
class EmbeddedSignupService
{
    public function __construct(private readonly SuscripcionesMeta $suscripciones)
    {
    }

    /**
     * @return array{numeros: array<int, string>, waba_id: string, coexistencia: bool, avisos: array<int, string>}
     *
     * @throws RrssApiException si el código no se puede canjear
     */
    public function conectar(string $code, ?string $wabaId, ?string $phoneNumberId, bool $coexistencia): array
    {
        $avisos = [];

        // 1) code -> token. Embedded Signup no usa `redirect_uri`: el código nace en una ventana
        //    emergente del SDK, no en una redirección.
        $respuesta = Http::get(ConexionMeta::graph() . '/oauth/access_token', [
            'client_id'     => ConexionMeta::appId(),
            'client_secret' => CredencialesRrss::valor('meta', 'secret'),
            'code'          => $code,
        ]);
        $this->lanzarSiFalla($respuesta, 'Meta no aceptó el código de autorización (vence a los pocos minutos)');

        $token = (string) $respuesta->json('access_token');

        if ($token === '') {
            throw new RrssApiException('Meta no devolvió el token de acceso.', $respuesta->json() ?? []);
        }

        // 2) La cuenta. Si el navegador no la mandó —se cerró la ventana a medias, o el evento no
        //    llegó— se le pregunta a Meta a qué cuentas llega este token.
        $cuentas = $this->cuentasDelToken($token);
        $wabaId = $wabaId && ($cuentas === [] || in_array($wabaId, $cuentas, true)) ? $wabaId : ($cuentas[0] ?? null);

        if (! $wabaId) {
            throw new RrssApiException('No se pudo saber a qué cuenta de WhatsApp Business se vinculó el número.', []);
        }

        // 3) Guardar. Es el token que sirve para enviar, descargar adjuntos y traer plantillas.
        CredencialesRrss::guardar('whatsapp', 'secret', $token);
        CredencialesRrss::guardar('whatsapp', 'waba', $wabaId);

        $expira = (int) $respuesta->json('expires_in');
        Configuracion::set('whatsapp_token_expira_en', $expira > 0 ? now()->addSeconds($expira)->toIso8601String() : '');

        // El token de verificación del webhook lo inventa la propia instalación: es una
        // contraseña compartida con Meta, no un dato que Meta entregue.
        if (CredencialesRrss::valor('whatsapp', 'redirect') === '') {
            CredencialesRrss::guardar('whatsapp', 'redirect', WhatsappDiagnosticoService::tokenSugerido());
        }

        // 4) Suscribir la aplicación a la cuenta: sin esto la conexión «funciona» y no entra nada.
        $suscripcion = $this->suscripciones->suscribirWaba($wabaId, $token);

        if (! $suscripcion['ok']) {
            $avisos[] = 'No se pudo suscribir la cuenta a los webhooks: ' . $suscripcion['mensaje'];
        }

        // 5) Los números de la cuenta.
        $numeros = [];

        foreach ($this->numerosDeLaCuenta($wabaId, $token, $phoneNumberId) as $linea) {
            $numero = $this->guardarNumero($linea, $wabaId, $coexistencia);
            $numeros[] = $numero->nombre . ' · ' . $numero->numero_telefono;

            if (! $coexistencia && ! $this->registrarEnLaApi($numero, $token)) {
                $avisos[] = "No se pudo registrar {$numero->numero_telefono} en la API en la nube; revísalo en el administrador de WhatsApp.";
            }
        }

        if ($numeros === []) {
            $avisos[] = 'La cuenta quedó conectada, pero Meta no listó ningún número en ella.';
        }

        // 6) La dirección del webhook en la aplicación, si se puede (no sirve en una máquina local).
        foreach ($this->suscripciones->registrarWebhookDeLaApp() as $objeto => $r) {
            if (! $r['ok']) {
                // «app» es el resultado de no poder intentarlo; los demás, el de cada objeto.
                $avisos[] = $objeto === 'app' ? $r['mensaje'] : "Webhook de {$objeto}: {$r['mensaje']}";
            }
        }

        return [
            'numeros'      => $numeros,
            'waba_id'      => $wabaId,
            'coexistencia' => $coexistencia,
            'avisos'       => $avisos,
        ];
    }

    /**
     * Las cuentas de WhatsApp Business a las que este token tiene acceso, según Meta.
     *
     * Sale de `debug_token`: sus `granular_scopes` listan los identificadores (`target_ids`) que
     * el cliente autorizó para `whatsapp_business_management`. Es la fuente de verdad, no lo que
     * diga el navegador.
     *
     * @return array<int, string>
     */
    private function cuentasDelToken(string $token): array
    {
        try {
            $respuesta = Http::timeout(15)->get(ConexionMeta::graph() . '/debug_token', [
                'input_token'  => $token,
                'access_token' => ConexionMeta::appId() . '|' . CredencialesRrss::valor('meta', 'secret'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Meta: no se pudo inspeccionar el token de Embedded Signup.', ['error' => $e->getMessage()]);

            return [];
        }

        foreach ($respuesta->json('data.granular_scopes', []) as $permiso) {
            if (($permiso['scope'] ?? '') === 'whatsapp_business_management') {
                return array_values(array_map('strval', $permiso['target_ids'] ?? []));
            }
        }

        return [];
    }

    /**
     * Los números de la cuenta con sus datos. Si el navegador dijo cuál se acababa de vincular,
     * solo ese: una cuenta puede tener otras líneas que no se quisieron traer.
     *
     * @return array<int, array{id: string, display_phone_number?: string, verified_name?: string}>
     */
    private function numerosDeLaCuenta(string $wabaId, string $token, ?string $phoneNumberId): array
    {
        try {
            $respuesta = Http::withToken($token)->timeout(15)->get(
                ConexionMeta::graph() . "/{$wabaId}/phone_numbers",
                ['fields' => 'id,display_phone_number,verified_name'],
            );
        } catch (\Throwable $e) {
            Log::warning('Meta: no se pudo listar los números de la cuenta.', ['error' => $e->getMessage()]);

            return $phoneNumberId ? [['id' => $phoneNumberId]] : [];
        }

        $lineas = $respuesta->successful() ? ($respuesta->json('data') ?? []) : [];

        if ($phoneNumberId) {
            $una = array_values(array_filter($lineas, fn ($l) => (string) ($l['id'] ?? '') === $phoneNumberId));

            // Si Meta no lo lista (todavía se está propagando), se guarda con lo que se sabe.
            return $una !== [] ? $una : [['id' => $phoneNumberId]];
        }

        return $lineas;
    }

    /** @param  array{id: string, display_phone_number?: string, verified_name?: string}  $linea */
    private function guardarNumero(array $linea, string $wabaId, bool $coexistencia): WhatsappNumero
    {
        $telefono = isset($linea['display_phone_number'])
            ? '+' . preg_replace('/\D+/', '', $linea['display_phone_number'])
            : '';

        $numero = WhatsappNumero::firstOrNew(['phone_number_id' => $linea['id']]);

        // Un número que ya estaba cargado conserva su nombre, su rol y a quien está asignado: la
        // vinculación solo agrega lo que Embedded Signup sabe.
        if (! $numero->exists) {
            $numero->nombre = $linea['verified_name'] ?? 'WhatsApp';
            $numero->numero_telefono = $telefono !== '' ? $telefono : 'pendiente';
            // El primero es la central; los demás, asesores. Se cambia desde la pantalla.
            $numero->rol = WhatsappNumero::central()->exists() ? 'asesor' : 'central';
        } elseif ($telefono !== '' && ! $numero->numero_telefono) {
            $numero->numero_telefono = $telefono;
        }

        $numero->waba_id = $wabaId;
        $numero->coexistencia = $coexistencia;
        $numero->activo = true;
        $numero->vinculado_at = now();
        $numero->save();

        return $numero;
    }

    /**
     * Registra un número nuevo en la API en la nube. Devuelve si quedó registrado.
     *
     * Un número que ya estaba registrado responde con error y no es un problema: se da por
     * bueno si Meta dice que ya está. El PIN —dos pasos— se inventa aquí y se guarda cifrado.
     */
    private function registrarEnLaApi(WhatsappNumero $numero, string $token): bool
    {
        $pin = $numero->pin_registro ?: str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        try {
            $respuesta = Http::withToken($token)->timeout(20)->post(
                ConexionMeta::graph() . "/{$numero->phone_number_id}/register",
                ['messaging_product' => 'whatsapp', 'pin' => $pin],
            );
        } catch (\Throwable $e) {
            Log::warning('Meta: no se pudo registrar el número.', ['numero' => $numero->id, 'error' => $e->getMessage()]);

            return false;
        }

        if ($respuesta->successful()) {
            $numero->update(['pin_registro' => $pin]);

            return true;
        }

        Log::warning('Meta: el registro del número fue rechazado.', ['numero' => $numero->id, 'respuesta' => $respuesta->json()]);

        return false;
    }

    private function lanzarSiFalla($respuesta, string $mensaje): void
    {
        if ($respuesta->successful()) {
            return;
        }

        $datos = $respuesta->json() ?? [];
        Log::error('Meta Embedded Signup: ' . $mensaje, ['status' => $respuesta->status(), 'respuesta' => $datos]);

        throw new RrssApiException($mensaje . ': ' . ($datos['error']['message'] ?? $respuesta->body()), $datos);
    }
}
