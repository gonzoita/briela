<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperarioTurnoConfig extends Model
{
    protected $table = 'operario_turnos_config';

    protected $fillable = [
        'nombre',
        'hora_inicio',
        'hora_fin',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];
}
