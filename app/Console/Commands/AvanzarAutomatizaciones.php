<?php

namespace App\Console\Commands;

use App\Models\AutomatizacionPaso;
use App\Services\Automatizaciones\LanzadorAutomatizaciones;
use Illuminate\Console\Command;

/**
 * El pulso de las Automatizaciones, cada minuto desde el cron.
 *
 * 1. Rescata las corridas que quedaron «en curso» porque el proceso murió sin avisar.
 * 2. Crea las corridas de las automatizaciones por horario a las que ya les toca.
 * 3. Retoma las corridas que esperaban y ya les llegó la hora.
 * 4. Limpia la bitácora de pasos de más de 90 días (las corridas y su resultado se conservan).
 *
 * Solo CREA trabajo; quien lo hace es el worker de `routes/console.php`.
 */
class AvanzarAutomatizaciones extends Command
{
    protected $signature = 'automatizaciones:avanzar';

    protected $description = 'Crea las corridas por horario, retoma las esperas vencidas y rescata las atascadas';

    public function handle(LanzadorAutomatizaciones $lanzador): int
    {
        $rescatadas = $lanzador->rescatarAtascadas();
        $creadas    = $lanzador->programadas(now());
        $retomadas  = $lanzador->reanudar();

        // Una vez por hora basta: es un DELETE sobre una tabla que crece.
        if (now()->minute === 0) {
            AutomatizacionPaso::where('created_at', '<', now()->subDays(90))->delete();
        }

        $this->info("Corridas por horario: {$creadas} · retomadas: {$retomadas} · rescatadas: {$rescatadas}.");

        return self::SUCCESS;
    }
}
