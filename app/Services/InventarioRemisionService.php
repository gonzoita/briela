<?php

namespace App\Services;

use App\Models\Bodega;
use App\Models\Op;
use App\Models\OpItem;
use App\Models\OpItemTrabajo;
use App\Models\Producto;
use App\Models\ProductoMovimiento;
use App\Models\Remision;
use App\Models\RemisionItem;
use App\Support\Modulos;

/**
 * Lo que se remisiona sale del almacén. **Un solo sitio**, y en el momento de crear la remisión.
 *
 * - Un **producto** sale tal cual: si se remisionan cuatro bisagras, salen cuatro bisagras.
 * - Un **ensamble** sale por sus componentes: la receta de la unidad por las unidades que viajan.
 *   Terminar en planta no mueve inventario; lo que se gastó se registra cuando sale de la empresa,
 *   que es cuando nadie puede discutir que se usó.
 * - Una unidad vieja que ya había entrado a bodega como **producto terminado** (antes de este
 *   cambio el material se descontaba al terminarla) sale como ese producto terminado: sus
 *   componentes ya se habían descontado, y volver a hacerlo los contaría dos veces.
 * - Los servicios no se mueven: no viven en una bodega.
 *
 * Cada movimiento queda con origen `remision` y el id de la remisión. Anular **devuelve
 * exactamente esos movimientos**, leídos de la base: no se recalcula la receta, que pudo haber
 * cambiado entre tanto, ni se adivina qué unidades eran.
 *
 * Si algo no alcanza se descuenta lo que hay y **se avisa** a administración y jefatura: la
 * mercancía ya salió en el camión, y negarse a registrarlo no la devuelve.
 */
class InventarioRemisionService
{
    /** @var list<array<string, mixed>> */
    private array $faltantes = [];

    public function sacar(Remision $remision): void
    {
        if ($remision->inventario_movido_at || ! Modulos::activo('inventario')) {
            return;
        }

        $remision->loadMissing(['items.opItem.ensamble', 'items.producto', 'op']);
        $this->faltantes = [];

        foreach ($remision->items as $linea) {
            $this->sacarLinea($remision, $linea);
        }

        $remision->update(['inventario_movido_at' => now()]);

        $this->avisarFaltantes($remision);
    }

    /** Anularon o borraron la remisión: lo que sacó vuelve a donde estaba. */
    public function devolver(Remision $remision): void
    {
        if (! $remision->inventario_movido_at || ! Modulos::activo('inventario')) {
            return;
        }

        $salidas = ProductoMovimiento::where('origen_tipo', 'remision')
            ->where('origen_id', $remision->id)
            ->whereIn('tipo', ['salida', 'consumo_ensamble'])
            ->get();

        foreach ($salidas as $mov) {
            $producto = Producto::find($mov->producto_id);

            if (! $producto || (float) $mov->cantidad <= 0) {
                continue;
            }

            $producto->registrarMovimiento(
                tipo: 'devolucion',
                cantidad: (float) $mov->cantidad,
                bodegaId: (int) $mov->bodega_id,
                usuarioId: $this->usuario($remision),
                origenTipo: 'remision',
                origenId: $remision->id,
                notas: "Remisión {$remision->numero} anulada: vuelve a la bodega",
            );
        }

        $remision->update(['inventario_movido_at' => null]);
    }

    private function sacarLinea(Remision $remision, RemisionItem $linea): void
    {
        $opItem   = $linea->opItem;
        $cantidad = (float) $linea->cantidad;

        if ($cantidad <= 0 || ($opItem && in_array($opItem->tipo, Op::TIPOS_SIN_DESPACHO, true))) {
            return;
        }

        // Un ensamble sale por sus componentes (o por su producto terminado, si ya había entrado).
        if ($opItem && $opItem->ensamble_id) {
            $this->sacarEnsamble($remision, $linea, $opItem);

            return;
        }

        $producto = $linea->producto ?? $opItem?->producto;

        if (! $producto || $producto->tipo === 'servicio' || $producto->es_padre) {
            return;
        }

        $this->descontar($remision, $producto, $cantidad, $this->bodegaDeProductos($remision), 'salida',
            "Despacho en la remisión {$remision->numero}");
    }

    private function sacarEnsamble(Remision $remision, RemisionItem $linea, OpItem $item): void
    {
        $unidades = OpItemTrabajo::where('op_item_id', $item->id)->where('remision_id', $remision->id)->get();

        // Las que ya habían entrado a bodega como producto terminado salen como tal.
        $yaEnBodega = $unidades->whereNotNull('entregado_at')->count();
        $porReceta  = $unidades->isEmpty()
            ? (float) $linea->cantidad      // ensamble sin unidades (alistado a mano), o remisión vieja
            : $unidades->count() - $yaEnBodega;

        if ($yaEnBodega > 0 && ($terminado = $item->ensamble?->productoTerminado)) {
            $this->descontar($remision, $terminado, $yaEnBodega, $this->bodegaDeProductos($remision), 'salida',
                "Despacho en la remisión {$remision->numero} (unidades ya armadas en bodega)");
        }

        if ($porReceta <= 0) {
            return;
        }

        $bodega = $this->bodegaDeMaterial($remision);

        foreach ($this->receta($item) as $comp) {
            $material = Producto::find($comp['producto_id']);

            if (! $material || $material->tipo === 'servicio' || $material->es_padre) {
                continue;
            }

            $this->descontar($remision, $material, $comp['cantidad'] * $porReceta, $bodega, 'consumo_ensamble',
                "Componente de «{$item->descripcion}» · remisión {$remision->numero}");
        }
    }

    /**
     * La receta de UNA unidad, sumada por material: la que quedó congelada en la orden, o la del
     * ensamble si la orden no la guardó. Los conceptos libres —mano de obra— no tienen producto.
     *
     * @return list<array{producto_id: int, cantidad: float}>
     */
    private function receta(OpItem $item): array
    {
        $componentes = $item->componentes_snapshot ?: ($item->ensamble?->componentes_resultado ?? []);
        $porMaterial = [];

        foreach ((array) $componentes as $comp) {
            $id       = $comp['producto_id'] ?? $comp['componente_id'] ?? null;
            $cantidad = (float) ($comp['cantidad_real'] ?? $comp['cantidad'] ?? 0);

            if ($id && $cantidad > 0) {
                $porMaterial[$id] = ($porMaterial[$id] ?? 0) + $cantidad;
            }
        }

        return collect($porMaterial)->map(fn ($c, $id) => ['producto_id' => (int) $id, 'cantidad' => $c])->values()->all();
    }

    /**
     * Descuenta lo que hay, hasta lo pedido, y anota lo que faltó. `registrarMovimiento()` corta
     * en cero sin decir nada; se mide antes para que el movimiento diga la verdad y para avisar.
     */
    private function descontar(Remision $remision, Producto $producto, float $cantidad, ?Bodega $bodega, string $tipo, string $nota): void
    {
        if (! $bodega || $cantidad <= 0) {
            return;
        }

        $hay       = (float) $producto->stockEnBodega($bodega->id);
        $descontar = round(min($cantidad, max(0, $hay)), 3);

        if ($descontar > 0) {
            $producto->registrarMovimiento(
                tipo: $tipo,
                cantidad: $descontar,
                bodegaId: $bodega->id,
                usuarioId: $this->usuario($remision),
                origenTipo: 'remision',
                origenId: $remision->id,
                notas: $nota,
            );
        }

        if ($cantidad - $descontar > 0.0001) {
            $this->faltantes[] = [
                'nombre' => $producto->nombre,
                'unidad' => $producto->unidad_medida,
                'pedia'  => round($cantidad, 3),
                'habia'  => round(max(0, $hay), 3),
                'bodega' => $bodega->nombre,
            ];
        }
    }

    private function avisarFaltantes(Remision $remision): void
    {
        if ($this->faltantes === []) {
            return;
        }

        $lineas = collect($this->faltantes)
            ->map(fn ($f) => "{$f['nombre']}: salían {$f['pedia']} {$f['unidad']} y había {$f['habia']} en {$f['bodega']}")
            ->implode(' · ');

        app(NotificacionService::class)->paraRol(
            ['administrador', 'jefe_produccion'],
            'material_faltante',
            "Inventario descuadrado en la remisión {$remision->numero}",
            count($this->faltantes) . ' artículo(s) no alcanzaron en bodega. ' . $lineas,
            "/logistica/remisiones/{$remision->id}",
        );

        $this->faltantes = [];
    }

    /** De dónde salen los productos: la bodega de entrega de la orden, o la principal. */
    private function bodegaDeProductos(Remision $remision): ?Bodega
    {
        $id = $remision->op?->bodega_entrega_id;

        return ($id ? Bodega::find($id) : null) ?? $this->principal($remision);
    }

    /** De dónde salen los componentes: la bodega de material de la orden, o la principal. */
    private function bodegaDeMaterial(Remision $remision): ?Bodega
    {
        $id = $remision->op?->bodega_material_id;

        return ($id ? Bodega::find($id) : null) ?? $this->principal($remision);
    }

    private function principal(Remision $remision): ?Bodega
    {
        return Bodega::principal($remision->sede_id) ?? Bodega::principal() ?? Bodega::where('activa', true)->orderBy('id')->first();
    }

    private function usuario(Remision $remision): int
    {
        return (int) (auth()->id() ?? $remision->created_by);
    }
}
