<?php

namespace App\Services\Automatizaciones;

use App\Models\Automatizacion;
use App\Models\AutomatizacionEjecucion;
use App\Models\AutomatizacionPaso;
use App\Models\User;
use App\Services\NotificacionService;
use App\Support\Modulos;
use Illuminate\Support\Str;

/**
 * Recorre el grafo de una automatización, nodo por nodo.
 *
 * Pensado para un hosting compartido, donde no hay un proceso que espere: **una espera no deja
 * nada corriendo**. La corrida queda `esperando` con la hora en que debe seguir, y el cron la
 * retoma (`LanzadorAutomatizaciones::reanudar`). Al volver, los datos del documento se leen otra
 * vez de la base: una condición posterior a una espera debe mirar cómo está hoy el documento, no
 * cómo estaba cuando pasó el evento.
 *
 * La misma ruta de código sirve para la **prueba en seco** (`simular`): lo único que cambia es que
 * las acciones no se ejecutan y las esperas no esperan, así que la prueba muestra el camino real.
 */
class MotorAutomatizaciones
{
    /** Pasos máximos por corrida: un cinturón contra un grafo mal armado que se escape a la validación. */
    private const MAX_PASOS = 60;

    public function __construct(private readonly AccionesAutomatizacion $acciones) {}

    // ─── Corrida real ────────────────────────────────────────────────────────

    public function correr(AutomatizacionEjecucion $e): void
    {
        // Solo una corrida toma la ejecución: dos workers que coincidan no la duplican.
        $tomada = AutomatizacionEjecucion::where('id', $e->id)->whereIn('estado', ['en_cola', 'esperando'])
            ->update(['estado' => 'corriendo', 'iniciada_at' => $e->iniciada_at ?? now()]);

        if (! $tomada) {
            return;
        }

        $e->refresh();
        $automatizacion = $e->automatizacion;

        if (! Modulos::activo('automatizaciones')) {
            $this->terminar($e, 'cancelada', 'El módulo Automatizaciones está apagado.');

            return;
        }

        $autor = $automatizacion?->creado_por ? User::withoutGlobalScopes()->find($automatizacion->creado_por) : null;

        if (! $autor || ! $autor->activo) {
            $this->fallar($e, $automatizacion, 'Quien armó esta automatización ya no está activo en el sistema.');

            return;
        }

        app()->forgetInstance('briela.permisos.' . $autor->id);

        $contexto = $e->contexto ?? [];
        $nodo     = $e->nodo_actual ?? GrafoAutomatizacion::primero($e->grafo);

        // Al volver de una espera se vuelve a leer el documento.
        if ($e->nodo_actual !== null && $e->entidad_tipo) {
            $fresco = CatalogoAutomatizaciones::refrescar($e->entidad_tipo, $e->entidad_id);

            if ($fresco === null) {
                $this->terminar($e, 'terminada', 'El documento ya no existe; se detiene aquí.');

                return;
            }

            $contexto = $fresco;
        }

        // Lo que esta corrida provoque nace una generación más abajo (anti-bucles).
        $anterior = app()->bound('briela.automatizacion.profundidad') ? app('briela.automatizacion.profundidad') : null;
        app()->instance('briela.automatizacion.profundidad', $e->profundidad + 1);

        try {
            $orden = (int) $e->pasos()->max('orden');

            $resultado = $this->recorrer(
                $e->grafo, $contexto, $nodo, $autor, false,
                function (string $nodoId, string $tipo, string $estado, string $resumen, int $ms) use ($e, &$orden) {
                    AutomatizacionPaso::create([
                        'ejecucion_id' => $e->id, 'orden' => ++$orden, 'nodo_id' => $nodoId, 'tipo' => $tipo,
                        'estado' => $estado, 'resumen' => Str::limit($resumen, 500, ''), 'ms' => $ms, 'created_at' => now(),
                    ]);
                },
                function (string $siguiente, int $minutos) use ($e, $contexto) {
                    $e->update([
                        'estado' => 'esperando', 'nodo_actual' => $siguiente, 'reanudar_at' => now()->addMinutes($minutos),
                        'contexto' => $contexto,
                    ]);
                },
            );

            if ($resultado === 'terminada') {
                $this->terminar($e, 'terminada');
                $this->contarExito($automatizacion);
            }
        } catch (\RuntimeException $ex) {
            $this->fallar($e, $automatizacion, $ex->getMessage());
        } catch (\Throwable $ex) {
            report($ex);
            $this->fallar($e, $automatizacion, 'Error interno al correr la automatización. Quedó registrado en el log.');
        } finally {
            $anterior === null ? app()->forgetInstance('briela.automatizacion.profundidad') : app()->instance('briela.automatizacion.profundidad', $anterior);
        }
    }

    // ─── Prueba en seco ──────────────────────────────────────────────────────

    /**
     * El camino que seguiría la automatización con estos datos, **sin ejecutar nada**.
     *
     * @return array{pasos: list<array<string, mixed>>, termino: bool, error: ?string}
     */
    public function simular(array $grafo, array $contexto, User $autor): array
    {
        $pasos = [];
        $error = null;
        $n     = 0;

        try {
            $this->recorrer(
                $grafo, $contexto, GrafoAutomatizacion::primero($grafo), $autor, true,
                function (string $nodoId, string $tipo, string $estado, string $resumen, int $ms) use (&$pasos, &$n) {
                    $pasos[] = ['orden' => ++$n, 'nodo_id' => $nodoId, 'tipo' => $tipo, 'estado' => $estado, 'resumen' => $resumen];
                },
                null,
            );
        } catch (\RuntimeException $ex) {
            $error = $ex->getMessage();
        }

        return ['pasos' => $pasos, 'termino' => $error === null, 'error' => $error];
    }

    // ─── El recorrido, común a las dos ───────────────────────────────────────

    /**
     * @param  callable(string, string, string, string, int): void  $registrar  nodo, tipo, estado, resumen, ms
     * @param  (callable(string, int): void)|null  $esperar  siguiente nodo y minutos; null = simulación (no espera)
     * @return 'terminada'|'esperando'
     *
     * @throws \RuntimeException
     */
    private function recorrer(array $grafo, array $ctx, ?string $nodoId, User $autor, bool $simular, callable $registrar, ?callable $esperar): string
    {
        $unidades = CatalogoAutomatizaciones::unidadesDeEspera();

        for ($i = 0; $i < self::MAX_PASOS && $nodoId !== null; $i++) {
            $n = GrafoAutomatizacion::nodo($grafo, $nodoId);

            if (! $n) {
                break;
            }

            $t0  = microtime(true);
            $ms  = fn () => (int) ((microtime(true) - $t0) * 1000);
            $sig = null;

            switch ($n['tipo']) {
                case 'disparador':
                    $registrar($nodoId, 'disparador', 'ok', 'Arrancó.', $ms());
                    $sig = GrafoAutomatizacion::siguiente($grafo, $nodoId);
                    break;

                case 'condicion':
                    $cfg    = $n['config'] ?? [];
                    $cumple = EvaluadorCondiciones::cumple($cfg['reglas'] ?? [], $cfg['combinador'] ?? 'y', $ctx);
                    $registrar($nodoId, 'condicion', $cumple ? 'ok' : 'omitido', ($cumple ? 'Se cumple: ' : 'No se cumple: ') . $this->describirReglas($cfg), $ms());
                    $sig = GrafoAutomatizacion::siguiente($grafo, $nodoId, $cumple ? 'si' : 'no');
                    break;

                case 'accion':
                    try {
                        $resumen = $this->acciones->ejecutar((string) $n['accion'], $n['config'] ?? [], $ctx, $autor, $simular);
                    } catch (\RuntimeException $ex) {
                        $registrar($nodoId, 'accion', 'error', $ex->getMessage(), $ms());

                        throw $ex;
                    }
                    $registrar($nodoId, 'accion', 'ok', $resumen, $ms());
                    $sig = GrafoAutomatizacion::siguiente($grafo, $nodoId);
                    break;

                case 'espera':
                    $cfg     = $n['config'] ?? [];
                    $minutos = (int) $cfg['cantidad'] * $unidades[$cfg['unidad']];
                    $sig     = GrafoAutomatizacion::siguiente($grafo, $nodoId);
                    $texto   = "{$cfg['cantidad']} {$cfg['unidad']}";

                    if ($esperar === null || $sig === null) {
                        $registrar($nodoId, 'espera', 'espera', ($simular ? "Esperaría {$texto}" : "Esperó {$texto}") . ($sig === null ? ' (y no queda nada después).' : '.'), $ms());
                        break;
                    }

                    $registrar($nodoId, 'espera', 'espera', "Espera {$texto}; sigue después.", $ms());
                    $esperar($sig, $minutos);

                    return 'esperando';
            }

            $nodoId = $sig;
        }

        return 'terminada';
    }

    private function describirReglas(array $cfg): string
    {
        $ops = CatalogoAutomatizaciones::operadores();

        return collect($cfg['reglas'] ?? [])
            ->map(fn ($r) => ($r['campo'] ?? '?') . ' ' . ($ops[$r['operador'] ?? ''] ?? '?') . ' «' . ($r['valor'] ?? '') . '»')
            ->implode(($cfg['combinador'] ?? 'y') === 'o' ? ' o ' : ' y ');
    }

    // ─── Cierre ──────────────────────────────────────────────────────────────

    private function terminar(AutomatizacionEjecucion $e, string $estado, ?string $nota = null): void
    {
        $e->update(['estado' => $estado, 'terminada_at' => now(), 'nodo_actual' => null, 'reanudar_at' => null] + ($nota ? ['error' => $nota] : []));
    }

    private function contarExito(?Automatizacion $a): void
    {
        $a?->forceFill(['fallas_seguidas' => 0, 'ultima_ejecucion_at' => now()])->saveQuietly();
    }

    /**
     * Una corrida que falla queda registrada, y la automatización que falla cinco veces seguidas se
     * **pausa sola** y avisa a su autor: una regla rota que dispara en cada guardado no debe seguir
     * fallando en silencio.
     */
    private function fallar(AutomatizacionEjecucion $e, ?Automatizacion $a, string $motivo): void
    {
        $this->terminar($e, 'fallida', $motivo);

        if (! $a) {
            return;
        }

        $a->fallas_seguidas = (int) $a->fallas_seguidas + 1;
        $a->ultima_ejecucion_at = now();

        if ($a->fallas_seguidas >= 5 && $a->activa) {
            $a->activa = false;

            if ($a->creado_por) {
                app(NotificacionService::class)->crear(
                    $a->creado_por, 'automatizacion_fallo',
                    "«{$a->nombre}» se pausó: falló 5 veces seguidas",
                    Str::limit($motivo, 200), '/automatizaciones',
                );
            }
        }

        $a->save();
    }
}
