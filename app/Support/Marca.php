<?php

namespace App\Support;

use App\Models\Configuracion;

/**
 * Identidad visual configurable desde la app.
 *
 * La idea: quien configura elige UN color y el resto de la paleta se deriva.
 * Pedir cuatro colores garantiza combinaciones feas; pedir uno y calcular el
 * hover, el fondo suave y el color de texto legible da un resultado coherente
 * siempre.
 *
 * Los valores salen como variables CSS en app.blade.php, así que se aplican
 * sin recompilar nada: se guarda, se recarga y ya.
 */
class Marca
{
    /**
     * Color de fábrica, el que ve una instalación recién hecha antes de que el
     * cliente ponga el suyo.
     *
     * PROVISIONAL: es un azul neutro puesto al desacoplar la marca de
     * Interfrigo. Cambiar por el color definitivo de Briela — es esta línea y
     * nada más, porque toda la paleta se deriva de aquí.
     */
    public const COLOR_POR_DEFECTO = '#2563EB';

    /**
     * Color principal. Si lo guardado no es un hex válido, cae al de fábrica
     * en vez de romper toda la interfaz.
     */
    public static function color(): string
    {
        $valor = (string) Configuracion::get('marca_color', '');

        return self::esHex($valor) ? strtoupper($valor) : self::COLOR_POR_DEFECTO;
    }

    public static function esHex(string $valor): bool
    {
        return (bool) preg_match('/^#[0-9A-Fa-f]{6}$/', $valor);
    }

    /**
     * Paleta completa derivada del color principal.
     *
     * @return array<string,string>
     */
    public static function paleta(): array
    {
        $base = self::color();

        return [
            'marca'        => $base,
            // Para hover y estados presionados.
            'marca-oscuro' => self::ajustarLuz($base, -0.18),
            // Fondos de tarjetas e iconos: el mismo tono, muy lavado.
            'marca-suave'  => self::ajustarLuz($base, 0.90),
            'marca-medio'  => self::ajustarLuz($base, 0.75),
            // Texto que se pone ENCIMA del color principal.
            'marca-texto'  => self::textoLegible($base),
        ];
    }

    /** Las variables listas para meter en un bloque <style>. */
    public static function comoCss(): string
    {
        $lineas = [];
        foreach (self::paleta() as $nombre => $valor) {
            $lineas[] = "--{$nombre}:{$valor}";
        }

        return ':root{' . implode(';', $lineas) . '}';
    }

    /**
     * Aclara u oscurece un color.
     *
     * @param float $factor  negativo oscurece, positivo aclara (de -1 a 1)
     */
    public static function ajustarLuz(string $hex, float $factor): string
    {
        [$r, $g, $b] = self::aRgb($hex);

        $mezclar = function (int $canal) use ($factor): int {
            $destino = $factor > 0 ? 255 : 0;
            $peso    = abs($factor);

            return (int) round($canal + ($destino - $canal) * $peso);
        };

        return sprintf('#%02X%02X%02X', $mezclar($r), $mezclar($g), $mezclar($b));
    }

    /**
     * Blanco o negro, el que se lea mejor sobre el color dado.
     *
     * Usa luminancia relativa (fórmula WCAG) en vez de un promedio simple,
     * porque el ojo humano percibe el verde mucho más brillante que el azul:
     * un amarillo y un azul con el mismo promedio necesitan texto opuesto.
     */
    public static function textoLegible(string $hex): string
    {
        [$r, $g, $b] = self::aRgb($hex);

        $canal = function (int $c): float {
            $s = $c / 255;

            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        };

        $luminancia = 0.2126 * $canal($r) + 0.7152 * $canal($g) + 0.0722 * $canal($b);

        return $luminancia > 0.45 ? '#111827' : '#FFFFFF';
    }

    /** @return array{0:int,1:int,2:int} */
    private static function aRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    // ─── Pestaña del navegador ───────────────────────────────────────────────

    public static function nombreEmpresa(): string
    {
        return (string) Configuracion::get('empresa_nombre', 'Mi empresa');
    }

    /**
     * Plantilla del título de la pestaña. Admite {pagina} y {empresa}.
     */
    public static function plantillaTitulo(): string
    {
        $valor = trim((string) Configuracion::get('marca_titulo', ''));

        // Por defecto, la pestaña muestra la empresa del cliente y no el nombre
        // del producto: quien usa el sistema todos los días trabaja en su
        // empresa, no en Briela.
        return $valor !== '' ? $valor : '{empresa}';
    }

    /**
     * El título cuando no hay página específica (por ejemplo, la primera carga
     * antes de que Vue tome el control).
     */
    public static function tituloBase(): string
    {
        return trim(str_replace(
            ['{pagina}', '{empresa}'],
            ['', self::nombreEmpresa()],
            self::plantillaTitulo()
        ), " -–—|·");
    }

    /**
     * Las imágenes viven en el servidor (storage/app/public/marca).
     *
     * Se sigue mirando la clave *_url por compatibilidad: es donde quedaron
     * los enlaces de Google Drive de antes. Si alguien todavía tiene uno
     * guardado y funciona, se respeta; lo nuevo va al disco local.
     */
    public static function faviconUrl(): string
    {
        return \App\Services\ImagenMarcaService::url('marca_favicon')
            ?? (trim((string) Configuracion::get('marca_favicon_url', '')) ?: '/icons/icon-96.png');
    }

    public static function logoUrl(): string
    {
        return \App\Services\ImagenMarcaService::url('empresa_logo')
            ?? (trim((string) Configuracion::get('empresa_logo_url', '')) ?: asset('icons/icon-512.png'));
    }
}
