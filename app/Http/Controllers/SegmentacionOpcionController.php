<?php

namespace App\Http\Controllers;

use App\Models\SegmentacionOpcion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SegmentacionOpcionController extends Controller
{
    public function index(): JsonResponse
    {
        $opciones = SegmentacionOpcion::orderBy('tipo')->orderBy('orden')->orderBy('etiqueta')->get();

        $agrupadas = $opciones->groupBy('tipo');

        return response()->json($agrupadas);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tipo'     => 'required|in:tipo_contacto,industria,proceso_seguimiento,fuente_contacto',
            'etiqueta' => 'required|string|max:100',
            'valor'    => 'nullable|string|max:80',
            'color'    => 'nullable|string|max:10',
            'orden'    => 'nullable|integer|min:0',
        ]);

        if (empty($data['valor'])) {
            $data['valor'] = \Illuminate\Support\Str::slug($data['etiqueta'], '_');
        }

        $data['orden'] = $data['orden'] ?? (SegmentacionOpcion::where('tipo', $data['tipo'])->max('orden') + 1);

        $opcion = SegmentacionOpcion::create($data);

        return response()->json($opcion, 201);
    }

    public function update(Request $request, SegmentacionOpcion $opcion): JsonResponse
    {
        $data = $request->validate([
            'etiqueta' => 'sometimes|required|string|max:100',
            'color'    => 'nullable|string|max:10',
            'orden'    => 'nullable|integer|min:0',
            'activo'   => 'nullable|boolean',
        ]);

        $opcion->update($data);

        return response()->json($opcion);
    }

    public function destroy(SegmentacionOpcion $opcion): JsonResponse
    {
        // Borrar «Mayorista» o «Distribuidor» no da error en ninguna parte: los
        // clientes que las tuvieran simplemente empezarían a cotizarse al precio
        // de cliente final. Un daño silencioso y difícil de rastrear después.
        if ($opcion->atada_a_precios) {
            return response()->json([
                'message' => "«{$opcion->etiqueta}» no se puede eliminar: el cotizador la usa para decidir el precio y la comisión. Si no la necesitas, desactívala.",
            ], 422);
        }

        $opcion->delete();

        return response()->json(['ok' => true]);
    }

    public function reordenar(Request $request): JsonResponse
    {
        $request->validate([
            'items'          => 'required|array',
            'items.*.id'     => 'required|integer|exists:segmentacion_opciones,id',
            'items.*.orden'  => 'required|integer|min:0',
        ]);

        foreach ($request->items as $item) {
            SegmentacionOpcion::where('id', $item['id'])->update(['orden' => $item['orden']]);
        }

        return response()->json(['ok' => true]);
    }
}
