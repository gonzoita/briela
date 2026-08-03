<?php

namespace App\Console\Commands;

use App\Models\Configuracion;
use App\Models\Cotizacion;
use App\Models\Notificacion;
use App\Models\OpCuota;
use App\Models\Producto;
use App\Services\NotificacionService;
use Illuminate\Console\Command;

// Recordatorios diarios que reúnen tres avisos: cotizaciones sin respuesta
// (al vendedor), insumos bajo el stock mínimo (a compras) y cuotas de OP
// vencidas (financiero). Cada tipo respeta su switch de configuración y no
// se repite si ya se envió el mismo día.
class NotificarRecordatoriosDiarios extends Command
{
    protected $signature = 'notificaciones:recordatorios';

    protected $description = 'Recordatorios diarios: cotizaciones sin respuesta, stock bajo y saldos vencidos';

    public function handle(NotificacionService $notif): int
    {
        $this->cotizacionesSinRespuesta($notif);
        $this->stockBajo($notif);
        $this->saldosVencidos($notif);

        $this->info('Recordatorios diarios procesados.');
        return self::SUCCESS;
    }

    private function cotizacionesSinRespuesta(NotificacionService $notif): void
    {
        $umbral = (int) Configuracion::get('semaforo_dias_alerta', 5);

        Cotizacion::where('estado', 'enviada')
            ->whereNotNull('responsable_id')
            ->with('seguimientos')
            ->get()
            ->each(function ($cot) use ($notif, $umbral) {
                $dias = $cot->diasSinRespuesta();
                if ($dias === null || $dias < $umbral) return;

                $url = "/cotizaciones/{$cot->id}";
                if ($this->yaAvisado('cotizacion_sin_respuesta', $url)) return;

                $notif->crear(
                    $cot->responsable_id,
                    'cotizacion_sin_respuesta',
                    "Cotización {$cot->numero} sin respuesta",
                    "Lleva {$dias} días enviada sin respuesta del cliente. Haz seguimiento.",
                    $url,
                );
            });
    }

    private function stockBajo(NotificacionService $notif): void
    {
        // Un solo aviso resumen al día (a compras), para no saturar.
        if ($this->yaAvisado('stock_bajo', null)) return;

        $bajos = Producto::insumos()->where('activo', true)->with('stocks')
            ->get()
            ->filter(fn ($p) => $p->stockTotal() <= (float) $p->stock_minimo && (float) $p->stock_minimo > 0);

        if ($bajos->isEmpty()) return;

        $notif->paraRol(
            ['administrador', 'jefe_produccion'],
            'stock_bajo',
            'Insumos bajo el stock mínimo',
            $bajos->count() . ' insumo(s) están por debajo de su stock mínimo.',
            '/inventario/dashboard',
        );
    }

    private function saldosVencidos(NotificacionService $notif): void
    {
        if ($this->yaAvisado('saldo_vencido', null)) return;

        $vencidas = OpCuota::whereDate('fecha_vencimiento', '<', now()->toDateString())
            ->where('estado', '!=', 'pagado')
            ->count();

        if ($vencidas === 0) return;

        $notif->paraRol(
            ['administrador'],
            'saldo_vencido',
            'Cuotas vencidas por cobrar',
            "{$vencidas} cuota(s) de OP están vencidas y sin pagar.",
            '/financiero/cartera',
        );
    }

    // ¿Ya se envió un aviso de este tipo (y esta url, si aplica) en las
    // últimas 20 horas? Evita duplicar el recordatorio cada corrida.
    private function yaAvisado(string $tipo, ?string $url): bool
    {
        return Notificacion::where('tipo', $tipo)
            ->when($url, fn ($q) => $q->where('url', $url))
            ->where('created_at', '>=', now()->subHours(20))
            ->exists();
    }
}
