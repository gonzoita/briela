<?php

namespace App\Services;

use App\Exceptions\WhatsAppApiException;
use App\Models\WhatsappConversacion;
use App\Models\WhatsappMensaje;
use App\Models\WhatsappNumero;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    /**
     * Envía un mensaje de texto por WhatsApp Cloud API desde el número dado.
     *
     * @throws \RuntimeException si falta configuración (token no definido)
     */
    public function enviarMensaje(WhatsappNumero $numero, string $numeroDestino, string $texto): array
    {
        $token = config('services.whatsapp.token');
        if (empty($token)) {
            throw new \RuntimeException('WhatsApp no configurado: falta WHATSAPP_TOKEN en .env');
        }

        $version = config('services.whatsapp.api_version', 'v21.0');

        $response = Http::withToken($token)
            ->timeout(15)
            ->post("https://graph.facebook.com/{$version}/{$numero->phone_number_id}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => $numeroDestino,
                'type' => 'text',
                'text' => ['body' => $texto],
            ]);

        $data = $response->json() ?? [];

        if (!$response->successful()) {
            Log::error('WhatsApp: error al enviar mensaje', [
                'numero_id' => $numero->id,
                'destino' => $numeroDestino,
                'status' => $response->status(),
                'respuesta' => $data,
                'error_data_details' => $data['error']['error_data']['details'] ?? null,
            ]);

            $mensajeError = $data['error']['message'] ?? $response->body();
            $detalles = $data['error']['error_data']['details'] ?? null;
            if ($detalles) {
                $mensajeError .= " ({$detalles})";
            }

            throw new WhatsAppApiException(
                "Error al enviar mensaje de WhatsApp: {$mensajeError}",
                $data
            );
        }

        $waMessageId = $data['messages'][0]['id'] ?? null;

        $conversacion = WhatsappConversacion::updateOrCreate(
            [
                'whatsapp_numero_id' => $numero->id,
                'numero_contacto' => $numeroDestino,
            ],
            ['ultimo_mensaje_at' => now()]
        );

        $mensaje = WhatsappMensaje::create([
            'whatsapp_conversacion_id' => $conversacion->id,
            'wa_message_id' => $waMessageId,
            'direccion' => 'saliente',
            'tipo' => 'texto',
            'contenido' => $texto,
            'estado' => 'enviado',
            'usuario_id' => auth()->id(),
        ]);

        return [
            'mensaje' => $mensaje,
            'conversacion' => $conversacion,
            'respuesta' => $data,
        ];
    }

    /**
     * Procesa el payload de un webhook entrante de Meta. Nunca debe lanzar
     * excepción: Meta reintenta el webhook si no recibe 200 rápido.
     */
    public function procesarWebhookEntrante(array $payload): void
    {
        try {
            foreach ($payload['entry'] ?? [] as $entry) {
                foreach ($entry['changes'] ?? [] as $change) {
                    $value = $change['value'] ?? [];

                    if (!empty($value['messages'])) {
                        $this->procesarMensajesEntrantes($value);
                    }

                    if (!empty($value['statuses'])) {
                        $this->procesarStatuses($value['statuses']);
                    }

                    if (!empty($value['message_echoes'])) {
                        $this->procesarMessageEchoes($value);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('WhatsApp: error al procesar webhook entrante', [
                'error' => $e->getMessage(),
                'payload' => $payload,
            ]);
        }
    }

    private function procesarMensajesEntrantes(array $value): void
    {
        $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;
        $numero = WhatsappNumero::where('phone_number_id', $phoneNumberId)->first();
        if (!$numero) {
            Log::error('WhatsApp: mensaje entrante para phone_number_id desconocido', ['phone_number_id' => $phoneNumberId]);
            return;
        }

        $contactos = collect($value['contacts'] ?? [])->keyBy('wa_id');

        foreach ($value['messages'] as $msg) {
            $numeroContacto = $msg['from'] ?? null;
            if (!$numeroContacto) continue;

            $nombreContacto = $contactos->get($numeroContacto)['profile']['name'] ?? null;

            $conversacion = WhatsappConversacion::updateOrCreate(
                [
                    'whatsapp_numero_id' => $numero->id,
                    'numero_contacto' => $numeroContacto,
                ],
                [
                    'nombre_contacto' => $nombreContacto,
                    'ultimo_mensaje_at' => now(),
                    'leido' => false,
                ]
            );

            WhatsappMensaje::create([
                'whatsapp_conversacion_id' => $conversacion->id,
                'wa_message_id' => $msg['id'] ?? null,
                'direccion' => 'entrante',
                'tipo' => $msg['type'] ?? 'texto',
                'contenido' => $msg['text']['body'] ?? null,
            ]);
        }
    }

    private function procesarStatuses(array $statuses): void
    {
        foreach ($statuses as $status) {
            $waMessageId = $status['id'] ?? null;
            if (!$waMessageId) continue;

            WhatsappMensaje::where('wa_message_id', $waMessageId)
                ->update(['estado' => $status['status'] ?? null]);
        }
    }

    /**
     * Coexistencia: mensajes enviados por el asesor desde su propio celular
     * (no desde SGI). Mantiene el historial sincronizado.
     */
    private function procesarMessageEchoes(array $value): void
    {
        $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;
        $numero = WhatsappNumero::where('phone_number_id', $phoneNumberId)->first();
        if (!$numero) {
            Log::error('WhatsApp: message_echo para phone_number_id desconocido', ['phone_number_id' => $phoneNumberId]);
            return;
        }

        foreach ($value['message_echoes'] as $echo) {
            $numeroContacto = $echo['to'] ?? null;
            if (!$numeroContacto) continue;

            $conversacion = WhatsappConversacion::updateOrCreate(
                [
                    'whatsapp_numero_id' => $numero->id,
                    'numero_contacto' => $numeroContacto,
                ],
                ['ultimo_mensaje_at' => now()]
            );

            WhatsappMensaje::create([
                'whatsapp_conversacion_id' => $conversacion->id,
                'wa_message_id' => $echo['id'] ?? null,
                'direccion' => 'saliente',
                'tipo' => $echo['type'] ?? 'texto',
                'contenido' => $echo['text']['body'] ?? null,
                'estado' => 'enviado',
                'es_echo' => true,
            ]);
        }
    }

    public function verificarWebhook(string $mode, string $token, string $challenge): ?string
    {
        $verifyToken = config('services.whatsapp.verify_token');

        if ($mode === 'subscribe' && $verifyToken && hash_equals($verifyToken, $token)) {
            return $challenge;
        }

        return null;
    }
}
