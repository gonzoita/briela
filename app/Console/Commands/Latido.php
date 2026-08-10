<?php

namespace App\Console\Commands;

use App\Services\LicenciaService;
use Illuminate\Console\Command;

/**
 * Pregunta al servidor de licencias por el estado de esta instalación.
 *
 * Va por el cron y no en las cargas de página a propósito: así ninguna pantalla
 * depende de que el servidor de licencias responda. Si no hay conexión, el sistema
 * sigue con lo último que supo durante los días de gracia.
 */
class Latido extends Command
{
    protected $signature = 'briela:latido';

    protected $description = 'Consulta el estado de la licencia y si hay una versión nueva';

    public function handle(LicenciaService $licencias): int
    {
        if ($licencias->serial() === null) {
            $this->warn('Esta instalación no tiene serial. Nada que consultar.');

            return self::SUCCESS;
        }

        $estado = $licencias->refrescar();

        $this->line('  Estado:  ' . ($estado['estado'] ?? '?'));
        $this->line('  Al día:  ' . (($estado['al_dia'] ?? false) ? 'sí' : 'no'));

        if ($dias = $estado['dias_para_vencer'] ?? null) {
            $this->line('  Vence:   ' . ($estado['vence_el'] ?? '?') . " (en {$dias} días)");
        }

        if ($nueva = $estado['actualizacion'] ?? null) {
            $this->info('  Hay versión nueva: ' . $nueva['version'] . ($nueva['obligatoria'] ? ' (obligatoria)' : ''));
        } else {
            $this->line('  Sin actualizaciones pendientes.');
        }

        return self::SUCCESS;
    }
}
