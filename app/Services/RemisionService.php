<?php

namespace App\Services;

use App\Models\Op;
use App\Models\OpItem;
use App\Models\Remision;
use App\Models\RemisionItem;
use Illuminate\Support\Facades\DB;

/**
 * Crear, mover y anular remisiones. **Un solo sitio**, lo use la pantalla o la guía.
 *
 * Crear una remisión: marca qué unidades salen (y en qué remisión), alista los productos que
 * viajan, **saca del almacén** todo lo que lleva (`InventarioRemisionService`) y pone al día la
 * orden. La orden se despacha cuando la remisión sale (en camino o entregada), no al crearla.
 *
 * Anular o borrar: suelta exactamente lo suyo y devuelve al almacén lo que sacó.
 */
class RemisionService
{
    public function __construct(private InventarioRemisionService $inventario) {}

    /**
     * Revisa que lo pedido se pueda remisionar. Devuelve los errores por campo, o [] si está bien.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, string>
     */
    public function errores(?int $opId, array $items): array
    {
        $errores = [];

        if ($opId && ! Op::whereKey($opId)->value('cliente_id')) {
            return ['op_id' => Op::SIN_CLIENTE];
        }

        foreach ($items as $idx => $item) {
            if (empty($item['op_item_id'])) {
                continue;
            }

            $opItem = OpItem::find($item['op_item_id']);

            if (! $opItem) {
                continue;
            }

            // Un ítem de otra orden, o un servicio, no viaja en esta remisión.
            if ((int) $opItem->op_id !== (int) $opId || in_array($opItem->tipo, Op::TIPOS_SIN_DESPACHO, true)) {
                $errores["items.{$idx}.op_item_id"] = 'Este ítem no se puede remisionar con esta orden.';

                continue;
            }

            $disponible = $opItem->cantidadDisponible();
            $solicitado = (int) ($item['cantidad_unidades'] ?? $item['cantidad']);

            if ($solicitado > $disponible) {
                $errores["items.{$idx}.cantidad_unidades"] = "Las unidades solicitadas ({$solicitado}) superan las disponibles ({$disponible}).";
            }
        }

        return $errores;
    }

    /**
     * Crea la remisión. Quien llama ya revisó `errores()`.
     *
     * @param  array{tipo: string, op_id?: ?int, cliente_id?: ?int, fecha_remision?: ?string,
     *               notas?: ?string, items: array<int, array<string, mixed>>}  $datos
     */
    public function crear(array $datos): Remision
    {
        return DB::transaction(function () use ($datos) {
            $opId = $datos['op_id'] ?? null;

            $remision = Remision::create([
                'tipo'           => $datos['tipo'] ?? ($opId ? 'op' : 'manual'),
                'op_id'          => $opId,
                'cliente_id'     => $datos['cliente_id'] ?? ($opId ? Op::find($opId)?->cliente_id : null),
                'fecha_remision' => $datos['fecha_remision'] ?? today(),
                'notas'          => $datos['notas'] ?? null,
                'created_by'     => auth()->id(),
            ]);

            $hayOpItems = false;

            foreach ($datos['items'] as $item) {
                $unidades = (int) ($item['cantidad_unidades'] ?? $item['cantidad']);

                $linea = RemisionItem::create([
                    'remision_id'  => $remision->id,
                    'op_item_id'   => $item['op_item_id'] ?? null,
                    'producto_id'  => $item['producto_id'] ?? null,
                    'descripcion'  => $item['descripcion'],
                    'cantidad'     => $item['cantidad_unidades'] ?? $item['cantidad'],
                    'unidad'       => $item['unidad'] ?? 'und',
                    'numero_serie' => $item['numero_serie'] ?? null,
                    'notas'        => $item['notas'] ?? null,
                ]);

                if (empty($item['op_item_id']) || $unidades <= 0) {
                    continue;
                }

                $hayOpItems = true;
                $opItem     = OpItem::find($item['op_item_id']);
                $this->marcarSalida($opItem, $remision, $unidades);

                // Lo que queda por entregar de ese ítem, congelado en la remisión: de 15 vendidas
                // salen 10 y la remisión dice «quedan 5». Mañana tiene que seguir diciéndolo.
                if ($opItem) {
                    $opItem->refresh();
                    $linea->update([
                        'cantidad_pendiente' => max(0, (int) $opItem->cantidad - $opItem->unidadesRemisionadas()),
                    ]);
                }
            }

            // Lo que se remisiona sale del almacén, ya.
            $this->inventario->sacar($remision);

            // Crear la remisión no despacha la orden: se despacha cuando la remisión **sale**.
            if ($hayOpItems && $opId) {
                Op::find($opId)?->sincronizarEstado();
            }

            return $remision;
        });
    }

    /**
     * Cambia el estado de una remisión. Devuelve el motivo si no se puede; null si quedó hecho.
     *
     * @param  array<string, mixed>  $extra  datos del transportista, receptor y fechas
     */
    public function cambiarEstado(Remision $remision, string $estado, array $extra = []): ?string
    {
        if (! $remision->puedePasarA($estado)) {
            return "Una remisión {$remision->estadoBadge()['label']} no puede pasar a ese estado.";
        }

        $data = ['estado' => $estado];

        if ($estado === 'en_camino') {
            $data['fecha_salida'] = $extra['fecha_salida'] ?? now();
            foreach (['transportista', 'celular_transportista', 'placa', 'costo_flete'] as $campo) {
                if (! empty($extra[$campo])) {
                    $data[$campo] = $extra[$campo];
                }
            }
        }

        if ($estado === 'entregada') {
            $data['fecha_entrega']   = $extra['fecha_entrega'] ?? now();
            $data['nombre_receptor'] = $extra['nombre_receptor'] ?? $remision->nombre_receptor;
        }

        DB::transaction(function () use ($remision, $data, $estado) {
            if ($estado === 'anulada') {
                $this->liberar($remision);
            }

            $remision->update($data);
            $this->alSalir($remision);
            $this->cerrarSiCorresponde($remision);
        });

        return null;
    }

    /**
     * Una remisión entregada se cierra cuando ya no queda nada por cobrar: la orden pagada del
     * todo con pagos verificados. Una remisión suelta, sin orden, no tiene nada que cobrar y se
     * cierra al entregarse.
     */
    public function cerrarSiCorresponde(Remision $remision): void
    {
        if ($remision->estado !== 'entregada') {
            return;
        }

        if ($remision->op_id) {
            $this->sincronizarCierre($remision->op);

            return;
        }

        $remision->update(['estado' => 'cerrada', 'cerrada_at' => now()]);
    }

    /**
     * Cierra o reabre las remisiones entregadas de una orden según lo cobrado. Lo llaman los
     * pagos al anotarse, verificarse o borrarse, y la remisión al entregarse.
     *
     * Reabrir es a propósito: si se borra o se desverifica un pago, la orden vuelve a deber y
     * sus remisiones vuelven a «Entregada — pendiente de pago».
     */
    public function sincronizarCierre(?Op $op): void
    {
        if (! $op) {
            return;
        }

        $pagada = $op->fresh()->pagadaYVerificada();

        foreach ($op->remisiones()->whereIn('estado', ['entregada', 'cerrada'])->get() as $remision) {
            if ($pagada && $remision->estado === 'entregada') {
                $remision->update(['estado' => 'cerrada', 'cerrada_at' => now()]);
            } elseif (! $pagada && $remision->estado === 'cerrada') {
                $remision->update(['estado' => 'entregada', 'cerrada_at' => null]);
            }
        }
    }

    /** Si la remisión salió (en camino o entregada), la orden se pone al día: puede quedar despachada. */
    public function alSalir(Remision $remision): void
    {
        if (in_array($remision->estado, Remision::SALIDAS, true) && $remision->op_id) {
            Op::find($remision->op_id)?->sincronizarEstado();
        }
    }

    /**
     * Suelta las unidades y productos de esta remisión y devuelve al almacén lo que sacó.
     *
     * Exactamente lo suyo: las unidades que salieron en ella (`op_item_trabajos.remision_id`) y,
     * en un producto, la cantidad que llevaba. Antes se soltaban «las últimas N» del ítem, y con
     * dos remisiones de la misma orden se podían soltar las de la otra.
     */
    public function liberar(Remision $remision): void
    {
        foreach ($remision->items()->whereNotNull('op_item_id')->get() as $linea) {
            $opItem = OpItem::find($linea->op_item_id);

            if (! $opItem) {
                continue;
            }

            $suyas = $opItem->trabajos()->where('remision_id', $remision->id);

            if ((clone $suyas)->exists()) {
                $suyas->update(['remisionado' => false, 'remision_id' => null]);
            } else {
                // Remisiones anteriores a `remision_id`: se sueltan las últimas, como antes.
                $opItem->trabajos()->where('remisionado', true)->whereNull('remision_id')
                    ->orderByDesc('numero_unidad')->take((int) $linea->cantidad)->get()
                    ->each(fn ($t) => $t->update(['remisionado' => false]));
            }

            $total = $opItem->sinTrabajos()
                ? max(0, (float) $opItem->cantidad_remisionada - (float) $linea->cantidad)
                : $opItem->trabajos()->where('remisionado', true)->count();

            $opItem->update([
                'cantidad_remisionada' => $total,
                'remisionado'          => false,
                'remision_id'          => $total > 0 ? $opItem->remision_id : null,
            ]);
        }

        $this->inventario->devolver($remision);

        // Si esta remisión había despachado la orden, la orden vuelve a donde estaba antes de salir.
        if ($remision->op_id) {
            Op::find($remision->op_id)?->revertirDespacho();
        }
    }

    /** Marca qué sale de un ítem de la orden en esta remisión y pone al día su cuenta. */
    private function marcarSalida(?OpItem $opItem, Remision $remision, int $unidades): void
    {
        if (! $opItem) {
            return;
        }

        $opItem->trabajos()->disponiblesParaRemision()
            ->orderBy('numero_unidad')->take($unidades)->get()
            ->each(fn ($t) => $t->update(['remisionado' => true, 'remision_id' => $remision->id]));

        // Un producto no tiene unidades que contar y lleva su propia cuenta: si se contara por
        // unidades, volvería a cero en cada remisión y se podría despachar lo mismo muchas veces.
        $totalTrabajos = $opItem->trabajos()->count();
        $remisionados  = $totalTrabajos > 0
            ? $opItem->trabajos()->where('remisionado', true)->count()
            : (int) $opItem->cantidad_remisionada + $unidades;

        // «Completo» es que no quede ninguna unidad sin remisionar, no que no queden disponibles:
        // las que faltan pueden seguir en revisión de calidad.
        $completo = $totalTrabajos > 0
            ? $opItem->trabajos()->where('remisionado', false)->doesntExist()
            : $remisionados >= (int) $opItem->cantidad;

        $cambios = [
            'cantidad_remisionada' => $remisionados,
            'remisionado'          => $completo,
            'remision_id'          => $completo ? $remision->id : $opItem->remision_id,
        ];

        // Remisionar un producto es la prueba de que lo que viaja está listo: queda alistado. Lo
        // que viaja, no todo: de 15, remisionar 10 deja 10 alistadas y 5 pendientes.
        if ($totalTrabajos === 0) {
            $alistadas = max($opItem->cantidadAlistada(), $remisionados);
            $cambios['cantidad_alistada'] = $alistadas;
            $cambios['estado_item']       = $alistadas >= (int) $opItem->cantidad ? 'terminado' : 'en_proceso';
        }

        $opItem->update($cambios);
    }
}
