<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Un punto que calidad tiene que revisar antes de dar por buena una unidad.
 *
 * Cuelga del ensamble cuando es directo y de la plantilla cuando el ensamble se fabrica por
 * medidas — la misma regla que los pasos de producción, porque es la misma pregunta: ¿esto es
 * propio de este ensamble, o de la receta que comparten varios?
 */
class ChecklistCalidad extends Model
{
    protected $table = 'checklist_calidad';

    protected $fillable = [
        'checkeable_type',
        'checkeable_id',
        'titulo',
        'descripcion',
        'orden',
        'exige_foto',
        'es_critico',
        'activo',
    ];

    protected $casts = [
        'orden'      => 'integer',
        'exige_foto' => 'boolean',
        'es_critico' => 'boolean',
        'activo'     => 'boolean',
    ];

    public function checkeable(): MorphTo
    {
        return $this->morphTo();
    }
}
