<?php

namespace App\Http\Controllers;

use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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
        $this->whatsapp->procesarWebhookEntrante($request->all());

        return response('OK', 200);
    }
}
