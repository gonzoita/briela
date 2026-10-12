<?php

namespace App\Console\Commands;

use App\Services\ReservaStockService;
use Illuminate\Console\Command;

/**
 * Suelta el stock que lleva más de 24 horas apartado por una cotización que no se concretó.
 *
 * Corre cada quince minutos: no hace falta más fino. Mientras tanto una reserva vencida ya no
 * aparta, porque `ReservaStock::vigentes()` mira la hora y no solo el estado.
 */
class LiberarReservasStock extends Command
{
    protected $signature = 'stock:liberar-reservas';

    protected $description = 'Libera el stock apartado por cotizaciones hace más de 24 horas';

    public function handle(ReservaStockService $reservas): int
    {
        $n = $reservas->liberarVencidas();

        $this->info($n === 0 ? 'No había reservas vencidas.' : "{$n} reserva(s) liberada(s).");

        return self::SUCCESS;
    }
}
