<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * La tasa de una moneda en un día: cuántos pesos vale una unidad.
 */
class TasaCambio extends Model
{
    protected $table = 'tasas_cambio';

    protected $fillable = ['moneda', 'fecha', 'valor', 'fuente'];

    protected $casts = [
        'fecha' => 'date',
        'valor' => 'decimal:4',
    ];
}
