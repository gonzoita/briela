<script setup>
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    porEstado:      { type: Object, default: () => ({}) },
    porFuente:      { type: Array,  default: () => [] },
    porResponsable: { type: Array,  default: () => [] },
    porMes:         { type: Array,  default: () => [] },
    porEtapa:       { type: Array,  default: () => [] },
    tasaConversion: { type: Number, default: 0 },
    totalPeriodo:   { type: Number, default: 0 },
    ganadosPeriodo: { type: Number, default: 0 },
    activos:        { type: Number, default: 0 },
    filtros:        { type: Object, default: () => ({}) },
})

const periodo = ref(props.filtros.periodo ?? 'mes')
const año     = ref(props.filtros.año     ?? new Date().getFullYear())
const mes     = ref(props.filtros.mes     ?? (new Date().getMonth() + 1))

function aplicarFiltros() {
    router.get('/crm/reportes', {
        periodo: periodo.value,
        año:     año.value,
        mes:     mes.value,
    }, { preserveState: true, preserveScroll: true })
}

// ── Gráfica barras por mes ───────────────────────────────────────────────────
const maxMes = computed(() => Math.max(...props.porMes.map(m => m.total), 1))

// ── Fuentes ──────────────────────────────────────────────────────────────────
const maxFuente = computed(() => Math.max(...props.porFuente.map(f => f.total), 1))

function pctFuente(total) {
    return props.totalPeriodo > 0 ? Math.round(total / props.totalPeriodo * 100) : 0
}

// ── Embudo etapas ────────────────────────────────────────────────────────────
const maxEtapa = computed(() => Math.max(...props.porEtapa.map(e => e.leads_count), 1))

// ── Conversión color ─────────────────────────────────────────────────────────
function colorConversion(pct) {
    if (pct > 30) return '#10B981'
    if (pct >= 15) return '#F59E0B'
    return '#EF4444'
}

const meses = [
    { v: 1, l: 'Enero' }, { v: 2, l: 'Febrero' }, { v: 3, l: 'Marzo' },
    { v: 4, l: 'Abril' }, { v: 5, l: 'Mayo' },    { v: 6, l: 'Junio' },
    { v: 7, l: 'Julio' }, { v: 8, l: 'Agosto' },  { v: 9, l: 'Septiembre' },
    { v: 10, l: 'Octubre' }, { v: 11, l: 'Noviembre' }, { v: 12, l: 'Diciembre' },
]

const años = Array.from({ length: 5 }, (_, i) => new Date().getFullYear() - i)
</script>

<template>
    <AppLayout title="Reportes CRM">
        <div class="max-w-5xl mx-auto space-y-6 pb-8">

            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold text-tinta-900">Reportes CRM</h1>
                    <p class="text-sm text-tinta-400 mt-0.5">Análisis de leads y conversiones</p>
                </div>

                <!-- Filtros de período -->
                <div class="flex flex-wrap gap-2 items-center">
                    <select v-model="periodo" @change="aplicarFiltros"
                        class="border border-tinta-200 rounded-lg px-3 py-1.5 text-sm bg-superficie focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]">
                        <option value="mes">Mes</option>
                        <option value="trimestre">Trimestre</option>
                        <option value="año">Año</option>
                    </select>

                    <select v-model="año" @change="aplicarFiltros"
                        class="border border-tinta-200 rounded-lg px-3 py-1.5 text-sm bg-superficie focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]">
                        <option v-for="a in años" :key="a" :value="a">{{ a }}</option>
                    </select>

                    <select v-if="periodo !== 'año'" v-model="mes" @change="aplicarFiltros"
                        class="border border-tinta-200 rounded-lg px-3 py-1.5 text-sm bg-superficie focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]">
                        <option v-for="m in meses" :key="m.v" :value="m.v">{{ m.l }}</option>
                    </select>
                </div>
            </div>

            <!-- Tarjetas métricas -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <div class="bg-superficie rounded-2xl border border-linea shadow-sm px-4 py-4">
                    <p class="text-xs text-tinta-400 font-medium">Total período</p>
                    <p class="text-3xl font-semibold mt-1" style="color: var(--marca);">{{ totalPeriodo }}</p>
                    <p class="text-xs text-tinta-300 mt-1">leads creados</p>
                </div>
                <div class="bg-superficie rounded-2xl border border-linea shadow-sm px-4 py-4">
                    <p class="text-xs text-tinta-400 font-medium">Ganados</p>
                    <p class="text-3xl font-semibold mt-1 text-green-600">{{ ganadosPeriodo }}</p>
                    <p class="text-xs text-tinta-300 mt-1">leads cerrados</p>
                </div>
                <div class="bg-superficie rounded-2xl border border-linea shadow-sm px-4 py-4">
                    <p class="text-xs text-tinta-400 font-medium">Conversión</p>
                    <p class="text-3xl font-semibold mt-1"
                        :style="{ color: colorConversion(tasaConversion) }">
                        {{ tasaConversion }}%
                    </p>
                    <p class="text-xs text-tinta-300 mt-1">tasa global</p>
                </div>
                <div class="bg-superficie rounded-2xl border border-linea shadow-sm px-4 py-4">
                    <p class="text-xs text-tinta-400 font-medium">Activos ahora</p>
                    <p class="text-3xl font-semibold mt-1 text-yellow-600">{{ activos }}</p>
                    <p class="text-xs text-tinta-300 mt-1">en pipeline</p>
                </div>
            </div>

            <!-- Gráfica leads por mes -->
            <div class="bg-superficie rounded-2xl border border-linea shadow-sm p-5">
                <h2 class="text-sm font-semibold text-tinta-700 mb-4">Leads últimos 6 meses</h2>
                <div v-if="porMes.length" class="overflow-x-auto">
                    <svg :width="Math.max(porMes.length * 80, 400)" height="180" class="block">
                        <g v-for="(m, i) in porMes" :key="i" :transform="`translate(${i * 80 + 20}, 0)`">
                            <!-- Barra total -->
                            <rect
                                :x="0" :y="140 - (m.total / maxMes) * 120"
                                :width="28" :height="(m.total / maxMes) * 120"
                                rx="4" fill="#BFDBFE"
                            />
                            <!-- Barra ganados -->
                            <rect
                                :x="32" :y="140 - (m.ganados / maxMes) * 120"
                                :width="28" :height="(m.ganados / maxMes) * 120"
                                rx="4" fill="#10B981"
                            />
                            <!-- Valor total -->
                            <text :x="14" :y="140 - (m.total / maxMes) * 120 - 4"
                                text-anchor="middle" font-size="10" fill="#6B7280">{{ m.total }}</text>
                            <!-- Valor ganados -->
                            <text :x="46" :y="140 - (m.ganados / maxMes) * 120 - 4"
                                text-anchor="middle" font-size="10" fill="#059669">{{ m.ganados }}</text>
                            <!-- Etiqueta mes -->
                            <text :x="30" :y="160" text-anchor="middle" font-size="10" fill="#9CA3AF">{{ m.label }}</text>
                        </g>
                    </svg>
                </div>
                <p v-else class="text-sm text-tinta-300 text-center py-6">Sin datos en este período</p>
                <!-- Leyenda -->
                <div class="flex gap-4 mt-3">
                    <div class="flex items-center gap-1.5 text-xs text-tinta-400">
                        <span class="w-3 h-3 rounded bg-blue-200 inline-block"></span> Total
                    </div>
                    <div class="flex items-center gap-1.5 text-xs text-tinta-400">
                        <span class="w-3 h-3 rounded bg-green-500 inline-block"></span> Ganados
                    </div>
                </div>
            </div>

            <!-- Dos columnas: Fuentes + Embudo etapas -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

                <!-- Leads por fuente -->
                <div class="bg-superficie rounded-2xl border border-linea shadow-sm p-5">
                    <h2 class="text-sm font-semibold text-tinta-700 mb-4">Leads por fuente</h2>
                    <div v-if="porFuente.length" class="space-y-3">
                        <div v-for="f in porFuente" :key="f.fuente">
                            <div class="flex items-center justify-between text-xs mb-1">
                                <span class="text-tinta-700 font-medium truncate max-w-[140px]">{{ f.fuente }}</span>
                                <span class="text-tinta-400 shrink-0 ml-2">{{ f.total }} ({{ pctFuente(f.total) }}%)</span>
                            </div>
                            <div class="h-2 bg-tinta-100 rounded-full overflow-hidden">
                                <div
                                    class="h-full rounded-full transition-all"
                                    style="background-color: var(--marca);"
                                    :style="{ width: `${(f.total / maxFuente) * 100}%` }"
                                ></div>
                            </div>
                        </div>
                    </div>
                    <p v-else class="text-sm text-tinta-300 text-center py-6">Sin datos</p>
                </div>

                <!-- Embudo por etapa -->
                <div class="bg-superficie rounded-2xl border border-linea shadow-sm p-5">
                    <h2 class="text-sm font-semibold text-tinta-700 mb-4">Pipeline activo por etapa</h2>
                    <div v-if="porEtapa.length" class="space-y-3">
                        <div v-for="e in porEtapa" :key="e.id">
                            <div class="flex items-center justify-between text-xs mb-1">
                                <div class="flex items-center gap-1.5 min-w-0">
                                    <span class="w-2 h-2 rounded-full shrink-0" :style="{ background: e.color }"></span>
                                    <span class="text-tinta-700 font-medium truncate max-w-[130px]">{{ e.nombre }}</span>
                                </div>
                                <span class="text-tinta-400 shrink-0 ml-2 font-semibold">{{ e.leads_count }}</span>
                            </div>
                            <div class="h-2 bg-tinta-100 rounded-full overflow-hidden">
                                <div
                                    class="h-full rounded-full transition-all"
                                    :style="{ width: `${(e.leads_count / maxEtapa) * 100}%`, background: e.color }"
                                ></div>
                            </div>
                        </div>
                    </div>
                    <p v-else class="text-sm text-tinta-300 text-center py-6">Sin etapas</p>
                </div>
            </div>

            <!-- Tabla por responsable -->
            <div class="bg-superficie rounded-2xl border border-linea shadow-sm p-5">
                <h2 class="text-sm font-semibold text-tinta-700 mb-4">Por responsable</h2>
                <div v-if="porResponsable.length" class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-linea">
                                <th class="text-left text-xs font-semibold text-tinta-400 pb-2">Responsable</th>
                                <th class="text-center text-xs font-semibold text-tinta-400 pb-2">Total</th>
                                <th class="text-center text-xs font-semibold text-tinta-400 pb-2">Ganados</th>
                                <th class="text-left text-xs font-semibold text-tinta-400 pb-2 pl-4">% Conversión</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-separador">
                            <tr v-for="r in porResponsable" :key="r.responsable" class="hover:bg-tinta-50">
                                <td class="py-2.5 font-medium text-tinta-900">{{ r.responsable }}</td>
                                <td class="py-2.5 text-center text-tinta-500">{{ r.total }}</td>
                                <td class="py-2.5 text-center text-green-600 font-semibold">{{ r.ganados }}</td>
                                <td class="py-2.5 pl-4">
                                    <div class="flex items-center gap-2">
                                        <div class="flex-1 h-2 bg-tinta-100 rounded-full overflow-hidden max-w-[80px]">
                                            <div
                                                class="h-full rounded-full"
                                                :style="{ width: `${r.conversion}%`, background: colorConversion(r.conversion) }"
                                            ></div>
                                        </div>
                                        <span class="text-xs font-semibold shrink-0"
                                            :style="{ color: colorConversion(r.conversion) }">
                                            {{ r.conversion }}%
                                        </span>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="text-sm text-tinta-300 text-center py-6">Sin datos en este período</p>
            </div>

        </div>
    </AppLayout>
</template>
