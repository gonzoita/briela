<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un pago al proveedor del exterior, con la tasa del día en que se hizo. */
class ImportacionPago extends Model
{
    protected $table = 'importacion_pagos';

    public const CONCEPTOS = ['anticipo' => 'Anticipo', 'saldo' => 'Saldo', 'pago' => 'Pago'];

    protected $fillable = ['importacion_id', 'fecha', 'concepto', 'valor', 'tasa', 'referencia', 'notas', 'creado_por'];

    protected $casts = [
        'fecha' => 'date:Y-m-d',
        'valor' => 'decimal:2',
        'tasa'  => 'decimal:8',
    ];

    public function importacion(): BelongsTo
    {
        return $this->belongsTo(Importacion::class);
    }
}
