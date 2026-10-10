<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\OrdenCompra;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Support\Fiscal;

/**
 * Cuánto le va a retener el cliente a la empresa al pagar, y cuánto llega de verdad.
 *
 * Las retenciones las practica quien PAGA. Una cotización no las cobra: las anticipa, para
 * que el vendedor sepa que de una venta de diez millones llegan nueve y medio, y el cliente
 * no se sorprenda de que el valor a pagar sea otro.
 *
 * Es una **estimación**: la cifra final depende de lo que el cliente declare y de reglas que
 * cambian por decreto. Por eso las reglas son pocas y explícitas, cada línea dice de dónde
 * sale y cada retención que no aplica dice por qué.
 *
 * ## Las reglas
 *
 *  - **Retención en la fuente (renta)**: la practica un cliente agente de retención —código
 *    07 o gran contribuyente (13) en su RUT— sobre la base de cada concepto (compras o
 *    servicios) que pase su base mínima en UVT. No aplica si la empresa es autorretenedora
 *    (15) o del régimen simple (47).
 *  - **Retención de IVA**: la practica un cliente agente de retención de IVA (09) o gran
 *    contribuyente (13), sobre el IVA, si la empresa es responsable de IVA (48). No aplica si
 *    la empresa también es gran contribuyente.
 *  - **Retención de ICA**: si el cliente está marcado como retenedor de ICA y la empresa
 *    configuró su tarifa por mil.
 */
class RetencionesService
{
    /**
     * @param  list<array{concepto: string, base: float}>  $lineas  base de cada ítem, ya con descuento y sin IVA
     * @return array{aplica: bool, lineas: list<array{clave: string, nombre: string, base: float, tarifa: float, valor: float}>, total: float, motivos: list<string>, avisos: list<string>}
     */
    public function estimar(array $lineas, float $iva, ?Cliente $cliente): array
    {
        $ajustes = Fiscal::ajustes();
        $empresa = $ajustes['responsabilidades'];
        $out     = ['aplica' => false, 'lineas' => [], 'total' => 0.0, 'motivos' => [], 'avisos' => []];

        if (! $cliente) {
            $out['motivos'][] = 'Sin cliente no se sabe qué retenciones practica.';

            return $out;
        }

        $delCliente = Fiscal::codigos($cliente->responsabilidades_fiscales ?? []);

        if ($delCliente === [] && ! $cliente->retenedor_ica) {
            $out['motivos'][] = 'El cliente no tiene cargadas las responsabilidades de su RUT. Súbelo en su ficha para calcular sus retenciones.';

            return $out;
        }

        if ($empresa === []) {
            $out['avisos'][] = 'La empresa no tiene cargadas sus responsabilidades fiscales (Configuración → Perfil fiscal). Se calcula como si no fuera autorretenedora ni del régimen simple.';
        }

        $out['aplica'] = true;
        $tiene   = fn (array $codigos, string ...$buscados) => array_intersect($buscados, $codigos) !== [];

        // ── Renta ──────────────────────────────────────────────────────────
        if (! $tiene($delCliente, '07', '13')) {
            $out['motivos'][] = 'Retención en la fuente: el cliente no es agente de retención (sin código 07 ni 13 en su RUT).';
        } elseif ($tiene($empresa, '15')) {
            $out['motivos'][] = 'Retención en la fuente: la empresa es autorretenedora, así que el cliente no le retiene.';
        } elseif ($tiene($empresa, '47')) {
            $out['motivos'][] = 'Retención en la fuente: la empresa está en el régimen simple, así que el cliente no le retiene.';
        } else {
            $this->retefuentePorConceptos($out, $lineas, $ajustes);
        }

        // ── IVA ────────────────────────────────────────────────────────────
        if ($iva > 0) {
            if (! $tiene($delCliente, '09', '13')) {
                $out['motivos'][] = 'Retención de IVA: el cliente no es agente de retención de IVA (sin código 09 ni 13).';
            } elseif ($empresa !== [] && ! $tiene($empresa, '48')) {
                $out['motivos'][] = 'Retención de IVA: la empresa no es responsable de IVA (sin código 48).';
            } elseif ($tiene($empresa, '13')) {
                $out['motivos'][] = 'Retención de IVA: a un gran contribuyente no le retienen IVA sus clientes privados.';
            } elseif ($ajustes['reteiva_pct'] > 0) {
                $this->agregar($out, 'reteiva', 'Retención de IVA', $iva, $ajustes['reteiva_pct'], $iva * $ajustes['reteiva_pct'] / 100);
            }
        }

        // ── ICA ────────────────────────────────────────────────────────────
        if ($cliente->retenedor_ica) {
            $base = round(array_sum(array_column($lineas, 'base')), 2);

            if ($ajustes['reteica_por_mil'] <= 0) {
                $out['avisos'][] = 'El cliente retiene ICA, pero falta la tarifa por mil en Configuración → Perfil fiscal.';
            } elseif ($base > 0) {
                // La tarifa del ICA va por mil, no por ciento: 9,66 ‰ son 0,966 %.
                $this->agregar($out, 'reteica', 'Retención de ICA', $base, $ajustes['reteica_por_mil'] / 10, $base * $ajustes['reteica_por_mil'] / 1000);
            }
        }

        return $out;
    }

    /** Las líneas de una cotización guardada, con su concepto. */
    public function paraCotizacion(Cotizacion $cotizacion): array
    {
        $cotizacion->loadMissing(['items.producto:id,tipo', 'cliente']);

        $lineas = $cotizacion->items->map(fn ($item) => [
            'concepto' => $item->producto?->tipo === 'servicio' ? 'servicios' : 'compras',
            'base'     => (float) $item->cantidad * (float) $item->precio_unitario * (1 - (float) $item->descuento_pct / 100),
        ])->all();

        return $this->estimar($lineas, (float) $cotizacion->impuesto_total, $cotizacion->cliente);
    }

    /**
     * Las líneas de una cotización que todavía se está armando.
     *
     * El concepto se decide aquí y no en el navegador: un servicio se reconoce por su
     * producto, y el navegador podría decir cualquier cosa.
     *
     * @param  list<array{producto_id?: ?int, base: float}>  $items
     * @return list<array{concepto: string, base: float}>
     */
    public function lineasDesdeFormulario(array $items): array
    {
        $ids       = array_filter(array_map(fn ($i) => (int) ($i['producto_id'] ?? 0), $items));
        $servicios = $ids === [] ? [] : Producto::whereIn('id', $ids)->where('tipo', 'servicio')->pluck('id')->all();

        return array_map(fn ($i) => [
            'concepto' => in_array((int) ($i['producto_id'] ?? 0), $servicios, true) ? 'servicios' : 'compras',
            'base'     => (float) ($i['base'] ?? 0),
        ], $items);
    }

    /**
     * Lo que la empresa le va a retener a un proveedor al pagarle una orden de compra, y cuánto
     * se le gira de verdad.
     *
     * Es el espejo de {@see estimar()}: aquí la empresa es quien paga, y por eso es **su** RUT el
     * que decide si es agente de retención. Reglas:
     *
     *  - **En la fuente**: la empresa tiene 07 o 13, y el proveedor no es autorretenedor (15) ni
     *    del régimen simple (47). Por concepto, sobre la base que pase su mínimo en UVT.
     *  - **De IVA**: la empresa tiene 09 o 13 y la orden lleva IVA. Si el RUT del proveedor dice
     *    que no es responsable de IVA, no hay IVA que retener; si es gran contribuyente (13), no se
     *    le retiene.
     *  - **ICA**: no se estima en compras. Depende del municipio de cada proveedor y de si la
     *    empresa es retenedora allí, y no hay de dónde sacarlo sin inventarlo.
     *
     * Sin el RUT del proveedor se calcula igual —la obligación es de la empresa—, pero se dice.
     */
    public function paraCompra(OrdenCompra $orden): array
    {
        $orden->loadMissing(['items.item:id,tipo', 'proveedor']);

        $lineas = $orden->items->map(fn ($i) => [
            'concepto' => $i->item?->tipo === 'servicio' ? 'servicios' : 'compras',
            'base'     => (float) $i->cantidad * (float) $i->precio_unitario,
        ])->all();

        return $this->estimarCompra($lineas, (float) $orden->impuesto, $orden->proveedor);
    }

    /**
     * @param  list<array{concepto: string, base: float}>  $lineas
     * @return array{aplica: bool, lineas: list<array{clave: string, nombre: string, base: float, tarifa: float, valor: float}>, total: float, motivos: list<string>, avisos: list<string>}
     */
    public function estimarCompra(array $lineas, float $iva, ?Proveedor $proveedor): array
    {
        $ajustes = Fiscal::ajustes();
        $empresa = $ajustes['responsabilidades'];
        $out     = ['aplica' => false, 'lineas' => [], 'total' => 0.0, 'motivos' => [], 'avisos' => []];

        if ($empresa === []) {
            $out['motivos'][] = 'La empresa no tiene cargadas sus responsabilidades fiscales (Configuración → Perfil fiscal): sin ellas no se sabe si es agente de retención.';

            return $out;
        }

        $delProveedor = Fiscal::codigos($proveedor?->responsabilidades_fiscales ?? []);
        $tiene        = fn (array $codigos, string ...$buscados) => array_intersect($buscados, $codigos) !== [];

        if ($delProveedor === []) {
            $out['avisos'][] = 'El proveedor no tiene cargado su RUT: se calculó como si le aplicaran todas las retenciones. Cárgalo en su ficha para afinarlo.';
        }

        $out['aplica'] = true;

        // ── Renta ──────────────────────────────────────────────────────────
        if (! $tiene($empresa, '07', '13')) {
            $out['motivos'][] = 'Retención en la fuente: la empresa no es agente de retención (sin código 07 ni 13 en su RUT).';
        } elseif ($tiene($delProveedor, '15')) {
            $out['motivos'][] = 'Retención en la fuente: el proveedor es autorretenedor, así que no se le retiene.';
        } elseif ($tiene($delProveedor, '47')) {
            $out['motivos'][] = 'Retención en la fuente: el proveedor está en el régimen simple, así que no se le retiene.';
        } else {
            $this->retefuentePorConceptos($out, $lineas, $ajustes);
        }

        // ── IVA ────────────────────────────────────────────────────────────
        if ($iva > 0) {
            if (! $tiene($empresa, '09', '13')) {
                $out['motivos'][] = 'Retención de IVA: la empresa no es agente de retención de IVA (sin código 09 ni 13).';
            } elseif ($proveedor && $proveedor->responsableDeIva() === false) {
                $out['motivos'][] = 'Retención de IVA: el proveedor no es responsable de IVA.';
            } elseif ($tiene($delProveedor, '13')) {
                $out['motivos'][] = 'Retención de IVA: a un gran contribuyente no se le retiene IVA.';
            } elseif ($ajustes['reteiva_pct'] > 0) {
                $this->agregar($out, 'reteiva', 'Retención de IVA', $iva, $ajustes['reteiva_pct'], $iva * $ajustes['reteiva_pct'] / 100);
            }
        }

        return $out;
    }

    /**
     * La retención en la fuente por concepto —compras o servicios—, con su base mínima en UVT.
     * La comparten la venta (la practica el cliente) y la compra (la practica la empresa): la
     * cuenta es la misma, lo que cambia es **quién** la practica.
     */
    private function retefuentePorConceptos(array &$out, array $lineas, array $ajustes): void
    {
        if ($ajustes['uvt'] === null) {
            $out['avisos'][] = 'Falta el valor de la UVT en Configuración → Perfil fiscal: se calculó sin base mínima.';
        }

        $bases = [];
        foreach ($lineas as $l) {
            $bases[$l['concepto']] = ($bases[$l['concepto']] ?? 0) + (float) $l['base'];
        }

        foreach ($ajustes['conceptos'] as $c) {
            $base = round($bases[$c['clave']] ?? 0, 2);

            if ($base <= 0 || $c['tarifa'] <= 0) {
                continue;
            }

            $minimo = ($ajustes['uvt'] ?? 0) * $c['base_uvt'];

            if ($base < $minimo) {
                $out['motivos'][] = "Retención por {$c['nombre']}: la base ($" . number_format($base, 0, ',', '.')
                    . ') no llega a la mínima de ' . rtrim(rtrim(number_format($c['base_uvt'], 2, ',', '.'), '0'), ',')
                    . ' UVT ($' . number_format($minimo, 0, ',', '.') . ').';

                continue;
            }

            $this->agregar($out, "retefuente_{$c['clave']}", "Retención en la fuente · {$c['nombre']}", $base, $c['tarifa'], $base * $c['tarifa'] / 100);
        }
    }

    private function agregar(array &$out, string $clave, string $nombre, float $base, float $tarifa, float $valor): void
    {
        // Las retenciones se liquidan en pesos enteros.
        $valor = round($valor);

        if ($valor <= 0) {
            return;
        }

        $out['lineas'][] = ['clave' => $clave, 'nombre' => $nombre, 'base' => round($base, 2), 'tarifa' => $tarifa, 'valor' => $valor];
        $out['total']   += $valor;
    }
}
