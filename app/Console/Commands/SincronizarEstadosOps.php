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
 *
 * También devuelve a «alistar» los productos que la 1.5.0 dejó mandar a planta (desde el 14 oct
 * 2026 solo los ensambles pasan por planta).
 */
class SincronizarEstadosOps extends Command
{
    protected $signature = 'ops:sincronizar-estados';

    protected $description = 'Pone al día el estado de las órdenes de producción abiertas';

    public function handle(): int
    {
        // Un producto no pasa por planta: los que la 1.5.0 alcanzó a mandar vuelven a alistar.
        $productos = app(\App\Services\TrabajoAutoGeneratorService::class)->quitarUnidadesDeProductos();

        if ($productos > 0) {
            $this->line("{$productos} producto(s) salieron de planta y quedaron para alistar.");
        }

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
