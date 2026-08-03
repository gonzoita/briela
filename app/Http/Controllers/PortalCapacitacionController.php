<?php

namespace App\Http\Controllers;

use App\Models\CapacitacionEstudiante;
use App\Models\Curso;
use App\Models\CursoEvaluacion;
use App\Models\CursoLeccion;
use App\Models\CursoModulo;
use App\Models\EvaluacionIntento;
use App\Models\Inscripcion;
use App\Models\ProgresoLeccion;
use App\Models\User;
use App\Services\EvaluacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PortalCapacitacionController extends Controller
{
    private function resolverEstudiante(Request $request): User|CapacitacionEstudiante
    {
        if (Auth::check()) {
            return $request->user();
        }

        $estudiante = Auth::guard('estudiante')->user();

        abort_if(! $estudiante, 403);

        return $estudiante;
    }

    private function tipoPublico(User|CapacitacionEstudiante $estudiante): string
    {
        return $estudiante instanceof User ? 'colaborador' : $estudiante->tipo;
    }

    public function index(Request $request): Response
    {
        $estudiante = $this->resolverEstudiante($request);
        $tipo       = $this->tipoPublico($estudiante);

        $inscripciones = $estudiante->inscripciones()->with('curso')->get();
        $cursoIds      = $inscripciones->pluck('curso_id');

        $pendientesObligatorios = $inscripciones
            ->where('obligatorio', true)
            ->whereNotIn('estado', ['completado', 'aprobado'])
            ->values()
            ->map(fn (Inscripcion $i) => $this->serializarInscripcion($i));

        $enProgreso = $inscripciones
            ->where('estado', 'en_progreso')
            ->where('obligatorio', false)
            ->values()
            ->map(fn (Inscripcion $i) => $this->serializarInscripcion($i));

        $completados = $inscripciones
            ->whereIn('estado', ['completado', 'aprobado'])
            ->values()
            ->map(fn (Inscripcion $i) => $this->serializarInscripcion($i));

        $catalogo = Curso::activos()
            ->paraPublico($tipo)
            ->whereNotIn('id', $cursoIds)
            ->latest()
            ->get();

        return Inertia::render('Portal/Index', [
            'pendientesObligatorios' => $pendientesObligatorios,
            'enProgreso'             => $enProgreso,
            'completados'            => $completados,
            'catalogo'               => $catalogo,
            'rutaBase'               => Auth::check() ? '/mi-capacitacion' : '/portal-capacitacion',
            'esColaborador'          => Auth::check(),
            'nombreEstudiante'       => $estudiante->name ?? $estudiante->nombre,
        ]);
    }

    public function show(Request $request, Curso $curso): Response
    {
        $estudiante = $this->resolverEstudiante($request);

        $inscripcion = Inscripcion::where('inscribible_id', $estudiante->id)
            ->where('inscribible_type', get_class($estudiante))
            ->where('curso_id', $curso->id)
            ->first();

        if (! $inscripcion) {
            abort_if($curso->obligatorio, 403, 'Debes ser asignado a este curso.');

            $inscripcion = Inscripcion::create([
                'inscribible_id'   => $estudiante->id,
                'inscribible_type' => get_class($estudiante),
                'curso_id'         => $curso->id,
                'obligatorio'      => false,
                'estado'           => 'pendiente',
            ]);
        }

        $curso->load('modulos.lecciones', 'modulos.evaluacion');

        $progreso = $inscripcion->progresos()->pluck('completado', 'curso_leccion_id');

        $modulos = $curso->modulos->map(function (CursoModulo $modulo) use ($inscripcion) {
            $evaluacionModulo = $modulo->evaluacion;

            return [
                'id'                  => $modulo->id,
                'nombre'              => $modulo->nombre,
                'orden'               => $modulo->orden,
                'lecciones'           => $modulo->lecciones,
                'bloqueado'           => ! $inscripcion->moduloDesbloqueado($modulo),
                'completado'          => $inscripcion->moduloCompletado($modulo),
                'tiene_evaluacion'    => $evaluacionModulo !== null,
                'evaluacion_aprobada' => $evaluacionModulo !== null && $this->estadoIntentos($inscripcion, $evaluacionModulo)['yaAprobado'],
            ];
        });

        return Inertia::render('Portal/CursoShow', [
            'curso'         => [
                'id'      => $curso->id,
                'titulo'  => $curso->titulo,
                'modulos' => $modulos,
            ],
            'inscripcion'   => $inscripcion,
            'progreso'      => $progreso,
            'tieneEvaluacion' => $curso->evaluacion()->exists(),
            'tieneCertificado' => $inscripcion->estado === 'aprobado' && $inscripcion->certificado()->exists(),
            'porcentaje'    => $inscripcion->porcentajeAvance(),
            'rutaBase'      => Auth::check() ? '/mi-capacitacion' : '/portal-capacitacion',
            'esColaborador' => Auth::check(),
        ]);
    }

    public function marcarLeccion(Request $request, Curso $curso, CursoLeccion $leccion): JsonResponse
    {
        $estudiante = $this->resolverEstudiante($request);

        abort_if($leccion->modulo->curso_id !== $curso->id, 404);

        $inscripcion = Inscripcion::where('inscribible_id', $estudiante->id)
            ->where('inscribible_type', get_class($estudiante))
            ->where('curso_id', $curso->id)
            ->firstOrFail();

        abort_if(! $inscripcion->moduloDesbloqueado($leccion->modulo), 403, 'Este módulo está bloqueado.');

        $inscripcion->marcarEnProgreso();

        ProgresoLeccion::updateOrCreate(
            ['inscripcion_id' => $inscripcion->id, 'curso_leccion_id' => $leccion->id],
            ['completado' => true, 'completado_at' => now()]
        );

        $totalLecciones = CursoLeccion::whereHas('modulo', fn ($q) => $q->where('curso_id', $curso->id))->count();
        $completadas    = $inscripcion->progresos()->where('completado', true)->count();

        if ($totalLecciones > 0 && $completadas >= $totalLecciones) {
            $inscripcion->marcarCompletado();
        }

        return response()->json([
            'estado'     => $inscripcion->fresh()->estado,
            'porcentaje' => $inscripcion->porcentajeAvance(),
        ]);
    }

    public function mostrarEvaluacion(Request $request, Curso $curso): Response|RedirectResponse
    {
        $estudiante = $this->resolverEstudiante($request);
        $rutaBase   = Auth::check() ? '/mi-capacitacion' : '/portal-capacitacion';

        $inscripcion = Inscripcion::where('inscribible_id', $estudiante->id)
            ->where('inscribible_type', get_class($estudiante))
            ->where('curso_id', $curso->id)
            ->firstOrFail();

        if ($inscripcion->porcentajeAvance() < 100) {
            return redirect("{$rutaBase}/{$curso->id}")->with('error', 'Debes completar todas las lecciones antes de presentar la evaluación.');
        }

        $evaluacion = $curso->evaluacion()->with('preguntas.opciones')->first();

        if (! $evaluacion) {
            return redirect("{$rutaBase}/{$curso->id}")->with('error', 'Este curso no tiene una evaluación configurada.');
        }

        ['intentosRealizados' => $intentosRealizados, 'ultimoIntento' => $ultimoIntento, 'yaAprobado' => $yaAprobado]
            = $this->estadoIntentos($inscripcion, $evaluacion);

        $sinIntentosDisponibles = $evaluacion->intentos_permitidos !== null
            && $intentosRealizados >= $evaluacion->intentos_permitidos
            && ! $yaAprobado;

        if ($sinIntentosDisponibles) {
            return redirect("{$rutaBase}/{$curso->id}")->with('error', 'Ya no cuentas con intentos disponibles para esta evaluación.');
        }

        return Inertia::render('Portal/Evaluacion', [
            'curso'             => ['id' => $curso->id, 'titulo' => $curso->titulo],
            'evaluacion'        => $this->serializarEvaluacionParaVista($evaluacion),
            'preguntas'         => $this->serializarPreguntas($evaluacion),
            'intentosRestantes' => $evaluacion->intentos_permitidos !== null
                ? max(0, $evaluacion->intentos_permitidos - $intentosRealizados)
                : null,
            'yaAprobado'     => $yaAprobado,
            'ultimoEstado'   => $ultimoIntento?->estado,
            'rutaBase'       => $rutaBase,
            'esFinal'        => true,
            'urlEnviar'      => "{$rutaBase}/{$curso->id}/evaluacion",
        ]);
    }

    public function enviarEvaluacion(Request $request, Curso $curso, EvaluacionService $evaluacionService): JsonResponse
    {
        $estudiante = $this->resolverEstudiante($request);

        $inscripcion = Inscripcion::where('inscribible_id', $estudiante->id)
            ->where('inscribible_type', get_class($estudiante))
            ->where('curso_id', $curso->id)
            ->firstOrFail();

        abort_if($inscripcion->porcentajeAvance() < 100, 403, 'Debes completar todas las lecciones primero.');

        $evaluacion = $curso->evaluacion;

        abort_if(! $evaluacion, 404);

        $data = $this->validarRespuestas($request);

        return $this->enviarRespuestas($inscripcion, $evaluacion, $data['respuestas'], $evaluacionService);
    }

    public function mostrarEvaluacionModulo(Request $request, Curso $curso, CursoModulo $modulo): Response|RedirectResponse
    {
        abort_if($modulo->curso_id !== $curso->id, 404);

        $estudiante = $this->resolverEstudiante($request);
        $rutaBase   = Auth::check() ? '/mi-capacitacion' : '/portal-capacitacion';

        $inscripcion = Inscripcion::where('inscribible_id', $estudiante->id)
            ->where('inscribible_type', get_class($estudiante))
            ->where('curso_id', $curso->id)
            ->firstOrFail();

        abort_if(! $inscripcion->moduloDesbloqueado($modulo), 403, 'Este módulo está bloqueado.');

        if (! $inscripcion->moduloCompletado($modulo)) {
            return redirect("{$rutaBase}/{$curso->id}")->with('error', 'Debes completar todas las lecciones del módulo antes de presentar la evaluación.');
        }

        $evaluacion = $modulo->evaluacion()->with('preguntas.opciones')->first();

        if (! $evaluacion) {
            return redirect("{$rutaBase}/{$curso->id}")->with('error', 'Este módulo no tiene una evaluación configurada.');
        }

        ['intentosRealizados' => $intentosRealizados, 'ultimoIntento' => $ultimoIntento, 'yaAprobado' => $yaAprobado]
            = $this->estadoIntentos($inscripcion, $evaluacion);

        $sinIntentosDisponibles = $evaluacion->intentos_permitidos !== null
            && $intentosRealizados >= $evaluacion->intentos_permitidos
            && ! $yaAprobado;

        if ($sinIntentosDisponibles) {
            return redirect("{$rutaBase}/{$curso->id}")->with('error', 'Ya no cuentas con intentos disponibles para esta evaluación.');
        }

        return Inertia::render('Portal/Evaluacion', [
            'curso'             => ['id' => $curso->id, 'titulo' => $modulo->nombre],
            'evaluacion'        => $this->serializarEvaluacionParaVista($evaluacion),
            'preguntas'         => $this->serializarPreguntas($evaluacion),
            'intentosRestantes' => $evaluacion->intentos_permitidos !== null
                ? max(0, $evaluacion->intentos_permitidos - $intentosRealizados)
                : null,
            'yaAprobado'     => $yaAprobado,
            'ultimoEstado'   => $ultimoIntento?->estado,
            'rutaBase'       => $rutaBase,
            'esFinal'        => false,
            'urlEnviar'      => "{$rutaBase}/{$curso->id}/modulos/{$modulo->id}/evaluacion",
        ]);
    }

    public function enviarEvaluacionModulo(Request $request, Curso $curso, CursoModulo $modulo, EvaluacionService $evaluacionService): JsonResponse
    {
        abort_if($modulo->curso_id !== $curso->id, 404);

        $estudiante = $this->resolverEstudiante($request);

        $inscripcion = Inscripcion::where('inscribible_id', $estudiante->id)
            ->where('inscribible_type', get_class($estudiante))
            ->where('curso_id', $curso->id)
            ->firstOrFail();

        abort_if(! $inscripcion->moduloDesbloqueado($modulo), 403, 'Este módulo está bloqueado.');
        abort_if(! $inscripcion->moduloCompletado($modulo), 403, 'Debes completar todas las lecciones del módulo primero.');

        $evaluacion = $modulo->evaluacion;

        abort_if(! $evaluacion, 404);

        $data = $this->validarRespuestas($request);

        return $this->enviarRespuestas($inscripcion, $evaluacion, $data['respuestas'], $evaluacionService);
    }

    private function validarRespuestas(Request $request): array
    {
        return $request->validate([
            'respuestas'                    => 'required|array',
            'respuestas.*.pregunta_id'      => 'required|integer',
            'respuestas.*.opcion_id'        => 'nullable|integer',
            'respuestas.*.texto_respuesta'  => 'nullable|string',
        ]);
    }

    private function enviarRespuestas(Inscripcion $inscripcion, CursoEvaluacion $evaluacion, array $respuestas, EvaluacionService $evaluacionService): JsonResponse
    {
        ['intentosRealizados' => $intentosRealizados, 'yaAprobado' => $yaAprobado] = $this->estadoIntentos($inscripcion, $evaluacion);

        abort_if(
            $evaluacion->intentos_permitidos !== null && ($intentosRealizados + 1) > $evaluacion->intentos_permitidos && ! $yaAprobado,
            403,
            'Ya no cuentas con intentos disponibles.'
        );

        $intento = EvaluacionIntento::create([
            'inscripcion_id'      => $inscripcion->id,
            'curso_evaluacion_id' => $evaluacion->id,
            'numero_intento'      => $intentosRealizados + 1,
            'respuestas'          => $respuestas,
            'completado_at'       => now(),
        ]);

        $evaluacionService->calificar($intento);
        $intento->refresh();

        return response()->json([
            'estado'   => $intento->estado,
            'aprobado' => $intento->aprobado,
            'nota'     => $intento->nota,
        ]);
    }

    private function estadoIntentos(Inscripcion $inscripcion, CursoEvaluacion $evaluacion): array
    {
        $intentosRealizados = EvaluacionIntento::where('inscripcion_id', $inscripcion->id)
            ->where('curso_evaluacion_id', $evaluacion->id)
            ->count();

        $ultimoIntento = EvaluacionIntento::where('inscripcion_id', $inscripcion->id)
            ->where('curso_evaluacion_id', $evaluacion->id)
            ->latest('id')
            ->first();

        $yaAprobado = EvaluacionIntento::where('inscripcion_id', $inscripcion->id)
            ->where('curso_evaluacion_id', $evaluacion->id)
            ->where('aprobado', true)
            ->exists();

        return compact('intentosRealizados', 'ultimoIntento', 'yaAprobado');
    }

    private function serializarEvaluacionParaVista(CursoEvaluacion $evaluacion): array
    {
        return [
            'id'                     => $evaluacion->id,
            'nombre'                 => $evaluacion->nombre,
            'nota_minima_aprobacion' => $evaluacion->nota_minima_aprobacion,
            'intentos_permitidos'    => $evaluacion->intentos_permitidos,
        ];
    }

    private function serializarPreguntas(CursoEvaluacion $evaluacion): array
    {
        return $evaluacion->preguntas->map(fn ($p) => [
            'id'        => $p->id,
            'enunciado' => $p->enunciado,
            'tipo'      => $p->tipo,
            'opciones'  => $p->opciones->map(fn ($o) => ['id' => $o->id, 'texto' => $o->texto]),
        ])->all();
    }

    public function descargarCertificado(Request $request, Curso $curso): StreamedResponse
    {
        $estudiante = $this->resolverEstudiante($request);

        $inscripcion = Inscripcion::where('inscribible_id', $estudiante->id)
            ->where('inscribible_type', get_class($estudiante))
            ->where('curso_id', $curso->id)
            ->firstOrFail();

        $certificado = $inscripcion->certificado;

        abort_if(! $certificado || ! $certificado->pdf_path, 404, 'Certificado no disponible.');

        return Storage::disk('public')->download($certificado->pdf_path, "certificado-{$curso->titulo}.pdf");
    }

    private function serializarInscripcion(Inscripcion $inscripcion): array
    {
        return [
            'id'            => $inscripcion->id,
            'curso'         => $inscripcion->curso,
            'estado'        => $inscripcion->estado,
            'fecha_limite'  => $inscripcion->fecha_limite,
            'porcentaje'    => $inscripcion->porcentajeAvance(),
            'tieneCertificado' => $inscripcion->estado === 'aprobado' && $inscripcion->certificado()->exists(),
        ];
    }
}
