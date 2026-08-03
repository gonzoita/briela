<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CursoEvaluacion extends Model
{
    protected $table = 'curso_evaluaciones';

    protected $fillable = [
        'curso_id',
        'curso_modulo_id',
        'nombre',
        'nota_minima_aprobacion',
        'intentos_permitidos',
        'requiere_revision_manual',
    ];

    protected $casts = [
        'requiere_revision_manual' => 'boolean',
        'nota_minima_aprobacion'   => 'integer',
        'intentos_permitidos'      => 'integer',
    ];

    public function curso(): BelongsTo
    {
        return $this->belongsTo(Curso::class);
    }

    public function modulo(): BelongsTo
    {
        return $this->belongsTo(CursoModulo::class, 'curso_modulo_id');
    }

    public function preguntas(): HasMany
    {
        return $this->hasMany(EvaluacionPregunta::class)->orderBy('orden');
    }

    public function scopeFinales($query)
    {
        return $query->whereNull('curso_modulo_id');
    }

    public function scopePorModulo($query)
    {
        return $query->whereNotNull('curso_modulo_id');
    }

    public function esFinal(): bool
    {
        return $this->curso_modulo_id === null;
    }
}
