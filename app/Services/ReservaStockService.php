<?php

namespace App\Services;

use App\Models\Bodega;
use App\Models\Cotizacion;
use App\Models\Producto;
use App\Models\ReservaStock;
use App\Support\Modulos;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Apartar stock mientras una cotización está en la calle.
 *
 * **Es una marca, no un movimiento.** No baja el inventario: el stock real sigue donde está
 * hasta que se despacha. Apartar solo cambia lo que se le dice a quien cotiza —«hay 12, pero 5
 * las tiene apartadas otra cotización»— y por eso soltar es borrar la marca: no hay nada que
 * deshacer.
 *
 * Y **solo avisa, no bloquea**. Una cotización se puede hacer por más de lo que está disponible;
 * lo que se cuida es que nadie se entere tarde. Cuando una venta se aprueba y, para atenderla,
 * hay que usar unidades que tenía apartadas otra cotización, esas unidades se le ceden a la
 * venta —una venta hecha gana a una cotización que sigue abierta— y se avisa **al vendedor
 * afectado y a administración**, para que los dos estén pendientes.
 *
 * Ciclo de vida, que engancha {@see \App\Observers\CotizacionObserver}:
 *  - Una cotización pasa a **enviada** → aparta cada producto de sus líneas por 24 horas.
 *  - Editar las líneas de una enviada ajusta lo apartado, **sin renovar el plazo**: guardar de
 *    nuevo no es una manera de alargar las 24 horas.
 *  - **aprobada** → se atiende la venta (puede ceder unidades de otras) y su reserva pasa a
 *    `concretada`: deja de apartar, y de ahí en adelante manda la OP.
 *  - rechazada, vencida, vuelta a borrador o borrada → se libera.
 *  - Pasan 24 horas → `stock:liberar-reservas` la marca vencida y le avisa al vendedor.
 *
 * Solo líneas de **producto**: un ensamble se fabrica por pedido y no tiene unidades que apartar.
 * Si el módulo de inventario está apagado, todo esto se salta: sin stock no hay qué apartar.
 */
class ReservaStockService
{
    /** El tope: nada se aparta por más de esto. */
    public const HORAS = 24;

    public static function habilitado(): bool
    {
        return Modulos::activo('inventario') && Modulos::activo('cotizaciones');
    }

    // ─── Ciclo de vida ───────────────────────────────────────────────────────

    /** La cotización cambió de estado. */
    public function alCambiarEstado(Cotizacion $cotizacion): void
    {
        if (! static::habilitado()) {
            return;
        }

        match ($cotizacion->estado) {
            'enviada'  => $this->reservar($cotizacion, reiniciarPlazo: true),
            'aprobada' => $this->aprobar($cotizacion),
            default    => $this->cerrar($cotizacion, 'liberada'),
        };
    }

    /**
     * Cambiaron las líneas de una cotización. Si ya está enviada, se ajusta lo apartado.
     *
     * No renueva el plazo de lo que ya estaba apartado: ver la nota de la clase.
     */
    public function alCambiarLineas(Cotizacion $cotizacion): void
    {
        if (! static::habilitado() || $cotizacion->estado !== 'enviada') {
            return;
        }

        $this->reservar($cotizacion, reiniciarPlazo: false);
    }

    /**
     * Aparta cada producto de la cotización.
     *
     * Con `$reiniciarPlazo` —se acaba de enviar— todo parte de cero con 24 horas nuevas. Sin él,
     * lo que ya estaba activo solo ajusta su cantidad; lo que **ya venció o se cedió no se
     * reactiva** porque alguien guardó otra vez; y lo que es nuevo o estaba liberado aparta con
     * un plazo propio.
     */
    public function reservar(Cotizacion $cotizacion, bool $reiniciarPlazo): void
    {
        $lineas = $this->lineas($cotizacion);

        DB::transaction(function () use ($cotizacion, $lineas, $reiniciarPlazo) {
            foreach ($lineas as $productoId => $cantidad) {
                $reserva = ReservaStock::firstOrNew(['cotizacion_id' => $cotizacion->id, 'producto_id' => $productoId]);

                $dejarComoEsta = ! $reiniciarPlazo && $reserva->exists
                    && in_array($reserva->estado, ['vencida', 'cedida', 'concretada'], true);

                if ($dejarComoEsta) {
                    continue;
                }

                $activa = $reserva->exists && $reserva->estado === 'activa';

                if (! $activa || $reiniciarPlazo) {
                    $reserva->estado                 = 'activa';
                    $reserva->expira_at              = now()->addHours(self::HORAS);
                    $reserva->cantidad_cedida        = 0;
                    $reserva->cerrada_at             = null;
                    $reserva->cedida_a_cotizacion_id = null;
                }

                $reserva->cantidad = $cantidad;
                // Si se bajó la cantidad por debajo de lo ya cedido, lo cedido no puede pasar de lo apartado.
                $reserva->cantidad_cedida = min((float) $reserva->cantidad_cedida, $cantidad);
                $reserva->save();
            }

            // Las líneas que se quitaron.
            ReservaStock::where('cotizacion_id', $cotizacion->id)
                ->where('estado', 'activa')
                ->whereNotIn('producto_id', $lineas->keys()->all() ?: [0])
                ->update(['estado' => 'liberada', 'cerrada_at' => now()]);
        });
    }

    /** Suelta lo que esta cotización tenía apartado. */
    public function cerrar(Cotizacion $cotizacion, string $estado): void
    {
        ReservaStock::where('cotizacion_id', $cotizacion->id)
            ->where('estado', 'activa')
            ->update(['estado' => $estado, 'cerrada_at' => now()]);
    }

    /** La venta: primero se atiende (puede ceder lo de otras) y luego su reserva se concreta. */
    private function aprobar(Cotizacion $cotizacion): void
    {
        $this->atenderVenta($cotizacion);
        $this->cerrar($cotizacion, 'concretada');
    }

    // ─── La venta que usa lo apartado por otras ──────────────────────────────

    /**
     * Si para atender esta venta hay que usar unidades apartadas por otras cotizaciones, se les
     * ceden y se avisa.
     *
     * Primero se atiende con lo que está libre (stock menos todo lo apartado). Solo lo que falte
     * sale de las reservas, y de las más nuevas primero: la gente que apartó antes conserva su
     * lugar. Y solo se cede lo que **existe**: apartar sobre un stock en cero no es ceder nada,
     * y avisarle a un vendedor que «se usaron» unas unidades que nunca estuvieron sería ruido.
     *
     * @return list<array{reserva: ReservaStock, cantidad: float}>
     */
    public function atenderVenta(Cotizacion $venta): array
    {
        $cedidas = [];
        $bodegas = $this->bodegasDe($venta);

        foreach ($this->lineas($venta) as $productoId => $cantidad) {
            $producto = Producto::find($productoId);

            if (! $producto) {
                continue;
            }

            $stock = $producto->stockEnBodegas($bodegas);

            $otras = ReservaStock::vigentes()
                ->where('producto_id', $productoId)
                ->where('cotizacion_id', '!=', $venta->id)
                ->with('cotizacion:id,numero,responsable_id', 'cotizacion.responsable:id,name')
                ->orderByDesc('created_at')->orderByDesc('id')
                ->get();

            $apartado = (float) $otras->sum(fn (ReservaStock $r) => $r->efectiva());
            $libre    = max(0.0, $stock - $apartado);
            $porCeder = min(max(0.0, $cantidad - $libre), min($stock, $apartado));

            foreach ($otras as $reserva) {
                if ($porCeder <= 0) {
                    break;
                }

                $toma = min($porCeder, $reserva->efectiva());

                if ($toma <= 0) {
                    continue;
                }

                $reserva->cantidad_cedida        = (float) $reserva->cantidad_cedida + $toma;
                $reserva->cedida_a_cotizacion_id = $venta->id;

                if ($reserva->efectiva() <= 0) {
                    $reserva->estado     = 'cedida';
                    $reserva->cerrada_at = now();
                }

                $reserva->save();
                $porCeder -= $toma;

                $cedidas[] = ['reserva' => $reserva, 'cantidad' => $toma];

                $this->avisarCesion($reserva, $venta, $producto, $toma);
            }
        }

        return $cedidas;
    }

    /**
     * Al vendedor de la cotización afectada **y** a administración, que es lo que se decidió: los
     * dos tienen que estar pendientes. Si el vendedor es además administrador, le llega una sola
     * vez, la suya.
     */
    private function avisarCesion(ReservaStock $reserva, Cotizacion $venta, Producto $producto, float $cantidad): void
    {
        $afectada = $reserva->cotizacion;
        // «4 unidad» se lee mal: la unidad por omisión va en plural cuando no es uno.
        $unidad   = $producto->unidad_medida ?: 'unidad';
        $unidad   = ($unidad === 'unidad' && abs($cantidad - 1) > 0.0001) ? 'unidades' : $unidad;
        $cuanto   = rtrim(rtrim(number_format($cantidad, 3, ',', '.'), '0'), ',');
        $quedan   = rtrim(rtrim(number_format($reserva->efectiva(), 3, ',', '.'), '0'), ',');
        $vendedor = $afectada->responsable;
        $url      = "/cotizaciones/{$afectada->id}";
        $notificar = app(NotificacionService::class);

        $quedaTexto = $reserva->efectiva() > 0 ? " Te quedan {$quedan} apartadas." : ' Ya no te queda nada apartado de este producto.';

        if ($vendedor) {
            $notificar->crear(
                $vendedor->id,
                'stock_apartado_usado',
                'Se usó stock que tenías apartado',
                "Se usarán {$cuanto} {$unidad} de «{$producto->nombre}» que tenías apartadas en la cotización {$afectada->numero}, "
                    ."para atender una venta realizada (cotización {$venta->numero}).{$quedaTexto}",
                $url,
            );
        }

        $notificar->paraRol(
            ['administrador'],
            'stock_apartado_usado',
            'Se usó stock apartado por una venta',
            "Se usarán {$cuanto} {$unidad} de «{$producto->nombre}» apartadas en la cotización {$afectada->numero}"
                .($vendedor ? " (vendedor: {$vendedor->name})" : '')
                ." para atender una venta realizada (cotización {$venta->numero}).",
            $url,
            excluirUserId: $vendedor?->id,
        );
    }

    // ─── El plazo ────────────────────────────────────────────────────────────

    /**
     * Libera lo que lleva más de 24 horas apartado y le avisa al vendedor, una vez por cotización.
     *
     * @return int cuántas reservas venció
     */
    public function liberarVencidas(): int
    {
        $vencidas = ReservaStock::where('estado', 'activa')
            ->where('expira_at', '<=', now())
            ->with('cotizacion:id,numero,estado,responsable_id', 'producto:id,nombre')
            ->get();

        if ($vencidas->isEmpty()) {
            return 0;
        }

        ReservaStock::whereIn('id', $vencidas->pluck('id'))->update(['estado' => 'vencida', 'cerrada_at' => now()]);

        foreach ($vencidas->groupBy('cotizacion_id') as $reservas) {
            $cotizacion = $reservas->first()->cotizacion;

            // Solo tiene sentido avisarle si la cotización sigue abierta: si ya se aprobó o se
            // cayó, el vendedor sabe que no hay nada que esperar.
            if (! $cotizacion || $cotizacion->estado !== 'enviada' || ! $cotizacion->responsable_id) {
                continue;
            }

            $productos = $reservas->map(fn (ReservaStock $r) => $r->producto?->nombre)->filter()->implode(', ');

            app(NotificacionService::class)->crear(
                $cotizacion->responsable_id,
                'stock_apartado_vencido',
                'Se liberó el stock apartado de una cotización',
                "Pasaron ".self::HORAS." horas y la cotización {$cotizacion->numero} no se concretó: ya no tiene apartado {$productos}. "
                    .'Sigue vigente, pero esas unidades pueden usarse en otra venta.',
                "/cotizaciones/{$cotizacion->id}",
            );
        }

        return $vencidas->count();
    }

    // ─── Lectura: lo que ven quienes cotizan ─────────────────────────────────

    /**
     * Cuánto tienen apartado OTRAS cotizaciones de cada producto.
     *
     * Se excluye la cotización que se está mirando: lo que ella misma aparta no le resta a ella.
     *
     * @param  array<int, int>  $productoIds
     * @return array<int, float>
     */
    public function apartadoPorProducto(array $productoIds, ?int $exceptoCotizacionId = null): array
    {
        if ($productoIds === [] || ! static::habilitado()) {
            return [];
        }

        return ReservaStock::vigentes()
            ->whereIn('producto_id', $productoIds)
            ->when($exceptoCotizacionId, fn ($q) => $q->where('cotizacion_id', '!=', $exceptoCotizacionId))
            ->get(['producto_id', 'cantidad', 'cantidad_cedida'])
            ->groupBy('producto_id')
            ->map(fn (Collection $filas) => (float) $filas->sum(fn (ReservaStock $r) => $r->efectiva()))
            ->all();
    }

    /**
     * Stock, apartado y disponible de varios productos, para mostrarlo mientras se cotiza.
     *
     * @param  array<int, int>  $productoIds
     * @param  array<int, int>  $bodegaIds  las bodegas que ve quien pregunta; vacío = todas
     * @return array<int, array{stock: float, apartado: float, disponible: float}>
     */
    public function disponibilidad(array $productoIds, array $bodegaIds, ?int $exceptoCotizacionId = null): array
    {
        $apartado = $this->apartadoPorProducto($productoIds, $exceptoCotizacionId);

        return Producto::whereIn('id', $productoIds)->get()->mapWithKeys(function (Producto $p) use ($bodegaIds, $apartado) {
            $stock = $p->stockEnBodegas($bodegaIds);
            $aparta = (float) ($apartado[$p->id] ?? 0);

            return [$p->id => [
                'stock'      => $stock,
                'apartado'   => $aparta,
                'disponible' => max(0.0, $stock - $aparta),
            ]];
        })->all();
    }

    // ─── Detalles ────────────────────────────────────────────────────────────

    /**
     * Las líneas que se pueden apartar: productos, con las cantidades de cada uno sumadas.
     *
     * @return Collection<int, float>  producto_id => cantidad
     */
    public function lineas(Cotizacion $cotizacion): Collection
    {
        return $cotizacion->items()
            ->where('tipo', 'producto')
            ->whereNotNull('producto_id')
            ->where('cantidad', '>', 0)
            ->get(['producto_id', 'cantidad'])
            ->groupBy('producto_id')
            ->map(fn (Collection $filas) => (float) $filas->sum('cantidad'));
    }

    /**
     * Las bodegas de la sede de la cotización. Una cotización pública no tiene un usuario con
     * sede activa, así que se mira la sede de la propia cotización. Sin sede o sin bodegas con
     * sede asignada —el estado natural de una empresa de una sola sede—, todas.
     *
     * @return array<int, int>
     */
    private function bodegasDe(Cotizacion $cotizacion): array
    {
        if (! $cotizacion->sede_id) {
            return [];
        }

        return Bodega::where('activa', true)->where('sede_id', $cotizacion->sede_id)->pluck('id')->all();
    }
}
