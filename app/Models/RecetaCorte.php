<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class RecetaCorte extends Model
{
    use SoftDeletes;

    protected $table = 'recetas_corte';

    protected $fillable = [
        'nombre',
        'producto_insumo_id',
        'producto_resultado_id',
        'cantidad_insumo',
        'activo',
    ];

    protected $casts = [
        'cantidad_insumo' => 'decimal:3',
        'activo'          => 'boolean',
    ];

    public function insumo(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_insumo_id');
    }

    public function resultado(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'producto_resultado_id');
    }

    public function scopeActivas($query)
    {
        return $query->where('activo', true);
    }
}
