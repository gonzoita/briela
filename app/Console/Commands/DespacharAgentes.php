<?php

namespace App\Console\Commands;

use App\Models\AgentePaso;
use App\Models\IaConsumo;
use App\Services\Agentes\AprobacionService;
use App\Services\Agentes\LanzadorAgentes;
use Illuminate\Console\Command;

/**
 * El pulso del Equipo de IA, cada cinco minutos desde el cron.
 *
 * 1. Rescata las corridas que quedaron «en curso» porque el proceso murió sin avisar.
 * 2. Vence las propuestas que nadie decidió a tiempo.
 * 3. Crea y pone en cola las corridas de los agentes a los que ya les toca.
 * 4. Limpia lo viejo: la bitácora de pasos se queda 90 días y el libro de consumo 400. Los
 *    resúmenes de las ejecuciones y las propuestas decididas NO se borran.
 *
 * Solo crea trabajo; quien lo hace es el worker de `routes/console.php`.
 */
class DespacharAgentes extends Command
{
    protected $signature = 'agentes:despachar';

    protected $description = 'Despacha los agentes que les toca, rescata los atascados y vence propuestas';

    public function handle(LanzadorAgentes $lanzador, AprobacionService $aprobaciones): int
    {
        $rescatadas = $lanzador->rescatarAtascadas();
        $vencidas   = $aprobaciones->vencerLasViejas();
        $creadas    = $lanzador->programadas(now());

        AgentePaso::where('created_at', '<', now()->subDays(90))->delete();
        IaConsumo::where('fecha', '<', now()->subDays(400)->toDateString())->delete();

        $this->info("Corridas creadas: {$creadas} · rescatadas: {$rescatadas} · propuestas vencidas: {$vencidas}.");

        return self::SUCCESS;
    }
}
