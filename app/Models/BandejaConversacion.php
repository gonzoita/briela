<?php

namespace App\Models;

use App\Models\Concerns\EsConversacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una conversación que llegó por una red: un directo o un hilo de comentarios de Instagram o
 * Facebook.
 *
 * Hermana de `WhatsappConversacion`, y las dos comparten `EsConversacion`: la bandeja las
 * presenta igual y la ventana de servicio se calcula en un solo sitio.
 */
class BandejaConversacion extends Model
{
    use EsConversacion;

    protected $table = 'bandeja_conversaciones';

    protected $fillable = [
        'canal', 'cuenta_rrss_id', 'externo_id', 'nombre_contacto', 'usuario_externo',
        'contexto', 'crm_lead_id', 'cliente_id', 'asignado_a', 'agente_id',
        'ultimo_mensaje_at', 'ultimo_entrante_at', 'leido', 'escalada_at', 'archivada_at',
    ];

    protected $casts = [
        'contexto'           => 'array',
        'ultimo_mensaje_at'  => 'datetime',
        'ultimo_entrante_at' => 'datetime',
        'escalada_at'        => 'datetime',
        'archivada_at'       => 'datetime',
        'leido'              => 'boolean',
    ];

    public function canal(): string
    {
        return (string) $this->canal;
    }

    public function mensajes(): HasMany
    {
        return $this->hasMany(BandejaMensaje::class, 'bandeja_conversacion_id');
    }

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(CuentaRrss::class, 'cuenta_rrss_id');
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

    public function agente(): BelongsTo
    {
        return $this->belongsTo(AgenteIa::class, 'agente_id');
    }

    /**
     * Cómo se llama quien escribe, para la lista.
     *
     * Meta no siempre da el nombre: en los comentarios de Instagram viene el usuario, y en un
     * directo de alguien con perfil restringido no viene nada. Mostrar el identificador interno
     * sería peor que decir «Sin nombre», porque parece un error del sistema.
     */
    public function comoSeLlama(): string
    {
        return $this->nombre_contacto
            ?: ($this->usuario_externo ? '@' . ltrim($this->usuario_externo, '@') : 'Sin nombre');
    }
}
