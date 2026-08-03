<?php

namespace App\Http\Controllers;

use App\Models\Curso;
use App\Models\CursoModulo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CursoModuloController extends Controller
{
    public function store(Request $request, Curso $curso): JsonResponse
    {
        $data = $request->validate([
            'nombre' => 'required|string|max:150',
        ]);

        $modulo = CursoModulo::create([
            'curso_id' => $curso->id,
            'nombre'   => $data['nombre'],
            'orden'    => ($curso->modulos()->max('orden') ?? 0) + 1,
        ]);

        return response()->json($modulo, 201);
    }

    public function update(Request $request, Curso $curso, CursoModulo $modulo): JsonResponse
    {
        abort_if($modulo->curso_id !== $curso->id, 404);

        $data = $request->validate([
            'nombre' => 'required|string|max:150',
        ]);

        $modulo->update($data);

        return response()->json($modulo);
    }

    public function destroy(Curso $curso, CursoModulo $modulo): JsonResponse
    {
        abort_if($modulo->curso_id !== $curso->id, 404);

        $modulo->delete();

        return response()->json(['ok' => true]);
    }

    public function reordenar(Request $request, Curso $curso): JsonResponse
    {
        $request->validate([
            'ids'   => 'required|array',
            'ids.*' => 'integer',
        ]);

        foreach ($request->ids as $orden => $id) {
            CursoModulo::where('id', $id)
                ->where('curso_id', $curso->id)
                ->update(['orden' => $orden]);
        }

        return response()->json(['ok' => true]);
    }
}
