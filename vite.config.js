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
                    { src: '/icons/icon-192.png', sizes: '192x192', type: 'image/png', purpose: 'any maskable' },
                    { src: '/icons/icon-384.png', sizes: '384x384', type: 'image/png' },
                    { src: '/icons/icon-512.png', sizes: '512x512', type: 'image/png', purpose: 'any maskable' },
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
                runtimeCaching: [
                    {
                        urlPattern: ({ url }) => url.pathname.startsWith('/dashboard'),
                        handler: 'NetworkFirst',
                        options: {
                            cacheName: 'pages-cache',
                            expiration: { maxEntries: 20, maxAgeSeconds: 86400 },
                            networkTimeoutSeconds: 5,
                        },
                    },
                    {
                        urlPattern: ({ url }) => url.pathname.startsWith('/produccion'),
                        handler: 'NetworkFirst',
                        options: {
                            cacheName: 'ops-cache',
                            expiration: { maxEntries: 50, maxAgeSeconds: 3600 },
                            networkTimeoutSeconds: 5,
                        },
                    },
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

