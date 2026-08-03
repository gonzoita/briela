<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Insumo extends Model
{
    protected $fillable = [
        'categoria',
        'nombre',
        'unidad',
        'precio_costo',
        'activo',
        'orden',
    ];

    protected $casts = [
        'activo'       => 'boolean',
        'precio_costo' => 'decimal:2',
    ];

    public function formulas(): HasMany
    {
        return $this->hasMany(FormulaComponente::class);
    }
}
