<?php

namespace App\Http\Controllers;

use App\Models\CrmEtapa;
use App\Models\CrmLead;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CrmReporteController extends Controller
{
    public function index(Request $request)
    {
        $periodo = $request->input('periodo', 'mes');
        $año     = (int) $request->input('año', now()->year);
        $mes     = (int) $request->input('mes', now()->month);

        if ($periodo === 'mes') {
            $desde = now()->setYear($año)->setMonth($mes)->startOfMonth();
            $hasta = now()->setYear($año)->setMonth($mes)->endOfMonth();
        } elseif ($periodo === 'trimestre') {
            $trimestre = (int) ceil($mes / 3);
            $desde = now()->setYear($año)->setMonth(($trimestre - 1) * 3 + 1)->startOfMonth();
            $hasta = now()->setYear($año)->setMonth($trimestre * 3)->endOfMonth();
        } else {
            $desde = now()->setYear($año)->startOfYear();
            $hasta = now()->setYear($año)->endOfYear();
        }

        // Los reportes del CRM también respetan la sede activa; con "Todas
        // las sedes" muestran el consolidado.
        $sedeActiva = \App\Support\ContextoSede::id();
        $deSede     = fn ($q) => $sedeActiva ? $q->where('sede_id', $sedeActiva) : $q;

        $porEstado = CrmLead::tap($deSede)->whereBetween('created_at', [$desde, $hasta])
            ->selectRaw('estado, count(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado');

        $porFuente = CrmLead::tap($deSede)->whereBetween('created_at', [$desde, $hasta])
            ->whereNotNull('fuente')
            ->where('fuente', '!=', '')
            ->selectRaw('fuente, count(*) as total')
            ->groupBy('fuente')
            ->orderByDesc('total')
            ->get();

        $porResponsable = CrmLead::tap($deSede)->whereBetween('created_at', [$desde, $hasta])
            ->with('responsable:id,name')
            ->selectRaw('responsable_id, count(*) as total, sum(estado = "ganado") as ganados')
            ->groupBy('responsable_id')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [
                'responsable' => $r->responsable?->name ?? 'Sin asignar',
                'total'       => (int) $r->total,
                'ganados'     => (int) $r->ganados,
                'conversion'  => $r->total > 0 ? round($r->ganados / $r->total * 100) : 0,
            ]);

        $porMes = CrmLead::tap($deSede)
            ->selectRaw('YEAR(created_at) as anio, MONTH(created_at) as mes_num, count(*) as total, sum(estado = "ganado") as ganados')
            ->where('created_at', '>=', now()->subMonths(6)->startOfMonth())
            ->groupByRaw('YEAR(created_at), MONTH(created_at)')
            ->orderByRaw('YEAR(created_at), MONTH(created_at)')
            ->get()
            ->map(fn ($r) => [
                'label'   => \Carbon\Carbon::createFromDate($r->anio, $r->mes_num, 1)->translatedFormat('M Y'),
                'total'   => (int) $r->total,
                'ganados' => (int) $r->ganados,
            ]);

        $totalPeriodo   = CrmLead::tap($deSede)->whereBetween('created_at', [$desde, $hasta])->count();
        $ganadosPeriodo = CrmLead::tap($deSede)->whereBetween('created_at', [$desde, $hasta])->where('estado', 'ganado')->count();
        $tasaConversion = $totalPeriodo > 0 ? round($ganadosPeriodo / $totalPeriodo * 100, 1) : 0;

        $porEtapa = CrmEtapa::withCount(['leads' => fn ($q) => $q->where('estado', 'activo')->tap($deSede)])
            ->orderBy('orden')
            ->get(['id', 'nombre', 'color', 'leads_count']);

        return Inertia::render('Crm/Reportes', [
            'porEstado'      => $porEstado,
            'porFuente'      => $porFuente,
            'porResponsable' => $porResponsable,
            'porMes'         => $porMes,
            'porEtapa'       => $porEtapa,
            'tasaConversion' => $tasaConversion,
            'totalPeriodo'   => $totalPeriodo,
            'ganadosPeriodo' => $ganadosPeriodo,
            'activos'        => CrmLead::tap($deSede)->where('estado', 'activo')->count(),
            'filtros'        => compact('periodo', 'año', 'mes'),
        ]);
    }
}
