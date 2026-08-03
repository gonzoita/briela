<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpItemComponente extends Model
{
    protected $table = 'op_item_componentes';

    protected $fillable = [
        'op_item_id',
        'producto_id',
        'nombre',
        'cantidad',
        'unidad',
        'es_informativo',
        'observacion',
        'parent_componente_id',
        'seccion',
    ];

    protected $casts = [
        'cantidad'       => 'decimal:3',
        'es_informativo' => 'boolean',
    ];

    public function opItem(): BelongsTo
    {
        return $this->belongsTo(OpItem::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function hijos(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OpItemComponente::class, 'parent_componente_id');
    }

    public function padre(): BelongsTo
    {
        return $this->belongsTo(OpItemComponente::class, 'parent_componente_id');
    }
}
