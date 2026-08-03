<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PasoFotos from '@/Components/PasoFotos.vue'

const props = defineProps({
    trabajo:         Object,
    operario_id:     Number,
    operario_nombre: String,
    operarios:       Array,
})

const BADGE = {
    borrador:      { bg: '#F3F4F6', text: '#374151' },
    confirmada:    { bg: '#DBEAFE', text: '#1D4ED8' },
    en_produccion: { bg: '#FEF3C7', text: '#92400E' },
    calidad:       { bg: '#E9D5FF', text: '#6B21A8' },
    despachada:    { bg: '#D1FAE5', text: '#065F46' },
}

const modalPaso = ref(null)
const operariosModal = ref([])
const guardando = ref(false)

function abrirModal(paso) {
    if (paso.completado) return
    modalPaso.value = paso
    operariosModal.value = [{ operario_id: props.operario_id ?? null, tiempo_minutos: null, observaciones: '' }]
}

function cerrarModal() {
    modalPaso.value = null
    operariosModal.value = []
}

function agregarOperario() {
    operariosModal.value.push({ operario_id: null, tiempo_minutos: null, observaciones: '' })
}

function quitarOperario(idx) {
    if (operariosModal.value.length > 1) operariosModal.value.splice(idx, 1)
}

function completarPaso() {
    if (guardando.value) return
    guardando.value = true
    router.post(
        `/trabajo/${props.trabajo.token}/pasos/${modalPaso.value.id}/completar`,
        { operarios: operariosModal.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                cerrarModal()
                router.reload({ preserveScroll: true })
            },
            onFinish: () => { guardando.value = false },
        }
    )
}

function desmarcarPaso(paso) {
    if (!confirm(`¿Desmarcar el paso "${paso.nombre}"?`)) return
    router.post(
        `/trabajo/${props.trabajo.token}/pasos/${paso.id}/desmarcar`,
        {},
        {
            preserveScroll: true,
            onSuccess: () => router.reload({ preserveScroll: true }),
        }
    )
}
</script>

<template>
    <AppLayout :title="`Trabajo ${trabajo.op_numero}`">
        <div class="max-w-2xl mx-auto space-y-4">

            <!-- Cabecera -->
            <div class="flex items-start gap-3 mb-1">
                <a :href="`/produccion/ops/${trabajo.op_id}`"
                    class="mt-1 text-gray-400 hover:text-gray-700 shrink-0">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                    </svg>
                </a>
                <div class="flex-1 min-w-0">
                    <h1 class="text-xl font-bold text-gray-900 leading-tight">
                        {{ trabajo.item_descripcion }}
                    </h1>
                    <div class="flex items-center gap-2 mt-1 flex-wrap">
                        <span class="font-mono text-xs text-gray-500 bg-gray-100 px-2 py-0.5 rounded">
                            {{ trabajo.item_codigo }}
                        </span>
                        <span class="text-xs text-gray-500">
                            Unidad <strong>{{ trabajo.numero_unidad }}</strong>/{{ trabajo.total_unidades }}
                        </span>
                        <span class="text-xs px-2 py-0.5 rounded-full font-medium"
                            :style="`background:${BADGE[trabajo.op_estado]?.bg ?? '#F3F4F6'};color:${BADGE[trabajo.op_estado]?.text ?? '#374151'};`">
                            {{ trabajo.op_numero }}
                        </span>
                    </div>
                    <p v-if="trabajo.cliente_nombre" class="text-xs text-gray-400 mt-0.5">
                        {{ trabajo.cliente_nombre }}
                    </p>
                </div>
            </div>

            <!-- Progreso -->
            <div class="bg-white rounded-2xl border border-gray-200 p-4">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-sm font-semibold text-gray-700">
                        {{ trabajo.template_nombre ?? 'Progreso del trabajo' }}
                    </span>
                    <span class="text-lg font-bold" style="color:var(--marca);">
                        {{ parseFloat(trabajo.porcentaje_avance ?? 0).toFixed(1) }}%
                    </span>
                </div>
                <div class="h-3 rounded-full bg-gray-100 overflow-hidden">
                    <div class="h-full rounded-full transition-all duration-500"
                        :style="`width:${Math.min(parseFloat(trabajo.porcentaje_avance ?? 0), 100)}%; background:var(--marca);`">
                    </div>
                </div>
                <div class="flex justify-between text-xs text-gray-400 mt-1.5">
                    <span>{{ trabajo.pasos.filter(p => p.completado).length }} de {{ trabajo.pasos.length }} pasos completados</span>
                </div>
            </div>

            <!-- Variables del producto -->
            <div v-if="trabajo.variables_instancia && Object.keys(trabajo.variables_instancia).length"
                class="bg-white rounded-2xl border border-amber-200 overflow-hidden">
                <div class="px-4 py-3 border-b border-amber-100 bg-amber-50">
                    <p class="text-xs font-semibold text-amber-700 uppercase tracking-wider">Variables del producto</p>
                </div>
                <div class="p-4 space-y-3">
                    <div v-if="trabajo.campos_plantilla?.some(c => c.imagen_referencia)"
                        class="grid grid-cols-2 sm:grid-cols-3 gap-3 mb-3">
                        <div v-for="campo in trabajo.campos_plantilla.filter(c => c.imagen_referencia)"
                            :key="campo.nombre"
                            class="rounded-xl overflow-hidden border border-amber-200 bg-amber-50">
                            <a :href="campo.imagen_referencia" target="_blank" rel="noopener">
                                <img :src="campo.imagen_referencia"
                                    class="w-full h-32 object-contain bg-white hover:opacity-90 transition-opacity" />
                            </a>
                            <p class="text-xs text-amber-700 px-2 py-1.5">
                                {{ campo.etiqueta }}
                                <strong v-if="trabajo.variables_instancia[campo.nombre] !== undefined">
                                    = {{ trabajo.variables_instancia[campo.nombre] }}
                                </strong>
                            </p>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <span v-for="(val, key) in trabajo.variables_instancia" :key="key"
                            class="text-xs px-3 py-1.5 rounded-xl bg-amber-50 border border-amber-200 text-amber-800 font-medium">
                            {{ key }}: <strong>{{ val }}</strong>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Imágenes de instancia -->
            <div v-if="trabajo.imagenes_instancia?.length"
                class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-100">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Imágenes de referencia</p>
                </div>
                <div class="p-4 grid grid-cols-2 sm:grid-cols-3 gap-3">
                    <div v-for="(img, idx) in trabajo.imagenes_instancia" :key="idx"
                        class="rounded-xl overflow-hidden border border-gray-200 bg-gray-50">
                        <a :href="'/storage/' + img.ruta" target="_blank" rel="noopener">
                            <img :src="'/storage/' + img.ruta"
                                class="w-full h-28 object-contain bg-white hover:opacity-90 transition-opacity" />
                        </a>
                        <p v-if="img.titulo" class="text-xs text-gray-500 px-2 py-1.5 truncate">{{ img.titulo }}</p>
                    </div>
                </div>
            </div>

            <!-- Pasos -->
            <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-100">
                    <h2 class="text-sm font-semibold text-gray-700">Pasos del trabajo</h2>
                </div>
                <div class="divide-y divide-gray-50">
                    <div v-for="paso in trabajo.pasos" :key="paso.id"
                        class="px-4 py-3 transition-colors"
                        :class="paso.completado ? 'bg-green-50' : 'hover:bg-gray-50'">
                        <div class="flex items-start gap-3">
                            <!-- Checkbox / estado -->
                            <button
                                @click="paso.completado ? desmarcarPaso(paso) : abrirModal(paso)"
                                class="shrink-0 mt-0.5 w-6 h-6 rounded-lg border-2 flex items-center justify-center transition-all"
                                :class="paso.completado
                                    ? 'bg-green-500 border-green-500 hover:bg-green-600'
                                    : 'border-gray-300 hover:border-blue-400 bg-white'">
                                <svg v-if="paso.completado" class="w-3.5 h-3.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                </svg>
                            </button>
                            <!-- Contenido -->
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="text-sm font-medium"
                                        :class="paso.completado ? 'text-green-700 line-through' : 'text-gray-800'">
                                        {{ paso.nombre }}
                                    </span>
                                    <span class="text-xs text-gray-400">{{ paso.peso_porcentaje }}%</span>
                                    <span v-if="paso.es_extra"
                                        class="text-xs px-1.5 py-0.5 rounded-full bg-blue-50 text-blue-600">Extra</span>
                                </div>
                                <p v-if="paso.descripcion_resuelta" class="text-xs text-gray-500 mt-0.5">
                                    {{ paso.descripcion_resuelta }}
                                </p>
                                <!-- Completado info -->
                                <div v-if="paso.completado" class="mt-1.5 space-y-0.5">
                                    <p class="text-xs text-green-600 font-medium">
                                        ✓ Completado {{ paso.completado_at }}
                                    </p>
                                    <div v-for="op_pivot in paso.operarios_pivot" :key="op_pivot.operario_id"
                                        class="text-xs text-gray-500">
                                        {{ op_pivot.nombre }}
                                        <span v-if="op_pivot.tiempo_minutos"> · {{ op_pivot.tiempo_minutos }} min</span>
                                        <span v-if="op_pivot.observaciones"> · {{ op_pivot.observaciones }}</span>
                                    </div>
                                </div>
                                <!-- Fotos del paso -->
                                <PasoFotos
                                    :paso-id="paso.id"
                                    :fotos="paso.fotos ?? []"
                                    :editable="true"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- PDF link -->
            <div class="pb-4">
                <a :href="`/produccion/trabajos/${trabajo.id}/pdf`" target="_blank"
                    class="inline-flex items-center gap-1.5 text-xs px-3 py-2 rounded-xl border border-red-200 text-red-600 hover:bg-red-50 font-medium">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                    </svg>
                    Descargar PDF de este trabajo
                </a>
            </div>

        </div>

        <!-- Modal completar paso -->
        <Teleport to="body">
            <div v-if="modalPaso" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-4"
                style="background:rgba(0,0,0,0.5);">
                <div class="bg-white rounded-2xl shadow-xl w-full max-w-md max-h-[90vh] overflow-y-auto">
                    <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-gray-900">Completar paso</h3>
                        <button @click="cerrarModal" class="text-gray-400 hover:text-gray-600">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                    <div class="p-5 space-y-4">
                        <p class="text-sm font-medium text-gray-800">{{ modalPaso?.nombre }}</p>

                        <div v-for="(op_data, idx) in operariosModal" :key="idx"
                            class="bg-gray-50 rounded-xl p-3 space-y-2">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-semibold text-gray-600">Operario {{ idx + 1 }}</span>
                                <button v-if="operariosModal.length > 1" @click="quitarOperario(idx)"
                                    class="text-red-400 hover:text-red-600">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                    </svg>
                                </button>
                            </div>
                            <select v-model="op_data.operario_id"
                                class="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm focus:outline-none focus:border-blue-400">
                                <option :value="null">Seleccionar operario...</option>
                                <option v-for="op_item in operarios" :key="op_item.id" :value="op_item.id">
                                    {{ op_item.nombre }}
                                </option>
                            </select>
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="text-xs text-gray-500 block mb-1">Tiempo (min)</label>
                                    <input v-model.number="op_data.tiempo_minutos" type="number" min="0"
                                        placeholder="0"
                                        class="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm focus:outline-none focus:border-blue-400"/>
                                </div>
                                <div>
                                    <label class="text-xs text-gray-500 block mb-1">Observación</label>
                                    <input v-model="op_data.observaciones" type="text" placeholder="—"
                                        class="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm focus:outline-none focus:border-blue-400"/>
                                </div>
                            </div>
                        </div>

                        <button @click="agregarOperario"
                            class="text-xs text-blue-600 hover:text-blue-800 font-medium flex items-center gap-1">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                            </svg>
                            Agregar operario
                        </button>

                        <div class="flex gap-3 pt-2">
                            <button @click="cerrarModal"
                                class="flex-1 py-2.5 rounded-xl border border-gray-200 text-sm text-gray-600">
                                Cancelar
                            </button>
                            <button @click="completarPaso" :disabled="guardando"
                                class="flex-1 py-2.5 rounded-xl text-white text-sm font-medium disabled:opacity-50"
                                style="background:var(--marca);">
                                {{ guardando ? 'Guardando...' : 'Marcar completado' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
