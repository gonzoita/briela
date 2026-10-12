<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una corrida de un agente, de principio a fin.
 */
class AgenteEjecucion extends Model
{
    protected $table = 'agente_ejecuciones';

    public const EN_CURSO = ['en_cola', 'corriendo'];

    protected $fillable = [
        'agente_id', 'estado', 'origen', 'prueba', 'disparado_por', 'tarea', 'modelo_usado', 'pasos',
        'tokens_entrada', 'tokens_salida', 'resumen', 'resultado', 'error', 'clave_idempotencia',
        'iniciada_at', 'terminada_at',
    ];

    protected $casts = [
        'prueba'       => 'boolean',
        'resultado'    => 'array',
        'iniciada_at'  => 'datetime',
        'terminada_at' => 'datetime',
    ];

    public function agente(): BelongsTo
    {
        return $this->belongsTo(AgenteInterno::class, 'agente_id')->withTrashed();
    }

    public function disparador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disparado_por');
    }

    public function registroPasos(): HasMany
    {
        return $this->hasMany(AgentePaso::class, 'ejecucion_id')->orderBy('orden');
    }

    public function propuestas(): HasMany
    {
        return $this->hasMany(AgentePropuesta::class, 'ejecucion_id');
    }

    public function scopeEnCurso(Builder $q): Builder
    {
        return $q->whereIn('estado', self::EN_CURSO);
    }

    public function enCurso(): bool
    {
        return in_array($this->estado, self::EN_CURSO, true);
    }
}
