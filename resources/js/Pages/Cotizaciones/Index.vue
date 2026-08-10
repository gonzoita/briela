<script setup>
import { ref, reactive, computed, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    cotizaciones: Object,
    filters:      Object,
    responsables: Array,
    metricas:     Object,
})

const mostrarMetricas = ref(true)

const filters = reactive({
    buscar:         props.filters?.buscar         ?? '',
    estado:         props.filters?.estado         ?? '',
    responsable_id: props.filters?.responsable_id ?? '',
    desde:          props.filters?.desde          ?? '',
    hasta:          props.filters?.hasta          ?? '',
})

const formatCOP = (v) =>
    new Intl.NumberFormat('es-CO', { maximumFractionDigits: 0 }).format(v ?? 0)

const formatFecha = (d) => d
    ? new Date(d).toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric' })
    : '—'

let debounceTimer = null
watch(filters, () => {
    clearTimeout(debounceTimer)
    debounceTimer = setTimeout(() => {
        router.get('/cotizaciones', {
            buscar:         filters.buscar         || undefined,
            estado:         filters.estado         || undefined,
            responsable_id: filters.responsable_id || undefined,
            desde:          filters.desde          || undefined,
            hasta:          filters.hasta          || undefined,
        }, { preserveState: true, replace: true })
    }, 400)
}, { deep: true })

function limpiarFiltros() {
    filters.buscar         = ''
    filters.estado         = ''
    filters.responsable_id = ''
    filters.desde          = ''
    filters.hasta          = ''
}

function duplicar(cot) {
    router.post(`/cotizaciones/${cot.id}/duplicar`)
}

const estados = [
    { value: '',               label: 'Todos los estados' },
    { value: 'borrador',       label: 'Borrador'          },
    { value: 'enviada',        label: 'Enviada'           },
    { value: 'aprobada',       label: 'Aprobada'          },
    { value: 'rechazada',      label: 'Rechazada'         },
    { value: 'vencida',        label: 'Vencida'           },
    { value: 'en_produccion',  label: 'En Producción'     },
]

// SVG line chart
const svgW = 320
const svgH = 80
const svgPad = { t: 8, r: 8, b: 20, l: 8 }

const chartPoints = computed(() => {
    const data = props.metricas?.por_mes ?? []
    if (!data.length) return { points: '', dots: [], labels: [] }

    const vals  = data.map(d => d.total)
    const max   = Math.max(...vals, 1)
    const min   = 0
    const w     = svgW - svgPad.l - svgPad.r
    const h     = svgH - svgPad.t - svgPad.b
    const step  = w / Math.max(data.length - 1, 1)

    const coords = data.map((d, i) => ({
        x: svgPad.l + i * step,
        y: svgPad.t + h - ((d.total - min) / (max - min)) * h,
        mes: d.mes,
        total: d.total,
    }))

    const points  = coords.map(c => `${c.x},${c.y}`).join(' ')
    const filled  = `${svgPad.l},${svgPad.t + h} ` + points + ` ${svgPad.l + w},${svgPad.t + h}`

    return { points, filled, dots: coords, labels: coords }
})
</script>

<template>
    <AppLayout title="Cotizaciones">
        <div class="max-w-7xl mx-auto">

            <!-- Cabecera -->
            <div class="flex items-center justify-between mb-4">
                <h1 class="text-lg font-semibold text-tinta-900">Cotizaciones</h1>
                <div class="flex items-center gap-2">
                    <button @click="mostrarMetricas = !mostrarMetricas"
                        class="p-2 rounded-xl border border-linea text-tinta-300 hover:text-tinta-700 hover:bg-tinta-50 transition-colors"
                        :title="mostrarMetricas ? 'Ocultar métricas' : 'Mostrar métricas'">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                        </svg>
                    </button>
                    <a href="/cotizaciones/crear"
                       class="flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold text-white"
                       style="background:var(--marca);">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                        </svg>
                        Nueva cotización
                    </a>
                </div>
            </div>

            <!-- Métricas + gráfico -->
            <div v-if="mostrarMetricas" class="mb-4">
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-3">
                    <div class="bg-white rounded-2xl border border-linea shadow-sm px-4 py-3">
                        <p class="text-xs text-tinta-300 mb-1">Borradores</p>
                        <p class="text-2xl font-semibold text-tinta-900">{{ metricas?.borradores ?? 0 }}</p>
                    </div>
                    <div class="bg-white rounded-2xl border border-linea shadow-sm px-4 py-3">
                        <p class="text-xs text-tinta-300 mb-1">Enviadas</p>
                        <p class="text-2xl font-semibold" style="color:#1D4ED8;">{{ metricas?.enviadas ?? 0 }}</p>
                    </div>
                    <div class="bg-white rounded-2xl border border-linea shadow-sm px-4 py-3">
                        <p class="text-xs text-tinta-300 mb-1">Aprobadas</p>
                        <p class="text-2xl font-semibold" style="color:#065F46;">{{ metricas?.aprobadas ?? 0 }}</p>
                    </div>
                    <div class="bg-white rounded-2xl border border-linea shadow-sm px-4 py-3">
                        <p class="text-xs text-tinta-300 mb-1">Aprobado este mes</p>
                        <p class="text-xl font-semibold" style="color:var(--marca);">${{ formatCOP(metricas?.total_mes ?? 0) }}</p>
                    </div>
                </div>

                <!-- Alerta: cotizaciones enviadas sin respuesta -->
                <div v-if="metricas?.necesitan_seguimiento > 0"
                    class="mb-3 px-4 py-3 rounded-2xl"
                    style="background:#FEF3C7; border:1px solid #F59E0B;">
                    <div class="flex items-center gap-3 mb-2">
                        <svg class="w-5 h-5 shrink-0" style="color:#92400E;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
                        </svg>
                        <p class="text-sm font-medium" style="color:#92400E;">
                            {{ metricas.necesitan_seguimiento }} cotización(es) llevan más de 5 días en estado "Enviada" sin respuesta — requieren seguimiento:
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2 pl-8">
                        <a v-for="c in metricas.cotizaciones_seguimiento" :key="c.id"
                            :href="`/cotizaciones/${c.id}`"
                            class="text-xs font-semibold px-2.5 py-1 rounded-full bg-white hover:underline"
                            style="color:#92400E; border:1px solid #F59E0B;">
                            {{ c.numero }} · {{ c.dias }}d
                        </a>
                    </div>
                </div>

                <!-- Mini gráfico: aprobadas últimos 6 meses -->
                <div class="bg-white rounded-2xl border border-linea shadow-sm px-4 py-3">
                    <p class="text-xs font-semibold text-tinta-300 uppercase tracking-[0.12em] mb-2">Aprobadas — últimos 6 meses</p>
                    <svg :viewBox="`0 0 ${svgW} ${svgH}`" class="w-full" style="height:80px;">
                        <!-- área rellena -->
                        <polygon v-if="chartPoints.filled"
                            :points="chartPoints.filled"
                            fill="var(--marca)" fill-opacity="0.08"/>
                        <!-- línea -->
                        <polyline v-if="chartPoints.points"
                            :points="chartPoints.points"
                            fill="none" stroke="var(--marca)" stroke-width="1.6"
                            stroke-linecap="round" stroke-linejoin="round"/>
                        <!-- puntos -->
                        <circle v-for="(dot, i) in chartPoints.dots" :key="i"
                            :cx="dot.x" :cy="dot.y" r="3"
                            fill="var(--marca)"/>
                        <!-- etiquetas mes -->
                        <text v-for="(dot, i) in chartPoints.labels" :key="`l${i}`"
                            :x="dot.x" :y="svgH - 3"
                            text-anchor="middle"
                            font-size="9" fill="#9CA3AF">
                            {{ dot.mes }}
                        </text>
                    </svg>
                </div>
            </div>

            <!-- Filtros -->
            <div class="bg-white rounded-xl border border-linea p-4 mb-5">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    <input v-model="filters.buscar" type="text" placeholder="Buscar número o cliente..."
                        class="rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:border-blue-400"/>
                    <select v-model="filters.estado"
                        class="rounded-xl border border-tinta-200 px-3 py-2 text-sm text-tinta-700 focus:outline-none focus:border-blue-400">
                        <option v-for="e in estados" :key="e.value" :value="e.value">{{ e.label }}</option>
                    </select>
                    <select v-model="filters.responsable_id"
                        class="rounded-xl border border-tinta-200 px-3 py-2 text-sm text-tinta-700 focus:outline-none focus:border-blue-400">
                        <option value="">Todos los responsables</option>
                        <option v-for="r in responsables" :key="r.id" :value="r.id">{{ r.name }}</option>
                    </select>
                    <div class="flex items-center gap-2">
                        <input v-model="filters.desde" type="date"
                            class="flex-1 min-w-0 rounded-xl border border-tinta-200 px-3 py-2 text-sm text-tinta-700 focus:outline-none focus:border-blue-400"/>
                        <span class="text-xs text-tinta-300 shrink-0">–</span>
                        <input v-model="filters.hasta" type="date"
                            class="flex-1 min-w-0 rounded-xl border border-tinta-200 px-3 py-2 text-sm text-tinta-700 focus:outline-none focus:border-blue-400"/>
                    </div>
                </div>
                <div v-if="filters.buscar || filters.estado || filters.responsable_id || filters.desde || filters.hasta"
                     class="flex justify-end mt-3">
                    <button @click="limpiarFiltros"
                        class="text-xs text-tinta-400 hover:text-red-600 flex items-center gap-1 transition-colors">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                        Limpiar filtros
                    </button>
                </div>
            </div>

            <!-- Tabla desktop / tarjetas mobile -->
            <div class="bg-white rounded-2xl border border-linea overflow-hidden">

                <!-- Desktop: tabla -->
                <div class="hidden md:block overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr style="background:#F8FAFC; border-bottom: 1px solid #E5E7EB;">
                                <th class="text-left px-4 py-3 text-xs font-semibold text-tinta-400 uppercase">Número</th>
                                <th class="text-left px-4 py-3 text-xs font-semibold text-tinta-400 uppercase">Cliente</th>
                                <th class="text-left px-4 py-3 text-xs font-semibold text-tinta-400 uppercase">Responsable</th>
                                <th class="text-center px-3 py-3 text-xs font-semibold text-tinta-400 uppercase">Ítems</th>
                                <th class="text-center px-3 py-3 text-xs font-semibold text-tinta-400 uppercase">Creación</th>
                                <th class="text-center px-3 py-3 text-xs font-semibold text-tinta-400 uppercase">Validez</th>
                                <th class="text-right px-4 py-3 text-xs font-semibold text-tinta-400 uppercase">Total</th>
                                <th class="text-center px-3 py-3 text-xs font-semibold text-tinta-400 uppercase">Estado</th>
                                <th class="text-right px-4 py-3 text-xs font-semibold text-tinta-400 uppercase">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <tr v-for="cot in cotizaciones.data" :key="cot.id" class="hover:bg-tinta-50 transition-colors">
                                <td class="px-4 py-3">
                                    <a :href="`/cotizaciones/${cot.id}`" class="font-semibold hover:underline" style="color:var(--marca);">
                                        {{ cot.numero }}
                                    </a>
                                </td>
                                <td class="px-4 py-3 text-tinta-700">
                                    {{ cot.cliente?.nombre ?? cot.nombre_contacto_override ?? '—' }}
                                </td>
                                <td class="px-4 py-3 text-tinta-400 text-xs">{{ cot.responsable?.name }}</td>
                                <td class="px-3 py-3 text-center">
                                    <span class="text-xs text-tinta-400 font-medium">{{ cot.items_count ?? 0 }}</span>
                                </td>
                                <td class="px-3 py-3 text-center text-tinta-400 text-xs">{{ formatFecha(cot.fecha_creacion) }}</td>
                                <td class="px-3 py-3 text-center text-tinta-400 text-xs">{{ formatFecha(cot.fecha_validez) }}</td>
                                <td class="px-4 py-3 text-right font-semibold text-tinta-900">${{ formatCOP(cot.total) }}</td>
                                <td class="px-3 py-3 text-center">
                                    <span class="text-xs px-2 py-0.5 rounded-full font-medium"
                                        :style="`background:${cot.estado_badge?.bg};color:${cot.estado_badge?.text};`">
                                        {{ cot.estado_badge?.label }}
                                    </span>
                                    <span v-if="cot.dias_sin_respuesta >= 5"
                                        class="block mt-1 text-[10px] font-semibold" style="color:#B45309;"
                                        :title="`${cot.dias_sin_respuesta} días sin respuesta`">
                                        ⚠ {{ cot.dias_sin_respuesta }}d sin respuesta
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center justify-end gap-1">
                                        <a :href="`/cotizaciones/${cot.id}`" class="p-1.5 rounded-lg text-tinta-300 hover:text-blue-600 hover:bg-blue-50" title="Ver">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                            </svg>
                                        </a>
                                        <a :href="`/cotizaciones/${cot.id}/editar`" class="p-1.5 rounded-lg text-tinta-300 hover:text-tinta-700 hover:bg-tinta-100" title="Editar">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                            </svg>
                                        </a>
                                        <a :href="`/cotizaciones/${cot.id}/pdf`" target="_blank" class="p-1.5 rounded-lg text-tinta-300 hover:text-red-600 hover:bg-red-50" title="PDF">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                                            </svg>
                                        </a>
                                        <button @click="duplicar(cot)" class="p-1.5 rounded-lg text-tinta-300 hover:text-green-600 hover:bg-green-50" title="Duplicar">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                                            </svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="cotizaciones.data.length === 0">
                                <td colspan="9" class="px-4 py-12 text-center text-tinta-300 text-sm">
                                    No hay cotizaciones.
                                    <a href="/cotizaciones/crear" class="ml-1 underline" style="color:var(--marca);">Crear una nueva</a>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile: tarjetas -->
                <div class="md:hidden divide-y divide-linea">
                    <div v-if="cotizaciones.data.length === 0" class="px-4 py-12 text-center text-tinta-300 text-sm">
                        Sin cotizaciones.
                    </div>
                    <div v-for="cot in cotizaciones.data" :key="cot.id" class="p-4">
                        <div class="flex items-center justify-between mb-2">
                            <a :href="`/cotizaciones/${cot.id}`" class="font-semibold text-sm" style="color:var(--marca);">{{ cot.numero }}</a>
                            <span class="text-xs px-2 py-0.5 rounded-full font-medium"
                                :style="`background:${cot.estado_badge?.bg};color:${cot.estado_badge?.text};`">
                                {{ cot.estado_badge?.label }}
                            </span>
                        </div>
                        <p class="text-sm text-tinta-700 font-medium">{{ cot.cliente?.nombre ?? cot.nombre_contacto_override ?? '—' }}</p>
                        <p v-if="cot.dias_sin_respuesta >= 5" class="text-[11px] font-semibold mt-1" style="color:#B45309;">
                            ⚠ {{ cot.dias_sin_respuesta }} días sin respuesta
                        </p>
                        <div class="flex items-center justify-between mt-2">
                            <p class="text-xs text-tinta-300">
                                {{ formatFecha(cot.fecha_creacion) }} · {{ cot.responsable?.name }}
                                <span v-if="cot.items_count" class="ml-1">· {{ cot.items_count }} ítem{{ cot.items_count !== 1 ? 's' : '' }}</span>
                            </p>
                            <p class="text-sm font-semibold" style="color:var(--marca);">${{ formatCOP(cot.total) }}</p>
                        </div>
                        <div class="flex gap-2 mt-3">
                            <a :href="`/cotizaciones/${cot.id}`" class="flex-1 text-center py-2 rounded-xl border border-linea text-xs text-tinta-500">Ver</a>
                            <a :href="`/cotizaciones/${cot.id}/editar`" class="flex-1 text-center py-2 rounded-xl border border-linea text-xs text-tinta-500">Editar</a>
                            <a :href="`/cotizaciones/${cot.id}/pdf`" target="_blank" class="flex-1 text-center py-2 rounded-xl border border-red-200 text-xs text-red-500">PDF</a>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Paginación -->
            <div v-if="cotizaciones.last_page > 1" class="flex justify-center gap-2 mt-5">
                <a v-for="link in cotizaciones.links" :key="link.label"
                   :href="link.url || '#'"
                   v-html="link.label"
                   class="px-3 py-1.5 rounded-lg text-sm border transition-colors"
                   :class="link.active
                       ? 'text-white border-transparent'
                       : 'border-tinta-200 text-tinta-500 hover:bg-tinta-50'"
                   :style="link.active ? 'background:var(--marca);' : ''"
                />
            </div>

        </div>
    </AppLayout>
</template>
