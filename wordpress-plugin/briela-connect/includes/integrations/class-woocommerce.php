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
 * - **Un producto con variantes es un producto variable**: una ficha, y el cliente elige la
 *   variante en su selector. El atributo se llama como lo nombró el ERP («Medida»).
 * - **Marca, origen y características son atributos visibles** (la pestaña «Información
 *   adicional» de la tienda), y los planos y fichas técnicas, una pestaña propia.
 * - **Sin precio se cotiza.** Un producto con el precio oculto en el ERP llega sin cifra: la
 *   tienda muestra «Cotizar» y el botón lleva a la ficha, nunca «$0».
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
        add_filter('woocommerce_product_tabs', [self::class, 'pestana_documentos']);
    }

    /** Las claves de los atributos que puso Briela, para no tocar los que el sitio agregó a mano. */
    private const META_ATRIBUTOS = '_briela_atributos';

    /**
     * Deja el producto igual a lo que dice el ERP: tipo, atributos, precio y existencias o
     * variaciones. Corre en cada pasada.
     */
    public static function sincronizar_producto(int $id_post, array $unidad): void
    {
        $variantes = is_array($unidad['variantes'] ?? null) ? $unidad['variantes'] : [];
        $producto  = self::con_tipo($id_post, $variantes ? 'variable' : 'simple');

        if (! $producto) {
            return;
        }

        self::guardar_atributos($producto, $unidad, self::etiquetas_de_variantes($variantes));

        if ($variantes) {
            self::guardar_variaciones($id_post, $unidad, self::etiquetas_de_variantes($variantes));
        } else {
            // Un producto que dejó de tener variantes: las que quedaron se apagan, no se borran.
            self::apagar_variaciones($id_post, []);
            self::actualizar_precio_y_stock($id_post, $unidad);
        }
    }

    /**
     * El producto con la clase del tipo pedido, cambiándolo si hace falta: un producto simple
     * al que en el ERP le crearon variantes pasa a variable, y al revés.
     *
     * @return \WC_Product|null
     */
    private static function con_tipo(int $id_post, string $tipo)
    {
        $actual = wc_get_product($id_post);

        if (! $actual) {
            return null;
        }

        if ($actual->get_type() === $tipo) {
            return $actual;
        }

        wp_set_object_terms($id_post, $tipo, 'product_type', false);

        if (class_exists('WC_Cache_Helper')) {
            \WC_Cache_Helper::invalidate_cache_group('product_' . $id_post);
        }

        $producto = $tipo === 'variable' ? new \WC_Product_Variable($id_post) : new \WC_Product_Simple($id_post);
        $producto->save();

        return $producto;
    }

    /**
     * Lo que el cliente ve en el selector por cada variante: su valor («45 mm»). Si falta o se
     * repite, se completa con la referencia: dos opciones iguales no se pueden elegir.
     *
     * @return array<int, string>  id de la variante en el ERP => etiqueta
     */
    private static function etiquetas_de_variantes(array $variantes): array
    {
        $etiquetas = [];

        foreach ($variantes as $v) {
            // «|» separa las opciones de un atributo en WooCommerce: dentro de un valor lo partiría.
            $etiqueta = str_replace('|', '/', trim((string) ($v['valor'] ?? '')) ?: trim((string) ($v['nombre'] ?? '')));

            if ($etiqueta === '' || in_array($etiqueta, $etiquetas, true)) {
                $etiqueta = trim($etiqueta . ' ' . ($v['referencia'] ?? $v['id']));
            }

            $etiquetas[(int) $v['id']] = $etiqueta;
        }

        return $etiquetas;
    }

    private static function nombre_atributo_variante(array $unidad): string
    {
        return trim((string) ($unidad['atributo_variante'] ?? '')) ?: 'Opción';
    }

    /**
     * Marca, origen y características como atributos visibles, más el atributo con el que se
     * elige la variante.
     *
     * Los atributos que el sitio agregó a mano se respetan: solo se reemplazan los que puso
     * Briela la vez anterior (`_briela_atributos`).
     *
     * @param  array<int, string>  $etiquetas
     */
    private static function guardar_atributos(\WC_Product $producto, array $unidad, array $etiquetas): void
    {
        $previos = get_post_meta($producto->get_id(), self::META_ATRIBUTOS, true);
        $previos = is_array($previos) ? $previos : [];

        $atributos = [];
        foreach ($producto->get_attributes() as $clave => $atributo) {
            if (! in_array($clave, $previos, true)) {
                $atributos[$clave] = $atributo;
            }
        }

        $nuestros = [];
        $agregar  = function (string $nombre, array $opciones, bool $variacion) use (&$nuestros) {
            $opciones = array_values(array_filter(array_map(function ($o) {
                return str_replace('|', '/', trim((string) $o));
            }, $opciones), 'strlen'));

            if ($nombre === '' || ! $opciones) {
                return;
            }

            $atributo = new \WC_Product_Attribute();
            $atributo->set_id(0);
            $atributo->set_name($nombre);
            $atributo->set_options($opciones);
            $atributo->set_visible(true);
            $atributo->set_variation($variacion);
            $atributo->set_position(count($nuestros));

            // Una característica con el nombre de otra ya puesta (la variante, «Marca»…) se descarta:
            // si la pisara, la variación dejaría de coincidir con su atributo y no se podría elegir.
            $clave = sanitize_title($nombre);
            if (isset($nuestros[$clave])) {
                return;
            }

            $nuestros[$clave] = $atributo;
        };

        if ($etiquetas) {
            $agregar(self::nombre_atributo_variante($unidad), array_values($etiquetas), true);
        }
        $agregar('Marca', [$unidad['marca'] ?? ''], false);
        $agregar('Origen', [$unidad['origen'] ?? ''], false);
        foreach (Catalogo::caracteristicas_limpias($unidad['caracteristicas'] ?? []) as $c) {
            $agregar($c['nombre'], [$c['valor']], false);
        }

        $producto->set_attributes($atributos + $nuestros);
        $producto->save();

        update_post_meta($producto->get_id(), self::META_ATRIBUTOS, array_keys($nuestros));
    }

    /**
     * Una variación por variante del ERP, con su referencia, precio y existencias.
     *
     * La identidad es el id de la variante en el ERP, no la etiqueta: si cambian «45mm» por
     * «45 mm», se actualiza la misma variación y no nace otra.
     *
     * @param  array<int, string>  $etiquetas
     */
    private static function guardar_variaciones(int $id_padre, array $unidad, array $etiquetas): void
    {
        $clave  = sanitize_title(self::nombre_atributo_variante($unidad));
        $vistas = [];

        foreach ($unidad['variantes'] as $v) {
            $id_erp       = (int) $v['id'];
            $id_variacion = self::buscar_variacion($id_padre, $id_erp);
            $variacion    = $id_variacion ? new \WC_Product_Variation($id_variacion) : new \WC_Product_Variation();

            if (! $id_variacion) {
                $variacion->set_parent_id($id_padre);
            }

            // La referencia se intenta en cada pasada mientras la variación no la tenga: las fichas
            // sueltas de la versión anterior pasan a borrador con la misma referencia, y WooCommerce
            // la da por repetida hasta que se les vacía (ver `Catalogo::pasar_a_borrador_las_que_ya_no_estan`).
            if (! $variacion->get_sku('edit') && ! empty($v['referencia'])) {
                try {
                    $variacion->set_sku((string) $v['referencia']);
                } catch (\Throwable $e) {
                    // Referencia repetida en la tienda: queda sin ella y se vuelve a intentar.
                }
            }

            $variacion->set_attributes([$clave => $etiquetas[$id_erp]]);
            $variacion->set_status('publish');
            self::aplicar_precio_y_stock($variacion, $v);
            $id_variacion = $variacion->save();

            update_post_meta($id_variacion, Catalogo::META_TIPO, 'variante');
            update_post_meta($id_variacion, Catalogo::META_ID, $id_erp);
            $vistas[] = (int) $id_variacion;
        }

        self::apagar_variaciones($id_padre, $vistas);

        \WC_Product_Variable::sync($id_padre);
        wc_delete_product_transients($id_padre);
    }

    private static function buscar_variacion(int $id_padre, int $id_erp): ?int
    {
        $encontradas = get_posts([
            'post_type'        => 'product_variation',
            'post_parent'      => $id_padre,
            'post_status'      => ['publish', 'private'],
            'numberposts'      => 1,
            'fields'           => 'ids',
            'suppress_filters' => false,
            'meta_query'       => [
                ['key' => Catalogo::META_TIPO, 'value' => 'variante'],
                ['key' => Catalogo::META_ID, 'value' => $id_erp, 'type' => 'NUMERIC'],
            ],
        ]);

        return $encontradas ? (int) $encontradas[0] : null;
    }

    /**
     * Las variaciones de Briela que ya no vienen se apagan (privadas), no se borran: un pedido
     * viejo de la tienda las nombra, y si vuelven en el ERP se encienden con su historia.
     *
     * @param  array<int, int>  $vistas
     */
    private static function apagar_variaciones(int $id_padre, array $vistas): void
    {
        $nuestras = get_posts([
            'post_type'   => 'product_variation',
            'post_parent' => $id_padre,
            'post_status' => ['publish'],
            'numberposts' => -1,
            'fields'      => 'ids',
            'meta_query'  => [['key' => Catalogo::META_ID, 'compare' => 'EXISTS']],
        ]);

        foreach ($nuestras as $id_variacion) {
            if (! in_array((int) $id_variacion, $vistas, true)) {
                $variacion = wc_get_product($id_variacion);

                if ($variacion) {
                    $variacion->set_status('private');
                    $variacion->save();
                }
            }
        }
    }

    /** La pestaña «Planos y fichas técnicas», solo si el producto tiene alguno. */
    public static function pestana_documentos($pestanas)
    {
        global $product;

        if (! is_array($pestanas) || ! is_object($product) || ! method_exists($product, 'get_id')) {
            return $pestanas;
        }

        $documentos = Catalogo::documentos((int) $product->get_id());

        if (! $documentos) {
            return $pestanas;
        }

        $pestanas['briela_documentos'] = [
            'title'    => 'Planos y fichas técnicas',
            'priority' => 25,
            'callback' => function () use ($documentos) {
                echo Catalogo::html_documentos($documentos); // Ya escapado al armarlo.
            },
        ];

        return $pestanas;
    }

    /**
     * Prepara un producto recién creado: tipo (simple, o variable si trae variantes),
     * referencia y visibilidad. El precio, las existencias y las variaciones los pone
     * `sincronizar_producto`, que corre justo después.
     *
     * La referencia puede chocar con una que ya exista en la tienda —cargada a mano antes
     * de conectar Briela—. En ese caso se deja el producto sin referencia en vez de no
     * crearlo: la ficha con su precio vale más que el código, y el choque se ve en la
     * tienda para arreglarlo.
     */
    public static function preparar_producto_nuevo(int $id_post, array $unidad): void
    {
        wp_set_object_terms($id_post, empty($unidad['variantes']) ? 'simple' : 'variable', 'product_type', false);

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

        self::aplicar_precio_y_stock($producto, $unidad);
        $producto->save();
    }

    /**
     * Precio y existencias sobre un producto o una variación, sin guardar.
     *
     * @param  \WC_Product  $producto
     */
    private static function aplicar_precio_y_stock($producto, array $datos): void
    {
        $precio = $datos['precio'] ?? null;

        if ($precio !== null && (float) $precio > 0) {
            $producto->set_regular_price((string) $precio);
        } else {
            $producto->set_regular_price('');
        }

        if (! empty($datos['gestiona_stock'])) {
            $producto->set_manage_stock(true);
            $producto->set_stock_quantity((float) ($datos['stock'] ?? 0));
            $producto->set_stock_status(((float) ($datos['stock'] ?? 0)) > 0 ? 'instock' : 'outofstock');
        } else {
            // Un ensamble se fabrica por pedido: no tiene existencias que contar, y
            // marcarlo «agotado» porque el ERP no lleva su stock sería mentir.
            $producto->set_manage_stock(false);
            $producto->set_stock_status('instock');
        }
    }

    /** ¿Lo publicó Briela? Las reglas de «Cotizar» no tocan los productos propios de la tienda. */
    private static function es_de_briela($producto): bool
    {
        $id = $producto->get_parent_id() ?: $producto->get_id();

        return get_post_meta($id, Catalogo::META_ID, true) !== '';
    }

    /** ¿Hay que cotizarlo? Lo que va por medidas, y lo de Briela que llega sin precio. */
    private static function se_cotiza($producto): bool
    {
        if (get_post_meta($producto->get_id(), '_briela_precio_desde', true) === '1') {
            return true;
        }

        return self::es_de_briela($producto) && ($producto->get_price() === '' || $producto->get_price() === null);
    }

    /** «Desde $X» para lo que se cotiza por medidas; «Cotizar» cuando no hay precio. */
    public static function precio_html(string $html, $producto): string
    {
        if (! is_object($producto) || ! method_exists($producto, 'get_id')) {
            return $html;
        }

        if (! self::se_cotiza($producto)) {
            return $html;
        }

        if ($producto->get_price() === '' || $producto->get_price() === null) {
            return '<span class="briela-cotizar">Cotizar</span>';
        }

        return '<span class="briela-desde">Desde </span>' . $html;
    }

    /** Lo que se cotiza por medidas no se compra con un clic (sin precio, WooCommerce ya no deja). */
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

        if (! self::se_cotiza($producto)) {
            return $html;
        }

        return sprintf(
            '<a href="%s" class="button briela-pedir-cotizacion">Pedir cotización</a>',
            esc_url(get_permalink($producto->get_id()))
        );
    }
}
