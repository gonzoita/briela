<?php

namespace App\Console\Commands;

use App\Services\Agentes\AgentesDeFabrica;
use App\Support\Modulos;
use Illuminate\Console\Command;

/**
 * Crea (o pone al día) los cargos y agentes de fábrica del Equipo de IA.
 *
 * Corre en cada despliegue, junto a `permisos:sincronizar`. Es idempotente y solo toca lo que es
 * de fábrica; los agentes que la empresa creó o ajustó se quedan como los dejó. Con el módulo
 * apagado no hace nada, para no llenar de filas una instalación que no lo usa.
 */
class SembrarAgentes extends Command
{
    protected $signature = 'agentes:sembrar {--forzar : Siembra aunque el módulo Equipo de IA esté apagado}';

    protected $description = 'Crea los cargos y agentes de fábrica del Equipo de IA';

    public function handle(AgentesDeFabrica $fabrica): int
    {
        if (! $this->option('forzar') && ! Modulos::activo('equipo_ia')) {
            $this->info('El módulo Equipo de IA está apagado; no se sembró nada.');

            return self::SUCCESS;
        }

        $c = $fabrica->sembrar();

        $this->info("Cargos nuevos: {$c['cargos']} · agentes nuevos: {$c['agentes']} · roles nuevos: {$c['roles']}.");

        return self::SUCCESS;
    }
}
