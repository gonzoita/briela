<?php

namespace App\Console\Commands;

use App\Services\CostosEnMonedaService;
use App\Services\TasaCambioService;
use Illuminate\Console\Command;

/**
 * Trae la tasa de cambio del día y hace lo que sigue solo después de ella.
 *
 * Un solo comando y no tres a propósito: recalcular los costos antes de tener la tasa
 * nueva sería recalcular con la de ayer. El orden es la regla.
 *
 *  1. Las tasas de hoy (TRM y euro).
 *  2. El costo en pesos, y sus precios, de lo que se compra en otra moneda.
 *  3. La tasa de las cotizaciones abiertas, si la empresa eligió que sigan a la TRM.
 */
class ActualizarTasas extends Command
{
    protected $signature = 'tasas:actualizar';

    protected $description = 'Trae la TRM y el euro del día, y recalcula costos y cotizaciones que dependen de ellos';

    public function handle(TasaCambioService $tasas, CostosEnMonedaService $costos): int
    {
        foreach ($tasas->actualizar() as $moneda => $r) {
            $r['ok']
                ? $this->info("{$moneda}: $" . number_format((float) $r['valor'], 2, ',', '.') . " ({$r['fuente']}). {$r['mensaje']}")
                : $this->warn("{$moneda}: {$r['mensaje']} Se sigue usando la última tasa guardada.");
        }

        $productos = $costos->actualizarProductos();
        $this->line("Productos con costo en otra moneda recalculados: {$productos}.");

        $cotizaciones = $costos->actualizarCotizacionesAbiertas();
        $this->line("Cotizaciones abiertas con la tasa de hoy: {$cotizaciones}.");

        return self::SUCCESS;
    }
}
