<?php

namespace App\Http\Controllers;

use App\Models\Curso;
use App\Models\CursoModulo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CursoEvaluacionController extends Controller
{
    public function guardar(Request $request, Curso $curso): JsonResponse
    {
        $data = $this->datosValidados($request);

        $evaluacion = $curso->evaluacion()->updateOrCreate(
            ['curso_id' => $curso->id],
            array_merge($data, ['nombre' => $data['nombre'] ?? 'Evaluación final'])
        );

        return response()->json($evaluacion);
    }

    public function guardarModulo(Request $request, Curso $curso, CursoModulo $modulo): JsonResponse
    {
        abort_if($modulo->curso_id !== $curso->id, 404);

        $data = $this->datosValidados($request);

        $evaluacion = $modulo->evaluacion()->updateOrCreate(
            ['curso_modulo_id' => $modulo->id],
            array_merge($data, ['nombre' => $data['nombre'] ?? "Evaluación de {$modulo->nombre}"])
        );

        return response()->json($evaluacion);
    }

    private function datosValidados(Request $request): array
    {
        $data = $request->validate([
            'nombre'                    => 'nullable|string|max:150',
            'nota_minima_aprobacion'    => 'required|integer|min:0|max:100',
            'intentos_permitidos'       => 'nullable|integer|min:1',
            'requiere_revision_manual'  => 'nullable|boolean',
        ]);

        return [
            'nombre'                   => $data['nombre'] ?? null,
            'nota_minima_aprobacion'   => $data['nota_minima_aprobacion'],
            'intentos_permitidos'      => $data['intentos_permitidos'] ?? null,
            'requiere_revision_manual' => (bool) ($request->boolean('requiere_revision_manual')),
        ];
    }
}
