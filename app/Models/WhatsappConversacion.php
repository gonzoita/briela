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
    ];

    protected $casts = [
        'ultimo_mensaje_at' => 'datetime',
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
