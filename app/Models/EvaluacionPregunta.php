<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvaluacionPregunta extends Model
{
    protected $fillable = [
        'curso_evaluacion_id',
        'enunciado',
        'tipo',
        'orden',
    ];

    public function evaluacion(): BelongsTo
    {
        return $this->belongsTo(CursoEvaluacion::class, 'curso_evaluacion_id');
    }

    public function opciones(): HasMany
    {
        return $this->hasMany(EvaluacionOpcion::class)->orderBy('orden');
    }
}
