<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un precio que un proveedor tuvo para un producto, en el momento en que una orden de compra
 * salió con él. La tabla `producto_proveedor` guarda solo el último; esta guarda el rastro.
 */
class ProductoProveedorPrecio extends Model
{
    protected $table = 'producto_proveedor_precios';

    protected $fillable = ['producto_id', 'proveedor_id', 'precio', 'orden_compra_id', 'registrado_el'];

    protected function casts(): array
    {
        return [
            'precio'        => 'decimal:2',
            'registrado_el' => 'date:Y-m-d',
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
}
