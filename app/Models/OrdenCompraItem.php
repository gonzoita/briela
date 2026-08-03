<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrdenCompraItem extends Model
{
    protected $table = 'ordenes_compra_items';

    protected $fillable = [
        'orden_id', 'item_id', 'descripcion', 'cantidad',
        'cantidad_recibida', 'unidad', 'precio_unitario',
        'impuesto_pct', 'total_linea',
    ];

    protected $casts = [
        'cantidad'          => 'decimal:3',
        'cantidad_recibida' => 'decimal:3',
        'precio_unitario'   => 'decimal:2',
        'impuesto_pct'      => 'decimal:2',
        'total_linea'       => 'decimal:2',
    ];

    public function orden(): BelongsTo
    {
        return $this->belongsTo(OrdenCompra::class, 'orden_id');
    }

    // item_id apunta a productos (es_insumo), el inventario real — antes
    // apuntaba a la tabla paralela inventario_items (ver migración
    // 2026_07_23_000002 y docs/manual/compras-inventario.md).
    public function item(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'item_id');
    }
}
