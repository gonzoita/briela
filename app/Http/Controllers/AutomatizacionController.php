<?php

namespace App\Http\Controllers;

use App\Models\AgenteInterno;
use App\Models\Automatizacion;
use App\Models\AutomatizacionEjecucion;
use App\Models\User;
use App\Services\Automatizaciones\CatalogoAutomatizaciones;
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
 * hacer cada acción; al ejecutar se vuelve a preguntar (ver `AccionesAutomatizacion`).
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
        $u = auth()->user();

        return response()->json([
            'eventos'    => collect(CatalogoAutomatizaciones::eventosDisponibles())->map(fn ($e, $k) => [
                'clave' => $k, 'label' => $e['label'], 'entidad' => $e['entidad'], 'ejemplo' => $e['ejemplo'],
                'campos' => collect($e['campos'])->map(fn ($c, $ck) => ['clave' => $ck] + $c)->values(),
            ])->values(),
            'acciones'   => collect(CatalogoAutomatizaciones::acciones())->map(fn ($a, $k) => [
                'clave' => $k, 'label' => $a['label'], 'descripcion' => $a['descripcion'], 'entidades' => $a['entidades'],
                'permiso' => $a['permiso'], 'puede' => ! $a['permiso'] || $u->tienePermiso($a['permiso']),
                'campos' => collect($a['campos'])->map(fn ($c, $ck) => ['clave' => $ck] + $c)->values(),
            ])->values(),
            'operadores' => collect(CatalogoAutomatizaciones::operadores())->map(fn ($l, $k) => ['clave' => $k, 'label' => $l])->values(),
            'unidades'   => array_keys(CatalogoAutomatizaciones::unidadesDeEspera()),
            'usuarios'   => User::where('activo', true)->orderBy('name')->get(['id', 'name']),
            'permisos'   => collect(Permisos::catalogo())->flatMap(fn ($mods) => collect($mods)->flatMap(
                fn ($m, $mod) => collect($m['acciones'])->map(fn ($a) => ['clave' => "{$mod}.{$a}", 'label' => "{$m['label']} · " . (Permisos::etiquetasAcciones()[$a] ?? $a)])
            ))->values(),
            // Un agente solo se ofrece si el módulo está encendido y esta persona puede ponerlo a trabajar.
            'agentes'    => Modulos::activo('equipo_ia') && $u->tienePermiso('equipo_ia.ejecutar')
                ? AgenteInterno::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']) : [],
            'plantillas' => PlantillasAutomatizacion::todas(),
        ]);
    }

    public function lista(): JsonResponse
    {
        $lista = Automatizacion::with('autor:id,name')
            ->withCount(['ejecuciones as en_curso' => fn ($q) => $q->enCurso()])
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
            'creado_por' => auth()->id(), 'version' => $automatizacion->version + 1, 'fallas_seguidas' => 0,
        ]);

        return response()->json($this->resumen($automatizacion->fresh()) + ['grafo' => $automatizacion->grafo]);
    }

    public function eliminar(Automatizacion $automatizacion): JsonResponse
    {
        // Lo que está esperando se cancela: sin su automatización, nadie puede decir qué iba a pasar.
        $automatizacion->ejecuciones()->enCurso()->update(['estado' => 'cancelada', 'error' => 'Se eliminó la automatización.', 'terminada_at' => now()]);
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
            $automatizacion->ejecuciones()->where('estado', 'esperando')->update(['estado' => 'cancelada', 'error' => 'Se pausó la automatización.', 'terminada_at' => now()]);
        }

        $automatizacion->activa = $activa;
        $automatizacion->save();

        return response()->json($this->resumen($automatizacion->fresh()));
    }

    // ─── Probar y ejecutar ───────────────────────────────────────────────────

    /** La prueba en seco: el camino que seguiría con estos datos, sin ejecutar nada. */
    public function probar(Request $request, MotorAutomatizaciones $motor): JsonResponse
    {
        $d = $request->validate(['grafo' => ['required', 'array'], 'contexto' => ['nullable', 'array']]);

        if ($errores = GrafoAutomatizacion::validar($d['grafo'], auth()->user())) {
            return $this->errores($errores);
        }

        $disparo  = GrafoAutomatizacion::disparadorDe($d['grafo']);
        $ejemplo  = CatalogoAutomatizaciones::eventos()[$disparo['clave'] ?? '']['ejemplo'] ?? [];
        $contexto = array_merge($ejemplo, array_intersect_key($d['contexto'] ?? [], $ejemplo));

        return response()->json($motor->simular($d['grafo'], $contexto, auth()->user()) + ['contexto' => $contexto]);
    }

    public function ejecutar(Automatizacion $automatizacion, LanzadorAutomatizaciones $lanzador): JsonResponse
    {
        if (! Modulos::activo('automatizaciones')) {
            return response()->json(['message' => 'El módulo está apagado.'], 422);
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
        return response()->json($automatizacion->ejecuciones()->latest('id')->limit(20)->get()->map(fn ($e) => $this->ejecucion($e)));
    }

    public function ejecucionDetalle(AutomatizacionEjecucion $ejecucion): JsonResponse
    {
        return response()->json($this->ejecucion($ejecucion) + [
            'pasos' => $ejecucion->pasos->map(fn ($p) => ['orden' => $p->orden, 'nodo_id' => $p->nodo_id, 'tipo' => $p->tipo, 'estado' => $p->estado, 'resumen' => $p->resumen, 'ms' => $p->ms]),
        ]);
    }

    public function cancelar(AutomatizacionEjecucion $ejecucion): JsonResponse
    {
        if (! in_array($ejecucion->estado, ['en_cola', 'esperando'], true)) {
            return response()->json(['message' => 'Esta corrida ya no se puede cancelar.'], 422);
        }

        $ejecucion->update(['estado' => 'cancelada', 'error' => 'La canceló ' . auth()->user()->name . '.', 'terminada_at' => now(), 'reanudar_at' => null]);

        return response()->json($this->ejecucion($ejecucion));
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

    /** @return array<string, mixed> */
    private function resumen(Automatizacion $a): array
    {
        $d = GrafoAutomatizacion::disparadorDe($a->grafo);
        $evento = CatalogoAutomatizaciones::eventos()[$d['clave'] ?? '']['label'] ?? null;

        return [
            'id' => $a->id, 'nombre' => $a->nombre, 'descripcion' => $a->descripcion, 'activa' => $a->activa,
            'disparador' => match ($d['tipo']) { 'evento' => $evento ?? 'Un evento', 'horario' => 'A una hora', default => 'A mano' },
            'disparador_tipo' => $d['tipo'], 'pasos' => count($a->grafo['nodos'] ?? []),
            'autor' => $a->relationLoaded('autor') ? $a->autor?->name : null, 'fallas_seguidas' => $a->fallas_seguidas,
            'en_curso' => (int) ($a->en_curso ?? 0), 'ultima' => $a->ultima_ejecucion_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function ejecucion(AutomatizacionEjecucion $e): array
    {
        return [
            'id' => $e->id, 'automatizacion_id' => $e->automatizacion_id, 'estado' => $e->estado, 'origen' => $e->origen, 'evento' => $e->evento,
            'error' => $e->error, 'reanudar' => $e->reanudar_at?->toIso8601String(), 'creada' => $e->created_at?->toIso8601String(),
            'terminada' => $e->terminada_at?->toIso8601String(), 'documento' => $e->contexto['numero'] ?? $e->contexto['titulo'] ?? null,
        ];
    }
}
