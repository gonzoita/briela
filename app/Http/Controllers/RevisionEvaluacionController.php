<?php

namespace App\Http\Controllers;

use App\Models\EvaluacionIntento;
use App\Services\EvaluacionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RevisionEvaluacionController extends Controller
{
    public function index(): Response
    {
        $intentos = EvaluacionIntento::with(['inscripcion.curso', 'inscripcion.inscribible'])
            ->where('estado', 'pendiente_revision')
            ->latest('completado_at')
            ->get()
            ->map(fn (EvaluacionIntento $intento) => [
                'id'             => $intento->id,
                'curso'          => $intento->inscripcion->curso->titulo,
                'estudiante'     => $intento->inscripcion->inscribible->name ?? $intento->inscripcion->inscribible->nombre,
                'completado_at'  => $intento->completado_at,
                'numero_intento' => $intento->numero_intento,
            ]);

        return Inertia::render('Capacitacion/RevisionEvaluaciones/Index', [
            'intentos' => $intentos,
        ]);
    }

    public function show(EvaluacionIntento $intento): Response
    {
        $intento->load(['inscripcion.curso', 'inscripcion.inscribible', 'evaluacion.preguntas.opciones']);

        $respuestas = collect($intento->respuestas)->keyBy('pregunta_id');

        $preguntas = $intento->evaluacion->preguntas->map(function ($pregunta) use ($respuestas) {
            $respuesta = $respuestas->get($pregunta->id);

            return [
                'id'              => $pregunta->id,
                'enunciado'       => $pregunta->enunciado,
                'tipo'            => $pregunta->tipo,
                'opciones'        => $pregunta->opciones,
                'opcion_id'       => $respuesta['opcion_id'] ?? null,
                'texto_respuesta' => $respuesta['texto_respuesta'] ?? null,
                'es_correcta'     => $pregunta->tipo === 'opcion_multiple'
                    ? ($pregunta->opciones->firstWhere('id', $respuesta['opcion_id'] ?? null)?->es_correcta ?? false)
                    : null,
            ];
        });

        return Inertia::render('Capacitacion/RevisionEvaluaciones/Show', [
            'intento'    => [
                'id'             => $intento->id,
                'curso'          => $intento->inscripcion->curso->titulo,
                'estudiante'     => $intento->inscripcion->inscribible->name ?? $intento->inscripcion->inscribible->nombre,
                'numero_intento' => $intento->numero_intento,
                'completado_at'  => $intento->completado_at,
            ],
            'preguntas' => $preguntas,
        ]);
    }

    public function calificar(Request $request, EvaluacionIntento $intento, EvaluacionService $evaluacionService): RedirectResponse
    {
        $data = $request->validate([
            'notas'                => 'required|array',
            'notas.*.pregunta_id'  => 'required|integer',
            'notas.*.correcta'     => 'required|boolean',
        ]);

        $evaluacionService->calificarManual($intento, $data['notas'], $request->user()->id);

        return redirect('/capacitacion/revision-evaluaciones')->with('success', 'Evaluación calificada correctamente.');
    }
}
