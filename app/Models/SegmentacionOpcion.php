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
    ];

    protected $casts = [
        'activo' => 'boolean',
        'orden'  => 'integer',
    ];

    protected $appends = ['atada_a_precios'];

    /**
     * Tipos de contacto que el cotizador usa para decidir el canal de precio.
     *
     * `Cotizaciones/Create.vue` compara contra estos textos exactos para elegir
     * entre precio mayorista, distribuidor o cliente final — y de ahí sale
     * también la comisión del vendedor. Si alguien borrara una de estas
     * opciones, los clientes que la tuvieran pasarían a cotizarse como cliente
     * final sin que nadie se entere, así que el borrado está bloqueado.
     */
    public const VALORES_CANAL_PRECIO = ['mayorista', 'distribuidor'];

    public function getAtadaAPreciosAttribute(): bool
    {
        return $this->tipo === 'tipo_contacto'
            && in_array($this->valor, self::VALORES_CANAL_PRECIO, true);
    }

    public function scopePorTipo(Builder $query, string $tipo): Builder
    {
        return $query->where('tipo', $tipo)->where('activo', true)->orderBy('orden');
    }
}
