<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BandejaMensaje extends Model
{
    protected $table = 'bandeja_mensajes';

    protected $fillable = [
        'bandeja_conversacion_id', 'externo_id', 'direccion', 'tipo', 'contenido',
        'archivo_id', 'estado', 'error', 'es_echo', 'usuario_id',
    ];

    protected $casts = [
        'es_echo' => 'boolean',
    ];

    public function conversacion(): BelongsTo
    {
        return $this->belongsTo(BandejaConversacion::class, 'bandeja_conversacion_id');
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
