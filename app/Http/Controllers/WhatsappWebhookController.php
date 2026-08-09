<?php

namespace App\Http\Controllers;

use App\Services\WhatsAppService;
use App\Support\CredencialesRrss;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WhatsappWebhookController extends Controller
{
    public function __construct(private WhatsAppService $whatsapp)
    {
    }

    public function verify(Request $request): Response
    {
        $challenge = $this->whatsapp->verificarWebhook(
            (string) $request->query('hub_mode'),
            (string) $request->query('hub_verify_token'),
            (string) $request->query('hub_challenge')
        );

        if ($challenge === null) {
            return response('Forbidden', 403);
        }

        return response($challenge, 200);
    }

    public function receive(Request $request): Response
    {
        if (! $this->firmaValida($request)) {
            return response('Forbidden', 403);
        }

        $this->whatsapp->procesarWebhookEntrante($request->all());

        return response('OK', 200);
    }

    /**
     * Esta ruta no tiene login: es Meta quien la llama. Sin comprobar la firma,
     * cualquiera que sepa la URL podría inventar mensajes y meter leads falsos
     * al CRM, que además se repartirían solos entre los vendedores.
     *
     * Meta firma el cuerpo con HMAC-SHA256 usando el App Secret de la misma app
     * de Meta que administra WhatsApp, y lo manda en `X-Hub-Signature-256`.
     *
     * Si todavía no hay App Secret guardado se deja pasar y se anota en el log:
     * para recibir mensajes de verdad hay que tener la app de Meta creada, así
     * que en producción el secreto siempre va a existir. No se bloquea antes de
     * tiempo para no romper las pruebas locales.
     */
    private function firmaValida(Request $request): bool
    {
        $secreto = CredencialesRrss::valor('meta', 'secret');

        if ($secreto === '') {
            Log::warning('Webhook de WhatsApp recibido sin App Secret configurado: no se pudo verificar la firma.');

            return true;
        }

        $recibida = (string) $request->header('X-Hub-Signature-256');

        if ($recibida === '') {
            Log::warning('Webhook de WhatsApp rechazado: llegó sin la cabecera de firma.');

            return false;
        }

        $esperada = 'sha256='.hash_hmac('sha256', $request->getContent(), $secreto);

        if (! hash_equals($esperada, $recibida)) {
            Log::warning('Webhook de WhatsApp rechazado: la firma no coincide.');

            return false;
        }

        return true;
    }
}
