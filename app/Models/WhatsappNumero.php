<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsappNumero extends Model
{
    protected $table = 'whatsapp_numeros';

    protected $fillable = [
        'nombre', 'numero_telefono', 'phone_number_id', 'rol', 'usuario_id', 'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function conversaciones(): HasMany
    {
        return $this->hasMany(WhatsappConversacion::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    public function scopeCentral($query)
    {
        return $query->where('rol', 'central');
    }
}
