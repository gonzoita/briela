<?php

namespace App\Console\Commands;

use App\Models\Inscripcion;
use App\Models\Notificacion;
use App\Models\User;
use App\Services\NotificacionService;
use Illuminate\Console\Command;

// Aviso diario a los colaboradores internos con un curso obligatorio cuya
// fecha límite se acerca y que todavía no completaron. No repite el aviso de
// la misma inscripción si ya se envió en las últimas 20 horas.
class NotificarCursosPorVencer extends Command
{
    protected $signature = 'notificaciones:cursos-por-vencer {--dias=3}';

    protected $description = 'Avisa a los colaboradores de cursos obligatorios por vencer';

    public function handle(NotificacionService $notif): int
    {
        $dias  = (int) $this->option('dias');
        $hasta = now()->addDays($dias)->toDateString();

        $inscripciones = Inscripcion::where('obligatorio', true)
            ->whereNotNull('fecha_limite')
            ->whereDate('fecha_limite', '<=', $hasta)
            ->whereNotIn('estado', ['completado', 'aprobado'])
            ->where('inscribible_type', User::class)
            ->with('curso')
            ->get();

        $enviadas = 0;

        foreach ($inscripciones as $ins) {
            $yaAvisado = Notificacion::where('tipo', 'curso_por_vencer')
                ->where('user_id', $ins->inscribible_id)
                ->where('created_at', '>=', now()->subHours(20))
                ->where('mensaje', 'like', "%{$ins->curso->titulo}%")
                ->exists();
            if ($yaAvisado) continue;

            $fecha = \Carbon\Carbon::parse($ins->fecha_limite)->format('d/m/Y');

            $notif->crear(
                $ins->inscribible_id,
                'curso_por_vencer',
                'Curso obligatorio por vencer',
                "El curso \"{$ins->curso->titulo}\" vence el {$fecha}. Complétalo a tiempo.",
                '/mi-capacitacion',
            );
            $enviadas++;
        }

        $this->info("Avisos de cursos por vencer enviados: {$enviadas}.");

        return self::SUCCESS;
    }
}
