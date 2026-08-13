<?php

namespace App\Console\Commands;

use App\Services\OrdenesBrielaService;
use Illuminate\Console\Command;

/**
 * Ejecuta los recados que Briela dejó en el último latido.
 *
 * Va por cron y no dentro de una petición web porque un respaldo de base tarda: hacerlo
 * mientras alguien carga una pantalla le colgaría el navegador. Si el hosting del cliente
 * no tiene cron, la pantalla de respaldos las muestra con un botón para ejecutarlas a
 * mano — el sistema no puede depender de que el cron exista (regla 5).
 */
class EjecutarOrdenesBriela extends Command
{
    protected $signature = 'briela:ordenes';

    protected $description = 'Ejecuta las órdenes pendientes que dejó el panel de Briela (respaldos, por ahora)';

    public function handle(OrdenesBrielaService $ordenes): int
    {
        $pendientes = $ordenes->pendientes();

        if ($pendientes === []) {
            $this->info('No hay órdenes pendientes.');

            return self::SUCCESS;
        }

        $this->info(count($pendientes).' orden(es) pendiente(s).');

        foreach ($ordenes->ejecutarPendientes() as $resultado) {
            $this->line(' · '.$resultado);
        }

        return self::SUCCESS;
    }
}
