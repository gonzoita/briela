<script setup>
import { ref, onMounted, computed } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { colorMarca } from '@/marca'

const props = defineProps({
    informe:         Object,
    etiquetas_campos: Object,
})

const datos      = ref([])
const totales    = ref({})
const cargando   = ref(false)
const ejecutado  = ref(false)
const error      = ref('')

const hayTotales = computed(() => Object.keys(totales.value).length > 0)

const fuenteLabel = {
    ops:           'OPs',
    colaboradores: 'Colaboradores',
    cotizaciones:  'Cotizaciones',
    pasos:         'Pasos',
}

// ─── Colores para gráficas simples SVG ───────────────────────────────────────
const COLORES = [colorMarca(), '#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#06B6D4', '#F97316']

// ─── Ejecutar ──────────────────────────────────────────────────────────────

async function ejecutar() {
    cargando.value = true
    error.value    = ''

    try {
        // Leer XSRF token de la cookie
        const cookie = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))
        const token  = cookie ? decodeURIComponent(cookie.split('=')[1]) : ''

        const res = await fetch(`/informes/${props.informe.id}/ejecutar`, {
            method:  'POST',
            headers: {
                'Content-Type':     'application/json',
                'Accept':           'application/json',
                'X-XSRF-TOKEN':     token,
                'X-Requested-With': 'XMLHttpRequest',
            },
        })

        if (!res.ok) throw new Error('Error al ejecutar el informe')
        const json   = await res.json()
        datos.value    = json.datos ?? []
        totales.value  = json.totales ?? {}
        ejecutado.value = true
    } catch (e) {
        error.value = 'No se pudo ejecutar el informe. Intenta de nuevo.'
    } finally {
        cargando.value = false
    }
}

onMounted(() => ejecutar())

// ─── Gráfica SVG simple ────────────────────────────────────────────────────

const graficaData = computed(() => {
    if (!datos.value.length || props.informe.tipo_grafica === 'ninguna' || !props.informe.tipo_grafica) {
        return null
    }
    const primer_campo_num = props.informe.campos.find(c => {
        const vals = datos.value.map(r => r[c])
        return vals.some(v => !isNaN(parseFloat(String(v).replace(',', '.').replace('.', ''))))
    })
    const campo_label = props.informe.campos[0]

    return {
        labels:  datos.value.slice(0, 15).map(r => String(r[campo_label] ?? '').slice(0, 18)),
        valores: datos.value.slice(0, 15).map(r => {
            if (!primer_campo_num) return 1
            const raw = String(r[primer_campo_num] ?? '0').replace('%', '').replace('$', '').replace(/\./g, '').replace(',', '.')
            return parseFloat(raw) || 0
        }),
    }
})

const alturaBarras = 200
const anchoBarras  = computed(() => graficaData.value ? Math.max(600, graficaData.value.labels.length * 60) : 600)
const maxValor     = computed(() => graficaData.value ? Math.max(...graficaData.value.valores, 1) : 1)

function altoBar(val) {
    return Math.max(4, (val / maxValor.value) * (alturaBarras - 30))
}

// ─── Gráfica Torta SVG ────────────────────────────────────────────────────

const tortaSlices = computed(() => {
    if (!graficaData.value) return []
    const total = graficaData.value.valores.reduce((a, b) => a + b, 0)
    if (!total) return []

    let angulo = -90
    return graficaData.value.valores.map((val, i) => {
        const pct    = val / total
        const grados = pct * 360
        const rad1   = (angulo * Math.PI) / 180
        const rad2   = ((angulo + grados) * Math.PI) / 180
        const r      = 90
        const cx = 110; const cy = 110

        const x1 = cx + r * Math.cos(rad1)
        const y1 = cy + r * Math.sin(rad1)
        const x2 = cx + r * Math.cos(rad2)
        const y2 = cy + r * Math.sin(rad2)
        const large = grados > 180 ? 1 : 0

        const d = `M ${cx} ${cy} L ${x1} ${y1} A ${r} ${r} 0 ${large} 1 ${x2} ${y2} Z`
        const slice = { d, color: COLORES[i % COLORES.length], label: graficaData.value.labels[i], pct: Math.round(pct * 100) }
        angulo += grados
        return slice
    })
})

// Puntos para gráfica de líneas
const lineaPuntos = computed(() => {
    if (!graficaData.value) return ''
    const { valores } = graficaData.value
    const w = Math.max(500, valores.length * 60)
    return valores.map((v, i) => {
        const x = 40 + (i / Math.max(valores.length - 1, 1)) * (w - 80)
        const y = alturaBarras - 30 - altoBar(v)
        return `${x},${y}`
    }).join(' ')
})
</script>

<template>
    <AppLayout :title="informe.nombre">
        <div class="max-w-5xl mx-auto space-y-6">

            <!-- Cabecera -->
            <div class="bg-white rounded-2xl border border-linea shadow-sm p-5">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <span class="text-xs font-semibold px-2.5 py-1 rounded-full bg-blue-100 text-blue-700">
                                {{ fuenteLabel[informe.fuente] ?? informe.fuente }}
                            </span>
                            <span v-if="informe.publico"
                                class="text-xs font-semibold px-2.5 py-1 rounded-full bg-tinta-100 text-tinta-400">
                                Público
                            </span>
                        </div>
                        <h1 class="text-lg font-semibold text-tinta-900">{{ informe.nombre }}</h1>
                        <p v-if="informe.descripcion" class="text-sm text-tinta-400 mt-0.5">{{ informe.descripcion }}</p>
                    </div>

                    <!-- Acciones -->
                    <div class="flex items-center gap-2 flex-wrap">
                        <button
                            @click="ejecutar"
                            :disabled="cargando"
                            class="flex items-center gap-2 px-4 py-2 rounded-xl text-white text-sm font-semibold disabled:opacity-60"
                            style="background-color:var(--marca);"
                        >
                            <svg v-if="cargando" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                            </svg>
                            <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            {{ cargando ? 'Ejecutando...' : 'Ejecutar' }}
                        </button>
                        <a :href="`/informes/${informe.id}/csv`"
                            class="flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold border border-linea text-tinta-700 hover:bg-tinta-50 transition-colors">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                            </svg>
                            CSV
                        </a>
                        <a :href="`/informes/${informe.id}/pdf`"
                            class="flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold border border-linea text-tinta-700 hover:bg-tinta-50 transition-colors">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                            </svg>
                            PDF
                        </a>
                    </div>
                </div>
            </div>

            <!-- Error -->
            <div v-if="error" class="bg-red-50 border border-red-200 rounded-2xl px-5 py-4 text-sm text-red-700">
                {{ error }}
            </div>

            <!-- Loading -->
            <div v-if="cargando && !ejecutado" class="flex flex-col items-center py-16 gap-3">
                <svg class="w-10 h-10 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-20" cx="12" cy="12" r="10" stroke="var(--marca)" stroke-width="4"/>
                    <path class="opacity-80" fill="var(--marca)" d="M4 12a8 8 0 018-8v8H4z"/>
                </svg>
                <p class="text-sm text-tinta-300">Ejecutando informe...</p>
            </div>

            <!-- Gráfica SVG -->
            <div v-if="ejecutado && graficaData && informe.tipo_grafica !== 'ninguna'"
                class="bg-white rounded-2xl border border-linea shadow-sm p-5">
                <h2 class="text-sm font-semibold text-tinta-700 mb-4">Gráfica — {{ informe.tipo_grafica }}</h2>

                <!-- Barras -->
                <div v-if="informe.tipo_grafica === 'barras'" class="overflow-x-auto">
                    <svg :width="anchoBarras" :height="alturaBarras + 40" xmlns="http://www.w3.org/2000/svg">
                        <g v-for="(val, i) in graficaData.valores" :key="i">
                            <rect
                                :x="40 + i * 60"
                                :y="alturaBarras - 30 - altoBar(val)"
                                width="40"
                                :height="altoBar(val)"
                                :fill="COLORES[i % COLORES.length]"
                                rx="4"
                            />
                            <text
                                :x="60 + i * 60"
                                :y="alturaBarras + 5"
                                text-anchor="middle"
                                font-size="9"
                                fill="#6B7280"
                            >{{ graficaData.labels[i] }}</text>
                            <text
                                :x="60 + i * 60"
                                :y="alturaBarras - 34 - altoBar(val)"
                                text-anchor="middle"
                                font-size="9"
                                fill="#374151"
                            >{{ val }}</text>
                        </g>
                        <line x1="40" :y1="alturaBarras - 30" :x2="anchoBarras - 10" :y2="alturaBarras - 30" stroke="#E5E7EB" stroke-width="1"/>
                    </svg>
                </div>

                <!-- Líneas -->
                <div v-if="informe.tipo_grafica === 'lineas'" class="overflow-x-auto">
                    <svg :width="anchoBarras" :height="alturaBarras + 40" xmlns="http://www.w3.org/2000/svg">
                        <polyline
                            :points="lineaPuntos"
                            fill="none"
                            stroke="var(--marca)"
                            stroke-width="1.8"
                            stroke-linejoin="round"
                        />
                        <g v-for="(val, i) in graficaData.valores" :key="i">
                            <circle
                                :cx="40 + (i / Math.max(graficaData.valores.length - 1, 1)) * (anchoBarras - 80)"
                                :cy="alturaBarras - 30 - altoBar(val)"
                                r="4"
                                fill="var(--marca)"
                            />
                            <text
                                :x="40 + (i / Math.max(graficaData.valores.length - 1, 1)) * (anchoBarras - 80)"
                                :y="alturaBarras + 5"
                                text-anchor="middle"
                                font-size="9"
                                fill="#6B7280"
                            >{{ graficaData.labels[i] }}</text>
                        </g>
                        <line x1="40" :y1="alturaBarras - 30" :x2="anchoBarras - 10" :y2="alturaBarras - 30" stroke="#E5E7EB" stroke-width="1"/>
                    </svg>
                </div>

                <!-- Torta -->
                <div v-if="informe.tipo_grafica === 'torta'" class="flex flex-wrap gap-6 items-start">
                    <svg width="220" height="220" viewBox="0 0 220 220" xmlns="http://www.w3.org/2000/svg">
                        <path v-for="(s, i) in tortaSlices" :key="i" :d="s.d" :fill="s.color"/>
                    </svg>
                    <div class="flex flex-col gap-2 py-2">
                        <div v-for="(s, i) in tortaSlices" :key="i" class="flex items-center gap-2 text-xs">
                            <span class="w-3 h-3 rounded-sm shrink-0" :style="`background:${s.color}`"/>
                            <span class="text-tinta-700">{{ s.label }}</span>
                            <span class="font-semibold text-tinta-900 ml-1">{{ s.pct }}%</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabla de resultados -->
            <div v-if="ejecutado" class="bg-white rounded-2xl border border-linea shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-linea flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-tinta-700">Resultados</h2>
                    <span class="text-xs text-tinta-300 font-medium">{{ datos.length }} registro(s)</span>
                </div>

                <div v-if="!datos.length" class="px-5 py-12 text-center text-sm text-tinta-300">
                    No se encontraron resultados con los filtros configurados.
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-tinta-50 border-b border-linea">
                                <th
                                    v-for="campo in informe.campos"
                                    :key="campo"
                                    class="px-4 py-3 text-left text-xs font-semibold text-tinta-400 uppercase tracking-wide whitespace-nowrap"
                                >
                                    {{ etiquetas_campos[campo] ?? campo }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <tr
                                v-for="(fila, i) in datos"
                                :key="i"
                                class="hover:bg-tinta-50 transition-colors"
                            >
                                <td
                                    v-for="campo in informe.campos"
                                    :key="campo"
                                    class="px-4 py-3 text-tinta-900 whitespace-nowrap"
                                >
                                    {{ fila[campo] ?? '—' }}
                                </td>
                            </tr>
                        </tbody>
                        <!-- Fila de totales / promedios -->
                        <tfoot v-if="hayTotales">
                            <tr class="border-t-2 border-linea bg-tinta-50 font-semibold">
                                <td
                                    v-for="(campo, idx) in informe.campos"
                                    :key="campo"
                                    class="px-4 py-3 whitespace-nowrap"
                                    :style="idx === 0 ? 'color:var(--marca)' : ''"
                                >
                                    <template v-if="totales[campo]">
                                        <span class="text-[10px] uppercase tracking-wide text-tinta-300 mr-1">
                                            {{ totales[campo].tipo === 'promedio' ? 'Prom.' : 'Total' }}
                                        </span>
                                        <span style="color:var(--marca)">{{ totales[campo].texto }}</span>
                                    </template>
                                    <span v-else-if="idx === 0" class="text-tinta-400">Totales</span>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

        </div>
    </AppLayout>
</template>
