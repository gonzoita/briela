<?php

namespace App\Models;

use App\Models\Concerns\EsConversacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsappConversacion extends Model
{
    // La bandeja atiende WhatsApp y las redes con la misma pantalla y las mismas reglas de
    // ventana. Lo compartido vive en el trait; ver su encabezado.
    use EsConversacion;

    protected $table = 'whatsapp_conversaciones';

    protected $fillable = [
        'whatsapp_numero_id', 'numero_contacto', 'nombre_contacto',
        'crm_lead_id', 'cliente_id', 'ultimo_mensaje_at', 'leido',
        // Qué agente la atiende, cuándo el cliente demostró quién es, y cuándo la tomó una
        // persona —desde ahí el agente no vuelve a hablar—.
        'agente_id', 'verificado_at', 'escalada_at',
        // La bandeja: quién la atiende, cuándo escribió el cliente por última vez —de eso
        // depende la ventana de 24 h— y si alguien la sacó de la vista.
        'asignado_a', 'ultimo_entrante_at', 'archivada_at',
    ];

    protected $casts = [
        'ultimo_mensaje_at'  => 'datetime',
        'ultimo_entrante_at' => 'datetime',
        'verificado_at'      => 'datetime',
        'escalada_at'        => 'datetime',
        'archivada_at'       => 'datetime',
        'leido'              => 'boolean',
    ];

    public function canal(): string
    {
        return 'whatsapp';
    }

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

    public function asignado(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asignado_a');
    }

    /** Cómo se llama quien escribe. WhatsApp siempre da al menos el número. */
    public function comoSeLlama(): string
    {
        return $this->nombre_contacto ?: $this->numero_contacto;
    }
}
