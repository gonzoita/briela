<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnsambleItem extends Model
{
    protected $table = 'ensamble_items';

    protected $fillable = ['ensamble_id', 'componente_id', 'cantidad', 'precio_costo_snapshot'];

    protected $casts = [
        'precio_costo_snapshot' => 'decimal:2',
    ];

    public function ensamble(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'ensamble_id');
    }

    public function componente(): BelongsTo
    {
        return $this->belongsTo(Producto::class, 'componente_id');
    }
}
