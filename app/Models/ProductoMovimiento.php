<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductoMovimiento extends Model
{
    protected $table = 'producto_movimientos';

    protected $fillable = [
        'producto_id',
        'bodega_id',
        'tipo',
        'cantidad',
        'stock_anterior',
        'stock_nuevo',
        'bodega_destino_id',
        'precio_unitario',
        'origen_tipo',
        'origen_id',
        'usuario_id',
        'notas',
    ];

    protected $casts = [
        'cantidad'       => 'decimal:3',
        'stock_anterior' => 'decimal:3',
        'stock_nuevo'    => 'decimal:3',
        'precio_unitario'=> 'decimal:2',
    ];

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function bodega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class);
    }

    public function bodegaDestino(): BelongsTo
    {
        return $this->belongsTo(Bodega::class, 'bodega_destino_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
