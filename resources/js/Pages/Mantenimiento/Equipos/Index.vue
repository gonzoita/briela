<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    equipos:    Array,
    filters:    Object,
    estaciones: { type: Array, default: () => [] },
})

const search      = ref(props.filters?.search ?? '')
const estado      = ref(props.filters?.estado ?? '')
const estacion_id = ref(props.filters?.estacion_id ?? '')

let debounce
function buscar() {
    clearTimeout(debounce)
    debounce = setTimeout(() => aplicarFiltros(), 300)
}

function aplicarFiltros() {
    const params = {}
    if (search.value)      params.search       = search.value
    if (estado.value)      params.estado       = estado.value
    if (estacion_id.value) params.estacion_id  = estacion_id.value
    router.get('/mantenimiento/equipos', params, { preserveScroll: true, preserveState: true })
}

const estadoBadge = {
    activo:          { label: 'Activo',          class: 'bg-green-100 text-green-700'  },
    en_mantenimiento:{ label: 'En mant.',         class: 'bg-amber-100 text-amber-700'  },
    fuera_servicio:  { label: 'Fuera de servicio',class: 'bg-red-100 text-red-700'     },
}

function eliminar(e) {
    if (confirm(`¿Eliminar equipo "${e.nombre}"?`)) {
        router.delete(`/mantenimiento/equipos/${e.id}`)
    }
}
</script>

<template>
    <AppLayout title="Equipos">
        <div class="max-w-4xl mx-auto">

            <div class="flex items-center justify-between mb-5">
                <h1 class="text-xl font-bold text-gray-900">Equipos</h1>
                <a href="/mantenimiento/equipos/crear"
                    @click.prevent="router.visit('/mantenimiento/equipos/crear')"
                    class="px-4 py-2 rounded-xl text-white text-sm font-semibold"
                    style="background:var(--marca);">
                    + Nuevo equipo
                </a>
            </div>

            <!-- Filtros -->
            <div class="flex flex-wrap gap-2 mb-4">
                <input v-model="search" @input="buscar" type="text" placeholder="Buscar equipo..."
                    class="flex-1 min-w-[140px] rounded-xl border border-gray-200 px-3 py-2 text-sm focus:outline-none focus:ring-2" />
                <select v-model="estado" @change="aplicarFiltros"
                    class="rounded-xl border border-gray-200 px-3 py-2 text-sm focus:outline-none">
                    <option value="">Todos los estados</option>
                    <option value="activo">Activo</option>
                    <option value="en_mantenimiento">En mant.</option>
                    <option value="fuera_servicio">Fuera de servicio</option>
                </select>
                <select v-if="estaciones.length" v-model="estacion_id" @change="aplicarFiltros"
                    class="rounded-xl border border-gray-200 px-3 py-2 text-sm focus:outline-none">
                    <option value="">Todas las estaciones</option>
                    <option v-for="est in estaciones" :key="est.id" :value="est.id">
                        {{ est.nombre }}
                    </option>
                </select>
            </div>

            <div v-if="!equipos.length" class="bg-white rounded-2xl border border-gray-200 py-12 text-center text-sm text-gray-400">
                Sin equipos registrados.
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div v-for="e in equipos" :key="e.id"
                    class="bg-white rounded-2xl border border-gray-200 p-4 hover:border-blue-200 transition-colors cursor-pointer"
                    @click="router.visit(`/mantenimiento/equipos/${e.id}`)">
                    <div class="flex items-start justify-between gap-3 mb-3">
                        <div class="min-w-0">
                            <p class="font-semibold text-gray-900 truncate">{{ e.nombre }}</p>
                            <p class="text-xs text-gray-400 mt-0.5">{{ e.codigo ?? '—' }} · {{ e.tipo ?? 'Tipo no definido' }}</p>
                        </div>
                        <span class="text-xs px-2 py-0.5 rounded-full font-medium shrink-0"
                            :class="estadoBadge[e.estado]?.class ?? 'bg-gray-100 text-gray-500'">
                            {{ estadoBadge[e.estado]?.label ?? e.estado }}
                        </span>
                    </div>
                    <div class="text-xs text-gray-500 space-y-1">
                        <div v-if="e.estacion" class="flex items-center gap-1.5">
                            <div class="w-2.5 h-2.5 rounded-full shrink-0" :style="{ background: e.estacion.color }"></div>
                            <span class="font-medium" :style="{ color: e.estacion.color }">{{ e.estacion.nombre }}</span>
                        </div>
                        <div v-if="e.ubicacion" class="flex items-center gap-1.5">
                            <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                            </svg>
                            {{ e.ubicacion }}
                        </div>
                        <div v-if="e.proxima_revision" class="flex items-center gap-1.5">
                            <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                            Próx. revisión: {{ e.proxima_revision }}
                        </div>
                        <p class="text-gray-400">{{ e.mantenimientos_count }} mantenimiento(s)</p>
                    </div>
                    <div class="flex gap-2 mt-3 pt-3 border-t border-gray-100">
                        <button @click.stop="router.visit(`/mantenimiento/equipos/${e.id}/editar`)"
                            class="flex-1 py-1.5 rounded-lg border border-gray-200 text-xs font-medium text-gray-600 hover:bg-gray-50">
                            Editar
                        </button>
                        <button @click.stop="eliminar(e)"
                            class="py-1.5 px-3 rounded-lg border border-red-100 text-xs font-medium text-red-500 hover:bg-red-50">
                            Eliminar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
