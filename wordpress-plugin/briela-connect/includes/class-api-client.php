<?php

namespace BrielaConnect;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Único punto de salida hacia el ERP de Briela. Envuelve wp_remote_*, con
 * reintento simple ante fallas de red (no ante errores de validación) y un
 * registro del último error para que la pantalla de ajustes lo pueda
 * mostrar sin tener que ir a los logs del servidor.
 */
class ApiClient
{
    private const OPT_URL   = 'briela_connect_url_base';
    private const OPT_TOKEN = 'briela_connect_token';
    private const OPT_ULTIMO_ERROR = 'briela_connect_ultimo_error';

    public static function url_base(): string
    {
        return rtrim((string) get_option(self::OPT_URL, ''), '/');
    }

    public static function token(): string
    {
        return (string) get_option(self::OPT_TOKEN, '');
    }

    public static function configurado(): bool
    {
        return self::url_base() !== '' && self::token() !== '';
    }

    public static function ultimo_error(): string
    {
        return (string) get_option(self::OPT_ULTIMO_ERROR, '');
    }

    /**
     * POST genérico contra un endpoint del namespace /api/wp/ del ERP.
     * Devuelve ['ok' => bool, 'datos' => array, 'mensaje' => string].
     */
    public static function post(string $ruta, array $cuerpo, int $intentos = 2): array
    {
        if (! self::configurado()) {
            return ['ok' => false, 'datos' => [], 'mensaje' => 'Briela Connect no está conectado a un ERP todavía.'];
        }

        $url = self::url_base() . '/api/wp/' . ltrim($ruta, '/');

        $args = [
            'timeout' => 8,
            'headers' => [
                'Authorization' => 'Bearer ' . self::token(),
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'body' => wp_json_encode($cuerpo),
        ];

        $ultimoError = '';

        for ($intento = 1; $intento <= max(1, $intentos); $intento++) {
            $respuesta = wp_remote_post($url, $args);

            if (! is_wp_error($respuesta)) {
                $codigo = wp_remote_retrieve_response_code($respuesta);
                $cuerpoRespuesta = json_decode(wp_remote_retrieve_body($respuesta), true) ?: [];

                if ($codigo >= 200 && $codigo < 300) {
                    update_option(self::OPT_ULTIMO_ERROR, '');
                    return ['ok' => true, 'datos' => $cuerpoRespuesta, 'mensaje' => ''];
                }

                // Error de validación o de autenticación: reintentar no ayuda.
                $mensaje = $cuerpoRespuesta['mensaje'] ?? ('El ERP respondió con el código ' . $codigo . '.');
                self::registrar_error($mensaje);
                return ['ok' => false, 'datos' => $cuerpoRespuesta, 'mensaje' => $mensaje];
            }

            // Falla de red: sí vale la pena reintentar una vez.
            $ultimoError = $respuesta->get_error_message();
        }

        self::registrar_error($ultimoError);
        return ['ok' => false, 'datos' => [], 'mensaje' => $ultimoError];
    }

    private static function registrar_error(string $mensaje): void
    {
        $mensaje = current_time('mysql') . ' — ' . $mensaje;
        update_option(self::OPT_ULTIMO_ERROR, $mensaje);
    }
}
