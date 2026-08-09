<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'

const props = defineProps({
    plantillas:       Array,
    modulos:          Object,
    modulosAgrupados: Array,
})

const moduloActivo  = ref('todas')
const confirmando   = ref(null)
const procesando    = ref(null)
const modalNueva    = ref(false)
const moduloNueva   = ref(
    props.modulosAgrupados?.[0]?.modulos?.[0]?.key ?? 'cotizacion'
)
const varEjemplo    = '{{variable}}'

const modulosFiltro = computed(() => [
    { key: 'todas', label: 'Todas' },
    ...Object.entries(props.modulos).map(([key, label]) => ({ key, label })),
])

const plantillasFiltradas = computed(() => {
    if (moduloActivo.value === 'todas') return props.plantillas
    return props.plantillas.filter(p => p.modulo === moduloActivo.value)
})

// Map module key → section
const moduloAGrupo = computed(() => {
    const map = {}
    props.modulosAgrupados?.forEach(g => g.modulos.forEach(m => { map[m.key] = g.grupo }))
    return map
})

// Group by section → module key → list of plantillas
const plantillasPorSeccion = computed(() => {
    const grupoMap = {}
    const grupoOrder = []
    plantillasFiltradas.value.forEach(p => {
        const grupo = moduloAGrupo.value[p.modulo] ?? 'Otros'
        if (!grupoMap[grupo]) { grupoMap[grupo] = {}; grupoOrder.push(grupo) }
        if (!grupoMap[grupo][p.modulo]) grupoMap[grupo][p.modulo] = []
        grupoMap[grupo][p.modulo].push(p)
    })
    return grupoOrder.map(g => ({
        grupo: g,
        modulos: Object.entries(grupoMap[g]).map(([key, items]) => ({
            key,
            label: props.modulos[key] ?? key,
            items,
        }))
    }))
})

function labelModulo(key) {
    return props.modulos[key] ?? key
}

function getCsrf() {
    const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/)
    return m ? decodeURIComponent(m[1]) : ''
}

async function marcarDefault(plantilla) {
    procesando.value = `default-${plantilla.id}`
    try {
        await fetch(`/configuracion/plantillas-pdf/${plantilla.id}/default`, {
            method: 'POST',
            headers: { 'X-XSRF-TOKEN': getCsrf() },
            credentials: 'same-origin',
        })
        router.reload({ preserveScroll: true })
    } finally {
        procesando.value = null
    }
}

async function duplicar(plantilla) {
    procesando.value = `dup-${plantilla.id}`
    try {
        await fetch(`/configuracion/plantillas-pdf/${plantilla.id}/duplicar`, {
            method: 'POST',
            headers: { 'X-XSRF-TOKEN': getCsrf() },
            credentials: 'same-origin',
        })
        router.reload({ preserveScroll: true })
    } finally {
        procesando.value = null
    }
}

async function eliminar(plantilla) {
    if (confirmando.value !== plantilla.id) {
        confirmando.value = plantilla.id
        return
    }
    confirmando.value = null
    procesando.value  = `del-${plantilla.id}`
    try {
        await fetch(`/configuracion/plantillas-pdf/${plantilla.id}`, {
            method: 'DELETE',
            headers: { 'X-XSRF-TOKEN': getCsrf() },
            credentials: 'same-origin',
        })
        router.reload({ preserveScroll: true })
    } finally {
        procesando.value = null
    }
}

function irACrear() {
    const select = document.getElementById('select-modulo-nueva')
    const modulo = select?.value
    if (!modulo) return
    modalNueva.value = false
    window.location.href = `/configuracion/plantillas-pdf/crear?modulo=${modulo}`
}
</script>

<template>
    <AppLayout title="Plantillas PDF">
        <div class="max-w-5xl mx-auto space-y-5">

            <!-- Cabecera -->
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-xl font-bold text-gray-900">Plantillas PDF</h2>
                    <p class="text-sm text-gray-500 mt-0.5">
                        Editor HTML libre con variables dinámicas <code class="text-xs bg-gray-100 px-1 rounded">{{ varEjemplo }}</code>
                    </p>
                </div>
                <button
                    @click="modalNueva = true"
                    class="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold text-white shadow-sm transition-opacity hover:opacity-90"
                    style="background-color: var(--marca);"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                    </svg>
                    Nueva plantilla
                </button>
            </div>

            <!-- Filtro por módulo -->
            <div class="flex gap-2 overflow-x-auto pb-1 -mx-4 px-4 lg:mx-0 lg:px-0">
                <button
                    v-for="m in modulosFiltro"
                    :key="m.key"
                    @click="moduloActivo = m.key"
                    class="shrink-0 px-3 py-1.5 rounded-full text-xs font-medium transition-colors whitespace-nowrap border"
                    :class="moduloActivo === m.key
                        ? 'bg-[var(--marca)] text-white border-[var(--marca)]'
                        : 'bg-white text-gray-600 border-gray-200 hover:bg-gray-50'"
                >
                    {{ m.label }}
                </button>
            </div>

            <!-- Sin resultados -->
            <div v-if="plantillasFiltradas.length === 0" class="text-center py-16 bg-white rounded-2xl border border-gray-100 shadow-sm">
                <svg class="w-12 h-12 mx-auto text-gray-300 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                </svg>
                <p class="text-gray-500 text-sm">Sin plantillas para este módulo</p>
                <button
                    @click="modalNueva = true"
                    class="mt-3 text-sm font-medium underline"
                    style="color: var(--marca);"
                >
                    Crear la primera
                </button>
            </div>

            <!-- Secciones → Módulos → Plantillas -->
            <div v-for="seccion in plantillasPorSeccion" :key="seccion.grupo" class="space-y-4">
                <!-- Encabezado de sección -->
                <div class="flex items-center gap-3">
                    <span
                        class="text-xs font-bold uppercase tracking-widest px-2.5 py-1 rounded-lg"
                        style="background-color: var(--marca); color: white;"
                    >
                        {{ seccion.grupo }}
                    </span>
                    <div class="flex-1 h-px bg-gray-100"></div>
                </div>

                <!-- Módulos dentro de la sección -->
                <div v-for="mod in seccion.modulos" :key="mod.key" class="space-y-2 pl-0 sm:pl-2">
                    <!-- Encabezado de módulo -->
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-semibold text-gray-500 uppercase tracking-wider">
                            {{ mod.label }}
                        </span>
                        <span class="text-xs text-gray-300">{{ mod.items.length }} plantilla{{ mod.items.length !== 1 ? 's' : '' }}</span>
                    </div>

                    <!-- Tarjetas de plantillas -->
                    <div
                        v-for="p in mod.items"
                        :key="p.id"
                        class="bg-white rounded-xl border border-gray-100 shadow-sm px-4 py-3"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <!-- Info -->
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="font-semibold text-sm text-gray-900 truncate">{{ p.nombre }}</span>
                                    <span
                                        v-if="p.es_default"
                                        class="shrink-0 text-xs px-2 py-0.5 rounded-full font-medium text-white"
                                        style="background-color: var(--marca);"
                                    >
                                        Por defecto
                                    </span>
                                    <span
                                        v-if="!p.activa"
                                        class="shrink-0 text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-500"
                                    >
                                        Inactiva
                                    </span>
                                </div>
                                <div class="flex items-center gap-3 mt-0.5">
                                    <span class="text-xs text-gray-400">{{ p.papel?.toUpperCase() }}</span>
                                    <span class="text-xs text-gray-400 capitalize">{{ p.orientacion }}</span>
                                    <span v-if="p.descripcion" class="text-xs text-gray-400 truncate max-w-xs">{{ p.descripcion }}</span>
                                </div>
                            </div>

                            <!-- Acciones desktop -->
                            <div class="hidden sm:flex items-center gap-1.5 shrink-0">
                                <button
                                    @click="router.visit(`/configuracion/plantillas-pdf/${p.id}/editar`)"
                                    class="px-3 py-1.5 text-xs font-medium rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50 transition-colors"
                                >
                                    Editar
                                </button>
                                <button
                                    @click="duplicar(p)"
                                    :disabled="procesando === `dup-${p.id}`"
                                    class="px-3 py-1.5 text-xs font-medium rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50 transition-colors disabled:opacity-50"
                                >
                                    Duplicar
                                </button>
                                <button
                                    v-if="!p.es_default"
                                    @click="marcarDefault(p)"
                                    :disabled="procesando === `default-${p.id}`"
                                    class="px-3 py-1.5 text-xs font-medium rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50 transition-colors disabled:opacity-50"
                                >
                                    Marcar default
                                </button>
                                <button
                                    @click="eliminar(p)"
                                    :disabled="procesando === `del-${p.id}`"
                                    class="px-3 py-1.5 text-xs font-medium rounded-lg border transition-colors disabled:opacity-50"
                                    :class="confirmando === p.id
                                        ? 'border-red-300 bg-red-50 text-red-700'
                                        : 'border-gray-200 text-gray-500 hover:border-red-200 hover:text-red-600'"
                                >
                                    {{ confirmando === p.id ? '¿Confirmar?' : 'Eliminar' }}
                                </button>
                            </div>
                        </div>

                        <!-- Acciones mobile -->
                        <div class="flex sm:hidden items-center gap-2 mt-3 pt-3 border-t border-gray-50">
                            <button
                                @click="router.visit(`/configuracion/plantillas-pdf/${p.id}/editar`)"
                                class="flex-1 py-1.5 text-xs font-medium rounded-lg text-center text-white"
                                style="background-color: var(--marca);"
                            >
                                Editar
                            </button>
                            <button
                                @click="duplicar(p)"
                                :disabled="procesando === `dup-${p.id}`"
                                class="flex-1 py-1.5 text-xs font-medium rounded-lg border border-gray-200 text-gray-700 disabled:opacity-50"
                            >
                                Duplicar
                            </button>
                            <button
                                v-if="!p.es_default"
                                @click="marcarDefault(p)"
                                :disabled="procesando === `default-${p.id}`"
                                class="flex-1 py-1.5 text-xs font-medium rounded-lg border border-gray-200 text-gray-700 disabled:opacity-50"
                            >
                                Default
                            </button>
                            <button
                                @click="eliminar(p)"
                                :disabled="procesando === `del-${p.id}`"
                                class="py-1.5 px-3 text-xs font-medium rounded-lg border disabled:opacity-50"
                                :class="confirmando === p.id ? 'border-red-300 bg-red-50 text-red-700' : 'border-gray-200 text-gray-500'"
                            >
                                {{ confirmando === p.id ? '¿Sí?' : 'X' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal nueva plantilla -->
        <Teleport to="body">
            <div v-if="modalNueva"
                class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-4"
                style="background: rgba(0,0,0,0.5);"
                @click.self="modalNueva = false">
                <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm p-6 space-y-4">
                    <h3 class="font-bold text-gray-900">Nueva plantilla PDF</h3>
                    <div>
                        <label class="block text-sm text-gray-600 mb-1">Módulo</label>
                        <select
                            id="select-modulo-nueva"
                            class="w-full text-sm border border-gray-200 rounded-xl px-3 py-2.5"
                        >
                            <option
                                v-for="(label, key) in props.modulos"
                                :key="key"
                                :value="key"
                            >{{ label }}</option>
                        </select>
                    </div>
                    <div class="flex gap-3">
                        <button
                            @click="modalNueva = false"
                            class="flex-1 py-2.5 text-sm font-medium rounded-xl border border-gray-200 text-gray-700"
                        >Cancelar</button>
                        <button
                            @click="irACrear"
                            class="flex-1 py-2.5 text-sm font-semibold rounded-xl text-white"
                            style="background-color: var(--marca);"
                        >Continuar</button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
