<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un grupo de chat: varias personas conversando sobre un tema (producción,
 * compras, una obra concreta).
 *
 * Los mensajes viven en `comentarios` con `grupo_id`, igual que los directos
 * y los de documento. Ver la migración de chat_grupos.
 */
class ChatGrupo extends Model
{
    use Auditable;

    protected $table = 'chat_grupos';

    protected $fillable = ['nombre', 'descripcion', 'creado_por', 'activo'];

    protected $casts = ['activo' => 'boolean'];

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function miembros(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'chat_grupo_usuario')
            ->withPivot('leido_hasta')
            ->withTimestamps();
    }

    public function mensajes(): HasMany
    {
        return $this->hasMany(Comentario::class, 'grupo_id');
    }

    /** Grupos donde participa una persona. */
    public function scopeDe($query, int $userId)
    {
        return $query->where('activo', true)
            ->whereHas('miembros', fn ($q) => $q->where('users.id', $userId));
    }

    public function tieneMiembro(int $userId): bool
    {
        return $this->miembros()->where('users.id', $userId)->exists();
    }

    public function nombreParaAuditoria(): string
    {
        return "Grupo de chat {$this->nombre}";
    }
}
