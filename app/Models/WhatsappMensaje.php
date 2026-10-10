<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappMensaje extends Model
{
    protected $table = 'whatsapp_mensajes';

    protected $fillable = [
        'whatsapp_conversacion_id', 'wa_message_id', 'direccion', 'tipo',
        'contenido', 'url_media', 'estado', 'es_echo', 'usuario_id',
        // El adjunto ya guardado en el servidor, por qué no salió un mensaje, y con qué
        // plantilla se mandó cuando la ventana estaba cerrada.
        'archivo_id', 'error', 'plantilla',
    ];

    protected $casts = [
        'es_echo' => 'boolean',
    ];

    public function conversacion(): BelongsTo
    {
        return $this->belongsTo(WhatsappConversacion::class, 'whatsapp_conversacion_id');
    }

    public function archivo(): BelongsTo
    {
        return $this->belongsTo(Archivo::class);
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
