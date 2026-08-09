<?php

namespace BrielaConnect;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Pantalla Ajustes → Briela Connect: URL del ERP + token de integración.
 * Se generan los dos desde el ERP, en Configuración → Integraciones →
 * WordPress — aquí solo se pegan.
 */
class Settings
{
    private const OPT_URL   = 'briela_connect_url_base';
    private const OPT_TOKEN = 'briela_connect_token';
    private const GRUPO     = 'briela_connect_ajustes';
    private const SLUG      = 'briela-connect';

    public static function registrar(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'campos']);
    }

    public static function menu(): void
    {
        add_options_page(
            'Briela Connect',
            'Briela Connect',
            'manage_options',
            self::SLUG,
            [self::class, 'pagina']
        );
    }

    public static function campos(): void
    {
        register_setting(self::GRUPO, self::OPT_URL, [
            'type'              => 'string',
            'sanitize_callback' => static function ($valor) {
                $valor = trim((string) $valor);
                return $valor === '' ? '' : rtrim(esc_url_raw($valor), '/');
            },
            'default' => '',
        ]);

        register_setting(self::GRUPO, self::OPT_TOKEN, [
            'type'              => 'string',
            'sanitize_callback' => static function ($valor) {
                $valor = trim((string) $valor);

                // Campo vacío al guardar = no se quiso cambiar el token ya
                // guardado (igual que el patrón de credenciales en el ERP).
                if ($valor === '') {
                    return get_option(self::OPT_TOKEN, '');
                }

                return sanitize_text_field($valor);
            },
            'default' => '',
        ]);
    }

    public static function pagina(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $urlActual   = get_option(self::OPT_URL, '');
        $tokenActual = get_option(self::OPT_TOKEN, '');
        $tokenParcial = $tokenActual === '' ? '' : '···' . substr($tokenActual, -6);
        $configurado = ApiClient::configurado();
        $ultimoError = ApiClient::ultimo_error();
        ?>
        <div class="wrap">
            <h1>Briela Connect</h1>
            <p>Conecta este sitio con tu instalación de Briela. La URL y el token se generan en el ERP, en
                <strong>Configuración → Integraciones → WordPress</strong>.</p>

            <p>
                <span class="dashicons <?php echo $configurado ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
                <strong><?php echo $configurado ? 'Conectado' : 'Sin configurar'; ?></strong>
            </p>

            <?php if ($ultimoError !== '') : ?>
                <div class="notice notice-error"><p><strong>Último error al hablar con el ERP:</strong> <?php echo esc_html($ultimoError); ?></p></div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields(self::GRUPO); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="briela_url_base">URL del sitio Briela</label></th>
                        <td>
                            <input type="url" id="briela_url_base" name="<?php echo esc_attr(self::OPT_URL); ?>"
                                value="<?php echo esc_attr($urlActual); ?>" class="regular-text"
                                placeholder="https://sistema.tucliente.com" required />
                            <p class="description">La URL base de la instalación de Briela del cliente, sin barra al final.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="briela_token">Token de integración</label></th>
                        <td>
                            <input type="password" id="briela_token" name="<?php echo esc_attr(self::OPT_TOKEN); ?>"
                                value="" class="regular-text" autocomplete="off"
                                placeholder="<?php echo $tokenParcial !== '' ? esc_attr('Guardado: ' . $tokenParcial) : 'Pega el token generado en el ERP'; ?>" />
                            <p class="description">Déjalo vacío para conservar el token que ya está guardado.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Guardar conexión'); ?>
            </form>
        </div>
        <?php
    }
}
