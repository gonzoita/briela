<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SegmentacionOpcion extends Model
{
    protected $table = 'segmentacion_opciones';

    protected $fillable = [
        'tipo',
        'valor',
        'etiqueta',
        'color',
        'orden',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'orden'  => 'integer',
    ];

    public function scopePorTipo(Builder $query, string $tipo): Builder
    {
        return $query->where('tipo', $tipo)->where('activo', true)->orderBy('orden');
    }
}
