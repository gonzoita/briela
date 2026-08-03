<?php

namespace App\Http\Controllers;

use App\Models\Bodega;
use App\Models\Sede;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BodegaController extends Controller
{
    public function index()
    {
        return Inertia::render('Configuracion/Bodegas', [
            'bodegas' => Bodega::with('sede:id,nombre,codigo')
                ->orderBy('sede_id')
                ->orderByDesc('es_principal')
                ->orderBy('nombre')
                ->get(),
            'sedes' => Sede::activas()->orderByDesc('es_principal')->orderBy('nombre')->get(['id', 'nombre', 'codigo']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'sede_id'      => 'required|exists:sedes,id',
            'nombre'       => 'required|string|max:100',
            'tipo'         => 'required|in:general,produccion,exhibicion,otra',
            'es_principal' => 'boolean',
            'activa'       => 'boolean',
        ]);

        // La bodega principal es una por sede, no una global.
        if (!empty($data['es_principal'])) {
            Bodega::where('sede_id', $data['sede_id'])->update(['es_principal' => false]);
        }

        Bodega::create($data);

        return redirect()->back()->with('success', 'Bodega creada.');
    }

    public function update(Request $request, Bodega $bodega)
    {
        $data = $request->validate([
            'sede_id'      => 'required|exists:sedes,id',
            'nombre'       => 'required|string|max:100',
            'tipo'         => 'required|in:general,produccion,exhibicion,otra',
            'es_principal' => 'boolean',
            'activa'       => 'boolean',
        ]);

        if (!empty($data['es_principal'])) {
            Bodega::where('sede_id', $data['sede_id'])
                ->where('id', '!=', $bodega->id)
                ->update(['es_principal' => false]);
        }

        $bodega->update($data);

        return redirect()->back()->with('success', 'Bodega actualizada.');
    }

    public function destroy(Bodega $bodega)
    {
        $tieneStock = $bodega->stocks()->where('cantidad', '>', 0)->exists();
        $tieneMovimientos = \App\Models\ProductoMovimiento::where('bodega_id', $bodega->id)
            ->orWhere('bodega_destino_id', $bodega->id)
            ->exists();

        if ($tieneStock || $tieneMovimientos) {
            $bodega->update(['activa' => false]);
            return redirect()->back()->with('success', 'Bodega desactivada (tiene stock o movimientos).');
        }

        $bodega->delete();
        return redirect()->back()->with('success', 'Bodega eliminada.');
    }
}
