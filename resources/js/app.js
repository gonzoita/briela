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

        return cargar().catch((error) => {
            // **La pantalla en negro después de un despliegue.**
            //
            // Cada pantalla es su propio archivo con un nombre que incluye un hash, y al
            // desplegar cambian todos. Una pestaña que quedó abierta —o el service worker con
            // su copia vieja— sigue pidiendo el archivo anterior, que ya no está en el
            // servidor: la importación falla, Inertia no tiene qué dibujar y el área de la
            // página queda vacía. En negro, sin un mensaje que lo explique.
            //
            // Recargar una vez trae el HTML nuevo, con los nombres nuevos. La marca en
            // `sessionStorage` evita el bucle si el fallo era otro y la recarga no lo cura.
            const clave = 'briela:recarga-por-version';

            if (! sessionStorage.getItem(clave)) {
                sessionStorage.setItem(clave, '1');
                window.location.reload();

                // No se resuelve a propósito: la página se está yendo, y devolver un
                // componente vacío pintaría el negro que estamos evitando.
                return new Promise(() => {});
            }

            throw error;
        });
    },
    setup({ el, App, props, plugin }) {
        // Se montó: lo que sea que hubiera fallado, ya no falla. Se borra la marca para que
        // el próximo despliegue pueda recuperarse igual.
        sessionStorage.removeItem('briela:recarga-por-version');

        const app = createApp({ render: () => h(App, props) });

        /**
         * Un error al dibujar no puede quedarse en una pantalla vacía.
         *
         * Vue, cuando el render revienta, desmonta el árbol y deja el hueco: negro, sin una
         * palabra. Pasó de verdad —una pantalla llamaba `.includes()` sobre algo que no era
         * una lista— y desde el chat es indistinguible de «no se desplegó». Ahora el error se
         * ve, con el nombre del archivo, que es lo único que hace falta para arreglarlo.
         */
        app.config.errorHandler = (error, instancia, info) => {
            console.error('[Briela] error al dibujar la pantalla:', error, info);

            const caja = document.createElement('div');
            caja.style.cssText = 'position:fixed;left:0;right:0;bottom:0;z-index:9999;padding:14px 18px;'
                + 'background:#7f1d1d;color:#fff;font:13px/1.5 system-ui;box-shadow:0 -2px 12px rgba(0,0,0,.35)';
            caja.textContent = 'Esta pantalla no se pudo dibujar: ' + (error?.message ?? error)
                + ' — dile esto a soporte, o pulsa «Forzar actualización» en el menú.';
            document.body.appendChild(caja);
        };

        return app.use(plugin).mount(el);
    },
    progress: {
        // La barra de carga necesita un color real, no una variable CSS.
        color: colorMarca(),
    },
});

registerSW({ immediate: true });
