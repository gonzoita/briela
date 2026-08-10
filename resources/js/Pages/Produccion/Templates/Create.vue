<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useUnsavedChanges } from '@/composables/useUnsavedChanges'

const props = defineProps({
    plantillas: Array,
})

const form = ref({
    nombre:                '',
    plantilla_ensamble_id: null,
    activo:                true,
    pasos:                 [],
})
const errors = ref({})
const pasoActivo = ref(0)

const { hasChanges, setOriginal, checkChanges, markClean } = useUnsavedChanges()
onMounted(() => setOriginal({ ...form.value }))
watch(() => form.value, checkChanges, { deep: true })

const sumValida = computed(() =>
    form.value.pasos.every(p => p.nombre?.trim())
)

const labelDificultad = ['', 'Fácil', 'Normal', 'Moderado', 'Difícil', 'Muy difícil']
const colorDificultad  = ['', 'text-green-600', 'text-lime-500', 'text-yellow-500', 'text-orange-500', 'text-red-500']

function recalcularPesos() {
    const pasos = form.value.pasos
    const totalDificultad = pasos.reduce((s, p) => s + (p.nivel_dificultad || 1), 0)
    if (totalDificultad === 0) return
    pasos.forEach(p => {
        p.peso_porcentaje = parseFloat(
            ((p.nivel_dificultad || 1) / totalDificultad * 100).toFixed(2)
        )
    })
    const sumaActual = pasos.reduce((s, p) => s + p.peso_porcentaje, 0)
    const diff = parseFloat((100 - sumaActual).toFixed(2))
    if (pasos.length > 0) {
        pasos[pasos.length - 1].peso_porcentaje = parseFloat(
            (pasos[pasos.length - 1].peso_porcentaje + diff).toFixed(2)
        )
    }
}

function agregarPaso() {
    form.value.pasos.push({
        nombre:           '',
        descripcion:      '',
        peso_porcentaje:  0,
        orden:            form.value.pasos.length,
        nivel_dificultad: 2,
        depende_de:       [],
        es_paso_final:    false,
    })
    recalcularPesos()
    pasoActivo.value = form.value.pasos.length - 1
}

function quitarPaso(idx) {
    form.value.pasos.splice(idx, 1)
    form.value.pasos.forEach((p, i) => {
        p.orden = i
        p.depende_de = (p.depende_de ?? [])
            .filter(d => d !== idx)
            .map(d => d > idx ? d - 1 : d)
    })
    recalcularPesos()
    pasoActivo.value = Math.max(0, pasoActivo.value - 1)
}

function subirPaso(idx) {
    if (idx === 0) return
    const pasos = form.value.pasos
    ;[pasos[idx - 1], pasos[idx]] = [pasos[idx], pasos[idx - 1]]
    pasos.forEach((p, i) => {
        p.orden = i
        p.depende_de = (p.depende_de ?? []).map(d => {
            if (d === idx - 1) return idx
            if (d === idx) return idx - 1
            return d
        })
    })
}

function bajarPaso(idx) {
    const pasos = form.value.pasos
    if (idx === pasos.length - 1) return
    ;[pasos[idx], pasos[idx + 1]] = [pasos[idx + 1], pasos[idx]]
    pasos.forEach((p, i) => {
        p.orden = i
        p.depende_de = (p.depende_de ?? []).map(d => {
            if (d === idx) return idx + 1
            if (d === idx + 1) return idx
            return d
        })
    })
}

function marcarPasoFinal(idx) {
    if (form.value.pasos[idx].es_paso_final) {
        form.value.pasos.forEach((p, i) => { if (i !== idx) p.es_paso_final = false })
    }
}

const variablesEncontradas = computed(() => {
    const set = new Set()
    for (const p of form.value.pasos) {
        const matches = (p.descripcion ?? '').match(/\{(\w+)\}/g) ?? []
        for (const m of matches) set.add(m)
    }
    return [...set]
})

function submit() {
    markClean()
    router.post('/produccion/templates', {
        nombre:                form.value.nombre,
        plantilla_ensamble_id: form.value.plantilla_ensamble_id || null,
        activo:                form.value.activo,
        pasos: form.value.pasos.map(p => ({
            nombre:           p.nombre,
            descripcion:      p.descripcion,
            peso_porcentaje:  p.peso_porcentaje,
            orden:            p.orden,
            nivel_dificultad: p.nivel_dificultad,
            depende_de:       p.depende_de ?? [],
            es_paso_final:    p.es_paso_final ?? false,
        })),
    }, {
        onError: (e) => { errors.value = e; hasChanges.value = true },
    })
}
</script>

<template>
    <AppLayout title="Nuevo Template">

        <!-- Indicador flotante mobile (sobre la barra inferior de 80px) -->
        <div v-if="form.pasos.length > 0"
            class="sm:hidden fixed bottom-24 left-0 right-0 z-30 flex justify-center pointer-events-none">
            <div class="bg-gray-900/75 text-white text-xs font-medium px-4 py-1.5 rounded-full shadow-lg">
                Editando: Paso {{ pasoActivo + 1 }} de {{ form.pasos.length }}
            </div>
        </div>

        <div class="max-w-2xl mx-auto sm:max-w-4xl">

            <!-- Título -->
            <div class="flex items-center gap-3 mb-4">
                <a href="/produccion/templates" @click.prevent="router.visit('/produccion/templates')"
                    class="text-tinta-300 hover:text-tinta-700">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M15 19l-7-7 7-7"/>
                    </svg>
                </a>
                <h1 class="text-xl font-semibold text-tinta-900">Nuevo Template de Trabajo</h1>
            </div>

            <div v-if="hasChanges"
                class="mb-4 inline-flex items-center gap-2 px-3 py-1.5 rounded-xl text-xs font-semibold text-orange-700"
                style="background:var(--pastel-ambar); border:1px solid #F59E0B;">
                ● Cambios sin guardar
            </div>

            <!-- ═══════════════ MOBILE (sm:hidden) ═══════════════ -->
            <div class="sm:hidden">

                <!-- Header sticky -->
                <div class="sticky top-14 z-10 bg-superficie rounded-xl border border-linea p-4 mb-4 shadow-sm space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Nombre *</label>
                        <input v-model="form.nombre" type="text"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2" />
                        <p v-if="errors.nombre" class="text-xs text-red-500 mt-1">{{ errors.nombre }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Plantilla de Ensamble</label>
                        <select v-model="form.plantilla_ensamble_id"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2">
                            <option :value="null">Sin plantilla asociada</option>
                            <option v-for="p in plantillas" :key="p.id" :value="p.id">{{ p.nombre }}</option>
                        </select>
                    </div>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input v-model="form.activo" type="checkbox" class="rounded" />
                        <span class="text-sm text-tinta-700">Activo</span>
                    </label>
                </div>

                <!-- Todos los pasos expandidos -->
                <div class="bg-superficie rounded-2xl border border-linea overflow-hidden mb-4">
                    <div class="px-5 py-3 border-b border-linea flex items-center justify-between">
                        <div>
                            <h2 class="text-sm font-semibold text-tinta-700">Pasos</h2>
                            <p class="text-xs mt-0.5 text-tinta-300">
                                {{ form.pasos.length }} paso{{ form.pasos.length !== 1 ? 's' : '' }} — pesos por dificultad
                            </p>
                        </div>
                        <button @click="agregarPaso" type="button"
                            class="px-3 py-1.5 rounded-xl border border-tinta-200 text-xs font-medium text-tinta-700 hover:bg-tinta-50">
                            + Paso
                        </button>
                    </div>
                    <p v-if="errors.pasos" class="text-xs text-red-500 px-5 pt-3">{{ errors.pasos }}</p>
                    <div v-if="!form.pasos.length" class="py-8 text-center text-sm text-tinta-300">
                        Agrega pasos al template.
                    </div>
                    <div class="divide-y divide-gray-50">
                        <div v-for="(paso, idx) in form.pasos" :key="idx"
                            :class="['p-4 space-y-3', paso.es_paso_final ? 'border-l-4 border-purple-400 bg-purple-50/20' : '']">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-semibold text-tinta-300">Paso {{ idx + 1 }}</span>
                                    <span v-if="paso.es_paso_final" class="text-xs font-semibold text-purple-600">★ Final</span>
                                    <span v-else class="text-xs font-medium" :class="colorDificultad[paso.nivel_dificultad]">
                                        {{ labelDificultad[paso.nivel_dificultad] }}
                                    </span>
                                </div>
                                <div class="flex items-center gap-1">
                                    <button @click="subirPaso(idx)" :disabled="idx === 0" type="button"
                                        class="w-7 h-7 rounded-lg flex items-center justify-center text-tinta-300 hover:bg-tinta-100 disabled:opacity-30">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7"/></svg>
                                    </button>
                                    <button @click="bajarPaso(idx)" :disabled="idx === form.pasos.length - 1" type="button"
                                        class="w-7 h-7 rounded-lg flex items-center justify-center text-tinta-300 hover:bg-tinta-100 disabled:opacity-30">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                                    </button>
                                    <button @click="quitarPaso(idx)" type="button"
                                        class="w-7 h-7 rounded-lg flex items-center justify-center text-red-400 hover:bg-red-50">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </button>
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1">Nombre *</label>
                                <input v-model="paso.nombre" type="text"
                                    class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2" />
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1">
                                    Descripción <span class="text-tinta-300 font-normal">(usa {variable})</span>
                                </label>
                                <textarea v-model="paso.descripcion" rows="2"
                                    class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 resize-none" />
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1">Dificultad</label>
                                <div class="flex items-center gap-3">
                                    <div class="flex items-center gap-0.5">
                                        <button v-for="s in 5" :key="s" type="button"
                                            @click="paso.nivel_dificultad = s; recalcularPesos()"
                                            class="text-2xl leading-none transition-colors"
                                            :class="s <= paso.nivel_dificultad ? 'text-yellow-400' : 'text-gray-200'">★</button>
                                    </div>
                                    <span class="text-xs text-tinta-300 font-mono">Peso: {{ parseFloat(paso.peso_porcentaje || 0).toFixed(1) }}%</span>
                                </div>
                            </div>
                            <!-- Paso final -->
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" v-model="paso.es_paso_final"
                                    @change="marcarPasoFinal(idx)" class="rounded accent-purple-600" />
                                <span class="text-xs font-semibold text-purple-700">Paso final</span>
                                <span class="text-xs text-tinta-300">(cierra el trabajo)</span>
                            </label>
                            <!-- Dependencias -->
                            <div v-if="form.pasos.length > 1">
                                <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1">
                                    Requiere completar primero:
                                </label>
                                <div class="space-y-1">
                                    <template v-for="(otroPaso, oIdx) in form.pasos" :key="oIdx">
                                        <label v-if="oIdx !== idx" class="flex items-center gap-2 cursor-pointer">
                                            <input type="checkbox" :value="oIdx" v-model="paso.depende_de" class="rounded" />
                                            <span class="text-xs text-tinta-500">Paso {{ oIdx + 1 }}: {{ otroPaso.nombre || 'Sin nombre' }}</span>
                                        </label>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Variables mobile -->
                <div v-if="variablesEncontradas.length" class="bg-amber-50 rounded-2xl border border-amber-200 p-4 mb-4">
                    <p class="text-xs font-semibold text-amber-700 mb-2">Variables detectadas:</p>
                    <div class="flex flex-wrap gap-2">
                        <span v-for="v in variablesEncontradas" :key="v"
                            class="text-xs px-2 py-1 rounded-lg bg-amber-100 border border-amber-300 text-amber-800 font-mono">{{ v }}</span>
                    </div>
                    <p class="text-xs text-amber-600 mt-2">Se reemplazarán con los valores del ítem al iniciar el trabajo.</p>
                </div>

                <!-- Botones mobile -->
                <div class="flex gap-3">
                    <button type="button" @click="router.visit('/produccion/templates')"
                        class="flex-1 py-3 rounded-xl border border-linea text-sm text-tinta-500 font-medium">
                        Cancelar
                    </button>
                    <button type="button" @click="submit"
                        :disabled="form.pasos.length > 0 && !sumValida"
                        class="flex-1 py-3 rounded-xl text-white text-sm font-semibold disabled:opacity-50 disabled:cursor-not-allowed"
                        style="background:var(--marca);">
                        Crear Template
                    </button>
                </div>
            </div>

            <!-- ═══════════════ DESKTOP (hidden sm:grid) ═══════════════ -->
            <div class="hidden sm:grid grid-cols-[280px_1fr] gap-4 items-start">

                <!-- Panel izquierdo sticky -->
                <div class="sticky top-14 space-y-3">

                    <!-- Header: nombre, plantilla, activo -->
                    <div class="bg-superficie rounded-xl border border-linea p-4 space-y-4">
                        <div>
                            <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Nombre *</label>
                            <input v-model="form.nombre" type="text"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2" />
                            <p v-if="errors.nombre" class="text-xs text-red-500 mt-1">{{ errors.nombre }}</p>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Plantilla de Ensamble</label>
                            <select v-model="form.plantilla_ensamble_id"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2">
                                <option :value="null">Sin plantilla asociada</option>
                                <option v-for="p in plantillas" :key="p.id" :value="p.id">{{ p.nombre }}</option>
                            </select>
                        </div>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input v-model="form.activo" type="checkbox" class="rounded" />
                            <span class="text-sm text-tinta-700">Activo</span>
                        </label>
                    </div>

                    <!-- Lista resumida de pasos -->
                    <div class="bg-superficie rounded-xl border border-linea p-3">
                        <p class="text-xs font-semibold text-tinta-300 uppercase tracking-[0.12em] px-2 mb-2">Pasos</p>
                        <div v-if="!form.pasos.length" class="text-xs text-tinta-300 text-center py-3">Sin pasos aún</div>
                        <div v-for="(paso, idx) in form.pasos" :key="idx"
                            @click="pasoActivo = idx"
                            :class="['flex items-center gap-2 px-2 py-1.5 rounded-lg cursor-pointer transition-colors',
                                pasoActivo === idx ? 'bg-blue-50 text-blue-700' : 'hover:bg-tinta-50 text-tinta-700']">
                            <span class="font-mono text-xs w-5 shrink-0 text-tinta-300">{{ idx + 1 }}</span>
                            <span class="flex-1 truncate text-sm">{{ paso.nombre || 'Sin nombre' }}</span>
                            <span v-if="paso.es_paso_final" class="shrink-0 text-purple-500 text-xs font-semibold">★</span>
                            <span v-else-if="paso.depende_de?.length" class="shrink-0 text-tinta-300 text-xs">→{{ paso.depende_de.length }}</span>
                            <span class="text-xs font-mono shrink-0" :class="pasoActivo === idx ? 'text-blue-500' : 'text-tinta-300'">
                                {{ parseFloat(paso.peso_porcentaje || 0).toFixed(0) }}%
                            </span>
                        </div>
                        <button @click="agregarPaso" type="button"
                            class="w-full mt-2 py-1.5 text-sm text-blue-600 hover:bg-blue-50 rounded-lg transition-colors">
                            + Paso
                        </button>
                        <p v-if="errors.pasos" class="text-xs text-red-500 mt-2 px-2">{{ errors.pasos }}</p>
                    </div>

                    <!-- Botones desktop -->
                    <div class="flex gap-2">
                        <button type="button" @click="router.visit('/produccion/templates')"
                            class="flex-1 py-2.5 rounded-xl border border-linea text-sm text-tinta-500 font-medium hover:bg-tinta-50">
                            Cancelar
                        </button>
                        <button type="button" @click="submit"
                            :disabled="form.pasos.length > 0 && !sumValida"
                            class="flex-1 py-2.5 rounded-xl text-white text-sm font-semibold disabled:opacity-50 disabled:cursor-not-allowed"
                            style="background:var(--marca);">
                            Guardar
                        </button>
                    </div>
                </div>

                <!-- Panel derecho: detalle del paso activo -->
                <div>
                    <div v-if="form.pasos.length === 0"
                        class="bg-superficie rounded-xl border-2 border-dashed border-linea p-10 text-center text-sm text-tinta-300">
                        Agrega un paso para comenzar
                    </div>

                    <div v-else-if="form.pasos[pasoActivo]"
                        :class="['bg-superficie rounded-xl border p-5 space-y-4',
                            form.pasos[pasoActivo].es_paso_final ? 'border-purple-300' : 'border-linea']">
                        <!-- Encabezado paso activo -->
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-semibold text-tinta-400">Paso {{ pasoActivo + 1 }}</span>
                                <span v-if="form.pasos[pasoActivo].es_paso_final" class="text-xs font-semibold text-purple-600">★ Final</span>
                                <span v-else class="text-xs font-medium" :class="colorDificultad[form.pasos[pasoActivo].nivel_dificultad]">
                                    {{ labelDificultad[form.pasos[pasoActivo].nivel_dificultad] }}
                                </span>
                            </div>
                            <div class="flex items-center gap-1">
                                <button type="button"
                                    @click="subirPaso(pasoActivo); pasoActivo = Math.max(0, pasoActivo - 1)"
                                    :disabled="pasoActivo === 0"
                                    class="w-7 h-7 rounded-lg flex items-center justify-center text-tinta-300 hover:bg-tinta-100 disabled:opacity-30">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7"/></svg>
                                </button>
                                <button type="button"
                                    @click="bajarPaso(pasoActivo); pasoActivo = Math.min(form.pasos.length - 1, pasoActivo + 1)"
                                    :disabled="pasoActivo === form.pasos.length - 1"
                                    class="w-7 h-7 rounded-lg flex items-center justify-center text-tinta-300 hover:bg-tinta-100 disabled:opacity-30">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                                </button>
                                <button type="button" @click="quitarPaso(pasoActivo)"
                                    class="w-7 h-7 rounded-lg flex items-center justify-center text-red-400 hover:bg-red-50">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Nombre *</label>
                            <input v-model="form.pasos[pasoActivo].nombre" type="text"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2" />
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">
                                Descripción <span class="text-tinta-300 font-normal">(usa {variable} para reemplazar al iniciar)</span>
                            </label>
                            <textarea v-model="form.pasos[pasoActivo].descripcion" rows="4"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 resize-none" />
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Dificultad</label>
                            <div class="flex items-center gap-4">
                                <div class="flex items-center gap-0.5">
                                    <button v-for="s in 5" :key="s" type="button"
                                        @click="form.pasos[pasoActivo].nivel_dificultad = s; recalcularPesos()"
                                        class="text-2xl leading-none transition-colors"
                                        :class="s <= form.pasos[pasoActivo].nivel_dificultad ? 'text-yellow-400' : 'text-gray-200'">★</button>
                                </div>
                                <span class="text-xs text-tinta-300 font-mono">
                                    Peso: {{ parseFloat(form.pasos[pasoActivo].peso_porcentaje || 0).toFixed(1) }}%
                                </span>
                            </div>
                        </div>

                        <!-- Paso final -->
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" v-model="form.pasos[pasoActivo].es_paso_final"
                                @change="marcarPasoFinal(pasoActivo)" class="rounded accent-purple-600" />
                            <span class="text-sm font-semibold text-purple-700">Paso final</span>
                            <span class="text-xs text-tinta-300">(cierra el trabajo al completarse)</span>
                        </label>

                        <!-- Dependencias -->
                        <div v-if="form.pasos.length > 1">
                            <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">
                                Requiere completar primero:
                            </label>
                            <div class="space-y-1.5">
                                <template v-for="(otroPaso, oIdx) in form.pasos" :key="oIdx">
                                    <label v-if="oIdx !== pasoActivo" class="flex items-center gap-2 cursor-pointer">
                                        <input type="checkbox" :value="oIdx"
                                            v-model="form.pasos[pasoActivo].depende_de" class="rounded" />
                                        <span class="text-sm text-tinta-500">Paso {{ oIdx + 1 }}: {{ otroPaso.nombre || 'Sin nombre' }}</span>
                                    </label>
                                </template>
                            </div>
                        </div>
                    </div>

                    <!-- Variables detectadas (panel derecho desktop) -->
                    <div v-if="variablesEncontradas.length" class="mt-4 bg-amber-50 rounded-xl border border-amber-200 p-4">
                        <p class="text-xs font-semibold text-amber-700 mb-2">Variables detectadas:</p>
                        <div class="flex flex-wrap gap-2">
                            <span v-for="v in variablesEncontradas" :key="v"
                                class="text-xs px-2 py-1 rounded-lg bg-amber-100 border border-amber-300 text-amber-800 font-mono">{{ v }}</span>
                        </div>
                        <p class="text-xs text-amber-600 mt-2">Se reemplazarán con los valores del ítem al iniciar el trabajo.</p>
                    </div>
                </div>
            </div>

        </div>
    </AppLayout>
</template>
