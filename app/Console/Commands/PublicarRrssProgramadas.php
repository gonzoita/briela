<?php

namespace App\Console\Commands;

use App\Models\PublicacionRrss;
use App\Services\Rrss\RrssPublicadorService;
use Illuminate\Console\Command;

// Revisa cada minuto las publicaciones de RRSS programadas cuya fecha ya
// llegó, y las publica en cada cuenta destino. Corre gracias al cron del
// servidor que ejecuta "php artisan schedule:run" cada minuto.
class PublicarRrssProgramadas extends Command
{
    protected $signature = 'rrss:publicar-programadas';

    protected $description = 'Publica las publicaciones de RRSS programadas cuya fecha ya se cumplió';

    public function handle(RrssPublicadorService $publicador): int
    {
        $pendientes = PublicacionRrss::pendientesDePublicar()->get();

        if ($pendientes->isEmpty()) {
            return self::SUCCESS;
        }

        foreach ($pendientes as $publicacion) {
            try {
                $publicador->publicar($publicacion);
                $this->info("Publicación #{$publicacion->id}: {$publicacion->fresh()->estado}");
            } catch (\Throwable $e) {
                $this->error("Publicación #{$publicacion->id} falló por completo: {$e->getMessage()}");
                $publicacion->update(['estado' => 'fallida']);
            }
        }

        return self::SUCCESS;
    }
}
