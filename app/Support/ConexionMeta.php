<?php

namespace App\Support;

use App\Models\Configuracion;

/**
 * Lo que el navegador y el servidor necesitan para hablar con Meta en un mismo lenguaje: la
 * versión de la API, el identificador de la aplicación y de la configuración de Embedded Signup.
 *
 * **La versión de la API vive aquí y en ningún otro sitio nuevo.** Los servicios viejos todavía
 * la llevan escrita (`v21.0`); los nuevos preguntan aquí, y el día de subirla se cambia en un
 * lugar. El SDK del navegador y el servidor tienen que coincidir, o el `code` que entrega
 * Embedded Signup no se puede canjear.
 *
 * **El `config_id` no está en `CredencialesRrss`** a propósito. Esa clase trata como faltante
 * cualquier campo vacío de su mapa, así que agregarle uno habría marcado como «Falta
 * configurar» a todas las instalaciones que hoy publican bien. Sin `config_id` solo se oculta el
 * botón de Embedded Signup; todo lo demás sigue como estaba.
 */
class ConexionMeta
{
    public const VERSION = 'v21.0';

    public static function version(): string
    {
        return (string) config('services.whatsapp.api_version', self::VERSION);
    }

    public static function graph(): string
    {
        return 'https://graph.facebook.com/' . self::version();
    }

    public static function appId(): string
    {
        return CredencialesRrss::valor('meta', 'id');
    }

    /** La configuración de Embedded Signup que se crea en Inicio de sesión con Facebook para empresas. */
    public static function configId(): string
    {
        return (string) Configuracion::get('meta_embedded_config_id', '');
    }

    public static function guardarConfigId(string $valor): void
    {
        Configuracion::set('meta_embedded_config_id', trim($valor));
    }

    /**
     * Si el botón de «Conectar WhatsApp» puede funcionar: hacen falta la aplicación, su secreto
     * (para canjear el código) y la configuración de Embedded Signup.
     */
    public static function embeddedListo(): bool
    {
        return self::appId() !== ''
            && CredencialesRrss::valor('meta', 'secret') !== ''
            && self::configId() !== '';
    }

    /** Lo que se le entrega a la pantalla; el secreto nunca viaja. */
    public static function paraPantalla(): array
    {
        return [
            'app_id'    => self::appId(),
            'config_id' => self::configId(),
            'version'   => self::version(),
            'listo'     => self::embeddedListo(),
        ];
    }
}
