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
        // `date:Y-m-d` y no `date`: sin el formato esto se serializa como
        // «2026-08-10T00:00:00.000000Z», y un `<input type="date"» exige «2026-08-10».
        // El navegador rechaza el valor y solo lo dice en la consola: el campo se ve
        // vacío y el usuario no puede leer ni corregir una fecha que sí está guardada.
        'fecha_pago' => 'date:Y-m-d',
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
