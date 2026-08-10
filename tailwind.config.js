import forms from '@tailwindcss/forms';

/**
 * Los fundamentos visuales del sistema.
 *
 * Casi todo apunta a variables CSS que imprime App\Support\Marca en cada página.
 * Así la empresa cambia su color y su tipografía desde Ajustes y se aplica al
 * instante, sin recompilar nada — que es lo que necesita un producto instalado en
 * el servidor de cada cliente, donde no hay Node para volver a construir.
 */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            // La tipografía la elige la empresa. Por defecto es la del sistema:
            // San Francisco en Mac y iPhone, Segoe UI en Windows, Roboto en
            // Android. No se descarga ningún archivo de ningún servidor ajeno.
            fontFamily: {
                sans: ['var(--fuente)'],
            },

            colors: {
                marca: {
                    DEFAULT: 'var(--marca)',
                    oscuro:  'var(--marca-oscuro)',
                    medio:   'var(--marca-medio)',
                    suave:   'var(--marca-suave)',
                    borde:   'var(--marca-borde)',
                    texto:   'var(--marca-texto)',
                },
                // Grises apenas fríos, apuntando a variables CSS y no a números
                // fijos. Es lo que permite el modo noche: no se añaden clases a las
                // 128 pantallas, cambia lo que significan las que ya usan.
                //
                // La escala es corta a propósito: con menos tonos disponibles es
                // más difícil que cada pantalla invente el suyo.
                tinta: {
                    900: 'var(--tinta-900)',
                    700: 'var(--tinta-700)',
                    500: 'var(--tinta-500)',
                    400: 'var(--tinta-400)',
                    300: 'var(--tinta-300)',
                    200: 'var(--tinta-200)',
                    100: 'var(--tinta-100)',
                    50:  'var(--tinta-50)',
                },
                linea: 'var(--borde)',
                lienzo: 'var(--fondo)',
                // Las superficies: lo que en modo día es blanco y de noche es un
                // gris muy oscuro. `text-white` se deja como blanco real, porque
                // casi siempre va encima del color de la marca.
                superficie: {
                    DEFAULT: 'var(--superficie)',
                    2: 'var(--superficie-2)',
                },
            },

            borderRadius: {
                sm:   'var(--radio-sm)',
                DEFAULT: 'var(--radio)',
                md:   'var(--radio-sm)',
                lg:   'var(--radio-lg)',
                xl:   'var(--radio-xl)',
                '2xl': '18px',
                '3xl': '24px',
            },

            boxShadow: {
                sm:   'var(--sombra-sm)',
                DEFAULT: 'var(--sombra)',
                lg:   'var(--sombra-lg)',
                // Para lo que flota sobre el contenido: menús y hojas.
                flotante: '0 4px 12px rgba(16,24,40,.06), 0 32px 64px -16px rgba(16,24,40,.18)',
                ninguna: 'none',
            },

            // Escala tipográfica con interlineado ya resuelto, para no tener que
            // acordarse de emparejarlos en cada pantalla.
            fontSize: {
                'xs':  ['12px', { lineHeight: '16px', letterSpacing: '0.01em' }],
                'sm':  ['13px', { lineHeight: '18px' }],
                'base':['15px', { lineHeight: '22px' }],
                'lg':  ['17px', { lineHeight: '24px', letterSpacing: '-0.01em' }],
                'xl':  ['20px', { lineHeight: '26px', letterSpacing: '-0.015em' }],
                '2xl': ['24px', { lineHeight: '30px', letterSpacing: '-0.02em' }],
                '3xl': ['30px', { lineHeight: '36px', letterSpacing: '-0.02em' }],
                '4xl': ['38px', { lineHeight: '44px', letterSpacing: '-0.025em' }],
            },
        },
    },

    plugins: [forms],
};
