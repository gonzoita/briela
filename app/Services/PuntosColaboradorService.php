<?php

namespace App\Services;

use App\Models\Configuracion;
use App\Models\NivelColaborador;
use App\Models\Operario;
use App\Models\OpItemTrabajoPaso;
use App\Models\PuntoColaborador;

class PuntosColaboradorService
{
    public function otorgarPuntosPorPaso(OpItemTrabajoPaso $paso, int $operarioId): void
    {
        // Idempotente: si este paso ya le otorgó puntos a este operario, no
        // vuelve a otorgar. Evita el doble conteo cuando un paso ya
        // completado se marca de nuevo (o completar → desmarcar → completar).
        // Al desmarcar, revertirPuntosPorPaso() borra estos registros, así
        // que un recompletado legítimo sí vuelve a otorgar.
        $yaOtorgado = PuntoColaborador::where('op_item_trabajo_paso_id', $paso->id)
            ->where('operario_id', $operarioId)
            ->where('puntos', '>', 0)
            ->exists();
        if ($yaOtorgado) return;

        $dificultad = $paso->templatePaso?->nivel_dificultad ?? 1;
        $puntos     = (int) Configuracion::get("puntos_dificultad_{$dificultad}", 10);

        // Bonus por terminar antes del tiempo estimado
        if ($paso->tiempo_estimado_minutos && $paso->tiempo_minutos) {
            if ($paso->tiempo_minutos < $paso->tiempo_estimado_minutos) {
                $bonusPct    = (int) Configuracion::get('puntos_bonus_tiempo_pct', 20);
                $bonusPuntos = (int) round($puntos * $bonusPct / 100);
                $this->registrar($operarioId, $bonusPuntos,
                    "Bonus por terminar antes del tiempo en: {$paso->nombre}",
                    'bonus_tiempo', $paso->id);
            }
        }

        // Bonus por paso final
        if ($paso->es_paso_final) {
            $bonusFinal = (int) Configuracion::get('puntos_bonus_paso_final', 50);
            $this->registrar($operarioId, $bonusFinal,
                "Bonus por completar paso final: {$paso->nombre}",
                'paso_final', $paso->id);
        }

        $this->registrar($operarioId, $puntos,
            "Paso completado: {$paso->nombre}",
            'produccion', $paso->id);
    }

    /**
     * Borra todos los puntos que un paso otorgó (a todos los operarios que lo
     * trabajaron) y recalcula sus totales. Se llama al desmarcar un paso, para
     * que los puntos ganados por completarlo se devuelvan y no queden inflando
     * el ranking.
     */
    public function revertirPuntosPorPaso(int $pasoId): void
    {
        $operarios = PuntoColaborador::where('op_item_trabajo_paso_id', $pasoId)
            ->pluck('operario_id')
            ->unique();

        if ($operarios->isEmpty()) return;

        PuntoColaborador::where('op_item_trabajo_paso_id', $pasoId)->delete();

        foreach ($operarios as $operarioId) {
            $this->recalcularTotal($operarioId);
        }
    }

    public function penalizar(int $operarioId, string $tipo, ?string $descripcion = null): void
    {
        $config = [
            'inasistencia' => ['clave' => 'puntos_penalizacion_inasistencia', 'label' => 'Inasistencia'],
            'tardanza'     => ['clave' => 'puntos_penalizacion_tardanza',     'label' => 'Llegada tarde'],
            'calidad'      => ['clave' => 'puntos_penalizacion_calidad',      'label' => 'Trabajo rechazado por calidad'],
        ];

        if (!isset($config[$tipo])) return;

        $puntos   = (int) Configuracion::get($config[$tipo]['clave'], 10);
        $concepto = $descripcion ?? $config[$tipo]['label'];

        $this->registrar($operarioId, -$puntos, $concepto, 'disciplina');
    }

    public function registrar(int $operarioId, int $puntos, string $concepto, string $tipo, ?int $pasoId = null): void
    {
        PuntoColaborador::create([
            'operario_id'             => $operarioId,
            'puntos'                  => $puntos,
            'concepto'                => $concepto,
            'tipo'                    => $tipo,
            'op_item_trabajo_paso_id' => $pasoId,
            'registrado_por'          => auth()->id(),
        ]);

        $this->recalcularTotal($operarioId);
    }

    public function recalcularTotal(int $operarioId): void
    {
        $total = PuntoColaborador::where('operario_id', $operarioId)->sum('puntos');
        $total = max(0, $total);

        $nivel = NivelColaborador::where('puntos_minimos', '<=', $total)
            ->where(function ($q) use ($total) {
                $q->whereNull('puntos_maximos')
                  ->orWhere('puntos_maximos', '>=', $total);
            })
            ->orderByDesc('puntos_minimos')
            ->first();

        Operario::where('id', $operarioId)->update([
            'puntos_totales' => $total,
            'nivel'          => $nivel?->nombre ?? 'Bronce',
        ]);
    }

    public function rankingSemanal(): \Illuminate\Support\Collection
    {
        $inicio = now()->startOfWeek();
        $fin    = now()->endOfWeek();

        return PuntoColaborador::with('operario')
            ->whereBetween('created_at', [$inicio, $fin])
            ->where('puntos', '>', 0)
            ->selectRaw('operario_id, SUM(puntos) as puntos_semana')
            ->groupBy('operario_id')
            ->orderByDesc('puntos_semana')
            ->get();
    }
}
