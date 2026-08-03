<?php

namespace App\Http\Controllers;

use App\Models\EquipoMantenimiento;
use App\Models\Mantenimiento;
use App\Models\MantenimientoRepuesto;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Carbon\Carbon;

class MantenimientoController extends Controller
{
    public function dashboard()
    {
        $hoy = Carbon::today();

        // Los equipos son físicos: se ven los de la sede activa.
        $equipos = \App\Support\ContextoSede::aplicar(EquipoMantenimiento::query())
            ->withCount('mantenimientos')
            ->with('ultimoMantenimiento')
            ->get();

        $idsEquipos = $equipos->pluck('id');

        $vencidos    = $equipos->filter(fn($e) => $e->proxima_revision && $e->proxima_revision->lt($hoy))->values();
        $proximos7   = $equipos->filter(fn($e) => $e->proxima_revision && $e->proxima_revision->between($hoy, $hoy->copy()->addDays(7)))->values();
        $enProceso   = Mantenimiento::where('estado', 'en_proceso')->whereIn('equipo_id', $idsEquipos)->with('equipo')->get();
        $completados = Mantenimiento::where('estado', 'completado')->whereIn('equipo_id', $idsEquipos)->whereMonth('fecha_fin', $hoy->month)->count();

        return Inertia::render('Mantenimiento/Dashboard', [
            'stats' => [
                'total_equipos'      => $equipos->count(),
                'activos'            => $equipos->where('estado', 'activo')->count(),
                'en_mantenimiento'   => $equipos->where('estado', 'en_mantenimiento')->count(),
                'fuera_servicio'     => $equipos->where('estado', 'fuera_servicio')->count(),
                'completados_mes'    => $completados,
            ],
            'alertas'   => $vencidos,
            'proximos'  => $proximos7,
            'en_proceso'=> $enProceso,
        ]);
    }

    public function index(Request $request)
    {
        $equiposDeSede = \App\Support\ContextoSede::aplicar(EquipoMantenimiento::query())->pluck('id');

        $query = Mantenimiento::with(['equipo', 'registradoPor'])
            ->whereIn('equipo_id', $equiposDeSede)
            ->withCount('repuestos');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->whereHas('equipo', fn($q) => $q->where('nombre', 'like', "%$s%"));
        }

        if ($request->filled('tipo'))   $query->where('tipo', $request->tipo);
        if ($request->filled('estado')) $query->where('estado', $request->estado);
        if ($request->filled('equipo')) $query->where('equipo_id', $request->equipo);

        $mantenimientos = $query->orderByDesc('fecha_programada')->get();
        $equipos = \App\Support\ContextoSede::aplicar(EquipoMantenimiento::query())
            ->orderBy('nombre')->get(['id', 'nombre']);

        return Inertia::render('Mantenimiento/Mantenimientos/Index', [
            'mantenimientos' => $mantenimientos,
            'equipos'        => $equipos,
            'filters'        => $request->only(['search', 'tipo', 'estado', 'equipo']),
        ]);
    }

    public function create()
    {
        $equipos = \App\Support\ContextoSede::aplicar(EquipoMantenimiento::query())
            ->where('estado', '!=', 'fuera_servicio')
            ->orderBy('nombre')->get(['id', 'nombre', 'tipo', 'ubicacion']);

        return Inertia::render('Mantenimiento/Mantenimientos/Create', [
            'equipos' => $equipos,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'equipo_id'        => 'required|exists:equipos_mantenimiento,id',
            'tipo'             => 'required|in:preventivo,correctivo,predictivo',
            'estado'           => 'required|in:programado,en_proceso,completado,cancelado',
            'fecha_programada' => 'required|date',
            'fecha_inicio'     => 'nullable|date',
            'fecha_fin'        => 'nullable|date',
            'ejecutor_tipo'    => 'required|in:interno,externo',
            'ejecutor_nombre'  => 'nullable|string|max:200',
            'descripcion'      => 'nullable|string',
            'hallazgos'        => 'nullable|string',
            'acciones'         => 'nullable|string',
            'costo_mano_obra'  => 'numeric|min:0',
            'tiempo_horas'     => 'nullable|integer|min:0',
            'repuestos'        => 'array',
            'repuestos.*.nombre'          => 'required|string',
            'repuestos.*.referencia'      => 'nullable|string',
            'repuestos.*.unidad'          => 'nullable|string',
            'repuestos.*.cantidad'        => 'numeric|min:0',
            'repuestos.*.precio_unitario' => 'numeric|min:0',
        ]);

        $repuestos = $data['repuestos'] ?? [];
        unset($data['repuestos']);

        $costoRepuestos = collect($repuestos)->sum(fn($r) => ($r['cantidad'] ?? 0) * ($r['precio_unitario'] ?? 0));
        $data['costo_repuestos'] = $costoRepuestos;
        $data['costo_total']     = ($data['costo_mano_obra'] ?? 0) + $costoRepuestos;
        $data['registrado_por']  = auth()->id();

        $mant = Mantenimiento::create($data);

        foreach ($repuestos as $rep) {
            $rep['subtotal'] = ($rep['cantidad'] ?? 0) * ($rep['precio_unitario'] ?? 0);
            $mant->repuestos()->create($rep);
        }

        // Si se completa, actualizar próxima revisión del equipo
        if ($data['estado'] === 'completado' && !empty($data['fecha_fin'])) {
            $equipo = EquipoMantenimiento::find($data['equipo_id']);
            $equipo->update([
                'proxima_revision' => Carbon::parse($data['fecha_fin'])->addDays($equipo->frecuencia_dias),
                'estado'           => 'activo',
            ]);
        }

        return redirect('/mantenimiento/mantenimientos/' . $mant->id)
            ->with('toast', ['type' => 'success', 'msg' => 'Mantenimiento registrado.']);
    }

    public function show(Mantenimiento $mantenimiento)
    {
        $mantenimiento->load(['equipo', 'repuestos', 'registradoPor']);
        return Inertia::render('Mantenimiento/Mantenimientos/Show', [
            'mantenimiento' => $mantenimiento,
        ]);
    }

    public function edit(Mantenimiento $mantenimiento)
    {
        $mantenimiento->load('repuestos');
        $equipos = EquipoMantenimiento::orderBy('nombre')->get(['id', 'nombre', 'tipo', 'ubicacion']);

        return Inertia::render('Mantenimiento/Mantenimientos/Edit', [
            'mantenimiento' => $mantenimiento,
            'equipos'       => $equipos,
        ]);
    }

    public function update(Request $request, Mantenimiento $mantenimiento)
    {
        $data = $request->validate([
            'equipo_id'        => 'required|exists:equipos_mantenimiento,id',
            'tipo'             => 'required|in:preventivo,correctivo,predictivo',
            'estado'           => 'required|in:programado,en_proceso,completado,cancelado',
            'fecha_programada' => 'required|date',
            'fecha_inicio'     => 'nullable|date',
            'fecha_fin'        => 'nullable|date',
            'ejecutor_tipo'    => 'required|in:interno,externo',
            'ejecutor_nombre'  => 'nullable|string|max:200',
            'descripcion'      => 'nullable|string',
            'hallazgos'        => 'nullable|string',
            'acciones'         => 'nullable|string',
            'costo_mano_obra'  => 'numeric|min:0',
            'tiempo_horas'     => 'nullable|integer|min:0',
            'repuestos'        => 'array',
            'repuestos.*.nombre'          => 'required|string',
            'repuestos.*.referencia'      => 'nullable|string',
            'repuestos.*.unidad'          => 'nullable|string',
            'repuestos.*.cantidad'        => 'numeric|min:0',
            'repuestos.*.precio_unitario' => 'numeric|min:0',
        ]);

        $repuestos = $data['repuestos'] ?? [];
        unset($data['repuestos']);

        $costoRepuestos = collect($repuestos)->sum(fn($r) => ($r['cantidad'] ?? 0) * ($r['precio_unitario'] ?? 0));
        $data['costo_repuestos'] = $costoRepuestos;
        $data['costo_total']     = ($data['costo_mano_obra'] ?? 0) + $costoRepuestos;

        $mantenimiento->update($data);
        $mantenimiento->repuestos()->delete();
        foreach ($repuestos as $rep) {
            $rep['subtotal'] = ($rep['cantidad'] ?? 0) * ($rep['precio_unitario'] ?? 0);
            $mantenimiento->repuestos()->create($rep);
        }

        if ($data['estado'] === 'completado' && !empty($data['fecha_fin'])) {
            $equipo = EquipoMantenimiento::find($data['equipo_id']);
            $equipo->update([
                'proxima_revision' => Carbon::parse($data['fecha_fin'])->addDays($equipo->frecuencia_dias),
                'estado'           => 'activo',
            ]);
        }

        return redirect('/mantenimiento/mantenimientos/' . $mantenimiento->id)
            ->with('toast', ['type' => 'success', 'msg' => 'Mantenimiento actualizado.']);
    }

    public function destroy(Mantenimiento $mantenimiento)
    {
        $mantenimiento->repuestos()->delete();
        $mantenimiento->delete();
        return redirect('/mantenimiento/mantenimientos')
            ->with('toast', ['type' => 'success', 'msg' => 'Registro eliminado.']);
    }
}
