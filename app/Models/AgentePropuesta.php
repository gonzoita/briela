<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lo que un agente propone y una persona decide. Un agente nunca escribe en el negocio: deja una
 * fila aquí, y al aprobarla se ejecuta con los mismos servicios que usan las pantallas.
 */
class AgentePropuesta extends Model
{
    protected $table = 'agente_propuestas';

    protected $fillable = [
        'agente_id', 'ejecucion_id', 'accion', 'parametros', 'resumen', 'entidad_type', 'entidad_id',
        'destinatario_id', 'estado', 'decidida_por', 'decidida_at', 'motivo', 'resultado', 'vence_at',
    ];

    protected $casts = [
        'parametros'  => 'array',
        'decidida_at' => 'datetime',
        'vence_at'    => 'datetime',
    ];

    public function agente(): BelongsTo
    {
        return $this->belongsTo(AgenteInterno::class, 'agente_id')->withTrashed();
    }

    public function ejecucion(): BelongsTo
    {
        return $this->belongsTo(AgenteEjecucion::class, 'ejecucion_id');
    }

    public function destinatario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'destinatario_id');
    }

    public function decididaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decidida_por');
    }

    public function scopePendientes(Builder $q): Builder
    {
        return $q->where('estado', 'pendiente');
    }

    /** Pendiente y con plazo vencido: ya no se debe aprobar, los datos pudieron cambiar. */
    public function estaVencida(): bool
    {
        return $this->estado === 'pendiente' && $this->vence_at !== null && $this->vence_at->isPast();
    }
}
