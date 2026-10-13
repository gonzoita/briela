<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un paso de una automatización que espera a que una persona diga sí o no.
 *
 * La corrida queda `esperando` hasta que alguien decide o hasta `vence_at`. Decide el primero de los
 * destinatarios que conteste; vencer cuenta como «no».
 */
class AutomatizacionAprobacion extends Model
{
    protected $table = 'automatizacion_aprobaciones';

    protected $fillable = [
        'ejecucion_id', 'nodo_id', 'pregunta', 'destinatarios', 'estado', 'decidida_por', 'decidida_at', 'nota', 'vence_at',
    ];

    protected $casts = [
        'destinatarios' => 'array',
        'decidida_at'   => 'datetime',
        'vence_at'      => 'datetime',
    ];

    public function ejecucion(): BelongsTo
    {
        return $this->belongsTo(AutomatizacionEjecucion::class, 'ejecucion_id');
    }

    public function decididaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decidida_por');
    }

    public function scopePendientesDe(Builder $q, int $userId): Builder
    {
        return $q->where('estado', 'pendiente')->where('vence_at', '>', now())->whereJsonContains('destinatarios', $userId);
    }

    public function puedeDecidir(User $u): bool
    {
        return in_array($u->id, array_map('intval', $this->destinatarios ?? []), true);
    }
}
