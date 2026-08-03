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

const empresa   = meta('app-empresa') || 'SGI';
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
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });
        return pages[`./Pages/${name}.vue`];
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
