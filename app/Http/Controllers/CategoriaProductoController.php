<?php

namespace App\Http\Controllers;

use App\Models\CategoriaProducto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoriaProductoController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            CategoriaProducto::withCount('productos')
                ->orderBy('nombre')
                ->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre' => 'required|string|max:100|unique:categorias_producto,nombre',
            'color'  => 'nullable|string|max:7',
        ]);

        $categoria = CategoriaProducto::create([
            'nombre' => $data['nombre'],
            'color'  => $data['color'] ?? '#0A4283',
        ]);

        return response()->json($categoria->loadCount('productos'), 201);
    }

    public function update(Request $request, CategoriaProducto $categoria): JsonResponse
    {
        $data = $request->validate([
            'nombre' => 'required|string|max:100|unique:categorias_producto,nombre,' . $categoria->id,
            'color'  => 'nullable|string|max:7',
        ]);

        $categoria->update($data);

        return response()->json($categoria->loadCount('productos'));
    }

    public function destroy(CategoriaProducto $categoria): JsonResponse
    {
        $count = $categoria->productos()->count();

        if ($count > 0) {
            return response()->json([
                'message' => "No se puede eliminar: tiene {$count} producto(s) asignado(s).",
            ], 422);
        }

        $categoria->delete();

        return response()->json(['ok' => true]);
    }
}
