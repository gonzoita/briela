<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperarioHito extends Model
{
    protected $table = 'operario_hitos';

    protected $fillable = [
        'operario_id',
        'nombre',
        'tipo',
        'meta_valor',
        'meta_tipo',
        'valor_bono',
        'periodo_mes',
        'periodo_anio',
        'cumplido',
        'cumplido_at',
    ];

    protected $casts = [
        'meta_valor'  => 'decimal:2',
        'valor_bono'  => 'decimal:2',
        'cumplido'    => 'boolean',
        'cumplido_at' => 'datetime',
    ];

    public function operario(): BelongsTo
    {
        return $this->belongsTo(Operario::class);
    }
}
