<?php

namespace App\Services;

use App\Models\Configuracion;
use App\Models\Importacion;
use App\Models\ImportacionItem;
use App\Models\Producto;
use App\Support\Monedas;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Cuánto cuesta de verdad lo que llegó de afuera.
 *
 * **La cuenta vive aquí y en ningún otro sitio**: la pantalla la pide al servidor mientras se
 * escribe, igual que las retenciones. Una cuenta aduanera repetida en el navegador termina
 * diciendo dos cifras distintas del mismo embarque.
 *
 * Para cada producto del embarque, en la moneda base:
 *
 *     FOB                = cantidad × valor unitario × tasa
 *     + flete y seguro   = lo que le toca del flete y del seguro del embarque
 *     = CIF              (la base del arancel)
 *     + arancel          = CIF × % de arancel
 *     + IVA              = (CIF + arancel) × % de IVA     — solo suma al costo si NO es descontable
 *     + gastos           = lo que le toca de los gastos de nacionalización
 *     = costo puesto en bodega
 *
 * Lo que «le toca» de un gasto del embarque se reparte por valor, por peso o por cantidad. Los
 * redondeos se corrigen en la última línea para que las partes sumen exactamente el total: un
 * flete de 100.000 repartido en tres no puede dejar 99.999,99 en la cuenta.
 *
 * Nada de esto fija una tarifa: el arancel y el IVA vienen de cada línea, y la pantalla los
 * propone del producto y del país (Configuración → Países).
 */
class LiquidacionImportacionService
{
    public const METODOS = ['valor' => 'Por valor', 'peso' => 'Por peso', 'cantidad' => 'Por cantidad'];

    public function __construct(private CostosEnMonedaService $reprecio) {}

    /**
     * @param  array{tasa: float|string, metodo?: string, iva_descontable?: bool,
     *   items: list<array<string, mixed>>, costos?: list<array<string, mixed>>}  $e
     * @return array{items: list<array<string, float|null>>, totales: array<string, float>, factor: float|null, avisos: list<string>}
     */
    public function calcular(array $e): array
    {
        $tasa   = (float) ($e['tasa'] ?? 1);
        $metodo = array_key_exists($e['metodo'] ?? 'valor', self::METODOS) ? ($e['metodo'] ?? 'valor') : 'valor';
        $iva    = (bool) ($e['iva_descontable'] ?? true);
        $avisos = [];

        if ($tasa <= 0) {
            $avisos[] = 'Falta la tasa de cambio: sin ella no se puede pasar el valor de la factura a la moneda base.';
            $tasa = 0.0;
        }

        $items = array_values($e['items'] ?? []);

        // ── Los gastos del embarque, en la moneda base ──────────────────────
        $fleteSeguro = 0.0;
        $gastos      = 0.0;

        foreach ($e['costos'] ?? [] as $c) {
            $valor = (float) ($c['valor'] ?? 0) * (! empty($c['en_moneda_base']) ? 1 : $tasa);

            ($c['tipo'] ?? 'gasto') === 'gasto' ? $gastos += $valor : $fleteSeguro += $valor;
        }

        // ── FOB de cada línea y el peso con el que se reparte ───────────────
        $fob = array_map(fn ($i) => round((float) ($i['cantidad'] ?? 0) * (float) ($i['valor_unitario'] ?? 0) * $tasa, 2), $items);

        $pesos = match ($metodo) {
            'peso'     => array_map(fn ($i) => (float) ($i['peso_kg'] ?? 0), $items),
            'cantidad' => array_map(fn ($i) => (float) ($i['cantidad'] ?? 0), $items),
            default    => $fob,
        };

        if ($metodo === 'peso' && array_sum($pesos) <= 0 && $items !== []) {
            $avisos[] = 'Ninguna línea tiene peso: se repartió por valor.';
            $pesos = $fob;
        }

        $parteFlete  = $this->repartir($fleteSeguro, $pesos);
        $parteGastos = $this->repartir($gastos, $pesos);

        // ── La cuenta de cada línea ──────────────────────────────────────────
        $filas = [];
        $tot   = ['fob' => 0.0, 'flete_seguro' => 0.0, 'cif' => 0.0, 'arancel' => 0.0, 'iva' => 0.0, 'gastos' => 0.0, 'costo_total' => 0.0];

        foreach ($items as $k => $i) {
            $cantidad = (float) ($i['cantidad'] ?? 0);
            $cif      = round($fob[$k] + $parteFlete[$k], 2);
            $arancel  = round($cif * (float) ($i['arancel_pct'] ?? 0) / 100, 2);
            $impuesto = round(($cif + $arancel) * (float) ($i['iva_pct'] ?? 0) / 100, 2);
            $costo    = round($cif + $arancel + $parteGastos[$k] + ($iva ? 0 : $impuesto), 2);

            $filas[] = [
                'fob'            => $fob[$k],
                'flete_seguro'   => $parteFlete[$k],
                'cif'            => $cif,
                'arancel'        => $arancel,
                'iva'            => $impuesto,
                'gastos'         => $parteGastos[$k],
                'costo_total'    => $costo,
                'costo_unitario' => $cantidad > 0 ? round($costo / $cantidad, 4) : null,
            ];

            foreach ($tot as $campo => $_) {
                $tot[$campo] += $filas[$k][$campo];
            }
        }

        $tot = array_map(fn ($v) => round($v, 2), $tot);

        return [
            'items'   => $filas,
            'totales' => $tot,
            // Cuántas veces el valor de la factura es el costo puesto en bodega: 1,35 quiere
            // decir que traerlo costó 35 % más de lo que se pagó al proveedor.
            'factor'  => $tot['fob'] > 0 ? round($tot['costo_total'] / $tot['fob'], 4) : null,
            'avisos'  => $avisos,
        ];
    }

    /**
     * Reparte un total entre varias líneas según su peso, sin perder centavos.
     *
     * @param  list<float>  $pesos
     * @return list<float>
     */
    private function repartir(float $total, array $pesos): array
    {
        $n = count($pesos);

        if ($n === 0) {
            return [];
        }

        $suma = array_sum($pesos);
        $pesos = $suma > 0 ? $pesos : array_fill(0, $n, 1.0);
        $suma  = array_sum($pesos);

        $partes = [];
        $dado   = 0.0;

        foreach ($pesos as $k => $p) {
            $partes[$k] = $k === $n - 1 ? round($total - $dado, 2) : round($total * $p / $suma, 2);
            $dado += $partes[$k];
        }

        return $partes;
    }

    /** La entrada del cálculo, sacada de un embarque guardado. */
    public function entradaDe(Importacion $imp): array
    {
        return [
            'tasa'            => (float) $imp->tasa,
            'metodo'          => $imp->metodo_prorrateo,
            'iva_descontable' => $imp->iva_descontable,
            'items'           => $imp->items->map->only(['cantidad', 'valor_unitario', 'peso_kg', 'arancel_pct', 'iva_pct'])->all(),
            'costos'          => $imp->costos->map->only(['tipo', 'valor', 'en_moneda_base'])->all(),
        ];
    }

    // ─── Liquidar ────────────────────────────────────────────────────────────

    /**
     * Cierra el embarque: guarda el resultado, lo vuelve inmodificable y lo lleva al costo de
     * cada producto.
     *
     * @return int cuántos productos cambiaron de costo
     */
    public function liquidar(Importacion $imp, ?int $usuarioId = null): int
    {
        if (! $imp->esBorrador()) {
            throw new RuntimeException('Esta importación ya está liquidada.');
        }

        $imp->load(['items', 'costos']);

        if ($imp->items->isEmpty()) {
            throw new RuntimeException('Agrega al menos un producto antes de liquidar.');
        }

        if ((float) $imp->tasa <= 0) {
            throw new RuntimeException('Falta la tasa de cambio.');
        }

        $r = $this->calcular($this->entradaDe($imp));

        return DB::transaction(function () use ($imp, $r, $usuarioId) {
            foreach ($imp->items as $k => $item) {
                $item->update($r['items'][$k]);
            }

            $imp->update([
                'estado'             => 'liquidada',
                'total_fob'          => $r['totales']['fob'],
                'total_flete_seguro' => $r['totales']['flete_seguro'],
                'total_arancel'      => $r['totales']['arancel'],
                'total_iva'          => $r['totales']['iva'],
                'total_gastos'       => $r['totales']['gastos'],
                'total_costo'        => $r['totales']['costo_total'],
                'liquidada_at'       => now(),
                'liquidada_por'      => $usuarioId,
            ]);

            $cambiados = 0;

            foreach ($imp->items()->get() as $item) {
                if ($item->producto_id && $this->aplicarAlProducto($item)) {
                    $cambiados++;
                }
            }

            return $cambiados;
        });
    }

    /** `ultimo` (el del último embarque) o `promedio` (ponderado por cantidad, de todos los liquidados). */
    public static function criterioCosto(): string
    {
        return Configuracion::get('importacion_costo_vigente', 'ultimo') === 'promedio' ? 'promedio' : 'ultimo';
    }

    /** Lleva el costo de una línea liquidada al producto y reprecia lo que salía de la cuenta. */
    private function aplicarAlProducto(ImportacionItem $item): bool
    {
        $producto = Producto::with('preciosPorCanal.canal')->find($item->producto_id);

        if (! $producto || $item->costo_unitario === null) {
            return false;
        }

        $costo = self::criterioCosto() === 'promedio'
            ? $this->promedioPonderado($producto->id) ?? (float) $item->costo_unitario
            : (float) $item->costo_unitario;

        $anterior = (float) $producto->precio_costo;

        // Desde aquí el costo es el del embarque, en la moneda base: deja de seguir la tasa
        // diaria que movía el costo de lo que se compra en otra moneda.
        $producto->update(array_filter([
            'precio_costo' => round($costo, 2),
            'moneda_costo' => Monedas::base(),
            'costo_moneda' => null,
            'codigo_hs'    => $item->codigo_hs ?: null,
            'arancel_pct'  => $item->codigo_hs || (float) $item->arancel_pct > 0 ? $item->arancel_pct : null,
        ], fn ($v, $campo) => $v !== null || in_array($campo, ['costo_moneda'], true), ARRAY_FILTER_USE_BOTH));

        $producto->refresh()->load('preciosPorCanal.canal');
        $filas = $this->reprecio->filasRepreciadas($producto, $anterior, round($costo, 2));

        if ($filas !== []) {
            app(PreciosPorCanalService::class)->guardar($producto, $filas);
        }

        $item->update(['aplicado_at' => now()]);

        return abs($anterior - round($costo, 2)) >= 0.01;
    }

    /** Σ costo ÷ Σ cantidad de todo lo liquidado de un producto. */
    private function promedioPonderado(int $productoId): ?float
    {
        $r = ImportacionItem::where('producto_id', $productoId)
            ->whereNotNull('costo_total')
            ->whereHas('importacion', fn ($q) => $q->where('estado', 'liquidada'))
            ->selectRaw('SUM(costo_total) AS costo, SUM(cantidad) AS cantidad')
            ->first();

        return $r && (float) $r->cantidad > 0 ? round((float) $r->costo / (float) $r->cantidad, 4) : null;
    }
}
