import '../css/app.css';
import './bootstrap';
import { registerSW } from 'virtual:pwa-register';

import { createInertiaApp } from '@inertiajs/vue3';
import { createApp, h } from 'vue';
import { colorMarca } from '@/marca';

// El título de la pestaña sale de Ajustes > Marca, que app.blade.php deja en
// un par de <meta>.
//
// Antes esto leía VITE_APP_NAME, que se congela al compilar: como el CI compila
// sin el .env del servidor, quedaba pegado en "Laravel" y así salía en las
// pestañas del navegador. Leerlo del <meta> lo hace un valor de tiempo de
// ejecución: se cambia desde la app y no hay que recompilar nada.
const meta = (nombre) => document.querySelector(`meta[name="${nombre}"]`)?.content ?? '';

const empresa   = meta('app-empresa') || 'Briela';
const plantilla = meta('app-titulo-plantilla') || '{pagina} — {empresa}';

function armarTitulo(pagina) {
    return plantilla
        .replace('{pagina}', pagina ?? '')
        .replace('{empresa}', empresa)
        // Si la página no trae título propio, el separador queda huérfano
        // al principio o al final: lo recortamos en vez de mostrar "— ACME".
        .replace(/^[\s\-–—|·]+|[\s\-–—|·]+$/g, '')
        .trim();
}

createInertiaApp({
    title: armarTitulo,
    /**
     * Cada pantalla en su propio archivo, que se trae cuando se abre.
     *
     * Antes esto llevaba `eager: true`, y eso metía las 128 pantallas del sistema en un
     * solo archivo de 2,9 MB que el navegador tenía que descargar y procesar en CADA
     * primera carga — incluida la de entrada, donde no hace falta ninguna de ellas.
     * Medido en el servidor real: 655 KB transferidos y 1,6 segundos hasta que la
     * página respondía.
     *
     * Sin `eager` se sigue usando `import.meta.glob` y no `resolvePageComponent`: el
     * glob devuelve funciones en vez de módulos ya cargados, y se llama la que
     * corresponde. El costo es una descarga de unos kilobytes la primera vez que se
     * abre cada pantalla.
     */
    resolve: (name) => {
        const paginas = import.meta.glob('./Pages/**/*.vue');
        const cargar = paginas[`./Pages/${name}.vue`];

        if (! cargar) {
            // Sin esto el fallo es un componente vacío y una pantalla en blanco, sin
            // pista de qué pantalla falta.
            throw new Error(`No existe la pantalla Pages/${name}.vue`);
        }

        return cargar();
    },
    setup({ el, App, props, plugin }) {
        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
    progress: {
        // La barra de carga necesita un color real, no una variable CSS.
        color: colorMarca(),
    },
});

registerSW({ immediate: true });
