<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use App\Services\NotificacionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Respaldo automático de la base de datos.
 *
 * Corre todos los días gracias al cron del servidor que ejecuta
 * "php artisan schedule:run" cada minuto.
 *
 * Si falla, avisa a los administradores por la campanita. Un respaldo que
 * falla en silencio es peor que no tener respaldo: da una falsa sensación de
 * seguridad hasta el día en que se necesita.
 */
class CrearBackup extends Command
{
    protected $signature = 'backup:crear {--origen=automatico : Etiqueta para el nombre del archivo}';

    protected $description = 'Genera una copia de seguridad de la base de datos y borra las viejas';

    public function handle(BackupService $backups, NotificacionService $notificaciones): int
    {
        $origen = $this->option('origen');

        try {
            $resultado = $backups->generar($origen);

            $this->info(sprintf(
                'Respaldo creado: %s (%s, vía %s)',
                $resultado['nombre'],
                BackupService::formatearBytes($resultado['bytes']),
                $resultado['metodo'],
            ));

            $borrados = $backups->limpiarViejos();

            if ($borrados > 0) {
                $this->info("Se borraron {$borrados} respaldos con más de " . BackupService::DIAS_RETENCION . ' días.');
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $mensaje = $e->getMessage();

            Log::error("El respaldo automático falló: {$mensaje}");
            $this->error("Falló: {$mensaje}");

            // Que el aviso falle no debe tapar el error original.
            try {
                $notificaciones->paraRol(
                    'administrador',
                    'backup_fallido',
                    'El respaldo automático falló',
                    $mensaje,
                    '/administracion/backup',
                    'alerta',
                    'rojo',
                );
            } catch (\Throwable $e2) {
                Log::warning("Tampoco se pudo avisar del fallo del respaldo: {$e2->getMessage()}");
            }

            return self::FAILURE;
        }
    }
}
