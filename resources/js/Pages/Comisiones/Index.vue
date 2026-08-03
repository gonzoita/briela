<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    comisiones:       { type: Array,   default: () => [] },
    totales:          { type: Object,  default: () => ({}) },
    totalMes:         { type: Number,  default: 0 },
    mesActual:        { type: String,  default: '' },
    mesesDisponibles: { type: Array,   default: () => [] },
    vendedores:       { type: Array,   default: () => [] },
    esAdmin:          { type: Boolean, default: false },
})

const mesSeleccionado = ref(props.mesActual)
const vendedorSel     = ref(null)

function filtrar() {
    router.get('/comisiones', {
        mes:         mesSeleccionado.value || undefined,
        vendedor_id: vendedorSel.value     || undefined,
    }, { preserveState: true, replace: true })
}

const formatCOP = (v) =>
    new Intl.NumberFormat('es-CO', {
        style: 'currency', currency: 'COP', maximumFractionDigits: 0,
    }).format(v ?? 0)

const formatMes = (mes) => {
    if (!mes) return ''
    const [year, month] = mes.split('-')
    const meses = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic']
    return `${meses[parseInt(month) - 1]} ${year}`
}

const estadoBadge = {
    proyectada: { clases: 'bg-amber-100 text-amber-700',   label: 'Proyectada' },
    confirmada: { clases: 'bg-blue-100 text-blue-700',     label: 'Confirmada' },
    ejecutada:  { clases: 'bg-purple-100 text-purple-700', label: 'Ejecutada'  },
    liquidada:  { clases: 'bg-green-100 text-green-700',   label: 'Liquidada'  },
}

async function liquidar(id) {
    if (!confirm('¿Marcar esta comisión como liquidada?')) return
    const token = (() => {
        const c = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))
        return c ? decodeURIComponent(c.split('=')[1]) : ''
    })()
    await fetch(`/comisiones/${id}/liquidar`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': token },
    })
    router.reload()
}
</script>

<template>
    <AppLayout title="Comisiones">
        <div class="max-w-5xl mx-auto px-4 py-4">

            <!-- Cabecera + filtros -->
            <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
                <h1 class="text-xl font-bold text-gray-900">Comisiones</h1>
                <div class="flex items-center gap-2 flex-wrap">
                    <select v-model="mesSeleccionado" @change="filtrar"
                        class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:outline-none focus:ring-2">
                        <option v-for="m in mesesDisponibles" :key="m" :value="m">{{ formatMes(m) }}</option>
                    </select>
                    <select v-if="esAdmin" v-model="vendedorSel" @change="filtrar"
                        class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:outline-none">
                        <option :value="null">Todos los vendedores</option>
                        <option v-for="v in vendedores" :key="v.id" :value="v.id">{{ v.name }}</option>
                    </select>
                    <a :href="`/comisiones/resumen-pdf?mes=${mesSeleccionado}${vendedorSel ? '&vendedor_id=' + vendedorSel : ''}`"
                       target="_blank"
                       class="flex items-center gap-2 px-4 py-1.5 bg-[var(--marca)] text-white
                              rounded-lg text-sm font-medium hover:bg-blue-800 transition-colors">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24"
                             stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                  d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0
                                  01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293
                                  l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        PDF Resumen
                    </a>
                </div>
            </div>

            <!-- Cards resumen -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
                <div class="rounded-xl border p-4" style="background:#EFF6FF;border-color:#BFDBFE;">
                    <p class="text-xs font-semibold uppercase mb-1" style="color:#3B82F6;">Proyectadas</p>
                    <p class="text-xl font-bold" style="color:#1D4ED8;">{{ formatCOP(totales.proyectada) }}</p>
                    <p class="text-xs mt-1" style="color:#93C5FD;">Cotizaciones enviadas</p>
                </div>
                <div class="rounded-xl border p-4" style="background:#FFFBEB;border-color:#FDE68A;">
                    <p class="text-xs font-semibold uppercase mb-1" style="color:#D97706;">Confirmadas</p>
                    <p class="text-xl font-bold" style="color:#92400E;">{{ formatCOP(totales.confirmada) }}</p>
                    <p class="text-xs mt-1" style="color:#FCD34D;">Cotizaciones aprobadas</p>
                </div>
                <div class="rounded-xl border p-4" style="background:#F0FDF4;border-color:#BBF7D0;">
                    <p class="text-xs font-semibold uppercase mb-1" style="color:#16A34A;">Ejecutadas</p>
                    <p class="text-xl font-bold" style="color:#14532D;">{{ formatCOP(totales.ejecutada) }}</p>
                    <p class="text-xs mt-1" style="color:#86EFAC;">OPs completadas</p>
                </div>
                <div class="rounded-xl border p-4 bg-white border-gray-200">
                    <p class="text-xs font-semibold uppercase mb-1 text-gray-500">Liquidadas</p>
                    <p class="text-xl font-bold text-gray-800">{{ formatCOP(totales.liquidada) }}</p>
                    <p class="text-xs mt-1 text-gray-400">Pagadas</p>
                </div>
            </div>

            <!-- Total del mes -->
            <div class="bg-white rounded-xl border border-gray-200 p-4 mb-4 flex items-center justify-between">
                <span class="text-sm font-semibold text-gray-700">Total del mes</span>
                <span class="text-xl font-bold" style="color:var(--marca);">{{ formatCOP(totalMes) }}</span>
            </div>

            <!-- Tabla de comisiones — desktop -->
            <div class="bg-white rounded-xl border border-gray-200 overflow-hidden hidden sm:block">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase">Cotización</th>
                            <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase">Cliente</th>
                            <th v-if="esAdmin" class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase">Vendedor</th>
                            <th class="text-left px-4 py-3 text-xs font-semibold text-gray-500 uppercase">Estado</th>
                            <th class="text-right px-4 py-3 text-xs font-semibold text-gray-500 uppercase">Comisión</th>
                            <th v-if="esAdmin" class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-if="comisiones.length === 0">
                            <td :colspan="esAdmin ? 6 : 4" class="px-4 py-10 text-center text-gray-400 text-sm">
                                Sin comisiones para este período
                            </td>
                        </tr>
                        <tr v-for="c in comisiones" :key="c.id"
                            @click="router.visit(`/comisiones/${c.id}`)"
                            class="hover:bg-gray-50 cursor-pointer transition-colors">
                            <td class="px-4 py-3">
                                <a :href="`/cotizaciones/${c.cotizacion_id}`"
                                    @click.stop
                                    class="font-semibold hover:underline" style="color:var(--marca);">
                                    {{ c.cotizacion?.numero }}
                                </a>
                            </td>
                            <td class="px-4 py-3 text-gray-700">{{ c.cotizacion?.cliente?.nombre }}</td>
                            <td v-if="esAdmin" class="px-4 py-3 text-gray-600">{{ c.user?.name }}</td>
                            <td class="px-4 py-3">
                                <span :class="['text-xs px-2 py-0.5 rounded-full font-medium', estadoBadge[c.estado]?.clases]">
                                    {{ estadoBadge[c.estado]?.label }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right font-bold text-gray-800">{{ formatCOP(c.total_comision) }}</td>
                            <td v-if="esAdmin" class="px-4 py-3 text-right">
                                <button v-if="c.estado !== 'liquidada'" type="button"
                                    @click.stop="liquidar(c.id)"
                                    class="text-xs font-medium underline" style="color:#16A34A;">
                                    Liquidar
                                </button>
                                <span v-else class="text-xs text-gray-400">✓ Pagada</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Lista mobile -->
            <div class="sm:hidden bg-white rounded-xl border border-gray-200 overflow-hidden">
                <div v-if="comisiones.length === 0" class="py-10 text-center text-gray-400 text-sm">
                    Sin comisiones para este período
                </div>
                <div v-else class="divide-y divide-gray-100">
                    <div v-for="c in comisiones" :key="c.id"
                        @click="router.visit(`/comisiones/${c.id}`)"
                        class="flex items-center gap-3 px-4 py-3 hover:bg-gray-50 cursor-pointer">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 mb-0.5">
                                <a :href="`/cotizaciones/${c.cotizacion_id}`"
                                    @click.stop
                                    class="text-sm font-semibold hover:underline" style="color:var(--marca);">
                                    {{ c.cotizacion?.numero }}
                                </a>
                                <span :class="['text-xs px-2 py-0.5 rounded-full font-medium', estadoBadge[c.estado]?.clases]">
                                    {{ estadoBadge[c.estado]?.label }}
                                </span>
                            </div>
                            <p class="text-xs text-gray-500 truncate">
                                {{ c.cotizacion?.cliente?.nombre }}
                                <span v-if="esAdmin && c.user?.name"> · {{ c.user?.name }}</span>
                            </p>
                        </div>
                        <div class="text-right shrink-0">
                            <p class="text-sm font-bold" style="color:var(--marca);">{{ formatCOP(c.total_comision) }}</p>
                            <button v-if="esAdmin && c.estado !== 'liquidada'" type="button"
                                @click.stop="liquidar(c.id)"
                                class="text-xs font-medium underline" style="color:#16A34A;">
                                Liquidar
                            </button>
                            <p v-else-if="c.estado === 'liquidada'" class="text-xs text-gray-400">Pagada</p>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </AppLayout>
</template>
