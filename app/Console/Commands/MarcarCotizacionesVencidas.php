<?php

namespace App\Console\Commands;

use App\Models\Cotizacion;
use Illuminate\Console\Command;

// Antes, cuando una cotización enviada pasaba su fecha_validez sin
// respuesta del cliente, se quedaba mostrando "Enviada" para siempre —
// nadie entraba a cambiarla a mano. Este comando corre todos los días
// (ver routes/console.php) y pasa a "Vencida" cualquier cotización que
// siga en "Enviada" con la fecha de validez ya pasada. Solo aplica a
// "Enviada": un borrador nunca se venció porque nunca se mandó, y una
// aprobada/rechazada ya tiene una decisión tomada que no se debe pisar.
class MarcarCotizacionesVencidas extends Command
{
    protected $signature = 'cotizaciones:marcar-vencidas';

    protected $description = 'Pasa a estado "vencida" las cotizaciones enviadas cuya fecha de validez ya pasó';

    public function handle(): int
    {
        $vencidas = Cotizacion::where('estado', 'enviada')
            ->whereDate('fecha_validez', '<', now()->toDateString())
            ->get();

        if ($vencidas->isEmpty()) {
            $this->info('No hay cotizaciones para vencer hoy.');
            return self::SUCCESS;
        }

        foreach ($vencidas as $cot) {
            $cot->update(['estado' => 'vencida']);
        }

        $this->info("{$vencidas->count()} cotización(es) marcada(s) como vencida(s): " . $vencidas->pluck('numero')->implode(', '));

        return self::SUCCESS;
    }
}
