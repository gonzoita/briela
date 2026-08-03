<?php

namespace App\Console\Commands;

use App\Models\OpItemTrabajo;
use Illuminate\Console\Command;

// El % de avance solo se recalcula cuando se toca un paso (marcar/desmarcar,
// iniciar, editar horas). Los trabajos que ya estaban completados ANTES de
// normalizar la fórmula en OpItemTrabajo::recalcularAvance() se quedaron con
// el valor viejo guardado en base de datos (a veces 0% aunque estén 100%
// completados). Este comando recorre todos los trabajos existentes y fuerza
// el recálculo una sola vez con la fórmula nueva. Se puede correr las veces
// que haga falta, no rompe nada.
class RecalcularAvanceTrabajos extends Command
{
    protected $signature = 'trabajos:recalcular-avance';

    protected $description = 'Recalcula el % de avance de todos los trabajos con la fórmula normalizada';

    public function handle(): int
    {
        $total = OpItemTrabajo::count();
        $this->info("Recalculando avance de {$total} trabajo(s)...");

        $procesados = 0;
        OpItemTrabajo::with('pasos')->chunkById(100, function ($trabajos) use (&$procesados) {
            foreach ($trabajos as $trabajo) {
                $trabajo->recalcularAvance();
                $procesados++;
            }
        });

        $this->info("Listo — {$procesados} trabajo(s) recalculado(s).");

        return self::SUCCESS;
    }
}
