<script setup>
/**
 * Pedir una foto: del archivo o tomada en el momento.
 *
 * Hay puntos de revisión que no se pueden dar por buenos sin evidencia — son justo los que
 * después se discuten con el cliente, y ahí una palabra contra otra no resuelve nada. Cuando
 * uno de esos se marca, esta hoja se interpone y no deja seguir sin la foto.
 *
 * Las dos entradas importan y por eso están las dos: en planta se toma con el celular, y en la
 * oficina se sube la que ya alguien mandó. El `capture` del navegador solo cubre la primera.
 */
import { ref, watch, onUnmounted, nextTick } from 'vue'

const props = defineProps({
    abierto:     { type: Boolean, default: false },
    titulo:      { type: String,  default: 'Foto de evidencia' },
    descripcion: { type: String,  default: '' },
    guardando:   { type: Boolean, default: false },
})

const emit = defineEmits(['confirmar', 'cerrar'])

const video   = ref(null)
const canvas  = ref(null)
const preview = ref(null)      // la URL para mirar
const archivo = ref(null)      // el File que se va a subir
const stream  = ref(null)
const camaraViva = ref(false)
const error   = ref('')

function detener() {
    stream.value?.getTracks().forEach(t => t.stop())
    stream.value = null
    camaraViva.value = false
}

async function abrirCamara() {
    error.value = ''
    preview.value = null
    archivo.value = null
    camaraViva.value = true

    await nextTick()

    try {
        const s = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'environment', width: { ideal: 1600 }, height: { ideal: 1200 } },
        })
        stream.value = s
        if (video.value) { video.value.srcObject = s; await video.value.play() }
    } catch {
        camaraViva.value = false
        // Un computador de escritorio sin cámara es normal, no un error del sistema: se dice
        // qué pasó y queda el camino del archivo, que sí sirve ahí.
        error.value = 'No se pudo abrir la cámara. Sube la foto desde un archivo.'
    }
}

function capturar() {
    const v = video.value, c = canvas.value
    if (! v || ! c) return

    c.width  = v.videoWidth
    c.height = v.videoHeight
    c.getContext('2d').drawImage(v, 0, 0)

    c.toBlob(blob => {
        if (! blob) return
        archivo.value = new File([blob], `calidad-${Date.now()}.jpg`, { type: 'image/jpeg' })
        preview.value = URL.createObjectURL(blob)
        detener()
    }, 'image/jpeg', 0.85)
}

function elegirArchivo(evento) {
    const f = evento.target.files?.[0]
    if (! f) return

    detener()
    error.value   = ''
    archivo.value = f
    preview.value = URL.createObjectURL(f)
    evento.target.value = ''
}

function repetir() {
    preview.value = null
    archivo.value = null
    abrirCamara()
}

function confirmar() {
    if (! archivo.value) return
    emit('confirmar', archivo.value)
}

function cerrar() {
    detener()
    preview.value = null
    archivo.value = null
    error.value   = ''
    emit('cerrar')
}

// Al abrirse se intenta la cámara de una: en planta es lo que se quiere el 90 % de las veces,
// y si no hay, el mensaje deja el archivo a un toque.
watch(() => props.abierto, (v) => {
    if (v) abrirCamara()
    else   detener()
})

onUnmounted(detener)
</script>

<template>
    <Teleport to="body">
        <div v-if="abierto" class="fixed inset-0 z-[70] flex items-end md:items-center justify-center p-0 md:p-6"
             style="background: rgba(16,24,40,.55); backdrop-filter: blur(4px);"
             @click.self="cerrar">

            <div class="w-full md:max-w-lg bg-superficie rounded-t-3xl md:rounded-3xl shadow-flotante overflow-hidden max-h-[92vh] flex flex-col">

                <div class="px-5 py-4 border-b border-linea flex items-start justify-between gap-3 shrink-0">
                    <div class="min-w-0">
                        <h3 class="text-base font-semibold text-tinta-900 truncate">{{ titulo }}</h3>
                        <p v-if="descripcion" class="text-xs text-tinta-400 mt-0.5">{{ descripcion }}</p>
                    </div>
                    <button type="button" @click="cerrar" class="shrink-0 text-tinta-300 hover:text-tinta-700 transition-colors" aria-label="Cerrar">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <div class="p-5 overflow-y-auto">
                    <p v-if="error"
                       class="text-xs text-aviso-ambar bg-pastel-ambar border border-borde-aviso-ambar rounded-xl px-3 py-2 mb-3">
                        {{ error }}
                    </p>

                    <!-- Lo que se va a subir -->
                    <div v-if="preview" class="rounded-2xl overflow-hidden border border-linea bg-lienzo">
                        <img :src="preview" alt="Foto tomada" class="w-full max-h-[46vh] object-contain" />
                    </div>

                    <!-- La cámara en vivo -->
                    <div v-else-if="camaraViva" class="rounded-2xl overflow-hidden border border-linea bg-black">
                        <video ref="video" playsinline muted class="w-full max-h-[46vh] object-contain"></video>
                    </div>

                    <!-- Ni una ni otra: solo queda el archivo -->
                    <div v-else class="rounded-2xl border border-dashed border-linea py-10 text-center">
                        <svg class="w-10 h-10 mx-auto text-tinta-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.4">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h1.6a2 2 0 001.7-.9l.7-1.1a2 2 0 011.7-1h2.6a2 2 0 011.7 1l.7 1.1a2 2 0 001.7.9H19a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/>
                            <circle cx="12" cy="13" r="3.2"/>
                        </svg>
                        <p class="text-sm text-tinta-400 mt-2">Toma la foto o súbela desde un archivo.</p>
                    </div>

                    <canvas ref="canvas" class="hidden"></canvas>

                    <div class="flex flex-wrap items-center gap-2 mt-4">
                        <button v-if="camaraViva && ! preview" type="button" @click="capturar"
                            class="flex-1 min-w-[140px] py-3 rounded-xl text-sm font-semibold text-white"
                            style="background: var(--marca);">
                            Tomar foto
                        </button>

                        <button v-if="preview" type="button" @click="repetir"
                            class="flex-1 min-w-[120px] py-3 rounded-xl text-sm font-medium border border-linea text-tinta-600 hover:bg-realce transition-colors">
                            Repetir
                        </button>

                        <button v-if="! camaraViva && ! preview" type="button" @click="abrirCamara"
                            class="flex-1 min-w-[140px] py-3 rounded-xl text-sm font-semibold text-white"
                            style="background: var(--marca);">
                            Abrir cámara
                        </button>

                        <label class="flex-1 min-w-[120px] py-3 rounded-xl text-sm font-medium border border-linea text-tinta-600 text-center cursor-pointer hover:bg-realce transition-colors">
                            Subir archivo
                            <input type="file" accept="image/*" class="hidden" @change="elegirArchivo" />
                        </label>
                    </div>
                </div>

                <div class="px-5 py-4 border-t border-linea flex items-center gap-2 shrink-0"
                     style="padding-bottom: calc(16px + env(safe-area-inset-bottom));">
                    <button type="button" @click="cerrar"
                        class="px-4 py-2.5 rounded-xl text-sm font-medium text-tinta-500 hover:bg-realce transition-colors">
                        Cancelar
                    </button>
                    <button type="button" @click="confirmar" :disabled="! archivo || guardando"
                        class="flex-1 py-2.5 rounded-xl text-sm font-semibold text-white disabled:opacity-40 transition-opacity"
                        style="background: var(--marca);">
                        {{ guardando ? 'Guardando…' : 'Usar esta foto' }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
