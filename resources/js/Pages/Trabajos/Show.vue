<script setup>
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import PasoFotos from '@/Components/PasoFotos.vue'
import RevisionCalidad from '@/Components/RevisionCalidad.vue'

const props = defineProps({
    trabajo:   { type: Object, required: true },
    operarios: { type: Array,  default: () => [] },
    bodegas:   { type: Array,  default: () => [] },
    bodegas_sugeridas: { type: Object, default: () => ({}) },
})

// Las dos bodegas del paso final: a dónde entra la unidad y de dónde salió su material.
//
// Llegan ya elegidas —las de la orden, o las de la unidad anterior— y se pueden corregir: quien
// cierra el paso es quien físicamente dejó la unidad en un estante y sacó el material de una
// caja, y es el único que sabe si terminó donde se había planeado.
//
// Son dos porque una bodega de producto terminado no guarda insumos: descontar el material
// contra ella lo recorta a cero en silencio y el inventario queda mintiendo por los dos lados.
const bodegaEntrega  = ref(props.bodegas_sugeridas?.entrega  ?? '')
const bodegaMaterial = ref(props.bodegas_sugeridas?.material ?? '')

const csrf = () => {
    const c = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))
    return c ? decodeURIComponent(c.split('=')[1]) : ''
}

// ── Estado local reactivo ─────────────────────────────────────────────────────
const porcentaje  = ref(props.trabajo.porcentaje_avance)
const pasos       = ref(props.trabajo.pasos.map(p => ({ ...p })))
const expandidos  = ref(new Set())
const guardando   = ref(new Set())

// ── Info general ──────────────────────────────────────────────────────────────
const pasosCompletados = computed(() => pasos.value.filter(p => p.completado).length)
const pasosTotal       = computed(() => pasos.value.length)
const tiempoTotal      = computed(() =>
    pasos.value.filter(p => p.completado).reduce((s, p) => s + (p.tiempo_minutos ?? 0), 0)
)

const formatTiempo = (min) => {
    if (!min) return '0 min'
    min = Math.round(min)
    if (min < 60) return `${min} min`
    const h = Math.floor(min / 60)
    const m = min % 60
    return m > 0 ? `${h}h ${m}min` : `${h}h`
}

const badgeOp = (estado) => {
    const map = {
        borrador:      'bg-tinta-100 text-tinta-500',
        activa:        'bg-pastel-azul-2 text-aviso-azul',
        en_produccion: 'bg-pastel-ambar-2 text-aviso-ambar',
        completada:    'bg-pastel-verde-2 text-aviso-verde',
        cancelada:     'bg-pastel-rojo-2 text-aviso-rojo',
    }
    return map[estado] ?? 'bg-tinta-100 text-tinta-500'
}

// ── Accordion paso ────────────────────────────────────────────────────────────
const togglePaso = (id) => {
    if (expandidos.value.has(id)) expandidos.value.delete(id)
    else {
        expandidos.value.add(id)
        const paso = pasos.value.find(p => p.id === id)
        if (paso) initOperariosPaso(paso)
    }
    expandidos.value = new Set(expandidos.value)
}

// ── Múltiples operarios por paso ──────────────────────────────────────────────
const operariosPorPaso = ref({})

function initOperariosPaso(paso) {
    if (operariosPorPaso.value[paso.id]) return
    operariosPorPaso.value[paso.id] = paso.operarios_pivot?.length
        ? paso.operarios_pivot.map(o => ({
            operario_id:    o.operario_id,
            tiempo_minutos: o.tiempo_minutos ?? '',
            observaciones:  o.observaciones ?? '',
          }))
        : [{ operario_id: '', tiempo_minutos: '', observaciones: '' }]
}

function agregarOperario(pasoId) {
    if (!operariosPorPaso.value[pasoId]) operariosPorPaso.value[pasoId] = []
    operariosPorPaso.value[pasoId].push({ operario_id: '', tiempo_minutos: '', observaciones: '' })
}

function quitarOperario(pasoId, idx) {
    const lista = operariosPorPaso.value[pasoId]
    if (lista && lista.length > 1) lista.splice(idx, 1)
}

// ── Actualizar paso via fetch PUT ─────────────────────────────────────────────
const actualizarPasoConOperarios = async (paso, payload) => {
    if (guardando.value.has(paso.id)) return
    guardando.value = new Set([...guardando.value, paso.id])
    try {
        const res = await fetch(`/trabajos/pasos/${paso.id}`, {
            method:  'PUT',
            headers: {
                'Content-Type':     'application/json',
                Accept:             'application/json',
                'X-XSRF-TOKEN':     csrf(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify(payload),
        })
        const data = await res.json()

        if (! data.success && data.message) {
            avisoEntrega.value = data.message

            return
        }

        if (data.success) {
            // Cerrar el último paso descuenta los materiales y mete la unidad a bodega. Que
            // eso pasó no se puede quedar en silencio: es un movimiento de inventario.
            avisoEntrega.value = data.entregada_en
                ? `La unidad entró a ${data.entregada_en} y sus materiales se descontaron.`
                : ''
            porcentaje.value = data.porcentaje_avance
            const idx = pasos.value.findIndex(p => p.id === paso.id)
            if (idx >= 0) {
                pasos.value[idx] = { ...pasos.value[idx], ...data.paso }
                operariosPorPaso.value[paso.id] = data.paso.operarios_pivot?.length
                    ? data.paso.operarios_pivot.map(o => ({
                        operario_id:    o.operario_id,
                        tiempo_minutos: o.tiempo_minutos ?? '',
                        observaciones:  o.observaciones ?? '',
                      }))
                    : [{ operario_id: '', tiempo_minutos: '', observaciones: '' }]
            }
        }
    } catch (e) {
        console.error('Error actualizando paso:', e)
    } finally {
        const s = new Set(guardando.value)
        s.delete(paso.id)
        guardando.value = s
    }
}

const avisoEntrega = ref('')

const marcarCompletado = (paso) => {
    const entries = (operariosPorPaso.value[paso.id] ?? [])
        .filter(o => o.operario_id)
        .map(o => ({
            ...o,
            tiempo_minutos: o.tiempo_minutos === '' ? null : parseInt(o.tiempo_minutos) || null,
        }))
    actualizarPasoConOperarios(paso, {
        completado: true,
        operarios: entries,
        // Solo significa algo en el paso final; el servidor lo ignora en los demás.
        ...(paso.es_paso_final ? {
            bodega_entrega_id:  bodegaEntrega.value  || null,
            bodega_material_id: bodegaMaterial.value || null,
        } : {}),
    })
}

const desmarcarCompletado = (paso) => {
    actualizarPasoConOperarios(paso, { completado: false, operarios: [] })
    operariosPorPaso.value[paso.id] = [{ operario_id: '', tiempo_minutos: '', observaciones: '' }]
}

// Guarda el tiempo/operarios de un paso ya completado sin desmarcarlo — para
// corregir un tiempo a mano sin tener que pasar por Desmarcar + Completar.
const guardarOperarios = (paso) => {
    const entries = (operariosPorPaso.value[paso.id] ?? [])
        .filter(o => o.operario_id)
        .map(o => ({
            ...o,
            tiempo_minutos: o.tiempo_minutos === '' ? null : parseInt(o.tiempo_minutos) || null,
        }))
    actualizarPasoConOperarios(paso, { operarios: entries })
}

// ── Inicio de paso — la fecha/hora la pone el servidor con now(), nadie la escribe ──
const iniciarPaso = (paso) => actualizarPasoConOperarios(paso, { iniciado: true })
const quitarInicio = (paso) => actualizarPasoConOperarios(paso, { iniciado: false })

const formatDuracion = (min) => {
    if (min === null || min === undefined) return null
    if (min < 1) return '< 1 min'
    return formatTiempo(min)
}

// ── Corrección manual de horas — por si el registro automático no coincide
// con lo que pasó de verdad en planta ─────────────────────────────────────────
const editandoHoras = ref(new Set())
const horasBorrador = ref({})

const toLocalInputValue = (iso) => {
    if (!iso) return ''
    const d = new Date(iso)
    const pad = (n) => String(n).padStart(2, '0')
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

const abrirEditarHoras = (paso) => {
    horasBorrador.value = {
        ...horasBorrador.value,
        [paso.id]: {
            iniciado_at:   toLocalInputValue(paso.iniciado_at_iso),
            completado_at: toLocalInputValue(paso.completado_at_iso),
        },
    }
    editandoHoras.value = new Set([...editandoHoras.value, paso.id])
}

const cancelarEditarHoras = (pasoId) => {
    const s = new Set(editandoHoras.value)
    s.delete(pasoId)
    editandoHoras.value = s
}

const guardarHoras = async (paso) => {
    const b = horasBorrador.value[paso.id]
    await actualizarPasoConOperarios(paso, {
        iniciado_at_manual:   b.iniciado_at || null,
        completado_at_manual: b.completado_at || null,
    })
    cancelarEditarHoras(paso.id)
}

// ── Color círculo estado paso ─────────────────────────────────────────────────
const circuloPaso = (paso) => {
    if (paso.completado) return 'bg-green-500 border-green-500'
    if (paso.iniciado_at || paso.operario_id) return 'bg-pastel-ambar border-yellow-400'
    return 'bg-superficie border-tinta-200'
}
</script>

<template>
    <AppLayout :title="`Trabajo — ${trabajo.op_numero}`">

        <!-- ── Breadcrumb ────────────────────────────────────────────────────── -->
        <div class="flex items-center gap-2 mb-4 text-sm text-tinta-400">
            <button class="hover:text-[var(--marca)] transition-colors" @click="router.visit('/trabajos')">Trabajos</button>
            <span>/</span>
            <span class="text-tinta-900 font-medium">{{ trabajo.op_numero }}</span>
        </div>

        <!-- ── Layout principal ──────────────────────────────────────────────── -->
        <div class="flex flex-col md:flex-row gap-5">

            <!-- ═══════════════════════════════════════════════════════════════
                 COLUMNA IZQUIERDA — info general + progreso
            ══════════════════════════════════════════════════════════════════ -->
            <div class="w-full md:w-[380px] md:shrink-0 space-y-4">

                <!-- Card info general -->
                <div class="bg-superficie rounded-2xl shadow-sm border border-linea p-5">
                    <h3 class="text-sm font-semibold text-tinta-900 mb-4">Información general</h3>
                    <div class="space-y-3">
                        <div class="flex items-start justify-between gap-2">
                            <span class="text-xs text-tinta-400 shrink-0">Orden de producción</span>
                            <div class="text-right">
                                <a
                                    :href="`/produccion/ops/${trabajo.op_id}`"
                                    class="text-sm font-semibold text-[var(--marca)] hover:underline"
                                    @click.prevent="router.visit(`/produccion/ops/${trabajo.op_id}`)"
                                >{{ trabajo.op_numero }}</a>
                                <span
                                    class="ml-2 inline-flex items-center px-2 py-0.5 rounded-lg text-xs font-semibold"
                                    :class="badgeOp(trabajo.op_estado)"
                                >{{ trabajo.op_estado }}</span>
                            </div>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-xs text-tinta-400">Cliente</span>
                            <span class="text-sm font-medium text-tinta-900 text-right">{{ trabajo.cliente_nombre ?? '—' }}</span>
                        </div>
                        <div class="flex items-start justify-between gap-2">
                            <span class="text-xs text-tinta-400 shrink-0">Ítem</span>
                            <span class="text-sm text-tinta-700 text-right max-w-[200px]">{{ trabajo.item_descripcion ?? '—' }}</span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-xs text-tinta-400">Template</span>
                            <span class="text-sm text-tinta-700">{{ trabajo.template_nombre ?? '—' }}</span>
                        </div>
                        <div v-if="trabajo.op_fecha" class="flex items-center justify-between">
                            <span class="text-xs text-tinta-400">Fecha creación</span>
                            <span class="text-sm text-tinta-700">{{ trabajo.op_fecha }}</span>
                        </div>
                    </div>
                </div>

                <!-- Card progreso general -->
                <div class="bg-superficie rounded-2xl shadow-sm border border-linea p-5">
                    <h3 class="text-sm font-semibold text-tinta-900 mb-4">Progreso general</h3>

                    <!-- Barra grande -->
                    <div class="mb-1 flex justify-between items-end">
                        <span class="text-xs text-tinta-400">Avance</span>
                        <span class="text-2xl font-semibold" style="color:var(--marca);">{{ Math.round(porcentaje) }}%</span>
                    </div>
                    <div class="bg-tinta-200 rounded-full h-3 mb-4 overflow-hidden">
                        <div
                            class="h-3 rounded-full transition-all duration-500"
                            style="background:var(--marca);"
                            :style="{ width: Math.min(porcentaje, 100) + '%' }"
                        />
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="bg-tinta-50 rounded-xl p-3 text-center">
                            <p class="text-xl font-semibold text-tinta-900">{{ pasosCompletados }}/{{ pasosTotal }}</p>
                            <p class="text-xs text-tinta-400 mt-0.5">Pasos completados</p>
                        </div>
                        <div class="bg-tinta-50 rounded-xl p-3 text-center">
                            <p class="text-xl font-semibold text-tinta-900">{{ formatTiempo(tiempoTotal) }}</p>
                            <p class="text-xs text-tinta-400 mt-0.5">Tiempo acumulado</p>
                        </div>
                    </div>
                </div>

            </div>

            <!-- ═══════════════════════════════════════════════════════════════
                 COLUMNA DERECHA — lista de pasos
            ══════════════════════════════════════════════════════════════════ -->
            <div class="flex-1 min-w-0">
                <!-- La revisión de calidad de esta unidad. Sale de la lista que el ensamble
                     definió, y sin ella todo sigue como antes: calidad aprueba la orden entera. -->
                <RevisionCalidad v-if="trabajo.checks?.length" :checks="trabajo.checks" class="mb-4" />

                <h3 class="text-sm font-semibold text-tinta-900 mb-3">Pasos del trabajo</h3>

                <div class="space-y-3">
                    <div
                        v-for="paso in pasos"
                        :key="paso.id"
                        class="bg-superficie rounded-2xl shadow-sm border transition-all"
                        :class="paso.completado ? 'border-borde-aviso-verde' : 'border-linea'"
                    >
                        <!-- ── Header del paso (clickeable) ───────────────── -->
                        <div
                            class="flex items-center gap-3 px-4 py-3 cursor-pointer select-none"
                            @click="togglePaso(paso.id)"
                        >
                            <!-- Número/círculo estado -->
                            <div
                                class="w-8 h-8 rounded-full border-2 flex items-center justify-center shrink-0 transition-all"
                                :class="circuloPaso(paso)"
                            >
                                <!-- Check si completado -->
                                <svg v-if="paso.completado" class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                </svg>
                                <span v-else class="text-xs font-semibold text-tinta-400">{{ String(paso.orden).padStart(2, '0') }}</span>
                            </div>

                            <!-- Nombre + badges -->
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="text-sm font-semibold" :class="paso.completado ? 'text-aviso-verde' : 'text-tinta-900'">
                                        {{ paso.nombre }}
                                    </span>
                                    <span class="text-xs px-1.5 py-0.5 rounded bg-tinta-100 text-tinta-400 font-medium" title="Peso de este paso dentro del trabajo">
                                        peso {{ paso.peso_porcentaje }}%
                                    </span>
                                    <span v-if="paso.es_extra" class="text-xs px-1.5 py-0.5 rounded bg-pastel-naranja-2 text-aviso-naranja font-semibold">
                                        Extra
                                    </span>
                                </div>
                                <p v-if="paso.descripcion_resuelta" class="text-xs text-tinta-300 mt-0.5 truncate" v-html="paso.descripcion_resuelta"></p>
                                <p v-if="paso.operario_nombre" class="text-xs text-tinta-300 mt-0.5 truncate">
                                    {{ paso.operario_nombre }}
                                </p>
                            </div>

                            <!-- Chevron -->
                            <svg
                                class="w-4 h-4 text-tinta-300 shrink-0 transition-transform"
                                :class="expandidos.has(paso.id) ? 'rotate-180' : ''"
                                fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                            </svg>
                        </div>

                        <!-- ── Body expandible ────────────────────────────── -->
                        <div v-if="expandidos.has(paso.id)" class="px-4 pb-4 border-t border-separador">

                            <!-- Descripción resuelta -->
                            <div v-if="paso.descripcion_resuelta" class="mt-3 text-sm text-tinta-500 bg-tinta-50 rounded-xl p-3 leading-relaxed rich-desc" v-html="paso.descripcion_resuelta"></div>

                            <!-- Línea de tiempo: inicio / fin (fechas automáticas del servidor) -->
                            <div v-if="(paso.iniciado_at || paso.completado_at) && !editandoHoras.has(paso.id)"
                                class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-tinta-400">
                                <span v-if="paso.iniciado_at">Iniciado: <span class="font-medium text-tinta-700">{{ paso.iniciado_at }}</span></span>
                                <span v-if="paso.completado_at">Finalizado: <span class="font-medium text-tinta-700">{{ paso.completado_at }}</span></span>
                                <span v-if="paso.duracion_real_minutos !== null && paso.duracion_real_minutos !== undefined">
                                    Duración: <span class="font-medium text-tinta-700">{{ formatDuracion(paso.duracion_real_minutos) }}</span>
                                </span>
                                <button @click.stop="abrirEditarHoras(paso)" class="text-aviso-azul hover:underline">Editar horas</button>
                            </div>

                            <!-- Edición manual de horas -->
                            <div v-if="editandoHoras.has(paso.id)" class="mt-3 bg-tinta-50 rounded-xl p-3 space-y-2" @click.stop>
                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label class="text-xs text-tinta-300 mb-1 block">Iniciado</label>
                                        <input v-model="horasBorrador[paso.id].iniciado_at" type="datetime-local"
                                            class="w-full rounded-lg border border-linea px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-blue-300" />
                                    </div>
                                    <div>
                                        <label class="text-xs text-tinta-300 mb-1 block">Finalizado</label>
                                        <input v-model="horasBorrador[paso.id].completado_at" type="datetime-local"
                                            class="w-full rounded-lg border border-linea px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-blue-300" />
                                    </div>
                                </div>
                                <div class="flex gap-2">
                                    <button @click="guardarHoras(paso)" :disabled="guardando.has(paso.id)"
                                        class="px-3 py-1.5 rounded-lg text-xs font-semibold text-white disabled:opacity-60" style="background:var(--marca);">
                                        {{ guardando.has(paso.id) ? 'Guardando...' : 'Guardar horas' }}
                                    </button>
                                    <button @click="cancelarEditarHoras(paso.id)" class="px-3 py-1.5 rounded-lg text-xs text-tinta-400 hover:bg-tinta-100">Cancelar</button>
                                </div>
                            </div>

                            <div class="mt-3 space-y-2">
                                <div class="flex items-center justify-between mb-1">
                                    <label class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Operarios</label>
                                    <button @click.stop="agregarOperario(paso.id)"
                                        class="text-xs text-aviso-azul hover:text-aviso-azul font-medium flex items-center gap-1">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                                        </svg>
                                        Agregar
                                    </button>
                                </div>
                                <div v-for="(entry, idx) in (operariosPorPaso[paso.id] ?? [])" :key="idx"
                                    class="bg-tinta-50 rounded-xl p-3 space-y-2">
                                    <div class="flex items-center gap-2">
                                        <select v-model="entry.operario_id"
                                            class="flex-1 rounded-lg border border-linea px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300 bg-superficie">
                                            <option value="">Sin asignar</option>
                                            <option v-for="op in operarios" :key="op.id" :value="op.id">{{ op.nombre }}</option>
                                        </select>
                                        <button v-if="(operariosPorPaso[paso.id] ?? []).length > 1"
                                            @click.stop="quitarOperario(paso.id, idx)"
                                            class="text-red-400 hover:text-aviso-rojo shrink-0">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                            </svg>
                                        </button>
                                    </div>
                                    <div class="grid grid-cols-2 gap-2">
                                        <div>
                                            <label class="text-xs text-tinta-300 mb-1 block">Tiempo (min)</label>
                                            <input v-model.number="entry.tiempo_minutos" type="number" min="0"
                                                placeholder="0" @click.stop
                                                class="w-full rounded-lg border border-linea px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300" />
                                        </div>
                                        <div>
                                            <label class="text-xs text-tinta-300 mb-1 block">Observaciones</label>
                                            <input v-model="entry.observaciones" type="text"
                                                placeholder="Opcional" @click.stop
                                                class="w-full rounded-lg border border-linea px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300" />
                                        </div>
                                    </div>
                                </div>
                                <button v-if="paso.completado" @click.stop="guardarOperarios(paso)"
                                    :disabled="guardando.has(paso.id)"
                                    class="text-xs text-aviso-azul hover:text-aviso-azul font-medium disabled:opacity-60">
                                    {{ guardando.has(paso.id) ? 'Guardando...' : 'Guardar tiempo / operarios' }}
                                </button>
                            </div>

                            <!-- Fotos del paso -->
                            <PasoFotos
                                :paso-id="paso.id"
                                :fotos="paso.fotos ?? []"
                                :editable="true"
                                @update:fotos="(val) => { const idx = pasos.findIndex(p => p.id === paso.id); if (idx >= 0) pasos[idx] = { ...pasos[idx], fotos: val } }"
                            />

                            <!-- Info si completado -->
                            <div v-if="paso.completado" class="mt-3 bg-pastel-verde rounded-xl px-3 py-2 flex items-center gap-2 text-xs text-aviso-verde flex-wrap">
                                <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <span>Completado el {{ paso.completado_at }}</span>
                                <span v-if="paso.duracion_real_minutos !== null && paso.duracion_real_minutos !== undefined">
                                    · duró {{ formatDuracion(paso.duracion_real_minutos) }}
                                </span>
                                <span v-if="paso.operarios_pivot?.length">
                                    · {{ paso.operarios_pivot.map(o => o.nombre).join(', ') }}
                                </span>
                                <span v-else-if="paso.operario_nombre"> · por {{ paso.operario_nombre }}</span>
                            </div>

                            <!-- Acciones -->
                            <div class="mt-3 flex gap-2">
                                <button
                                    v-if="!paso.completado && !paso.iniciado_at"
                                    class="flex-1 py-2.5 rounded-xl text-sm font-semibold text-white transition-colors disabled:opacity-60"
                                    style="background:var(--marca);"
                                    :disabled="guardando.has(paso.id)"
                                    @click.stop="iniciarPaso(paso)"
                                >
                                    <span v-if="guardando.has(paso.id)">Guardando...</span>
                                    <span v-else>
                                        <svg class="w-4 h-4 inline mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        Iniciar paso
                                    </span>
                                </button>
                                <button
                                    v-if="!paso.completado && paso.iniciado_at"
                                    class="px-3 py-2 rounded-xl text-xs font-medium text-tinta-400 border border-linea hover:bg-tinta-50 transition-colors disabled:opacity-60"
                                    :disabled="guardando.has(paso.id)"
                                    @click.stop="quitarInicio(paso)"
                                >
                                    {{ guardando.has(paso.id) ? '...' : 'Quitar inicio' }}
                                </button>
                                <!-- El paso final entrega la unidad, y para eso hacen falta las
                                     dos bodegas. Vienen ya elegidas de la orden: casi siempre
                                     es confirmar y seguir. -->
                                <div v-if="paso.es_paso_final && ! paso.completado" class="w-full mb-2 space-y-3">
                                    <div>
                                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1">
                                            La unidad entra en
                                        </label>
                                        <select v-model="bodegaEntrega" @click.stop
                                            class="w-full border border-linea rounded-xl px-3 py-2 text-sm bg-superficie focus:outline-none focus:border-[var(--marca)]">
                                            <option value="">Elige la bodega…</option>
                                            <option v-for="b in bodegas" :key="b.id" :value="b.id">{{ b.nombre }}</option>
                                        </select>
                                    </div>

                                    <div>
                                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1">
                                            El material salió de
                                        </label>
                                        <select v-model="bodegaMaterial" @click.stop
                                            class="w-full border border-linea rounded-xl px-3 py-2 text-sm bg-superficie focus:outline-none focus:border-[var(--marca)]">
                                            <option value="">Elige la bodega…</option>
                                            <option v-for="b in bodegas" :key="b.id" :value="b.id">{{ b.nombre }}</option>
                                        </select>
                                        <p class="text-xs text-tinta-300 mt-1">
                                            Al cerrar este paso la unidad entra a la primera y se descuenta
                                            de la segunda el material que se gastó en ella.
                                        </p>
                                    </div>
                                </div>

                                <button
                                    v-if="!paso.completado"
                                    class="flex-1 py-2.5 rounded-xl text-sm font-semibold text-white transition-colors disabled:opacity-60"
                                    style="background:#16a34a;"
                                    :disabled="guardando.has(paso.id)"
                                    @click.stop="marcarCompletado(paso)"
                                >
                                    <span v-if="guardando.has(paso.id)">Guardando...</span>
                                    <span v-else>
                                        <svg class="w-4 h-4 inline mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                        </svg>
                                        Marcar completado
                                    </span>
                                </button>
                                <button
                                    v-if="paso.completado"
                                    class="px-4 py-2 rounded-xl text-xs font-medium text-tinta-400 border border-linea hover:bg-tinta-50 transition-colors disabled:opacity-60"
                                    :disabled="guardando.has(paso.id)"
                                    @click.stop="desmarcarCompletado(paso)"
                                >
                                    {{ guardando.has(paso.id) ? '...' : 'Desmarcar' }}
                                </button>
                            </div>

                            <p v-if="avisoEntrega && paso.es_paso_final"
                                class="text-xs mt-2 px-3 py-2 rounded-lg bg-pastel-verde border border-borde-aviso-verde text-aviso-verde">
                                {{ avisoEntrega }}
                            </p>
                        </div>

                        <!-- Footer mini si completado y no expandido -->
                        <div
                            v-if="paso.completado && !expandidos.has(paso.id)"
                            class="px-4 pb-2 flex items-center gap-1 text-xs text-aviso-verde"
                        >
                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                            {{ paso.completado_at }}
                            <span v-if="paso.operarios_pivot?.length"> · {{ paso.operarios_pivot.map(o => o.nombre).join(', ') }}</span>
                            <span v-else-if="paso.operario_nombre"> · {{ paso.operario_nombre }}</span>
                        </div>

                    </div>
                </div>
            </div>

        </div>

    </AppLayout>
</template>

<style scoped>
.rich-desc :deep(ul) { list-style: disc; padding-left: 1.25rem; }
.rich-desc :deep(ol) { list-style: decimal; padding-left: 1.25rem; }
</style>
