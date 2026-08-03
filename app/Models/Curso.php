<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Curso extends Model
{
    protected $fillable = [
        'titulo',
        'descripcion',
        'categoria',
        'publico_objetivo',
        'obligatorio',
        'activo',
        'imagen_portada',
        'puntos_otorga',
    ];

    protected $casts = [
        'obligatorio'   => 'boolean',
        'activo'        => 'boolean',
        'puntos_otorga' => 'integer',
    ];

    public function modulos(): HasMany
    {
        return $this->hasMany(CursoModulo::class)->orderBy('orden');
    }

    public function evaluacion(): HasOne
    {
        return $this->hasOne(CursoEvaluacion::class);
    }

    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    public function scopeParaPublico($query, string $tipo)
    {
        return $query->where(function ($q) use ($tipo) {
            $q->where('publico_objetivo', $tipo)->orWhere('publico_objetivo', 'todos');
        });
    }
}
