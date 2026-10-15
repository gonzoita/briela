<?php

namespace App\Services;

use App\Models\Importacion;
use App\Models\ImportacionPago;

/**
 * La diferencia en cambio de una importación: cuánto más (o menos) costó pagarle al proveedor
 * que lo que dice el costo puesto en bodega.
 *
 * El costo sale con la tasa de la declaración de importación (`Importacion::tasaDelCosto()`).
 * Cada pago al proveedor se hace a la tasa de su día, y la distancia entre las dos es un
 * resultado financiero —ganancia o pérdida en cambio—, **no** un mayor o menor costo del
 * producto. Por eso esta cuenta no toca la liquidación: se muestra al lado.
 *
 *     diferencia del pago = valor × (tasa del pago − tasa del costo)
 *
 * Positiva es **pérdida** (se pagaron más pesos de los que dice el costo); negativa, ganancia.
 *
 * Lo que se le debe al proveedor es la factura: las líneas del embarque en su moneda. El flete y
 * los gastos se pagan a otros (la naviera, el agente de aduana) y no entran aquí.
 */
class DiferenciaCambioService
{
    /**
     * @return array{aplica: bool, moneda: string, tasa_costo: float, factura: float, pagado: float,
     *   saldo: float, pagado_base: float, al_costo_base: float, diferencia: float, resultado: string|null,
     *   pagos: list<array<string, mixed>>, avisos: list<string>}
     */
    public function resumen(Importacion $imp): array
    {
        $imp->loadMissing(['items', 'pagos']);

        $tasaCosto = $imp->tasaDelCosto();
        $factura   = round($imp->items->sum(fn ($i) => (float) $i->cantidad * (float) $i->valor_unitario), 2);

        $pagos = $imp->pagos->map(function (ImportacionPago $p) use ($tasaCosto) {
            $valor = (float) $p->valor;
            $tasa  = (float) $p->tasa;

            return [
                'id'         => $p->id,
                'fecha'      => $p->fecha?->toDateString(),
                'concepto'   => $p->concepto,
                'etiqueta'   => ImportacionPago::CONCEPTOS[$p->concepto] ?? ucfirst((string) $p->concepto),
                'valor'      => $valor,
                'tasa'       => $tasa,
                'referencia' => $p->referencia,
                'notas'      => $p->notas,
                'en_base'    => round($valor * $tasa, 2),
                'diferencia' => round($valor * ($tasa - $tasaCosto), 2),
            ];
        })->values()->all();

        $pagado     = round(array_sum(array_column($pagos, 'valor')), 2);
        $pagadoBase = round(array_sum(array_column($pagos, 'en_base')), 2);
        $diferencia = round(array_sum(array_column($pagos, 'diferencia')), 2);
        $saldo      = round($factura - $pagado, 2);

        $avisos = [];
        if ($saldo < -0.009) {
            $avisos[] = 'Lo pagado supera la factura del embarque. Revisa si algún pago incluía flete u otro concepto.';
        }
        if ((float) $imp->tasa_nacionalizacion <= 0 && $pagos !== []) {
            $avisos[] = 'Falta la tasa de la declaración de importación: la diferencia se está midiendo contra la tasa de la factura.';
        }

        return [
            'aplica'        => $imp->moneda !== \App\Support\Monedas::base(),
            'moneda'        => (string) $imp->moneda,
            'tasa_costo'    => $tasaCosto,
            'factura'       => $factura,
            'pagado'        => $pagado,
            'saldo'         => $saldo,
            'pagado_base'   => $pagadoBase,
            'al_costo_base' => round($pagado * $tasaCosto, 2),
            'diferencia'    => $diferencia,
            'resultado'     => abs($diferencia) < 0.01 ? null : ($diferencia > 0 ? 'perdida' : 'ganancia'),
            'pagos'         => $pagos,
            'avisos'        => $avisos,
        ];
    }
}
