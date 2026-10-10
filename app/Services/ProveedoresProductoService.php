<?php

namespace App\Services;

use App\Models\OrdenCompra;
use App\Models\Producto;
use App\Models\ProductoProveedor;
use App\Models\ProductoProveedorPrecio;
use Illuminate\Support\Collection;

/**
 * Lo que se sabe de cada proveedor de un producto: su código, su precio y su historia.
 *
 * Las tres formas de crear una línea de orden de compra —crear, editar, convertir una
 * solicitud— necesitan lo mismo: «¿con qué código conoce este proveedor este producto, y a
 * cuánto me lo vende?». Escrito en cada una, las tres lo responderían distinto al primer
 * arreglo. Está aquí, en un solo sitio.
 */
class ProveedoresProductoService
{
    /** Un precio de hace más de esto ya no es un precio, es un recuerdo. */
    public const DIAS_PRECIO_VIGENTE = 90;

    public function de(?int $productoId, int $proveedorId): ?ProductoProveedor
    {
        if (! $productoId) {
            return null;
        }

        return ProductoProveedor::where('producto_id', $productoId)
            ->where('proveedor_id', $proveedorId)
            ->first();
    }

    /**
     * Lo que la pantalla de la orden necesita para llenar sola el código y el precio cuando se
     * elige proveedor: por producto, y dentro, por proveedor.
     *
     * @param  iterable<int>  $productoIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function mapaParaOrden(iterable $productoIds): array
    {
        $ids = collect($productoIds)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return ProductoProveedor::whereIn('producto_id', $ids)
            ->get()
            ->groupBy('producto_id')
            ->map(fn (Collection $filas) => $filas->mapWithKeys(fn (ProductoProveedor $f) => [
                $f->proveedor_id => [
                    'referencia'      => $f->referencia_proveedor,
                    'precio'          => (float) $f->precio,
                    'dias_entrega'    => $f->dias_entrega,
                    'minimo_compra'   => $f->minimo_compra !== null ? (float) $f->minimo_compra : null,
                    'dias_actualizado' => $f->diasDesdeActualizacion(),
                ],
            ])->all())
            ->all();
    }

    /**
     * Guarda la equivalencia «este proveedor lo llama así» cuando se escribe en la orden.
     *
     * Es la segunda puerta para configurarla —la primera es la ficha del producto—: quien
     * compra descubre el código del proveedor justo al armar la orden, y obligarlo a salir a
     * otra pantalla a guardarlo es la razón por la que no se guardaba. Si el proveedor no
     * tenía fila para este producto, se crea con el precio de la línea.
     */
    public function guardarEquivalencia(int $productoId, int $proveedorId, ?string $referencia, float $precio = 0): void
    {
        $referencia = trim((string) $referencia);

        if ($referencia === '') {
            return;
        }

        $fila = ProductoProveedor::firstOrNew(['producto_id' => $productoId, 'proveedor_id' => $proveedorId]);

        if ($fila->exists && $fila->referencia_proveedor === $referencia) {
            return;
        }

        if (! $fila->exists) {
            $fila->precio         = $precio;
            $fila->actualizado_el = $precio > 0 ? today() : null;
            $fila->es_preferido   = ! ProductoProveedor::where('producto_id', $productoId)->exists();
        }

        $fila->referencia_proveedor = $referencia;
        $fila->save();

        $this->sincronizarPreferido($productoId);
    }

    /**
     * Cuando una orden sale al proveedor, sus precios pasan a ser un hecho: se actualiza el
     * último y se agrega una fila al historial.
     *
     * Se hace al **enviar**, no al crear el borrador: un borrador es una intención y se puede
     * cambiar diez veces; lo que se manda es lo que se acordó.
     */
    public function registrarPrecios(OrdenCompra $orden): void
    {
        $orden->loadMissing('items');

        foreach ($orden->items as $linea) {
            $precio = (float) $linea->precio_unitario;

            if (! $linea->item_id || $precio <= 0) {
                continue;
            }

            $fila = ProductoProveedor::firstOrNew([
                'producto_id'  => $linea->item_id,
                'proveedor_id' => $orden->proveedor_id,
            ]);

            if (! $fila->exists) {
                $fila->es_preferido = ! ProductoProveedor::where('producto_id', $linea->item_id)->exists();
            }

            if (! $fila->referencia_proveedor && $linea->referencia_proveedor) {
                $fila->referencia_proveedor = $linea->referencia_proveedor;
            }

            $fila->precio         = $precio;
            $fila->actualizado_el = today();
            $fila->save();

            ProductoProveedorPrecio::updateOrCreate(
                ['producto_id' => $linea->item_id, 'proveedor_id' => $orden->proveedor_id, 'orden_compra_id' => $orden->id],
                ['precio' => $precio, 'registrado_el' => today()],
            );

            $this->sincronizarPreferido($linea->item_id);
        }
    }

    /**
     * Los proveedores de un producto puestos uno al lado del otro: lo que se mira antes de
     * comprar, y lo que lee el asistente para recomendar.
     *
     * El más barato **vigente**: el que tiene un precio de hace ocho meses no gana por eso,
     * porque esa cifra ya no es una oferta. Si ninguno está vigente se dice, en vez de elegir
     * uno con cara de exacto.
     *
     * @return array<string, mixed>
     */
    public function comparar(int $productoId): array
    {
        $filas = ProductoProveedor::with('proveedor:id,nombre')
            ->where('producto_id', $productoId)
            ->get();

        $historial = ProductoProveedorPrecio::where('producto_id', $productoId)
            ->orderByDesc('registrado_el')
            ->orderByDesc('id')
            ->get()
            ->groupBy('proveedor_id');

        $proveedores = $filas->map(function (ProductoProveedor $f) use ($historial) {
            $dias = $f->diasDesdeActualizacion();

            return [
                'proveedor_id'     => $f->proveedor_id,
                'proveedor'        => $f->proveedor?->nombre,
                'codigo_proveedor' => $f->referencia_proveedor,
                'precio'           => (float) $f->precio,
                'dias_entrega'     => $f->dias_entrega,
                'minimo_compra'    => $f->minimo_compra !== null ? (float) $f->minimo_compra : null,
                'actualizado_hace_dias' => $dias,
                'precio_vigente'   => $dias !== null && $dias <= self::DIAS_PRECIO_VIGENTE && (float) $f->precio > 0,
                'es_preferido'     => (bool) $f->es_preferido,
                'ultimos_precios'  => ($historial->get($f->proveedor_id) ?? collect())
                    ->take(5)
                    ->map(fn ($h) => ['fecha' => $h->registrado_el?->toDateString(), 'precio' => (float) $h->precio])
                    ->values()
                    ->all(),
            ];
        })->sortBy([
            fn ($a, $b) => ($b['precio_vigente'] <=> $a['precio_vigente']),
            fn ($a, $b) => ($a['precio'] ?: INF) <=> ($b['precio'] ?: INF),
        ])->values();

        $vigentes = $proveedores->where('precio_vigente', true);

        return [
            'proveedores' => $proveedores->all(),
            'mas_barato'  => $vigentes->sortBy('precio')->first()['proveedor'] ?? null,
            'aviso'       => match (true) {
                $proveedores->isEmpty() => 'Este producto no tiene proveedores registrados.',
                $vigentes->isEmpty()    => 'Ningún precio está vigente (todos tienen más de '.self::DIAS_PRECIO_VIGENTE.' días o están en cero): confirma precios antes de decidir.',
                default                 => null,
            },
        ];
    }

    /** Mantiene `productos.proveedor_id`, que las órdenes y otras pantallas leen, apuntando al preferido. */
    private function sincronizarPreferido(int $productoId): void
    {
        $preferido = ProductoProveedor::where('producto_id', $productoId)->where('es_preferido', true)->value('proveedor_id');

        if ($preferido) {
            Producto::whereKey($productoId)->whereNull('proveedor_id')->update(['proveedor_id' => $preferido]);
        }
    }
}
