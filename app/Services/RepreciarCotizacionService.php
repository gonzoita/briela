<?php

namespace App\Services;

use App\Models\Cotizacion;
use App\Models\Ensamble;
use App\Models\Producto;

/**
 * Lleva los precios de una cotización al canal que le corresponde a su cliente.
 *
 * El precio de lista de un producto depende del segmento del cliente (su canal en Segmentación):
 * al **duplicar** una cotización —o cambiarle el cliente— los precios copiados eran los del cliente
 * anterior. Aquí se vuelven a tomar de las filas por canal del producto, con la misma regla que
 * usa la pantalla (`Cotizaciones/Create.vue`): el canal propio del cliente y, si no tiene, el de
 * precio público.
 *
 * Qué se reprecia: las líneas de **producto** y de **ensamble directo** (receta escrita a mano),
 * cuyo precio es una fila guardada. Un ensamble medido se cotizó con sus medidas y una receta
 * congelada: su precio sale de ese cálculo, no de una fila, y no se toca aquí.
 *
 * Lo negociado no se pierde: el descuento y la comisión se conservan y solo se **recortan** si
 * salen del rango del canal nuevo —igual que la pantalla—. El canal base no paga comisión ni
 * admite descuento.
 */
class RepreciarCotizacionService
{
    public function __construct(
        private CanalesPrecioService $canales,
        private PreciosPorCanalService $precios,
    ) {}

    /**
     * @return array{lineas: int, canal: ?string}  cuántos precios cambiaron y con qué canal
     */
    public function aplicar(Cotizacion $cot): array
    {
        $cot->loadMissing(['items', 'cliente']);

        $canal = $this->canales->paraCliente($cot->cliente) ?? $this->canales->publico();

        if (! $canal) {
            return ['lineas' => 0, 'canal' => null];
        }

        $base    = $this->canales->base();
        $cambios = 0;

        foreach ($cot->items as $item) {
            $fuente = match (true) {
                (bool) $item->producto_id => Producto::with('preciosPorCanal')->find($item->producto_id),
                (bool) $item->ensamble_id => ($e = Ensamble::with('preciosPorCanal')->find($item->ensamble_id)) && $e->esDirecto() ? $e : null,
                default                   => null,
            };

            $precio = $fuente ? $this->precios->precioDe($fuente, $canal) : null;

            if (! $fuente || $precio === null || $precio <= 0) {
                continue;
            }

            $fila = $fuente->precioDeCanal($canal);
            $esBase = (bool) $canal->es_canal_base;

            $descMax = $esBase ? 0.0 : (float) ($fila->descuento_max_pct ?? 0);
            $descuento = min(max((float) $item->descuento_pct, 0.0), $descMax);

            $min = $esBase ? 0.0 : (float) ($fila->comision_min_pct ?? 0);
            $max = $esBase ? 0.0 : (float) ($fila->comision_max_pct ?? 0);
            $aplicada = (float) $item->comision_pct_aplicada;
            // Nada negociado arranca en el máximo; lo negociado se respeta dentro del rango.
            $comision = ($aplicada > 0 || $descuento > 0) ? min(max($aplicada, $min), $max) : $max;

            $cantidad = (float) $item->cantidad;
            $bruto    = $cantidad * $precio;
            $neto     = $bruto - $bruto * ($descuento / 100);
            $impuesto = $neto * ((float) $item->impuesto_pct / 100);

            $precioBase = $base ? (float) ($fuente->precioDeCanal($base)->precio ?? 0) : 0.0;

            $cambio = abs((float) $item->precio_unitario - $precio) >= 0.005;

            $item->update([
                'precio_unitario'       => $precio,
                'precio_mayorista_base' => $precioBase > 0 ? $precioBase : $item->precio_mayorista_base,
                'descuento_pct'         => $descuento,
                'subtotal'              => $bruto,
                'impuesto_valor'        => $impuesto,
                'total_linea'           => $neto + $impuesto,
                'comision_pct_aplicada' => $comision,
                'comision_valor'        => $precio * $cantidad * ($comision / 100),
            ]);

            $cambios += $cambio ? 1 : 0;
        }

        $cot->load('items')->recalcularTotales();

        return ['lineas' => $cambios, 'canal' => $canal->etiqueta];
    }
}
