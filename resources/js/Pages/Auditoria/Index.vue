<script setup>
import { reactive, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import OrdenarLista from '@/Components/OrdenarLista.vue'
import { useOrden } from '@/composables/useOrden'

const props = defineProps({
    registros: Object,
    filtros:   Object,
    usuarios:  Array,
    modelos:   Array,
    // El orden vigente, que decide el servidor: { campo, dir }.
    orden: { type: Object, default: () => ({}) },
})

// Ordenar mantiene los filtros: reordenar no es empezar de cero.
const { ordenarPor } = useOrden('/auditoria', props.orden, props.filtros)

const camposOrden = [
    { campo: 'created_at', etiqueta: 'Más reciente', texto: false },
    { campo: 'accion', etiqueta: 'Acción' },
    { campo: 'modelo', etiqueta: 'Módulo' },
]

const filtros = reactive({
    usuario_id: props.filtros?.usuario_id ?? '',
    modelo:     props.filtros?.modelo     ?? '',
    accion:     props.filtros?.accion     ?? '',
    desde:      props.filtros?.desde      ?? '',
    hasta:      props.filtros?.hasta      ?? '',
    buscar:     props.filtros?.buscar     ?? '',
})

let debounceTimer = null
watch(filtros, () => {
    clearTimeout(debounceTimer)
    debounceTimer = setTimeout(() => {
        router.get('/auditoria', { ...filtros }, { preserveState: true, replace: true })
    }, 350)
}, { deep: true })

function limpiarFiltros() {
    filtros.usuario_id = ''
    filtros.modelo     = ''
    filtros.accion     = ''
    filtros.desde      = ''
    filtros.hasta      = ''
    filtros.buscar     = ''
}

const accionInfo = (accion) => ({
    creado:               { label: 'Creado',            bg: '#D1FAE5', text: '#065F46' },
    actualizado:          { label: 'Actualizado',        bg: '#DBEAFE', text: '#1D4ED8' },
    eliminado:            { label: 'Eliminado',          bg: '#FEE2E2', text: '#991B1B' },
    eliminado_definitivo: { label: 'Eliminado (def.)',   bg: '#FEE2E2', text: '#991B1B' },
    restaurado:           { label: 'Restaurado',         bg: '#EDE9FE', text: '#5B21B6' },
    movido:               { label: 'Movido',             bg: '#FEF3C7', text: '#92400E' },
    otro:                 { label: 'Otro',               bg: '#F3F4F6', text: '#6B7280' },
}[accion] ?? { label: accion, bg: '#F3F4F6', text: '#6B7280' })

const formatFecha = (d) => d
    ? new Date(d).toLocaleString('es-CO', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
    : '—'
</script>

<template>
    <AppLayout title="Auditoría">
        <div class="max-w-6xl mx-auto">

            <div class="flex items-center justify-between mb-4">
                <div>
                    <h1 class="text-lg font-semibold text-tinta-900">Bitácora de actividad</h1>
                    <p class="text-xs text-tinta-300 mt-0.5">Registro de acciones de los usuarios: creación, edición, movimiento y eliminación de datos.</p>
                </div>
            </div>

            <!-- Ordenar. Vale para las listas que son tabla y para las que son tarjetas, y
                 en celular es el único camino: ahí no hay encabezados donde hacer clic. -->
            <div class="mb-3">
                <OrdenarLista :campos="camposOrden" :orden="orden" @ordenar="ordenarPor" />
            </div>

            <!-- Filtros -->
            <div class="bg-superficie rounded-xl border border-linea p-4 mb-5">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                    <input v-model="filtros.buscar" type="text" placeholder="Buscar descripción..."
                        class="rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]"/>
                    <select v-model="filtros.usuario_id"
                        class="rounded-xl border border-tinta-200 px-3 py-2 text-sm text-tinta-700 focus:outline-none focus:border-[var(--marca)]">
                        <option value="">Todos los usuarios</option>
                        <option v-for="u in usuarios" :key="u.id" :value="u.id">{{ u.name }}</option>
                    </select>
                    <select v-model="filtros.modelo"
                        class="rounded-xl border border-tinta-200 px-3 py-2 text-sm text-tinta-700 focus:outline-none focus:border-[var(--marca)]">
                        <option value="">Todos los módulos</option>
                        <option v-for="m in modelos" :key="m" :value="m">{{ m }}</option>
                    </select>
                    <select v-model="filtros.accion"
                        class="rounded-xl border border-tinta-200 px-3 py-2 text-sm text-tinta-700 focus:outline-none focus:border-[var(--marca)]">
                        <option value="">Todas las acciones</option>
                        <option value="creado">Creado</option>
                        <option value="actualizado">Actualizado</option>
                        <option value="eliminado">Eliminado</option>
                        <option value="movido">Movido</option>
                    </select>
                    <div class="flex items-center gap-2">
                        <input v-model="filtros.desde" type="date"
                            class="flex-1 min-w-0 rounded-xl border border-tinta-200 px-3 py-2 text-sm text-tinta-700 focus:outline-none focus:border-[var(--marca)]"/>
                        <span class="text-xs text-tinta-300 shrink-0">–</span>
                        <input v-model="filtros.hasta" type="date"
                            class="flex-1 min-w-0 rounded-xl border border-tinta-200 px-3 py-2 text-sm text-tinta-700 focus:outline-none focus:border-[var(--marca)]"/>
                    </div>
                </div>
                <div v-if="filtros.usuario_id || filtros.modelo || filtros.accion || filtros.desde || filtros.hasta || filtros.buscar"
                     class="flex justify-end mt-3">
                    <button @click="limpiarFiltros"
                        class="text-xs text-tinta-400 hover:text-red-600 flex items-center gap-1 transition-colors">
                        Limpiar filtros
                    </button>
                </div>
            </div>

            <!-- Lista -->
            <div class="bg-superficie rounded-2xl border border-linea divide-y divide-gray-50">
                <div v-if="registros.data.length === 0" class="px-4 py-12 text-center text-tinta-300 text-sm">
                    Sin registros de actividad para estos filtros.
                </div>
                <div v-for="r in registros.data" :key="r.id" class="px-4 py-3 flex items-start gap-3">
                    <span class="text-xs px-2 py-0.5 rounded-full font-medium mt-0.5 shrink-0"
                        :style="`background:${accionInfo(r.accion).bg};color:${accionInfo(r.accion).text};`">
                        {{ accionInfo(r.accion).label }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm text-tinta-900">{{ r.descripcion }}</p>
                        <p class="text-xs text-tinta-300 mt-0.5">
                            {{ r.usuario?.name ?? 'Sistema' }} · {{ r.modelo }} · {{ formatFecha(r.created_at) }}
                        </p>
                        <details v-if="r.cambios" class="mt-1">
                            <summary class="text-xs text-blue-600 cursor-pointer select-none">Ver cambios</summary>
                            <ul class="mt-1 text-xs text-tinta-500 space-y-0.5">
                                <li v-for="(v, campo) in r.cambios" :key="campo">
                                    <span class="font-medium">{{ campo }}:</span>
                                    <span class="text-red-500 line-through mr-1">{{ v.antes ?? '—' }}</span>
                                    <span class="text-green-600">→ {{ v.despues ?? '—' }}</span>
                                </li>
                            </ul>
                        </details>
                    </div>
                </div>
            </div>

            <!-- Paginación -->
            <div v-if="registros.links?.length > 3" class="flex flex-wrap gap-1 justify-center mt-4">
                <button v-for="(link, i) in registros.links" :key="i"
                    :disabled="!link.url"
                    @click="link.url && router.get(link.url, {}, { preserveState: true })"
                    class="px-3 py-1.5 rounded-lg text-xs"
                    :class="link.active ? 'text-white' : 'text-tinta-400 hover:bg-tinta-100'"
                    :style="link.active ? 'background:var(--marca);' : ''"
                    v-html="link.label"/>
            </div>
        </div>
    </AppLayout>
</template>
