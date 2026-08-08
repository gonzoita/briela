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
        'comentable_type', 'comentable_id', 'user_id', 'contenido',
        'tipo', 'estado', 'asignado_a', 'fecha_limite',
        'resuelto_at', 'resuelto_por', 'mencionados',
    ];

    protected $casts = [
        'mencionados'  => 'array',
        'fecha_limite' => 'date',
        'resuelto_at'  => 'datetime',
    ];

    public function comentable()
    {
        return $this->morphTo();
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
