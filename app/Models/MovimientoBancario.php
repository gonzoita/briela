<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Dinero que entró a una cuenta de la empresa, según el banco. Ver {@see \App\Services\VerificadorPagosService}. */
class MovimientoBancario extends Model
{
    protected $table = 'movimientos_bancarios';

    protected $fillable = [
        'cuenta_bancaria_id', 'id_externo', 'huella', 'fecha', 'valor', 'descripcion', 'referencia',
        'contraparte', 'estado', 'op_pago_id', 'conciliado_por', 'conciliado_at', 'automatico', 'nota', 'origen',
    ];

    protected $casts = [
        // `date:Y-m-d` y no `date`: un `<input type="date">` exige «2026-08-10».
        'fecha'         => 'date:Y-m-d',
        'valor'         => 'decimal:2',
        'conciliado_at' => 'datetime',
        'automatico'    => 'boolean',
    ];

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(CuentaBancaria::class, 'cuenta_bancaria_id');
    }

    public function pago(): BelongsTo
    {
        return $this->belongsTo(OpPago::class, 'op_pago_id');
    }

    public function conciliadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'conciliado_por');
    }
}
