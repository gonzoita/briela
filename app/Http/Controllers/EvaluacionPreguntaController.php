<?php

namespace App\Http\Controllers;

use App\Models\CursoEvaluacion;
use App\Models\EvaluacionOpcion;
use App\Models\EvaluacionPregunta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EvaluacionPreguntaController extends Controller
{
    public function store(Request $request, CursoEvaluacion $evaluacion): JsonResponse
    {
        $data = $request->validate($this->reglas());

        $pregunta = EvaluacionPregunta::create([
            'curso_evaluacion_id' => $evaluacion->id,
            'enunciado'           => $data['enunciado'],
            'tipo'                => $data['tipo'],
            'orden'               => ($evaluacion->preguntas()->max('orden') ?? 0) + 1,
        ]);

        $this->guardarOpciones($pregunta, $data);

        return response()->json($pregunta->load('opciones'), 201);
    }

    public function update(Request $request, CursoEvaluacion $evaluacion, EvaluacionPregunta $pregunta): JsonResponse
    {
        abort_if($pregunta->curso_evaluacion_id !== $evaluacion->id, 404);

        $data = $request->validate($this->reglas());

        $pregunta->update([
            'enunciado' => $data['enunciado'],
            'tipo'      => $data['tipo'],
        ]);

        $pregunta->opciones()->delete();
        $this->guardarOpciones($pregunta, $data);

        return response()->json($pregunta->load('opciones'));
    }

    public function destroy(CursoEvaluacion $evaluacion, EvaluacionPregunta $pregunta): JsonResponse
    {
        abort_if($pregunta->curso_evaluacion_id !== $evaluacion->id, 404);

        $pregunta->delete();

        return response()->json(['ok' => true]);
    }

    public function reordenar(Request $request, CursoEvaluacion $evaluacion): JsonResponse
    {
        $request->validate([
            'ids'   => 'required|array',
            'ids.*' => 'integer',
        ]);

        foreach ($request->ids as $orden => $id) {
            EvaluacionPregunta::where('id', $id)
                ->where('curso_evaluacion_id', $evaluacion->id)
                ->update(['orden' => $orden]);
        }

        return response()->json(['ok' => true]);
    }

    private function guardarOpciones(EvaluacionPregunta $pregunta, array $data): void
    {
        if ($pregunta->tipo !== 'opcion_multiple' || empty($data['opciones'])) {
            return;
        }

        foreach ($data['opciones'] as $orden => $opcion) {
            EvaluacionOpcion::create([
                'evaluacion_pregunta_id' => $pregunta->id,
                'texto'                  => $opcion['texto'],
                'es_correcta'            => (bool) ($opcion['es_correcta'] ?? false),
                'orden'                  => $orden,
            ]);
        }
    }

    private function reglas(): array
    {
        return [
            'enunciado'              => 'required|string',
            'tipo'                   => 'required|in:opcion_multiple,abierta',
            'opciones'               => 'required_if:tipo,opcion_multiple|nullable|array|min:2',
            'opciones.*.texto'       => 'required_with:opciones|string|max:255',
            'opciones.*.es_correcta' => 'nullable|boolean',
        ];
    }
}
