<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un renglón de la caja negra de una corrida: un mensaje del modelo, una herramienta que pidió,
 * o un error. El resumen va recortado a propósito: la bitácora no debe duplicar datos de clientes.
 */
class AgentePaso extends Model
{
    protected $table = 'agente_pasos';

    public $timestamps = false;

    protected $fillable = ['ejecucion_id', 'orden', 'tipo', 'herramienta', 'argumentos', 'resumen', 'ms', 'created_at'];

    protected $casts = ['argumentos' => 'array', 'created_at' => 'datetime'];

    public function ejecucion(): BelongsTo
    {
        return $this->belongsTo(AgenteEjecucion::class, 'ejecucion_id');
    }
}
