<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Un renglón de la caja negra de una corrida: un nodo y lo que pasó en él. */
class AutomatizacionPaso extends Model
{
    protected $table = 'automatizacion_pasos';

    public $timestamps = false;

    protected $fillable = ['ejecucion_id', 'orden', 'nodo_id', 'tipo', 'estado', 'resumen', 'ms', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];
}
