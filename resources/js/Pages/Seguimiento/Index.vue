<script setup>
import { ref, onMounted } from 'vue'
import { router, usePage, Head } from '@inertiajs/vue3'
import { Html5Qrcode } from 'html5-qrcode'

const codigo      = ref('')
const verificacion = ref('')
const modalQR     = ref(false)
const flash       = usePage().props.flash

let qrScanner = null

const consultar = () => {
    if (!codigo.value.trim() || !verificacion.value.trim()) return
    const v = encodeURIComponent(verificacion.value.trim())
    router.visit(`/seguimiento/${codigo.value.trim()}?v=${v}`)
}

const abrirQR = async () => {
    modalQR.value = true
    await new Promise(r => setTimeout(r, 100))
    try {
        qrScanner = new Html5Qrcode('qr-seguimiento')
        await qrScanner.start(
            { facingMode: 'environment' },
            { fps: 10, qrbox: { width: 250, height: 250 } },
            (decoded) => {
                cerrarQR()
                // Las etiquetas/QR reales de las OP codifican un link completo
                // a /op/{token}, no un código para buscar acá — si el QR trae
                // una URL, se sigue directo en vez de tratarla como texto de
                // búsqueda (antes eso rompía la consulta).
                if (decoded.startsWith('http://') || decoded.startsWith('https://')) {
                    window.location.href = decoded
                    return
                }
                router.visit(`/seguimiento/${decoded.startsWith('/seguimiento/') ? decoded.replace('/seguimiento/', '') : decoded}`)
            },
            () => {}
        )
    } catch (e) {
        modalQR.value = false
    }
}

const cerrarQR = async () => {
    if (qrScanner) {
        try { await qrScanner.stop() } catch (e) { /* ignore */ }
        qrScanner = null
    }
    modalQR.value = false
}
</script>

<template>
    <Head title="Seguimiento de pedido" />

    <div class="min-h-screen flex flex-col" style="background: var(--superficie-2);">
        <!-- Header -->
        <header class="flex flex-col items-center pt-12 pb-8 px-6">
            <img
                :src="$page.props.marca.logo"
                class="h-14 w-auto object-contain mb-6"
                :alt="$page.props.marca.nombre"
            />
            <h1 class="text-2xl font-semibold text-tinta-900 text-center">Seguimiento de pedido</h1>
            <p class="text-tinta-400 text-sm mt-1 text-center">Consulta el estado de tu orden de producción</p>
        </header>

        <!-- Formulario -->
        <main class="flex-1 px-6 max-w-md mx-auto w-full">
            <!-- Flash error -->
            <div
                v-if="flash?.error"
                class="mb-4 px-4 py-3 rounded-xl text-sm text-aviso-rojo"
                style="background: var(--pastel-rojo); border-left: 4px solid #EF4444;"
            >
                {{ flash.error }}
            </div>

            <div class="rounded-2xl shadow-sm p-6" style="background: var(--superficie);">
                <label class="block text-sm font-medium text-tinta-700 mb-2">
                    Número de OP o código de serie
                </label>
                <input
                    v-model="codigo"
                    type="text"
                    placeholder="OP-045 ó IF-2026-045-P-001"
                    class="w-full rounded-xl border px-4 py-3 text-sm focus:outline-none focus:ring-2"
                    style="border-color: #D1D5DB; focus-ring-color: var(--marca);"
                    @keyup.enter="consultar"
                />

                <label class="block text-sm font-medium text-tinta-700 mb-2 mt-4">
                    Apellido o documento del cliente
                </label>
                <input
                    v-model="verificacion"
                    type="text"
                    placeholder="Ej: González ó 1020304050"
                    class="w-full rounded-xl border px-4 py-3 text-sm focus:outline-none focus:ring-2"
                    style="border-color: #D1D5DB; focus-ring-color: var(--marca);"
                    @keyup.enter="consultar"
                />
                <p class="text-xs text-tinta-300 mt-1.5">
                    Por seguridad, pedimos un dato del cliente tal como figura en la orden.
                </p>

                <button
                    @click="consultar"
                    :disabled="!codigo.trim() || !verificacion.trim()"
                    class="mt-4 w-full py-3 rounded-xl font-semibold text-white text-sm disabled:opacity-50"
                    style="background-color: var(--marca);"
                >
                    Consultar
                </button>

                <button
                    @click="abrirQR"
                    class="mt-3 w-full py-3 rounded-xl font-semibold text-sm flex items-center justify-center gap-2 border"
                    style="color: var(--marca); border-color: var(--marca);"
                >
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z" />
                    </svg>
                    Escanear QR
                </button>
            </div>
        </main>

        <!-- Footer -->
        <footer class="px-6 py-8 text-center">
            <p class="text-xs text-tinta-300">{{ $page.props.marca.nombre }}</p>
            <p v-if="$page.props.marca.email" class="text-xs text-tinta-300 mt-1">{{ $page.props.marca.email }}</p>
        </footer>
    </div>

    <!-- Modal QR -->
    <teleport to="body">
        <div
            v-if="modalQR"
            class="fixed inset-0 z-50 flex flex-col items-center justify-center"
            style="background: black;"
        >
            <button
                @click="cerrarQR"
                class="absolute top-4 right-4 w-10 h-10 rounded-full flex items-center justify-center"
                style="background: rgba(255,255,255,0.2);"
            >
                <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
            <div id="qr-seguimiento" class="rounded-xl overflow-hidden" style="width: 280px;" />
            <p class="text-white mt-6 text-sm">Apunta al código QR</p>
        </div>
    </teleport>
</template>
