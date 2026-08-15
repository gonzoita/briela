<script setup>
import { ref, reactive, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import OrdenarLista from '@/Components/OrdenarLista.vue'
import { useOrden } from '@/composables/useOrden'

const props = defineProps({
    ops:          Object,
    filters:      Object,
    responsables: Array,
    metricas:     Object,
    // El orden vigente, que decide el servidor: { campo, dir }.
    orden: { type: Object, default: () => ({}) },
})

// Ordenar mantiene los filtros: reordenar no es empezar de cero.
const { ordenarPor } = useOrden('/produccion/ops', props.orden, props.filters)

const camposOrden = [
    { campo: 'numero', etiqueta: 'Número' },
    { campo: 'estado', etiqueta: 'Estado' },
    { campo: 'porcentaje_avance', etiqueta: 'Avance', texto: false },
    { campo: 'fecha_entrega_estimada', etiqueta: 'Entrega', texto: false },
    { campo: 'created_at', etiqueta: 'Más reciente', texto: false },
]

const filters = reactive({
    buscar:         props.filters?.buscar         ?? '',
    estado:         props.filters?.estado         ?? '',
    responsable_id: props.filters?.responsable_id ?? '',
    desde:          props.filters?.desde          ?? '',
    hasta:          props.filters?.hasta          ?? '',
})

const formatFecha = (d) => {
    if (!d) return '—'
    const fecha = new Date(d)
    if (isNaN(fecha.getTime())) return '—'
    return fecha.toLocaleDateString('es-CO', {
        day: '2-digit', month: 'short', year: 'numeric',
        timeZone: 'America/Bogota',
    })
}

let timer = null
watch(filters, () => {
    clearTimeout(timer)
    timer = setTimeout(() => {
        router.get('/produccion/ops', {
            buscar:         filters.buscar         || undefined,
            estado:         filters.estado         || undefined,
            responsable_id: filters.responsable_id || undefined,
            desde:          filters.desde          || undefined,
            hasta:          filters.hasta          || undefined,
        }, { preserveState: true, replace: true })
    }, 400)
}, { deep: true })

function limpiar() {
    filters.buscar = ''; filters.estado = ''; filters.responsable_id = ''
    filters.desde = ''; filters.hasta = ''
}

const ESTADOS = [
    { value: '',              label: 'Todos los estados' },
    { value: 'borrador',      label: 'Borrador'      },
    { value: 'confirmada',    label: 'Confirmada'    },
    { value: 'en_produccion', label: 'En producción' },
    { value: 'calidad',       label: 'Calidad'       },
    { value: 'reproceso',     label: 'Reproceso'     },
    { value: 'despachada',    label: 'Despachada'    },
]

const BADGE_COLOR = {
    borrador:      { bg: '#F3F4F6', text: '#374151' },
    confirmada:    { bg: '#DBEAFE', text: '#1D4ED8' },
    en_produccion: { bg: '#FEF3C7', text: '#92400E' },
    calidad:       { bg: '#E9D5FF', text: '#6B21A8' },
    reproceso:     { bg: '#FFEDD5', text: '#9A3412' },
    despachada:    { bg: '#D1FAE5', text: '#065F46' },
}
function badgeStyle(estado) { return BADGE_COLOR[estado] ?? { bg: '#F3F4F6', text: '#374151' } }
function badgeLabel(estado) { return ESTADOS.find(e => e.value === estado)?.label ?? estado }
function saldoPendiente(op) { return op.saldo_pendiente ?? 0 }
function fmt(v) { return Number(v || 0).toLocaleString('es-CO') }
</script>

<template>
    <AppLayout title="Órdenes de Producción">

        <!-- Cabecera -->
        <div class="flex items-center justify-between mb-4">
            <h1 class="text-lg font-semibold text-tinta-900">Órdenes de Producción</h1>
            <a href="/produccion/ops/crear"
                class="flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold text-white"
                style="background:var(--marca);">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                </svg>
                Nueva OP
            </a>
        </div>

        <!-- Ordenar. Vale para las listas que son tabla y para las que son tarjetas, y
             en celular es el único camino: ahí no hay encabezados donde hacer clic. -->
        <div class="mb-3">
            <OrdenarLista :campos="camposOrden" :orden="orden" @ordenar="ordenarPor" />
        </div>

        <!-- Métricas clickeables por estado -->
        <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4">
            <button v-for="card in [
                { key: 'borrador',      label: 'Borrador',      color: 'text-tinta-500',   border: 'border-linea',   ring: 'ring-gray-300'   },
                { key: 'confirmada',    label: 'Confirmada',    color: 'text-blue-700',   border: 'border-blue-100',   ring: 'ring-blue-300'   },
                { key: 'en_produccion', label: 'En producción', color: 'text-yellow-700', border: 'border-yellow-100', ring: 'ring-yellow-300' },
                { key: 'calidad',       label: 'Calidad',       color: 'text-purple-700', border: 'border-purple-100', ring: 'ring-purple-300' },
                { key: 'reproceso',     label: 'Reproceso',     color: 'text-orange-700', border: 'border-orange-100', ring: 'ring-orange-300' },
                { key: 'despachada',    label: 'Despachada',    color: 'text-green-700',  border: 'border-green-100',  ring: 'ring-green-300'  },
            ]" :key="card.key"
                @click="filters.estado = filters.estado === card.key ? '' : card.key"
                class="bg-superficie rounded-2xl border shadow-sm px-4 py-4 text-center transition-all hover:shadow-md w-full"
                :class="[card.border, filters.estado === card.key ? `ring-2 ${card.ring}` : '']">
                <p class="text-2xl font-semibold" :class="card.color">{{ metricas[card.key] ?? 0 }}</p>
                <p class="text-xs text-tinta-300 mt-1">{{ card.label }}</p>
            </button>
        </div>

        <!-- Filtros -->
        <div class="bg-superficie rounded-2xl border border-linea shadow-sm p-4 mb-4 space-y-3">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <input v-model="filters.buscar" type="text" placeholder="Buscar número o cliente..."
                    class="border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]" />
                <select v-model="filters.estado" class="border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]">
                    <option v-for="e in ESTADOS" :key="e.value" :value="e.value">{{ e.label }}</option>
                </select>
                <select v-model="filters.responsable_id" class="border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]">
                    <option value="">Todos los responsables</option>
                    <option v-for="r in responsables" :key="r.id" :value="r.id">{{ r.name }}</option>
                </select>
                <div class="flex gap-2">
                    <input v-model="filters.desde" type="date"
                        class="flex-1 border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]" title="Desde" />
                    <input v-model="filters.hasta" type="date"
                        class="flex-1 border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]" title="Hasta" />
                </div>
            </div>
            <div v-if="filters.buscar || filters.estado || filters.responsable_id || filters.desde || filters.hasta"
                 class="flex justify-end">
                <button @click="limpiar"
                    class="text-xs text-red-500 hover:text-red-700 font-medium flex items-center gap-1">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                    Limpiar filtros
                </button>
            </div>
        </div>

        <!-- Lista de OPs -->
        <div class="space-y-3">
            <template v-if="ops.data?.length">
                <div v-for="op in ops.data" :key="op.id"
                    class="bg-superficie rounded-2xl border border-linea shadow-sm hover:shadow-md transition-shadow cursor-pointer"
                    @click="router.visit(`/produccion/ops/${op.id}`)">
                    <div class="px-5 py-4 flex items-center gap-4">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 flex-wrap mb-1">
                                <span class="text-sm font-semibold text-tinta-900">{{ op.numero }}</span>
                                <span class="text-xs px-2 py-0.5 rounded-full font-semibold"
                                    :style="`background:${badgeStyle(op.estado).bg};color:${badgeStyle(op.estado).text};`">
                                    {{ badgeLabel(op.estado) }}
                                </span>
                                <span v-if="op.cotizacion_id" class="text-xs px-2 py-0.5 rounded-full bg-blue-50 text-blue-600 font-medium">
                                    Desde cotización
                                </span>
                                <span v-if="op.alerta_entrega === 'rojo'"
                                    class="text-xs px-2 py-0.5 rounded-full font-semibold bg-red-100 text-red-700">
                                    ⚠ Entrega hoy
                                </span>
                                <span v-else-if="op.alerta_entrega === 'amarillo'"
                                    class="text-xs px-2 py-0.5 rounded-full font-semibold bg-yellow-100 text-yellow-700">
                                    ⏰ {{ op.dias_para_entrega }} día{{ op.dias_para_entrega !== 1 ? 's' : '' }}
                                </span>
                            </div>
                            <p class="text-xs text-tinta-400 truncate">
                                <span v-if="op.cliente_nombre" class="font-medium text-tinta-700">{{ op.cliente_nombre }}</span>
                                <span v-else class="text-tinta-300">Sin cliente</span>
                                <span class="mx-1">·</span>
                                {{ op.responsable_nombre ?? '—' }}
                                <span v-if="op.items_count" class="mx-1">·</span>
                                <span v-if="op.items_count">{{ op.items_count }} ítem{{ op.items_count !== 1 ? 's' : '' }}</span>
                            </p>
                            <div v-if="op.porcentaje_avance > 0" class="mt-2">
                                <div class="flex items-center gap-2">
                                    <div class="flex-1 bg-tinta-100 rounded-full h-1.5">
                                        <div class="h-1.5 rounded-full transition-all"
                                            :style="`width:${Math.min(op.porcentaje_avance, 100)}%; background:var(--marca);`">
                                        </div>
                                    </div>
                                    <span class="text-xs font-semibold text-blue-700 shrink-0">
                                        {{ Math.round(op.porcentaje_avance) }}%
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="hidden sm:flex flex-col items-end shrink-0 gap-1">
                            <p class="text-xs text-tinta-400">{{ formatFecha(op.fecha_creacion) }}</p>
                            <p v-if="op.fecha_entrega_estimada" class="text-xs text-amber-600 font-medium">
                                Entrega: {{ formatFecha(op.fecha_entrega_estimada) }}
                            </p>
                            <!-- Saldo financiero -->
                            <div v-if="saldoPendiente(op) <= 0 && (op.total ?? 0) > 0"
                                class="flex flex-col items-end">
                                <div class="flex items-center gap-1.5">
                                    <span class="w-2 h-2 rounded-full bg-green-500"></span>
                                    <span class="text-xs font-semibold text-green-600">Pagado</span>
                                </div>
                                <span class="text-xs text-tinta-300 mt-0.5">Saldo</span>
                            </div>
                            <div v-else-if="op.semaforo_cartera === 'rojo'"
                                class="flex flex-col items-end">
                                <div class="flex items-center gap-1.5">
                                    <span class="w-2 h-2 rounded-full bg-red-500"></span>
                                    <span class="text-xs font-semibold text-red-600">
                                        ${{ fmt(saldoPendiente(op)) }}
                                    </span>
                                </div>
                                <span class="text-xs text-tinta-300 mt-0.5">Saldo</span>
                            </div>
                            <div v-else-if="op.semaforo_cartera === 'amarillo'"
                                class="flex flex-col items-end">
                                <div class="flex items-center gap-1.5">
                                    <span class="w-2 h-2 rounded-full bg-yellow-400"></span>
                                    <span class="text-xs font-semibold text-yellow-600">
                                        ${{ fmt(saldoPendiente(op)) }}
                                    </span>
                                </div>
                                <span class="text-xs text-tinta-300 mt-0.5">Saldo</span>
                            </div>
                            <div v-else-if="op.semaforo_cartera === 'gris' && saldoPendiente(op) > 0"
                                class="flex flex-col items-end">
                                <span class="text-xs font-semibold text-tinta-300">
                                    ${{ fmt(saldoPendiente(op)) }}
                                </span>
                                <span class="text-xs text-tinta-300 mt-0.5">Saldo</span>
                            </div>
                        </div>
                        <svg class="w-4 h-4 text-tinta-200 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                        </svg>
                    </div>
                    <div class="sm:hidden px-5 pb-3 flex items-center justify-between">
                        <span class="text-xs text-tinta-300">{{ formatFecha(op.fecha_creacion) }}</span>
                        <div class="flex items-center gap-3">
                            <span v-if="op.fecha_entrega_estimada" class="text-xs text-amber-600 font-medium">
                                Entrega: {{ formatFecha(op.fecha_entrega_estimada) }}
                            </span>
                            <div v-if="saldoPendiente(op) <= 0 && (op.total ?? 0) > 0"
                                class="flex flex-col items-end">
                                <div class="flex items-center gap-1">
                                    <span class="w-2 h-2 rounded-full bg-green-500"></span>
                                    <span class="text-xs font-semibold text-green-600">Pagado</span>
                                </div>
                                <span class="text-xs text-tinta-300 mt-0.5">Saldo</span>
                            </div>
                            <div v-else-if="op.semaforo_cartera === 'rojo'"
                                class="flex flex-col items-end">
                                <div class="flex items-center gap-1">
                                    <span class="w-2 h-2 rounded-full bg-red-500"></span>
                                    <span class="text-xs font-semibold text-red-600">
                                        ${{ fmt(saldoPendiente(op)) }}
                                    </span>
                                </div>
                                <span class="text-xs text-tinta-300 mt-0.5">Saldo</span>
                            </div>
                            <div v-else-if="op.semaforo_cartera === 'amarillo'"
                                class="flex flex-col items-end">
                                <div class="flex items-center gap-1">
                                    <span class="w-2 h-2 rounded-full bg-yellow-400"></span>
                                    <span class="text-xs font-semibold text-yellow-600">
                                        ${{ fmt(saldoPendiente(op)) }}
                                    </span>
                                </div>
                                <span class="text-xs text-tinta-300 mt-0.5">Saldo</span>
                            </div>
                            <div v-else-if="op.semaforo_cartera === 'gris' && saldoPendiente(op) > 0"
                                class="flex flex-col items-end">
                                <span class="text-xs font-semibold text-tinta-300">
                                    ${{ fmt(saldoPendiente(op)) }}
                                </span>
                                <span class="text-xs text-tinta-300 mt-0.5">Saldo</span>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
            <div v-else class="bg-superficie rounded-2xl border border-linea shadow-sm px-5 py-16 text-center">
                <svg class="w-12 h-12 mx-auto mb-3 text-tinta-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                </svg>
                <p class="text-sm text-tinta-400">No hay órdenes de producción.</p>
                <a href="/produccion/ops/crear" class="mt-3 inline-block text-sm font-medium" style="color:var(--marca);">
                    Crear la primera OP
                </a>
            </div>
        </div>

        <!-- Paginación -->
        <div v-if="ops.last_page > 1" class="flex items-center justify-between mt-4">
            <p class="text-xs text-tinta-300">
                Mostrando {{ ops.from }}–{{ ops.to }} de {{ ops.total }} OPs
            </p>
            <div class="flex gap-2">
                <a v-if="ops.prev_page_url" :href="ops.prev_page_url"
                    class="px-3 py-1.5 rounded-xl border border-linea text-xs text-tinta-500 hover:bg-tinta-50">Anterior</a>
                <a v-if="ops.next_page_url" :href="ops.next_page_url"
                    class="px-3 py-1.5 rounded-xl border border-linea text-xs text-tinta-500 hover:bg-tinta-50">Siguiente</a>
            </div>
        </div>

    </AppLayout>
</template>
