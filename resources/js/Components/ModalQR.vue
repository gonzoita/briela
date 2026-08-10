<template>
    <Teleport to="body">
        <div
            v-if="show"
            class="fixed inset-0 z-50 flex items-end md:items-center justify-center bg-black/70 backdrop-blur-sm"
            @click.self="cerrar"
        >
            <div class="bg-superficie w-full max-w-sm rounded-t-2xl md:rounded-2xl shadow-2xl overflow-hidden">

                <!-- Header -->
                <div class="flex items-center justify-between px-5 py-4 bg-[var(--marca)]">
                    <div>
                        <h3 class="text-white font-semibold text-base">Lector QR</h3>
                        <p class="text-white/60 text-xs mt-0.5">Apunta la cámara al código</p>
                    </div>
                    <button
                        @click="cerrar"
                        class="w-8 h-8 flex items-center justify-center text-white/70 hover:text-white rounded-lg hover:bg-superficie/10 transition-colors"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
                            <path d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <!-- Scanner area -->
                <div class="p-4">
                    <div v-if="error" class="text-center py-8">
                        <svg class="w-12 h-12 text-red-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-width="1.5" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                        </svg>
                        <p class="text-red-500 font-medium text-sm">{{ error }}</p>
                        <button
                            @click="iniciarScanner"
                            class="mt-3 px-4 py-2 bg-[var(--marca)] text-white rounded-xl text-sm font-medium"
                        >
                            Reintentar
                        </button>
                    </div>

                    <div v-else>
                        <div
                            id="qr-reader"
                            class="w-full rounded-xl overflow-hidden"
                            style="min-height: 250px;"
                        />
                        <p class="text-center text-xs text-tinta-300 mt-3">
                            Asegúrate de dar permiso de cámara
                        </p>
                    </div>

                    <!-- Resultado -->
                    <div
                        v-if="resultado"
                        class="mt-4 p-3 bg-green-50 border border-green-200 rounded-xl"
                    >
                        <p class="text-xs text-green-600 font-medium mb-1">✓ Código detectado</p>
                        <p class="text-sm text-green-800 font-mono break-all">{{ resultado }}</p>
                        <div class="flex gap-2 mt-3">
                            <button
                                @click="copiar"
                                class="flex-1 py-2 border border-green-300 text-green-700 rounded-lg text-sm font-medium hover:bg-green-100"
                            >
                                Copiar
                            </button>
                            <button
                                v-if="resultado.startsWith('IF-') || resultado.startsWith('OP-')"
                                @click="buscarOP"
                                class="flex-1 py-2 bg-[var(--marca)] text-white rounded-lg text-sm font-medium"
                            >
                                Abrir en SGI
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<script setup>
import { ref, watch, onUnmounted } from 'vue'
import { router } from '@inertiajs/vue3'
import { useClipboard } from '@/composables/useClipboard'

const { copyText } = useClipboard()

const props = defineProps({ show: Boolean })
const emit  = defineEmits(['close'])

const error     = ref(null)
const resultado = ref(null)
let html5QrCode = null

watch(() => props.show, async (val) => {
    if (val) {
        resultado.value = null
        error.value = null
        await new Promise(r => setTimeout(r, 300))
        iniciarScanner()
    } else {
        detenerScanner()
    }
})

async function iniciarScanner() {
    try {
        error.value = null
        const { Html5Qrcode } = await import('html5-qrcode')

        if (html5QrCode) {
            try { await html5QrCode.stop() } catch (e) { /* ignorar */ }
        }

        html5QrCode = new Html5Qrcode('qr-reader')

        await html5QrCode.start(
            { facingMode: 'environment' },
            { fps: 10, qrbox: { width: 220, height: 220 } },
            (decodedText) => {
                resultado.value = decodedText
                detenerScanner()
            },
            () => {}
        )
    } catch (e) {
        if (e.toString().includes('permission') || e.toString().includes('Permission')) {
            error.value = 'Permiso de cámara denegado. Habilítalo en configuración.'
        } else {
            error.value = 'No se pudo iniciar la cámara: ' + e.toString()
        }
    }
}

async function detenerScanner() {
    if (html5QrCode) {
        try {
            const state = html5QrCode.getState()
            if (state === 2) await html5QrCode.stop()
        } catch (e) { /* ignorar */ }
        html5QrCode = null
    }
}

function cerrar() {
    detenerScanner()
    resultado.value = null
    error.value = null
    emit('close')
}

function copiar() {
    copyText(resultado.value)
}

function buscarOP() {
    const codigo = resultado.value
    cerrar()
    if (codigo.startsWith('OP-')) {
        router.visit('/produccion/ops?buscar=' + codigo)
    } else if (codigo.startsWith('IF-')) {
        router.visit('/seguimiento/' + codigo)
    }
}

onUnmounted(() => detenerScanner())
</script>
