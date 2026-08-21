<?php

namespace App\Http\Controllers;

use App\Models\Cotizacion;
use App\Models\DashboardSeccion;
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

        // Las tarjetas fijas de conteo se retiraron el 21 ago 2026: lo que se mira al entrar
        // ahora lo arma cada empresa en sus propias secciones, y esas cuentas se pueden
        // reconstruir como gráficos de la fuente «OPs». Aquí solo quedan las alertas, que no
        // son un tablero sino un aviso.
        $metricas = [];

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

        return Inertia::render('Dashboard', [
            'metricas'      => $metricas,
            'atencion'      => array_values($atencion),
            'contexto'      => [
                'usuario' => $user->name,
                'sede'    => ContextoSede::viendoTodas()
                    ? 'Todas las sedes'
                    : ContextoSede::actual()?->nombre,
            ],
            // Las secciones que la empresa armó para su tablero. Cada una es un «módulo» para
            // el motor de gráficos que ya existe, y su clave es lo que sus gráficos guardan.
            'secciones'     => DashboardSeccion::where('activa', true)
                ->orderBy('orden')->orderBy('id')
                ->get(['id', 'titulo', 'clave']),
            'permisos'      => [
                'puedeCrearOps'    => $user->puedeCrearOps(),
                'puedeVerificarOps'=> $user->puedeVerificarOps(),
                'puedeVerTodasOps' => $user->puedeVerTodasOps(),
                'esCotizador'      => $user->esAdmin() || $user->esVendedor(),
                'esMantenimiento'  => $user->esAdmin() || $user->esJefeProduccion(),
                'puedeGestionarGraficos' => $user->tienePermiso('graficos.gestionar'),
            ],
        ]);
    }
}
