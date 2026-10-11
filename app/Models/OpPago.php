<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpPago extends Model
{
    protected $table = 'op_pagos';

    protected $fillable = [
        'op_id', 'cuota_id', 'numero_recibo', 'valor',
        'medio_pago', 'fecha_pago', 'referencia', 'notas', 'registrado_por', 'verificado_at', 'verificado_por',
    ];

    protected $casts = [
        // `date:Y-m-d` y no `date`: sin el formato esto se serializa como
        // «2026-08-10T00:00:00.000000Z», y un `<input type="date"» exige «2026-08-10».
        // El navegador rechaza el valor y solo lo dice en la consola: el campo se ve
        // vacío y el usuario no puede leer ni corregir una fecha que sí está guardada.
        'fecha_pago' => 'date:Y-m-d',
        'valor'      => 'decimal:2',
        // Cuándo el banco confirmó que este dinero entró. Nulo: alguien lo anotó, sin confirmación.
        'verificado_at' => 'datetime',
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

    /**
     * Registra un pago de la orden: su recibo, lo que abona a la cuota y el saldo de la orden.
     * Un solo sitio: lo usan la cartera de la orden y la guía.
     *
     * @param  array{valor: float|int|string, medio_pago: string, fecha_pago: string, cuota_id?: ?int,
     *               referencia?: ?string, notas?: ?string}  $datos
     */
    /**
     * Anotar, verificar o borrar un pago puede dejar la orden pagada, o devolverla a deber: sus
     * remisiones entregadas se cierran o se reabren. Va en el modelo para que ningún camino
     * —la cartera, el verificador, la guía— se lo salte.
     */
    protected static function booted(): void
    {
        $sincronizar = fn (OpPago $pago) => app(\App\Services\RemisionService::class)->sincronizarCierre($pago->op);

        static::saved($sincronizar);
        static::deleted($sincronizar);
    }

    public static function registrar(Op $op, array $datos): self
    {
        $pago = \Illuminate\Support\Facades\DB::transaction(function () use ($datos, $op) {
            $pago = static::create([
                ...$datos,
                'op_id'          => $op->id,
                'numero_recibo'  => 'REC-TMP',
                'registrado_por' => auth()->id(),
            ]);

            $pago->numero_recibo = 'REC-' . str_pad($pago->id, 4, '0', STR_PAD_LEFT);
            $pago->save();

            if (! empty($datos['cuota_id'])) {
                $cuota = OpCuota::find($datos['cuota_id']);
                $cuota->valor_pagado += $datos['valor'];
                $cuota->estado = $cuota->valor_pagado >= $cuota->valor ? 'pagado' : 'parcial';
                $cuota->save();
            }

            return $pago;
        });

        $op->sincronizarSaldoPendiente();

        return $pago;
    }
}
