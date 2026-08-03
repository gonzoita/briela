<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CursoLeccion extends Model
{
    protected $table = 'curso_lecciones';

    protected $fillable = [
        'curso_modulo_id',
        'nombre',
        'tipo',
        'contenido',
        'duracion_minutos',
        'orden',
    ];

    public function modulo(): BelongsTo
    {
        return $this->belongsTo(CursoModulo::class, 'curso_modulo_id');
    }
}
