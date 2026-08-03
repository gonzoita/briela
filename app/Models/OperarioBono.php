<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperarioBono extends Model
{
    protected $table = 'operario_bonos';

    protected $fillable = [
        'operario_id',
        'periodo_mes',
        'periodo_anio',
        'pasos_valor',
        'hitos_valor',
        'extras_valor',
        'penalizaciones',
        'total_bono',
        'calculado_at',
        'detalle',
    ];

    protected $casts = [
        'pasos_valor'    => 'decimal:2',
        'hitos_valor'    => 'decimal:2',
        'extras_valor'   => 'decimal:2',
        'penalizaciones' => 'decimal:2',
        'total_bono'     => 'decimal:2',
        'calculado_at'   => 'datetime',
        'detalle'        => 'array',
    ];

    public function operario(): BelongsTo
    {
        return $this->belongsTo(Operario::class);
    }
}
