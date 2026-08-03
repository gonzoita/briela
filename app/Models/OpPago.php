<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpPago extends Model
{
    protected $table = 'op_pagos';

    protected $fillable = [
        'op_id', 'cuota_id', 'numero_recibo', 'valor',
        'medio_pago', 'fecha_pago', 'referencia', 'notas', 'registrado_por',
    ];

    protected $casts = [
        'fecha_pago' => 'date',
        'valor'      => 'decimal:2',
    ];

    public function op()
    {
        return $this->belongsTo(Op::class);
    }

    public function cuota()
    {
        return $this->belongsTo(OpCuota::class, 'cuota_id');
    }

    public function registradoPor()
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
