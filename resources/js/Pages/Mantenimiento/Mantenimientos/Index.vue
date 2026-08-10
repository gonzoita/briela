<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    mantenimientos: Array,
    equipos:        Array,
    filters:        Object,
})

const search = ref(props.filters?.search ?? '')
const tipo   = ref(props.filters?.tipo   ?? '')
const estado = ref(props.filters?.estado ?? '')
const equipo = ref(props.filters?.equipo ?? '')

let debounce
function buscar() {
    clearTimeout(debounce)
    debounce = setTimeout(() => aplicarFiltros(), 300)
}

function aplicarFiltros() {
    const params = {}
    if (search.value) params.search = search.value
    if (tipo.value)   params.tipo   = tipo.value
    if (estado.value) params.estado = estado.value
    if (equipo.value) params.equipo = equipo.value
    router.get('/mantenimiento/mantenimientos', params, { preserveScroll: true, preserveState: true })
}

const tipoBadge = {
    preventivo: 'bg-blue-100 text-blue-700',
    correctivo: 'bg-red-100 text-red-700',
    predictivo: 'bg-purple-100 text-purple-700',
}
const estadoBadge = {
    programado:  'bg-tinta-100 text-tinta-500',
    en_proceso:  'bg-blue-100 text-blue-700',
    completado:  'bg-green-100 text-green-700',
    cancelado:   'bg-red-100 text-red-500',
}

const fmt = (n) => Number(n || 0).toLocaleString('es-CO')

function eliminar(m) {
    if (confirm('¿Eliminar este mantenimiento?')) {
        router.delete(`/mantenimiento/mantenimientos/${m.id}`)
    }
}
</script>

<template>
    <AppLayout title="Mantenimientos">
        <div class="max-w-4xl mx-auto">

            <div class="flex items-center justify-between mb-5">
                <h1 class="text-xl font-semibold text-tinta-900">Mantenimientos</h1>
                <a href="/mantenimiento/mantenimientos/crear"
                    @click.prevent="router.visit('/mantenimiento/mantenimientos/crear')"
                    class="px-4 py-2 rounded-xl text-white text-sm font-semibold"
                    style="background:var(--marca);">
                    + Registrar
                </a>
            </div>

            <!-- Filtros -->
            <div class="flex flex-wrap gap-2 mb-4">
                <input v-model="search" @input="buscar" type="text" placeholder="Buscar equipo..."
                    class="flex-1 min-w-40 rounded-xl border border-linea px-3 py-2 text-sm focus:outline-none" />
                <select v-model="tipo" @change="aplicarFiltros"
                    class="rounded-xl border border-linea px-3 py-2 text-sm focus:outline-none">
                    <option value="">Todos los tipos</option>
                    <option value="preventivo">Preventivo</option>
                    <option value="correctivo">Correctivo</option>
                    <option value="predictivo">Predictivo</option>
                </select>
                <select v-model="estado" @change="aplicarFiltros"
                    class="rounded-xl border border-linea px-3 py-2 text-sm focus:outline-none">
                    <option value="">Todos los estados</option>
                    <option value="programado">Programado</option>
                    <option value="en_proceso">En proceso</option>
                    <option value="completado">Completado</option>
                    <option value="cancelado">Cancelado</option>
                </select>
                <select v-model="equipo" @change="aplicarFiltros"
                    class="rounded-xl border border-linea px-3 py-2 text-sm focus:outline-none">
                    <option value="">Todos los equipos</option>
                    <option v-for="e in equipos" :key="e.id" :value="e.id">{{ e.nombre }}</option>
                </select>
            </div>

            <div v-if="!mantenimientos.length" class="bg-superficie rounded-2xl border border-linea py-12 text-center text-sm text-tinta-300">
                Sin mantenimientos registrados.
            </div>

            <div v-else class="bg-superficie rounded-2xl border border-linea overflow-hidden">
                <div class="divide-y divide-gray-50">
                    <div v-for="m in mantenimientos" :key="m.id"
                        class="flex items-center px-4 py-4 gap-3 hover:bg-tinta-50 cursor-pointer"
                        @click="router.visit(`/mantenimiento/mantenimientos/${m.id}`)">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap mb-1">
                                <span class="text-sm font-semibold text-tinta-900">{{ m.equipo?.nombre }}</span>
                                <span class="text-xs px-1.5 py-0.5 rounded-full font-medium"
                                    :class="tipoBadge[m.tipo] ?? 'bg-tinta-100 text-tinta-500'">
                                    {{ m.tipo }}
                                </span>
                                <span class="text-xs px-1.5 py-0.5 rounded-full font-medium"
                                    :class="estadoBadge[m.estado] ?? 'bg-tinta-100 text-tinta-500'">
                                    {{ m.estado }}
                                </span>
                            </div>
                            <p class="text-xs text-tinta-300">
                                {{ m.fecha_programada }}
                                <span v-if="m.ejecutor_nombre"> · {{ m.ejecutor_nombre }}</span>
                                <span v-if="m.costo_total > 0"> · ${{ fmt(m.costo_total) }}</span>
                            </p>
                        </div>
                        <div class="flex gap-1 shrink-0">
                            <button @click.stop="router.visit(`/mantenimiento/mantenimientos/${m.id}/editar`)"
                                class="px-2.5 py-1.5 rounded-lg border border-linea text-xs text-tinta-500 hover:bg-tinta-100">
                                Editar
                            </button>
                            <button @click.stop="eliminar(m)"
                                class="px-2.5 py-1.5 rounded-lg border border-red-100 text-xs text-red-500 hover:bg-red-50">
                                Eliminar
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
