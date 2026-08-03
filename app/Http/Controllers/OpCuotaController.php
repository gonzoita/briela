<?php

namespace App\Http\Controllers;

use App\Models\Op;
use App\Models\OpCuota;
use Illuminate\Http\Request;

class OpCuotaController extends Controller
{
    public function index(Op $op)
    {
        $op->sincronizarSaldoPendiente();

        return response()->json([
            'cuotas' => $op->cuotas()->with('pagos')->get()->map(fn ($c) => [
                'id'                  => $c->id,
                'numero_cuota'        => $c->numero_cuota,
                'concepto'            => $c->concepto,
                'valor'               => $c->valor,
                'valor_pagado'        => $c->valor_pagado,
                'saldo'               => $c->saldo,
                'fecha_vencimiento'   => $c->fecha_vencimiento?->format('Y-m-d'),
                'estado'              => $c->estado,
                'semaforo'            => $c->semaforo,
                'es_saldo_automatico' => (bool) $c->es_saldo_automatico,
                'pagos'               => $c->pagos->map(fn ($p) => [
                    'id'            => $p->id,
                    'numero_recibo' => $p->numero_recibo,
                    'valor'         => $p->valor,
                    'medio_pago'    => $p->medio_pago,
                    'fecha_pago'    => $p->fecha_pago?->format('Y-m-d'),
                    'referencia'    => $p->referencia,
                    'notas'         => $p->notas,
                ]),
            ]),
            'total_op'        => (float) ($op->total ?? 0),
            'total_pagado'    => $op->total_pagado,
            'saldo_pendiente' => $op->saldo_pendiente,
        ]);
    }

    public function store(Request $request, Op $op)
    {
        $data = $request->validate([
            'concepto'          => 'required|string|max:200',
            'valor'             => 'required|numeric|min:0',
            'fecha_vencimiento' => 'nullable|date',
        ]);

        $numero = ($op->cuotas()->max('numero_cuota') ?? 0) + 1;

        $cuota = OpCuota::create([
            ...$data,
            'op_id'               => $op->id,
            'numero_cuota'        => $numero,
            'estado'              => 'pendiente',
            'valor_pagado'        => 0,
            'es_saldo_automatico' => false,
        ]);

        $op->sincronizarSaldoPendiente();

        return response()->json([
            'cuota'    => $cuota,
            'semaforo' => $cuota->semaforo,
        ]);
    }

    public function update(Request $request, OpCuota $cuota)
    {
        if ($cuota->es_saldo_automatico) {
            return response()->json(
                ['error' => 'La cuota de saldo automático no se puede editar manualmente'],
                422
            );
        }

        $data = $request->validate([
            'concepto'          => 'required|string|max:200',
            'valor'             => 'required|numeric|min:0',
            'fecha_vencimiento' => 'nullable|date',
        ]);

        $cuota->update($data);

        return response()->json([
            'cuota'    => $cuota->fresh(),
            'semaforo' => $cuota->semaforo,
        ]);
    }

    public function destroy(OpCuota $cuota)
    {
        if ($cuota->es_saldo_automatico) {
            return response()->json(
                ['error' => 'La cuota de saldo automático no se puede eliminar manualmente'],
                422
            );
        }

        if ($cuota->valor_pagado > 0) {
            return response()->json(
                ['error' => 'No se puede eliminar una cuota con pagos registrados'],
                422
            );
        }

        $cuota->delete();

        return response()->json(['ok' => true]);
    }
}
