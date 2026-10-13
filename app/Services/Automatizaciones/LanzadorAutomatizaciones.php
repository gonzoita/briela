<?php

namespace App\Services\Automatizaciones;

use App\Jobs\EjecutarAutomatizacionJob;
use App\Models\Automatizacion;
use App\Models\AutomatizacionAprobacion;
use App\Models\AutomatizacionEjecucion;
use App\Models\User;
use App\Services\NotificacionService;
use App\Support\VentanaHoraria;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        // Mismo documento, mismos datos, mismo minuto = una sola corrida: un guardado que dispara dos
        // veces el evento (observadores encadenados) no duplica los avisos. Si los datos cambiaron
        // (el stock volvió a bajar, llegó otro mensaje), es otro hecho y sí corre.
        // Lo «anterior» no cuenta: es lo que el evento sabe, no un dato del documento.
        $huella = md5(json_encode(array_filter(EvaluadorCondiciones::publicos($contexto), fn ($k) => ! str_ends_with($k, '_anterior'), ARRAY_FILTER_USE_KEY)));
        $clave  = "ev:{$a->id}:{$evento}:" . ($contexto['_entidad'] ?? '') . ':' . ($contexto['_id'] ?? 0) . ":{$huella}:" . now()->format('Y-m-d-H-i');

        return $this->crear($a, 'evento', $contexto, $evento, null, $profundidad, $clave);
    }

    public function manual(Automatizacion $a, ?User $quien): ?AutomatizacionEjecucion
    {
        return $this->crear($a, 'manual', [], null, $quien, 0, null);
    }

    /**
     * Las automatizaciones por horario a las que ya les toca. La ventana («2026-10-14») es la clave de
     * idempotencia: dos pasadas del cron dentro de la misma ventana chocan contra el índice único y la
     * segunda se descarta sola. Además la ventana atendida se anota en la automatización, para que un
     * barrido no vuelva a recorrer la base cada minuto durante las tres horas de su ventana.
     */
    public function programadas(CarbonInterface $ahora): int
    {
        $creadas = 0;

        foreach (Automatizacion::where('activa', true)->where('disparador_tipo', 'horario')->get() as $a) {
            $cfg     = $a->disparador()['config'] ?? [];
            $ventana = VentanaHoraria::actual($cfg['frecuencia'] ?? [], $ahora);

            if ($ventana === null || $a->ultima_ventana === $ventana) {
                continue;
            }

            $a->forceFill(['ultima_ventana' => $ventana])->saveQuietly();

            try {
                $creadas += filled($cfg['para_cada'] ?? null)
                    ? $this->barrer($a, (string) $cfg['para_cada'], $ventana)
                    : ($this->crear($a, 'horario', [], null, null, 0, "auto-{$a->id}:{$ventana}") ? 1 : 0);
            } catch (\Throwable $e) {
                // Una automatización rota no frena a las demás del mismo minuto.
                report($e);
            }
        }

        return $creadas;
    }

    /** Cuántos documentos mira un barrido como máximo: más que eso es una consulta, no una revisión. */
    public const MAX_DOCUMENTOS_BARRIDO = 2000;

    /**
     * «Cada mañana, para cada cotización enviada que lleve 5 días sin respuesta…». Recorre los
     * documentos vivos de la entidad y crea una corrida por cada uno que pase las condiciones del
     * principio. Esas condiciones se evalúan AQUÍ, antes de crear nada: sin eso, un barrido sobre mil
     * cotizaciones dejaría mil corridas que se detienen en el primer paso.
     */
    public function barrer(Automatizacion $a, string $entidad, string $ventana): int
    {
        $consulta = EntidadesAutomatizacion::disponible($entidad) ? EntidadesAutomatizacion::consultaBarrido($entidad) : null;

        if ($consulta === null) {
            return 0;
        }

        $filtros = self::condicionesIniciales($a->grafo);
        $limite  = (int) ($a->max_por_hora ?: 60);
        $creadas = 0;
        $vistos  = 0;
        $globales = CatalogoAutomatizaciones::globales();

        foreach ($consulta->lazyById(200) as $doc) {
            if (++$vistos > self::MAX_DOCUMENTOS_BARRIDO || $creadas >= $limite) {
                break;
            }

            $ctx = EntidadesAutomatizacion::contexto($doc);

            if ($ctx === null || ! collect($filtros)->every(fn ($c) => EvaluadorCondiciones::cumple($c['reglas'] ?? [], $c['combinador'] ?? 'y', $ctx + $globales))) {
                continue;
            }

            if ($this->crear($a, 'horario', $ctx, null, null, 0, "barr-{$a->id}:{$ventana}:{$ctx['_entidad']}:{$ctx['_id']}")) {
                $creadas++;
            }
        }

        return $creadas;
    }

    /**
     * Las condiciones que van justo después del disparador, una tras otra, mientras no tengan rama
     * «no»: esas deciden si la corrida tiene sentido, y se pueden mirar antes de crearla.
     *
     * @return list<array<string, mixed>>
     */
    public static function condicionesIniciales(array $grafo): array
    {
        $out  = [];
        $nodo = GrafoAutomatizacion::siguiente($grafo, (string) GrafoAutomatizacion::primero($grafo));

        while ($nodo !== null && count($out) < 10) {
            $n = GrafoAutomatizacion::nodo($grafo, $nodo);

            if (($n['tipo'] ?? null) !== 'condicion') {
                break;
            }

            $tieneNo = collect($grafo['aristas'] ?? [])->contains(fn ($ar) => ($ar['de'] ?? null) === $nodo && ($ar['rama'] ?? null) === 'no');

            if ($tieneNo) {
                break;
            }

            $out[] = $n['config'] ?? [];
            $nodo  = GrafoAutomatizacion::siguiente($grafo, $nodo, 'si');
        }

        return $out;
    }

    /** Cancela lo que una automatización tiene en curso (al pausarla, borrarla o desde el historial). */
    public static function cancelarEnCurso(Builder|HasMany $ejecuciones, string $motivo): int
    {
        $ids = (clone $ejecuciones)->whereIn('estado', AutomatizacionEjecucion::EN_CURSO)->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        AutomatizacionAprobacion::whereIn('ejecucion_id', $ids)->where('estado', 'pendiente')->update(['estado' => 'cancelada']);

        return AutomatizacionEjecucion::whereIn('id', $ids)->whereIn('estado', AutomatizacionEjecucion::EN_CURSO)
            ->update(['estado' => 'cancelada', 'error' => $motivo, 'terminada_at' => now(), 'reanudar_at' => null]);
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

        // «No repetir con el mismo documento en N días»: la cotización que se reenvía tres veces no
        // tiene por qué recibir tres seguimientos.
        $noRepetir = (int) ($a->disparador()['config']['no_repetir_dias'] ?? 0);

        if ($noRepetir > 0 && isset($contexto['_entidad'], $contexto['_id'])
            && $a->ejecuciones()->where('entidad_tipo', $contexto['_entidad'])->where('entidad_id', $contexto['_id'])
                ->where('created_at', '>=', now()->subDays($noRepetir))->where('estado', '!=', 'cancelada')->exists()) {
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
