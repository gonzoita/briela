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

        $pago = OpPago::registrar($op, $data);

        return response()->json([
            'pago'          => $pago->load('cuota', 'registradoPor'),
            'numero_recibo' => $pago->numero_recibo,
        ]);
    }

    public function pdf(OpPago $pago)
    {
        $pdf = \App\Services\PdfPlantillaRenderer::paraRegistro(
            'recibo_pago', $pago, request()->integer('plantilla_id') ?: null
        );
        if ($pdf) {
            return $pdf->download("REC-{$pago->numero_recibo}.pdf");
        }

        $pago->load('op.cliente', 'cuota', 'registradoPor');

        $pdf = Pdf::loadView('pdf.recibo-pago', ['pago' => $pago])
            ->setPaper('a5', 'portrait');

        return $pdf->download("REC-{$pago->numero_recibo}.pdf");
    }

    /**
     * Verificar a mano un pago que no pasa por el banco: efectivo, cheque, un abono que alguien
     * vio entrar. Es lo que, con el saldo en cero, cierra las remisiones entregadas de la orden.
     */
    public function verificar(OpPago $pago)
    {
        if (! $pago->verificado_at) {
            $pago->update(['verificado_at' => now(), 'verificado_por' => auth()->id()]);
        }

        return response()->json(['ok' => true]);
    }

    /** Deshacer una verificación manual. La del banco se deshace en el verificador de pagos. */
    public function desverificar(OpPago $pago)
    {
        if ($pago->verificado_at && ! $pago->verificado_por) {
            return response()->json(['message' => 'Este pago lo confirmó el banco: se deshace desde el verificador de pagos.'], 422);
        }

        $pago->update(['verificado_at' => null, 'verificado_por' => null]);

        return response()->json(['ok' => true]);
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
            // Si el banco había confirmado este pago, el abono vuelve a la bandeja del verificador:
            // el dinero sigue en la cuenta aunque el pago ya no esté anotado.
            app(\App\Services\VerificadorPagosService::class)->pagoBorrado($pago->id);
            $pago->delete();
        });

        return response()->json(['ok' => true]);
    }
}
