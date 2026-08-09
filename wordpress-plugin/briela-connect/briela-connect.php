<?php
/**
 * Plugin Name:       Briela Connect
 * Plugin URI:        https://briela.app
 * Description:       Conecta este sitio de WordPress con tu instalación de Briela: leads a CRM con atribución UTM, datos estructurados (schema.org) y reseñas post-entrega. WooCommerce y Elementor son módulos opcionales.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Briela
 * Author URI:        https://briela.app
 * Text Domain:       briela-connect
 *
 * Fase A del plugin: token de integración + leads con UTM al CRM.
 * Ver docs/plugin-wordpress-contexto.md en el repo de Briela para el resto
 * de las fases (schema.org, reseñas, WooCommerce, Elementor).
 */

if (! defined('ABSPATH')) {
    exit; // Acceso directo no permitido.
}

define('BRIELA_CONNECT_VERSION', '0.1.0');
define('BRIELA_CONNECT_PATH', plugin_dir_path(__FILE__));
define('BRIELA_CONNECT_URL', plugin_dir_url(__FILE__));

require_once BRIELA_CONNECT_PATH . 'includes/class-api-client.php';
require_once BRIELA_CONNECT_PATH . 'includes/class-settings.php';
require_once BRIELA_CONNECT_PATH . 'includes/class-utm.php';
require_once BRIELA_CONNECT_PATH . 'includes/integrations/class-forms-bridge.php';

/**
 * Arranque del plugin. Cada pieza se registra sola; los módulos que
 * dependen de otro plugin (WooCommerce, Elementor) llegan en fases
 * posteriores y se activan solo si detectan que ese plugin existe — el
 * núcleo (este archivo) funciona en cualquier sitio de WordPress.
 */
function briela_connect_init(): void
{
    BrielaConnect\Settings::registrar();
    BrielaConnect\Utm::registrar();
    BrielaConnect\Integrations\FormsBridge::registrar();
}
add_action('plugins_loaded', 'briela_connect_init');

/**
 * Aviso en el admin si el plugin está activo pero sin token: es fácil
 * instalarlo y olvidar el paso de pegar la URL y el token del ERP.
 */
function briela_connect_aviso_sin_configurar(): void
{
    if (! current_user_can('manage_options') || BrielaConnect\ApiClient::configurado()) {
        return;
    }

    $pantalla = get_current_screen();
    if ($pantalla && $pantalla->id === 'settings_page_briela-connect') {
        return; // Ya está viendo la pantalla de ajustes.
    }

    $url = admin_url('options-general.php?page=briela-connect');
    echo '<div class="notice notice-warning"><p>';
    echo 'Briela Connect está activo pero todavía no está conectado a tu ERP. ';
    echo '<a href="' . esc_url($url) . '">Pega la URL y el token del ERP aquí</a>.';
    echo '</p></div>';
}
add_action('admin_notices', 'briela_connect_aviso_sin_configurar');
