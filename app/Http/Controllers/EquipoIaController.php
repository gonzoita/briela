<?php

namespace App\Http\Controllers;

use App\Models\AgenteEjecucion;
use App\Models\AgenteInterno;
use App\Models\AgentePropuesta;
use App\Models\CargoIa;
use App\Models\Configuracion;
use App\Models\IaConsumo;
use App\Models\PromptVersionIa;
use App\Models\Rol;
use App\Models\User;
use App\Services\Agentes\AgenteUsuarioService;
use App\Services\Agentes\AgentesDeFabrica;
use App\Services\Agentes\AprobacionService;
use App\Services\Agentes\ArmadorPrompt;
use App\Services\Agentes\CatalogoHerramientas;
use App\Services\Agentes\EjecutorAgente;
use App\Services\Agentes\LanzadorAgentes;
use App\Services\Agentes\PropuestasAgenteService;
use App\Services\Agentes\RecomendadorModelo;
use App\Services\IA\IaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * El Equipo de IA: agentes internos que trabajan para la empresa y proponen; una persona decide.
 *
 * Todo es JSON bajo `/api/equipo-ia`, consumido por la pestaña «Equipo interno» de la pantalla
 * Agentes. Cada ruta lleva su permiso (ver / ejecutar / aprobar / gestionar / consumo) y el
 * módulo apagado las corta enteras (ver `Modulos`).
 */
class EquipoIaController extends Controller
{
    public function __construct(
        private readonly IaService $ia,
        private readonly EjecutorAgente $ejecutor,
    ) {}

    // ─── La oficina ──────────────────────────────────────────────────────────

    public function oficina(AgentesDeFabrica $fabrica): JsonResponse
    {
        // La primera vez que se abre con el módulo encendido, llegan los de fábrica.
        if (! CargoIa::exists()) {
            $fabrica->sembrar();
        }

        $agentes = AgenteInterno::with(['cargo:id,nombre', 'ultimaEjecucion'])
            ->withExists(['ejecuciones as en_curso' => fn ($q) => $q->whereIn('estado', AgenteEjecucion::EN_CURSO)])
            ->withCount(['propuestas as pendientes' => fn ($q) => $q->where('estado', 'pendiente')])
            ->orderBy('orden')->orderBy('id')
            ->get()
            ->map(function (AgenteInterno $a) {
                $u = $a->ultimaEjecucion;

                return [
                    'id'          => $a->id,
                    'nombre'      => $a->nombre,
                    'descripcion' => $a->descripcion,
                    'avatar'      => $a->avatar,
                    'color'       => $a->color,
                    'cargo'       => $a->cargo?->nombre,
                    'activo'      => $a->activo,
                    'modo'        => $a->modo,
                    'modelo'      => $this->ejecutor->modeloDe($a),
                    'frecuencia'  => $a->frecuencia,
                    'pendientes'  => (int) $a->pendientes,
                    'estado'      => $a->estadoOficina((bool) $a->en_curso, (int) $a->pendientes, $u),
                    'ultima'      => $u ? [
                        'id'      => $u->id,
                        'estado'  => $u->estado,
                        'resumen' => \Illuminate\Support\Str::limit((string) $u->resumen, 220),
                        'error'   => $u->error,
                        'cuando'  => ($u->terminada_at ?? $u->created_at)?->toIso8601String(),
                    ] : null,
                ];
            });

        return response()->json([
            'agentes'   => $agentes,
            'pausado'   => $this->pausado(),
            'ia_lista'  => $this->ia->configurado(),
            'pendientes' => (int) $agentes->sum('pendientes'),
        ]);
    }

    /**
     * Una firma barata de «qué cambió». La pantalla la pide cada pocos segundos y solo vuelve a
     * traer la lista completa si la firma cambió: mismo patrón que la campana de avisos.
     */
    public function pulso(): JsonResponse
    {
        $ej = AgenteEjecucion::selectRaw("MAX(updated_at) as ultima, SUM(estado IN ('en_cola','corriendo')) as en_curso")->first();
        $pr = AgentePropuesta::selectRaw("MAX(updated_at) as ultima, SUM(estado = 'pendiente') as pendientes")->first();

        $firma = md5(implode('|', [$ej->ultima, (int) $ej->en_curso, $pr->ultima, (int) $pr->pendientes, (int) $this->pausado()]));

        return response()->json(['firma' => $firma, 'pendientes' => (int) $pr->pendientes, 'en_curso' => (int) $ej->en_curso]);
    }

    public function pausar(Request $request): JsonResponse
    {
        $pausar = $request->boolean('pausado');
        Configuracion::set('equipo_ia_pausado', $pausar ? '1' : '0');

        return response()->json(['pausado' => $pausar]);
    }

    private function pausado(): bool
    {
        return LanzadorAgentes::pausado();
    }

    // ─── Agentes ─────────────────────────────────────────────────────────────

    public function agente(AgenteInterno $agente): JsonResponse
    {
        return response()->json($this->agenteCompleto($agente));
    }

    public function crearAgente(Request $request): JsonResponse
    {
        $datos = $this->validarAgente($request);

        $agente = AgenteInterno::create($datos + [
            'creado_por' => auth()->id(),
            'orden'      => (int) AgenteInterno::max('orden') + 1,
            'activo'     => false,
        ]);

        PromptVersionIa::registrar('agente', $agente->id, $agente->instrucciones, $agente->tarea, auth()->id(), 'Versión inicial');

        // El usuario con el que actuará, listo para la prueba.
        if ($agente->cargo?->rol_id) {
            app(AgenteUsuarioService::class)->asegurar($agente->load('cargo'));
        }

        return response()->json($this->agenteCompleto($agente->fresh()), 201);
    }

    public function actualizarAgente(Request $request, AgenteInterno $agente): JsonResponse
    {
        $datos = $this->validarAgente($request, $agente);

        // Se revisa ANTES de guardar: un agente no se enciende a medias.
        $agente->fill($datos)->unsetRelation('cargo')->load('cargo.rol');

        if (($datos['activo'] ?? false) && ! $this->puedeActivarse($agente, $motivo)) {
            return response()->json(['message' => $motivo], 422);
        }

        $agente->save();

        PromptVersionIa::registrar('agente', $agente->id, $agente->instrucciones, $agente->tarea, auth()->id());

        // Un cambio de cargo o de sede cambia lo que el agente puede ver: se pone al día su usuario.
        if ($agente->cargo?->rol_id) {
            app(AgenteUsuarioService::class)->asegurar($agente->load('cargo'));
        }

        return response()->json($this->agenteCompleto($agente->fresh()));
    }

    public function eliminarAgente(AgenteInterno $agente): JsonResponse
    {
        if ($agente->ejecuciones()->enCurso()->exists()) {
            return response()->json(['message' => 'Tiene una corrida en curso. Espera a que termine.'], 422);
        }

        // Soft delete: el historial de corridas, propuestas y consumo se conserva.
        $agente->update(['activo' => false]);
        $agente->delete();

        return response()->json(['ok' => true]);
    }

    /** Un agente se enciende solo si tiene con qué trabajar: un cargo con rol activo y herramientas. */
    private function puedeActivarse(AgenteInterno $agente, ?string &$motivo): bool
    {
        if (! $agente->cargo || ! $agente->cargo->rol?->activo) {
            $motivo = 'Para activarlo necesita un cargo con un rol activo: el rol es lo que le permite leer datos.';

            return false;
        }

        if ($agente->herramientasEfectivas() === []) {
            $motivo = 'Para activarlo necesita al menos una herramienta.';

            return false;
        }

        return true;
    }

    private function validarAgente(Request $request, ?AgenteInterno $actual = null): array
    {
        $claves = array_keys(CatalogoHerramientas::catalogo());

        $d = $request->validate([
            'nombre'               => ['required', 'string', 'max:120'],
            'descripcion'          => ['nullable', 'string', 'max:300'],
            'avatar'               => ['nullable', 'string', 'max:40'],
            'color'                => ['nullable', 'string', 'max:20'],
            'cargo_id'             => ['required', 'integer', Rule::exists('cargos_ia', 'id')],
            'instrucciones'        => ['nullable', 'string', 'max:8000'],
            'tarea'                => ['nullable', 'string', 'max:2000'],
            'herramientas'         => ['nullable', 'array'],
            'herramientas.*'       => ['string', Rule::in($claves)],
            'modelo'               => ['nullable', 'string', 'max:100'],
            'modelo_respaldo'      => ['nullable', 'string', 'max:100'],
            'nivel_modelo'         => ['nullable', Rule::in(['economico', 'potente'])],
            'temperatura'          => ['nullable', 'numeric', 'min:0', 'max:2'],
            'max_tokens'           => ['nullable', 'integer', 'min:200', 'max:8000'],
            'max_pasos'            => ['nullable', 'integer', 'min:1', 'max:20'],
            'max_tokens_ejecucion' => ['nullable', 'integer', 'min:5000', 'max:500000'],
            'frecuencia'           => ['nullable', 'array'],
            'frecuencia.tipo'      => ['nullable', Rule::in(['manual', 'diaria', 'semanal'])],
            'frecuencia.hora'      => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'frecuencia.dias'      => ['nullable', 'array'],
            'frecuencia.dias.*'    => ['integer', 'between:1,7'],
            'modo'                 => ['required', Rule::in(AgenteInterno::MODOS)],
            'avisar_a'             => ['nullable', 'array'],
            'avisar_a.*'           => ['integer', Rule::exists('users', 'id')],
            'sede_id'              => ['nullable', 'integer', Rule::exists('sedes', 'id')],
            'activo'               => ['boolean'],
        ]);

        $d['max_tokens_ejecucion'] ??= $actual?->max_tokens_ejecucion ?? 60000;

        // El techo lo fija el cargo: un agente no puede tener más herramientas que su cargo.
        $cargo = CargoIa::find($d['cargo_id']);

        if (isset($d['herramientas']) && $cargo?->herramientas !== null) {
            $d['herramientas'] = array_values(array_intersect($d['herramientas'], $cargo->herramientas));
        }

        if (($d['frecuencia']['tipo'] ?? 'manual') !== 'manual' && empty($d['frecuencia']['hora'])) {
            abort(response()->json(['message' => 'Un agente programado necesita una hora.', 'errors' => ['frecuencia.hora' => ['Falta la hora.']]], 422));
        }

        $this->validarModelo($d['modelo'] ?? null, $d['herramientas'] ?? ($cargo?->herramientas ?? []));
        $this->validarModelo($d['modelo_respaldo'] ?? null, $d['herramientas'] ?? ($cargo?->herramientas ?? []));

        return $d;
    }

    /** Un agente con herramientas solo funciona con un modelo que las soporte. */
    private function validarModelo(?string $modelo, array $herramientas): void
    {
        if (! $modelo || $herramientas === []) {
            return;
        }

        $detalle = $this->ia->modelosDisponibles()['detalle'] ?? [];

        // Si no se pudo leer la lista, no se bloquea: la prueba en seco lo dirá.
        if ($detalle !== [] && isset($detalle[$modelo]) && ! $detalle[$modelo]['herramientas']) {
            abort(response()->json([
                'message' => "El modelo {$modelo} no soporta herramientas, y este agente las necesita. Elige otro.",
                'errors'  => ['modelo' => ["No soporta herramientas."]],
            ], 422));
        }
    }

    /** @return array<string, mixed> */
    private function agenteCompleto(AgenteInterno $a): array
    {
        $a->loadMissing('cargo.rol');

        return [
            'id'                   => $a->id,
            'clave'                => $a->clave,
            'nombre'               => $a->nombre,
            'descripcion'          => $a->descripcion,
            'avatar'               => $a->avatar,
            'color'                => $a->color,
            'cargo_id'             => $a->cargo_id,
            'cargo'                => $a->cargo?->nombre,
            'rol'                  => $a->cargo?->rol?->nombre,
            'instrucciones'        => $a->instrucciones,
            'tarea'                => $a->tarea,
            'herramientas'         => $a->herramientas,
            'herramientas_efectivas' => $a->herramientasEfectivas(),
            'modelo'               => $a->modelo,
            'modelo_respaldo'      => $a->modelo_respaldo,
            'nivel_modelo'         => $a->nivel_modelo,
            'nivel_efectivo'       => $a->nivelEfectivo(),
            'modelo_efectivo'      => $this->ejecutor->modeloDe($a),
            'temperatura'          => $a->temperatura,
            'max_tokens'           => $a->max_tokens,
            'max_pasos'            => $a->max_pasos,
            'max_tokens_ejecucion' => $a->max_tokens_ejecucion,
            'frecuencia'           => $a->frecuencia ?? ['tipo' => 'manual', 'hora' => '', 'dias' => []],
            'modo'                 => $a->modo,
            'avisar_a'             => $a->avisar_a ?? [],
            'sede_id'              => $a->sede_id,
            'activo'               => $a->activo,
        ];
    }

    // ─── Cargos ──────────────────────────────────────────────────────────────

    /** Todo lo que la pantalla necesita para armar formularios: cargos, roles, herramientas, usuarios. */
    public function catalogos(): JsonResponse
    {
        $cargos = CargoIa::with('rol:id,nombre,activo')->withCount('agentes')->orderByDesc('de_fabrica')->orderBy('nombre')->get()
            ->map(fn (CargoIa $c) => [
                'id'            => $c->id,
                'clave'         => $c->clave,
                'nombre'        => $c->nombre,
                'descripcion'   => $c->descripcion,
                'area'          => $c->area,
                'color'         => $c->color,
                'avatar'        => $c->avatar,
                'instrucciones' => $c->instrucciones,
                'herramientas'  => $c->herramientas ?? [],
                'rol_id'        => $c->rol_id,
                'rol'           => $c->rol?->nombre,
                'rol_activo'    => (bool) $c->rol?->activo,
                'nivel_modelo'  => $c->nivel_modelo,
                'max_pasos'     => $c->max_pasos,
                'max_tokens'    => $c->max_tokens,
                'de_fabrica'    => $c->de_fabrica,
                'agentes'       => $c->agentes_count,
            ]);

        return response()->json([
            'cargos'       => $cargos,
            'roles'        => Rol::activos()->orderBy('nombre')->get(['id', 'nombre']),
            'herramientas' => collect(CatalogoHerramientas::catalogo())->map(fn ($h, $k) => [
                'clave' => $k, 'tipo' => $h['tipo'], 'grupo' => $h['grupo'], 'descripcion' => $h['descripcion'], 'permiso' => $h['permiso'],
            ])->values(),
            'usuarios'     => User::where('activo', true)->orderBy('name')->get(['id', 'name']),
            'sedes'        => \App\Models\Sede::activas()->orderBy('nombre')->get(['id', 'nombre']),
            'variables'    => ArmadorPrompt::nombresDeVariables(),
            'vence_horas'  => PropuestasAgenteService::horasDeVida(),
        ]);
    }

    public function crearCargo(Request $request): JsonResponse
    {
        $c = CargoIa::create($this->validarCargo($request) + ['de_fabrica' => false, 'activo' => true]);

        PromptVersionIa::registrar('cargo', $c->id, $c->instrucciones, null, auth()->id(), 'Versión inicial');

        return response()->json(['id' => $c->id], 201);
    }

    public function actualizarCargo(Request $request, CargoIa $cargo): JsonResponse
    {
        if ($cargo->de_fabrica) {
            return response()->json(['message' => 'Los cargos de fábrica no se editan: copia el cargo y edita la copia. Así una actualización puede ponerlo al día sin pisar tus cambios.'], 422);
        }

        $cargo->update($this->validarCargo($request));
        PromptVersionIa::registrar('cargo', $cargo->id, $cargo->instrucciones, null, auth()->id());

        // Los agentes de este cargo no pueden quedarse con herramientas que el cargo ya no permite.
        foreach ($cargo->agentes as $agente) {
            if ($agente->herramientas !== null) {
                $agente->update(['herramientas' => array_values(array_intersect($agente->herramientas, $cargo->herramientas ?? []))]);
            }

            app(AgenteUsuarioService::class)->asegurar($agente->load('cargo'));
        }

        return response()->json(['ok' => true]);
    }

    public function copiarCargo(CargoIa $cargo): JsonResponse
    {
        $copia = $cargo->replicate(['clave', 'de_fabrica']);
        $copia->nombre     = $cargo->nombre . ' (copia)';
        $copia->de_fabrica = false;
        $copia->clave      = null;
        $copia->save();

        PromptVersionIa::registrar('cargo', $copia->id, $copia->instrucciones, null, auth()->id(), "Copia de «{$cargo->nombre}»");

        return response()->json(['id' => $copia->id], 201);
    }

    public function eliminarCargo(CargoIa $cargo): JsonResponse
    {
        if ($cargo->de_fabrica) {
            return response()->json(['message' => 'Un cargo de fábrica no se elimina.'], 422);
        }

        if ($cargo->agentes()->exists()) {
            return response()->json(['message' => 'Hay agentes con este cargo. Cámbiales el cargo primero.'], 422);
        }

        $cargo->delete();

        return response()->json(['ok' => true]);
    }

    private function validarCargo(Request $request): array
    {
        $claves = array_keys(CatalogoHerramientas::catalogo());

        return $request->validate([
            'nombre'         => ['required', 'string', 'max:120'],
            'descripcion'    => ['nullable', 'string', 'max:300'],
            'area'           => ['nullable', 'string', 'max:40'],
            'color'          => ['nullable', 'string', 'max:20'],
            'avatar'         => ['nullable', 'string', 'max:40'],
            'instrucciones'  => ['nullable', 'string', 'max:8000'],
            'herramientas'   => ['nullable', 'array'],
            'herramientas.*' => ['string', Rule::in($claves)],
            'rol_id'         => ['nullable', 'integer', Rule::exists('roles', 'id')],
            'nivel_modelo'   => ['required', Rule::in(['economico', 'potente'])],
            'max_pasos'      => ['required', 'integer', 'min:1', 'max:20'],
            'max_tokens'     => ['required', 'integer', 'min:200', 'max:8000'],
        ]);
    }

    // ─── Prompt y modelo ─────────────────────────────────────────────────────

    /** El prompt final por capas, con su tamaño aproximado, ANTES de guardar. */
    public function vistaPrevia(Request $request, ArmadorPrompt $armador): JsonResponse
    {
        $d = $request->validate([
            'cargo_id'      => ['nullable', 'integer'],
            'nombre'        => ['nullable', 'string', 'max:120'],
            'instrucciones' => ['nullable', 'string', 'max:8000'],
            'herramientas'  => ['nullable', 'array'],
            'sede_id'       => ['nullable', 'integer'],
        ]);

        $agente = new AgenteInterno([
            'nombre' => $d['nombre'] ?? 'Agente', 'instrucciones' => $d['instrucciones'] ?? null,
            'herramientas' => $d['herramientas'] ?? null, 'sede_id' => $d['sede_id'] ?? null,
        ]);
        $agente->setRelation('cargo', isset($d['cargo_id']) ? CargoIa::find($d['cargo_id']) : null);
        $agente->setRelation('sede', isset($d['sede_id']) ? \App\Models\Sede::find($d['sede_id']) : null);

        $capas = $armador->capas($agente);
        $total = collect($capas)->sum(fn ($c) => $armador->tokensAproximados($c['texto']));

        return response()->json(['capas' => $capas, 'tokens' => $total]);
    }

    /** Los modelos de texto con lo que importa para elegir: herramientas, contexto y precio. */
    public function modelos(): JsonResponse
    {
        $lista = $this->ia->modelosDisponibles();

        return response()->json([
            'configurado' => $this->ia->configurado(),
            'modelos'     => collect($lista['texto'] ?? [])->map(fn ($nombre, $id) => [
                'id' => $id, 'nombre' => $nombre,
            ] + ($lista['detalle'][$id] ?? ['herramientas' => null, 'contexto' => 0, 'precio_in' => null, 'precio_out' => null]))->values(),
            'rapido'      => $this->ia->modeloRapido(),
            'texto'       => $this->ia->modeloTexto(),
        ]);
    }

    public function recomendarModelo(Request $request, RecomendadorModelo $recomendador): JsonResponse
    {
        $d = $request->validate([
            'herramientas'  => ['nullable', 'array'],
            'herramientas.*' => ['string'],
            'instrucciones' => ['nullable', 'string', 'max:8000'],
            'tarea'         => ['nullable', 'string', 'max:2000'],
            'cargo_id'      => ['nullable', 'integer'],
        ]);

        $cargo = isset($d['cargo_id']) ? CargoIa::find($d['cargo_id']) : null;

        return response()->json($recomendador->recomendar(
            $d['herramientas'] ?? ($cargo?->herramientas ?? []),
            ($cargo?->instrucciones ?? '') . ' ' . ($d['instrucciones'] ?? ''),
            $d['tarea'] ?? '',
            $cargo?->nivel_modelo,
        ));
    }

    public function versiones(string $tipo, int $id): JsonResponse
    {
        abort_unless(in_array($tipo, ['agente', 'cargo'], true), 404);

        return response()->json(
            PromptVersionIa::where('origen_tipo', $tipo)->where('origen_id', $id)->orderByDesc('version')->limit(30)->get()
                ->map(fn ($v) => [
                    'version' => $v->version, 'instrucciones' => $v->instrucciones, 'tarea' => $v->tarea, 'nota' => $v->nota,
                    'cuando'  => $v->created_at?->toIso8601String(), 'por' => User::withoutGlobalScopes()->find($v->cambiado_por)?->name,
                ])
        );
    }

    public function restaurarVersion(AgenteInterno $agente, int $version): JsonResponse
    {
        $v = PromptVersionIa::where('origen_tipo', 'agente')->where('origen_id', $agente->id)->where('version', $version)->firstOrFail();

        $agente->update(['instrucciones' => $v->instrucciones, 'tarea' => $v->tarea]);
        PromptVersionIa::registrar('agente', $agente->id, $v->instrucciones, $v->tarea, auth()->id(), "Restaurada la versión {$version}");

        return response()->json($this->agenteCompleto($agente->fresh()));
    }

    // ─── Corridas ────────────────────────────────────────────────────────────

    public function ejecutarAgente(AgenteInterno $agente, LanzadorAgentes $lanzador): JsonResponse
    {
        if ($this->pausado()) {
            return response()->json(['message' => 'El Equipo de IA está en pausa general. Reanúdalo para ejecutar agentes.'], 422);
        }

        if (! $agente->activo) {
            return response()->json(['message' => 'El agente está apagado. Actívalo, o usa «Probar», que corre sin escribir nada.'], 422);
        }

        $e = $lanzador->manual($agente, auth()->user());

        return response()->json(['ejecucion_id' => $e->id, 'estado' => $e->estado], 202);
    }

    /** La prueba en seco: corre con el agente apagado y sin guardar propuestas ni avisar a nadie. */
    public function probarAgente(Request $request, AgenteInterno $agente, LanzadorAgentes $lanzador): JsonResponse
    {
        $d = $request->validate(['tarea' => ['nullable', 'string', 'max:2000']]);

        $e = $lanzador->manual($agente, auth()->user(), $d['tarea'] ?? null, prueba: true);

        return response()->json(['ejecucion_id' => $e->id, 'estado' => $e->estado], 202);
    }

    public function ejecuciones(AgenteInterno $agente): JsonResponse
    {
        return response()->json($agente->ejecuciones()->latest('id')->limit(15)->get()->map(fn (AgenteEjecucion $e) => $this->ejecucionResumen($e)));
    }

    public function ejecucion(AgenteEjecucion $ejecucion): JsonResponse
    {
        $ejecucion->load(['registroPasos', 'propuestas']);

        return response()->json($this->ejecucionResumen($ejecucion) + [
            'resumen'    => $ejecucion->resumen,
            'tarea'      => $ejecucion->tarea,
            'pasos_log'  => $ejecucion->registroPasos->map(fn ($p) => [
                'orden' => $p->orden, 'tipo' => $p->tipo, 'herramienta' => $p->herramienta, 'argumentos' => $p->argumentos,
                'resumen' => $p->resumen, 'ms' => $p->ms,
            ]),
            'propuestas' => $ejecucion->propuestas->map(fn ($p) => $this->propuestaComoArray($p)),
        ]);
    }

    private function ejecucionResumen(AgenteEjecucion $e): array
    {
        return [
            'id'      => $e->id, 'agente_id' => $e->agente_id, 'estado' => $e->estado, 'origen' => $e->origen, 'prueba' => $e->prueba,
            'modelo'  => $e->modelo_usado, 'pasos' => $e->pasos, 'tokens_entrada' => $e->tokens_entrada, 'tokens_salida' => $e->tokens_salida,
            'error'   => $e->error, 'iniciada' => $e->iniciada_at?->toIso8601String(), 'terminada' => $e->terminada_at?->toIso8601String(),
            'creada'  => $e->created_at?->toIso8601String(),
        ];
    }

    // ─── Aprobaciones ────────────────────────────────────────────────────────

    public function propuestas(Request $request): JsonResponse
    {
        $estado = $request->query('estado', 'pendiente');

        $q = AgentePropuesta::with(['agente:id,nombre,avatar', 'destinatario:id,name', 'decididaPor:id,name'])
            ->when($estado !== 'todas', fn ($q) => $q->where('estado', $estado))
            ->latest('id')->limit(60);

        return response()->json($q->get()->map(fn ($p) => $this->propuestaComoArray($p)));
    }

    public function aprobar(AgentePropuesta $propuesta, AprobacionService $servicio): JsonResponse
    {
        $r = $servicio->aprobar($propuesta, auth()->user());

        return response()->json($r, $r['exito'] ? 200 : 422);
    }

    public function rechazar(Request $request, AgentePropuesta $propuesta, AprobacionService $servicio): JsonResponse
    {
        $d = $request->validate(['motivo' => ['nullable', 'string', 'max:500']]);

        $r = $servicio->rechazar($propuesta, auth()->user(), $d['motivo'] ?? null);

        return response()->json($r, $r['exito'] ? 200 : 422);
    }

    private function propuestaComoArray(AgentePropuesta $p): array
    {
        $accion = PropuestasAgenteService::ACCIONES[$p->accion] ?? null;

        return [
            'id'           => $p->id,
            'agente'       => $p->relationLoaded('agente') ? $p->agente?->nombre : null,
            'avatar'       => $p->relationLoaded('agente') ? $p->agente?->avatar : null,
            'accion'       => $p->accion,
            'etiqueta'     => $accion['etiqueta'] ?? $p->accion,
            'resumen'      => $p->resumen,
            'detalle'      => $p->parametros,
            'estado'       => $p->estado,
            'vencida'      => $p->estaVencida(),
            'vence'        => $p->vence_at?->toIso8601String(),
            'creada'       => $p->created_at?->toIso8601String(),
            'destinatario' => $p->relationLoaded('destinatario') ? $p->destinatario?->name : null,
            'decidida_por' => $p->relationLoaded('decididaPor') ? $p->decididaPor?->name : null,
            'motivo'       => $p->motivo,
            'resultado'    => $p->resultado,
            // Qué permiso extra exige aprobarla, para decírselo a quien no lo tiene antes de que toque el botón.
            'permiso'      => $accion['permiso'] ?? null,
            'puede'        => $accion ? (bool) auth()->user()?->tienePermiso($accion['permiso']) : false,
            'entidad_url'  => match ($p->entidad_type) {
                'cotizacion' => "/cotizaciones/{$p->entidad_id}",
                'crm_lead'   => "/crm/leads/{$p->entidad_id}",
                default      => null,
            },
        ];
    }

    // ─── Consumo ─────────────────────────────────────────────────────────────

    /**
     * Lo que gastó cada agente, en tokens. El dinero lo lleva el superadmin: aquí no se calcula.
     */
    public function consumo(Request $request): JsonResponse
    {
        $dias  = max(1, min(90, (int) $request->query('dias', 30)));
        $desde = now()->subDays($dias - 1)->toDateString();

        $porAgente = IaConsumo::where('origen', 'agentes')->where('fecha', '>=', $desde)
            ->select('agente_id', DB::raw('COUNT(*) as llamadas'), DB::raw('SUM(tokens_entrada) as entrada'), DB::raw('SUM(tokens_salida) as salida'), DB::raw('SUM(ok = 0) as fallidas'))
            ->groupBy('agente_id')->get();

        $nombres = AgenteInterno::withTrashed()->whereIn('id', $porAgente->pluck('agente_id'))->pluck('nombre', 'id');

        $porDia = IaConsumo::where('fecha', '>=', $desde)
            ->select('fecha', 'origen', DB::raw('SUM(tokens_entrada + tokens_salida) as tokens'))
            ->groupBy('fecha', 'origen')->orderBy('fecha')->get();

        return response()->json([
            'dias'       => $dias,
            'por_agente' => $porAgente->map(fn ($f) => [
                'agente' => $nombres[$f->agente_id] ?? 'Agente eliminado', 'llamadas' => (int) $f->llamadas,
                'entrada' => (int) $f->entrada, 'salida' => (int) $f->salida, 'fallidas' => (int) $f->fallidas,
            ])->sortByDesc('llamadas')->values(),
            'por_dia'    => $porDia->map(fn ($f) => ['fecha' => \Illuminate\Support\Carbon::parse($f->fecha)->toDateString(), 'origen' => $f->origen, 'tokens' => (int) $f->tokens])->values(),
            'nota'       => 'Son tokens. El costo y el cobro los lleva el panel de Briela.',
        ]);
    }
}
