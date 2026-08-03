<?php

namespace App\Http\Controllers;

use App\Models\PlantillaComponente;
use App\Models\PlantillaEnsamble;
use App\Models\PlantillaSeccion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlantillaSeccionController extends Controller
{
    public function store(Request $request, PlantillaEnsamble $plantilla): JsonResponse
    {
        $data = $request->validate([
            'nombre' => 'required|string|max:150',
            'orden'  => 'nullable|integer',
        ]);

        $data['plantilla_id'] = $plantilla->id;
        $data['orden']        = $data['orden'] ?? ($plantilla->secciones()->max('orden') + 1);

        $seccion = PlantillaSeccion::create($data);

        return response()->json($seccion, 201);
    }

    public function update(Request $request, PlantillaEnsamble $plantilla, PlantillaSeccion $seccion): JsonResponse
    {
        abort_if($seccion->plantilla_id !== $plantilla->id, 404);

        $data = $request->validate([
            'nombre' => 'required|string|max:150',
        ]);

        $seccion->update($data);

        return response()->json($seccion);
    }

    public function destroy(PlantillaEnsamble $plantilla, PlantillaSeccion $seccion): JsonResponse
    {
        abort_if($seccion->plantilla_id !== $plantilla->id, 404);

        PlantillaComponente::where('seccion_id', $seccion->id)->update(['seccion_id' => null]);
        $seccion->delete();

        return response()->json(['ok' => true]);
    }

    public function reordenar(Request $request, PlantillaEnsamble $plantilla): JsonResponse
    {
        $request->validate([
            'ids'   => 'required|array',
            'ids.*' => 'integer',
        ]);

        foreach ($request->ids as $orden => $id) {
            PlantillaSeccion::where('id', $id)
                ->where('plantilla_id', $plantilla->id)
                ->update(['orden' => $orden]);
        }

        return response()->json(['ok' => true]);
    }

    public function moverComponente(Request $request, PlantillaEnsamble $plantilla, PlantillaComponente $componente): JsonResponse
    {
        abort_if($componente->plantilla_id !== $plantilla->id, 404);

        $request->validate([
            'seccion_id' => 'nullable|integer',
            'orden'      => 'nullable|integer',
        ]);

        if ($request->filled('seccion_id')) {
            $seccion = PlantillaSeccion::find($request->seccion_id);
            abort_if(!$seccion || $seccion->plantilla_id !== $plantilla->id, 422, 'La sección no pertenece a esta plantilla.');
        }

        $updates = ['seccion_id' => $request->seccion_id];
        if ($request->has('orden') && $request->orden !== null) {
            $updates['orden'] = $request->orden;
        }

        $componente->update($updates);

        return response()->json($componente->load('producto'));
    }
}
