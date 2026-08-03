<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperarioTarifaExtra extends Model
{
    protected $table = 'operario_tarifas_extra';

    protected $fillable = [
        'tipo',
        'valor_hora',
        'activo',
    ];

    protected $casts = [
        'activo'     => 'boolean',
        'valor_hora' => 'decimal:2',
    ];
}
