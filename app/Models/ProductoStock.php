<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductoStock extends Model
{
    protected $table = 'producto_stock';

    protected $fillable = ['producto_id', 'bodega_id', 'cantidad'];

    protected $casts = [
        'cantidad' => 'decimal:3',
    ];

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function bodega(): BelongsTo
    {
        return $this->belongsTo(Bodega::class);
    }
}
