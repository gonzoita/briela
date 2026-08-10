<template>
    <Teleport to="body">
        <div
            v-if="show"
            class="fixed inset-0 z-50 flex items-end md:items-center justify-center bg-black/60 backdrop-blur-sm"
            @click.self="cerrar"
        >
            <div class="bg-superficie w-full max-w-md rounded-t-2xl md:rounded-2xl shadow-2xl overflow-hidden">

                <!-- Header -->
                <div class="flex items-center justify-between px-5 py-4 bg-[var(--marca)]">
                    <div>
                        <h3 class="text-white font-semibold text-base">Subir archivo</h3>
                        <p class="text-white/60 text-xs mt-0.5">PDF, imágenes, documentos · Máx 20 MB</p>
                    </div>
                    <button @click="cerrar" class="w-8 h-8 flex items-center justify-center text-white/70 hover:text-white rounded-lg hover:bg-white/10 transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
                            <path d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <div class="p-5 space-y-4">

                    <!-- Zona drag & drop -->
                    <div
                        class="border-2 border-dashed rounded-2xl p-8 text-center cursor-pointer transition-colors"
                        :class="archivoSeleccionado ? 'border-[var(--marca)] bg-blue-50' : 'border-linea hover:border-[var(--marca)] hover:bg-blue-50'"
                        @dragover.prevent
                        @drop.prevent="onDrop"
                        @click="$refs.inputArchivo.click()"
                    >
                        <input ref="inputArchivo" type="file" class="hidden" @change="onFileChange" />

                        <div v-if="!archivoSeleccionado">
                            <svg class="w-10 h-10 text-tinta-200 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/>
                            </svg>
                            <p class="text-sm text-tinta-500 font-medium">Arrastra aquí o haz clic para seleccionar</p>
                            <p class="text-xs text-tinta-300 mt-1">PDF, JPG, PNG, DOCX · Máx 20 MB</p>
                        </div>

                        <div v-else class="flex items-center gap-3">
                            <div class="w-12 h-12 rounded-xl bg-blue-100 flex items-center justify-center shrink-0">
                                <img v-if="previewUrl" :src="previewUrl" class="w-12 h-12 rounded-xl object-cover"/>
                                <span v-else class="text-xs font-semibold text-blue-600 uppercase">{{ extension }}</span>
                            </div>
                            <div class="flex-1 min-w-0 text-left">
                                <p class="text-sm font-medium text-tinta-900 truncate">{{ archivoSeleccionado.name }}</p>
                                <p class="text-xs text-tinta-300">{{ tamanoFormateado }}</p>
                            </div>
                            <button @click.stop="limpiar" class="text-tinta-300 hover:text-red-500 transition-colors">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <!-- Categoría -->
                    <div>
                        <label class="block text-sm font-medium text-tinta-700 mb-1.5">Categoría</label>
                        <select v-model="categoria" class="w-full border border-linea rounded-xl px-4 py-2.5 text-sm bg-superficie focus:outline-none focus:ring-2">
                            <option value="plano">Plano</option>
                            <option value="foto_calidad">Foto de calidad</option>
                            <option value="documento">Documento</option>
                            <option value="otro">Otro</option>
                        </select>
                    </div>

                    <!-- Descripción -->
                    <div>
                        <label class="block text-sm font-medium text-tinta-700 mb-1.5">Descripción <span class="text-tinta-300 font-normal">(opcional)</span></label>
                        <input
                            v-model="descripcion"
                            type="text"
                            placeholder="Ej: Plano de fachada norte"
                            class="w-full border border-linea rounded-xl px-4 py-2.5 text-sm bg-superficie focus:outline-none focus:ring-2"
                        />
                    </div>

                    <!-- Barra de progreso -->
                    <div v-if="subiendo" class="space-y-1">
                        <div class="flex justify-between text-xs text-tinta-400">
                            <span>Subiendo...</span>
                            <span>{{ progreso }}%</span>
                        </div>
                        <div class="w-full bg-tinta-100 rounded-full h-2">
                            <div
                                class="h-2 rounded-full transition-all duration-200 bg-[var(--marca)]"
                                :style="{ width: progreso + '%' }"
                            />
                        </div>
                    </div>

                    <!-- Error -->
                    <p v-if="errorMsg" class="text-sm text-red-600 bg-red-50 rounded-xl px-3 py-2">{{ errorMsg }}</p>

                </div>

                <!-- Footer -->
                <div class="border-t border-linea px-5 py-4 flex items-center justify-between">
                    <button @click="cerrar" class="text-sm font-medium text-tinta-400 hover:text-tinta-700 transition-colors">
                        Cancelar
                    </button>
                    <button
                        @click="subir"
                        :disabled="!archivoSeleccionado || subiendo"
                        class="px-6 py-2.5 rounded-xl text-white text-sm font-semibold bg-[var(--marca)] hover:opacity-90 transition-opacity disabled:opacity-40"
                    >
                        {{ subiendo ? 'Subiendo...' : 'Subir archivo' }}
                    </button>
                </div>

            </div>
        </div>
    </Teleport>
</template>

<script setup>
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'

const props = defineProps({
    show:  Boolean,
    opId:  { type: [Number, String], default: null },
})
const emit = defineEmits(['close', 'subido'])

const inputArchivo      = ref(null)
const archivoSeleccionado = ref(null)
const previewUrl        = ref(null)
const categoria         = ref('otro')
const descripcion       = ref('')
const progreso          = ref(0)
const subiendo          = ref(false)
const errorMsg          = ref(null)

const extension = computed(() => {
    if (!archivoSeleccionado.value) return ''
    const parts = archivoSeleccionado.value.name.split('.')
    return parts[parts.length - 1]?.toLowerCase() ?? ''
})

const tamanoFormateado = computed(() => {
    const b = archivoSeleccionado.value?.size ?? 0
    if (b < 1024)    return b + ' B'
    if (b < 1048576) return (b / 1024).toFixed(1) + ' KB'
    return (b / 1048576).toFixed(1) + ' MB'
})

function onFileChange(e) {
    seleccionarArchivo(e.target.files[0])
}

function onDrop(e) {
    seleccionarArchivo(e.dataTransfer.files[0])
}

function seleccionarArchivo(file) {
    if (!file) return
    archivoSeleccionado.value = file
    errorMsg.value = null
    if (file.type.startsWith('image/')) {
        const reader = new FileReader()
        reader.onload = e => { previewUrl.value = e.target.result }
        reader.readAsDataURL(file)
    } else {
        previewUrl.value = null
    }
}

function limpiar() {
    archivoSeleccionado.value = null
    previewUrl.value = null
    if (inputArchivo.value) inputArchivo.value.value = ''
}

async function subir() {
    if (!archivoSeleccionado.value) return
    subiendo.value = true
    progreso.value = 0
    errorMsg.value = null

    const formData = new FormData()
    formData.append('archivo',     archivoSeleccionado.value)
    formData.append('categoria',   categoria.value)
    formData.append('descripcion', descripcion.value)
    if (props.opId) formData.append('op_id', props.opId)

    try {
        await axios.post('/multimedia', formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
            onUploadProgress: e => {
                progreso.value = Math.round((e.loaded / e.total) * 100)
            },
        })
        emit('subido')
        cerrar()
    } catch (e) {
        errorMsg.value = e.response?.data?.message ?? 'Error al subir el archivo.'
    } finally {
        subiendo.value = false
    }
}

function cerrar() {
    limpiar()
    descripcion.value = ''
    categoria.value   = 'otro'
    errorMsg.value    = null
    progreso.value    = 0
    emit('close')
}
</script>
