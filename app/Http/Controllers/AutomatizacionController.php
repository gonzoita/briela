<?php

namespace App\Http\Controllers;

use App\Models\AgenteInterno;
use App\Models\Automatizacion;
use App\Models\AutomatizacionAprobacion;
use App\Models\AutomatizacionEjecucion;
use App\Models\User;
use App\Jobs\EjecutarAutomatizacionJob;
use App\Services\Automatizaciones\CatalogoAutomatizaciones;
use App\Services\Automatizaciones\EntidadesAutomatizacion;
use App\Services\Automatizaciones\EvaluadorCondiciones;
use App\Services\Automatizaciones\GrafoAutomatizacion;
use App\Services\Automatizaciones\LanzadorAutomatizaciones;
use App\Services\Automatizaciones\MotorAutomatizaciones;
use App\Services\Automatizaciones\PlantillasAutomatizacion;
use App\Support\Modulos;
use App\Support\Permisos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Automatizaciones: lo que Briela hace sola cuando pasa algo o a una hora.
 *
 * La pantalla es una página; todo lo demás es JSON bajo `/api/automatizaciones`. Cada ruta lleva su
 * permiso (ver / gestionar / ejecutar) y el módulo apagado corta el prefijo entero.
 *
 * **Una automatización actúa con los permisos de su autor.** Al guardar se valida que el autor pueda
 * hacer cada acción y ver cada documento; al ejecutar se vuelve a preguntar (ver `AccionesAutomatizacion`).
 *
 * Las **aprobaciones** son la excepción a los permisos del módulo: las decide la persona a la que se
 * le pidieron, tenga o no acceso a la pantalla de automatizaciones. Por eso su página y sus rutas
 * solo exigen sesión, y cada una comprueba que quien entra sea uno de los destinatarios.
 */
class AutomatizacionController extends Controller
{
    public function index(): Response
    {
        $u = auth()->user();

        return Inertia::render('Automatizaciones/Index', [
            'permisos' => [
                'gestionar' => $u->tienePermiso('automatizaciones.gestionar'),
                'ejecutar'  => $u->tienePermiso('automatizaciones.ejecutar'),
            ],
        ]);
    }

    // ─── Catálogo y lista ────────────────────────────────────────────────────

    public function catalogo(): JsonResponse
    {
        $u      = auth()->user();
        $lista  = fn (array $campos) => collect($campos)->map(fn ($c, $ck) => ['clave' => $ck] + $c)->values();
        $puede  = fn (?string $permiso) => ! $permiso || $u->tienePermiso($permiso);

        $entidades = collect(EntidadesAutomatizacion::todas())
            ->filter(fn ($d, $k) => EntidadesAutomatizacion::disponible($k))
            ->map(fn ($d, $k) => [
                'clave' => $k, 'label' => $d['label'], 'plural' => $d['plural'], 'sensible' => $d['sensible'],
                'puede' => $u->tienePermiso($d['permiso']), 'barrido' => $d['barrido'] !== null,
                'campos' => $lista(CatalogoAutomatizaciones::camposDe($k)), 'ejemplo' => CatalogoAutomatizaciones::ejemploDe($k),
            ])->values();

        $acciones = collect(CatalogoAutomatizaciones::accionesDisponibles())->map(fn ($a, $k) => [
            'clave' => $k, 'label' => $a['label'], 'grupo' => $a['grupo'], 'descripcion' => $a['descripcion'], 'entidades' => $a['entidades'],
            'sin_sensibles' => $a['sin_sensibles'], 'permiso' => $a['permiso'], 'puede' => $puede($a['permiso']), 'campos' => $lista($a['campos']),
        ])->values();

        // Lo que le falta a cada plantilla con ESTA persona (elegir a quién, un permiso): se muestra
        // antes de usarla, no al guardar.
        $plantillas = collect(PlantillasAutomatizacion::todas())
            ->map(fn ($p) => $p + ['pendientes' => GrafoAutomatizacion::validar($p['grafo'], $u)])
            ->values();

        return response()->json([
            'eventos'    => collect(CatalogoAutomatizaciones::eventosDisponibles())
                ->map(fn ($e, $k) => ['clave' => $k, 'label' => $e['label'], 'entidad' => $e['entidad']])->values(),
            'entidades'  => $entidades,
            'globales'   => $lista(CatalogoAutomatizaciones::camposGlobales()),
            'acciones'   => $acciones,
            'operadores' => collect(CatalogoAutomatizaciones::operadores())->map(fn ($l, $k) => ['clave' => $k, 'label' => $l])->values(),
            'unidades'   => array_keys(CatalogoAutomatizaciones::unidadesDeEspera()),
            'usuarios'   => User::where('activo', true)->orderBy('name')->get(['id', 'name']),
            'etapas'     => Modulos::activo('crm') ? EntidadesAutomatizacion::etapas() : [],
            'permisos'   => collect(Permisos::catalogo())->flatMap(fn ($mods) => collect($mods)->flatMap(
                fn ($m, $mod) => collect($m['acciones'])->map(fn ($a) => ['clave' => "{$mod}.{$a}", 'label' => "{$m['label']} · " . (Permisos::etiquetasAcciones()[$a] ?? $a)])
            ))->values(),
            // Un agente solo se ofrece si el módulo está encendido y esta persona puede ponerlo a trabajar.
            'agentes'    => Modulos::activo('equipo_ia') && $u->tienePermiso('equipo_ia.ejecutar')
                ? AgenteInterno::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']) : [],
            'plantillas' => $plantillas,
        ]);
    }

    public function lista(): JsonResponse
    {
        $lista = Automatizacion::with('autor:id,name')
            ->withCount(['ejecuciones as en_curso' => fn ($q) => $q->enCurso()])
            ->withCount(['ejecuciones as fallidas_24h' => fn ($q) => $q->where('estado', 'fallida')->where('created_at', '>=', now()->subDay())])
            ->withCount(['ejecuciones as corridas_24h' => fn ($q) => $q->where('created_at', '>=', now()->subDay())])
            ->orderByDesc('activa')->orderBy('nombre')->get()
            ->map(fn (Automatizacion $a) => $this->resumen($a));

        return response()->json($lista);
    }

    public function mostrar(Automatizacion $automatizacion): JsonResponse
    {
        return response()->json($this->resumen($automatizacion) + ['grafo' => $automatizacion->grafo, 'max_por_hora' => $automatizacion->max_por_hora]);
    }

    // ─── Guardar ─────────────────────────────────────────────────────────────

    public function crear(Request $request): JsonResponse
    {
        $d = $this->validar($request);

        if ($errores = GrafoAutomatizacion::validar($d['grafo'], auth()->user())) {
            return $this->errores($errores);
        }

        $a = Automatizacion::create($d + $this->disparador($d['grafo']) + ['creado_por' => auth()->id(), 'activa' => false]);

        return response()->json($this->resumen($a) + ['grafo' => $a->grafo], 201);
    }

    public function actualizar(Request $request, Automatizacion $automatizacion): JsonResponse
    {
        $d = $this->validar($request);

        // Una automatización pertenece a quien la armó, y sus permisos son su techo: si la edita otra
        // persona, pasa a ser SUYA —y se valida con los permisos de esa persona—.
        if ($errores = GrafoAutomatizacion::validar($d['grafo'], auth()->user())) {
            return $this->errores($errores);
        }

        $automatizacion->update($d + $this->disparador($d['grafo']) + [
            'creado_por' => auth()->id(), 'version' => $automatizacion->version + 1, 'fallas_seguidas' => 0, 'ultima_ventana' => null,
        ]);

        return response()->json($this->resumen($automatizacion->fresh()) + ['grafo' => $automatizacion->grafo]);
    }

    public function eliminar(Automatizacion $automatizacion): JsonResponse
    {
        // Lo que está en curso se cancela: sin su automatización, nadie puede decir qué iba a pasar.
        LanzadorAutomatizaciones::cancelarEnCurso($automatizacion->ejecuciones(), 'Se eliminó la automatización.');
        $automatizacion->update(['activa' => false]);
        $automatizacion->delete();

        return response()->json(['ok' => true]);
    }

    public function activar(Request $request, Automatizacion $automatizacion): JsonResponse
    {
        $activa = $request->boolean('activa');

        if ($activa) {
            // Al encenderla se vuelve a validar con los permisos de quien la enciende: los datos o los
            // módulos pudieron cambiar desde que se guardó.
            if ($errores = GrafoAutomatizacion::validar($automatizacion->grafo, auth()->user())) {
                return $this->errores($errores);
            }

            $automatizacion->creado_por = auth()->id();
            $automatizacion->fallas_seguidas = 0;
        } else {
            LanzadorAutomatizaciones::cancelarEnCurso($automatizacion->ejecuciones(), 'Se pausó la automatización.');
        }

        $automatizacion->activa = $activa;
        $automatizacion->save();

        return response()->json($this->resumen($automatizacion->fresh()));
    }

    // ─── Probar y ejecutar ───────────────────────────────────────────────────

    /**
     * La prueba en seco: el camino que seguiría, sin ejecutar nada. Con datos de ejemplo (que la
     * persona puede cambiar) o con un documento real, por su número o su id.
     */
    public function probar(Request $request, MotorAutomatizaciones $motor): JsonResponse
    {
        $d = $request->validate([
            'grafo' => ['required', 'array'], 'contexto' => ['nullable', 'array'], 'documento' => ['nullable', 'string', 'max:60'],
            'nombre' => ['nullable', 'string', 'max:150'],
        ]);

        if ($errores = GrafoAutomatizacion::validar($d['grafo'], auth()->user())) {
            return $this->errores($errores);
        }

        $entidad = GrafoAutomatizacion::entidadDe($d['grafo']);
        $real    = false;

        if ($entidad && filled($d['documento'] ?? null)) {
            $doc = EntidadesAutomatizacion::buscarParaProbar($entidad, $d['documento']);

            if (! $doc) {
                return response()->json(['message' => 'No encontré ese documento. Escribe su número (o su id).'], 422);
            }

            $contexto = EntidadesAutomatizacion::contexto($doc);
            $real     = true;
        } else {
            $ejemplo  = CatalogoAutomatizaciones::ejemploDe($entidad);
            $contexto = array_merge($ejemplo, array_intersect_key($d['contexto'] ?? [], $ejemplo));

            // Con datos de ejemplo, «el responsable» es quien prueba: así la prueba muestra el aviso.
            if ($entidad) {
                $contexto += ['_tipo' => $entidad, '_entidad' => $entidad, '_id' => 0, '_url' => '/', '_responsable_id' => auth()->id()];
            }
        }

        $resultado = $motor->simular($d['grafo'], $contexto, auth()->user(), $d['nombre'] ?? 'Prueba');

        return response()->json($resultado + ['contexto' => EvaluadorCondiciones::publicos($contexto), 'real' => $real]);
    }

    public function ejecutar(Automatizacion $automatizacion, LanzadorAutomatizaciones $lanzador): JsonResponse
    {
        if (! Modulos::activo('automatizaciones')) {
            return response()->json(['message' => 'El módulo está apagado.'], 422);
        }

        if (! $automatizacion->activa) {
            return response()->json(['message' => 'Enciéndela primero: una automatización apagada no corre.'], 422);
        }

        if (GrafoAutomatizacion::disparadorDe($automatizacion->grafo)['tipo'] !== 'manual') {
            return response()->json(['message' => 'Solo las automatizaciones de arranque manual se lanzan a mano.'], 422);
        }

        if ($errores = GrafoAutomatizacion::validar($automatizacion->grafo, auth()->user())) {
            return $this->errores($errores);
        }

        $e = $lanzador->manual($automatizacion, auth()->user());

        return response()->json(['ejecucion_id' => $e?->id, 'mensaje' => $e ? 'En cola.' : 'Llegó al límite de corridas por hora.'], $e ? 202 : 429);
    }

    public function ejecuciones(Automatizacion $automatizacion): JsonResponse
    {
        return response()->json($automatizacion->ejecuciones()->latest('id')->limit(30)->get()->map(fn ($e) => $this->ejecucion($e)));
    }

    public function ejecucionDetalle(AutomatizacionEjecucion $ejecucion): JsonResponse
    {
        return response()->json($this->ejecucion($ejecucion) + [
            'pasos' => $ejecucion->pasos->map(fn ($p) => ['orden' => $p->orden, 'nodo_id' => $p->nodo_id, 'tipo' => $p->tipo, 'estado' => $p->estado, 'resumen' => $p->resumen, 'ms' => $p->ms]),
            'url'   => $ejecucion->contexto['_url'] ?? null,
            'aprobaciones' => $ejecucion->aprobaciones()->with('decididaPor:id,name')->get()->map(fn ($a) => [
                'id' => $a->id, 'pregunta' => $a->pregunta, 'estado' => $a->estado, 'por' => $a->decididaPor?->name, 'nota' => $a->nota,
                'vence' => $a->vence_at?->toIso8601String(),
            ]),
        ]);
    }

    public function cancelar(AutomatizacionEjecucion $ejecucion): JsonResponse
    {
        if (! in_array($ejecucion->estado, ['en_cola', 'esperando'], true)) {
            return response()->json(['message' => 'Esta corrida ya no se puede cancelar.'], 422);
        }

        LanzadorAutomatizaciones::cancelarEnCurso(AutomatizacionEjecucion::whereKey($ejecucion->id), 'La canceló ' . auth()->user()->name . '.');

        return response()->json($this->ejecucion($ejecucion->fresh()));
    }

    // ─── Aprobaciones ────────────────────────────────────────────────────────

    /** Las aprobaciones que esperan a quien pregunta. */
    public function aprobaciones(): JsonResponse
    {
        return response()->json(
            AutomatizacionAprobacion::pendientesDe(auth()->id())->with('ejecucion.automatizacion:id,nombre')->latest('id')->limit(50)->get()
                ->map(fn ($a) => $this->aprobacion($a))
        );
    }

    public function aprobacionPagina(AutomatizacionAprobacion $aprobacion): Response
    {
        abort_unless($aprobacion->puedeDecidir(auth()->user()), 403, 'Esta aprobación no se le pidió a usted.');

        return Inertia::render('Automatizaciones/Aprobar', ['aprobacion' => $this->aprobacion($aprobacion->load('ejecucion.automatizacion:id,nombre', 'decididaPor:id,name'))]);
    }

    public function decidir(Request $request, AutomatizacionAprobacion $aprobacion): JsonResponse
    {
        abort_unless($aprobacion->puedeDecidir(auth()->user()), 403, 'Esta aprobación no se le pidió a usted.');

        $d = $request->validate(['decision' => ['required', 'in:aprobar,rechazar'], 'nota' => ['nullable', 'string', 'max:500']]);

        // Decide el primero que conteste: el `where` hace que dos respuestas a la vez no se pisen.
        $hecho = AutomatizacionAprobacion::whereKey($aprobacion->id)->where('estado', 'pendiente')->where('vence_at', '>', now())->update([
            'estado' => $d['decision'] === 'aprobar' ? 'aprobada' : 'rechazada',
            'decidida_por' => auth()->id(), 'decidida_at' => now(), 'nota' => $d['nota'] ?? null, 'updated_at' => now(),
        ]);

        if (! $hecho) {
            return response()->json(['message' => 'Esta aprobación ya se decidió, se canceló o venció.'], 422);
        }

        // La corrida sigue ya, sin esperar al cron.
        AutomatizacionEjecucion::whereKey($aprobacion->ejecucion_id)->where('estado', 'esperando')->update(['reanudar_at' => now()]);
        EjecutarAutomatizacionJob::dispatch($aprobacion->ejecucion_id);

        return response()->json($this->aprobacion($aprobacion->fresh()->load('ejecucion.automatizacion:id,nombre', 'decididaPor:id,name')));
    }

    /** @return array<string, mixed> */
    private function aprobacion(AutomatizacionAprobacion $a): array
    {
        $ctx = $a->ejecucion?->contexto ?? [];
        $tipo = $ctx['_tipo'] ?? null;
        $campos = CatalogoAutomatizaciones::camposDe($tipo);

        return [
            'id' => $a->id, 'pregunta' => $a->pregunta, 'estado' => $a->estado, 'automatizacion' => $a->ejecucion?->automatizacion?->nombre,
            'vence' => $a->vence_at?->toIso8601String(), 'por' => $a->decididaPor?->name, 'nota' => $a->nota,
            'documento' => $tipo ? (EntidadesAutomatizacion::def($tipo)['label'] ?? null) : null, 'url' => $ctx['_url'] ?? null,
            // Lo que sabe del documento, con nombres de pantalla, para decidir sin tener que abrirlo.
            'datos' => collect(EvaluadorCondiciones::publicos($ctx))
                ->filter(fn ($v, $k) => isset($campos[$k]) && empty($campos[$k]['global']) && is_scalar($v) && $v !== '')
                ->map(fn ($v, $k) => ['label' => $campos[$k]['label'], 'valor' => ($campos[$k]['formato'] ?? '') === 'dinero' && is_numeric($v) ? '$ ' . number_format((float) $v, 0, ',', '.') : (string) $v])
                ->values(),
        ];
    }

    // ─── Ayudas ──────────────────────────────────────────────────────────────

    private function validar(Request $request): array
    {
        return $request->validate([
            'nombre'       => ['required', 'string', 'max:150'],
            'descripcion'  => ['nullable', 'string', 'max:400'],
            'grafo'        => ['required', 'array'],
            'max_por_hora' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]) + ['max_por_hora' => 60];
    }

    /** @return array{disparador_tipo: string, disparador_clave: ?string} */
    private function disparador(array $grafo): array
    {
        $d = GrafoAutomatizacion::disparadorDe($grafo);

        return ['disparador_tipo' => $d['tipo'], 'disparador_clave' => $d['clave']];
    }

    private function errores(array $errores): JsonResponse
    {
        return response()->json(['message' => $errores[0], 'errors' => ['grafo' => $errores]], 422);
    }

    /** Cómo arranca, en una frase: «Cuando cambia el estado de una cotización», «Cada día a las 07:00 · cotizaciones abiertas». */
    private function describirDisparador(array $grafo): string
    {
        $d   = GrafoAutomatizacion::disparadorDe($grafo);
        $cfg = GrafoAutomatizacion::nodo($grafo, GrafoAutomatizacion::primero($grafo))['config'] ?? [];

        if ($d['tipo'] === 'evento') {
            return CatalogoAutomatizaciones::eventos()[$d['clave'] ?? '']['label'] ?? 'Un evento';
        }

        if ($d['tipo'] !== 'horario') {
            return 'A mano';
        }

        $f    = $cfg['frecuencia'] ?? [];
        $dias = ['', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom'];

        $cuando = match ($f['tipo'] ?? '') {
            'diaria'     => 'Cada día a las ' . ($f['hora'] ?? '?'),
            'semanal'    => collect($f['dias'] ?? [])->map(fn ($x) => $dias[(int) $x] ?? '')->implode(', ') . ' a las ' . ($f['hora'] ?? '?'),
            'mensual'    => 'El día ' . ($f['dia_mes'] ?? '?') . ' de cada mes a las ' . ($f['hora'] ?? '?'),
            'cada_horas' => 'Cada ' . ($f['intervalo'] ?? '?') . ' h, de ' . ($f['desde'] ?? '?') . ' a ' . ($f['hasta'] ?? '?'),
            default      => 'A una hora',
        };

        return $d['entidad'] ? $cuando . ' · ' . mb_strtolower(EntidadesAutomatizacion::def($d['entidad'])['plural'] ?? '') : $cuando;
    }

    /** @return array<string, mixed> */
    private function resumen(Automatizacion $a): array
    {
        $d = GrafoAutomatizacion::disparadorDe($a->grafo);

        return [
            'id' => $a->id, 'nombre' => $a->nombre, 'descripcion' => $a->descripcion, 'activa' => $a->activa,
            'disparador' => $this->describirDisparador($a->grafo), 'disparador_tipo' => $d['tipo'],
            'pasos' => max(0, count($a->grafo['nodos'] ?? []) - 1),
            'acciones' => collect($a->grafo['nodos'] ?? [])->where('tipo', 'accion')
                ->map(fn ($n) => CatalogoAutomatizaciones::acciones()[$n['accion'] ?? '']['label'] ?? $n['accion'] ?? '?')->unique()->values(),
            'autor' => $a->relationLoaded('autor') ? $a->autor?->name : null, 'fallas_seguidas' => $a->fallas_seguidas,
            'en_curso' => (int) ($a->en_curso ?? 0), 'fallidas_24h' => (int) ($a->fallidas_24h ?? 0), 'corridas_24h' => (int) ($a->corridas_24h ?? 0),
            'ultima' => $a->ultima_ejecucion_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function ejecucion(AutomatizacionEjecucion $e): array
    {
        $ctx = $e->contexto ?? [];

        return [
            'id' => $e->id, 'automatizacion_id' => $e->automatizacion_id, 'estado' => $e->estado, 'origen' => $e->origen, 'evento' => $e->evento,
            'error' => $e->error, 'reanudar' => $e->reanudar_at?->toIso8601String(), 'creada' => $e->created_at?->toIso8601String(),
            'terminada' => $e->terminada_at?->toIso8601String(),
            'documento' => $ctx['numero'] ?? $ctx['titulo'] ?? $ctx['nombre'] ?? $ctx['contacto'] ?? $ctx['equipo'] ?? $ctx['op_numero'] ?? null,
        ];
    }
}
