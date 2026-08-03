<?php

namespace App\Console\Commands;

use App\Models\Notificacion;
use App\Models\Op;
use App\Services\NotificacionService;
use Illuminate\Console\Command;

// Aviso diario: OPs cuya fecha de entrega estimada está cerca (dentro de los
// próximos N días) y que todavía no se despacharon. Le llega a producción
// para que priorice. No repite el aviso de una misma OP si ya se envió en
// las últimas 20 horas (evita spam diario).
class NotificarEntregasProximas extends Command
{
    protected $signature = 'notificaciones:entregas-proximas {--dias=3}';

    protected $description = 'Avisa a producción de las OPs con entrega próxima que aún no se han despachado';

    public function handle(NotificacionService $notif): int
    {
        $dias  = (int) $this->option('dias');
        $hasta = now()->addDays($dias)->toDateString();

        $ops = Op::whereNotNull('fecha_entrega_estimada')
            ->whereDate('fecha_entrega_estimada', '<=', $hasta)
            ->whereNotIn('estado', ['despachada'])
            ->get();

        $enviadas = 0;

        foreach ($ops as $op) {
            $url = "/produccion/ops/{$op->id}";

            // ¿Ya avisamos de esta OP en las últimas 20 horas?
            $yaAvisado = Notificacion::where('tipo', 'entrega_proxima')
                ->where('url', $url)
                ->where('created_at', '>=', now()->subHours(20))
                ->exists();
            if ($yaAvisado) continue;

            $fecha   = $op->fecha_entrega_estimada->format('d/m/Y');
            $avance  = (float) ($op->porcentaje_avance ?? 0);

            $notif->paraRol(
                ['administrador', 'jefe_produccion'],
                'entrega_proxima',
                "Entrega próxima — OP {$op->numero}",
                "Vence el {$fecha}. Avance: {$avance}%.",
                $url,
            );
            $enviadas++;
        }

        $this->info("Avisos de entrega próxima enviados para {$enviadas} OP(s).");

        return self::SUCCESS;
    }
}
