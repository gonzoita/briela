<?php

namespace App\Http\Controllers;

use App\Models\EquipoMantenimiento;
use App\Models\EquipoComponente;
use App\Models\EstacionTrabajo;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Carbon\Carbon;

class EquipoController extends Controller
{
    public function index(Request $request)
    {
        $query = EquipoMantenimiento::with(['estacion', 'ultimoMantenimiento'])
            ->withCount('mantenimientos');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('nombre', 'like', "%$s%")
                  ->orWhere('codigo', 'like', "%$s%")
                  ->orWhere('tipo', 'like', "%$s%")
                  ->orWhere('ubicacion', 'like', "%$s%");
            });
        }

        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }

        if ($request->filled('estacion_id')) {
            $query->where('estacion_trabajo_id', $request->estacion_id);
        }

        $equipos = $query->orderBy('nombre')->get();

        return Inertia::render('Mantenimiento/Equipos/Index', [
            'equipos'    => $equipos,
            'filters'    => $request->only(['search', 'estado', 'estacion_id']),
            'estaciones' => EstacionTrabajo::where('activa', true)->orderBy('orden')->get(['id', 'nombre', 'color']),
        ]);
    }

    public function create()
    {
        return Inertia::render('Mantenimiento/Equipos/Create', [
            'estaciones' => EstacionTrabajo::where('activa', true)->orderBy('orden')->get(['id', 'nombre', 'color']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre'              => 'required|string|max:200',
            'codigo'              => 'nullable|string|max:50|unique:equipos_mantenimiento,codigo',
            'tipo'                => 'nullable|string|max:50',
            'marca'               => 'nullable|string|max:100',
            'modelo'              => 'nullable|string|max:100',
            'serial'              => 'nullable|string|max:100',
            'fecha_instalacion'   => 'nullable|date',
            'frecuencia_dias'     => 'integer|min:1',
            'ubicacion'           => 'nullable|string|max:200',
            'estacion_trabajo_id' => 'nullable|exists:estaciones_trabajo,id',
            'responsable'         => 'nullable|string|max:200',
            'observaciones'       => 'nullable|string',
            'estado'              => 'in:activo,en_mantenimiento,fuera_servicio',
            'componentes'               => 'array',
            'componentes.*.nombre'      => 'required|string',
            'componentes.*.referencia'  => 'nullable|string',
            'componentes.*.unidad'      => 'nullable|string',
            'componentes.*.cantidad'    => 'numeric|min:0',
            'componentes.*.descripcion' => 'nullable|string',
        ]);

        $componentes = $data['componentes'] ?? [];
        unset($data['componentes']);

        if (!empty($data['fecha_instalacion'])) {
            $data['proxima_revision'] = Carbon::parse($data['fecha_instalacion'])
                ->addDays($data['frecuencia_dias']);
        }

        $equipo = EquipoMantenimiento::create($data);

        foreach ($componentes as $comp) {
            $equipo->componentes()->create($comp);
        }

        return redirect('/mantenimiento/equipos/' . $equipo->id)
            ->with('toast', ['type' => 'success', 'msg' => 'Equipo registrado.']);
    }

    public function show(EquipoMantenimiento $equipo)
    {
        $equipo->load(['estacion', 'componentes', 'mantenimientos.repuestos', 'mantenimientos.registradoPor']);
        return Inertia::render('Mantenimiento/Equipos/Show', [
            'equipo' => $equipo,
        ]);
    }

    public function edit(EquipoMantenimiento $equipo)
    {
        $equipo->load('componentes');
        return Inertia::render('Mantenimiento/Equipos/Edit', [
            'equipo'     => $equipo,
            'estaciones' => EstacionTrabajo::where('activa', true)->orderBy('orden')->get(['id', 'nombre', 'color']),
        ]);
    }

    public function update(Request $request, EquipoMantenimiento $equipo)
    {
        $data = $request->validate([
            'nombre'              => 'required|string|max:200',
            'codigo'              => 'nullable|string|max:50|unique:equipos_mantenimiento,codigo,' . $equipo->id,
            'tipo'                => 'nullable|string|max:50',
            'marca'               => 'nullable|string|max:100',
            'modelo'              => 'nullable|string|max:100',
            'serial'              => 'nullable|string|max:100',
            'fecha_instalacion'   => 'nullable|date',
            'frecuencia_dias'     => 'integer|min:1',
            'ubicacion'           => 'nullable|string|max:200',
            'estacion_trabajo_id' => 'nullable|exists:estaciones_trabajo,id',
            'responsable'         => 'nullable|string|max:200',
            'observaciones'       => 'nullable|string',
            'estado'              => 'in:activo,en_mantenimiento,fuera_servicio',
            'componentes'               => 'array',
            'componentes.*.nombre'      => 'required|string',
            'componentes.*.referencia'  => 'nullable|string',
            'componentes.*.unidad'      => 'nullable|string',
            'componentes.*.cantidad'    => 'numeric|min:0',
            'componentes.*.descripcion' => 'nullable|string',
        ]);

        $componentes = $data['componentes'] ?? [];
        unset($data['componentes']);

        if (!empty($data['fecha_instalacion'])) {
            $data['proxima_revision'] = Carbon::parse($data['fecha_instalacion'])
                ->addDays($data['frecuencia_dias']);
        }

        $equipo->update($data);
        $equipo->componentes()->delete();
        foreach ($componentes as $comp) {
            $equipo->componentes()->create($comp);
        }

        return redirect('/mantenimiento/equipos/' . $equipo->id)
            ->with('toast', ['type' => 'success', 'msg' => 'Equipo actualizado.']);
    }

    public function destroy(EquipoMantenimiento $equipo)
    {
        $equipo->delete();
        return redirect('/mantenimiento/equipos')
            ->with('toast', ['type' => 'success', 'msg' => 'Equipo eliminado.']);
    }

    public function reasignar(Request $request, EquipoMantenimiento $equipo)
    {
        $data = $request->validate([
            'responsable' => 'required|string|max:200',
            'ubicacion'   => 'nullable|string|max:200',
        ]);
        $equipo->update($data);
        return back()->with('toast', ['type' => 'success', 'msg' => 'Responsable actualizado.']);
    }
}
