<?php

namespace App\Services;

use App\Models\Cotizacion;
use App\Models\Producto;
use App\Support\Monedas;
use Illuminate\Support\Facades\DB;

/**
 * Lo que cambia cuando cambia la tasa: el costo de lo que se compra en otra moneda, sus
 * precios, y las cotizaciones abiertas que siguen a la TRM.
 *
 * Es el paso que sigue solo a traer la tasa del día. Sin él, un producto que cuesta
 * 1.000 euros quedaba con el costo en pesos del día en que alguien lo escribió, y cada
 * semana se vendía con un margen distinto sin que nadie lo decidiera.
 */
class CostosEnMonedaService
{
    public function __construct(
        private TasaCambioService $tasas,
        private PreciosPorCanalService $precios,
    ) {}

    /**
     * Recalcula el costo en pesos de todos los productos que cuestan en otra moneda.
     *
     * @return int cuántos productos cambiaron
     */
    public function actualizarProductos(): int
    {
        $cambiados = 0;

        Producto::where('moneda_costo', '!=', Monedas::LOCAL)
            ->where('costo_moneda', '>', 0)
            ->with('preciosPorCanal.canal')
            ->chunkById(100, function ($productos) use (&$cambiados) {
                foreach ($productos as $producto) {
                    if ($this->actualizarProducto($producto)) {
                        $cambiados++;
                    }
                }
            });

        return $cambiados;
    }

    /** Devuelve si el costo cambió. */
    public function actualizarProducto(Producto $producto): bool
    {
        $nuevo = $this->tasas->costoEnPesos((float) $producto->costo_moneda, (string) $producto->moneda_costo);

        if ($nuevo === null) {
            return false;
        }

        $anterior = (float) $producto->precio_costo;

        if (abs($nuevo - $anterior) < 0.01) {
            return false;
        }

        DB::transaction(function () use ($producto, $anterior, $nuevo) {
            $producto->update(['precio_costo' => $nuevo]);

            $filas = $this->filasRepreciadas($producto, $anterior, $nuevo);

            if ($filas !== []) {
                $this->precios->guardar($producto, $filas);
            }
        });

        return true;
    }

    /**
     * Las filas de precio del ítem, con el precio recalculado desde el costo nuevo.
     *
     * **Solo las que salían de la cuenta.** Un precio que no se reproduce con el costo
     * anterior y su margen lo escribió alguien, y se respeta: es la misma regla de
     * `precios:recalcular`. Un precio en cero no es una decisión, es un dato que falta, y ese
     * sí se calcula.
     *
     * @return list<array<string, mixed>>
     */
    public function filasRepreciadas(Producto $producto, float $costoAnterior, float $costoNuevo): array
    {
        return $producto->preciosPorCanal->map(function ($fila) use ($costoAnterior, $costoNuevo) {
            $margen = (float) $fila->margen_pct;
            $precio = (float) $fila->precio;

            $calculado = $precio <= 0
                || abs($this->precios->precioDesdeCosto($costoAnterior, $margen) - $precio) < 0.01;

            if ($margen > 0 && $calculado) {
                $precio = $this->precios->precioDesdeCosto($costoNuevo, $margen);
            }

            return [
                'segmentacion_opcion_id' => $fila->segmentacion_opcion_id,
                'margen_pct'             => $margen,
                'precio'                 => $precio,
                'comision_min_pct'       => (float) $fila->comision_min_pct,
                'comision_max_pct'       => (float) $fila->comision_max_pct,
                'descuento_max_pct'      => (float) $fila->descuento_max_pct,
            ];
        })->values()->all();
    }

    /**
     * Pone la tasa de hoy a las cotizaciones abiertas, si la empresa eligió que sigan a la TRM.
     *
     * Solo las que siguen abiertas: una aprobada ya es un acuerdo, y una vencida o rechazada
     * no se va a cobrar. En modo `fija` no toca nada.
     *
     * @return int cuántas cotizaciones cambiaron
     */
    public function actualizarCotizacionesAbiertas(): int
    {
        if (Monedas::modoCotizacion() !== 'diaria') {
            return 0;
        }

        $cambiadas = 0;

        foreach (Monedas::extranjeras() as $moneda) {
            $tasa = $this->tasas->vigente($moneda);

            if (! $tasa) {
                continue;
            }

            $cambiadas += Cotizacion::where('moneda', $moneda)
                ->whereIn('estado', ['borrador', 'enviada'])
                ->where(fn ($q) => $q->where('tasa_cambio', '!=', $tasa->valor)->orWhereNull('tasa_fecha'))
                ->update([
                    'tasa_cambio' => $tasa->valor,
                    'tasa_fecha'  => $tasa->fecha->toDateString(),
                ]);
        }

        return $cambiadas;
    }
}
