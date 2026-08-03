<?php

namespace App\Http\Controllers;

use App\Models\Insumo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InsumoController extends Controller
{
    public function index(Request $request): Response
    {
        $insumos = Insumo::query()
            ->when($request->filled('buscar'), fn ($q) => $q->where('nombre', 'like', "%{$request->buscar}%"))
            ->orderBy('categoria')
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get();

        $agrupados = $insumos->groupBy('categoria')->map(fn ($items, $cat) => [
            'categoria' => $cat,
            'items'     => $items->values(),
        ])->values();

        return Inertia::render('Cotizadores/Insumos/Index', [
            'agrupados' => $agrupados,
            'filters'   => $request->only('buscar'),
            'esAdmin'   => auth()->user()->rol === 'administrador',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'categoria'    => 'required|string|max:50',
            'nombre'       => 'required|string|max:150|unique:insumos,nombre',
            'unidad'       => 'required|in:UN,ML,M2,KG,GL',
            'precio_costo' => 'required|numeric|min:0',
            'orden'        => 'nullable|integer',
        ]);

        Insumo::create($data);

        return back()->with('success', 'Insumo creado.');
    }

    public function update(Request $request, Insumo $insumo): RedirectResponse
    {
        $data = $request->validate([
            'precio_costo' => 'required|numeric|min:0',
            'nombre'       => 'sometimes|required|string|max:150|unique:insumos,nombre,' . $insumo->id,
            'categoria'    => 'sometimes|required|string|max:50',
            'unidad'       => 'sometimes|required|in:UN,ML,M2,KG,GL',
            'activo'       => 'sometimes|boolean',
        ]);

        $insumo->update($data);

        return back()->with('success', 'Insumo actualizado.');
    }

    public function destroy(Insumo $insumo): RedirectResponse
    {
        $insumo->update(['activo' => false]);

        return back()->with('success', 'Insumo desactivado.');
    }
}
