import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import vue from '@vitejs/plugin-vue'
import { VitePWA } from 'vite-plugin-pwa'

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.js'],
            refresh: true,
        }),
        vue(),
        VitePWA({
            strategies: 'generateSW',
            registerType: 'autoUpdate',
            injectRegister: 'auto',
            includeAssets: [
                'favicon.ico',
                'apple-touch-icon.png',
                'icons/*.png',
            ],
            manifest: {
                name: 'Briela',
                short_name: 'Briela',
                description: 'Sistema de gestión integral para empresas de fabricación',
                // Azul neutro, el mismo de fábrica de App\Support\Marca. No
                // puede ser el color de una empresa concreta: la PWA se
                // instala en el celular de cada cliente.
                theme_color: '#2563EB',
                background_color: '#2563EB',
                display: 'standalone',
                orientation: 'portrait-primary',
                scope: '/',
                start_url: '/dashboard',
                lang: 'es',
                categories: ['business', 'productivity'],
                icons: [
                    { src: '/icons/icon-72.png',  sizes: '72x72',   type: 'image/png' },
                    { src: '/icons/icon-96.png',  sizes: '96x96',   type: 'image/png' },
                    { src: '/icons/icon-128.png', sizes: '128x128', type: 'image/png' },
                    { src: '/icons/icon-144.png', sizes: '144x144', type: 'image/png' },
                    { src: '/icons/icon-152.png', sizes: '152x152', type: 'image/png' },
                    { src: '/icons/icon-192.png', sizes: '192x192', type: 'image/png' },
                    { src: '/icons/icon-384.png', sizes: '384x384', type: 'image/png' },
                    { src: '/icons/icon-512.png', sizes: '512x512', type: 'image/png' },
                    // Los maskable van aparte, no marcando "any maskable" en el
                    // mismo archivo: Android recorta el icono con su propia
                    // máscara, y uno que ya viene con esquinas redondeadas queda
                    // con doble borde o con el logo mordido. Estos llenan el
                    // cuadrado y dejan el logo en la zona segura.
                    { src: '/icons/icon-192-maskable.png', sizes: '192x192', type: 'image/png', purpose: 'maskable' },
                    { src: '/icons/icon-512-maskable.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
                ],
                shortcuts: [
                    {
                        name: 'Nueva OP',
                        short_name: 'Nueva OP',
                        url: '/produccion/ops/create',
                        icons: [{ src: '/icons/icon-96.png', sizes: '96x96' }],
                    },
                    {
                        name: 'Ver OPs',
                        short_name: 'OPs',
                        url: '/produccion/ops',
                        icons: [{ src: '/icons/icon-96.png', sizes: '96x96' }],
                    },
                ],
            },
            workbox: {
                maximumFileSizeToCacheInBytes: 3 * 1024 * 1024,
                globPatterns: ['**/*.{js,css,html,ico,png,svg,woff2}'],
                navigateFallback: null,
                // Las paginas NO se cachean.
                //
                // Estuvieron con NetworkFirst sobre /dashboard y /produccion, y eso guarda el
                // HTML de la pantalla — que apunta a archivos con hash en el nombre—. Tras un
                // despliegue esos archivos ya no existen: el HTML viejo pedia un archivo
                // borrado, la importacion fallaba y la pantalla quedaba EN NEGRO. Un ERP
                // autenticado no gana nada sirviendo su HTML sin conexion: sin servidor no hay
                // datos que mostrar.
                cleanupOutdatedCaches: true,
                runtimeCaching: [
                    {
                        urlPattern: ({ url }) => url.pathname.startsWith('/storage'),
                        handler: 'CacheFirst',
                        options: {
                            cacheName: 'multimedia-cache',
                            expiration: { maxEntries: 100, maxAgeSeconds: 604800 },
                        },
                    },
                ],
            },
            devOptions: {
                enabled: false,
                type: 'module',
            },
        }),
    ],
    resolve: {
        alias: { '@': '/resources/js' },
    },
})

