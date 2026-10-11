<?php

namespace App\Console\Commands;

use App\Models\Op;
use Illuminate\Console\Command;

/**
 * Pone al día el estado de las órdenes abiertas.
 *
 * Hasta el 12 oct 2026 alistar un producto no movía la orden: hay órdenes con todo listo que
 * siguen «En producción». Esto les aplica la regla de `Op::sincronizarEstado()` una vez. Se puede
 * correr las veces que haga falta: a una orden que ya está al día no le cambia nada.
 */
class SincronizarEstadosOps extends Command
{
    protected $signature = 'ops:sincronizar-estados';

    protected $description = 'Pone al día el estado de las órdenes de producción abiertas';

    public function handle(): int
    {
        $cambiadas = 0;

        Op::whereIn('estado', ['confirmada', 'en_produccion', 'calidad', 'reproceso'])
            ->orderBy('id')
            ->chunkById(100, function ($ops) use (&$cambiadas) {
                foreach ($ops as $op) {
                    $antes = $op->estado.'|'.$op->calidad_aprobada_at;
                    $op->sincronizarEstado();

                    if ($op->estado.'|'.$op->calidad_aprobada_at !== $antes) {
                        $cambiadas++;
                        $this->line("{$op->numero}: {$op->estado}");
                    }
                }
            });

        $this->info("Listo: {$cambiadas} orden(es) puestas al día.");

        return self::SUCCESS;
    }
}
