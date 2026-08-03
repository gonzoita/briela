<?php

namespace App\Console\Commands;

use App\Exceptions\WhatsAppApiException;
use App\Models\WhatsappNumero;
use App\Services\WhatsAppService;
use Illuminate\Console\Command;

class WhatsappTestSend extends Command
{
    protected $signature = 'whatsapp:test {numero_destino} {mensaje} {--numero-id= : ID del WhatsappNumero a usar (por defecto, el primero activo)}';

    protected $description = 'Envía un mensaje de prueba por WhatsApp Cloud API para verificar la configuración';

    public function handle(WhatsAppService $whatsapp): int
    {
        $numeroId = $this->option('numero-id');

        $numero = $numeroId
            ? WhatsappNumero::find($numeroId)
            : WhatsappNumero::activos()->first();

        if (!$numero) {
            $this->error($numeroId
                ? "No se encontró un WhatsappNumero con id={$numeroId}."
                : 'No hay números registrados. Créalo primero en /configuracion/whatsapp-numeros');

            return self::FAILURE;
        }

        $this->info("Enviando mensaje de prueba desde \"{$numero->nombre}\" ({$numero->numero_telefono}) a {$this->argument('numero_destino')}...");

        try {
            $resultado = $whatsapp->enviarMensaje($numero, $this->argument('numero_destino'), $this->argument('mensaje'));

            $this->info('Mensaje enviado correctamente.');
            $this->line('wa_message_id: ' . ($resultado['mensaje']->wa_message_id ?? 'N/D'));

            return self::SUCCESS;
        } catch (WhatsAppApiException $e) {
            $this->error($e->getMessage());
            $this->line('Respuesta completa de Meta:');
            $this->line(json_encode($e->getResponseData(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::FAILURE;
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
