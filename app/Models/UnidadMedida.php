<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Una unidad de medida configurable por la empresa.
 *
 * `clave` es lo que se guarda en `productos.unidad_medida` y lo que se lee al lado de una
 * cantidad; `etiqueta` es lo que se ve en el selector. La clave no se cambia después de
 * creada: hay productos que la guardaron.
 */
class UnidadMedida extends Model
{
    protected $table = 'unidades_medida';

    protected $fillable = ['clave', 'etiqueta', 'tipo', 'orden', 'activo'];

    protected $casts = [
        'orden'  => 'integer',
        'activo' => 'boolean',
    ];

    /**
     * Las que sirven para un tipo de ítem, en el orden que puso la empresa.
     *
     * «ambos» aparece en las dos listas: la unidad suelta sirve igual para un producto que
     * para un servicio.
     */
    public function scopeParaTipo(Builder $query, string $tipo): Builder
    {
        return $query->where('activo', true)
            ->whereIn('tipo', [$tipo, 'ambos'])
            ->orderBy('orden')
            ->orderBy('etiqueta');
    }

    /**
     * Cuántos productos usan esta unidad.
     *
     * No es una relación de base de datos —`unidad_medida` es texto libre, sin llave
     * foránea— pero es lo que hay que decir antes de borrarla.
     */
    public function productosQueLaUsan(): int
    {
        return Producto::where('unidad_medida', $this->clave)->count();
    }
}
