<?php

namespace App\Console\Commands;

use App\Services\WhatsappPlantillaService;
use Illuminate\Console\Command;

/**
 * Trae de Meta las plantillas aprobadas.
 *
 * Corre una vez al día porque el estado cambia del lado de Meta sin avisar: una plantilla
 * aprobada se puede pausar por mala calidad, y una en revisión se aprueba cuando le toca. Sin
 * esto, el selector de la bandeja ofrecería plantillas que Meta ya rechazó, y eso solo se
 * descubre cuando un mensaje a un cliente no sale.
 *
 * También se dispara a mano desde Configuración → Números de WhatsApp, que es lo que uno hace
 * justo después de crear una plantilla en Meta.
 */
class SincronizarPlantillasWhatsapp extends Command
{
    protected $signature = 'whatsapp:sincronizar-plantillas';

    protected $description = 'Trae de Meta las plantillas de WhatsApp y actualiza su estado';

    public function handle(WhatsappPlantillaService $plantillas): int
    {
        $resultado = $plantillas->sincronizar();

        if (! $resultado['ok']) {
            $this->error($resultado['mensaje']);

            return self::FAILURE;
        }

        $this->info($resultado['mensaje']);

        return self::SUCCESS;
    }
}
