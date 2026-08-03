<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RemisionItem extends Model
{
    protected $fillable = [
        'remision_id',
        'op_item_id',
        'producto_id',
        'descripcion',
        'cantidad',
        'unidad',
        'numero_serie',
        'notas',
    ];

    protected $casts = [
        'cantidad' => 'decimal:3',
    ];

    public function remision(): BelongsTo
    {
        return $this->belongsTo(Remision::class);
    }

    public function opItem(): BelongsTo
    {
        return $this->belongsTo(OpItem::class, 'op_item_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }
}
