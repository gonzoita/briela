<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormulaComponente extends Model
{
    protected $table = 'formulas_componente';

    protected $fillable = [
        'insumo_id',
        'tipo_puerta',
        'cantidad',
        'es_lamina',
        'escala_con_dimension',
    ];

    protected $casts = [
        'cantidad'             => 'decimal:4',
        'es_lamina'            => 'boolean',
        'escala_con_dimension' => 'boolean',
    ];

    public function insumo(): BelongsTo
    {
        return $this->belongsTo(Insumo::class);
    }
}
