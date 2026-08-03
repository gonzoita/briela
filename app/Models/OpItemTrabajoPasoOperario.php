<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpItemTrabajoPasoOperario extends Model
{
    protected $table = 'op_item_trabajo_paso_operarios';

    protected $fillable = [
        'op_item_trabajo_paso_id',
        'operario_id',
        'tiempo_minutos',
        'observaciones',
    ];

    public function paso(): BelongsTo
    {
        return $this->belongsTo(OpItemTrabajoPaso::class, 'op_item_trabajo_paso_id');
    }

    public function operario(): BelongsTo
    {
        return $this->belongsTo(Operario::class);
    }
}
