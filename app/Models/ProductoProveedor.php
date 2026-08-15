<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * «Este proveedor me vende este producto a este precio.»
 *
 * Es la fila que permite comparar antes de comprar. `productos.proveedor_id` guarda uno solo
 * —el último al que se le compró— y esa comparación se venía haciendo por fuera del sistema.
 *
 * El precio más barato no gana solo: un precio de hace ocho meses no es un precio, y el que
 * llega en tres semanas no sirve para una OP de mañana. Por eso la fila lleva también los
 * días de entrega, el mínimo de compra y la fecha del precio.
 */
class ProductoProveedor extends Model
{
    protected $table = 'producto_proveedor';

    protected $fillable = [
        'producto_id', 'proveedor_id', 'referencia_proveedor',
        'precio', 'dias_entrega', 'minimo_compra',
        'es_preferido', 'actualizado_el', 'notas',
    ];

    protected function casts(): array
    {
        return [
            'precio'         => 'decimal:2',
            'minimo_compra'  => 'decimal:3',
            'es_preferido'   => 'boolean',
            'actualizado_el' => 'date',
        ];
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    /**
     * ¿Hace cuánto se actualizó este precio? En días, o null si nunca se anotó.
     *
     * Existe para que la pantalla pueda avisar. Comparar tres cifras sin saber de cuándo son
     * da una respuesta con cara de exacta que puede estar completamente equivocada.
     */
    public function diasDesdeActualizacion(): ?int
    {
        // `diffInDays` devuelve un flotante con las horas: el molde a entero va explícito
        // para no depender de una conversión implícita, que en PHP 8.3 avisa y en una
        // versión próxima será un error.
        return $this->actualizado_el === null ? null : (int) $this->actualizado_el->diffInDays(now());
    }
}
