<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Informe extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'nombre',
        'descripcion',
        'fuente',
        'campos',
        'filtros',
        'tipo_grafica',
        'publico',
        'created_by',
        'agrupar',
        'origen',
        'pregunta',
        'analisis',
        'analizado_at',
    ];

    protected $casts = [
        'campos'  => 'array',
        'filtros' => 'array',
        'publico' => 'boolean',
        'agrupar' => 'array',
        'analizado_at' => 'datetime',
    ];

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopePublicos(Builder $query): Builder
    {
        return $query->where('publico', true);
    }
}
