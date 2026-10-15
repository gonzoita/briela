<?php

namespace App\Services;

use App\Models\OrdenCompraItem;
use App\Support\ContextoSede;
use App\Support\Modulos;
use Illuminate\Support\Collection;

/**
 * El inventario en tránsito: lo que ya se pidió al proveedor y todavía no llega.
 *
 * **Es una cuenta, no un movimiento.** No hay tabla de tránsito ni nadie que lo anote: es lo
 * pedido y no recibido de toda orden de compra **enviada, confirmada o recibida en parte**. Al
 * recibir contra la remisión o la factura del proveedor (`OrdenCompra::recibir`) entra a bodega
 * y sale de aquí solo, porque sube `cantidad_recibida`; si la orden se cancela, deja de contar.
 * Así no hay dos números que se puedan separar.
 *
 * **Primero lo real, después lo que viene.** Lo apartado por cotizaciones se descuenta primero
 * del stock real; lo que no cabe en el real se descuenta del tránsito. Por eso
 * `cobertura()` reparte una cantidad pedida en «de bodega», «en tránsito» y «falta», y es la
 * misma cuenta para la pantalla, para apartar y para el PDF.
 */
class InventarioTransitoService
{
    /** Los estados de una orden de compra cuyo pendiente ya está en camino. */
    public const ESTADOS = ['enviada', 'confirmada', 'recibida_parcial'];

    public static function habilitado(): bool
    {
        return Modulos::activo('inventario') && Modulos::activo('compras');
    }

    /**
     * Lo que viene en camino de cada producto, con las órdenes que lo traen.
     *
     * @param  array<int, int>  $productoIds
     * @return array<int, array{cantidad: float, llega: ?string, atrasada: bool,
     *   ordenes: list<array{id: int, numero: string, proveedor: ?string, pendiente: float, fecha_esperada: ?string, atrasada: bool}>}>
     */
    public function porProducto(array $productoIds): array
    {
        if ($productoIds === [] || ! static::habilitado()) {
            return [];
        }

        return $this->pendientes()
            ->whereIn('item_id', $productoIds)
            ->get()
            ->groupBy('item_id')
            ->map(function (Collection $filas) {
                $ordenes = $filas->map(function (OrdenCompraItem $i) {
                    $fecha = $i->orden->fecha_entrega_esperada;

                    return [
                        'id'             => $i->orden->id,
                        'numero'         => $i->orden->numero,
                        'proveedor'      => $i->orden->proveedor?->nombre,
                        'pendiente'      => round((float) $i->cantidad - (float) $i->cantidad_recibida, 3),
                        'fecha_esperada' => $fecha?->toDateString(),
                        'atrasada'       => $fecha !== null && $fecha->lt(today()),
                    ];
                })->sortBy(fn ($o) => $o['fecha_esperada'] ?? '9999-12-31')->values();

                // La primera fecha que todavía no pasó: una orden atrasada no dice cuándo llega.
                $proxima = $ordenes->first(fn ($o) => $o['fecha_esperada'] && ! $o['atrasada'])['fecha_esperada'] ?? null;

                return [
                    'cantidad' => round((float) $ordenes->sum('pendiente'), 3),
                    'llega'    => $proxima,
                    'atrasada' => $ordenes->contains('atrasada', true),
                    'ordenes'  => $ordenes->all(),
                ];
            })
            ->all();
    }

    /**
     * Cómo se cubre una cantidad pedida: de bodega, de lo que viene en tránsito, y lo que falta.
     *
     * `$apartado` es lo que otras cotizaciones tienen apartado del producto: se come primero el
     * real y lo que sobra se come el tránsito.
     *
     * @return array{de_real: float, de_transito: float, faltan: float, libre_real: float, libre_transito: float}
     */
    public static function cobertura(float $pedida, float $stock, float $apartado, float $transito): array
    {
        $libreReal     = max(0.0, $stock - $apartado);
        $libreTransito = max(0.0, $transito - max(0.0, $apartado - $stock));

        $deReal     = min(max(0.0, $pedida), $libreReal);
        $deTransito = min(max(0.0, $pedida - $deReal), $libreTransito);

        return [
            'de_real'        => round($deReal, 3),
            'de_transito'    => round($deTransito, 3),
            'faltan'         => round(max(0.0, $pedida - $deReal - $deTransito), 3),
            'libre_real'     => round($libreReal, 3),
            'libre_transito' => round($libreTransito, 3),
        ];
    }

    /**
     * Para el tablero de inventario: unidades y valor en camino, y las órdenes atrasadas.
     *
     * @return array{unidades: float, valor: float, ordenes: int, productos: int,
     *   atrasadas: list<array{id: int, numero: string, proveedor: ?string, fecha_esperada: string, dias: int, pendiente: float}>}
     */
    public function resumen(): array
    {
        if (! static::habilitado()) {
            return ['unidades' => 0.0, 'valor' => 0.0, 'ordenes' => 0, 'productos' => 0, 'atrasadas' => []];
        }

        $filas = $this->pendientes()->whereNotNull('item_id')->get();

        $atrasadas = $filas->groupBy('orden_id')
            ->map(function (Collection $items) {
                $orden = $items->first()->orden;
                $fecha = $orden->fecha_entrega_esperada;

                return $fecha !== null && $fecha->lt(today()) ? [
                    'id'             => $orden->id,
                    'numero'         => $orden->numero,
                    'proveedor'      => $orden->proveedor?->nombre,
                    'fecha_esperada' => $fecha->toDateString(),
                    'dias'           => (int) $fecha->diffInDays(today()),
                    'pendiente'      => round((float) $items->sum(fn ($i) => (float) $i->cantidad - (float) $i->cantidad_recibida), 3),
                ] : null;
            })
            ->filter()->sortByDesc('dias')->values()->all();

        return [
            'unidades'  => round((float) $filas->sum(fn ($i) => (float) $i->cantidad - (float) $i->cantidad_recibida), 3),
            'valor'     => round((float) $filas->sum(fn ($i) => ((float) $i->cantidad - (float) $i->cantidad_recibida) * (float) $i->precio_unitario), 2),
            'ordenes'   => $filas->pluck('orden_id')->unique()->count(),
            'productos' => $filas->pluck('item_id')->unique()->count(),
            'atrasadas' => $atrasadas,
        ];
    }

    /**
     * Lo que la cotización le dice al cliente sobre la entrega de cada línea que depende de lo
     * que viene en tránsito: «Entrega: 4 de inmediato y 6 a partir del 20 de octubre de 2026».
     *
     * Se reparte en el orden de las líneas, descontando lo que ya tomó una línea anterior del
     * mismo producto, y con lo apartado por **otras** cotizaciones. Lo que no alcanza ni con el
     * tránsito no se dice aquí: el faltante es un asunto interno, no una promesa al cliente.
     *
     * @return array<int, string>  id de la línea => texto (solo las que usan tránsito)
     */
    public function entregaDeCotizacion(\App\Models\Cotizacion $cotizacion): array
    {
        $lineas = $cotizacion->items->filter(fn ($i) => $i->producto_id && ($i->tipo ?? 'producto') === 'producto');

        if ($lineas->isEmpty() || ! static::habilitado()) {
            return [];
        }

        $ids    = $lineas->pluck('producto_id')->unique()->values()->all();
        $datos  = app(ReservaStockService::class)->disponibilidad($ids, ContextoSede::idsBodegasVisibles(), $cotizacion->id);
        $usado  = [];
        $textos = [];

        foreach ($lineas as $linea) {
            $d = $datos[$linea->producto_id] ?? null;

            if (! $d || $d['transito'] <= 0) {
                continue;
            }

            // Lo que ya tomaron las líneas anteriores de este producto cuenta como apartado.
            $previo = $usado[$linea->producto_id] ?? 0.0;
            $c = self::cobertura((float) $linea->cantidad, $d['stock'], $d['apartado'] + $previo, $d['transito']);
            $usado[$linea->producto_id] = $previo + $c['de_real'] + $c['de_transito'];

            if ($c['de_transito'] <= 0) {
                continue;
            }

            $cuando = $d['llega']
                ? 'a partir del ' . \Illuminate\Support\Carbon::parse($d['llega'])->locale('es')->translatedFormat('j \\d\\e F \\d\\e Y')
                : 'en camino desde el proveedor';

            $textos[$linea->id] = $c['de_real'] > 0
                ? 'Entrega: ' . self::numero($c['de_real']) . ' de inmediato y ' . self::numero($c['de_transito']) . ' ' . $cuando . '.'
                : 'Entrega ' . $cuando . '.';
        }

        return $textos;
    }

    /**
     * Al recibir mercancía de una orden de compra: avisa al vendedor de cada cotización viva que
     * contaba con unidades en tránsito de ese producto.
     *
     * «Contaba con el tránsito» = la cotización pide de ese producto más de lo que había en bodega
     * antes de recibir. No se mira la reserva de 24 horas: lo que viene en camino tarda semanas, y
     * para entonces la reserva ya venció, pero el vendedor sigue esperando la mercancía. Vivas son
     * las cotizaciones en borrador, enviadas o aprobadas de los últimos 90 días; una por vendedor y
     * cotización, aunque traiga varias líneas del producto.
     */
    public function avisarLlegada(\App\Models\Producto $producto, float $stockAntes, float $recibido, \App\Models\OrdenCompra $orden): int
    {
        if ($recibido <= 0 || ! static::habilitado()) {
            return 0;
        }

        $cotizaciones = \App\Models\Cotizacion::query()
            ->whereIn('estado', ['borrador', 'enviada', 'aprobada'])
            ->where('updated_at', '>=', now()->subDays(90))
            ->whereNotNull('responsable_id')
            // Una cotización que ya se volvió orden dejó de esperar: su inventario sale por la orden.
            ->whereNotExists(fn ($q) => $q->select(\DB::raw(1))->from('ops')->whereColumn('ops.cotizacion_id', 'cotizaciones.id')->whereNull('ops.deleted_at'))
            // De la sede de la orden (o de cualquiera si la orden no tiene sede).
            ->when($orden->sede_id, fn ($q) => $q->where(fn ($s) => $s->where('sede_id', $orden->sede_id)->orWhereNull('sede_id')))
            ->whereHas('items', fn ($q) => $q->where('producto_id', $producto->id))
            ->with(['items' => fn ($q) => $q->where('producto_id', $producto->id)])
            ->get();

        $unidad = $producto->unidad_medida ?: 'unidad';
        $unidad = ($unidad === 'unidad' && abs($recibido - 1) > 0.0001) ? 'unidades' : $unidad;
        $avisos = 0;

        foreach ($cotizaciones as $cot) {
            if ((float) $cot->items->sum('cantidad') <= $stockAntes) {
                continue; // Lo que pedía ya estaba en bodega: no esperaba esta mercancía.
            }

            app(NotificacionService::class)->crear(
                $cot->responsable_id,
                'transito_recibido',
                'Llegó mercancía que esperabas',
                'Llegaron ' . self::numero($recibido) . " {$unidad} de «{$producto->nombre}» (orden de compra {$orden->numero}). "
                    . "Tu cotización {$cot->numero} contaba con unidades en tránsito de este producto.",
                "/cotizaciones/{$cot->id}",
            );
            $avisos++;
        }

        return $avisos;
    }

    private static function numero(float $n): string
    {
        return rtrim(rtrim(number_format($n, 3, ',', '.'), '0'), ',');
    }

    /**
     * Las líneas de órdenes en camino con algo por recibir, de la sede que se ve.
     *
     * Una orden **sin sede** cuenta en todas: las de antes de las sedes no la tienen, y la
     * mercancía entra a la bodega principal sin importar quién la pidió. Dejarlas fuera escondía
     * lo que venía en camino justo en la instalación de una sola sede.
     */
    private function pendientes()
    {
        $sede = ContextoSede::id();

        return OrdenCompraItem::query()
            ->with(['orden:id,numero,proveedor_id,fecha_entrega_esperada,estado,sede_id', 'orden.proveedor:id,nombre'])
            ->whereColumn('cantidad_recibida', '<', 'cantidad')
            ->whereHas('orden', fn ($q) => $q->whereIn('estado', self::ESTADOS)
                ->when($sede, fn ($w) => $w->where(fn ($s) => $s->where('sede_id', $sede)->orWhereNull('sede_id'))));
    }
}
