<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una corrida de una automatización, con su propia copia del grafo.
 */
class AutomatizacionEjecucion extends Model
{
    protected $table = 'automatizacion_ejecuciones';

    public const EN_CURSO = ['en_cola', 'corriendo', 'esperando'];

    protected $fillable = [
        'automatizacion_id', 'estado', 'origen', 'evento', 'grafo', 'contexto', 'variables', 'entidad_tipo', 'entidad_id',
        'profundidad', 'nodo_actual', 'reanudar_at', 'error', 'clave_idempotencia', 'disparado_por',
        'iniciada_at', 'terminada_at',
    ];

    protected $casts = [
        'grafo'        => 'array',
        'contexto'     => 'array',
        'variables'    => 'array',
        'reanudar_at'  => 'datetime',
        'iniciada_at'  => 'datetime',
        'terminada_at' => 'datetime',
    ];

    public function automatizacion(): BelongsTo
    {
        return $this->belongsTo(Automatizacion::class, 'automatizacion_id')->withTrashed();
    }

    public function pasos(): HasMany
    {
        return $this->hasMany(AutomatizacionPaso::class, 'ejecucion_id')->orderBy('orden');
    }

    public function aprobaciones(): HasMany
    {
        return $this->hasMany(AutomatizacionAprobacion::class, 'ejecucion_id');
    }

    public function scopeEnCurso(Builder $q): Builder
    {
        return $q->whereIn('estado', self::EN_CURSO);
    }
}
