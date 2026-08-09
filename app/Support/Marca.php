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
     * PROVISIONAL: es un azul neutro, sin identidad de ninguna empresa.
     * Cambiar por el color definitivo de Briela — es esta línea y nada más,
     * porque toda la paleta se deriva de aquí.
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

    // ─── Tipografía ──────────────────────────────────────────────────────────

    public const FUENTE_POR_DEFECTO = 'sistema';

    /**
     * Tipografías que puede elegir la empresa.
     *
     * Todas son pilas de fuentes ya presentes en los dispositivos: no se descarga
     * ningún archivo. Eso importa en un producto que se instala en servidores de
     * clientes — una fuente traída de un CDN externo deja de verse el día que ese
     * servicio falla, y de paso le reporta cada visita a un tercero.
     *
     * La opción del sistema usa San Francisco en Mac y iPhone, Segoe UI Variable
     * en Windows y Roboto en Android: la tipografía nativa de cada aparato, que es
     * la que el usuario ya está acostumbrado a leer.
     *
     * @return array<string, array{nombre:string, pila:string, nota:string}>
     */
    public static function fuentes(): array
    {
        return [
            'sistema' => [
                'nombre' => 'Del sistema',
                'pila'   => '-apple-system, BlinkMacSystemFont, "Segoe UI Variable Text", "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
                'nota'   => 'La tipografía propia de cada dispositivo. La más rápida y la que mejor se lee.',
            ],
            'neutra' => [
                'nombre' => 'Neutra',
                'pila'   => '"Helvetica Neue", Helvetica, "Liberation Sans", Arial, sans-serif',
                'nota'   => 'Sobria y sin carácter propio. Se ve igual en todas partes.',
            ],
            'clasica' => [
                'nombre' => 'Clásica con serifas',
                'pila'   => 'Iowan Old Style, "Palatino Linotype", Palatino, Georgia, "Times New Roman", serif',
                'nota'   => 'Más formal. Va bien si tus documentos son lo que el cliente más ve.',
            ],
            'compacta' => [
                'nombre' => 'Compacta',
                'pila'   => '"Segoe UI", Tahoma, Verdana, sans-serif',
                'nota'   => 'Aprovecha mejor el ancho en pantallas pequeñas y en tablas con muchas columnas.',
            ],
        ];
    }

    /** La clave elegida, o la de fábrica si lo guardado no existe. */
    public static function fuenteClave(): string
    {
        $valor = trim((string) Configuracion::get('marca_fuente', ''));

        return array_key_exists($valor, self::fuentes()) ? $valor : self::FUENTE_POR_DEFECTO;
    }

    /** La pila CSS lista para poner en font-family. */
    public static function fuente(): string
    {
        return self::fuentes()[self::fuenteClave()]['pila'];
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
            // Un tono apenas teñido para bordes y separadores: el gris puro al
            // lado de un color de marca se ve sucio.
            'marca-borde'  => self::ajustarLuz($base, 0.86),
        ];
    }

    /**
     * Las variables listas para meter en un bloque <style>.
     *
     * Además del color y la tipografía van los tokens de forma —radios y
     * sombras—, para poder ajustar el aspecto de todo el sistema desde un solo
     * lugar en vez de tocar clases en 128 pantallas.
     */
    public static function comoCss(): string
    {
        $lineas = [];

        foreach (self::paleta() as $nombre => $valor) {
            $lineas[] = "--{$nombre}:{$valor}";
        }

        $lineas[] = '--fuente:' . self::fuente();

        foreach (self::tokens() as $nombre => $valor) {
            $lineas[] = "--{$nombre}:{$valor}";
        }

        return ':root{' . implode(';', $lineas) . '}';
    }

    /**
     * Tokens de forma: lo que hace que la interfaz se sienta de una pieza.
     *
     * Sombras muy suaves y en dos capas, como las de las interfaces de Apple: una
     * sombra marcada envejece cualquier pantalla. Los radios crecen con el tamaño
     * del elemento, que es lo que hace que un botón y una tarjeta se vean de la
     * misma familia sin tener el mismo radio.
     *
     * @return array<string,string>
     */
    public static function tokens(): array
    {
        return [
            'radio-sm'  => '8px',
            'radio'     => '12px',
            'radio-lg'  => '16px',
            'radio-xl'  => '22px',
            'sombra-sm' => '0 1px 2px rgba(16,24,40,.04), 0 1px 3px rgba(16,24,40,.06)',
            'sombra'    => '0 1px 3px rgba(16,24,40,.04), 0 8px 24px -8px rgba(16,24,40,.10)',
            'sombra-lg' => '0 2px 6px rgba(16,24,40,.04), 0 24px 48px -12px rgba(16,24,40,.14)',
            'borde'     => '#EDEFF2',
            'texto'     => '#101828',
            'texto-2'   => '#475467',
            'texto-3'   => '#98A2B3',
            'fondo'     => '#FBFBFC',
        ];
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
        // Se compara con vacío, no solo con null: la instalación nace con la
        // clave creada y sin valor, y en ese caso Configuracion::get devuelve la
        // cadena vacía en vez del valor por defecto. Sin esto, una instalación
        // recién hecha muestra el título de la pestaña y los pies de página en
        // blanco hasta que alguien escriba el nombre.
        $valor = trim((string) Configuracion::get('empresa_nombre', ''));

        return $valor !== '' ? $valor : 'Mi empresa';
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

    /**
     * Ruta en disco del logo, para los PDF.
     *
     * dompdf no puede incrustar una imagen desde una URL, necesita el archivo.
     * Devuelve null si la empresa todavía no subió logo: las plantillas ya
     * saben mostrar el nombre en texto cuando no hay imagen.
     */
    public static function logoPath(): ?string
    {
        return \App\Services\ImagenMarcaService::ruta('empresa_logo');
    }
}
