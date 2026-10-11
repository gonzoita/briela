<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Una cuenta del banco de la empresa, donde le pagan sus clientes. Nunca guarda el número completo. */
class CuentaBancaria extends Model
{
    protected $table = 'cuentas_bancarias';

    protected $fillable = ['nombre', 'banco', 'ultimos_digitos', 'moneda', 'activa', 'origen', 'referencia_externa'];

    protected $casts = ['activa' => 'boolean'];

    public function movimientos(): HasMany
    {
        return $this->hasMany(MovimientoBancario::class);
    }
}
