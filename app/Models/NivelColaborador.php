<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NivelColaborador extends Model
{
    protected $table = 'niveles_colaborador';

    protected $fillable = ['nombre', 'color', 'icono', 'puntos_minimos', 'puntos_maximos', 'orden'];

    protected $casts = [
        'puntos_minimos' => 'integer',
        'puntos_maximos' => 'integer',
        'orden'          => 'integer',
    ];
}
