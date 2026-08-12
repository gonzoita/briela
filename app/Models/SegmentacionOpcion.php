<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SegmentacionOpcion extends Model
{
    protected $table = 'segmentacion_opciones';

    protected $fillable = [
        'tipo',
        'valor',
        'etiqueta',
        'color',
        'orden',
        'activo',
        'define_precio',
        'es_canal_base',
        'es_precio_publico',
        'margen_sugerido',
    ];

    protected $casts = [
        'activo'            => 'boolean',
        'orden'             => 'integer',
        'define_precio'     => 'boolean',
        'es_canal_base'     => 'boolean',
        'es_precio_publico' => 'boolean',
        'margen_sugerido'   => 'decimal:2',
    ];

    protected $appends = ['atada_a_precios'];

    /**
     * ¿Esta opción está atada a los precios y por eso no se puede borrar?
     *
     * Antes eran dos textos escritos aquí —`mayorista` y `distribuidor`—, comparados a
     * mano en `Cotizaciones/Create.vue`. Ahora lo dice el dato: cualquier tipo de
     * contacto que defina precio queda protegido, sea uno de los tres originales o uno
     * que la empresa haya creado.
     *
     * Borrar una opción con precios dejaría a sus clientes sin canal, y el sistema los
     * pasaría a cotizar sin precio sin que nadie se entere.
     */
    public function getAtadaAPreciosAttribute(): bool
    {
        return $this->tipo === 'tipo_contacto' && (bool) $this->define_precio;
    }

    public function precios(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CanalPrecio::class, 'segmentacion_opcion_id');
    }

    public function scopePorTipo(Builder $query, string $tipo): Builder
    {
        return $query->where('tipo', $tipo)->where('activo', true)->orderBy('orden');
    }

    /** Los canales de precio, en el orden que decide la prioridad. */
    public function scopeCanalesDePrecio(Builder $query): Builder
    {
        return $query->where('tipo', 'tipo_contacto')
            ->where('activo', true)
            ->where('define_precio', true)
            ->orderBy('orden');
    }
}
