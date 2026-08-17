<?php

namespace App\Services;

use App\Models\Producto;

/**
 * Arma la receta de un ensamble **directo**: líneas con cantidades exactas, sin fórmulas.
 *
 * **La decisión que hace que esto sea barato:** las líneas se guardan con la misma forma que
 * los componentes que calcula `FormulaEvaluatorService`. Por eso la orden de producción, el
 * consumo de inventario al despachar, los PDF y la cotización no distinguen un ensamble
 * directo de uno con plantilla — no hubo que tocar ninguno.
 *
 * Cada línea es una de dos cosas:
 *
 * - **Un producto del catálogo**: su costo sale del producto y al despachar descuenta
 *   inventario, como cualquier material de una receta.
 * - **Un concepto libre** —mano de obra, transporte, instalación—: suma al costo y no
 *   descuenta nada, porque no es algo que exista en una bodega. Se distingue por no tener
 *   `producto_id`.
 */
class EnsambleDirectoService
{
    /**
     * Convierte las líneas del formulario en componentes guardables.
     *
     * @param  array<int, array<string, mixed>>  $lineas
     * @return array<int, array<string, mixed>>
     */
    public function componentes(array $lineas): array
    {
        // Se leen todos los productos de una vez: una consulta por línea son treinta
        // consultas en un kit de treinta materiales.
        $ids = collect($lineas)->pluck('producto_id')->filter()->unique()->all();

        $productos = $ids !== []
            ? Producto::whereIn('id', $ids)->get()->keyBy('id')
            : collect();

        return collect($lineas)
            ->map(function (array $linea) use ($productos) {
                // Con `??`: la validación acepta que la línea llegue sin `producto_id` —es
                // `nullable`— y leerla a secas reventaba con un 500 en vez de guardar el
                // concepto libre. La pantalla siempre manda la clave; otro cliente, no.
                $producto = ($linea['producto_id'] ?? null)
                    ? $productos->get((int) $linea['producto_id'])
                    : null;
                $cantidad = round((float) ($linea['cantidad'] ?? 0), 6);

                // El precio unitario se guarda congelado, no se lee del producto al cotizar:
                // una cotización de hace tres meses tiene que poder explicar su costo con los
                // precios que había entonces. Se recalcula solo cuando alguien lo pide.
                $precio = array_key_exists('precio_unit', $linea) && $linea['precio_unit'] !== null && $linea['precio_unit'] !== ''
                    ? (float) $linea['precio_unit']
                    : (float) ($producto->precio_costo ?? 0);

                return [
                    'producto_id'   => $producto?->id,
                    'nombre'        => $producto?->nombre_completo
                        ?? $producto?->nombre
                        ?? trim((string) ($linea['concepto'] ?? '')) ?: '(sin nombre)',
                    'unidad'        => $producto?->unidad_medida ?? ($linea['unidad'] ?? 'unidad'),
                    'cantidad'      => $cantidad,
                    // En un ensamble directo la cantidad real es la misma: no hay fórmula que
                    // distinga «lo que se cobra» de «lo que se corta».
                    'cantidad_real' => $cantidad,
                    'precio_unit'   => $precio,
                    'subtotal'      => round($cantidad * $precio, 2),
                    'subtotal_real' => round($cantidad * $precio, 2),
                    'tiene_formula_real' => false,
                    'incluir_precio' => true,
                    // El cliente no ve la receta: el ensamble se cotiza como un ítem con su
                    // resumen técnico. Igual que los ensambles con plantilla.
                    'visible_cliente' => false,
                    'visible_op'      => true,
                    'seccion'         => $linea['seccion'] ?? null,
                    // Marca lo que no es inventario, para que el despacho no intente
                    // descontar mano de obra de una bodega.
                    'es_concepto'     => $producto === null,
                ];
            })
            // Una línea sin nada útil no se guarda: son las filas que quedan vacías cuando
            // alguien agrega tres y llena dos.
            ->filter(fn ($c) => $c['cantidad'] > 0 && ($c['producto_id'] || $c['nombre'] !== '(sin nombre)'))
            ->values()
            ->all();
    }

    /** El costo del ensamble: la suma de las líneas. */
    public function costo(array $componentes): float
    {
        return round(collect($componentes)->sum(fn ($c) => (float) ($c['subtotal'] ?? 0)), 2);
    }

    /**
     * Vuelve a leer el costo de cada producto y recalcula.
     *
     * Es lo que se llama desde «Recalcular»: los precios congelados se actualizan al costo de
     * hoy. Los conceptos libres se quedan como están — nadie los puede releer de ninguna
     * parte.
     *
     * @param  array<int, array<string, mixed>>  $componentes
     * @return array<int, array<string, mixed>>
     */
    public function recalcular(array $componentes): array
    {
        $ids = collect($componentes)->pluck('producto_id')->filter()->unique()->all();

        $productos = $ids !== []
            ? Producto::whereIn('id', $ids)->get()->keyBy('id')
            : collect();

        return collect($componentes)->map(function (array $c) use ($productos) {
            if (! ($c['producto_id'] ?? null)) {
                return $c;
            }

            $producto = $productos->get((int) $c['producto_id']);

            if (! $producto) {
                return $c;
            }

            $precio   = (float) $producto->precio_costo;
            $cantidad = (float) ($c['cantidad'] ?? 0);

            return array_merge($c, [
                'precio_unit'   => $precio,
                'subtotal'      => round($cantidad * $precio, 2),
                'subtotal_real' => round($cantidad * $precio, 2),
            ]);
        })->all();
    }
}
