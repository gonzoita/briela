<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventarioMovimiento extends Model
{
    protected $table = 'inventario_movimientos';

    protected $fillable = [
        'item_id', 'tipo', 'cantidad', 'stock_anterior', 'stock_nuevo',
        'precio_unitario', 'origen_tipo', 'origen_id', 'usuario_id', 'notas',
    ];

    protected $casts = [
        'cantidad'        => 'decimal:3',
        'stock_anterior'  => 'decimal:3',
        'stock_nuevo'     => 'decimal:3',
        'precio_unitario' => 'decimal:2',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventarioItem::class, 'item_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
