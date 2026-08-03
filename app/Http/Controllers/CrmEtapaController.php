<?php

namespace App\Http\Controllers;

use App\Models\CrmEtapa;
use Illuminate\Http\Request;

class CrmEtapaController extends Controller
{
    public function index()
    {
        return response()->json(['etapas' => CrmEtapa::orderBy('orden')->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre'            => 'required|string|max:100',
            'color'             => 'required|string|max:7',
            'accion_automatica' => 'required|in:ninguna,cotizacion,op',
            'es_ganado'         => 'boolean',
            'es_perdido'        => 'boolean',
        ]);

        $orden = CrmEtapa::max('orden') + 1;
        $etapa = CrmEtapa::create([...$data, 'orden' => $orden]);
        return response()->json(['etapa' => $etapa]);
    }

    public function update(Request $request, CrmEtapa $etapa)
    {
        $data = $request->validate([
            'nombre'            => 'required|string|max:100',
            'color'             => 'required|string|max:7',
            'accion_automatica' => 'required|in:ninguna,cotizacion,op',
            'es_ganado'         => 'boolean',
            'es_perdido'        => 'boolean',
            'activa'            => 'boolean',
        ]);

        $etapa->update($data);
        return response()->json(['etapa' => $etapa->fresh()]);
    }

    public function reordenar(Request $request)
    {
        foreach ($request->orden as $item) {
            CrmEtapa::where('id', $item['id'])->update(['orden' => $item['orden']]);
        }
        return response()->json(['ok' => true]);
    }

    public function destroy(CrmEtapa $etapa)
    {
        if ($etapa->leads()->count() > 0) {
            return response()->json(
                ['error' => 'No se puede eliminar una etapa con leads activos'],
                422
            );
        }
        $etapa->delete();
        return response()->json(['ok' => true]);
    }
}
