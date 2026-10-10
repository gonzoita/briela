<?php

namespace App\Support;

/**
 * ¿Esta dirección existe desde internet, o solo dentro de este computador?
 *
 * Hace falta en más de un sitio y la respuesta tiene que ser **la misma** en todos:
 *
 * - El webhook de WhatsApp: Meta lo llama desde internet, y a `localhost` no va a llegar nunca.
 *   Es lo primero que falla al probar en Laragon, y la pantalla lo dice en vez de dejar a
 *   alguien esperando mensajes que no van a entrar.
 * - Los adjuntos de Messenger e Instagram: la API **no acepta el archivo**, acepta una URL que
 *   Meta va a descargar. Si la instalación no es alcanzable, el adjunto falla con un error de
 *   Meta que no explica nada.
 *
 * Estaba escrito una vez, privado dentro de `WhatsappDiagnosticoService`. Al necesitarlo los
 * adjuntos habrían quedado dos copias de la misma lista de sufijos, y la segunda se olvida de
 * actualizar cuando alguien agrega `.internal`.
 */
class UrlPublica
{
    /** Los nombres y sufijos que solo existen en la máquina o en la red local. */
    private const SUFIJOS_LOCALES = ['localhost', '.local', '.test', '.localhost', '.internal'];

    public static function si(?string $url): bool
    {
        $host = parse_url((string) $url, PHP_URL_HOST) ?: '';

        if ($host === '') {
            return false;
        }

        // Una IP privada o reservada —192.168.x.x, 10.x.x.x, 127.0.0.1— es de la red de la
        // empresa, no de internet.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        foreach (self::SUFIJOS_LOCALES as $sufijo) {
            if ($host === $sufijo || str_ends_with($host, $sufijo)) {
                return false;
            }
        }

        // Un nombre sin punto —«servidor», «briela»— es un nombre de red interna.
        return str_contains($host, '.');
    }

    /** Si la instalación entera es alcanzable: lo que decide si Meta puede venir a buscar algo. */
    public static function instalacionAlcanzable(): bool
    {
        return self::si(config('app.url'));
    }
}
