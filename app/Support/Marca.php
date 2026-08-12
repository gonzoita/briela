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

    /**
     * El color de fondo de un esquema, para la franja del sistema en el teléfono.
     *
     * Lo necesita el HTML antes de que corra nada: en las pantallas sin sesión no hay
     * interruptor de tema montado, y sin esto la franja del reloj queda clara sobre una
     * pantalla oscura.
     */
    public static function colorFondo(string $esquema = 'claro'): string
    {
        return self::esquemas()[$esquema]['fondo'] ?? '#FFFFFF';
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

        $esquemas = self::esquemas();

        // El modo día va en :root y el de noche en un atributo del <html>, que es
        // lo que cambia el selector de tema. Así el cambio es instantáneo y no
        // necesita recargar ni volver a pedir nada al servidor.
        foreach ($esquemas['claro'] as $nombre => $valor) {
            $lineas[] = "--{$nombre}:{$valor}";
        }

        $oscuro = [];
        foreach ($esquemas['oscuro'] as $nombre => $valor) {
            $oscuro[] = "--{$nombre}:{$valor}";
        }

        return ':root{' . implode(';', $lineas) . '}'
            . 'html[data-tema="oscuro"]{' . implode(';', $oscuro) . '}'
            . 'html[data-tema="oscuro"]{color-scheme:dark}';
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
            // Radios contenidos. Un botón de 40 píxeles de alto con 14 de radio se
            // lee como pastilla, y la pastilla es de las cosas que más abaratan el
            // aspecto de una interfaz de trabajo. Estos valores dejan la esquina
            // suave sin que la forma llame la atención.
            'radio-sm'  => '4px',
            'radio'     => '6px',
            'radio-lg'  => '8px',
            'radio-xl'  => '10px',
        ];
    }

    /**
     * Los colores de las superficies y del texto, para el modo día y el de noche.
     *
     * Están aquí y no en Tailwind porque tienen que poder **cambiar de valor** sin
     * recompilar: el modo noche no añade clases a las 128 pantallas, cambia lo que
     * significan las que ya usan. Por eso la escala de grises y las superficies
     * salen de variables CSS y no de números fijos.
     *
     * En el modo de noche la escala se invierte —lo que era casi blanco pasa a ser
     * casi negro— y las sombras se aclaran: una sombra negra sobre un fondo oscuro
     * no se ve, así que ahí el relieve lo dan los bordes.
     *
     * @return array<string, array<string,string>>
     */
    public static function esquemas(): array
    {
        return [
            'claro' => [
                'superficie'   => '#FFFFFF',
                'superficie-2' => '#F9FAFB',
                'fondo'        => '#FBFBFC',
                'borde'        => '#EDEFF2',
                'texto'        => '#101828',
                'texto-2'      => '#475467',
                'texto-3'      => '#98A2B3',
                'tinta-900'    => '#101828',
                'tinta-700'    => '#344054',
                'tinta-500'    => '#475467',
                'tinta-400'    => '#667085',
                'tinta-300'    => '#98A2B3',
                'tinta-200'    => '#EAECF0',
                'tinta-100'    => '#F2F4F7',
                'tinta-50'     => '#F9FAFB',
                'sombra-sm'    => '0 1px 2px rgba(16,24,40,.04), 0 1px 3px rgba(16,24,40,.06)',
                'sombra'       => '0 1px 3px rgba(16,24,40,.04), 0 8px 24px -8px rgba(16,24,40,.10)',
                'sombra-lg'    => '0 2px 6px rgba(16,24,40,.04), 0 24px 48px -12px rgba(16,24,40,.14)',
                // La barra inferior del móvil proyecta hacia arriba. Va aparte porque en
                // modo de noche una sombra negra sobre fondo oscuro no se ve, y lo que
                // separa la barra del contenido pasa a ser un borde claro.
                'sombra-barra' => 'rgba(16,24,40,.08)',
                // Los fondos suaves de aviso. Son 505 sitios en el sistema que los
                // usan como fondo de cajas informativas, etiquetas y alertas.
                'pastel-azul'    => '#EFF8FF',
                'pastel-azul-2'  => '#D1E9FF',
                'pastel-verde'   => '#ECFDF3',
                'pastel-ambar'   => '#FFFAEB',
                'pastel-rojo'    => '#FEF3F2',
                'pastel-violeta' => '#F4F3FF',
                'pastel-naranja' => '#FEF6EE',
                // Texto de color. En el modo de noche se aclara: un azul oscuro
                // sobre fondo oscuro se lee con esfuerzo, y en un sistema de
                // trabajo eso cansa en media hora.
                'texto-azul'    => '#1849A9',
                'texto-verde'   => '#05603A',
                'texto-ambar'   => '#93370D',
                'texto-rojo'    => '#B42318',
                'texto-violeta' => '#5925DC',
                // El velo de las barras fijas, que van translúcidas con desenfoque.
                // Tiene que teñir del color del tema: un velo blanco sobre el fondo
                // de noche deja una banda clara arriba de la pantalla.
                'velo'         => 'rgba(255,255,255,.85)',
                'scroll'       => '#D0D5DD',
                'scroll-hover' => '#98A2B3',
            ],
            'oscuro' => [
                // Grises con un asomo de azul, no negro puro: el negro absoluto
                // sobre pantallas OLED produce un contraste que cansa a las dos
                // horas de trabajo.
                'superficie'   => '#1C2029',
                'superficie-2' => '#232833',
                'fondo'        => '#14171E',
                'borde'        => '#2E3542',
                'texto'        => '#F2F4F7',
                'texto-2'      => '#B4BCCA',
                'texto-3'      => '#7D8698',
                'tinta-900'    => '#F2F4F7',
                'tinta-700'    => '#D9DEE7',
                'tinta-500'    => '#B4BCCA',
                'tinta-400'    => '#98A2B3',
                'tinta-300'    => '#7D8698',
                'tinta-200'    => '#2E3542',
                'tinta-100'    => '#252B36',
                'tinta-50'     => '#232833',
                'sombra-sm'    => '0 1px 2px rgba(0,0,0,.30)',
                'sombra'       => '0 2px 8px rgba(0,0,0,.35)',
                'sombra-lg'    => '0 12px 32px rgba(0,0,0,.45)',
                // Sobre fondo oscuro una sombra negra no separa nada: se usa un halo
                // claro, que es lo que hace de línea entre la barra y el contenido.
                'sombra-barra' => 'rgba(255,255,255,.10)',
                // En el modo de noche los pasteles se vuelven tintes oscuros del
                // mismo color: mantienen el significado —azul informa, rojo avisa—
                // sin dejar una caja clara con texto claro encima, que es lo que
                // los volvía ilegibles.
                'pastel-azul'    => '#182A45',
                'pastel-azul-2'  => '#1E3A5F',
                'pastel-verde'   => '#14302A',
                'pastel-ambar'   => '#33280F',
                'pastel-rojo'    => '#3A1D1D',
                'pastel-violeta' => '#251F41',
                'pastel-naranja' => '#37220F',
                'texto-azul'    => '#84CAFF',
                'texto-verde'   => '#6CE9A6',
                'texto-ambar'   => '#FEC84B',
                'texto-rojo'    => '#FDA29B',
                'texto-violeta' => '#BDB4FE',
                'velo'         => 'rgba(28,32,41,.85)',
                'scroll'       => '#3B4454',
                'scroll-hover' => '#55617A',
            ],
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
     * Logo para el modo de noche.
     *
     * Un logo con texto oscuro desaparece sobre fondo oscuro, y no hay forma de
     * arreglarlo por CSS: es una imagen. Así que se sube aparte. Si la empresa no
     * subió versión de noche, se usa la de día, que es mejor que no mostrar nada.
     */
    public static function logoOscuroUrl(): ?string
    {
        return \App\Services\ImagenMarcaService::url('empresa_logo_oscuro');
    }

    /** ¿Tiene una versión propia para el modo de noche? */
    public static function tieneLogoOscuro(): bool
    {
        return self::logoOscuroUrl() !== null;
    }

    public static function faviconOscuroUrl(): ?string
    {
        return \App\Services\ImagenMarcaService::url('marca_favicon_oscuro');
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
