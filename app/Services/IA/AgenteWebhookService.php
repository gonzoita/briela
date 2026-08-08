<?php

namespace App\Services\IA;

use App\Models\Configuracion;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Dispara un webhook cuando el agente termina de atender a alguien, para que
 * otro sistema haga lo que tenga que hacer (meterlo a una campaña, avisar a un
 * departamento, lo que sea).
 *
 * Va aparte de las acciones internas —crear el lead, repartirlo, avisar por la
 * campanita— porque esas ya las hace el sistema solo y no deberían depender de
 * que un servicio externo responda. El webhook es **adicional**: si falla, no
 * se pierde nada de lo interno.
 *
 * El envío se firma con HMAC para que quien lo reciba pueda comprobar que
 * viene de aquí y no de un tercero que adivinó la URL.
 */
class AgenteWebhookService
{
    public static function config(): array
    {
        return [
            'activo'  => Configuracion::get('agente_webhook_activo', '0') === '1',
            'url'     => (string) Configuracion::get('agente_webhook_url', ''),
            'secreto' => (string) Configuracion::get('agente_webhook_secreto', ''),
        ];
    }

    /**
     * @param  string  $evento  Qué ocurrió: 'lead_creado', 'derivado', 'cerrado'.
     * @param  array   $datos   Lo que se sabe del contacto y la conversación.
     */
    public function disparar(string $evento, array $datos): void
    {
        $cfg = static::config();

        if (! $cfg['activo'] || $cfg['url'] === '') {
            return;
        }

        $cuerpo = [
            'evento'     => $evento,
            'ocurrio_en' => now()->toIso8601String(),
            'datos'      => $datos,
        ];

        $json = json_encode($cuerpo);

        $cabeceras = ['Content-Type' => 'application/json'];

        if ($cfg['secreto'] !== '') {
            // El receptor recalcula esta firma con el mismo secreto: si no
            // coincide, la petición no vino de aquí.
            $cabeceras['X-Firma'] = hash_hmac('sha256', $json, $cfg['secreto']);
        }

        try {
            $resp = Http::withHeaders($cabeceras)->timeout(10)->send('POST', $cfg['url'], ['body' => $json]);

            if (! $resp->successful()) {
                Log::warning('Agente webhook: el destino respondió con error', [
                    'evento' => $evento,
                    'status' => $resp->status(),
                ]);
            }
        } catch (\Throwable $e) {
            // A propósito no se relanza: el webhook es un extra. Que el sistema
            // de un tercero esté caído no puede romper la atención al cliente.
            Log::error('Agente webhook: no se pudo enviar', [
                'evento' => $evento,
                'error'  => $e->getMessage(),
            ]);
        }
    }
}
