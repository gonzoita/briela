<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CursoModulo extends Model
{
    protected $fillable = [
        'curso_id',
        'nombre',
        'orden',
    ];

    public function curso(): BelongsTo
    {
        return $this->belongsTo(Curso::class);
    }

    public function lecciones(): HasMany
    {
        return $this->hasMany(CursoLeccion::class)->orderBy('orden');
    }

    public function evaluacion(): HasOne
    {
        return $this->hasOne(CursoEvaluacion::class, 'curso_modulo_id');
    }
}
