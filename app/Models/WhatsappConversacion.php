<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsappConversacion extends Model
{
    protected $table = 'whatsapp_conversaciones';

    protected $fillable = [
        'whatsapp_numero_id', 'numero_contacto', 'nombre_contacto',
        'crm_lead_id', 'cliente_id', 'ultimo_mensaje_at', 'leido',
        // Qué agente la atiende, cuándo el cliente demostró quién es, y cuándo la tomó una
        // persona —desde ahí el agente no vuelve a hablar—.
        'agente_id', 'verificado_at', 'escalada_at',
    ];

    protected $casts = [
        'ultimo_mensaje_at' => 'datetime',
        'verificado_at'     => 'datetime',
        'escalada_at'       => 'datetime',
        'leido'             => 'boolean',
    ];

    public function numero(): BelongsTo
    {
        return $this->belongsTo(WhatsappNumero::class, 'whatsapp_numero_id');
    }

    public function mensajes(): HasMany
    {
        return $this->hasMany(WhatsappMensaje::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CrmLead::class, 'crm_lead_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }
}
