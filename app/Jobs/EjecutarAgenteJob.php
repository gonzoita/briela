<?php

namespace App\Jobs;

use App\Models\AgenteEjecucion;
use App\Services\Agentes\EjecutorAgente;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Una corrida de un agente, en cola.
 *
 * `tries = 1` a propósito: un reintento del job entero repetiría llamadas a la IA que ya se
 * pagaron. Los reintentos útiles (el modelo de respaldo) ocurren DENTRO del ejecutor.
 *
 * En un hosting compartido no hay un worker permanente: el cron de cada minuto lanza
 * `queue:work --stop-when-empty` (ver `routes/console.php`).
 */
class EjecutarAgenteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(public int $ejecucionId) {}

    public function handle(EjecutorAgente $ejecutor): void
    {
        $ejecucion = AgenteEjecucion::find($this->ejecucionId);

        if ($ejecucion) {
            $ejecutor->ejecutar($ejecucion);
        }
    }

    /** Si el proceso muere (tiempo agotado, memoria), la ejecución no se queda «corriendo» para siempre. */
    public function failed(?\Throwable $e): void
    {
        AgenteEjecucion::where('id', $this->ejecucionId)->whereIn('estado', AgenteEjecucion::EN_CURSO)->update([
            'estado'       => 'fallida',
            'error'        => 'La corrida se interrumpió: ' . ($e ? \Illuminate\Support\Str::limit($e->getMessage(), 300) : 'sin detalle') . '.',
            'terminada_at' => now(),
        ]);
    }
}
