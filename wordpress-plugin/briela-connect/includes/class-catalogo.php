<?php

namespace BrielaConnect;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Trae del ERP el catálogo publicado y lo refleja en este sitio.
 *
 * **Quién manda.** Briela manda el precio y las existencias: cada sincronización los
 * reescribe. El título, el texto, las fotos y el SEO se escriben la primera vez y después
 * son del sitio — quien redacta la web puede mejorar una descripción sin que la próxima
 * pasada le borre el trabajo.
 *
 * **Una lista completa, no un diario de cambios.** El ERP devuelve todo lo publicado. Con
 * eventos incrementales, un aviso perdido —una caída de red, el sitio en mantenimiento—
 * deja el sitio mintiendo para siempre y nadie se entera. Comparar contra la lista
 * completa se autocorrige en la siguiente pasada.
 *
 * **Qué se publica.** Una ficha por unidad que de verdad se vende: un producto simple es
 * una ficha; un producto con variantes son sus variantes, porque lo que alguien compra es
 * «lámina 40 mm», no «lámina». Un ensamble es una ficha más, con su precio marcado como
 * «desde»: el final depende de las medidas.
 *
 * **Lo que se deja de publicar no se borra.** Pasa a borrador. Borrar la entrada tira a la
 * basura el posicionamiento que ganó y cualquier texto que el sitio le haya escrito, y
 * despublicar por error es demasiado fácil.
 */
class Catalogo
{
    /** Nombre del evento de cron. */
    private const CRON = 'briela_connect_sincronizar_catalogo';

    /** Tipo de contenido propio, para sitios sin tienda. */
    public const CPT = 'briela_producto';

    private const META_TIPO   = '_briela_tipo';
    private const META_ID     = '_briela_id';
    private const META_DESDE  = '_briela_precio_desde';
    private const META_FICHA  = '_briela_url_ficha';

    // El precio y las existencias también se guardan como meta propia, no solo en
    // WooCommerce: un sitio sin tienda necesita mostrarlos igual, y así el día que existan
    // los Dynamic Tags de Elementor tienen de dónde leer sin depender de la tienda.
    private const META_PRECIO = '_briela_precio';
    private const META_STOCK  = '_briela_stock';

    private const OPT_ULTIMA  = 'briela_connect_ultima_sincronizacion';
    private const OPT_RESUMEN = 'briela_connect_ultimo_resumen';

    public static function registrar(): void
    {
        add_action('init', [self::class, 'registrar_tipo_contenido']);
        add_action(self::CRON, [self::class, 'sincronizar']);
        add_action('rest_api_init', [self::class, 'registrar_ruta']);
        add_action('admin_post_briela_connect_sincronizar', [self::class, 'sincronizar_desde_admin']);
        add_shortcode('briela_producto', [self::class, 'shortcode']);

        // Cada hora es suficiente para un catálogo: los cambios de verdad llegan por el
        // aviso del ERP, y esto es la red de seguridad para cuando ese aviso no llega.
        if (! wp_next_scheduled(self::CRON)) {
            wp_schedule_event(time() + 60, 'hourly', self::CRON);
        }
    }

    public static function desactivar(): void
    {
        wp_clear_scheduled_hook(self::CRON);
    }

    /**
     * El tipo de contenido para sitios sin WooCommerce.
     *
     * Se registra siempre, incluso con la tienda instalada: si el cliente desactiva
     * WooCommerce, las fichas que ya existían siguen abriéndose en vez de dar 404.
     */
    public static function registrar_tipo_contenido(): void
    {
        register_post_type(self::CPT, [
            'label'         => 'Catálogo Briela',
            'public'        => true,
            'has_archive'   => true,
            'show_in_menu'  => true,
            'menu_icon'     => 'dashicons-products',
            'supports'      => ['title', 'editor', 'excerpt', 'thumbnail'],
            'rewrite'       => ['slug' => 'catalogo'],
            'show_in_rest'  => true,
        ]);
    }

    /**
     * La ruta que el ERP llama cuando alguien publica o retira algo.
     *
     * Mismo token que usa el plugin para hablarle al ERP: si los dos lados comparten el
     * secreto, sirve en las dos direcciones y no hay una credencial más que administrar.
     */
    public static function registrar_ruta(): void
    {
        register_rest_route('briela/v1', '/sincronizar', [
            'methods'             => 'POST',
            'callback'            => function () {
                $resultado = self::sincronizar();

                return rest_ensure_response([
                    'ok'      => $resultado['ok'],
                    'resumen' => $resultado['resumen'],
                ]);
            },
            'permission_callback' => [self::class, 'token_valido'],
        ]);
    }

    public static function token_valido(\WP_REST_Request $request): bool
    {
        $recibido = trim(str_ireplace('Bearer', '', (string) $request->get_header('authorization')));
        $propio   = ApiClient::token();

        return $propio !== '' && hash_equals($propio, $recibido);
    }

    public static function sincronizar_desde_admin(): void
    {
        if (! current_user_can('manage_options') || ! check_admin_referer('briela_connect_sincronizar')) {
            wp_die('No autorizado.');
        }

        self::sincronizar();

        wp_safe_redirect(admin_url('options-general.php?page=briela-connect&sincronizado=1'));
        exit;
    }

    /**
     * El trabajo: pedir el catálogo y dejar el sitio igual a lo que dice el ERP.
     *
     * @return array{ok: bool, resumen: string}
     */
    public static function sincronizar(): array
    {
        $respuesta = ApiClient::get('catalogo');

        if (! $respuesta['ok']) {
            update_option(self::OPT_RESUMEN, 'Error: ' . $respuesta['mensaje']);

            return ['ok' => false, 'resumen' => $respuesta['mensaje']];
        }

        $items = $respuesta['datos']['catalogo'] ?? [];

        $creadas       = 0;
        $actualizadas  = 0;
        $vistas        = [];

        foreach ($items as $item) {
            foreach (self::unidades_vendibles($item) as $unidad) {
                $resultado = self::guardar_ficha($unidad);

                if ($resultado === 'creada') {
                    $creadas++;
                } elseif ($resultado === 'actualizada') {
                    $actualizadas++;
                }

                $vistas[] = $unidad['tipo'] . ':' . $unidad['id'];
            }
        }

        $retiradas = self::pasar_a_borrador_las_que_ya_no_estan($vistas);

        $resumen = sprintf(
            '%d ficha(s) nueva(s), %d actualizada(s), %d pasada(s) a borrador.',
            $creadas,
            $actualizadas,
            $retiradas
        );

        update_option(self::OPT_ULTIMA, current_time('mysql'));
        update_option(self::OPT_RESUMEN, $resumen);

        return ['ok' => true, 'resumen' => $resumen];
    }

    /**
     * De un ítem del ERP a las fichas que hay que crear en el sitio.
     *
     * Un producto con variantes no se publica él mismo: no tiene existencias propias ni
     * precio propio, y lo que un cliente compra es una variante concreta. Se publica una
     * ficha por variante, con el nombre completo que ya trae el ERP.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function unidades_vendibles(array $item): array
    {
        $variantes = $item['variantes'] ?? [];

        if (empty($variantes)) {
            return [$item];
        }

        $unidades = [];

        foreach ($variantes as $variante) {
            $unidades[] = array_merge($item, [
                'tipo'       => 'variante',
                'id'         => $variante['id'],
                'nombre'     => $variante['nombre'],
                'referencia' => $variante['referencia'] ?: ($item['referencia'] . '-' . $variante['id']),
                'precio'     => $variante['precio'],
                'stock'      => $variante['stock'],
                'variantes'  => [],
            ]);
        }

        return $unidades;
    }

    /**
     * Crea o actualiza una ficha.
     *
     * @return 'creada'|'actualizada'|'sin_cambios'
     */
    private static function guardar_ficha(array $unidad): string
    {
        $existente = self::buscar_ficha($unidad['tipo'], (int) $unidad['id']);
        $con_tienda = self::hay_tienda();

        if ($existente) {
            // Lo único que Briela reescribe siempre: precio, existencias y referencia. El
            // texto y las fotos quedan como los tenga el sitio.
            if ($con_tienda) {
                Integrations\WooCommerce::actualizar_precio_y_stock($existente, $unidad);
            }

            self::guardar_precio_y_stock($existente, $unidad);
            update_post_meta($existente, self::META_DESDE, ! empty($unidad['precio_es_desde']) ? '1' : '0');
            update_post_meta($existente, self::META_FICHA, (string) ($unidad['url_ficha'] ?? ''));

            // Volver a publicar lo que estaba en borrador: si se retiró y se volvió a
            // marcar en el ERP, el sitio tiene que reflejarlo sin intervención.
            if (get_post_status($existente) !== 'publish') {
                wp_update_post(['ID' => $existente, 'post_status' => 'publish']);
            }

            return 'actualizada';
        }

        $datos = [
            'post_title'   => $unidad['nombre'],
            'post_content' => (string) ($unidad['descripcion_larga'] ?? ''),
            'post_excerpt' => (string) ($unidad['descripcion_corta'] ?? ''),
            'post_status'  => 'publish',
            'post_type'    => $con_tienda ? 'product' : self::CPT,
        ];

        $id_post = wp_insert_post($datos, true);

        if (is_wp_error($id_post) || ! $id_post) {
            return 'sin_cambios';
        }

        update_post_meta($id_post, self::META_TIPO, $unidad['tipo']);
        update_post_meta($id_post, self::META_ID, (int) $unidad['id']);
        update_post_meta($id_post, self::META_DESDE, ! empty($unidad['precio_es_desde']) ? '1' : '0');
        update_post_meta($id_post, self::META_FICHA, (string) ($unidad['url_ficha'] ?? ''));
        self::guardar_precio_y_stock($id_post, $unidad);

        if ($con_tienda) {
            Integrations\WooCommerce::preparar_producto_nuevo($id_post, $unidad);
        }

        self::traer_imagenes($id_post, $unidad['imagenes'] ?? []);
        self::asignar_categoria($id_post, $unidad['categoria'] ?? null, $con_tienda);

        return 'creada';
    }

    /**
     * El precio y las existencias como meta propia del plugin.
     *
     * Cero no se guarda como precio: un ítem sin precio cargado en el ERP llega en nulo y
     * la ficha muestra «Cotizar». Publicar «$0» parece un regalo o un error del sitio.
     */
    private static function guardar_precio_y_stock(int $id_post, array $unidad): void
    {
        $precio = $unidad['precio'] ?? null;

        update_post_meta($id_post, self::META_PRECIO, $precio !== null && (float) $precio > 0 ? (float) $precio : '');
        update_post_meta($id_post, self::META_STOCK, empty($unidad['gestiona_stock']) ? '' : (float) ($unidad['stock'] ?? 0));
    }

    /**
     * Busca la ficha de un ítem del ERP.
     *
     * El par tipo + id es la identidad: el nombre no sirve —cambia— y la referencia
     * tampoco, porque el cliente la puede editar en el ERP.
     */
    private static function buscar_ficha(string $tipo, int $id): ?int
    {
        $encontrados = get_posts([
            // Siempre los dos tipos: si el cliente desactiva WooCommerce, las fichas que
            // ya existían como producto siguen encontrándose y no se duplican como ficha.
            'post_type'        => ['product', self::CPT],
            'post_status'      => ['publish', 'draft', 'pending', 'private'],
            'numberposts'      => 1,
            'fields'           => 'ids',
            'suppress_filters' => false,
            'meta_query'       => [
                ['key' => self::META_TIPO, 'value' => $tipo],
                ['key' => self::META_ID,   'value' => $id, 'type' => 'NUMERIC'],
            ],
        ]);

        return $encontrados ? (int) $encontrados[0] : null;
    }

    /**
     * Lo que el ERP ya no publica pasa a borrador.
     *
     * @param  array<int, string>  $vistas  Claves «tipo:id» que sí vinieron en esta pasada.
     */
    private static function pasar_a_borrador_las_que_ya_no_estan(array $vistas): int
    {
        $nuestras = get_posts([
            'post_type'   => ['product', self::CPT],
            'post_status' => ['publish'],
            'numberposts' => -1,
            'fields'      => 'ids',
            'meta_query'  => [['key' => self::META_ID, 'compare' => 'EXISTS']],
        ]);

        $retiradas = 0;

        foreach ($nuestras as $id_post) {
            $clave = get_post_meta($id_post, self::META_TIPO, true) . ':' . get_post_meta($id_post, self::META_ID, true);

            if (! in_array($clave, $vistas, true)) {
                wp_update_post(['ID' => $id_post, 'post_status' => 'draft']);
                $retiradas++;
            }
        }

        return $retiradas;
    }

    /**
     * Baja las imágenes del ERP a la biblioteca de medios, solo al crear la ficha.
     *
     * Se hace una vez porque las fotos pesan y porque el sitio puede querer las suyas,
     * mejor recortadas o con marca de agua. Si falla —el ERP no es accesible desde el
     * sitio, permisos de la carpeta de subidas— la ficha queda creada sin foto: es un
     * problema estético, no una razón para no publicar el producto.
     */
    private static function traer_imagenes(int $id_post, array $urls): void
    {
        if (empty($urls)) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $adjuntos = [];

        foreach (array_slice($urls, 0, 6) as $url) {
            $id_adjunto = media_sideload_image($url, $id_post, null, 'id');

            if (! is_wp_error($id_adjunto)) {
                $adjuntos[] = (int) $id_adjunto;
            }
        }

        if (! $adjuntos) {
            return;
        }

        set_post_thumbnail($id_post, $adjuntos[0]);

        if (self::hay_tienda() && count($adjuntos) > 1) {
            update_post_meta($id_post, '_product_image_gallery', implode(',', array_slice($adjuntos, 1)));
        }
    }

    private static function asignar_categoria(int $id_post, ?string $categoria, bool $con_tienda): void
    {
        if (! $categoria) {
            return;
        }

        $taxonomia = $con_tienda ? 'product_cat' : '';

        if ($taxonomia === '' || ! taxonomy_exists($taxonomia)) {
            return;
        }

        wp_set_object_terms($id_post, $categoria, $taxonomia, false);
    }

    public static function hay_tienda(): bool
    {
        return class_exists('WooCommerce');
    }

    public static function ultima_sincronizacion(): string
    {
        return (string) get_option(self::OPT_ULTIMA, '');
    }

    public static function ultimo_resumen(): string
    {
        return (string) get_option(self::OPT_RESUMEN, '');
    }

    /**
     * `[briela_producto id="12" tipo="ensamble"]` — para sitios sin tienda que armaron su
     * página a mano y solo quieren incrustar una ficha.
     */
    public static function shortcode(array $atributos): string
    {
        $atributos = shortcode_atts(['id' => 0, 'tipo' => 'producto'], $atributos);
        $id_post   = self::buscar_ficha((string) $atributos['tipo'], (int) $atributos['id']);

        if (! $id_post) {
            return '';
        }

        $desde  = get_post_meta($id_post, self::META_DESDE, true) === '1';
        $precio = get_post_meta($id_post, self::META_PRECIO, true);
        $imagen = get_the_post_thumbnail($id_post, 'medium');

        $html  = '<div class="briela-ficha">';
        $html .= $imagen;
        $html .= '<h3>' . esc_html(get_the_title($id_post)) . '</h3>';
        $html .= '<div>' . wp_kses_post(get_post_field('post_excerpt', $id_post)) . '</div>';

        if ($precio === '' || $precio === null) {
            $html .= '<p class="briela-cotizar">Cotizar</p>';
        } else {
            $html .= '<p class="briela-precio">'
                . ($desde ? 'Desde ' : '')
                . esc_html(number_format((float) $precio, 0, ',', '.'))
                . '</p>';
        }

        if ($desde) {
            $html .= '<p class="briela-precio-desde">El precio final depende de las medidas.</p>';
        }

        $html .= '<a href="' . esc_url(get_permalink($id_post)) . '">Ver ficha</a>';
        $html .= '</div>';

        return $html;
    }
}
