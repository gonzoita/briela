<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TemplateTrabajoPaso extends Model
{
    protected $table = 'template_trabajo_pasos';

    protected $fillable = [
        'template_id',
        'nombre',
        'objetivo',
        'descripcion',
        'peso_porcentaje',
        'orden',
        'nivel_dificultad',
        'depende_de',
        'es_paso_final',
        // A qué bodega entra la unidad al cerrar este paso. Solo se usa en el paso de
        // entrega; en los demás no significa nada.
        'bodega_destino_id',
        'imagen',
        'archivo_plano',
    ];

    protected $casts = [
        'peso_porcentaje'  => 'decimal:2',
        'orden'            => 'integer',
        'nivel_dificultad' => 'integer',
        'depende_de'       => 'array',
        'es_paso_final'    => 'boolean',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(TemplateTrabajo::class, 'template_id');
    }
}
