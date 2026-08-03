<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlantillaCampo extends Model
{
    protected $table = 'plantilla_campos';

    protected $fillable = [
        'plantilla_id',
        'nombre',
        'etiqueta',
        'tipo',
        'tipo_campo',
        'subtipo_variable',
        'opciones_selector',
        'formula_calculo',
        'opciones',
        'valor_defecto',
        'placeholder',
        'ayuda',
        'requerido',
        'orden',
        'imagen_referencia',
        'imagen_referencia_titulo',
    ];

    protected $casts = [
        'opciones'          => 'array',
        'opciones_selector' => 'array',
        'requerido'         => 'boolean',
        'orden'             => 'integer',
    ];

    public function plantilla(): BelongsTo
    {
        return $this->belongsTo(PlantillaEnsamble::class, 'plantilla_id');
    }
}
