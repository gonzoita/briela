<?php

namespace App\Services;

use App\Models\Bodega;
use App\Models\OpItemTrabajo;
use App\Models\Producto;
use Illuminate\Support\Facades\DB;

/**
 * Cuando una unidad termina de fabricarse, entra a una bodega.
 *
 * Es el eslabón que faltaba entre producción e inventario. Antes un trabajo terminaba y ahí
 * quedaba: la unidad existía en el mundo real —armada, aprobada, en un estante— y en el
 * sistema no existía en ninguna parte. El material recién se descontaba al despachar, así que
 * entre fabricar y despachar el inventario mostraba insumos que ya no estaban y no mostraba el
 * producto que sí estaba.
 *
 * Un `OpItemTrabajo` es **una unidad física**, así que cada trabajo que termina mueve una
 * unidad. Al entregarla pasan dos cosas, y las dos en la misma transacción:
 *
 * 1. Se **descuentan los materiales** de esa unidad. Es el momento honesto: es cuando se
 *    gastaron. Hacerlo al despachar dejaba el inventario mintiendo mientras la unidad esperaba.
 * 2. Se **registra la entrada** del producto terminado en la bodega que declaró el último paso.
 *
 * Después, al despachar, la remisión saca de esa bodega y **no** vuelve a tocar los materiales
 * — eso lo resuelve `Op::consumirMaterialesInventario()`, que ve el stock del terminado y se
 * aparta.
 */
class EntregaAlmacenService
{
    /**
     * Entrega una unidad terminada a su bodega. Devuelve la bodega, o null si no hizo nada.
     *
     * Es idempotente: un trabajo ya entregado no vuelve a entrar. Sin ese candado, volver a
     * marcar el último paso —o dos personas marcándolo a la vez— metería la misma unidad dos
     * veces al inventario, y el error no se nota hasta que alguien cuenta el estante.
     */
    public function entregar(OpItemTrabajo $trabajo): ?Bodega
    {
        if ($trabajo->entregado_at) {
            return null;
        }

        $item     = $trabajo->opItem;
        $ensamble = $item?->ensamble;

        // Sin ensamble no hay receta ni producto que entregar: es un ítem suelto de la OP.
        if (! $ensamble) {
            return null;
        }

        $bodega = $this->bodegaDeEntrega($trabajo);

        if (! $bodega) {
            return null;
        }

        return DB::transaction(function () use ($trabajo, $item, $ensamble, $bodega) {
            // Se relee con candado: dos operarios cerrando el último paso a la vez llegarían
            // los dos hasta aquí, y el `entregado_at` que ya se revisó arriba estaría viejo.
            $fresco = OpItemTrabajo::whereKey($trabajo->getKey())->lockForUpdate()->first();

            if (! $fresco || $fresco->entregado_at) {
                return null;
            }

            $this->descontarMateriales($item, $bodega, $trabajo);

            $terminado = $ensamble->sincronizarProductoTerminado()
                ?? $this->forzarProductoTerminado($ensamble);

            $terminado->registrarMovimiento(
                tipo: 'entrada',
                cantidad: 1,
                bodegaId: $bodega->id,
                usuarioId: auth()->id(),
                precioUnitario: (float) $ensamble->precio_costo,
                origenTipo: 'op',
                origenId: $item->op_id,
                notas: "Fabricada · OP #{$item->op_id} · unidad {$trabajo->numero_unidad} de {$trabajo->total_unidades}",
            );

            $fresco->update([
                'entregado_at'      => now(),
                'bodega_entrega_id' => $bodega->id,
            ]);

            return $bodega;
        });
    }

    /**
     * A qué bodega entra: la que dijo el último paso.
     *
     * El respaldo a la principal se conserva para las unidades viejas, cuyos pasos se crearon
     * cuando la bodega no se pedía. Para las nuevas ya no aplica: cerrar el paso final exige
     * elegirla, porque mandar inventario a una bodega que nadie decidió es hacerlo aparecer
     * donde no es.
     *
     * La principal como respaldo y no un error: una unidad terminada existe en algún estante
     * aunque nadie haya configurado el paso, y negarse a registrarla la dejaría invisible —
     * que es justo el problema que esto viene a resolver.
     */
    private function bodegaDeEntrega(OpItemTrabajo $trabajo): ?Bodega
    {
        $paso = $trabajo->pasos()
            ->whereNotNull('bodega_destino_id')
            ->orderByDesc('es_paso_final')
            ->orderByDesc('orden')
            ->first();

        if ($paso && $paso->bodega_destino_id) {
            $bodega = Bodega::find($paso->bodega_destino_id);

            if ($bodega) {
                return $bodega;
            }
        }

        return Bodega::principal() ?? Bodega::where('activa', true)->first();
    }

    /**
     * Descuenta los materiales que se gastaron en esta unidad.
     *
     * Se descuenta **una unidad de la receta**, no el ítem completo: cada trabajo es una unidad
     * física, y si la OP pidió cinco, cada una descuenta lo suyo cuando de verdad se armó. Así
     * una OP a medio fabricar tiene el inventario que le corresponde y no el de las cinco.
     *
     * Los conceptos libres —mano de obra, transporte— no descuentan nada: no viven en bodega.
     */
    private function descontarMateriales($item, Bodega $bodega, OpItemTrabajo $trabajo): void
    {
        $componentes = $item->componentes_snapshot ?: ($item->ensamble->componentes_resultado ?? []);

        foreach ($componentes as $comp) {
            $productoId = $comp['producto_id'] ?? $comp['componente_id'] ?? null;
            $cantidad   = (float) ($comp['cantidad_real'] ?? $comp['cantidad'] ?? 0);

            if (! $productoId || $cantidad <= 0) {
                continue;
            }

            $material = Producto::find($productoId);

            if (! $material) {
                continue;
            }

            $material->registrarMovimiento(
                tipo: 'consumo_ensamble',
                cantidad: $cantidad,
                bodegaId: $bodega->id,
                usuarioId: auth()->id(),
                origenTipo: 'op',
                origenId: $item->op_id,
                notas: "Consumo al fabricar · OP #{$item->op_id} · unidad {$trabajo->numero_unidad}",
            );
        }
    }

    /**
     * El producto terminado, incluso si el ensamble no tiene prendido «se guarda en bodega».
     *
     * Toda producción entra a bodega, así que la unidad necesita dónde entrar. Prender la marca
     * es lo honesto: de ese ensamble sí hay unidades guardadas, aunque nadie lo hubiera
     * declarado de antemano.
     */
    private function forzarProductoTerminado($ensamble): Producto
    {
        $ensamble->maneja_stock = true;
        $ensamble->save();

        return $ensamble->sincronizarProductoTerminado();
    }
}
