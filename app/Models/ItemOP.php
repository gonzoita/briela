<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemOP extends Model
{
    protected $table = 'items_op';

    protected $fillable = [
        'orden_produccion_id',
        'tipo',
        'numero_serie',
        'estado_serie',
        'cantidad',
        'orden',
        'observaciones',
        // Panel
        'panel_ancho',
        'panel_alto',
        'panel_espesor',
        'panel_tipo',
        'panel_acabado_interior',
        'panel_acabado_exterior',
        // Puerta
        'puerta_ancho',
        'puerta_alto',
        'puerta_tipo',
        'puerta_sentido',
        'puerta_acabado',
        'puerta_herrajes',
        // Componente
        'comp_referencia',
        'comp_descripcion',
        'comp_unidad',
    ];

    protected $casts = [
        'cantidad'     => 'integer',
        'orden'        => 'integer',
        'panel_ancho'  => 'decimal:2',
        'panel_alto'   => 'decimal:2',
        'panel_espesor'=> 'integer',
        'puerta_ancho' => 'decimal:2',
        'puerta_alto'  => 'decimal:2',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (ItemOP $item) {
            if (empty($item->numero_serie)) {
                $item->numero_serie = $item->generarSerie();
            }
        });
    }

    public function generarSerie(): string
    {
        $op      = $this->ordenProduccion;
        $numOp   = preg_replace('/[^0-9]/', '', $op->numero_op); // OP-045 → 045
        $numOp   = str_pad($numOp, 3, '0', STR_PAD_LEFT);
        $anio    = date('Y');

        $inicial = match ($this->tipo) {
            'panel'      => 'P',
            'puerta'     => 'D',
            'componente' => 'C',
            default      => 'X',
        };

        $correlativo = static::where('orden_produccion_id', $this->orden_produccion_id)
            ->where('tipo', $this->tipo)
            ->whereNotNull('numero_serie')
            ->count() + 1;

        return sprintf('IF-%s-%s-%s-%s', $anio, $numOp, $inicial, str_pad($correlativo, 3, '0', STR_PAD_LEFT));
    }

    public function estadoSerieLabel(): string
    {
        return match ($this->estado_serie) {
            'en_produccion'    => 'En producción',
            'calidad_aprobada' => 'Calidad aprobada',
            'empacado'         => 'Empacado',
            'despachado'       => 'Despachado',
            'instalado'        => 'Instalado',
            default            => $this->estado_serie,
        };
    }

    public function ordenProduccion(): BelongsTo
    {
        return $this->belongsTo(OrdenProduccion::class, 'orden_produccion_id');
    }

    public function tipoLabel(): string
    {
        return match ($this->tipo) {
            'panel'      => 'Panel',
            'puerta'     => 'Puerta',
            'componente' => 'Componente',
            default      => $this->tipo,
        };
    }

    public function resumen(): string
    {
        return match ($this->tipo) {
            'panel' => sprintf(
                '%dx Panel %sx%sm e:%smm',
                $this->cantidad,
                $this->panel_ancho ?? '?',
                $this->panel_alto ?? '?',
                $this->panel_espesor ?? '?'
            ),
            'puerta' => sprintf(
                '%dx Puerta %s %sx%sm',
                $this->cantidad,
                $this->puerta_tipo ?? '?',
                $this->puerta_ancho ?? '?',
                $this->puerta_alto ?? '?'
            ),
            'componente' => sprintf(
                '%dx %s - %s',
                $this->cantidad,
                $this->comp_referencia ?? 's/ref',
                $this->comp_descripcion ?? ''
            ),
            default => "{$this->cantidad}x {$this->tipo}",
        };
    }
}
