<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpCuota extends Model
{
    protected $table = 'op_cuotas';

    protected $fillable = [
        'op_id', 'numero_cuota', 'concepto', 'valor',
        'fecha_vencimiento', 'estado', 'valor_pagado',
        'es_saldo_automatico',
    ];

    protected $casts = [
        // `date:Y-m-d` y no `date`: sin el formato esto se serializa como
        // «2026-08-10T00:00:00.000000Z», y un `<input type="date"» exige «2026-08-10».
        // El navegador rechaza el valor y solo lo dice en la consola: el campo se ve
        // vacío y el usuario no puede leer ni corregir una fecha que sí está guardada.
        'fecha_vencimiento'  => 'date:Y-m-d',
        'valor'              => 'decimal:2',
        'valor_pagado'       => 'decimal:2',
        'es_saldo_automatico'=> 'boolean',
    ];

    public function op()
    {
        return $this->belongsTo(Op::class);
    }

    public function pagos()
    {
        return $this->hasMany(OpPago::class, 'cuota_id');
    }

    public function getSemaforoAttribute(): string
    {
        if ($this->estado === 'pagado') return 'verde';
        if (!$this->fecha_vencimiento) return 'gris';

        $diasAlerta = (int) (\App\Models\Configuracion::get('semaforo_dias_alerta', 5));

        $hoy   = now()->startOfDay();
        $vence = $this->fecha_vencimiento->startOfDay();
        $dias  = $hoy->diffInDays($vence, false);
        if ($dias < 0) return 'rojo';
        if ($dias <= $diasAlerta) return 'amarillo';
        return 'verde';
    }

    public function getSaldoAttribute(): float
    {
        return (float) $this->valor - (float) $this->valor_pagado;
    }
}
