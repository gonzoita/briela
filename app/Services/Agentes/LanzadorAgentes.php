<?php

namespace App\Services\Agentes;

use App\Jobs\EjecutarAgenteJob;
use App\Models\AgenteEjecucion;
use App\Models\AgenteInterno;
use App\Models\Configuracion;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * El único sitio que crea una corrida y la pone en cola.
 *
 * Lo usan el cron (`agentes:despachar`), el botón «Ejecutar ahora» y la prueba en seco. Una sola
 * puerta evita que cada uno se invente su manera de crear ejecuciones.
 */
class LanzadorAgentes
{
    /**
     * El interruptor de emergencia: con la pausa general puesta, ningún agente arranca solo ni
     * por botón. Las pruebas en seco siguen funcionando: no escriben nada.
     */
    public static function pausado(): bool
    {
        return Configuracion::get('equipo_ia_pausado', '0') === '1';
    }

    /**
     * Una corrida a pedido de una persona. Si el agente ya tiene una en curso, no crea otra: dos
     * corridas a la vez del mismo agente se pisarían las propuestas y duplicarían el gasto.
     */
    public function manual(AgenteInterno $agente, ?User $quien, ?string $tarea = null, bool $prueba = false): AgenteEjecucion
    {
        $enCurso = $agente->ejecuciones()->enCurso()->latest('id')->first();

        if ($enCurso) {
            return $enCurso;
        }

        $e = AgenteEjecucion::create([
            'agente_id'     => $agente->id,
            'estado'        => 'en_cola',
            'origen'        => $prueba ? 'prueba' : 'manual',
            'prueba'        => $prueba,
            'disparado_por' => $quien?->id,
            'tarea'         => $tarea ? trim($tarea) : null,
        ]);

        EjecutarAgenteJob::dispatch($e->id);

        return $e;
    }

    /**
     * Los agentes activos a los que ya les toca. La clave de idempotencia es la ventana
     * («agente-3:2026-10-13»): dos pasadas del cron dentro de la misma ventana chocan contra el
     * índice único y la segunda se descarta sola.
     *
     * @return int cuántas corridas se crearon
     */
    public function programadas(CarbonInterface $ahora): int
    {
        if (self::pausado()) {
            return 0;
        }

        $creadas = 0;

        foreach (AgenteInterno::where('activo', true)->get() as $agente) {
            $clave = $agente->ventanaActual($ahora);

            if ($clave === null || $agente->ejecuciones()->enCurso()->exists()) {
                continue;
            }

            try {
                $e = AgenteEjecucion::create([
                    'agente_id'          => $agente->id,
                    'estado'             => 'en_cola',
                    'origen'             => 'cron',
                    'clave_idempotencia' => $clave,
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                continue;
            }

            EjecutarAgenteJob::dispatch($e->id);
            $creadas++;
        }

        return $creadas;
    }

    /**
     * Las corridas que quedaron «en curso» porque el proceso murió sin avisar. En un hosting
     * compartido pasa: PHP se corta por tiempo o memoria y nadie escribe el estado final.
     */
    public function rescatarAtascadas(int $minutos = 15): int
    {
        return AgenteEjecucion::whereIn('estado', AgenteEjecucion::EN_CURSO)
            ->where('updated_at', '<', now()->subMinutes($minutos))
            ->update([
                'estado'       => 'fallida',
                'error'        => "La corrida llevaba más de {$minutos} minutos sin avanzar y se dio por perdida (el proceso se interrumpió).",
                'terminada_at' => now(),
            ]);
    }
}
