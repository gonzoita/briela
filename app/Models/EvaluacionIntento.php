<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvaluacionIntento extends Model
{
    protected $fillable = [
        'inscripcion_id',
        'curso_evaluacion_id',
        'numero_intento',
        'respuestas',
        'nota',
        'aprobado',
        'estado',
        'revisado_por',
        'revisado_at',
        'notas_revisor',
        'completado_at',
    ];

    protected $casts = [
        'respuestas'    => 'array',
        'nota'          => 'decimal:2',
        'aprobado'      => 'boolean',
        'revisado_at'   => 'datetime',
        'completado_at' => 'datetime',
    ];

    public function inscripcion(): BelongsTo
    {
        return $this->belongsTo(Inscripcion::class);
    }

    public function evaluacion(): BelongsTo
    {
        return $this->belongsTo(CursoEvaluacion::class, 'curso_evaluacion_id');
    }

    public function revisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisado_por');
    }
}
