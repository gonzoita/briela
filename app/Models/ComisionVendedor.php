<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComisionVendedor extends Model
{
    protected $table = 'comisiones_vendedor';

    protected $fillable = [
        'cotizacion_id',
        'user_id',
        'total_comision',
        'estado',
        'periodo_mes',
        'liquidada_at',
        'liquidacion_id',
    ];

    protected $casts = [
        'total_comision' => 'float',
        'liquidada_at'   => 'datetime',
    ];

    public function cotizacion(): BelongsTo
    {
        return $this->belongsTo(Cotizacion::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** En qué pago entró, si ya entró en alguno. */
    public function liquidacion(): BelongsTo
    {
        return $this->belongsTo(LiquidacionComision::class, 'liquidacion_id');
    }
}
