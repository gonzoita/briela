<?php

namespace App\Services\Automatizaciones;

use App\Jobs\EjecutarAutomatizacionJob;
use App\Models\Automatizacion;
use App\Models\AutomatizacionEjecucion;
use App\Models\User;
use App\Services\NotificacionService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * El único sitio que crea una corrida de una automatización y la pone en cola.
 *
 * Lo usan los eventos, el cron (horarios y esperas), el botón «Ejecutar ahora» y la prueba. Una sola
 * puerta, para que los frenos —límite por hora, anti-bucles, idempotencia— estén en un solo lugar.
 */
class LanzadorAutomatizaciones
{
    /** Cuántas automatizaciones se pueden encadenar (una provoca un evento que dispara otra…). */
    public const PROFUNDIDAD_MAXIMA = 3;

    /** @param array<string, mixed> $contexto */
    public function desdeEvento(Automatizacion $a, string $evento, array $contexto, int $profundidad): ?AutomatizacionEjecucion
    {
        // Mismo documento, mismo cambio, mismo minuto = una sola corrida: un guardado que dispara dos
        // veces el evento (observadores encadenados) no duplica los avisos.
        $huella = md5(json_encode([$contexto['estado'] ?? null, $contexto['etapa'] ?? null]));
        $clave  = "ev:{$a->id}:{$evento}:" . ($contexto['_id'] ?? 0) . ":{$huella}:" . now()->format('Y-m-d-H-i');

        return $this->crear($a, 'evento', $contexto, $evento, null, $profundidad, $clave);
    }

    public function manual(Automatizacion $a, ?User $quien): ?AutomatizacionEjecucion
    {
        return $this->crear($a, 'manual', [], null, $quien, 0, null);
    }

    /**
     * Las automatizaciones por horario a las que ya les toca. La ventana («auto-4:2026-10-14») es la
     * clave de idempotencia: dos pasadas del cron dentro de la misma ventana chocan contra el índice
     * único y la segunda se descarta sola.
     */
    public function programadas(CarbonInterface $ahora): int
    {
        $creadas = 0;

        foreach (Automatizacion::where('activa', true)->where('disparador_tipo', 'horario')->get() as $a) {
            $f = $a->disparador()['config']['frecuencia'] ?? [];

            $ventana = \App\Support\VentanaHoraria::actual($f, $ahora);

            if ($ventana !== null && $this->crear($a, 'horario', [], null, null, 0, "auto-{$a->id}:{$ventana}")) {
                $creadas++;
            }
        }

        return $creadas;
    }

    /** Las corridas que esperaban y ya les llegó la hora. */
    public function reanudar(): int
    {
        $n = 0;

        AutomatizacionEjecucion::where('estado', 'esperando')->where('reanudar_at', '<=', now())->orderBy('reanudar_at')->limit(100)->get()
            ->each(function (AutomatizacionEjecucion $e) use (&$n) {
                EjecutarAutomatizacionJob::dispatch($e->id);
                $n++;
            });

        return $n;
    }

    /** Las corridas «en curso» cuyo proceso murió sin avisar (en un hosting compartido pasa). */
    public function rescatarAtascadas(int $minutos = 15): int
    {
        return AutomatizacionEjecucion::where('estado', 'corriendo')->where('updated_at', '<', now()->subMinutes($minutos))
            ->update([
                'estado'       => 'fallida',
                'error'        => "La corrida llevaba más de {$minutos} minutos sin avanzar y se dio por perdida (el proceso se interrumpió).",
                'terminada_at' => now(),
            ]);
    }

    /** @param array<string, mixed> $contexto */
    private function crear(Automatizacion $a, string $origen, array $contexto, ?string $evento, ?User $quien, int $profundidad, ?string $clave): ?AutomatizacionEjecucion
    {
        // Un freno por automatización: una regla mal armada que dispara en cada guardado no llena la
        // base ni la cola. Se avisa UNA vez por hora a su autor, no una por cada corrida descartada.
        $limite   = (int) ($a->max_por_hora ?: 60);
        $enLaHora = $a->ejecuciones()->where('created_at', '>=', now()->subHour())->count();

        if ($enLaHora >= $limite) {
            if ($a->creado_por && Cache::add("automatizacion.limite.{$a->id}", 1, now()->addHour())) {
                app(NotificacionService::class)->crear(
                    $a->creado_por, 'automatizacion_fallo', "«{$a->nombre}» llegó a su límite por hora",
                    "Se descartaron corridas: el máximo es {$limite} por hora. Revisa que no esté dando vueltas.", '/automatizaciones',
                );
            }

            return null;
        }

        try {
            $e = AutomatizacionEjecucion::create([
                'automatizacion_id'  => $a->id,
                'estado'             => 'en_cola',
                'origen'             => $origen,
                'evento'             => $evento,
                'grafo'              => $a->grafo,
                'contexto'           => $contexto ?: null,
                'entidad_tipo'       => $contexto['_entidad'] ?? null,
                'entidad_id'         => $contexto['_id'] ?? null,
                'profundidad'        => $profundidad,
                'disparado_por'      => $quien?->id,
                'clave_idempotencia' => $clave,
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return null;
        }

        EjecutarAutomatizacionJob::dispatch($e->id);

        return $e;
    }
}
