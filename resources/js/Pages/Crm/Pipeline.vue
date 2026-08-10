<script setup>
import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ModalLead from '@/Components/Crm/ModalLead.vue'
import DetalleLead from '@/Components/Crm/DetalleLead.vue'

const props = defineProps({
    etapas:   { type: Array,  default: () => [] },
    usuarios: { type: Array,  default: () => [] },
    clientes: { type: Array,  default: () => [] },
    fuentes:  { type: Array,  default: () => [] },
    filtros:  { type: Object, default: () => ({}) },
    metricas: { type: Object, default: () => ({}) },
})

const etapas           = ref(props.etapas.map(e => ({ ...e, leads: [...e.leads] })))
const metricasRef      = ref({ ...props.metricas })
const modalLeadVisible = ref(false)
const etapaIdInicial   = ref(null)
const leadDetalleId    = ref(null)

watch(() => props.etapas, (nuevas) => {
    etapas.value = nuevas.map(e => ({ ...e, leads: [...e.leads] }))
}, { deep: true })

watch(() => props.metricas, (val) => {
    metricasRef.value = { ...val }
})

// ── Filtros ───────────────────────────────────────────────────────────────────
const filtroMes         = ref(props.filtros.mes         ?? '')
const filtroResponsable = ref(props.filtros.responsable ?? '')
const filtroFuente      = ref(props.filtros.fuente      ?? '')
const filtroEstado      = ref(props.filtros.estado      ?? 'activo')
const filtroBuscar      = ref(props.filtros.buscar      ?? '')

function aplicarFiltros() {
    const params = {}
    if (filtroMes.value)         params.mes         = filtroMes.value
    if (filtroResponsable.value) params.responsable = filtroResponsable.value
    if (filtroFuente.value)      params.fuente      = filtroFuente.value
    if (filtroEstado.value)      params.estado      = filtroEstado.value
    if (filtroBuscar.value)      params.buscar      = filtroBuscar.value

    router.get('/crm', params, {
        preserveState: true,
        preserveScroll: true,
        only: ['etapas', 'filtros', 'metricas'],
        onSuccess: (page) => {
            etapas.value      = page.props.etapas.map(e => ({ ...e, leads: [...e.leads] }))
            metricasRef.value = { ...page.props.metricas }
        },
    })
}

function limpiarFiltros() {
    filtroMes.value         = ''
    filtroResponsable.value = ''
    filtroFuente.value      = ''
    filtroEstado.value      = 'activo'
    filtroBuscar.value      = ''
    aplicarFiltros()
}

let searchTimeout = null
function debounceSearch() {
    clearTimeout(searchTimeout)
    searchTimeout = setTimeout(() => {
        aplicarFiltros()
    }, 400)
}

// ── Drag & Drop ───────────────────────────────────────────────────────────────
let leadArrastrado = null
let etapaOrigenId  = null

function onDragStart(e, lead, etapaId) {
    leadArrastrado = lead
    etapaOrigenId  = etapaId
    e.dataTransfer.effectAllowed = 'move'
}

async function onDrop(e, etapaDestinoId) {
    if (!leadArrastrado || etapaOrigenId === etapaDestinoId) return

    const etapaOrigen  = etapas.value.find(et => et.id === etapaOrigenId)
    const etapaDestino = etapas.value.find(et => et.id === etapaDestinoId)
    if (!etapaOrigen || !etapaDestino) return

    etapaOrigen.leads  = etapaOrigen.leads.filter(l => l.id !== leadArrastrado.id)
    const nuevoOrden   = etapaDestino.leads.length
    etapaDestino.leads.push({ ...leadArrastrado })

    const res = await fetch(`/crm/leads/${leadArrastrado.id}/mover`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': getCookie('XSRF-TOKEN') },
        credentials: 'same-origin',
        body: JSON.stringify({ etapa_id: etapaDestinoId, orden_en_etapa: nuevoOrden }),
    })
    const data = await res.json()

    if (data.accion === 'cotizacion_creada') {
        if (confirm(`Lead movido a "${etapaDestino.nombre}". Se generó automáticamente una cotización en borrador. ¿Abrirla ahora?`)) {
            router.visit(`/cotizaciones/${data.cotizacion_id}`)
        }
    } else if (data.accion === 'falta_cliente') {
        alert(`Lead movido a "${etapaDestino.nombre}". Este lead aún no tiene un cliente asociado — conviértelo a cliente para generar la cotización automáticamente.`)
    }

    leadArrastrado = null
    etapaOrigenId  = null
}

function abrirModalLead(etapaId) {
    etapaIdInicial.value   = etapaId
    modalLeadVisible.value = true
}

function abrirDetalleLead(leadId) {
    leadDetalleId.value = leadId
}

function onLeadGuardado(lead) {
    const leadNormalizado = {
        id:                lead.id,
        titulo:            lead.titulo,
        nombre_contacto:   lead.nombre_contacto  ?? null,
        empresa_contacto:  lead.empresa_contacto ?? null,
        telefono_contacto: lead.telefono_contacto ?? null,
        fuente:            lead.fuente            ?? null,
        estado:            'activo',
        responsable:       lead.responsable?.name ?? lead.responsable ?? null,
        cliente:           lead.cliente?.nombre   ?? lead.cliente     ?? null,
        tareas_pendientes: 0,
        tareas_vencidas:   0,
        created_at:        new Date().toLocaleDateString('es-CO'),
    }
    const etapa = etapas.value.find(e => e.id === lead.etapa_id)
    if (etapa) etapa.leads.push(leadNormalizado)
    modalLeadVisible.value = false
}

function cargarPipeline() {
    router.reload({
        only: ['etapas', 'metricas'],
        onSuccess: (page) => {
            etapas.value      = page.props.etapas.map(e => ({ ...e, leads: [...e.leads] }))
            metricasRef.value = { ...page.props.metricas }
        },
    })
}

function getCookie(name) {
    const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'))
    return match ? decodeURIComponent(match[2]) : ''
}
</script>

<template>
    <AppLayout title="CRM Pipeline">
        <div class="flex flex-col" style="height: calc(100vh - 56px);">

            <!-- Header -->
            <div class="flex items-center justify-between mb-3">
                <div>
                    <h1 class="text-xl font-semibold text-tinta-900">Pipeline CRM</h1>
                    <p class="text-sm text-tinta-400">{{ etapas.reduce((s, e) => s + e.leads.length, 0) }} oportunidades visibles</p>
                </div>
                <button
                    @click="abrirModalLead(null)"
                    class="flex items-center gap-2 px-4 py-2 rounded-xl text-white text-sm font-semibold shadow-sm transition-opacity hover:opacity-90"
                    style="background-color: var(--marca);"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                    </svg>
                    Nueva
                </button>
            </div>

            <!-- Métricas rápidas -->
            <div class="flex gap-4 -mx-4 px-4 py-2 bg-white border-b border-linea shrink-0">
                <div class="flex items-center gap-1.5 text-sm">
                    <span class="w-2 h-2 rounded-full bg-blue-500"></span>
                    <span class="text-tinta-400">Activos:</span>
                    <span class="font-semibold text-tinta-900">{{ metricasRef.activos ?? 0 }}</span>
                </div>
                <div class="flex items-center gap-1.5 text-sm">
                    <span class="w-2 h-2 rounded-full bg-green-500"></span>
                    <span class="text-tinta-400">Ganados:</span>
                    <span class="font-semibold text-green-700">{{ metricasRef.ganados ?? 0 }}</span>
                </div>
                <div class="flex items-center gap-1.5 text-sm">
                    <span class="w-2 h-2 rounded-full bg-red-400"></span>
                    <span class="text-tinta-400">Perdidos:</span>
                    <span class="font-semibold text-red-700">{{ metricasRef.perdidos ?? 0 }}</span>
                </div>
            </div>

            <!-- Barra de filtros -->
            <div class="flex flex-wrap gap-2 -mx-4 px-4 py-2.5 bg-white border-b border-linea mb-3 shrink-0">
                <!-- Búsqueda -->
                <div class="relative">
                    <input
                        v-model="filtroBuscar"
                        type="text"
                        placeholder="Buscar lead..."
                        @input="debounceSearch"
                        class="border border-tinta-200 rounded-lg pl-8 pr-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-200 w-48"
                    />
                    <svg xmlns="http://www.w3.org/2000/svg"
                        class="w-4 h-4 text-tinta-300 absolute left-2 top-1/2 -translate-y-1/2"
                        fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                </div>

                <!-- Mes -->
                <input
                    v-model="filtroMes"
                    type="month"
                    @change="aplicarFiltros"
                    class="border border-tinta-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-200"
                />

                <!-- Estado -->
                <select
                    v-model="filtroEstado"
                    @change="aplicarFiltros"
                    class="border border-tinta-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-200 bg-white"
                >
                    <option value="activo">Activos</option>
                    <option value="ganado">Ganados</option>
                    <option value="perdido">Perdidos</option>
                    <option value="todos">Todos</option>
                </select>

                <!-- Responsable -->
                <select
                    v-model="filtroResponsable"
                    @change="aplicarFiltros"
                    class="border border-tinta-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-200 bg-white"
                >
                    <option value="">Todos los responsables</option>
                    <option v-for="u in props.usuarios" :key="u.id" :value="String(u.id)">{{ u.name }}</option>
                </select>

                <!-- Fuente -->
                <select
                    v-model="filtroFuente"
                    @change="aplicarFiltros"
                    class="border border-tinta-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-200 bg-white"
                >
                    <option value="">Todas las fuentes</option>
                    <option v-for="f in props.fuentes" :key="f" :value="f">{{ f }}</option>
                </select>

                <!-- Limpiar filtros -->
                <button
                    v-if="filtroMes || filtroResponsable || filtroFuente || filtroEstado !== 'activo' || filtroBuscar"
                    @click="limpiarFiltros"
                    class="flex items-center gap-1 text-sm text-red-500 border border-red-200 rounded-lg px-3 py-1.5 hover:bg-red-50 transition-colors"
                >
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                    Limpiar
                </button>
            </div>

            <!-- Kanban Board -->
            <div class="flex-1 overflow-x-auto -mx-4 px-4">
                <div class="flex gap-3 h-full pb-4" style="min-width: max-content;">

                    <div
                        v-for="etapa in etapas"
                        :key="etapa.id"
                        class="flex flex-col rounded-xl"
                        style="width: 280px; background: #F3F4F6;"
                        @dragover.prevent
                        @drop="onDrop($event, etapa.id)"
                    >
                        <!-- Header columna -->
                        <div
                            class="flex items-center justify-between px-3 py-2.5 rounded-t-xl"
                            :style="{ borderTop: `3px solid ${etapa.color}` }"
                        >
                            <div class="flex items-center gap-2 min-w-0">
                                <span class="w-2.5 h-2.5 rounded-full shrink-0" :style="{ background: etapa.color }"></span>
                                <span class="text-sm font-semibold text-tinta-700 truncate">{{ etapa.nombre }}</span>
                                <span class="text-xs bg-tinta-200 text-tinta-500 rounded-full px-1.5 py-0.5 shrink-0 font-medium">
                                    {{ etapa.leads.length }}
                                </span>
                            </div>
                            <button
                                @click="abrirModalLead(etapa.id)"
                                class="text-tinta-300 hover:text-tinta-700 transition-colors shrink-0 ml-1"
                                title="Agregar lead"
                            >
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                                </svg>
                            </button>
                        </div>

                        <!-- Lista de tarjetas -->
                        <div class="flex-1 overflow-y-auto px-2 py-2 space-y-2" style="min-height: 120px;">
                            <div
                                v-for="lead in etapa.leads"
                                :key="lead.id"
                                draggable="true"
                                @dragstart="onDragStart($event, lead, etapa.id)"
                                @click="abrirDetalleLead(lead.id)"
                                class="bg-white rounded-xl border border-linea p-3 cursor-pointer hover:shadow-md transition-shadow select-none"
                            >
                                <!-- Badge estado ganado/perdido -->
                                <div
                                    v-if="lead.estado && lead.estado !== 'activo'"
                                    class="inline-flex items-center text-xs font-semibold px-1.5 py-0.5 rounded-md mb-1"
                                    :class="lead.estado === 'ganado' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-600'"
                                >
                                    {{ lead.estado === 'ganado' ? '✓ Ganado' : '✗ Perdido' }}
                                </div>

                                <!-- Título -->
                                <p class="text-sm font-semibold text-tinta-900 mb-1 line-clamp-2 leading-snug">
                                    {{ lead.titulo }}
                                </p>

                                <!-- Empresa/Contacto -->
                                <div v-if="lead.empresa_contacto || lead.nombre_contacto"
                                    class="flex items-center gap-1 text-xs text-tinta-400 mb-2">
                                    <svg class="w-3 h-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                    </svg>
                                    <span class="truncate">{{ lead.empresa_contacto ?? lead.nombre_contacto }}</span>
                                </div>

                                <!-- Footer tarjeta -->
                                <div class="flex items-center justify-between gap-1 mt-2">
                                    <span
                                        v-if="lead.fuente"
                                        class="text-xs bg-blue-50 text-blue-600 px-1.5 py-0.5 rounded-md truncate max-w-[100px]"
                                    >{{ lead.fuente }}</span>
                                    <span v-else class="flex-1"></span>

                                    <div class="flex items-center gap-1.5 shrink-0">
                                        <span v-if="lead.tareas_vencidas > 0"
                                            class="flex items-center gap-0.5 text-xs text-red-600 font-semibold">
                                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                            </svg>
                                            {{ lead.tareas_vencidas }}
                                        </span>
                                        <span v-else-if="lead.tareas_pendientes > 0"
                                            class="flex items-center gap-0.5 text-xs text-yellow-600">
                                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                                            </svg>
                                            {{ lead.tareas_pendientes }}
                                        </span>
                                    </div>
                                </div>

                                <!-- Responsable -->
                                <div v-if="lead.responsable"
                                    class="mt-2 flex items-center gap-1.5 text-xs text-tinta-300">
                                    <div
                                        class="w-5 h-5 rounded-full flex items-center justify-center text-white font-semibold shrink-0"
                                        style="background-color: var(--marca); font-size: 10px;"
                                    >
                                        {{ lead.responsable[0].toUpperCase() }}
                                    </div>
                                    <span class="truncate">{{ lead.responsable }}</span>
                                </div>
                            </div>

                            <!-- Zona drop vacía -->
                            <div
                                v-if="etapa.leads.length === 0"
                                class="flex items-center justify-center rounded-xl border-2 border-dashed border-linea text-xs text-tinta-300 py-6"
                            >
                                Arrastra aquí
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal crear lead -->
        <ModalLead
            v-if="modalLeadVisible"
            :etapa-id-inicial="etapaIdInicial"
            :etapas="etapas"
            :usuarios="props.usuarios"
            :clientes="props.clientes"
            @guardado="onLeadGuardado"
            @cerrar="modalLeadVisible = false"
        />

        <!-- Panel detalle lead -->
        <DetalleLead
            v-if="leadDetalleId"
            :lead-id="leadDetalleId"
            :usuarios="props.usuarios"
            :clientes="props.clientes"
            :etapas="etapas"
            @cerrar="leadDetalleId = null"
            @actualizado="cargarPipeline"
        />
    </AppLayout>
</template>
