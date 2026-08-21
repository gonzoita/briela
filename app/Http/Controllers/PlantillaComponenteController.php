<?php

namespace App\Http\Controllers;

use App\Models\PlantillaComponente;
use App\Models\PlantillaEnsamble;
use App\Rules\ProductoSeleccionable;
use App\Services\FormulaEvaluatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlantillaComponenteController extends Controller
{
    public function store(Request $request, PlantillaEnsamble $plantilla): JsonResponse
    {
        $data = $request->validate([
            'producto_id'                 => ['required', new ProductoSeleccionable],
            'etiqueta'                    => 'nullable|string|max:200',
            'formula'                     => 'required|string|max:10000',
            'formula_real'                => 'nullable|string|max:10000',
            'sub_formulas'                => 'nullable|array',
            'sub_formulas.*.id'           => 'required|string',
            'sub_formulas.*.etiqueta'     => 'required|string|max:255',
            'sub_formulas.*.formula'      => 'required|string',
            'sub_formulas.*.formula_real' => 'nullable|string',
            'sub_formulas.*.unidad'       => 'nullable|string|max:50',
            'condicion'                   => 'nullable|string|max:10000',
            'unidad'                      => 'nullable|string|max:20',
            'incluir_en_precio'           => 'boolean',
            'visible_cliente'             => 'boolean',
            'visible_op'                  => 'boolean',
            'orden'                       => 'nullable|integer',
            'activo'                      => 'boolean',
            'notas'                       => 'nullable|string|max:255',
            'seccion'                     => 'nullable|string|max:100',
            // La sección tiene que ser de ESTA plantilla: una de otra deja el componente
            // guardado pero invisible, porque la lista agrupa por las secciones propias.
            'seccion_id'                  => ['nullable', 'integer', Rule::exists('plantilla_secciones', 'id')->where('plantilla_id', $plantilla->id)],
        ]);

        $comp = $plantilla->componentes()->create($data);
        $comp->load('producto');

        return response()->json($comp, 201);
    }

    public function update(Request $request, PlantillaEnsamble $plantilla, PlantillaComponente $componente): JsonResponse
    {
        abort_if($componente->plantilla_id !== $plantilla->id, 404);

        $data = $request->validate([
            'producto_id'                 => ['required', new ProductoSeleccionable],
            'etiqueta'                    => 'nullable|string|max:200',
            'formula'                     => 'required|string|max:10000',
            'formula_real'                => 'nullable|string|max:10000',
            'sub_formulas'                => 'nullable|array',
            'sub_formulas.*.id'           => 'required|string',
            'sub_formulas.*.etiqueta'     => 'required|string|max:255',
            'sub_formulas.*.formula'      => 'required|string',
            'sub_formulas.*.formula_real' => 'nullable|string',
            'sub_formulas.*.unidad'       => 'nullable|string|max:50',
            'condicion'                   => 'nullable|string|max:10000',
            'unidad'                      => 'nullable|string|max:20',
            'incluir_en_precio'           => 'boolean',
            'visible_cliente'             => 'boolean',
            'visible_op'                  => 'boolean',
            'orden'                       => 'nullable|integer',
            'activo'                      => 'boolean',
            'notas'                       => 'nullable|string|max:255',
            'seccion'                     => 'nullable|string|max:100',
            // La sección tiene que ser de ESTA plantilla: una de otra deja el componente
            // guardado pero invisible, porque la lista agrupa por las secciones propias.
            'seccion_id'                  => ['nullable', 'integer', Rule::exists('plantilla_secciones', 'id')->where('plantilla_id', $plantilla->id)],
        ]);

        $componente->update($data);

        return response()->json($componente->load('producto'));
    }

    public function destroy(PlantillaEnsamble $plantilla, PlantillaComponente $componente): JsonResponse
    {
        abort_if($componente->plantilla_id !== $plantilla->id, 404);
        $componente->delete();

        return response()->json(['ok' => true]);
    }

    public function reordenar(Request $request, PlantillaEnsamble $plantilla): JsonResponse
    {
        $request->validate(['ids' => 'required|array', 'ids.*' => 'integer']);

        foreach ($request->ids as $orden => $id) {
            PlantillaComponente::where('id', $id)->where('plantilla_id', $plantilla->id)
                ->update(['orden' => $orden]);
        }

        return response()->json(['ok' => true]);
    }

    public function probarSubFormula(Request $request, PlantillaEnsamble $plantilla): JsonResponse
    {
        $request->validate([
            'formula'   => 'required|string',
            'variables' => 'required|array',
        ]);

        $plantilla->load('campos');

        $camposCalculados = $plantilla->campos
            ->where('tipo_campo', 'calculado')
            ->sortBy('orden')
            ->map(fn ($c) => ['nombre' => $c->nombre, 'formula' => $c->formula_calculo])
            ->values()
            ->toArray();

        $resultado = app(FormulaEvaluatorService::class)->testFormula(
            $request->formula,
            $request->variables,
            $camposCalculados
        );

        return response()->json($resultado);
    }
}
