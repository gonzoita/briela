<?php

namespace App\Services\Automatizaciones;

use App\Models\Automatizacion;
use App\Models\AutomatizacionAprobacion;
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
 * cómo estaba cuando pasó el evento. Lo que los pasos produjeron (el texto de la IA) sí se guarda,
 * en `variables`, porque eso no se puede volver a leer.
 *
 * Un paso de **aprobación** es una espera que termina antes si alguien decide: la corrida queda
 * parada en ese paso, se avisa a quienes deciden, y sigue por «sí» o por «no» (o se detiene). Si
 * vence sin respuesta, cuenta como «no»: lo que nadie aprobó no se hace.
 *
 * La misma ruta de código sirve para la **prueba en seco** (`simular`): lo único que cambia es que
 * las acciones no se ejecutan, las esperas no esperan y las aprobaciones se dan por aprobadas, así
 * que la prueba muestra el camino real.
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
            ->where(fn ($q) => $q->where('estado', 'en_cola')->orWhere('reanudar_at', '<=', now()))
            ->update(['estado' => 'corriendo', 'iniciada_at' => $e->iniciada_at ?? now(), 'updated_at' => now()]);

        if (! $tomada) {
            return;
        }

        $e->refresh();
        $automatizacion = $e->automatizacion;

        if (! Modulos::activo('automatizaciones')) {
            $this->terminar($e, 'cancelada', 'El módulo Automatizaciones está apagado.');

            return;
        }

        // Una corrida que quedó en cola justo antes de pausar o borrar la automatización no se ejecuta.
        if (! $automatizacion || $automatizacion->trashed() || ! $automatizacion->activa) {
            $this->terminar($e, 'cancelada', 'La automatización está pausada o se eliminó.');

            return;
        }

        $autor = $automatizacion->creado_por ? User::withoutGlobalScopes()->find($automatizacion->creado_por) : null;

        if (! $autor || ! $autor->activo) {
            $this->fallar($e, $automatizacion, 'Quien armó esta automatización ya no está activo en el sistema.');

            return;
        }

        app()->forgetInstance('briela.permisos.' . $autor->id);

        // El autor tiene que poder ver el documento HOY, no solo cuando la armó.
        $tipo = $e->contexto['_tipo'] ?? null;
        if ($tipo && ($def = EntidadesAutomatizacion::def($tipo)) && ! $autor->tienePermiso($def['permiso'])) {
            $this->fallar($e, $automatizacion, "Quien armó esta automatización ya no puede ver «{$def['plural']}» ({$def['permiso']}).");

            return;
        }

        $contexto = $e->contexto ?? [];
        $nodo     = $e->nodo_actual ?? GrafoAutomatizacion::primero($e->grafo);
        $decision = null;

        // Al volver de una espera (o de una aprobación) se vuelve a leer el documento.
        if ($e->nodo_actual !== null && $e->entidad_tipo) {
            $fresco = CatalogoAutomatizaciones::refrescar($e->entidad_tipo, $e->entidad_id);

            if ($fresco === null) {
                $this->terminar($e, 'terminada', 'El documento ya no existe; se detiene aquí.');

                return;
            }

            $contexto = $fresco;
        }

        // ¿Vuelve de una aprobación? Si nadie decidió y ya venció, cuenta como «no».
        if ($e->nodo_actual !== null && (GrafoAutomatizacion::nodo($e->grafo, $e->nodo_actual)['tipo'] ?? null) === 'aprobacion') {
            $ap = AutomatizacionAprobacion::where('ejecucion_id', $e->id)->where('nodo_id', $e->nodo_actual)->first();

            if ($ap && $ap->estado === 'pendiente') {
                if ($ap->vence_at->isFuture()) {
                    // Alguien la despertó antes de tiempo: vuelve a esperar.
                    $e->update(['estado' => 'esperando', 'reanudar_at' => $ap->vence_at]);

                    return;
                }

                $ap->update(['estado' => 'vencida']);
            }

            $decision = ['nodo' => $e->nodo_actual, 'estado' => $ap?->estado ?? 'vencida', 'por' => $ap?->decididaPor?->name, 'nota' => $ap?->nota];
        }

        $variables = $e->variables ?? [];
        $contexto  = array_merge($contexto, CatalogoAutomatizaciones::globales(), $variables);

        // Lo que esta corrida provoque nace una generación más abajo (anti-bucles).
        $anterior = app()->bound('briela.automatizacion.profundidad') ? app('briela.automatizacion.profundidad') : null;
        app()->instance('briela.automatizacion.profundidad', $e->profundidad + 1);

        try {
            $orden = (int) $e->pasos()->max('orden');

            $resultado = $this->recorrer(
                $e->grafo, $contexto, $nodo, $autor, false, (string) $automatizacion?->nombre,
                function (string $nodoId, string $tipo, string $estado, string $resumen, int $ms) use ($e, &$orden) {
                    AutomatizacionPaso::create([
                        'ejecucion_id' => $e->id, 'orden' => ++$orden, 'nodo_id' => $nodoId, 'tipo' => $tipo,
                        'estado' => $estado, 'resumen' => Str::limit($resumen, 500, ''), 'ms' => $ms, 'created_at' => now(),
                    ]);
                },
                function (string $siguiente, int $minutos, array $vars) use ($e, $contexto) {
                    $e->update([
                        'estado' => 'esperando', 'nodo_actual' => $siguiente, 'reanudar_at' => now()->addMinutes($minutos),
                        'contexto' => $this->sinGlobales($contexto), 'variables' => $vars ?: null,
                    ]);
                },
                fn (string $nodoId, array $cfg, array $ctx, array $vars) => $this->pedirAprobacion($e, $automatizacion, $autor, $nodoId, $cfg, $ctx, $vars),
                $decision,
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

    private function sinGlobales(array $ctx): array
    {
        return array_diff_key($ctx, CatalogoAutomatizaciones::globales(), CatalogoAutomatizaciones::camposGlobales());
    }

    /**
     * Deja la corrida esperando una decisión y avisa a quienes deciden.
     *
     * @throws \RuntimeException
     */
    private function pedirAprobacion(AutomatizacionEjecucion $e, ?Automatizacion $a, User $autor, string $nodoId, array $cfg, array $ctx, array $vars): void
    {
        $ids = AccionesAutomatizacion::destinatarios($cfg, $ctx, $autor);

        if ($ids === []) {
            throw new \RuntimeException('No hay a quién pedirle la aprobación (el documento no tiene responsable o las personas ya no están activas).');
        }

        $horas    = max(1, min(GrafoAutomatizacion::MAX_APROBACION_HORAS, (int) ($cfg['vence_horas'] ?? 48)));
        $pregunta = mb_substr(EvaluadorCondiciones::rellenar((string) $cfg['pregunta'], $ctx), 0, 500);

        $ap = AutomatizacionAprobacion::updateOrCreate(
            ['ejecucion_id' => $e->id, 'nodo_id' => $nodoId],
            ['pregunta' => $pregunta, 'destinatarios' => $ids, 'estado' => 'pendiente', 'decidida_por' => null, 'decidida_at' => null, 'nota' => null, 'vence_at' => now()->addHours($horas)],
        );

        $e->update([
            'estado' => 'esperando', 'nodo_actual' => $nodoId, 'reanudar_at' => $ap->vence_at,
            'contexto' => $this->sinGlobales($ctx), 'variables' => $vars ?: null,
        ]);

        $servicio = app(NotificacionService::class);

        foreach ($ids as $id) {
            $servicio->crear($id, 'automatizacion_aprobacion', 'Te piden una aprobación: ' . mb_strimwidth($pregunta, 0, 120, '…'),
                '«' . ($a?->nombre ?? 'Automatización') . "» espera tu respuesta. Vence en {$horas} h.", "/automatizaciones/aprobar/{$ap->id}");
        }
    }

    // ─── Prueba en seco ──────────────────────────────────────────────────────

    /**
     * El camino que seguiría la automatización con estos datos, **sin ejecutar nada**.
     *
     * @return array{pasos: list<array<string, mixed>>, termino: bool, error: ?string}
     */
    public function simular(array $grafo, array $contexto, User $autor, string $nombre = 'Prueba'): array
    {
        $pasos = [];
        $error = null;
        $n     = 0;

        try {
            $this->recorrer(
                $grafo, array_merge(CatalogoAutomatizaciones::globales(), $contexto), GrafoAutomatizacion::primero($grafo), $autor, true, $nombre,
                function (string $nodoId, string $tipo, string $estado, string $resumen, int $ms) use (&$pasos, &$n) {
                    $pasos[] = ['orden' => ++$n, 'nodo_id' => $nodoId, 'tipo' => $tipo, 'estado' => $estado, 'resumen' => $resumen];
                },
                null,
                null,
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
     * @param  (callable(string, int, array): void)|null  $esperar  siguiente nodo, minutos y variables; null = simulación
     * @param  (callable(string, array, array, array): void)|null  $pedir  nodo, config, contexto y variables; null = simulación
     * @param  array{nodo: string, estado: string, por: ?string, nota: ?string}|null  $decision  la respuesta a la aprobación de la que se vuelve
     * @return 'terminada'|'esperando'
     *
     * @throws \RuntimeException
     */
    private function recorrer(array $grafo, array $ctx, ?string $nodoId, User $autor, bool $simular, string $nombre,
        callable $registrar, ?callable $esperar, ?callable $pedir, ?array $decision): string
    {
        $unidades  = CatalogoAutomatizaciones::unidadesDeEspera();
        $campos    = CatalogoAutomatizaciones::camposDe($ctx['_tipo'] ?? GrafoAutomatizacion::entidadDe($grafo));
        $variables = array_intersect_key($ctx, array_filter(CatalogoAutomatizaciones::camposGlobales(), fn ($c) => ! empty($c['variable'])));

        for ($i = 0; $i < self::MAX_PASOS && $nodoId !== null; $i++) {
            $n = GrafoAutomatizacion::nodo($grafo, $nodoId);

            if (! $n) {
                break;
            }

            $t0  = microtime(true);
            $ms  = fn () => (int) ((microtime(true) - $t0) * 1000);
            $sig = null;
            $cfg = $n['config'] ?? [];

            switch ($n['tipo']) {
                case 'disparador':
                    $doc = isset($ctx['numero']) ? " ({$ctx['numero']})" : (isset($ctx['titulo']) ? " ({$ctx['titulo']})" : (isset($ctx['nombre']) ? " ({$ctx['nombre']})" : ''));
                    $registrar($nodoId, 'disparador', 'ok', 'Arrancó' . $doc . '.', $ms());
                    $sig = GrafoAutomatizacion::siguiente($grafo, $nodoId);
                    break;

                case 'condicion':
                    $cumple = EvaluadorCondiciones::cumple($cfg['reglas'] ?? [], $cfg['combinador'] ?? 'y', $ctx);
                    $sig    = GrafoAutomatizacion::siguiente($grafo, $nodoId, $cumple ? 'si' : 'no');
                    $registrar($nodoId, 'condicion', $cumple ? 'ok' : 'omitido',
                        ($cumple ? 'Se cumple: ' : 'No se cumple: ') . $this->describirReglas($cfg, $campos, $ctx) . ($sig === null && ! $cumple ? ' Se detiene aquí.' : ''), $ms());
                    break;

                case 'accion':
                    try {
                        $r = $this->acciones->ejecutar((string) $n['accion'], $cfg, $ctx, $autor, $simular, $nombre);
                    } catch (\RuntimeException $ex) {
                        $registrar($nodoId, 'accion', 'error', $ex->getMessage(), $ms());

                        throw $ex;
                    }

                    $ctx       = array_merge($ctx, $r['variables']);
                    $variables = array_merge($variables, $r['variables']);
                    $registrar($nodoId, 'accion', 'ok', $r['resumen'], $ms());
                    $sig = GrafoAutomatizacion::siguiente($grafo, $nodoId);
                    break;

                case 'espera':
                    $minutos = (int) $cfg['cantidad'] * $unidades[$cfg['unidad']];
                    $sig     = GrafoAutomatizacion::siguiente($grafo, $nodoId);
                    $texto   = "{$cfg['cantidad']} {$cfg['unidad']}";

                    if ($esperar === null || $sig === null) {
                        $registrar($nodoId, 'espera', 'espera', ($simular ? "Esperaría {$texto}" : "Esperó {$texto}") . ($sig === null ? ' (y no queda nada después).' : '.'), $ms());
                        break;
                    }

                    $registrar($nodoId, 'espera', 'espera', "Espera {$texto}; sigue después.", $ms());
                    $esperar($sig, $minutos, $variables);

                    return 'esperando';

                case 'aprobacion':
                    if ($decision !== null && $decision['nodo'] === $nodoId) {
                        $aprobada = $decision['estado'] === 'aprobada';
                        $sig      = GrafoAutomatizacion::siguiente($grafo, $nodoId, $aprobada ? 'si' : 'no');
                        $quien    = $decision['por'] ? " por {$decision['por']}" : '';
                        $texto    = match ($decision['estado']) {
                            'aprobada'  => "Aprobada{$quien}.",
                            'rechazada' => "Rechazada{$quien}.",
                            'cancelada' => 'Se canceló la aprobación.',
                            default     => 'Venció sin respuesta: cuenta como «no».',
                        };
                        $registrar($nodoId, 'aprobacion', $aprobada ? 'ok' : 'omitido',
                            $texto . ($decision['nota'] ? " Nota: «{$decision['nota']}»." : '') . (! $aprobada && $sig === null ? ' Se detiene aquí.' : ''), $ms());
                        $decision = null;
                        break;
                    }

                    if ($pedir === null) {
                        $ids = AccionesAutomatizacion::destinatarios($cfg, $ctx, $autor);
                        $registrar($nodoId, 'aprobacion', 'espera', 'Pediría aprobación a ' . count($ids) . (count($ids) === 1 ? ' persona' : ' personas')
                            . ': ' . EvaluadorCondiciones::rellenar((string) ($cfg['pregunta'] ?? ''), $ctx) . ' En la prueba se da por aprobada.', $ms());
                        $sig = GrafoAutomatizacion::siguiente($grafo, $nodoId, 'si');
                        break;
                    }

                    $pedir($nodoId, $cfg, $ctx, $variables);
                    $registrar($nodoId, 'aprobacion', 'espera', 'Pidió aprobación: ' . EvaluadorCondiciones::rellenar((string) ($cfg['pregunta'] ?? ''), $ctx), $ms());

                    return 'esperando';
            }

            $nodoId = $sig;
        }

        return 'terminada';
    }

    private function describirReglas(array $cfg, array $campos, array $ctx): string
    {
        $ops = CatalogoAutomatizaciones::operadores();

        return collect($cfg['reglas'] ?? [])
            ->map(function ($r) use ($ops, $campos, $ctx) {
                $campo = $r['campo'] ?? '?';
                $label = $campos[$campo]['label'] ?? $campo;
                $op    = $r['operador'] ?? '';
                $dato  = $ctx[$campo] ?? null;
                $valor = in_array($op, ['vacio', 'no_vacio'], true) ? '' : ' «' . ($r['valor'] ?? '') . '»';

                return "{$label} " . ($ops[$op] ?? '?') . $valor . (is_scalar($dato) ? " (es «{$dato}»)" : '');
            })
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
        AutomatizacionAprobacion::where('ejecucion_id', $e->id)->where('estado', 'pendiente')->update(['estado' => 'cancelada']);

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
