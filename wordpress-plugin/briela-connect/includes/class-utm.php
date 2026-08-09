<?php

namespace BrielaConnect;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Atribución UTM: encola el JS que la captura al aterrizar (assets/utm-capture.js)
 * y expone un helper para leerla en PHP al momento de crear el lead.
 *
 * Primer-toque: la cookie solo se sobreescribe cuando la URL trae utm_source
 * de nuevo, así una visita directa posterior no borra de dónde vino la
 * persona realmente.
 */
class Utm
{
    public const COOKIE = 'briela_connect_utm';

    public static function registrar(): void
    {
        add_action('wp_enqueue_scripts', [self::class, 'encolar']);
    }

    public static function encolar(): void
    {
        wp_enqueue_script(
            'briela-connect-utm',
            BRIELA_CONNECT_URL . 'assets/utm-capture.js',
            [],
            BRIELA_CONNECT_VERSION,
            true
        );

        wp_localize_script('briela-connect-utm', 'BrielaConnectUtm', [
            'cookie'    => self::COOKIE,
            'dias'      => 30,
        ]);
    }

    /**
     * Lee la atribución capturada por JS (utm_source/medium/campaign +
     * página de origen). Devuelve un arreglo vacío si no hay cookie o no se
     * pudo leer — un formulario sin atribución sigue creando el lead igual.
     */
    public static function leer(): array
    {
        if (empty($_COOKIE[self::COOKIE])) {
            return [];
        }

        $datos = json_decode(stripslashes((string) $_COOKIE[self::COOKIE]), true);

        if (! is_array($datos)) {
            return [];
        }

        return [
            'pagina_origen' => sanitize_text_field($datos['pagina_origen'] ?? ''),
            'utm_source'    => sanitize_text_field($datos['utm_source'] ?? ''),
            'utm_medium'    => sanitize_text_field($datos['utm_medium'] ?? ''),
            'utm_campaign'  => sanitize_text_field($datos['utm_campaign'] ?? ''),
        ];
    }
}
