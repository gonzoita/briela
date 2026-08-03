<script setup>
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    comision: Object,
    desglose: Array,
    esAdmin: Boolean,
    bloqueada: Boolean,
})

const badgeEstado = (estado) => {
    const map = {
        proyectada: 'bg-blue-100 text-blue-700',
        confirmada: 'bg-yellow-100 text-yellow-700',
        ejecutada:  'bg-green-100 text-green-700',
        liquidada:  'bg-gray-100 text-gray-600',
    }
    return map[estado] || 'bg-gray-100 text-gray-600'
}

const labelEstado = (estado) => ({
    proyectada: 'Proyectada',
    confirmada: 'Confirmada',
    ejecutada:  'Ejecutada',
    liquidada:  '✓ Liquidada',
}[estado] || estado)

const formatMes = (mes) => {
    if (!mes) return ''
    const [year, month] = mes.substring(0, 7).split('-')
    const meses = ['Ene','Feb','Mar','Abr','May','Jun',
                   'Jul','Ago','Sep','Oct','Nov','Dic']
    return `${meses[parseInt(month) - 1]} ${year}`
}

const formatCOP = (val) => new Intl.NumberFormat('es-CO', {
    style: 'currency', currency: 'COP',
    minimumFractionDigits: 0, maximumFractionDigits: 0
}).format(val || 0)

const formatFecha = (f) => {
    if (!f) return '—'
    return new Date(f).toLocaleDateString('es-CO', {
        year: 'numeric', month: 'short', day: 'numeric'
    })
}

const liquidar = () => {
    if (!confirm(
        '¿Confirmas que esta comisión fue pagada?\n\n' +
        'Una vez liquidada NO se puede modificar.'
    )) return

    const token = document.cookie.split('; ')
        .find(r => r.startsWith('XSRF-TOKEN='))
    const xsrf = token ? decodeURIComponent(token.split('=')[1]) : ''

    fetch(`/comisiones/${props.comision.id}/liquidar`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': xsrf,
        },
    }).then(() => router.reload())
}
</script>

<template>
    <AppLayout title="Detalle de Comisión">
        <div class="max-w-5xl mx-auto px-4 py-4">
            <div class="grid grid-cols-1 md:grid-cols-[320px_1fr] gap-4 items-start">

                <!-- ── Columna izquierda — info general ── -->
                <div class="bg-white rounded-xl border border-gray-200 p-5">

                    <!-- Header con botón volver -->
                    <div class="flex items-center gap-3 mb-4">
                        <a href="/comisiones"
                           class="text-gray-400 hover:text-gray-600 transition-colors">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24"
                                 stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="M15 19l-7-7 7-7"/>
                            </svg>
                        </a>
                        <h1 class="text-lg font-bold text-gray-800">Detalle de Comisión</h1>
                    </div>

                    <!-- Badge estado -->
                    <div class="mb-4">
                        <span :class="[badgeEstado(comision.estado),
                                       'px-3 py-1 rounded-full text-sm font-medium']">
                            {{ labelEstado(comision.estado) }}
                        </span>
                        <span v-if="bloqueada"
                              class="ml-2 text-xs text-red-500 font-medium">
                            🔒 Liquidada — no modificable
                        </span>
                    </div>

                    <!-- Info cotización -->
                    <div class="space-y-3 text-sm">
                        <div class="flex justify-between">
                            <span class="text-gray-500">Cotización</span>
                            <a :href="`/cotizaciones/${comision.cotizacion_id}`"
                               class="font-medium text-[var(--marca)] hover:underline">
                                {{ comision.cotizacion?.numero }}
                            </a>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Cliente</span>
                            <span class="font-medium text-right ml-2">
                                {{ comision.cotizacion?.cliente?.nombre }}
                            </span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Vendedor</span>
                            <span class="font-medium">{{ comision.user?.name }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Período</span>
                            <span class="font-medium">{{ formatMes(comision.periodo_mes) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Fecha cotización</span>
                            <span class="font-medium">
                                {{ formatFecha(comision.cotizacion?.fecha_creacion) }}
                            </span>
                        </div>
                    </div>

                    <!-- Total destacado -->
                    <div class="mt-5 pt-4 border-t border-gray-100">
                        <p class="text-xs text-gray-400 mb-1">Total comisión</p>
                        <p class="text-3xl font-bold text-[var(--marca)]">
                            {{ formatCOP(comision.total_comision) }}
                        </p>
                    </div>

                    <!-- Acciones -->
                    <div class="mt-4 space-y-2">
                        <a :href="`/comisiones/${comision.id}/pdf`"
                           target="_blank"
                           class="flex items-center justify-center gap-2 w-full py-2
                                  border border-[var(--marca)] text-[var(--marca)] rounded-lg
                                  text-sm hover:bg-blue-50 transition-colors">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24"
                                 stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                      d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0
                                      01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293
                                      l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                            Descargar PDF
                        </a>

                        <button
                            v-if="esAdmin && comision.estado !== 'liquidada'"
                            @click="liquidar"
                            class="w-full py-2 bg-green-600 text-white rounded-lg
                                   text-sm font-medium hover:bg-green-700 transition-colors">
                            ✓ Marcar como liquidada (pagada)
                        </button>
                    </div>
                </div>

                <!-- ── Columna derecha — desglose por ítem ── -->
                <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-100">
                        <h2 class="font-semibold text-gray-800">Desglose por ítem</h2>
                        <p class="text-xs text-gray-400 mt-0.5">
                            Solo se muestran ítems con comisión configurada
                        </p>
                    </div>

                    <!-- Desktop table -->
                    <div class="hidden sm:block overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="text-left px-5 py-3 text-xs font-semibold
                                               text-gray-500 uppercase">Producto / Servicio</th>
                                    <th class="text-right px-3 py-3 text-xs font-semibold
                                               text-gray-500 uppercase">Cant.</th>
                                    <th class="text-right px-3 py-3 text-xs font-semibold
                                               text-gray-500 uppercase">P.Unit</th>
                                    <th class="text-right px-3 py-3 text-xs font-semibold
                                               text-gray-500 uppercase">Dto%</th>
                                    <th class="text-right px-3 py-3 text-xs font-semibold
                                               text-gray-500 uppercase">Com%</th>
                                    <th class="text-right px-5 py-3 text-xs font-semibold
                                               text-gray-500 uppercase">Comisión</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="(item, i) in desglose" :key="i"
                                    class="hover:bg-gray-50 transition-colors">
                                    <td class="px-5 py-3">
                                        <p class="font-medium text-gray-800">{{ item.descripcion }}</p>
                                        <span class="text-xs px-2 py-0.5 rounded-full"
                                              :class="item.tipo === 'ensamble'
                                                ? 'bg-orange-100 text-orange-600'
                                                : 'bg-blue-100 text-blue-600'">
                                            {{ item.tipo }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-3 text-right text-gray-600">{{ item.cantidad }}</td>
                                    <td class="px-3 py-3 text-right text-gray-600">
                                        {{ formatCOP(item.precio_unitario) }}
                                    </td>
                                    <td class="px-3 py-3 text-right text-gray-500">
                                        {{ item.descuento_pct > 0 ? item.descuento_pct + '%' : '—' }}
                                    </td>
                                    <td class="px-3 py-3 text-right">
                                        <span class="font-medium text-amber-600">{{ item.comision_pct }}%</span>
                                    </td>
                                    <td class="px-5 py-3 text-right">
                                        <span class="font-bold text-gray-800">
                                            {{ formatCOP(item.comision_valor) }}
                                        </span>
                                    </td>
                                </tr>

                                <tr v-if="desglose.length === 0">
                                    <td colspan="6"
                                        class="px-5 py-8 text-center text-gray-400 text-sm">
                                        Esta cotización no tiene ítems con comisión configurada
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot class="bg-amber-50 border-t-2 border-amber-200">
                                <tr>
                                    <td colspan="5"
                                        class="px-5 py-3 text-sm font-semibold text-amber-700 text-right">
                                        TOTAL COMISIÓN
                                    </td>
                                    <td class="px-5 py-3 text-right">
                                        <span class="text-lg font-bold text-amber-800">
                                            {{ formatCOP(comision.total_comision) }}
                                        </span>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <!-- Mobile list -->
                    <div class="sm:hidden divide-y divide-gray-100">
                        <div v-if="desglose.length === 0"
                             class="px-5 py-8 text-center text-gray-400 text-sm">
                            Esta cotización no tiene ítems con comisión configurada
                        </div>
                        <div v-for="(item, i) in desglose" :key="i" class="px-4 py-3">
                            <div class="flex justify-between items-start mb-1">
                                <div class="flex-1 pr-3">
                                    <p class="font-medium text-gray-800 text-sm">{{ item.descripcion }}</p>
                                    <span class="text-xs px-2 py-0.5 rounded-full"
                                          :class="item.tipo === 'ensamble'
                                            ? 'bg-orange-100 text-orange-600'
                                            : 'bg-blue-100 text-blue-600'">
                                        {{ item.tipo }}
                                    </span>
                                </div>
                                <span class="font-bold text-gray-800 text-sm shrink-0">
                                    {{ formatCOP(item.comision_valor) }}
                                </span>
                            </div>
                            <div class="flex gap-3 text-xs text-gray-500 mt-1">
                                <span>{{ item.cantidad }} u.</span>
                                <span>{{ formatCOP(item.precio_unitario) }}</span>
                                <span v-if="item.descuento_pct > 0">-{{ item.descuento_pct }}%</span>
                                <span class="text-amber-600 font-medium">Com: {{ item.comision_pct }}%</span>
                            </div>
                        </div>
                        <div class="bg-amber-50 px-4 py-3 flex justify-between items-center
                                    border-t-2 border-amber-200">
                            <span class="text-sm font-semibold text-amber-700">TOTAL COMISIÓN</span>
                            <span class="text-lg font-bold text-amber-800">
                                {{ formatCOP(comision.total_comision) }}
                            </span>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </AppLayout>
</template>
