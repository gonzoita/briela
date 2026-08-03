<?php

namespace App\Services;

class ComisionService
{
    /**
     * Calcula el rango de comisión para un ítem dado el precio lista, precio de venta
     * y los parámetros de comisión configurados en el producto/ensamble.
     */
    public function calcularRango(
        float $precioLista,
        float $precioVenta,
        float $comisionMinPct,
        float $comisionMaxPct,
        float $descuentoMaxPct = 5.0
    ): array {
        if ($precioLista <= 0) {
            return [
                'comision_min'    => 0,
                'comision_max'    => 0,
                'comision_actual' => 0,
                'pct_actual'      => 0,
                'pct_del_maximo'  => 0,
            ];
        }

        $comisionMax = $precioLista * ($comisionMaxPct / 100);
        $comisionMin = $precioVenta  * ($comisionMinPct / 100);

        $descuentoPct   = (($precioLista - $precioVenta) / $precioLista) * 100;
        $rangoDescuento = max($descuentoMaxPct, 0.01);

        $factorDescuento = min($descuentoPct / $rangoDescuento, 1.0);
        $comisionActual  = $comisionMax - ($comisionMax - $comisionMin) * $factorDescuento;
        $comisionActual  = max($comisionMin, $comisionActual);

        $pctActual = $precioVenta > 0 ? ($comisionActual / $precioVenta) * 100 : 0;
        $pctMaximo = $comisionMax > 0 ? ($comisionActual / $comisionMax) * 100 : 0;

        return [
            'comision_min'    => (int) round($comisionMin),
            'comision_max'    => (int) round($comisionMax),
            'comision_actual' => (int) round($comisionActual),
            'pct_actual'      => round($pctActual, 2),
            'pct_del_maximo'  => (int) round($pctMaximo),
        ];
    }

    /**
     * Sugiere topes de comisión basándose en la utilidad disponible.
     */
    public function sugerirTopes(
        float $precioLista,
        float $costo,
        float $utilidadMinEmpresaPct,
        float $descuentoMaxPct
    ): array {
        if ($precioLista <= 0) {
            return [
                'pct_disponible_comision' => 0,
                'valor_disponible'        => 0,
                'sugerido_minimo'         => 0,
                'sugerido_maximo'         => 0,
                'advertencia'             => null,
            ];
        }

        $utilidadBruta      = $precioLista - $costo;
        $utilidadMinEmpresa = $precioLista * ($utilidadMinEmpresaPct / 100);
        $descuentoMax       = $precioLista * ($descuentoMaxPct / 100);
        $disponible         = $utilidadBruta - $utilidadMinEmpresa - $descuentoMax;
        $pctDisponible      = ($disponible / $precioLista) * 100;

        return [
            'pct_disponible_comision' => round($pctDisponible, 2),
            'valor_disponible'        => (int) round($disponible),
            'sugerido_minimo'         => round($pctDisponible * 0.4, 2),
            'sugerido_maximo'         => round($pctDisponible * 0.6, 2),
            'advertencia'             => $pctDisponible < 3
                ? 'Margen muy ajustado para comisiones'
                : null,
        ];
    }
}
