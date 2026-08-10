<script setup>
import { ref, computed } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    remisiones: Object,
    filtros:    Object,
})

const page    = usePage()
const flash   = computed(() => page.props.flash)

const ESTADO_BADGE = {
    borrador:   { label: 'Borrador',   bg: '#F3F4F6', text: '#374151' },
    confirmada: { label: 'Confirmada', bg: '#DBEAFE', text: '#1D4ED8' },
    en_camino:  { label: 'En camino',  bg: '#FEF3C7', text: '#92400E' },
    entregada:  { label: 'Entregada',  bg: '#D1FAE5', text: '#065F46' },
    anulada:    { label: 'Anulada',    bg: '#FEE2E2', text: '#991B1B' },
}

const TABS = [
    { value: '',          label: 'Todas'     },
    { value: 'borrador',  label: 'Borrador'  },
    { value: 'confirmada',label: 'Confirmada'},
    { value: 'en_camino', label: 'En camino' },
    { value: 'entregada', label: 'Entregada' },
    { value: 'anulada',   label: 'Anulada'   },
]

const estadoActivo = ref(props.filtros?.estado ?? '')
const tipoFiltro   = ref(props.filtros?.tipo ?? '')

function filtrar(estado) {
    estadoActivo.value = estado
    router.get('/logistica/remisiones', { estado, tipo: tipoFiltro.value }, { preserveState: true, replace: true })
}

function filtrarTipo(e) {
    tipoFiltro.value = e.target.value
    router.get('/logistica/remisiones', { estado: estadoActivo.value, tipo: tipoFiltro.value }, { preserveState: true, replace: true })
}

const formatFecha = (d) => {
    if (!d) return '—'
    return new Date(d).toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric' })
}

function badge(estado) {
    return ESTADO_BADGE[estado] ?? ESTADO_BADGE.borrador
}

function anular(id) {
    if (!confirm('¿Anular esta remisión? Se desbloquearán los ítems de la OP.')) return
    router.patch(`/logistica/remisiones/${id}/estado`, { estado: 'anulada' })
}

function eliminar(id) {
    if (!confirm('¿Eliminar esta remisión?')) return
    router.delete(`/logistica/remisiones/${id}`)
}
</script>

<template>
    <AppLayout title="Remisiones">
        <div class="max-w-5xl mx-auto">

            <!-- Flash -->
            <div v-if="flash?.success"
                class="mb-4 px-4 py-3 rounded-xl bg-green-50 border border-green-200 text-green-700 text-sm">
                {{ flash.success }}
            </div>

            <!-- Header -->
            <div class="flex items-center justify-between mb-5">
                <h1 class="text-xl font-semibold text-tinta-900">Remisiones</h1>
                <a href="/logistica/remisiones/crear"
                    class="flex items-center gap-1.5 px-4 py-2 rounded-xl text-sm font-semibold text-white"
                    style="background:var(--marca);">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                    </svg>
                    Nueva Remisión
                </a>
            </div>

            <!-- Filtros -->
            <div class="flex flex-col sm:flex-row gap-3 mb-5">
                <!-- Tabs estado -->
                <div class="flex gap-1 overflow-x-auto pb-1 flex-1">
                    <button v-for="tab in TABS" :key="tab.value"
                        @click="filtrar(tab.value)"
                        class="flex-shrink-0 px-3 py-1.5 rounded-lg text-xs font-medium transition-colors"
                        :class="estadoActivo === tab.value
                            ? 'text-white' : 'bg-tinta-100 text-tinta-500 hover:bg-tinta-200'"
                        :style="estadoActivo === tab.value ? 'background:var(--marca);' : ''">
                        {{ tab.label }}
                    </button>
                </div>
                <!-- Tipo -->
                <select @change="filtrarTipo" :value="tipoFiltro"
                    class="px-3 py-1.5 rounded-xl border border-linea text-sm text-tinta-700 bg-superficie">
                    <option value="">Todos los tipos</option>
                    <option value="op">Desde OP</option>
                    <option value="manual">Manual</option>
                </select>
            </div>

            <!-- Tabla desktop -->
            <div class="hidden md:block bg-superficie rounded-2xl border border-linea overflow-hidden">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-tinta-50 border-b border-linea">
                            <th class="text-left px-4 py-2.5 text-xs font-semibold text-tinta-400 uppercase">Número</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold text-tinta-400 uppercase">Tipo</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold text-tinta-400 uppercase">Cliente / OP</th>
                            <th class="text-center px-3 py-2.5 text-xs font-semibold text-tinta-400 uppercase">Ítems</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold text-tinta-400 uppercase">Estado</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold text-tinta-400 uppercase">Fecha</th>
                            <th class="w-24"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <tr v-if="!remisiones.data.length">
                            <td colspan="7" class="px-4 py-12 text-center text-sm text-tinta-300">
                                No hay remisiones con los filtros seleccionados.
                            </td>
                        </tr>
                        <tr v-for="rem in remisiones.data" :key="rem.id"
                            class="hover:bg-tinta-50 transition-colors">
                            <td class="px-4 py-3 font-mono text-xs font-semibold text-tinta-900">
                                {{ rem.numero }}
                            </td>
                            <td class="px-4 py-3">
                                <span class="text-xs px-2 py-0.5 rounded-full"
                                    :style="rem.tipo === 'op'
                                        ? 'background:#EDE9FE;color:#5B21B6;'
                                        : 'background:#E0F2FE;color:#0369A1;'">
                                    {{ rem.tipo === 'op' ? 'OP' : 'Manual' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm text-tinta-700">
                                <div>{{ rem.cliente?.nombre ?? '—' }}</div>
                                <div v-if="rem.op" class="text-xs text-blue-500">{{ rem.op.numero }}</div>
                            </td>
                            <td class="px-3 py-3 text-center text-sm font-medium text-tinta-700">
                                {{ rem.items?.length ?? 0 }}
                            </td>
                            <td class="px-4 py-3">
                                <span class="text-xs px-2.5 py-1 rounded-full font-semibold"
                                    :style="`background:${badge(rem.estado).bg};color:${badge(rem.estado).text};`">
                                    {{ badge(rem.estado).label }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm text-tinta-400">
                                {{ formatFecha(rem.fecha_remision ?? rem.created_at) }}
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2 justify-end">
                                    <a :href="`/logistica/remisiones/${rem.id}`"
                                        class="text-xs text-blue-600 hover:underline">Ver</a>
                                    <a v-if="rem.estado === 'borrador'" :href="`/logistica/remisiones/${rem.id}/editar`"
                                        class="text-xs text-tinta-500 hover:underline">Editar</a>
                                    <button v-if="!['anulada','entregada'].includes(rem.estado)"
                                        @click="anular(rem.id)"
                                        class="text-xs text-red-500 hover:underline">Anular</button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Cards mobile -->
            <div class="md:hidden space-y-3">
                <div v-if="!remisiones.data.length" class="text-center py-12 text-sm text-tinta-300">
                    No hay remisiones.
                </div>
                <div v-for="rem in remisiones.data" :key="rem.id"
                    class="bg-superficie rounded-2xl border border-linea p-4">
                    <div class="flex items-center justify-between mb-2">
                        <span class="font-mono text-sm font-semibold text-tinta-900">{{ rem.numero }}</span>
                        <span class="text-xs px-2.5 py-1 rounded-full font-semibold"
                            :style="`background:${badge(rem.estado).bg};color:${badge(rem.estado).text};`">
                            {{ badge(rem.estado).label }}
                        </span>
                    </div>
                    <p class="text-sm text-tinta-700 mb-1">{{ rem.cliente?.nombre ?? '—' }}</p>
                    <p v-if="rem.op" class="text-xs text-blue-500 mb-2">{{ rem.op.numero }}</p>
                    <div class="flex items-center justify-between text-xs text-tinta-300">
                        <span>{{ rem.items?.length ?? 0 }} ítems · {{ rem.tipo === 'op' ? 'OP' : 'Manual' }}</span>
                        <span>{{ formatFecha(rem.fecha_remision ?? rem.created_at) }}</span>
                    </div>
                    <div class="flex gap-3 mt-3 pt-3 border-t border-linea">
                        <a :href="`/logistica/remisiones/${rem.id}`"
                            class="text-xs font-medium text-blue-600">Ver detalle →</a>
                        <a v-if="rem.estado === 'borrador'" :href="`/logistica/remisiones/${rem.id}/editar`"
                            class="text-xs font-medium text-tinta-500">Editar</a>
                        <button v-if="!['anulada','entregada'].includes(rem.estado)"
                            @click="anular(rem.id)"
                            class="text-xs font-medium text-red-500 ml-auto">Anular</button>
                    </div>
                </div>
            </div>

            <!-- Paginación -->
            <div v-if="remisiones.last_page > 1" class="flex justify-center gap-2 mt-6">
                <a v-if="remisiones.prev_page_url" :href="remisiones.prev_page_url"
                    class="px-3 py-1.5 rounded-lg border border-linea text-sm text-tinta-700 hover:bg-tinta-50">
                    ← Anterior
                </a>
                <span class="px-3 py-1.5 text-sm text-tinta-400">
                    {{ remisiones.current_page }} / {{ remisiones.last_page }}
                </span>
                <a v-if="remisiones.next_page_url" :href="remisiones.next_page_url"
                    class="px-3 py-1.5 rounded-lg border border-linea text-sm text-tinta-700 hover:bg-tinta-50">
                    Siguiente →
                </a>
            </div>

        </div>
    </AppLayout>
</template>
