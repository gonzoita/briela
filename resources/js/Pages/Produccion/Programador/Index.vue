<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    fecha:             String,
    estaciones:        Array,
    pasosProgramados:  Array,
    pasosSinProgramar: Array,
    colaboradores:     Array,
})

// ─── Estado reactivo para polling ────────────────────────────────────────────
const pasosProgramados = ref(props.pasosProgramados ?? [])
const pasosSinProgramar = ref(props.pasosSinProgramar ?? [])
const ultimaActualizacion = ref(null)
const hayNuevos = ref(false)
let pollingIntervalo = null

// ─── Navegación de fecha ──────────────────────────────────────────────────────
const fechaInput = ref(props.fecha)

function irFecha(dias) {
    const d = new Date(fechaInput.value + 'T12:00:00')
    d.setDate(d.getDate() + dias)
    fechaInput.value = d.toISOString().split('T')[0]
    cargarFecha()
}

function cargarFecha() {
    router.get('/produccion/programador', { fecha: fechaInput.value }, { preserveState: false })
}

function formatFechaTitulo(f) {
    const d = new Date(f + 'T12:00:00')
    return d.toLocaleDateString('es-CO', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
}

// ─── Mobile tabs ─────────────────────────────────────────────────────────────
const tabMobile = ref('backlog')

// ─── Kanban: agrupar pasos por estación ───────────────────────────────────────
const kanbanPorEstacion = computed(() => {
    const map = {}
    for (const paso of pasosProgramados.value) {
        const id = paso.estacion_trabajo_id
        if (!map[id]) map[id] = []
        map[id].push(paso)
    }
    return map
})

// ─── Ayudantes de datos anidados ─────────────────────────────────────────────
function opNumero(paso) {
    return paso.op ?? paso.trabajo?.op_item?.op?.numero ?? '—'
}
function clienteNombre(paso) {
    return paso.cliente ?? paso.trabajo?.op_item?.op?.cliente?.nombre ?? paso.trabajo?.op_item?.op?.cliente ?? '—'
}
function ensambleNombre(paso) {
    return paso.trabajo?.op_item?.ensamble?.nombre
        ?? paso.trabajo?.op_item?.descripcion
        ?? '—'
}
function variablesInstancia(paso) {
    const v = paso.trabajo?.op_item?.variables_instancia
    if (!v || typeof v !== 'object') return {}
    return v
}
function tieneEquipoEnMantenimiento(estacion) {
    return estacion.equipos?.some(e => e.estado === 'en_mantenimiento')
}

// ─── Inline form: programar paso ─────────────────────────────────────────────
const programandoId = ref(null)
const programarForm = ref({
    estacion_trabajo_id:       null,
    colaborador_programado_id: null,
    tiempo_estimado_minutos:   60,
})

function abrirProgramar(paso) {
    programandoId.value = paso.id
    programarForm.value = {
        estacion_trabajo_id:       null,
        colaborador_programado_id: null,
        tiempo_estimado_minutos:   60,
    }
}

function cancelarProgramar() {
    programandoId.value = null
}

async function confirmarProgramar(pasoId) {
    if (!programarForm.value.estacion_trabajo_id) return
    await fetch(`/produccion/programador/pasos/${pasoId}/programar`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': getCsrf() },
        body: JSON.stringify({
            ...programarForm.value,
            fecha_programada: props.fecha,
        }),
    })
    programandoId.value = null
    router.reload({ preserveScroll: true })
}

async function desprogramar(pasoId) {
    await fetch(`/produccion/programador/pasos/${pasoId}/desprogramar`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': getCsrf() },
    })
    router.reload({ preserveScroll: true })
}

function getCsrf() {
    const match = document.cookie.split(';').find(c => c.trim().startsWith('XSRF-TOKEN='))
    return match ? decodeURIComponent(match.split('=')[1]) : ''
}

async function refrescarDatos() {
    try {
        const res = await fetch(`/produccion/programador/datos?fecha=${fechaInput.value}`, {
            headers: { 'X-XSRF-TOKEN': getCsrf() }
        })
        const data = await res.json()
        const completadosAntes = pasosProgramados.value.filter(p => p.completado).length
        const completadosDespues = data.pasosProgramados.filter(p => p.completado).length
        if (completadosDespues > completadosAntes) {
            hayNuevos.value = true
            setTimeout(() => { hayNuevos.value = false }, 3000)
        }
        pasosProgramados.value = data.pasosProgramados
        pasosSinProgramar.value = data.pasosSinProgramar
    } catch (e) {
        console.error('Error en polling:', e)
    } finally {
        ultimaActualizacion.value = new Date().toLocaleTimeString('es-CO', {
            hour: '2-digit', minute: '2-digit', second: '2-digit'
        })
    }
}

onMounted(() => {
    ultimaActualizacion.value = new Date().toLocaleTimeString('es-CO', {
        hour: '2-digit', minute: '2-digit', second: '2-digit'
    })
    pollingIntervalo = setInterval(refrescarDatos, 15000)
})

onUnmounted(() => {
    clearInterval(pollingIntervalo)
})
</script>

<template>
    <AppLayout title="Programador">
        <!-- ─── Header ───────────────────────────────────────────────────── -->
        <div class="flex flex-wrap items-center gap-3 mb-4">
            <h1 class="text-xl font-semibold text-tinta-900">Programador de Producción</h1>
            <div class="ml-auto flex items-center gap-2">
                <button
                    @click="irFecha(-1)"
                    class="w-8 h-8 rounded-lg border border-linea flex items-center justify-center text-tinta-400 hover:bg-tinta-50 transition-colors"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                    </svg>
                </button>
                <input
                    v-model="fechaInput"
                    type="date"
                    @change="cargarFecha"
                    class="rounded-xl border border-tinta-200 px-3 py-1.5 text-sm focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]"
                />
                <button
                    @click="irFecha(1)"
                    class="w-8 h-8 rounded-lg border border-linea flex items-center justify-center text-tinta-400 hover:bg-tinta-50 transition-colors"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                    </svg>
                </button>
                <!-- Indicador de última actualización -->
                <div class="flex items-center gap-1.5 text-xs text-tinta-300 ml-1">
                    <div class="w-2 h-2 rounded-full animate-pulse"
                         :class="hayNuevos ? 'bg-green-500' : 'bg-blue-400'"></div>
                    <span v-if="hayNuevos" class="text-aviso-verde font-medium">✓ Actualizado</span>
                    <template v-else>
                        <span class="hidden sm:inline">{{ ultimaActualizacion }}</span>
                        <button @click="refrescarDatos"
                                class="text-tinta-300 hover:text-aviso-azul transition-colors"
                                title="Actualizar ahora">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6"
                                      d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                        </button>
                    </template>
                </div>
            </div>
            <p class="w-full text-sm text-tinta-400 capitalize">{{ formatFechaTitulo(fecha) }}</p>
        </div>

        <!-- ─── Mobile tabs ───────────────────────────────────────────────── -->
        <div class="md:hidden flex gap-1 bg-tinta-100 p-1 rounded-2xl mb-4">
            <button
                @click="tabMobile = 'backlog'"
                class="flex-1 py-2 rounded-xl text-xs font-semibold transition-colors"
                :class="tabMobile === 'backlog' ? 'bg-superficie text-tinta-900 shadow-sm' : 'text-tinta-400'"
            >
                Backlog ({{ pasosSinProgramar.length }})
            </button>
            <button
                @click="tabMobile = 'kanban'"
                class="flex-1 py-2 rounded-xl text-xs font-semibold transition-colors"
                :class="tabMobile === 'kanban' ? 'bg-superficie text-tinta-900 shadow-sm' : 'text-tinta-400'"
            >
                Kanban ({{ pasosProgramados.length }})
            </button>
        </div>

        <!-- ─── Layout principal ──────────────────────────────────────────── -->
        <div class="flex gap-4 items-start min-h-0">

            <!-- ══ BACKLOG ══════════════════════════════════════════════════ -->
            <div
                class="shrink-0 space-y-2"
                :class="tabMobile === 'backlog' ? 'block w-full md:w-[300px]' : 'hidden md:block md:w-[300px]'"
            >
                <div class="flex items-center justify-between mb-2">
                    <h2 class="text-sm font-semibold text-tinta-700">Backlog</h2>
                    <span class="text-xs px-2 py-0.5 rounded-full bg-tinta-100 text-tinta-400">
                        {{ pasosSinProgramar.length }} paso(s)
                    </span>
                </div>

                <div v-if="!pasosSinProgramar.length"
                    class="bg-superficie rounded-2xl border border-linea py-10 text-center text-sm text-tinta-300">
                    Sin pasos pendientes de programar.
                </div>

                <!-- Card de backlog -->
                <div
                    v-for="paso in pasosSinProgramar"
                    :key="paso.id"
                    class="bg-superficie rounded-2xl border border-linea p-4 shadow-sm"
                >
                    <!-- Info del paso -->
                    <p class="text-sm font-semibold text-tinta-900 leading-snug">{{ paso.nombre }}</p>
                    <div class="flex items-center gap-1.5 mt-1 flex-wrap">
                        <span class="text-xs font-mono font-semibold text-aviso-azul" style="color:var(--marca);">
                            {{ opNumero(paso) }}
                        </span>
                        <span class="text-xs text-tinta-300">·</span>
                        <span class="text-xs text-tinta-500 truncate">{{ clienteNombre(paso) }}</span>
                    </div>
                    <p class="text-xs text-tinta-400 mt-0.5 truncate">{{ ensambleNombre(paso) }}</p>

                    <!-- Variables de instancia -->
                    <div
                        v-if="Object.keys(variablesInstancia(paso)).length"
                        class="flex flex-wrap gap-1 mt-2"
                    >
                        <span
                            v-for="(val, key) in variablesInstancia(paso)"
                            :key="key"
                            class="text-xs px-2 py-0.5 rounded-full bg-pastel-ambar-2 text-aviso-ambar"
                        >
                            {{ key }}: {{ val }}
                        </span>
                    </div>

                    <!-- Botón programar -->
                    <div v-if="programandoId !== paso.id" class="mt-3">
                        <button
                            @click="abrirProgramar(paso)"
                            class="w-full py-2 rounded-xl text-xs font-semibold text-white transition-colors"
                            style="background:var(--marca);"
                        >
                            Programar para {{ fecha }}
                        </button>
                    </div>

                    <!-- Formulario inline de programación -->
                    <div v-else class="mt-3 space-y-2 border-t border-linea pt-3">
                        <div>
                            <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1">
                                Estación *
                            </label>
                            <select
                                v-model="programarForm.estacion_trabajo_id"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]"
                            >
                                <option :value="null">Seleccionar estación...</option>
                                <option
                                    v-for="est in estaciones"
                                    :key="est.id"
                                    :value="est.id"
                                >
                                    {{ est.nombre }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1">
                                Colaborador
                            </label>
                            <select
                                v-model="programarForm.colaborador_programado_id"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]"
                            >
                                <option :value="null">Sin asignar</option>
                                <option v-for="col in colaboradores" :key="col.id" :value="col.id">
                                    {{ col.name }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1">
                                Tiempo estimado (min)
                            </label>
                            <input
                                v-model.number="programarForm.tiempo_estimado_minutos"
                                type="number"
                                min="1"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]"
                            />
                        </div>
                        <div class="flex gap-2 pt-1">
                            <button
                                @click="cancelarProgramar"
                                class="flex-1 py-2 rounded-xl border border-linea text-xs font-medium text-tinta-500"
                            >
                                Cancelar
                            </button>
                            <button
                                @click="confirmarProgramar(paso.id)"
                                :disabled="!programarForm.estacion_trabajo_id"
                                class="flex-1 py-2 rounded-xl text-xs font-semibold text-white disabled:opacity-40 transition-colors"
                                style="background:var(--marca);"
                            >
                                Confirmar
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ══ KANBAN ════════════════════════════════════════════════════ -->
            <div
                class="flex-1 min-w-0 overflow-x-auto pb-4"
                :class="tabMobile === 'kanban' ? 'block w-full' : 'hidden md:block'"
            >
                <!-- Sin estaciones -->
                <div v-if="!estaciones.length"
                    class="bg-superficie rounded-2xl border border-linea py-12 text-center text-sm text-tinta-300">
                    No hay estaciones de trabajo activas. Configúralas en
                    <a href="/configuracion" @click.prevent="router.visit('/configuracion')"
                        class="text-aviso-azul hover:underline">Configuración</a>.
                </div>

                <div v-else class="flex gap-3" style="min-width: max-content;">
                    <!-- ─── Columna por estación ──────────────────────────── -->
                    <div
                        v-for="est in estaciones"
                        :key="est.id"
                        class="w-[280px] shrink-0 flex flex-col"
                    >
                        <!-- Header columna -->
                        <div
                            class="flex items-center gap-2 px-3 py-2.5 rounded-t-2xl text-white mb-0"
                            :style="{ background: est.color || 'var(--marca)' }"
                        >
                            <span class="flex-1 text-sm font-semibold truncate">{{ est.nombre }}</span>
                            <span class="text-xs px-1.5 py-0.5 rounded-full bg-white/20 font-semibold shrink-0">
                                {{ (kanbanPorEstacion[est.id] ?? []).length }}
                            </span>
                        </div>

                        <!-- Alerta equipos en mantenimiento -->
                        <div
                            v-if="tieneEquipoEnMantenimiento(est)"
                            class="flex items-center gap-1.5 px-3 py-1.5 bg-pastel-rojo border-x border-borde-aviso-rojo text-aviso-rojo text-xs font-medium"
                        >
                            <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                            </svg>
                            Equipo en mantenimiento
                        </div>

                        <!-- Área de cards -->
                        <div
                            class="flex-1 bg-tinta-50 border border-linea rounded-b-2xl p-2 space-y-2 min-h-[200px]"
                            :class="tieneEquipoEnMantenimiento(est) ? 'rounded-t-none' : ''"
                        >
                            <!-- Empty state -->
                            <div
                                v-if="!(kanbanPorEstacion[est.id]?.length)"
                                class="flex items-center justify-center h-24 text-xs text-tinta-300 border-2 border-dashed border-linea rounded-xl"
                            >
                                Sin pasos programados
                            </div>

                            <!-- Cards programadas -->
                            <div
                                v-for="paso in kanbanPorEstacion[est.id] ?? []"
                                :key="paso.id"
                                class="bg-superficie rounded-xl border shadow-sm p-3"
                                :class="paso.completado ? 'border-borde-aviso-verde bg-pastel-verde' : 'border-linea'"
                            >
                                <!-- Estado badge + botón desprogramar -->
                                <div class="flex items-start justify-between gap-2 mb-1.5">
                                    <span
                                        class="text-xs px-1.5 py-0.5 rounded-full font-medium shrink-0"
                                        :class="paso.completado
                                            ? 'bg-pastel-verde-2 text-aviso-verde'
                                            : 'bg-tinta-100 text-tinta-400'"
                                    >
                                        {{ paso.completado ? '✓ Completado' : 'Pendiente' }}
                                    </span>
                                    <button
                                        v-if="!paso.completado"
                                        @click="desprogramar(paso.id)"
                                        class="w-5 h-5 flex items-center justify-center rounded-full text-tinta-300 hover:bg-pastel-rojo-2 hover:text-aviso-rojo transition-colors shrink-0"
                                        title="Desprogramar"
                                    >
                                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                </div>

                                <!-- Nombre del paso -->
                                <p class="text-sm font-semibold text-tinta-900 leading-snug">{{ paso.nombre }}</p>

                                <!-- OP + cliente -->
                                <div class="flex items-center gap-1 mt-1 flex-wrap">
                                    <span class="text-xs font-mono font-semibold" style="color:var(--marca);">
                                        {{ opNumero(paso) }}
                                    </span>
                                    <span class="text-xs text-tinta-300">·</span>
                                    <span class="text-xs text-tinta-500 truncate">{{ clienteNombre(paso) }}</span>
                                </div>

                                <!-- Ensamble -->
                                <p class="text-xs text-tinta-300 mt-0.5 truncate">{{ ensambleNombre(paso) }}</p>

                                <!-- Colaborador programado -->
                                <div v-if="paso.colaborador || paso.colaborador_programado" class="flex items-center gap-1.5 mt-2">
                                    <div
                                        class="w-5 h-5 rounded-full flex items-center justify-center text-xs font-semibold text-white shrink-0"
                                        style="background:var(--marca);"
                                    >
                                        {{ (paso.colaborador ?? paso.colaborador_programado?.name)?.[0]?.toUpperCase() }}
                                    </div>
                                    <span class="text-xs text-tinta-500 truncate">
                                        {{ paso.colaborador ?? paso.colaborador_programado?.name }}
                                    </span>
                                </div>

                                <!-- Tiempo estimado -->
                                <div v-if="paso.tiempo_estimado ?? paso.tiempo_estimado_minutos" class="flex items-center gap-1 mt-1.5">
                                    <svg class="w-3 h-3 text-tinta-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    <span class="text-xs text-tinta-400">{{ paso.tiempo_estimado ?? paso.tiempo_estimado_minutos }} min</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </AppLayout>
</template>
