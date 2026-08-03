<?php

namespace App\Http\Controllers;

use App\Models\EstacionTrabajo;
use App\Models\OpItemTrabajoPaso;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProgramadorController extends Controller
{
    public function index(Request $request): Response
    {
        $fecha = $request->fecha ?? now()->addDay()->toDateString();

        // El programador es de una fábrica concreta: estaciones y trabajos se
        // limitan a la sede activa.
        $sedeActiva = \App\Support\ContextoSede::id();
        $deLaSede   = fn ($q) => $sedeActiva ? $q->where('sede_id', $sedeActiva) : $q;

        $estaciones = EstacionTrabajo::where('activa', true)
            ->when($sedeActiva, fn ($q) => $q->where('sede_id', $sedeActiva))
            ->with(['equipos:id,estacion_trabajo_id,nombre,estado'])
            ->orderBy('orden')
            ->get();

        $pasosProgramados = OpItemTrabajoPaso::with([
                'trabajo.opItem.op.cliente',
                'trabajo.opItem.ensamble',
                'estacion',
                'colaboradorProgramado',
                'operarios.operario',
            ])
            ->when($sedeActiva, fn ($q) => $q->whereHas('trabajo.opItem.op', $deLaSede))
            ->where('fecha_programada', $fecha)
            ->get();

        $pasosSinProgramar = OpItemTrabajoPaso::with([
                'trabajo.opItem.op.cliente',
                'trabajo.opItem.ensamble',
            ])
            ->whereNull('fecha_programada')
            ->where('completado', false)
            ->whereHas('trabajo.opItem.op', fn($q) => $q->whereIn('estado', ['confirmada', 'en_produccion'])
                ->when($sedeActiva, fn ($q2) => $q2->where('sede_id', $sedeActiva)))
            ->orderBy('orden')
            ->get();

        $colaboradores = User::where('activo', true)
            ->orderBy('name')
            ->get(['id', 'name', 'rol']);

        return Inertia::render('Produccion/Programador/Index', [
            'fecha'             => $fecha,
            'estaciones'        => $estaciones,
            'pasosProgramados'  => $pasosProgramados,
            'pasosSinProgramar' => $pasosSinProgramar,
            'colaboradores'     => $colaboradores,
        ]);
    }

    public function programarPaso(Request $request, OpItemTrabajoPaso $paso): JsonResponse
    {
        $request->validate([
            'estacion_trabajo_id'       => 'nullable|exists:estaciones_trabajo,id',
            'fecha_programada'          => 'nullable|date',
            'colaborador_programado_id' => 'nullable|exists:users,id',
            'tiempo_estimado_minutos'   => 'nullable|integer|min:1',
        ]);

        $colaboradorAnterior = $paso->colaborador_programado_id;

        $paso->update($request->only([
            'estacion_trabajo_id',
            'fecha_programada',
            'colaborador_programado_id',
            'tiempo_estimado_minutos',
        ]));

        // Aviso al colaborador cuando se le asigna un trabajo (o se le
        // reasigna a otro). Solo si cambió, para no repetir el aviso al
        // reprogramar la fecha o la estación.
        $nuevo = $paso->colaborador_programado_id;
        if ($nuevo && $nuevo !== $colaboradorAnterior) {
            $paso->loadMissing('trabajo.opItem.op');
            $op = $paso->trabajo?->opItem?->op;
            $fecha = $paso->fecha_programada ? \Carbon\Carbon::parse($paso->fecha_programada)->format('d/m/Y') : null;

            app(\App\Services\NotificacionService::class)->crear(
                $nuevo,
                'trabajo_asignado',
                'Te asignaron un trabajo',
                trim(($paso->nombre ?? 'Paso') . ($op ? " · OP {$op->numero}" : '') . ($fecha ? " · para el {$fecha}" : '')),
                '/mi-panel',
            );
        }

        return response()->json([
            'ok'   => true,
            'paso' => $paso->fresh(['estacion', 'colaboradorProgramado']),
        ]);
    }

    public function desprogramarPaso(OpItemTrabajoPaso $paso): JsonResponse
    {
        $paso->update([
            'estacion_trabajo_id'       => null,
            'fecha_programada'          => null,
            'colaborador_programado_id' => null,
            'tiempo_estimado_minutos'   => null,
        ]);

        return response()->json(['ok' => true]);
    }

    public function datos(Request $request): JsonResponse
    {
        $fecha      = $request->fecha ?? now()->addDay()->toDateString();
        $sedeActiva = \App\Support\ContextoSede::id();

        $pasosProgramados = OpItemTrabajoPaso::with([
                'trabajo.opItem.op.cliente',
                'trabajo.opItem.ensamble',
                'estacion',
                'colaboradorProgramado',
                'operarios.operario',
            ])
            ->when($sedeActiva, fn ($q) => $q->whereHas('trabajo.opItem.op', fn ($q2) => $q2->where('sede_id', $sedeActiva)))
            ->where('fecha_programada', $fecha)
            ->get()
            ->map(fn($p) => [
                'id'                  => $p->id,
                'nombre'              => $p->nombre,
                'completado'          => $p->completado,
                'completado_at'       => $p->completado_at?->format('H:i'),
                'estacion_trabajo_id' => $p->estacion_trabajo_id,
                'colaborador'         => $p->colaboradorProgramado?->name,
                'tiempo_estimado'     => $p->tiempo_estimado_minutos,
                'op'                  => $p->trabajo?->opItem?->op?->numero,
                'cliente'             => $p->trabajo?->opItem?->op?->cliente?->nombre,
            ]);

        $pasosSinProgramar = OpItemTrabajoPaso::with([
                'trabajo.opItem.op.cliente',
                'trabajo.opItem.ensamble',
            ])
            ->whereNull('fecha_programada')
            ->where('completado', false)
            ->whereHas('trabajo.opItem.op', fn($q) =>
                $q->whereIn('estado', ['confirmada', 'en_produccion'])
                  ->when($sedeActiva, fn ($q2) => $q2->where('sede_id', $sedeActiva))
            )
            ->get()
            ->map(fn($p) => [
                'id'     => $p->id,
                'nombre' => $p->nombre,
                'op'     => $p->trabajo?->opItem?->op?->numero,
                'cliente'=> $p->trabajo?->opItem?->op?->cliente?->nombre,
            ]);

        return response()->json([
            'pasosProgramados'  => $pasosProgramados,
            'pasosSinProgramar' => $pasosSinProgramar,
            'timestamp'         => now()->timestamp,
        ]);
    }
}
