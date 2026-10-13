<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un flujo «cuando pasa esto, haz aquello». Ver `App\Services\Automatizaciones`.
 */
class Automatizacion extends Model
{
    use SoftDeletes;

    protected $table = 'automatizaciones';

    protected $fillable = [
        'nombre', 'descripcion', 'activa', 'grafo', 'version', 'disparador_tipo', 'disparador_clave',
        'max_por_hora', 'fallas_seguidas', 'creado_por', 'ultima_ejecucion_at', 'ultima_ventana',
    ];

    protected $casts = [
        'activa'              => 'boolean',
        'grafo'               => 'array',
        'ultima_ejecucion_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // El mapa «evento → automatizaciones activas» se recuerda por petición (en el contenedor,
        // nunca en una estática); cualquier cambio lo descarta.
        $olvidar = fn () => app()->forgetInstance('briela.automatizaciones.activas');

        static::saved($olvidar);
        static::deleted($olvidar);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por')->withoutGlobalScopes();
    }

    public function ejecuciones(): HasMany
    {
        return $this->hasMany(AutomatizacionEjecucion::class, 'automatizacion_id');
    }

    /** El nodo disparador del grafo. */
    public function disparador(): ?array
    {
        return collect($this->grafo['nodos'] ?? [])->firstWhere('tipo', 'disparador');
    }
}
