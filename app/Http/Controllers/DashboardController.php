<?php

namespace App\Http\Controllers;

use App\Models\Cotizacion;
use App\Models\EquipoMantenimiento;
use App\Models\Mantenimiento;
use App\Models\Op;
use App\Support\ContextoSede;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        $user = auth()->user();
        $hoy  = now()->toDateString();

        // ─── Métricas OPs ────────────────────────────────────────────────────
        // Nota: hasta julio 2026 esto leía del modelo OrdenProduccion, una
        // tabla abandonada desde que se migró a Op/OpItem/Trabajos — las
        // métricas nunca reflejaban la producción real. Corregido para usar
        // el modelo Op, que es el que usa todo el resto del sistema.
        //
        // Y hasta esta misma fecha tampoco filtraba por sede: el encabezado
        // decía "Bogotá" pero los números sumaban las tres sedes. Ahora usa
        // ContextoSede como el resto de los módulos, así los conteos cuadran
        // con lo que se ve al entrar a cada listado.
        $baseOps = ContextoSede::aplicar(Op::query());
        if (! $user->puedeVerTodasOps()) {
            $baseOps->where('responsable_id', $user->id);
        }

        $metricas = [
            'en_produccion'   => (clone $baseOps)->where('estado', 'en_produccion')->count(),
            'borrador'        => (clone $baseOps)->where('estado', 'borrador')->count(),
            'calidad'         => (clone $baseOps)->where('estado', 'calidad')->count(),
            'despachadas_mes' => (clone $baseOps)->where('estado', 'despachada')
                                        ->whereMonth('updated_at', now()->month)
                                        ->whereYear('updated_at', now()->year)
                                        ->count(),
        ];

        // ─── Lo que requiere atención hoy ────────────────────────────────────
        // Un conteo dice cuántas hay; esto dice cuáles hay que mirar.
        $opsVencidas = (clone $baseOps)
            ->whereNotNull('fecha_entrega_estimada')
            ->whereDate('fecha_entrega_estimada', '<', $hoy)
            ->whereNotIn('estado', ['despachada', 'cerrada', 'rechazada'])
            ->count();

        $atencion = array_filter([
            $opsVencidas > 0 ? [
                'clave'    => 'ops_vencidas',
                'cantidad' => $opsVencidas,
                'titulo'   => $opsVencidas === 1 ? 'OP con la entrega vencida' : 'OPs con la entrega vencida',
                'detalle'  => 'Pasó la fecha estimada y todavía no se despachan.',
                'href'     => '/produccion/ops?entrega=vencida',
                'tono'     => 'rojo',
            ] : null,
        ]);

        // ─── Métricas Cotizaciones (admin/vendedor) ───────────────────────────
        if ($user->esAdmin() || $user->esVendedor()) {
            $baseCots = ContextoSede::aplicar(Cotizacion::query());
            if ($user->esVendedor()) {
                $baseCots->where('responsable_id', $user->id);
            }
            $metricas['cots_enviadas'] = (clone $baseCots)->where('estado', 'enviada')->count();
            $metricas['cots_mes']      = (clone $baseCots)
                                            ->whereMonth('fecha_creacion', now()->month)
                                            ->whereYear('fecha_creacion', now()->year)
                                            ->count();

            // Enviadas que se vencen esta semana: es plata que se puede perder
            // por no llamar a tiempo.
            $cotsPorVencer = (clone $baseCots)
                ->where('estado', 'enviada')
                ->whereNotNull('fecha_validez')
                ->whereDate('fecha_validez', '>=', $hoy)
                ->whereDate('fecha_validez', '<=', now()->addDays(7)->toDateString())
                ->count();

            if ($cotsPorVencer > 0) {
                $atencion[] = [
                    'clave'    => 'cots_por_vencer',
                    'cantidad' => $cotsPorVencer,
                    'titulo'   => $cotsPorVencer === 1 ? 'Cotización por vencer' : 'Cotizaciones por vencer',
                    'detalle'  => 'Se les acaba la validez en los próximos 7 días.',
                    'href'     => '/cotizaciones?estado=enviada',
                    'tono'     => 'ambar',
                ];
            }
        }

        // ─── Alertas Mantenimiento (admin/jefe_produccion) ────────────────────
        if ($user->esAdmin() || $user->esJefeProduccion()) {
            $baseEquipos = ContextoSede::aplicar(EquipoMantenimiento::where('estado', 'activo'));

            $metricas['mant_vencidos']   = (clone $baseEquipos)
                                                ->whereNotNull('proxima_revision')
                                                ->whereDate('proxima_revision', '<', $hoy)
                                                ->count();
            $metricas['mant_proximos']   = (clone $baseEquipos)
                                                ->whereNotNull('proxima_revision')
                                                ->whereDate('proxima_revision', '>=', $hoy)
                                                ->whereDate('proxima_revision', '<=', now()->addDays(7)->toDateString())
                                                ->count();
            $metricas['mant_en_proceso'] = Mantenimiento::where('estado', 'en_proceso')->count();
        }

        // ─── OPs recientes ────────────────────────────────────────────────────
        $ops_recientes = ContextoSede::aplicar(Op::with('cliente:id,nombre,apellido'))
            ->when(! $user->puedeVerTodasOps(), fn ($q) => $q->where('responsable_id', $user->id))
            ->latest()
            ->take(5)
            ->get()
            ->map(fn ($op) => [
                'id'           => $op->id,
                'numero_op'    => $op->numero,
                'cliente'      => $op->cliente ? trim($op->cliente->nombre . ' ' . $op->cliente->apellido) : '—',
                'estado'       => $op->estado,
                'estado_label' => $op->estadoBadge()['label'],
                'created_at'   => $op->created_at->format('d/m/Y'),
            ]);

        return Inertia::render('Dashboard', [
            'metricas'      => $metricas,
            'atencion'      => array_values($atencion),
            'ops_recientes' => $ops_recientes,
            'contexto'      => [
                'usuario' => $user->name,
                'sede'    => ContextoSede::viendoTodas()
                    ? 'Todas las sedes'
                    : ContextoSede::actual()?->nombre,
            ],
            'permisos'      => [
                'puedeCrearOps'    => $user->puedeCrearOps(),
                'puedeVerificarOps'=> $user->puedeVerificarOps(),
                'puedeVerTodasOps' => $user->puedeVerTodasOps(),
                'esCotizador'      => $user->esAdmin() || $user->esVendedor(),
                'esMantenimiento'  => $user->esAdmin() || $user->esJefeProduccion(),
            ],
        ]);
    }
}
