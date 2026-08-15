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
                // El separador entre filas: más suave que `linea`. Delimitar una tarjeta y
                // dejar seguir el renglón con la vista son dos cosas distintas, y cuando
                // pesan lo mismo la tabla se vuelve una reja. Antes esto era
                // `divide-gray-50` escrito a mano en 84 sitios — un gris casi blanco que
                // en modo noche dejaba líneas encendidas sobre fondo oscuro.
                separador: 'var(--separador)',
                // El realce del puntero sobre una fila. Un azul fijo funcionaba de día y
                // encendía la pantalla de noche.
                realce: 'var(--realce)',
                // Las familias de aviso. Cada una tiene fondo, fondo-2 (para la insignia que
                // va dentro de la caja), borde y texto, y las cuatro cambian con el tema: de
                // noche el pastel se vuelve un tinte oscuro y el texto se aclara.
                //
                // Antes esto se escribía con los colores fijos de Tailwind —`bg-red-50`
                // con `text-red-700`— y en modo noche eso daba una caja clara con texto
                // oscuro, o al revés: ilegible de las dos maneras.
                pastel: {
                    azul:    'var(--pastel-azul)',
                    'azul-2':    'var(--pastel-azul-2)',
                    verde:   'var(--pastel-verde)',
                    'verde-2':   'var(--pastel-verde-2)',
                    ambar:   'var(--pastel-ambar)',
                    'ambar-2':   'var(--pastel-ambar-2)',
                    rojo:    'var(--pastel-rojo)',
                    'rojo-2':    'var(--pastel-rojo-2)',
                    violeta: 'var(--pastel-violeta)',
                    'violeta-2': 'var(--pastel-violeta-2)',
                    naranja: 'var(--pastel-naranja)',
                    'naranja-2': 'var(--pastel-naranja-2)',
                },
                aviso: {
                    azul:    'var(--texto-azul)',
                    verde:   'var(--texto-verde)',
                    ambar:   'var(--texto-ambar)',
                    rojo:    'var(--texto-rojo)',
                    violeta: 'var(--texto-violeta)',
                    naranja: 'var(--texto-naranja)',
                },
                'borde-aviso': {
                    azul:    'var(--borde-azul)',
                    verde:   'var(--borde-verde)',
                    ambar:   'var(--borde-ambar)',
                    rojo:    'var(--borde-rojo)',
                    violeta: 'var(--borde-violeta)',
                    naranja: 'var(--borde-naranja)',
                },
                lienzo: 'var(--fondo)',
                // Las superficies: lo que en modo día es blanco y de noche es un
                // gris muy oscuro. `text-white` se deja como blanco real, porque
                // casi siempre va encima del color de la marca.
                superficie: {
                    DEFAULT: 'var(--superficie)',
                    2: 'var(--superficie-2)',
                },

                // Los tonos 50 y 100 de los colores de aviso salen de variables:
                // son los que se usan como fondo de cajas informativas, y en el
                // modo de noche una caja celeste con texto claro encima es
                // ilegible. Son 505 sitios, así que cambiarlos a mano no era opción.
                //
                // La escala se declara COMPLETA a propósito: al redefinir un color
                // en Tailwind se reemplaza toda su escala, y con solo el 50 y el 100
                // se romperían los blue-600, red-700 y demás, que sí se usan.
                blue: {
                    50: 'var(--pastel-azul)', 100: 'var(--pastel-azul-2)', 200: '#B2DDFF',
                    300: '#84CAFF', 400: '#53B1FD', 500: '#2E90FA', 600: '#1570EF',
                    700: '#175CD3', 800: '#1849A9', 900: '#194185',
                },
                green: {
                    50: 'var(--pastel-verde)', 100: '#D1FADF', 200: '#A6F4C5',
                    300: '#6CE9A6', 400: '#32D583', 500: '#12B76A', 600: '#039855',
                    700: '#027A48', 800: '#05603A', 900: '#054F31',
                },
                emerald: {
                    50: 'var(--pastel-verde)', 100: '#D1FADF', 700: '#027A48', 800: '#05603A',
                },
                amber: {
                    50: 'var(--pastel-ambar)', 100: '#FEF0C7', 200: '#FEDF89',
                    300: '#FEC84B', 400: '#FDB022', 500: '#F79009', 600: '#DC6803',
                    700: '#B54708', 800: '#93370D', 900: '#7A2E0E',
                },
                yellow: {
                    50: 'var(--pastel-ambar)', 100: '#FEF0C7', 200: '#FEDF89',
                    300: '#FEC84B', 400: '#FDB022', 500: '#F79009', 600: '#DC6803',
                    700: '#B54708', 800: '#93370D',
                },
                red: {
                    50: 'var(--pastel-rojo)', 100: '#FEE4E2', 200: '#FECDCA',
                    300: '#FDA29B', 400: '#F97066', 500: '#F04438', 600: '#D92D20',
                    700: '#B42318', 800: '#912018',
                },
                purple: {
                    50: 'var(--pastel-violeta)', 100: '#EBE9FE', 200: '#D9D6FE',
                    300: '#BDB4FE', 400: '#9B8AFB', 500: '#7A5AF8', 600: '#6938EF',
                    700: '#5925DC', 800: '#4A1FB8',
                },
                violet: {
                    50: 'var(--pastel-violeta)', 100: '#EBE9FE', 700: '#5925DC', 800: '#4A1FB8',
                },
                orange: {
                    50: 'var(--pastel-naranja)', 100: '#FDEAD7', 200: '#F9DBAF',
                    300: '#F7B27A', 400: '#F38744', 500: '#EF6820', 600: '#E04F16',
                    700: '#B93815', 800: '#932F19',
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
