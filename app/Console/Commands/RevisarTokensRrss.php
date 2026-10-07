<?php

namespace App\Console\Commands;

use App\Services\Rrss\TokensRrssService;
use Illuminate\Console\Command;

/**
 * Renueva los permisos de las redes que se pueden renovar solos, y avisa de los que no.
 *
 * Corre una vez al día, de madrugada. No hace falta más: lo que vigila vence en meses, y la
 * renovación se intenta con 20 días de margen.
 */
class RevisarTokensRrss extends Command
{
    protected $signature = 'rrss:revisar-tokens';

    protected $description = 'Renueva los permisos de las redes por vencer y avisa de los que hay que reconectar a mano';

    public function handle(TokensRrssService $tokens): int
    {
        $resultado = $tokens->revisar();

        foreach ($resultado['renovadas'] as $cuenta) {
            $this->info("Renovado solo: {$cuenta}");
        }

        foreach ($resultado['avisadas'] as $cuenta) {
            $this->warn("Por vencer, hay que reconectar: {$cuenta}");
        }

        foreach ($resultado['vencidas'] as $cuenta) {
            $this->error("Vencido: {$cuenta}");
        }

        if (! array_filter($resultado)) {
            $this->info('Todos los permisos están al día.');
        }

        return self::SUCCESS;
    }
}
