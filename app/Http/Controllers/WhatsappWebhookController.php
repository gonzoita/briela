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
     * **Sin App Secret se rechaza, salvo en local.** Hasta oct 2026 se dejaba pasar
     * y se anotaba en el log, con el argumento de que en producción el secreto iba
     * a existir siempre. No es cierto: el App Secret se carga en otra pantalla
     * —Redes Sociales → Cuentas— y la de WhatsApp solo avisaba que faltaba. Una
     * instalación perfectamente funcional podía quedarse meses sin él, con el
     * webhook abierto a que cualquiera que supiera la URL inventara mensajes, metiera
     * leads falsos al CRM y además se los repartiera entre los vendedores.
     *
     * En `local` se sigue dejando pasar, porque ahí el webhook se prueba a mano con
     * un cliente HTTP que no sabe firmar. Fuera de `local` no hay excusa: Meta
     * siempre firma.
     */
    private function firmaValida(Request $request): bool
    {
        $secreto = CredencialesRrss::valor('meta', 'secret');

        if ($secreto === '') {
            if (app()->environment('local')) {
                Log::warning('Webhook de WhatsApp aceptado sin verificar: no hay App Secret y el entorno es local.');

                return true;
            }

            Log::error(
                'Webhook de WhatsApp rechazado: falta el App Secret de Meta, así que no se puede '
                . 'comprobar que el mensaje venga de Meta. Se carga en Redes Sociales → Cuentas.'
            );

            return false;
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
