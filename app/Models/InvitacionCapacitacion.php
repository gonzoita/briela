<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvitacionCapacitacion extends Model
{
    protected $table = 'invitaciones_capacitacion';

    protected $fillable = [
        'token',
        'email',
        'tipo',
        'cliente_id',
        'nombre_sugerido',
        'invitado_por',
        'expira_at',
        'usado_at',
    ];

    protected $casts = [
        'expira_at' => 'datetime',
        'usado_at'  => 'datetime',
    ];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function invitadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invitado_por');
    }

    public function scopeVigentes($query)
    {
        return $query->whereNull('usado_at')->where('expira_at', '>', now());
    }

    public function estaVigente(): bool
    {
        return is_null($this->usado_at) && $this->expira_at->isFuture();
    }
}
