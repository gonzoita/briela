<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TipoColaborador extends Model
{
    protected $table = 'tipos_colaborador';

    protected $fillable = ['nombre', 'color', 'activo', 'orden'];

    protected $casts = ['activo' => 'boolean'];
}
