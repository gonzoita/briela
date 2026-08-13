<?php

namespace BrielaConnect\Integrations;

use BrielaConnect\Catalogo;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Módulo de WooCommerce: solo se carga si la tienda está instalada.
 *
 * Traduce una ficha del ERP a un producto de la tienda. Dos decisiones que se ven en el
 * sitio y conviene tener presentes:
 *
 * - **El precio y las existencias los manda Briela**, en cada sincronización. Es lo que
 *   evita que la tienda venda a un precio viejo o algo que ya no hay.
 * - **Un ensamble no se puede comprar con un clic.** Su precio es «desde» y el final sale
 *   de las medidas, así que se publica visible pero no comprable, y el botón lleva a pedir
 *   cotización. Publicar un «agregar al carrito» sobre un precio que va a cambiar es la
 *   forma de tener una discusión con el cliente después de que pagó.
 */
class WooCommerce
{
    public static function registrar(): void
    {
        if (! Catalogo::hay_tienda()) {
            return;
        }

        add_filter('woocommerce_get_price_html', [self::class, 'precio_html'], 10, 2);
        add_filter('woocommerce_is_purchasable', [self::class, 'se_puede_comprar'], 10, 2);
        add_filter('woocommerce_loop_add_to_cart_link', [self::class, 'boton_de_lista'], 10, 2);
    }

    /**
     * Prepara un producto recién creado: tipo simple, referencia, precio y existencias.
     *
     * La referencia puede chocar con una que ya exista en la tienda —cargada a mano antes
     * de conectar Briela—. En ese caso se deja el producto sin referencia en vez de no
     * crearlo: la ficha con su precio vale más que el código, y el choque se ve en la
     * tienda para arreglarlo.
     */
    public static function preparar_producto_nuevo(int $id_post, array $unidad): void
    {
        wp_set_object_terms($id_post, 'simple', 'product_type', false);

        $producto = wc_get_product($id_post);

        if (! $producto) {
            return;
        }

        try {
            $producto->set_sku((string) ($unidad['referencia'] ?? ''));
        } catch (\Throwable $e) {
            // Referencia repetida: se sigue sin ella.
        }

        $producto->set_catalog_visibility('visible');
        $producto->save();

        self::actualizar_precio_y_stock($id_post, $unidad);
    }

    /**
     * Lo único que se reescribe en cada pasada.
     *
     * Sin precio no se pone cero: cero es un regalo. Se deja vacío y el sitio muestra
     * «Cotizar» (ver `precio_html`).
     */
    public static function actualizar_precio_y_stock(int $id_post, array $unidad): void
    {
        $producto = wc_get_product($id_post);

        if (! $producto) {
            return;
        }

        $precio = $unidad['precio'] ?? null;

        if ($precio !== null && (float) $precio > 0) {
            $producto->set_regular_price((string) $precio);
        } else {
            $producto->set_regular_price('');
        }

        if (! empty($unidad['gestiona_stock'])) {
            $producto->set_manage_stock(true);
            $producto->set_stock_quantity((float) ($unidad['stock'] ?? 0));
            $producto->set_stock_status(((float) ($unidad['stock'] ?? 0)) > 0 ? 'instock' : 'outofstock');
        } else {
            // Un ensamble se fabrica por pedido: no tiene existencias que contar, y
            // marcarlo «agotado» porque el ERP no lleva su stock sería mentir.
            $producto->set_manage_stock(false);
            $producto->set_stock_status('instock');
        }

        $producto->save();
    }

    /** «Desde $X» para lo que se cotiza por medidas; «Cotizar» cuando no hay precio. */
    public static function precio_html(string $html, $producto): string
    {
        if (! is_object($producto) || ! method_exists($producto, 'get_id')) {
            return $html;
        }

        if (get_post_meta($producto->get_id(), '_briela_precio_desde', true) !== '1') {
            return $html;
        }

        if ($producto->get_regular_price() === '' || $producto->get_regular_price() === null) {
            return '<span class="briela-cotizar">Cotizar</span>';
        }

        return '<span class="briela-desde">Desde </span>' . $html;
    }

    /** Lo que se cotiza por medidas no se compra con un clic. */
    public static function se_puede_comprar(bool $comprable, $producto): bool
    {
        if (! is_object($producto) || ! method_exists($producto, 'get_id')) {
            return $comprable;
        }

        return get_post_meta($producto->get_id(), '_briela_precio_desde', true) === '1'
            ? false
            : $comprable;
    }

    /** En el listado de la tienda, el botón lleva a la ficha en vez de al carrito. */
    public static function boton_de_lista(string $html, $producto): string
    {
        if (! is_object($producto) || ! method_exists($producto, 'get_id')) {
            return $html;
        }

        if (get_post_meta($producto->get_id(), '_briela_precio_desde', true) !== '1') {
            return $html;
        }

        return sprintf(
            '<a href="%s" class="button briela-pedir-cotizacion">Pedir cotización</a>',
            esc_url(get_permalink($producto->get_id()))
        );
    }
}
