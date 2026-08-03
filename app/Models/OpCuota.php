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
        'fecha_vencimiento'  => 'date',
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
