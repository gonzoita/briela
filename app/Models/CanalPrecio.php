<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * El precio de un producto o un ensamble para un canal.
 *
 * Un canal es un tipo de contacto marcado con `define_precio` en Segmentación. Antes
 * eran tres columnas fijas por tabla y agregar un cuarto canal pedía una migración;
 * ahora es una fila, y la empresa crea los canales que necesite desde la interfaz.
 */
class CanalPrecio extends Model
{
    protected $table = 'canal_precios';

    protected $fillable = [
        'precionable_type',
        'precionable_id',
        'segmentacion_opcion_id',
        'margen_pct',
        'precio',
        'comision_min_pct',
        'comision_max_pct',
        'descuento_max_pct',
    ];

    protected function casts(): array
    {
        return [
            'margen_pct'        => 'decimal:2',
            'precio'            => 'decimal:2',
            'comision_min_pct'  => 'decimal:2',
            'comision_max_pct'  => 'decimal:2',
            'descuento_max_pct' => 'decimal:2',
        ];
    }

    public function precionable(): MorphTo
    {
        return $this->morphTo();
    }

    public function canal(): BelongsTo
    {
        return $this->belongsTo(SegmentacionOpcion::class, 'segmentacion_opcion_id');
    }
}
