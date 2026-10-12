<?php

namespace App\Jobs;

use App\Models\AutomatizacionEjecucion;
use App\Services\Automatizaciones\MotorAutomatizaciones;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Una corrida de una automatización, en cola. `tries = 1`: un reintento del job repetiría acciones
 * que ya se hicieron (un aviso, una tarea).
 */
class EjecutarAutomatizacionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $ejecucionId)
    {
        // Un evento nace dentro del guardado de un documento: el job espera a que ese guardado se confirme.
        $this->afterCommit();
    }

    public function handle(MotorAutomatizaciones $motor): void
    {
        $e = AutomatizacionEjecucion::find($this->ejecucionId);

        if ($e) {
            $motor->correr($e);
        }
    }

    /** Si el proceso muere, la corrida no se queda «corriendo» para siempre. */
    public function failed(?\Throwable $ex): void
    {
        AutomatizacionEjecucion::where('id', $this->ejecucionId)->whereIn('estado', AutomatizacionEjecucion::EN_CURSO)->update([
            'estado'       => 'fallida',
            'error'        => 'La corrida se interrumpió: ' . ($ex ? \Illuminate\Support\Str::limit($ex->getMessage(), 300) : 'sin detalle') . '.',
            'terminada_at' => now(),
        ]);
    }
}
