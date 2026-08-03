<?php

namespace App\Http\Controllers;

use App\Models\PlantillaCampo;
use App\Models\PlantillaEnsamble;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PlantillaCampoController extends Controller
{
    public function store(Request $request, PlantillaEnsamble $plantilla): JsonResponse
    {
        $tipoCampo = $request->tipo_campo ?? 'entrada';

        if ($tipoCampo === 'calculado') {
            $request->merge(['etiqueta' => $request->nombre, 'tipo' => 'decimal', 'requerido' => false]);
        }
        if ($tipoCampo === 'variable_instancia') {
            $request->merge([
                'requerido' => false,
                'etiqueta'  => $request->etiqueta ?: ($request->nombre ?? ''),
                'tipo'      => $request->tipo ?: 'texto',
            ]);
        }

        $data = $request->validate([
            'nombre'            => 'required|string|max:100',
            'etiqueta'          => 'nullable|string|max:150',
            'tipo_campo'        => 'nullable|in:entrada,calculado,variable_instancia',
            'tipo'              => 'required|in:texto,numero,decimal,select,boolean,checkbox',
            'subtipo_variable'  => 'nullable|string|in:numero,decimal,texto,selector',
            'opciones_selector' => 'nullable|array',
            'formula_calculo'   => 'nullable|string|max:10000',
            'opciones'          => 'nullable|array',
            'valor_defecto'     => 'nullable|string|max:255',
            'placeholder'       => 'nullable|string|max:255',
            'ayuda'             => 'nullable|string|max:500',
            'requerido'         => 'boolean',
            'orden'             => 'nullable|integer',
        ]);

        $data['tipo_campo'] = $tipoCampo;
        $data['orden']      = $plantilla->campos()->max('orden') + 1;

        $campo = $plantilla->campos()->create($data);

        return response()->json($campo, 201);
    }

    public function update(Request $request, PlantillaEnsamble $plantilla, PlantillaCampo $campo): JsonResponse
    {
        abort_if($campo->plantilla_id !== $plantilla->id, 404);

        $tipoCampo = $request->tipo_campo ?? 'entrada';

        if ($tipoCampo === 'calculado') {
            $request->merge(['etiqueta' => $request->nombre, 'tipo' => 'decimal', 'requerido' => false]);
        }
        if ($tipoCampo === 'variable_instancia') {
            $request->merge([
                'requerido' => false,
                'etiqueta'  => $request->etiqueta ?: ($request->nombre ?? ''),
                'tipo'      => $request->tipo ?: 'texto',
            ]);
        }

        $data = $request->validate([
            'nombre'                   => 'required|string|max:100',
            'etiqueta'                 => 'nullable|string|max:150',
            'tipo_campo'               => 'nullable|in:entrada,calculado,variable_instancia',
            'tipo'                     => 'required|in:texto,numero,decimal,select,boolean,checkbox',
            'subtipo_variable'         => 'nullable|string|in:numero,decimal,texto,selector',
            'opciones_selector'        => 'nullable|array',
            'formula_calculo'          => 'nullable|string|max:10000',
            'opciones'                 => 'nullable|array',
            'valor_defecto'            => 'nullable|string|max:255',
            'placeholder'              => 'nullable|string|max:255',
            'ayuda'                    => 'nullable|string|max:500',
            'requerido'                => 'boolean',
            'orden'                    => 'nullable|integer',
            'imagen_referencia_titulo' => 'nullable|string|max:255',
        ]);

        $data['tipo_campo'] = $tipoCampo;

        $campo->update($data);

        return response()->json($campo);
    }

    public function destroy(PlantillaEnsamble $plantilla, PlantillaCampo $campo): JsonResponse
    {
        abort_if($campo->plantilla_id !== $plantilla->id, 404);
        $campo->delete();

        return response()->json(['ok' => true]);
    }

    public function reordenar(Request $request, PlantillaEnsamble $plantilla): JsonResponse
    {
        $request->validate(['ids' => 'required|array', 'ids.*' => 'integer']);

        foreach ($request->ids as $orden => $id) {
            PlantillaCampo::where('id', $id)->where('plantilla_id', $plantilla->id)
                ->update(['orden' => $orden]);
        }

        return response()->json(['ok' => true]);
    }

    public function subirImagenReferencia(Request $request, PlantillaEnsamble $plantilla, PlantillaCampo $campo): JsonResponse
    {
        abort_if($campo->plantilla_id !== $plantilla->id, 404);

        $request->validate([
            'imagen' => 'required|image|max:5120',
            'titulo' => 'nullable|string|max:255',
        ]);

        if ($campo->imagen_referencia) {
            Storage::disk('public')->delete($campo->imagen_referencia);
        }

        $ruta = $request->file('imagen')->store('referencias/campos', 'public');

        $campo->update([
            'imagen_referencia'        => $ruta,
            'imagen_referencia_titulo' => $request->titulo,
        ]);

        return response()->json([
            'imagen_referencia'        => $ruta,
            'imagen_referencia_titulo' => $campo->imagen_referencia_titulo,
        ]);
    }

    public function eliminarImagenReferencia(PlantillaEnsamble $plantilla, PlantillaCampo $campo): JsonResponse
    {
        abort_if($campo->plantilla_id !== $plantilla->id, 404);

        if ($campo->imagen_referencia) {
            Storage::disk('public')->delete($campo->imagen_referencia);
        }

        $campo->update([
            'imagen_referencia'        => null,
            'imagen_referencia_titulo' => null,
        ]);

        return response()->json(['ok' => true]);
    }

    public function subirImagenOpcionSelector(Request $request, PlantillaEnsamble $plantilla, PlantillaCampo $campo): JsonResponse
    {
        abort_if($campo->plantilla_id !== $plantilla->id, 404);

        $request->validate([
            'imagen' => 'required|image|max:3072',
            'index'  => 'required|integer|min:0',
        ]);

        $ruta = $request->file('imagen')->store('referencias/selector-opciones', 'public');

        $opciones = $campo->opciones_selector ?? [];
        $idx      = $request->integer('index');

        if (isset($opciones[$idx])) {
            if (isset($opciones[$idx]['imagen'])) {
                Storage::disk('public')->delete($opciones[$idx]['imagen']);
            }
            $opciones[$idx]['imagen'] = $ruta;
        }

        $campo->update(['opciones_selector' => $opciones]);

        return response()->json([
            'opciones_selector' => $opciones,
            'url'               => asset('storage/' . $ruta),
        ]);
    }

    public function eliminarImagenOpcionSelector(Request $request, PlantillaEnsamble $plantilla, PlantillaCampo $campo): JsonResponse
    {
        abort_if($campo->plantilla_id !== $plantilla->id, 404);

        $request->validate(['index' => 'required|integer|min:0']);

        $opciones = $campo->opciones_selector ?? [];
        $idx      = $request->integer('index');

        if (isset($opciones[$idx]['imagen'])) {
            Storage::disk('public')->delete($opciones[$idx]['imagen']);
            $opciones[$idx]['imagen'] = null;
        }

        $campo->update(['opciones_selector' => $opciones]);

        return response()->json(['opciones_selector' => $opciones]);
    }
}
