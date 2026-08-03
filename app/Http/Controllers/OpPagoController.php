<?php

namespace App\Http\Controllers;

use App\Models\Op;
use App\Models\OpCuota;
use App\Models\OpPago;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OpPagoController extends Controller
{
    public function store(Request $request, Op $op)
    {
        $data = $request->validate([
            'cuota_id'   => 'nullable|exists:op_cuotas,id',
            'valor'      => 'required|numeric|min:1',
            'medio_pago' => 'required|in:efectivo,transferencia,cheque',
            'fecha_pago' => 'required|date',
            'referencia' => 'nullable|string|max:200',
            'notas'      => 'nullable|string',
        ]);

        $pago = null;

        DB::transaction(function () use ($data, $op, &$pago) {
            $pago = OpPago::create([
                ...$data,
                'op_id'          => $op->id,
                'numero_recibo'  => 'REC-TMP',
                'registrado_por' => auth()->id(),
            ]);

            $pago->numero_recibo = 'REC-' . str_pad($pago->id, 4, '0', STR_PAD_LEFT);
            $pago->save();

            if (!empty($data['cuota_id'])) {
                $cuota = OpCuota::find($data['cuota_id']);
                $cuota->valor_pagado += $data['valor'];
                $cuota->estado = $cuota->valor_pagado >= $cuota->valor ? 'pagado' : 'parcial';
                $cuota->save();
            }
        });

        $op->sincronizarSaldoPendiente();

        return response()->json([
            'pago'          => $pago->load('cuota', 'registradoPor'),
            'numero_recibo' => $pago->numero_recibo,
        ]);
    }

    public function pdf(OpPago $pago)
    {
        $pago->load('op.cliente', 'cuota', 'registradoPor');

        $pdf = Pdf::loadView('pdf.recibo-pago', ['pago' => $pago])
            ->setPaper('a5', 'portrait');

        return $pdf->download("REC-{$pago->numero_recibo}.pdf");
    }

    public function destroy(OpPago $pago)
    {
        DB::transaction(function () use ($pago) {
            if ($pago->cuota_id) {
                $cuota = OpCuota::find($pago->cuota_id);
                $cuota->valor_pagado = max(0, $cuota->valor_pagado - $pago->valor);
                $cuota->estado = $cuota->valor_pagado <= 0 ? 'pendiente' : 'parcial';
                $cuota->save();
            }
            $pago->delete();
        });

        return response()->json(['ok' => true]);
    }
}
