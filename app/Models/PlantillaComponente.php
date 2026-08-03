<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlantillaComponente extends Model
{
    protected $table = 'plantilla_componentes';

    protected $fillable = [
        'plantilla_id',
        'producto_id',
        'etiqueta',
        'formula',
        'formula_real',
        'sub_formulas',
        'condicion',
        'unidad',
        'incluir_en_precio',
        'visible_cliente',
        'visible_op',
        'orden',
        'activo',
        'notas',
        'seccion',
        'seccion_id',
    ];

    protected $casts = [
        'activo'           => 'boolean',
        'incluir_en_precio'=> 'boolean',
        'visible_cliente'  => 'boolean',
        'visible_op'       => 'boolean',
        'orden'            => 'integer',
        'sub_formulas'     => 'array',
    ];

    public function plantilla(): BelongsTo
    {
        return $this->belongsTo(PlantillaEnsamble::class, 'plantilla_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function seccion(): BelongsTo
    {
        return $this->belongsTo(PlantillaSeccion::class, 'seccion_id');
    }

    public function tieneSubFormulas(): bool
    {
        return !empty($this->sub_formulas) && count($this->sub_formulas) > 0;
    }
}
