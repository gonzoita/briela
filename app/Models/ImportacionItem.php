<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportacionItem extends Model
{
    protected $table = 'importacion_items';

    protected $fillable = [
        'importacion_id', 'producto_id', 'descripcion', 'cantidad', 'valor_unitario', 'peso_kg',
        'codigo_hs', 'arancel_pct', 'iva_pct', 'orden',
        'fob', 'flete_seguro', 'cif', 'arancel', 'iva', 'gastos', 'costo_total', 'costo_unitario', 'aplicado_at',
    ];

    protected $casts = [
        'cantidad'       => 'decimal:3',
        'valor_unitario' => 'decimal:4',
        'peso_kg'        => 'decimal:3',
        'arancel_pct'    => 'decimal:2',
        'iva_pct'        => 'decimal:2',
        'costo_unitario' => 'decimal:4',
        'aplicado_at'    => 'datetime',
    ];

    public function importacion(): BelongsTo
    {
        return $this->belongsTo(Importacion::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }
}
