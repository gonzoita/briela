<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SolicitudCompraItem extends Model
{
    protected $table = 'solicitudes_compra_items';

    protected $fillable = [
        'solicitud_id', 'item_id', 'descripcion',
        'cantidad', 'unidad', 'precio_estimado', 'notas',
    ];

    protected $casts = [
        'cantidad'        => 'decimal:3',
        'precio_estimado' => 'decimal:2',
    ];

    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(SolicitudCompra::class, 'solicitud_id');
    }

    // item_id apunta a productos (es_insumo), el inventario real — antes
    // apuntaba a la tabla paralela inventario_items (ver migración
    // 2026_07_23_000002 y docs/manual/compras-inventario.md).
    public function item(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'item_id');
    }
}
