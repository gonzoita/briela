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
     * ¿Esta opción no se puede borrar?
     *
     * Solo dos: el **canal base** y el **precio público**. El sistema necesita saber cuál
     * es el piso de utilidad —contra él se calculan las comisiones— y qué precio ve alguien
     * que no ha entrado. Sin uno de los dos, las comisiones salen en cero y el catálogo no
     * sabe qué mostrar.
     *
     * Los nombres los pone la empresa: no tienen por qué ser «Mayorista» ni «Distribuidor».
     * Lo que está atado es el papel, no la etiqueta.
     *
     * Todos los demás canales se crean, se borran y se renombran libremente. Antes estaban
     * protegidos todos los que definieran precio, y eso dejaba la lista intocable en cuanto
     * la empresa marcaba unos cuantos.
     *
     * Para borrar el canal base o el precio público, primero se marca otro: al marcarlo, el
     * anterior se libera solo.
     */
    public function getAtadaAPreciosAttribute(): bool
    {
        return $this->tipo === 'tipo_contacto'
            && ((bool) $this->es_canal_base || (bool) $this->es_precio_publico);
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
