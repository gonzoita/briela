<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un mensaje dentro del hilo de un documento. Puede ser charla, una solicitud
 * que alguien debe atender, o una tarea con responsable y fecha.
 */
class Comentario extends Model
{
    use SoftDeletes, Auditable;

    protected $table = 'comentarios';

    protected $fillable = [
        'comentable_type', 'comentable_id', 'user_id', 'destinatario_id', 'contenido',
        'tipo', 'estado', 'asignado_a', 'fecha_limite',
        'resuelto_at', 'resuelto_por', 'mencionados',
        'referencia_type', 'referencia_id', 'leido_at',
    ];

    protected $casts = [
        'mencionados'  => 'array',
        'fecha_limite' => 'date',
        'resuelto_at'  => 'datetime',
        'leido_at'     => 'datetime',
    ];

    public function comentable()
    {
        return $this->morphTo();
    }

    /** Documento compartido dentro del mensaje, si lo hay. */
    public function referencia()
    {
        return $this->morphTo();
    }

    public function destinatario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'destinatario_id');
    }

    public function esDirecto(): bool
    {
        return $this->destinatario_id !== null;
    }

    /**
     * La conversación entre dos personas: lo que uno le mandó al otro, en
     * ambos sentidos.
     */
    public function scopeEntre($query, int $unUsuario, int $otroUsuario)
    {
        return $query->whereNotNull('destinatario_id')
            ->where(function ($q) use ($unUsuario, $otroUsuario) {
                $q->where(fn ($s) => $s->where('user_id', $unUsuario)->where('destinatario_id', $otroUsuario))
                  ->orWhere(fn ($s) => $s->where('user_id', $otroUsuario)->where('destinatario_id', $unUsuario));
            });
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function asignado(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asignado_a');
    }

    public function resueltoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resuelto_por');
    }

    /** Solicitudes y tareas que siguen abiertas. */
    public function scopeAbiertos($query)
    {
        return $query->whereIn('tipo', ['solicitud', 'tarea'])->where('estado', 'pendiente');
    }

    public function esAccionable(): bool
    {
        return in_array($this->tipo, ['solicitud', 'tarea'], true);
    }

    public function nombreParaAuditoria(): string
    {
        return 'Comentario en ' . class_basename($this->comentable_type) . " #{$this->comentable_id}";
    }
}
