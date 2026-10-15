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
 * **Qué se publica.** Una ficha por producto. Un producto con variantes es UNA ficha y el
 * cliente elige la variante dentro de ella —en WooCommerce, un producto variable con sus
 * variaciones—: veinte fichas casi iguales, una por medida, llenan la tienda de repetidos y
 * reparten el posicionamiento entre todas. Un ensamble es una ficha más, con su precio
 * marcado como «desde»: el final depende de las medidas.
 *
 * **Lo que es del ERP se reescribe en cada pasada**: precio, existencias, variantes, marca,
 * origen, características y documentos (planos, fichas técnicas). Son datos del producto,
 * no redacción del sitio.
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

    public const META_TIPO    = '_briela_tipo';
    public const META_ID      = '_briela_id';
    private const META_DESDE  = '_briela_precio_desde';
    private const META_FICHA  = '_briela_url_ficha';

    // El precio y las existencias también se guardan como meta propia, no solo en
    // WooCommerce: un sitio sin tienda necesita mostrarlos igual, y así el día que existan
    // los Dynamic Tags de Elementor tienen de dónde leer sin depender de la tienda.
    private const META_PRECIO = '_briela_precio';
    private const META_STOCK  = '_briela_stock';

    // Los datos de la ficha que manda el ERP, guardados tal cual para mostrarlos con o sin tienda.
    private const META_MARCA           = '_briela_marca';
    private const META_ORIGEN          = '_briela_origen';
    private const META_CARACTERISTICAS = '_briela_caracteristicas';
    private const META_DOCUMENTOS      = '_briela_documentos';
    private const META_VARIANTES       = '_briela_variantes';
    private const META_ATRIBUTO        = '_briela_atributo_variante';

    private const OPT_ULTIMA  = 'briela_connect_ultima_sincronizacion';
    private const OPT_RESUMEN = 'briela_connect_ultimo_resumen';

    private const OPT_PDF_HUELLA = 'briela_connect_catalogo_pdf_huella';
    private const OPT_PDF_FECHA  = 'briela_connect_catalogo_pdf_fecha';

    /** Cada cuánto se vuelve a pedir el PDF aunque nada haya cambiado: una foto nueva no mueve la huella. */
    private const PDF_VIGENCIA = 7 * DAY_IN_SECONDS;

    public static function registrar(): void
    {
        add_action('init', [self::class, 'registrar_tipo_contenido']);
        add_action(self::CRON, [self::class, 'sincronizar']);
        add_action('rest_api_init', [self::class, 'registrar_ruta']);
        add_action('admin_post_briela_connect_sincronizar', [self::class, 'sincronizar_desde_admin']);
        add_shortcode('briela_producto', [self::class, 'shortcode']);
        add_shortcode('briela_catalogo_pdf', [self::class, 'shortcode_catalogo_pdf']);
        add_filter('the_content', [self::class, 'contenido_de_ficha']);

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
            $resultado = self::guardar_ficha($item);

            if ($resultado === 'creada') {
                $creadas++;
            } elseif ($resultado === 'actualizada') {
                $actualizadas++;
            }

            $vistas[] = $item['tipo'] . ':' . $item['id'];
        }

        // Las fichas sueltas por variante que publicaban las versiones anteriores no vienen en
        // esta lista: pasan solas a borrador y su lugar lo toma la ficha del producto.
        $retiradas = self::pasar_a_borrador_las_que_ya_no_estan($vistas);

        self::actualizar_catalogo_pdf($items);

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
     * Crea o actualiza la ficha de un producto o ensamble.
     *
     * @return 'creada'|'actualizada'|'sin_cambios'
     */
    private static function guardar_ficha(array $unidad): string
    {
        $existente = self::buscar_ficha($unidad['tipo'], (int) $unidad['id']);
        $con_tienda = self::hay_tienda();

        if ($existente) {
            $id_post   = $existente;
            $resultado = 'actualizada';

            // Volver a publicar lo que estaba en borrador: si se retiró y se volvió a
            // marcar en el ERP, el sitio tiene que reflejarlo sin intervención.
            if (get_post_status($existente) !== 'publish') {
                wp_update_post(['ID' => $existente, 'post_status' => 'publish']);
            }
        } else {
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

            $resultado = 'creada';
            update_post_meta($id_post, self::META_TIPO, $unidad['tipo']);
            update_post_meta($id_post, self::META_ID, (int) $unidad['id']);
        }

        // El texto y las fotos solo se escriben al crear (después son del sitio); lo que sigue
        // es del ERP y se reescribe en cada pasada.
        update_post_meta($id_post, self::META_DESDE, ! empty($unidad['precio_es_desde']) ? '1' : '0');
        update_post_meta($id_post, self::META_FICHA, (string) ($unidad['url_ficha'] ?? ''));
        self::guardar_precio_y_stock($id_post, $unidad);
        self::guardar_datos_de_ficha($id_post, $unidad);

        if ($con_tienda) {
            if ($resultado === 'creada') {
                Integrations\WooCommerce::preparar_producto_nuevo($id_post, $unidad);
            }

            Integrations\WooCommerce::sincronizar_producto($id_post, $unidad);
        }

        if ($resultado === 'creada') {
            self::traer_imagenes($id_post, $unidad['imagenes'] ?? []);
            self::asignar_categoria($id_post, $unidad['categoria'] ?? null, $con_tienda);
        }

        return $resultado;
    }

    /**
     * Marca, origen, características, documentos y variantes, como meta propia.
     *
     * Con tienda, WooCommerce los muestra como atributos y una pestaña; sin tienda, la ficha
     * los agrega debajo del texto (`contenido_de_ficha`). Guardarlos aquí sirve a los dos.
     */
    private static function guardar_datos_de_ficha(int $id_post, array $unidad): void
    {
        update_post_meta($id_post, self::META_MARCA, (string) ($unidad['marca'] ?? ''));
        update_post_meta($id_post, self::META_ORIGEN, (string) ($unidad['origen'] ?? ''));
        update_post_meta($id_post, self::META_CARACTERISTICAS, self::caracteristicas_limpias($unidad['caracteristicas'] ?? []));
        update_post_meta($id_post, self::META_DOCUMENTOS, self::documentos_limpios($unidad['documentos'] ?? []));
        update_post_meta($id_post, self::META_ATRIBUTO, (string) ($unidad['atributo_variante'] ?? ''));
        update_post_meta($id_post, self::META_VARIANTES, array_map(function ($v) {
            return [
                'id'         => (int) ($v['id'] ?? 0),
                'valor'      => (string) ($v['valor'] ?? ''),
                'nombre'     => (string) ($v['nombre'] ?? ''),
                'referencia' => (string) ($v['referencia'] ?? ''),
                'precio'     => isset($v['precio']) && (float) $v['precio'] > 0 ? (float) $v['precio'] : null,
            ];
        }, is_array($unidad['variantes'] ?? null) ? $unidad['variantes'] : []));
    }

    /** @return array<int, array{nombre: string, valor: string}> */
    public static function caracteristicas_limpias($lista): array
    {
        $limpias = [];

        foreach (is_array($lista) ? $lista : [] as $c) {
            $nombre = trim((string) ($c['nombre'] ?? ''));

            if ($nombre !== '') {
                $limpias[] = ['nombre' => $nombre, 'valor' => trim((string) ($c['valor'] ?? ''))];
            }
        }

        return $limpias;
    }

    /**
     * Solo enlaces http(s): la URL viene del ERP y termina en un `href` del sitio.
     *
     * @return array<int, array{tipo: string, nombre: string, url: string}>
     */
    private static function documentos_limpios($lista): array
    {
        $limpios = [];

        foreach (is_array($lista) ? $lista : [] as $d) {
            $url = esc_url_raw((string) ($d['url'] ?? ''), ['http', 'https']);

            if ($url !== '') {
                $limpios[] = [
                    'tipo'   => (string) ($d['tipo'] ?? ''),
                    'nombre' => (string) ($d['nombre'] ?? 'Documento'),
                    'url'    => $url,
                ];
            }
        }

        return $limpios;
    }

    /** @return array<int, array{tipo: string, nombre: string, url: string}> */
    public static function documentos(int $id_post): array
    {
        $docs = get_post_meta($id_post, self::META_DOCUMENTOS, true);

        return is_array($docs) ? $docs : [];
    }

    /** La lista de planos y fichas técnicas, lista para pintar. */
    public static function html_documentos(array $documentos): string
    {
        if (! $documentos) {
            return '';
        }

        $html = '<ul class="briela-documentos">';

        foreach ($documentos as $doc) {
            $etiqueta = $doc['tipo'] === 'plano' ? 'Plano' : ($doc['tipo'] === 'ficha_tecnica' ? 'Ficha técnica' : 'Documento');
            $html .= '<li><span class="briela-documento-tipo">' . esc_html($etiqueta) . ':</span> '
                . '<a href="' . esc_url($doc['url']) . '" target="_blank" rel="noopener">' . esc_html($doc['nombre']) . '</a></li>';
        }

        return $html . '</ul>';
    }

    /**
     * Sin tienda, la ficha muestra debajo de su texto lo que con WooCommerce serían atributos,
     * variaciones y la pestaña de documentos. Solo en la ficha abierta, no en los listados.
     */
    public static function contenido_de_ficha($contenido)
    {
        if (! is_singular(self::CPT) || ! in_the_loop() || ! is_main_query()) {
            return $contenido;
        }

        $id_post = (int) get_the_ID();
        $html    = '';

        $filas = [];
        foreach (['Marca' => self::META_MARCA, 'Origen' => self::META_ORIGEN] as $nombre => $meta) {
            $valor = (string) get_post_meta($id_post, $meta, true);
            if ($valor !== '') {
                $filas[] = [$nombre, $valor];
            }
        }
        $caracteristicas = get_post_meta($id_post, self::META_CARACTERISTICAS, true);
        foreach (is_array($caracteristicas) ? $caracteristicas : [] as $c) {
            $filas[] = [$c['nombre'], $c['valor']];
        }

        if ($filas) {
            $html .= '<table class="briela-caracteristicas"><tbody>';
            foreach ($filas as [$nombre, $valor]) {
                $html .= '<tr><th>' . esc_html($nombre) . '</th><td>' . esc_html($valor) . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }

        $variantes = get_post_meta($id_post, self::META_VARIANTES, true);
        if (is_array($variantes) && $variantes) {
            $atributo = (string) get_post_meta($id_post, self::META_ATRIBUTO, true) ?: 'Opción';
            $html .= '<table class="briela-variantes"><thead><tr><th>' . esc_html($atributo) . '</th><th>Referencia</th><th>Precio</th></tr></thead><tbody>';
            foreach ($variantes as $v) {
                $precio = $v['precio'] !== null ? esc_html(number_format((float) $v['precio'], 0, ',', '.')) : 'Cotizar';
                $html .= '<tr><td>' . esc_html($v['valor'] ?: $v['nombre']) . '</td><td>' . esc_html($v['referencia']) . '</td><td>' . $precio . '</td></tr>';
            }
            $html .= '</tbody></table>';
        }

        $html .= self::html_documentos(self::documentos($id_post));

        return $contenido . $html;
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

        // Un producto con variantes no tiene precio propio: la ficha muestra el menor de ellas.
        if (! empty($unidad['variantes'])) {
            $precios = array_filter(array_map(function ($v) {
                return isset($v['precio']) ? (float) $v['precio'] : 0.0;
            }, $unidad['variantes']), function ($p) {
                return $p > 0;
            });
            $precio = $precios ? min($precios) : null;
        }

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

                // Se libera la referencia: WooCommerce no deja repetirla mientras la ficha vieja la
                // conserve, y la variación que la reemplaza se quedaría sin ella.
                if (self::hay_tienda() && function_exists('wc_get_product')) {
                    $viejo = wc_get_product($id_post);
                    if ($viejo && $viejo->get_sku('edit')) {
                        $viejo->set_sku('');
                        $viejo->save();
                    }
                }

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

    /**
     * Baja el catálogo en PDF del ERP —siempre sin precios— a la carpeta de subidas.
     *
     * Solo cuando el catálogo cambió (la huella mira qué hay y cuándo se editó, no precios ni
     * existencias, que el PDF no lleva) o cuando la copia tiene más de una semana: armar el PDF
     * con todas las fotos le cuesta al ERP, y la sincronización corre cada hora. Si la descarga
     * falla se queda el PDF anterior: uno de ayer sirve más que un enlace roto.
     */
    private static function actualizar_catalogo_pdf(array $items): void
    {
        $huella = md5((string) wp_json_encode(array_map(function ($item) {
            return [
                $item['tipo'] ?? '', $item['id'] ?? 0, $item['nombre'] ?? '', $item['actualizado_at'] ?? '',
                array_map(function ($v) {
                    return [$v['id'] ?? 0, $v['valor'] ?? '', $v['referencia'] ?? ''];
                }, is_array($item['variantes'] ?? null) ? $item['variantes'] : []),
            ];
        }, $items)));

        $ruta   = self::ruta_catalogo_pdf();
        $fecha  = (int) get_option(self::OPT_PDF_FECHA, 0);
        $vigente = is_file($ruta) && get_option(self::OPT_PDF_HUELLA) === $huella && (time() - $fecha) < self::PDF_VIGENCIA;

        if ($vigente) {
            return;
        }

        $respuesta = ApiClient::descargar('catalogo/pdf');

        // Un PDF empieza por «%PDF»: lo demás es una página de error que no se publica.
        if (! $respuesta['ok'] || strncmp($respuesta['cuerpo'], '%PDF', 4) !== 0) {
            return;
        }

        if (! wp_mkdir_p(dirname($ruta)) || file_put_contents($ruta, $respuesta['cuerpo']) === false) {
            return; // Sin permiso de escritura en las subidas: el catálogo sigue, sin PDF.
        }

        update_option(self::OPT_PDF_HUELLA, $huella);
        update_option(self::OPT_PDF_FECHA, time());
    }

    private static function ruta_catalogo_pdf(): string
    {
        return trailingslashit(wp_upload_dir()['basedir']) . 'briela-connect/catalogo.pdf';
    }

    /** La dirección pública del PDF, o vacío si todavía no se ha bajado. */
    public static function url_catalogo_pdf(): string
    {
        $ruta = self::ruta_catalogo_pdf();

        if (! is_file($ruta)) {
            return '';
        }

        // La versión en la dirección evita que el navegador o un caché sirvan el de la semana pasada.
        return trailingslashit(wp_upload_dir()['baseurl']) . 'briela-connect/catalogo.pdf?v=' . filemtime($ruta);
    }

    /**
     * `[briela_catalogo_pdf texto="Descargar catálogo"]` — el botón de descarga del catálogo,
     * que sale siempre sin precios. No pinta nada mientras el PDF no exista.
     */
    public static function shortcode_catalogo_pdf($atributos): string
    {
        $atributos = shortcode_atts(['texto' => 'Descargar catálogo (PDF)'], is_array($atributos) ? $atributos : []);
        $url       = self::url_catalogo_pdf();

        if ($url === '') {
            return '';
        }

        return '<a class="briela-catalogo-pdf button" href="' . esc_url($url) . '" download>' . esc_html($atributos['texto']) . '</a>';
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
