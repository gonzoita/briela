<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notificacion extends Model
{
    protected $table = 'notificaciones';

    protected $fillable = [
        'user_id', 'tipo', 'titulo', 'mensaje', 'url', 'icono', 'color',
        'leida', 'leida_at',
    ];

    protected $casts = [
        'leida'    => 'boolean',
        'leida_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeNoLeidas($query)
    {
        return $query->where('leida', false);
    }
}
