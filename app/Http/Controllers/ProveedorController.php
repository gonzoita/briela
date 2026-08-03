<?php

namespace App\Http\Controllers;

use App\Models\Proveedor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProveedorController extends Controller
{
    public function index(Request $request): Response
    {
        $proveedores = Proveedor::query()
            ->when($request->filled('buscar'), fn ($q) => $q->where(function ($q) use ($request) {
                $q->where('nombre', 'like', "%{$request->buscar}%")
                  ->orWhere('nit', 'like', "%{$request->buscar}%")
                  ->orWhere('contacto', 'like', "%{$request->buscar}%")
                  ->orWhere('email', 'like', "%{$request->buscar}%");
            }))
            ->when($request->filled('tipo'), fn ($q) => $q->where('tipo', $request->tipo))
            ->when($request->filled('activo'), fn ($q) => $q->where('activo', $request->activo === 'true'))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Compras/Proveedores/Index', [
            'proveedores' => $proveedores,
            'filters'     => $request->only(['buscar', 'tipo', 'activo']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'nombre'    => 'required|string|max:255',
            'nit'       => 'nullable|string|max:20',
            'contacto'  => 'nullable|string|max:255',
            'telefono'  => 'nullable|string|max:20',
            'email'     => 'nullable|email|max:255',
            'ciudad'    => 'nullable|string|max:100',
            'direccion' => 'nullable|string|max:255',
            'tipo'      => 'required|in:materia_prima,insumos,mixto',
            'activo'    => 'boolean',
            'notas'     => 'nullable|string',
        ]);

        Proveedor::create($data);

        return back()->with('success', 'Proveedor creado correctamente.');
    }

    public function update(Request $request, Proveedor $proveedor): RedirectResponse
    {
        $data = $request->validate([
            'nombre'    => 'required|string|max:255',
            'nit'       => 'nullable|string|max:20',
            'contacto'  => 'nullable|string|max:255',
            'telefono'  => 'nullable|string|max:20',
            'email'     => 'nullable|email|max:255',
            'ciudad'    => 'nullable|string|max:100',
            'direccion' => 'nullable|string|max:255',
            'tipo'      => 'required|in:materia_prima,insumos,mixto',
            'activo'    => 'boolean',
            'notas'     => 'nullable|string',
        ]);

        $proveedor->update($data);

        return back()->with('success', 'Proveedor actualizado correctamente.');
    }

    public function destroy(Proveedor $proveedor): RedirectResponse
    {
        $proveedor->update(['activo' => false]);

        return back()->with('success', 'Proveedor desactivado.');
    }
}
