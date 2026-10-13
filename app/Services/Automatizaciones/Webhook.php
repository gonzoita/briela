<?php

namespace App\Services\Automatizaciones;

use Illuminate\Support\Facades\Http;

/**
 * Envía los datos de un documento a otro sistema (Zapier, Make, n8n o uno propio).
 *
 * Una dirección que escribe una persona y que el SERVIDOR visita es una puerta clásica para que
 * alguien haga que Briela consulte su propia red interna (el panel del hosting, la base de datos,
 * los metadatos de la nube). Por eso:
 * - solo `https`, sin usuario ni clave en la dirección y sin puertos raros;
 * - el nombre se resuelve ANTES de enviar y se rechaza si apunta a una dirección privada,
 *   reservada o local;
 * - no sigue redirecciones: una respuesta 302 hacia la red interna se queda en 302.
 */
class Webhook
{
    /** Por qué no se puede usar una dirección, o null si se puede. */
    public static function errorDeDireccion(string $url, bool $resolver = true): ?string
    {
        $p = parse_url(trim($url));

        if (! $p || ($p['scheme'] ?? '') !== 'https' || empty($p['host'])) {
            return 'la dirección debe empezar por https://';
        }

        if (isset($p['user']) || isset($p['pass'])) {
            return 'la dirección no puede llevar usuario ni clave.';
        }

        if (isset($p['port']) && ! in_array((int) $p['port'], [443, 8443], true)) {
            return 'solo se admiten los puertos 443 y 8443.';
        }

        $host = strtolower(trim($p['host'], '[]'));

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return 'la dirección apunta a la red interna.';
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::esPublica($host) ? null : 'la dirección apunta a la red interna.';
        }

        if (! $resolver) {
            return null;
        }

        $ips = @gethostbynamel($host) ?: [];

        if ($ips === []) {
            return 'no se encontró ese dominio.';
        }

        foreach ($ips as $ip) {
            if (! self::esPublica($ip)) {
                return 'la dirección apunta a la red interna.';
            }
        }

        return null;
    }

    private static function esPublica(string $ip): bool
    {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    /**
     * @param  array<string, mixed>  $cuerpo
     * @return int el código HTTP de la respuesta
     *
     * @throws \RuntimeException
     */
    public static function enviar(string $url, array $cuerpo, ?string $clave = null): int
    {
        if ($error = self::errorDeDireccion($url, resolver: ! app()->runningUnitTests())) {
            throw new \RuntimeException("No se envió el webhook: {$error}");
        }

        $json    = json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = ['User-Agent' => 'Briela-Automatizaciones/1.0'];

        // Con una clave, el otro sistema puede comprobar que el envío viene de aquí y no de cualquiera.
        if (filled($clave)) {
            $headers['X-Briela-Firma'] = 'sha256=' . hash_hmac('sha256', $json, (string) $clave);
        }

        try {
            $r = Http::withHeaders($headers)->withoutRedirecting()->timeout(10)->connectTimeout(5)
                ->withBody($json, 'application/json')->post($url);
        } catch (\Throwable $e) {
            throw new \RuntimeException('No se pudo conectar con el otro sistema: ' . mb_strimwidth($e->getMessage(), 0, 150, '…'));
        }

        if (! $r->successful()) {
            throw new \RuntimeException("El otro sistema respondió con un error ({$r->status()}).");
        }

        return $r->status();
    }
}
