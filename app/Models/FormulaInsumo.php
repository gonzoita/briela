<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormulaInsumo extends Model
{
    protected $table = 'formula_insumo';

    protected $fillable = [
        'insumo_id',
        'tipo_puerta',
        'formula',
        'condicion',
        'es_constante',
        'valor_constante',
        'activo',
        'notas',
    ];

    protected $casts = [
        'es_constante'    => 'boolean',
        'activo'          => 'boolean',
        'valor_constante' => 'decimal:6',
    ];

    public function insumo(): BelongsTo
    {
        return $this->belongsTo(Insumo::class);
    }
}
