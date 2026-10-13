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
        // Los que escribe Embedded Signup. Ver EmbeddedSignupService.
        'waba_id', 'coexistencia', 'pin_registro', 'vinculado_at',
    ];

    // El PIN de registro nunca viaja a la pantalla.
    protected $hidden = ['pin_registro'];

    protected $casts = [
        'activo' => 'boolean',
        'coexistencia' => 'boolean',
        'pin_registro' => 'encrypted',
        'vinculado_at' => 'datetime',
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
