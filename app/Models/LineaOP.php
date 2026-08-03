<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LineaOP extends Model
{
    protected $table = 'lineas_op';

    protected $fillable = [
        'orden_produccion_id',
        'linea',
        'estado',
        'tiene_faltantes',
        'detalle_faltantes',
        'checklist',
        'checklist_aprobado',
        'inicio_proceso',
        'fin_proceso',
        'tiempo_minutos',
        'observaciones',
    ];

    protected $casts = [
        'checklist'          => 'array',
        'tiene_faltantes'    => 'boolean',
        'checklist_aprobado' => 'boolean',
        'inicio_proceso'     => 'datetime',
        'fin_proceso'        => 'datetime',
    ];

    public function ordenProduccion(): BelongsTo
    {
        return $this->belongsTo(OrdenProduccion::class, 'orden_produccion_id');
    }

    public function lineaLabel(): string
    {
        return match ($this->linea) {
            'puertas'   => 'Puertas',
            'paneleria' => 'Panelería',
            'almacen'   => 'Almacén',
            default     => $this->linea,
        };
    }

    public function estadoLabel(): string
    {
        return match ($this->estado) {
            'pendiente'           => 'Pendiente',
            'revision_material'   => 'Revisión de material',
            'faltante_compras'    => 'Faltante en compras',
            'en_proceso'          => 'En proceso',
            'checklist_pendiente' => 'Checklist pendiente',
            'completada'          => 'Completada',
            default               => $this->estado,
        };
    }

    public function porcentaje(): int
    {
        return match ($this->estado) {
            'pendiente'           => 0,
            'revision_material'   => 15,
            'faltante_compras'    => 20,
            'en_proceso'          => 55,
            'checklist_pendiente' => 80,
            'completada'          => 100,
            default               => 0,
        };
    }
}
