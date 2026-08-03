<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConfiguracionPuerta extends Model
{
    protected $table = 'configuraciones_puerta';

    protected $fillable = [
        'nombre',
        'tipo_puerta',
        'tipo_sello',
        'temperatura',
        'ancho_vano',
        'alto_vano',
        'tipo_corredera',
        'sin_llave',
        'palanca',
        'visor',
        'desglose',
        'precios_resultado',
        'creado_por',
    ];

    protected $casts = [
        'sin_llave'         => 'boolean',
        'palanca'           => 'boolean',
        'visor'             => 'boolean',
        'desglose'          => 'array',
        'precios_resultado' => 'array',
        'ancho_vano'        => 'decimal:3',
        'alto_vano'         => 'decimal:3',
    ];

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }
}
