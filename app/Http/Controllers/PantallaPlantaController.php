<?php

namespace App\Http\Controllers;

use App\Models\Configuracion;
use App\Models\Op;
use App\Models\OpItemTrabajoPaso;
use App\Models\PuntoColaborador;
use App\Services\PuntosColaboradorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PantallaPlantaController extends Controller
{
    private function trabajosProgramadosHoy(): \Illuminate\Support\Collection
    {
        $hoy = now()->toDateString();

        return OpItemTrabajoPaso::with([
                'trabajo.opItem.op.cliente',
                'trabajo.opItem.ensamble',
                'estacion',
                'colaboradorProgramado',
            ])
            ->where('fecha_programada', $hoy)
            ->orderBy('estacion_trabajo_id')
            ->get()
            ->groupBy('estacion_trabajo_id')
            ->map(fn ($pasos) => [
                'estacion'   => $pasos->first()->estacion?->nombre ?? 'Sin estación',
                'color'      => $pasos->first()->estacion?->color ?? '#0A4283',
                'pasos'      => $pasos->map(fn ($p) => [
                    'nombre'      => $p->nombre,
                    'op'          => $p->trabajo?->opItem?->op?->numero,
                    'cliente'     => $p->trabajo?->opItem?->op?->cliente?->nombre,
                    'colaborador' => $p->colaboradorProgramado?->name,
                    'completado'  => $p->completado,
                    'tiempo_est'  => $p->tiempo_estimado_minutos,
                ])->values(),
                'total'      => $pasos->count(),
                'completados'=> $pasos->where('completado', true)->count(),
            ])
            ->values();
    }

    public function show(Request $request, string $token): Response
    {
        $tokenValido = Configuracion::get('pantalla_planta_token');
        abort_if($token !== $tokenValido, 403, 'Token inválido');

        $svc = app(PuntosColaboradorService::class);

        $rankingSemanal = $svc->rankingSemanal()
            ->take(10)
            ->map(fn ($r) => [
                'operario_id'    => $r->operario_id,
                'nombre'         => $r->operario?->nombre,
                'nivel'          => $r->operario?->nivel ?? 'Bronce',
                'puntos_semana'  => $r->puntos_semana,
                'puntos_totales' => $r->operario?->puntos_totales ?? 0,
            ]);

        $opsActivas = Op::whereIn('estado', ['confirmada', 'en_produccion'])
            ->with('cliente')
            ->orderByDesc('updated_at')
            ->take(8)
            ->get()
            ->map(fn ($op) => [
                'numero'            => $op->numero,
                'cliente'           => $op->cliente?->nombre,
                'estado'            => $op->estado,
                'porcentaje_avance' => (float) $op->porcentaje_avance,
                'fecha_entrega'     => $op->fecha_entrega_estimada?->format('d/m/Y'),
            ]);

        $pasosHoy = OpItemTrabajoPaso::with(['operarios.operario'])
            ->whereDate('completado_at', today())
            ->where('completado', true)
            ->orderByDesc('completado_at')
            ->take(10)
            ->get()
            ->map(fn ($p) => [
                'nombre'        => $p->nombre,
                'completado_at' => $p->completado_at?->format('H:i'),
                'operarios'     => $p->operarios->map(fn ($o) => $o->operario?->nombre)->filter()->values(),
            ]);

        $topHoy = PuntoColaborador::with('operario')
            ->whereDate('created_at', today())
            ->where('puntos', '>', 0)
            ->selectRaw('operario_id, SUM(puntos) as puntos_hoy')
            ->groupBy('operario_id')
            ->orderByDesc('puntos_hoy')
            ->first();

        return Inertia::render('PantallaPlanta/Show', [
            'token'               => $token,
            'rankingSemanal'      => $rankingSemanal,
            'opsActivas'          => $opsActivas,
            'pasosHoy'            => $pasosHoy,
            'trabajosProgramados' => $this->trabajosProgramadosHoy(),
            'topHoy'              => $topHoy ? [
                'nombre'     => $topHoy->operario?->nombre,
                'puntos_hoy' => $topHoy->puntos_hoy,
                'nivel'      => $topHoy->operario?->nivel,
            ] : null,
            'hora'  => now()->format('H:i'),
            'fecha' => now()->isoFormat('dddd, D [de] MMMM [de] YYYY'),
        ]);
    }

    public function datos(Request $request, string $token): JsonResponse
    {
        $tokenValido = Configuracion::get('pantalla_planta_token');
        abort_if($token !== $tokenValido, 403);

        $svc = app(PuntosColaboradorService::class);

        return response()->json([
            'rankingSemanal' => $svc->rankingSemanal()->take(10)->map(fn ($r) => [
                'nombre'        => $r->operario?->nombre,
                'nivel'         => $r->operario?->nivel ?? 'Bronce',
                'puntos_semana' => $r->puntos_semana,
            ]),
            'opsActivas' => Op::whereIn('estado', ['confirmada', 'en_produccion'])
                ->with('cliente')
                ->orderByDesc('updated_at')
                ->take(8)
                ->get()
                ->map(fn ($op) => [
                    'numero'            => $op->numero,
                    'cliente'           => $op->cliente?->nombre,
                    'porcentaje_avance' => (float) $op->porcentaje_avance,
                    'fecha_entrega'     => $op->fecha_entrega_estimada?->format('d/m/Y'),
                ]),
            'trabajosProgramados' => $this->trabajosProgramadosHoy(),
            'hora' => now()->format('H:i'),
        ]);
    }
}
