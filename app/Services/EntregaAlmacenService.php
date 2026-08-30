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

        $entregada = DB::transaction(function () use ($trabajo, $item, $ensamble, $bodega) {
            // Se relee con candado: dos operarios cerrando el último paso a la vez llegarían
            // los dos hasta aquí, y el `entregado_at` que ya se revisó arriba estaría viejo.
            $fresco = OpItemTrabajo::whereKey($trabajo->getKey())->lockForUpdate()->first();

            if (! $fresco || $fresco->entregado_at) {
                return null;
            }

            $this->descontarMateriales($item, $this->bodegaDelMaterial($trabajo, $bodega), $trabajo);

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

        // Fuera de la transacción a propósito: un aviso no debe poder tumbar una entrega que
        // ya está bien registrada, y una notificación creada dentro de una transacción que
        // luego se revierte avisa de algo que no pasó.
        if ($entregada) {
            $this->avisarFaltantes($trabajo, $this->bodegaDelMaterial($trabajo, $entregada));
        }

        return $entregada;
    }

    /**
     * A qué bodega entra, en este orden: la de la OP, la del último paso, la principal.
     *
     * **Manda la OP.** Es la decisión de quien planea la producción y se toma al confirmar la
     * orden, así que vale para todo lo que esa orden fabrique. Antes la tomaba el operario al
     * cerrar el último paso de cada unidad, o venía predefinida en la plantilla del ensamble:
     * los dos sitios estaban mal, porque el mismo ensamble se fabrica hoy para una bodega y
     * mañana para otra.
     *
     * El paso sigue valiendo como respaldo, no como capricho: las OPs que ya existían nacieron
     * sin el campo, y sus unidades a medio fabricar tienen que poder terminar de entrar.
     *
     * La principal al final, y no un error: una unidad terminada existe en algún estante
     * aunque nadie haya configurado nada, y negarse a registrarla la dejaría invisible — que
     * es justo el problema que esto viene a resolver.
     */
    private function bodegaDeEntrega(OpItemTrabajo $trabajo): ?Bodega
    {
        // Lo primero es lo que se eligió al cerrar el paso final de ESTA unidad. Viene ya
        // precargado de la orden, así que casi siempre son el mismo valor; cuando no lo son es
        // porque alguien corrigió a dónde quedó de verdad, y esa corrección manda.
        if ($trabajo->bodega_entrega_id && $bodega = Bodega::find($trabajo->bodega_entrega_id)) {
            return $bodega;
        }

        $deLaOp = $trabajo->opItem?->op?->bodega_entrega_id;

        if ($deLaOp && $bodega = Bodega::find($deLaOp)) {
            return $bodega;
        }

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
     * De qué bodega sale el material, que **no es la misma** a la que entra lo fabricado.
     *
     * Una bodega de producto terminado no guarda insumos. Mientras las dos fueron la misma, el
     * descuento se hacía contra una bodega en cero, `registrarMovimiento()` lo recortaba con
     * `max(0, …)` y el material seguía figurando entero donde de verdad estaba: un descuento
     * que no descontaba nada, sin error y sin stock en rojo.
     *
     * La declara la OP, y es obligatoria para confirmarla. El respaldo a la bodega de entrega
     * es para las órdenes nacidas antes del campo: para ellas se conserva el comportamiento
     * que tenían, que es lo único honesto — cambiarles el sitio de descuento a mitad de
     * fabricación movería inventario que ya se contó de otra manera.
     */
    private function bodegaDelMaterial(OpItemTrabajo $trabajo, Bodega $entrega): Bodega
    {
        // Igual que la de entrega: manda lo que se eligió al cerrar el paso final de esta
        // unidad, y la orden es el respaldo.
        $id = $trabajo->bodega_material_id ?: $trabajo->opItem?->op?->bodega_material_id;

        return ($id ? Bodega::find($id) : null) ?? $entrega;
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

            // Lo que de verdad hay ahí. `registrarMovimiento()` corta en `max(0, …)`, así que
            // pedirle más de lo que existe no falla ni deja negativo: descuenta hasta cero y
            // se calla. Ese silencio es el problema, no el recorte — la unidad ya está armada
            // y el material se gastó de verdad. Se mide antes para poder decirlo.
            $hay      = (float) $material->stockEnBodega($bodega->id);
            $descontar = min($cantidad, $hay);

            if ($descontar > 0) {
                $material->registrarMovimiento(
                    tipo: 'consumo_ensamble',
                    cantidad: $descontar,
                    bodegaId: $bodega->id,
                    usuarioId: auth()->id(),
                    origenTipo: 'op',
                    origenId: $item->op_id,
                    notas: "Consumo al fabricar · OP #{$item->op_id} · unidad {$trabajo->numero_unidad}",
                );
            }

            if ($cantidad - $descontar > 0.0001) {
                $this->sinDescontar[] = [
                    'nombre'   => $comp['nombre'] ?? $material->nombre,
                    'unidad'   => $comp['unidad'] ?? $material->unidad_medida,
                    'pedia'    => $cantidad,
                    'habia'    => $hay,
                    'falto'    => round($cantidad - $descontar, 4),
                ];
            }
        }
    }

    /** @var array<int, array<string, mixed>> Lo que no alcanzó a descontarse en esta entrega. */
    private array $sinDescontar = [];

    /**
     * Avisa lo que quedó sin descontar.
     *
     * No bloquea nada: la unidad ya está armada y el trabajo del operario ya está hecho.
     * Negarse a registrarlo no devuelve el material, solo esconde dos problemas en vez de uno.
     * Lo que corresponde es dejar el inventario lo más cerca posible de la realidad y **decir
     * en qué quedó corto**, para que alguien lo cuadre.
     *
     * Va a administración y jefe de producción, que es quien puede hacer algo: el operario no
     * arregla un inventario descuadrado desde la pantalla del código QR.
     */
    private function avisarFaltantes(OpItemTrabajo $trabajo, Bodega $bodega): void
    {
        if ($this->sinDescontar === []) {
            return;
        }

        $op      = $trabajo->opItem?->op;
        $lineas  = collect($this->sinDescontar)
            ->map(fn ($f) => "{$f['nombre']}: faltaron {$f['falto']} {$f['unidad']} (pedía {$f['pedia']}, había {$f['habia']})")
            ->implode(' · ');

        app(\App\Services\NotificacionService::class)->paraRol(
            ['administrador', 'jefe_produccion'],
            'material_faltante',
            'Inventario descuadrado en ' . ($op?->numero ?? 'una OP'),
            'Se fabricó una unidad pero ' . count($this->sinDescontar)
                . ' insumo(s) no alcanzaron en ' . $bodega->nombre . '. ' . $lineas,
            $op ? "/produccion/ops/{$op->id}" : null,
        );

        $this->sinDescontar = [];
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
