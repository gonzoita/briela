<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvaluacionOpcion extends Model
{
    protected $table = 'evaluacion_opciones';

    protected $fillable = [
        'evaluacion_pregunta_id',
        'texto',
        'es_correcta',
        'orden',
    ];

    protected $casts = [
        'es_correcta' => 'boolean',
    ];

    public function pregunta(): BelongsTo
    {
        return $this->belongsTo(EvaluacionPregunta::class, 'evaluacion_pregunta_id');
    }
}
