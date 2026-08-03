<?php

namespace App\Http\Controllers;

use App\Models\NivelColaborador;
use App\Models\Operario;
use App\Models\OpItemTrabajo;
use App\Models\OpItemTrabajoPaso;
use App\Models\OpItemTrabajoPasoOperario;
use App\Models\PuntoColaborador;
use App\Services\PuntosColaboradorService;
use Inertia\Inertia;
use Inertia\Response;

class OperarioDashboardController extends Controller
{
    public function index(): Response
    {
        $user     = auth()->user();
        $operario = Operario::where('user_id', $user->id)->first();

        if (!$operario) {
            return Inertia::render('Operario/Dashboard', [
                'sin_perfil'          => true,
                'operario'            => null,
                'metricas'            => null,
                'trabajos_activos'    => [],
                'trabajos_terminados' => [],
                'puntos_totales'      => 0,
                'nivel_actual'        => null,
                'nivel_siguiente'     => null,
                'puntos_semanales'    => 0,
                'posicion_ranking'    => null,
                'historial_puntos'    => [],
                'trabajos_hoy'        => [],
            ]);
        }

        // ── Métricas de producción ────────────────────────────────────────────
        $pasosCompletados = OpItemTrabajoPasoOperario::where('operario_id', $operario->id)->count();
        $tiempoTotal      = OpItemTrabajoPasoOperario::where('operario_id', $operario->id)->sum('tiempo_minutos');

        $trabajos = OpItemTrabajo::with([
                'opItem.op',
                'template',
                'pasos' => fn ($q) => $q->orderBy('orden'),
            ])
            ->whereHas('pasos', function ($q) use ($operario) {
                $q->whereHas('operarios', fn ($q2) => $q2->where('operario_id', $operario->id))
                  ->orWhere('operario_id', $operario->id);
            })
            ->latest()
            ->take(30)
            ->get();

        $trabajosActivos    = $trabajos->filter(fn ($t) => $t->porcentaje_avance < 100)->values();
        $trabajosTerminados = $trabajos->filter(fn ($t) => $t->porcentaje_avance >= 100)->values();

        $mapTrabajo = fn ($t) => [
            'id'                => $t->id,
            'token'             => $t->token_trabajo,
            'numero_unidad'     => $t->numero_unidad,
            'total_unidades'    => $t->total_unidades,
            'porcentaje_avance' => (float) $t->porcentaje_avance,
            'template_nombre'   => $t->template?->nombre,
            'op_numero'         => $t->opItem?->op?->numero,
            'op_estado'         => $t->opItem?->op?->estado,
            'item_descripcion'  => $t->opItem?->descripcion,
            'pasos_total'       => $t->pasos->count(),
            'pasos_completados' => $t->pasos->where('completado', true)->count(),
        ];

        // ── Puntos y nivel ───────────────────────────────────────────────────
        $puntosTotales = $operario->puntos_totales ?? 0;

        $nivelActual = NivelColaborador::where('puntos_minimos', '<=', $puntosTotales)
            ->where(function ($q) use ($puntosTotales) {
                $q->whereNull('puntos_maximos')
                  ->orWhere('puntos_maximos', '>=', $puntosTotales);
            })
            ->orderByDesc('puntos_minimos')
            ->first();

        $nivelSiguiente = NivelColaborador::where('puntos_minimos', '>', $puntosTotales)
            ->orderBy('puntos_minimos')
            ->first();

        // ── Historial de puntos recientes ────────────────────────────────────
        $historialPuntos = PuntoColaborador::where('operario_id', $operario->id)
            ->orderByDesc('created_at')
            ->take(10)
            ->get()
            ->map(fn ($p) => [
                'puntos'     => $p->puntos,
                'concepto'   => $p->concepto,
                'tipo'       => $p->tipo,
                'created_at' => $p->created_at->format('d/m H:i'),
            ]);

        // ── Ranking semanal ──────────────────────────────────────────────────
        $svc     = app(PuntosColaboradorService::class);
        $ranking = $svc->rankingSemanal();

        $posicionRanking = $ranking->search(fn ($r) => $r->operario_id === $operario->id);
        $puntosSemanales = $ranking->firstWhere('operario_id', $operario->id)?->puntos_semana ?? 0;

        // ── Trabajos programados hoy ─────────────────────────────────────────
        $trabajosHoy = OpItemTrabajoPaso::with(['trabajo.opItem.op', 'estacion'])
            ->where('colaborador_programado_id', $operario->user_id)
            ->where('fecha_programada', now()->toDateString())
            ->where('completado', false)
            ->get()
            ->map(fn ($p) => [
                'nombre'   => $p->nombre,
                'op'       => $p->trabajo?->opItem?->op?->numero,
                'estacion' => $p->estacion?->nombre,
                'tiempo'   => $p->tiempo_estimado_minutos,
            ]);

        return Inertia::render('Operario/Dashboard', [
            'sin_perfil'          => false,
            'operario'            => [
                'id'          => $operario->id,
                'nombre'      => $operario->nombre,
                'especialidad' => $operario->especialidad,
                'documento'   => $operario->documento,
            ],
            'metricas'            => [
                'pasos_completados'    => $pasosCompletados,
                'tiempo_total_minutos' => (int) $tiempoTotal,
                'trabajos_activos'     => $trabajosActivos->count(),
                'trabajos_terminados'  => $trabajosTerminados->count(),
            ],
            'trabajos_activos'    => $trabajosActivos->map($mapTrabajo)->values(),
            'trabajos_terminados' => $trabajosTerminados->map($mapTrabajo)->values(),
            'puntos_totales'      => $puntosTotales,
            'nivel_actual'        => $nivelActual ? [
                'nombre'         => $nivelActual->nombre,
                'color'          => $nivelActual->color,
                'puntos_minimos' => $nivelActual->puntos_minimos,
                'puntos_maximos' => $nivelActual->puntos_maximos,
            ] : null,
            'nivel_siguiente'     => $nivelSiguiente ? [
                'nombre'         => $nivelSiguiente->nombre,
                'color'          => $nivelSiguiente->color,
                'puntos_minimos' => $nivelSiguiente->puntos_minimos,
            ] : null,
            'puntos_semanales'    => $puntosSemanales,
            'posicion_ranking'    => $posicionRanking !== false ? $posicionRanking + 1 : null,
            'historial_puntos'    => $historialPuntos,
            'trabajos_hoy'        => $trabajosHoy,
        ]);
    }
}
