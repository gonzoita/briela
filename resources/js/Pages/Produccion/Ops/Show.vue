<script setup>
import { ref, computed } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import FinancieroOP from '@/Components/FinancieroOP.vue'
import ModalAnticipo from '@/Components/ModalAnticipo.vue'
import BtnPdf from '@/Components/BtnPdf.vue'
import HiloComentarios from '@/Components/HiloComentarios.vue'
import { useUnsavedChanges } from '@/composables/useUnsavedChanges'

const props = defineProps({
    op:           Object,
    responsables: Array,
    operarios:    Array,
    templates:    Array,
})

const op = computed(() => props.op)

const formatFecha = (d) => {
    if (!d) return '—'
    const solo = d.includes('T') ? d.split('T')[0] : d.split(' ')[0]
    return new Date(solo + 'T00:00').toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric' })
}

const BADGE = {
    borrador:      { bg: '#F3F4F6', text: '#374151' },
    confirmada:    { bg: '#DBEAFE', text: '#1D4ED8' },
    en_produccion: { bg: '#FEF3C7', text: '#92400E' },
    calidad:       { bg: '#E9D5FF', text: '#6B21A8' },
    reproceso:     { bg: '#FFEDD5', text: '#9A3412' },
    despachada:    { bg: '#D1FAE5', text: '#065F46' },
}

const ESTADOS = [
    { value: 'borrador',      label: 'Borrador'      },
    { value: 'confirmada',    label: 'Confirmada'    },
    { value: 'en_produccion', label: 'En producción' },
    { value: 'calidad',       label: 'Calidad'       },
    { value: 'reproceso',     label: 'Reproceso'     },
    { value: 'despachada',    label: 'Despachada'    },
]

const ESTADOS_ITEM = {
    pendiente:  { label: 'Pendiente',  bg: '#F3F4F6', text: '#374151' },
    en_proceso: { label: 'En proceso', bg: '#DBEAFE', text: '#1D4ED8' },
    terminado:  { label: 'Terminado',  bg: '#D1FAE5', text: '#065F46' },
}

const badgeOp    = (e) => BADGE[e] ?? BADGE.borrador
const badgeLabel = (e) => ESTADOS.find(s => s.value === e)?.label ?? e

const modalEstado    = ref(false)
const modalAnticipo  = ref(false)
const nuevoEstado    = ref(props.op.estado)
const financieroRef  = ref(null)

function cambiarEstado() {
    if (
        nuevoEstado.value === 'confirmada'
        && op.value.cliente?.requiere_anticipo
        && !op.value.tiene_cuotas
    ) {
        modalEstado.value = false
        modalAnticipo.value = true
        return
    }
    router.post(`/produccion/ops/${op.value.id}/estado`, { estado: nuevoEstado.value }, {
        onSuccess: () => { modalEstado.value = false },
    })
}

async function onAnticipo() {
    modalAnticipo.value = false
    if (financieroRef.value?.cargar) {
        await financieroRef.value.cargar()
    }
    router.reload({ preserveScroll: true })
}

function eliminar() {
    if (confirm(`¿Eliminar la orden ${op.value.numero}?`)) {
        router.delete(`/produccion/ops/${op.value.id}`)
    }
}

// ── Control de calidad ─────────────────────────────────────────────────────────
const obsCalidad      = ref(props.op.observaciones_calidad ?? '')
const motivoRechazo   = ref('')
const mostrarRechazo  = ref(false)
const subiendoFoto    = ref(false)
const guardandoCalidad= ref(false)

function getCookieCsrf() {
    return decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '')
}

async function subirFotoCalidad(e) {
    const files = Array.from(e.target.files || [])
    if (!files.length) return
    subiendoFoto.value = true
    try {
        for (const file of files) {
            const form = new FormData()
            form.append('archivo', file)
            form.append('categoria', 'foto_calidad')
            form.append('op_id', op.value.id)
            await fetch('/multimedia', {
                method: 'POST',
                headers: { 'X-XSRF-TOKEN': getCookieCsrf() },
                credentials: 'same-origin',
                body: form,
            })
        }
        router.reload({ preserveScroll: true, only: ['op'] })
    } finally {
        subiendoFoto.value = false
        e.target.value = ''
    }
}

function decidirCalidad(accion) {
    if (accion === 'rechazar' && !mostrarRechazo.value) {
        mostrarRechazo.value = true
        return
    }
    if (accion === 'rechazar' && !motivoRechazo.value.trim()) return

    guardandoCalidad.value = true
    router.post(`/produccion/ops/${op.value.id}/calidad`, {
        accion,
        observaciones_calidad: obsCalidad.value,
        motivo_rechazo: accion === 'rechazar' ? motivoRechazo.value : null,
    }, {
        preserveScroll: true,
        onFinish: () => { guardandoCalidad.value = false; mostrarRechazo.value = false },
    })
}

const itemExpandido = ref(null)
function toggleItem(id) {
    itemExpandido.value = itemExpandido.value === id ? null : id
}

const descExpandido = ref({})

const page      = usePage()
const authUser  = computed(() => page.props.auth?.user)
const puedeGestionar     = computed(() => ['administrador', 'jefe_produccion'].includes(authUser.value?.rol))

const templatePorItem = ref({})

function iniciarTrabajo(item) {
    const tid = templatePorItem.value[item.id]
    if (!tid) return
    const cantidad   = Math.floor(parseFloat(item.cantidad))
    const existentes = item.trabajos?.length ?? 0
    if (existentes >= cantidad) {
        if (!confirm('Ya existen todos los trabajos para este ítem. ¿Deseas resetearlos con el nuevo template?')) return
    }
    router.post(
        `/produccion/ops/${op.value.id}/items/${item.id}/trabajo/iniciar`,
        { template_id: tid },
        {
            preserveScroll: true,
            onSuccess: () => {
                templatePorItem.value[item.id] = ''
                router.reload({ preserveScroll: true })
            },
        }
    )
}

// ── Guard cambios sin guardar (edición inline de cantidad pendiente) ──────────
const { hasChanges, markClean } = useUnsavedChanges()

// ── Edición inline de cantidad de componente ──────────────────────────────────
const editandoCantidad = ref({})

const iniciarEditCantidad = (comp) => {
    editandoCantidad.value[comp.id] = comp.cantidad
    hasChanges.value = true
}

const guardarCantidad = async (item, comp) => {
    const nueva = parseFloat(editandoCantidad.value[comp.id])
    if (isNaN(nueva) || nueva < 0) return
    try {
        await fetch(`/produccion/ops/${op.value.id}/items/${item.id}/componentes/${comp.id}`, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': (() => {
                    const c = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))
                    return c ? decodeURIComponent(c.split('=')[1]) : ''
                })(),
                'Accept': 'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ cantidad: nueva }),
        })
        const itemIdx = op.value.items.findIndex(i => i.id === item.id)
        if (itemIdx >= 0) {
            const compIdx = op.value.items[itemIdx].componentes.findIndex(c => c.id === comp.id)
            if (compIdx >= 0) op.value.items[itemIdx].componentes[compIdx].cantidad = nueva
        }
        delete editandoCantidad.value[comp.id]
        if (Object.keys(editandoCantidad.value).length === 0) markClean()
    } catch (e) {
        console.error('Error guardando cantidad:', e)
    }
}

const cancelarEditCantidad = (comp) => {
    delete editandoCantidad.value[comp.id]
    if (Object.keys(editandoCantidad.value).length === 0) markClean()
}

const guardarObservacion = async (item, comp, valor) => {
    try {
        await fetch(`/produccion/ops/${op.value.id}/items/${item.id}/componentes/${comp.id}`, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': (() => {
                    const c = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))
                    return c ? decodeURIComponent(c.split('=')[1]) : ''
                })(),
                'Accept': 'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ observacion: valor }),
        })
        const itemIdx = op.value.items.findIndex(i => i.id === item.id)
        if (itemIdx >= 0) {
            const compIdx = op.value.items[itemIdx].componentes.findIndex(c => c.id === comp.id)
            if (compIdx >= 0) op.value.items[itemIdx].componentes[compIdx].observacion = valor
        }
    } catch (e) { console.error('Error guardando observacion:', e) }
}

function componentesConJerarquia(comps) {
    if (!comps?.length) return []
    const padres = comps.filter(c => !c.parent_componente_id)
    const result = []
    for (const padre of padres) {
        result.push(padre)
        const hijos = comps.filter(c => c.parent_componente_id === padre.id)
        for (const hijo of hijos) {
            result.push(hijo)
        }
    }
    return result
}

function componentesPorSeccion(comps) {
    if (!comps?.length) return null
    const padres = comps.filter(c => !c.parent_componente_id)
    if (!padres.some(p => p.seccion_nombre)) return null
    const hijosPorPadre = {}
    comps.filter(c => c.parent_componente_id).forEach(h => {
        if (!hijosPorPadre[h.parent_componente_id]) hijosPorPadre[h.parent_componente_id] = []
        hijosPorPadre[h.parent_componente_id].push(h)
    })
    const secciones = {}
    for (const padre of padres) {
        const sec = padre.seccion_nombre ?? 'General'
        if (!secciones[sec]) secciones[sec] = []
        secciones[sec].push({ ...padre, hijos: hijosPorPadre[padre.id] ?? [] })
    }
    return secciones
}

function eliminarTrabajos(item) {
    if (!confirm('¿Eliminar todos los trabajos de este ítem? Se perderá el progreso.')) return
    router.delete(
        `/produccion/ops/${op.value.id}/items/${item.id}/trabajo/pasos-todos`,
        {
            preserveScroll: true,
            onSuccess: () => router.reload({ preserveScroll: true }),
        }
    )
}

function marcarTerminado(item) {
    if (!confirm('¿Marcar este ítem como terminado?')) return
    router.patch(
        `/produccion/ops/${op.value.id}/items/${item.id}/terminar`,
        {},
        {
            preserveScroll: true,
            onSuccess: () => router.reload({ preserveScroll: true }),
        }
    )
}
</script>

<template>
    <AppLayout :title="op.numero">
        <div class="max-w-4xl mx-auto">

            <!-- Cabecera -->
            <div class="flex items-center justify-between mb-5">
                <div class="flex items-center gap-3">
                    <a href="/produccion/ops" class="text-tinta-300 hover:text-tinta-700">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M15 19l-7-7 7-7"/>
                        </svg>
                    </a>
                    <div>
                        <h1 class="text-xl font-semibold text-tinta-900">{{ op.numero }}</h1>
                        <span v-if="op.cotizacion_id" class="text-xs text-aviso-azul font-medium">Desde cotización</span>
                    </div>
                </div>
                <div class="flex items-center gap-2 flex-wrap justify-end">
                    <span class="text-xs px-2.5 py-1 rounded-full font-semibold"
                        :style="`background:${badgeOp(op.estado).bg};color:${badgeOp(op.estado).text};`">
                        {{ badgeLabel(op.estado) }}
                    </span>
                    <a v-if="op.token_publico" :href="`/op/${op.token_publico}`" target="_blank"
                        class="px-3 py-1.5 rounded-xl border border-tinta-200 text-xs font-medium text-tinta-700 hover:bg-tinta-50 flex items-center gap-1.5">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"/>
                        </svg>
                        QR
                    </a>
                    <button @click="modalEstado = true"
                        class="px-3 py-1.5 rounded-xl border border-tinta-200 text-xs font-medium text-tinta-700 hover:bg-tinta-50">
                        Estado
                    </button>
                    <BtnPdf
                        :url="`/produccion/ops/${op.id}/pdf`"
                        modulo="op"
                        label="PDF OP"
                    />
                    <a v-if="op.items?.some(i => !i.remisionado) && op.calidad_aprobada_at"
                        :href="`/logistica/remisiones/crear?op_id=${op.id}`"
                        class="px-3 py-1.5 rounded-xl text-xs font-semibold text-white flex items-center gap-1"
                        style="background:var(--marca);">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 17H3V5h12v12H9zm0 0h6m-6 0a2 2 0 104 0m6 0a2 2 0 104 0M15 5h4l2 4v8h-6V5z"/>
                        </svg>
                        Remisión
                    </a>
                    <span v-else-if="op.items?.some(i => !i.remisionado)"
                        class="px-3 py-1.5 rounded-xl text-xs font-medium text-tinta-300 border border-linea flex items-center gap-1 cursor-not-allowed"
                        title="Falta aprobar control de calidad">
                        Remisión (falta calidad)
                    </span>
                    <a :href="`/produccion/ops/${op.id}/editar`"
                        class="px-3 py-1.5 rounded-xl border border-tinta-200 text-xs font-medium text-tinta-700 hover:bg-tinta-50">
                        Editar
                    </a>
                </div>
            </div>

            <!-- Aviso de material faltante — no bloquea, solo informa -->
            <div v-if="op.insumos_faltantes?.length" class="mb-5 rounded-2xl p-4"
                style="background:var(--pastel-ambar); border:1px solid #F59E0B;">
                <div class="flex items-start gap-2">
                    <svg class="w-5 h-5 flex-shrink-0 mt-0.5" style="color:var(--texto-ambar);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                    </svg>
                    <div class="flex-1">
                        <p class="text-sm font-semibold" style="color:var(--texto-ambar);">
                            Puede faltar material para esta OP (según receta vs. stock actual)
                        </p>
                        <p class="text-xs mt-1" style="color:var(--texto-ambar);">
                            Es un aviso, no bloquea la producción ni el cambio de estado.
                        </p>
                        <ul class="mt-2 space-y-1">
                            <li v-for="f in op.insumos_faltantes" :key="f.producto_id" class="text-xs" style="color:var(--texto-ambar);">
                                <strong>{{ f.nombre }}</strong> — necesita {{ f.necesario }} {{ f.unidad }}, hay {{ f.disponible }} {{ f.unidad }}
                                (faltan {{ f.faltante }} {{ f.unidad }})
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Barra de progreso global -->
            <div class="mb-5 bg-superficie rounded-2xl border border-linea p-4">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-sm font-semibold text-tinta-700">Progreso general de la OP</span>
                    <span class="text-lg font-semibold text-aviso-azul">{{ parseFloat(op.porcentaje_avance ?? 0).toFixed(1) }}%</span>
                </div>
                <div class="h-3 rounded-full bg-tinta-100 overflow-hidden">
                    <div class="h-full rounded-full transition-all duration-500"
                        :style="`width:${Math.min(parseFloat(op.porcentaje_avance ?? 0), 100)}%; background:var(--marca);`">
                    </div>
                </div>
                <div class="flex justify-between text-xs text-tinta-300 mt-1.5">
                    <span>{{ op.items?.filter(i => i.estado_item === 'terminado').length ?? 0 }} ítems terminados</span>
                    <span>{{ op.items?.length ?? 0 }} ítems totales</span>
                </div>
            </div>

            <!-- Info cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">
                <div class="bg-superficie rounded-2xl border border-linea p-5">
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-3">Cliente</p>
                    <p class="text-base font-semibold text-tinta-900">{{ op.cliente_nombre ?? '—' }}</p>
                    <p v-if="op.cotizacion" class="text-xs text-aviso-azul mt-1">
                        Cotización: {{ op.cotizacion.numero }}
                    </p>
                </div>
                <div class="bg-superficie rounded-2xl border border-linea p-5">
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-3">Detalles</p>
                    <div class="space-y-1.5 text-sm">
                        <div class="flex justify-between">
                            <span class="text-tinta-400">Responsable</span>
                            <span class="font-medium">{{ op.responsable_nombre ?? '—' }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-tinta-400">Fecha creación</span>
                            <span>{{ formatFecha(op.fecha_creacion) }}</span>
                        </div>
                        <div v-if="op.fecha_entrega_estimada" class="flex justify-between">
                            <span class="text-tinta-400">Entrega estimada</span>
                            <span class="text-aviso-ambar font-medium">{{ formatFecha(op.fecha_entrega_estimada) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Ítems -->
            <div class="bg-superficie rounded-2xl border border-linea overflow-hidden mb-5">
                <div class="px-5 py-3 border-b border-linea flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-tinta-700">Ítems</h2>
                    <span class="text-xs text-tinta-300">{{ op.items?.length ?? 0 }} artículos</span>
                </div>

                <div v-if="!op.items?.length" class="px-5 py-10 text-center">
                    <p class="text-sm text-tinta-300">No hay ítems en esta orden.</p>
                </div>

                <!-- Desktop table -->
                <div v-else class="hidden md:block overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-tinta-50 border-b border-linea">
                                <th class="text-left px-4 py-2.5 text-xs font-semibold text-tinta-400 uppercase w-28">Código</th>
                                <th class="text-left px-4 py-2.5 text-xs font-semibold text-tinta-400 uppercase">Descripción</th>
                                <th class="text-center px-3 py-2.5 text-xs font-semibold text-tinta-400 uppercase w-20">Cant.</th>
                                <th class="text-left px-3 py-2.5 text-xs font-semibold text-tinta-400 uppercase w-40">Serie</th>
                                <th class="text-center px-3 py-2.5 text-xs font-semibold text-tinta-400 uppercase w-28">Avance</th>
                                <th class="text-center px-3 py-2.5 text-xs font-semibold text-tinta-400 uppercase w-28">Estado</th>
                                <th class="w-8"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-separador">
                            <template v-for="(item, idx) in op.items" :key="item.id">
                                <tr class="hover:bg-tinta-50 cursor-pointer transition-colors" @click="toggleItem(item.id)">
                                    <td class="px-4 py-3">
                                        <span class="font-mono text-xs text-tinta-400 bg-tinta-100 px-2 py-0.5 rounded">
                                            {{ item.codigo_item ?? (idx + 1) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex items-center gap-2">
                                            <span class="font-medium text-tinta-900">{{ item.descripcion }}</span>
                                            <span class="text-xs px-1.5 py-0.5 rounded-full"
                                                :style="item.tipo === 'ensamble' ? 'background:var(--pastel-violeta);color:var(--texto-violeta);'
                                                    : item.tipo === 'servicio' ? 'background:var(--pastel-ambar);color:#713F12;'
                                                    : 'background:var(--pastel-azul-2);color:#0369A1;'">
                                                {{ item.tipo }}
                                            </span>
                                        </div>
                                        <p v-if="item.descripcion_larga_texto" class="text-xs text-tinta-300 italic mt-0.5 max-w-xs">
                                            <span>{{ descExpandido[item.id]
                                                ? item.descripcion_larga_texto
                                                : item.descripcion_larga_texto.slice(0, 120) + (item.descripcion_larga_texto.length > 120 ? '…' : '') }}</span>
                                            <button v-if="item.descripcion_larga_texto.length > 120"
                                                @click.stop="descExpandido[item.id] = !descExpandido[item.id]"
                                                class="ml-1 text-blue-400 hover:text-aviso-azul underline font-normal not-italic">
                                                {{ descExpandido[item.id] ? 'ver menos' : 'ver más' }}
                                            </button>
                                        </p>
                                    </td>
                                    <td class="px-3 py-3 text-center text-tinta-700 font-medium">
                                        {{ parseFloat(item.cantidad) }}
                                    </td>
                                    <td class="px-3 py-3">
                                        <span v-if="item.numero_serie"
                                            class="font-mono text-xs text-tinta-500 bg-tinta-100 px-2 py-0.5 rounded">
                                            {{ item.numero_serie }}
                                        </span>
                                        <span v-else class="text-tinta-200">—</span>
                                    </td>
                                    <td class="px-3 py-3 text-center">
                                        <div v-if="item.trabajos?.length">
                                            <div class="flex items-center gap-1.5 justify-center">
                                                <div class="w-14 bg-tinta-200 rounded-full h-1.5">
                                                    <div class="h-1.5 rounded-full transition-all"
                                                        :style="`width:${Math.min(item.trabajos.reduce((s,t) => s + t.porcentaje_avance, 0) / item.trabajos.length, 100)}%; background:var(--marca);`">
                                                    </div>
                                                </div>
                                                <span class="text-xs font-semibold text-aviso-azul">
                                                    {{ Math.round(item.trabajos.reduce((s,t) => s + t.porcentaje_avance, 0) / item.trabajos.length) }}%
                                                </span>
                                            </div>
                                        </div>
                                        <span v-else class="text-xs text-tinta-200">—</span>
                                    </td>
                                    <td class="px-3 py-3 text-center">
                                        <div class="flex flex-col items-center gap-1">
                                            <span v-if="item.estado_item"
                                                class="text-xs px-2 py-0.5 rounded-full font-medium"
                                                :style="`background:${ESTADOS_ITEM[item.estado_item]?.bg ?? '#F3F4F6'};color:${ESTADOS_ITEM[item.estado_item]?.text ?? '#374151'};`">
                                                {{ ESTADOS_ITEM[item.estado_item]?.label ?? item.estado_item }}
                                            </span>
                                            <!-- Badge remisión basado en trabajos -->
                                            <template v-if="item.trabajos?.length">
                                                <span v-if="item.remisionado"
                                                    class="text-xs px-2 py-0.5 rounded-full font-medium"
                                                    style="background:var(--pastel-verde);color:var(--texto-verde);">
                                                    Remisionado ✓
                                                </span>
                                                <span v-else-if="item.trabajos.some(t => t.remisionado)"
                                                    class="text-xs px-2 py-0.5 rounded-full font-medium"
                                                    style="background:var(--pastel-ambar);color:var(--texto-ambar);">
                                                    Rem. {{ item.trabajos.filter(t => t.remisionado).length }}/{{ item.trabajos.length }} unid.
                                                </span>
                                            </template>
                                            <span v-else-if="item.remisionado"
                                                class="text-xs px-2 py-0.5 rounded-full font-medium"
                                                style="background:var(--pastel-verde);color:var(--texto-verde);">
                                                Remisionado ✓
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-3 py-3 text-center">
                                        <svg class="w-4 h-4 mx-auto text-tinta-200 transition-transform"
                                            :class="itemExpandido === item.id ? 'rotate-90' : ''"
                                            fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                                        </svg>
                                    </td>
                                </tr>
                                <tr v-if="itemExpandido === item.id" class="bg-tinta-50">
                                    <td colspan="7" class="px-6 pb-4 pt-2">
                                        <div class="space-y-4">

                                            <!-- Variables con imágenes de plantilla -->
                                            <div v-if="item.variables_instancia && Object.keys(item.variables_instancia).length">
                                                <p class="text-xs font-semibold text-aviso-ambar uppercase tracking-[0.12em] mb-2">Variables</p>
                                                <div v-if="item.campos_plantilla?.some(c => c.imagen_referencia)"
                                                    class="grid grid-cols-2 sm:grid-cols-3 gap-3 mb-3">
                                                    <div v-for="campo in item.campos_plantilla.filter(c => c.imagen_referencia)"
                                                        :key="campo.nombre"
                                                        class="rounded-xl overflow-hidden border border-borde-aviso-ambar bg-pastel-ambar">
                                                        <a :href="campo.imagen_referencia" target="_blank" rel="noopener">
                                                            <img :src="campo.imagen_referencia"
                                                                class="w-full h-36 object-contain bg-superficie hover:opacity-90 transition-opacity" />
                                                        </a>
                                                        <p class="text-xs text-aviso-ambar px-2 py-1.5">
                                                            {{ campo.imagen_referencia_titulo ?? campo.etiqueta }}
                                                            <strong v-if="item.variables_instancia[campo.nombre] !== undefined">
                                                                = {{ item.variables_instancia[campo.nombre] }}
                                                            </strong>
                                                        </p>
                                                    </div>
                                                </div>
                                                <div class="flex flex-wrap gap-2">
                                                    <span v-for="(val, key) in item.variables_instancia" :key="key"
                                                        class="text-xs px-2 py-1 rounded-lg bg-pastel-ambar border border-borde-aviso-ambar text-aviso-ambar">
                                                        {{ key }}: <strong>{{ val }}</strong>
                                                    </span>
                                                </div>
                                            </div>

                                            <!-- Imágenes de instancia inline -->
                                            <div v-if="item.imagenes_instancia?.length">
                                                <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-2">Imágenes</p>
                                                <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                                                    <div v-for="(img, imgIdx) in item.imagenes_instancia" :key="imgIdx"
                                                        class="rounded-xl overflow-hidden border border-linea bg-tinta-50">
                                                        <a :href="'/storage/' + img.ruta" target="_blank" rel="noopener">
                                                            <img :src="'/storage/' + img.ruta"
                                                                class="w-full h-28 object-contain bg-superficie hover:opacity-90 transition-opacity" />
                                                        </a>
                                                        <p v-if="img.titulo" class="text-xs text-tinta-400 px-2 py-1.5 truncate">{{ img.titulo }}</p>
                                                    </div>
                                                </div>
                                            </div>

                                            <div v-if="item.notas_item">
                                                <p class="text-xs font-semibold text-tinta-300 uppercase tracking-[0.12em] mb-1">Notas</p>
                                                <p class="text-sm text-tinta-500 whitespace-pre-line">{{ item.notas_item }}</p>
                                            </div>

                                            <!-- Lista de componentes editable -->
                                            <div v-if="item.componentes?.length">
                                                <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-2">
                                                    Componentes
                                                </p>
                                                <div class="rounded-xl border border-linea overflow-hidden">
                                                    <table class="w-full text-xs">
                                                        <thead>
                                                            <tr class="bg-tinta-50 border-b border-linea">
                                                                <th class="text-left px-3 py-2 font-semibold text-tinta-400">Componente</th>
                                                                <th class="text-left px-3 py-2 font-semibold text-tinta-400">Ref.</th>
                                                                <th class="text-center px-3 py-2 font-semibold text-tinta-400">Cant.</th>
                                                                <th class="text-left px-3 py-2 font-semibold text-tinta-400">Und.</th>
                                                                <th class="text-left px-3 py-2 font-semibold text-tinta-400">Obs.</th>
                                                                <th class="w-8"></th>
                                                            </tr>
                                                        </thead>
                                                        <tbody class="divide-y divide-separador">
                                                            <!-- Vista con secciones -->
                                                            <template v-if="item.tiene_secciones">
                                                                <template v-for="(padres, seccionNombre) in componentesPorSeccion(item.componentes)" :key="seccionNombre">
                                                                    <tr>
                                                                        <td colspan="6" class="px-3 py-1.5 text-xs font-semibold text-white uppercase tracking-[0.12em]" style="background:var(--marca);">
                                                                            {{ seccionNombre }}
                                                                        </td>
                                                                    </tr>
                                                                    <template v-for="padre in padres" :key="padre.id">
                                                                        <tr class="hover:bg-tinta-50">
                                                                            <td class="px-3 py-2 text-tinta-700 font-semibold">{{ padre.nombre }}</td>
                                                                            <td class="px-3 py-2 text-tinta-300 font-mono">{{ padre.referencia ?? '—' }}</td>
                                                                            <td class="px-3 py-2 text-center">
                                                                                <input v-if="editandoCantidad[padre.id] !== undefined"
                                                                                    v-model.number="editandoCantidad[padre.id]"
                                                                                    type="number" min="0" step="0.001"
                                                                                    @keyup.enter="guardarCantidad(item, padre)"
                                                                                    @keyup.escape="cancelarEditCantidad(padre)"
                                                                                    class="w-20 rounded-lg border border-blue-400 px-2 py-1 text-center text-xs focus:outline-none focus:ring-2 focus:ring-blue-300"
                                                                                    @click.stop />
                                                                                <span v-else class="font-semibold text-tinta-900">
                                                                                    {{ parseFloat(padre.cantidad).toFixed(3).replace(/\.?0+$/, '') }}
                                                                                </span>
                                                                            </td>
                                                                            <td class="px-3 py-2 text-tinta-300">{{ padre.unidad }}</td>
                                                                            <td class="px-3 py-2">
                                                                                <input :value="padre.observacion ?? ''"
                                                                                    @blur="guardarObservacion(item, padre, $event.target.value)"
                                                                                    type="text" placeholder="—"
                                                                                    class="w-full min-w-[100px] rounded-lg border border-linea px-2 py-1 text-xs text-tinta-500 focus:outline-none focus:border-borde-aviso-azul focus:ring-1 bg-transparent hover:bg-superficie"
                                                                                    @click.stop />
                                                                            </td>
                                                                            <td class="px-3 py-2">
                                                                                <div v-if="editandoCantidad[padre.id] !== undefined" class="flex gap-1">
                                                                                    <button @click.stop="guardarCantidad(item, padre)"
                                                                                        class="text-aviso-verde hover:text-aviso-verde">
                                                                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                                                                        </svg>
                                                                                    </button>
                                                                                    <button @click.stop="cancelarEditCantidad(padre)"
                                                                                        class="text-tinta-300 hover:text-tinta-500">
                                                                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                                                                        </svg>
                                                                                    </button>
                                                                                </div>
                                                                                <button v-else @click.stop="iniciarEditCantidad(padre)"
                                                                                    class="text-tinta-200 hover:text-aviso-azul transition-colors">
                                                                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                                                                    </svg>
                                                                                </button>
                                                                            </td>
                                                                        </tr>
                                                                        <tr v-for="hijo in padre.hijos" :key="hijo.id" class="bg-tinta-50/50">
                                                                            <td class="pl-6 pr-3 py-1.5 text-tinta-400">
                                                                                <span class="text-tinta-300 mr-1">↳</span>{{ hijo.nombre }}
                                                                            </td>
                                                                            <td class="px-3 py-1.5 text-tinta-200">—</td>
                                                                            <td class="px-3 py-1.5 text-center text-tinta-500 font-medium">
                                                                                {{ parseFloat(hijo.cantidad).toFixed(3).replace(/\.?0+$/, '') }}
                                                                            </td>
                                                                            <td class="px-3 py-1.5 text-tinta-300">{{ hijo.unidad }}</td>
                                                                            <td class="px-3 py-1.5"></td>
                                                                            <td class="px-3 py-1.5"></td>
                                                                        </tr>
                                                                    </template>
                                                                </template>
                                                            </template>
                                                            <!-- Vista plana (fallback) -->
                                                            <template v-else>
                                                                <template v-for="comp in componentesConJerarquia(item.componentes)" :key="comp.id">
                                                                    <!-- Fila hijo -->
                                                                    <tr v-if="comp.parent_componente_id" class="bg-tinta-50/50">
                                                                        <td class="pl-6 pr-3 py-1.5 text-tinta-400">
                                                                            <span class="text-tinta-300 mr-1">↳</span>{{ comp.nombre }}
                                                                        </td>
                                                                        <td class="px-3 py-1.5 text-tinta-200">—</td>
                                                                        <td class="px-3 py-1.5 text-center text-tinta-500 font-medium">
                                                                            {{ parseFloat(comp.cantidad).toFixed(3).replace(/\.?0+$/, '') }}
                                                                        </td>
                                                                        <td class="px-3 py-1.5 text-tinta-300">{{ comp.unidad }}</td>
                                                                        <td class="px-3 py-1.5"></td>
                                                                        <td class="px-3 py-1.5"></td>
                                                                    </tr>
                                                                    <!-- Fila padre / componente normal -->
                                                                    <tr v-else class="hover:bg-tinta-50">
                                                                        <td class="px-3 py-2 text-tinta-700 font-medium">{{ comp.nombre }}</td>
                                                                        <td class="px-3 py-2 text-tinta-300 font-mono">{{ comp.referencia ?? '—' }}</td>
                                                                        <td class="px-3 py-2 text-center">
                                                                            <input v-if="editandoCantidad[comp.id] !== undefined"
                                                                                v-model.number="editandoCantidad[comp.id]"
                                                                                type="number" min="0" step="0.001"
                                                                                @keyup.enter="guardarCantidad(item, comp)"
                                                                                @keyup.escape="cancelarEditCantidad(comp)"
                                                                                class="w-20 rounded-lg border border-blue-400 px-2 py-1 text-center text-xs focus:outline-none focus:ring-2 focus:ring-blue-300"
                                                                                @click.stop />
                                                                            <span v-else class="font-semibold text-tinta-900">
                                                                                {{ parseFloat(comp.cantidad).toFixed(3).replace(/\.?0+$/, '') }}
                                                                            </span>
                                                                        </td>
                                                                        <td class="px-3 py-2 text-tinta-300">{{ comp.unidad }}</td>
                                                                        <td class="px-3 py-2">
                                                                            <input :value="comp.observacion ?? ''"
                                                                                @blur="guardarObservacion(item, comp, $event.target.value)"
                                                                                type="text" placeholder="—"
                                                                                class="w-full min-w-[100px] rounded-lg border border-linea px-2 py-1 text-xs text-tinta-500 focus:outline-none focus:border-borde-aviso-azul focus:ring-1 bg-transparent hover:bg-superficie"
                                                                                @click.stop />
                                                                        </td>
                                                                        <td class="px-3 py-2">
                                                                            <div v-if="editandoCantidad[comp.id] !== undefined" class="flex gap-1">
                                                                                <button @click.stop="guardarCantidad(item, comp)"
                                                                                    class="text-aviso-verde hover:text-aviso-verde">
                                                                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                                                                    </svg>
                                                                                </button>
                                                                                <button @click.stop="cancelarEditCantidad(comp)"
                                                                                    class="text-tinta-300 hover:text-tinta-500">
                                                                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                                                                    </svg>
                                                                                </button>
                                                                            </div>
                                                                            <button v-else @click.stop="iniciarEditCantidad(comp)"
                                                                                class="text-tinta-200 hover:text-aviso-azul transition-colors">
                                                                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                                                                </svg>
                                                                            </button>
                                                                        </td>
                                                                    </tr>
                                                                </template>
                                                            </template>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>

                                            <!-- Estado de trabajos por unidad -->
                                            <div v-if="item.trabajos?.length">
                                                <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-2">Estado por unidad</p>
                                                <div class="flex flex-wrap gap-2">
                                                    <div v-for="t in item.trabajos" :key="t.id"
                                                        class="flex items-center gap-1.5 text-xs px-2 py-1 rounded-lg border"
                                                        :style="t.remisionado
                                                            ? 'background:var(--pastel-verde);border-color:#6EE7B7;color:var(--texto-verde);'
                                                            : t.porcentaje_avance >= 100
                                                                ? 'background:var(--pastel-azul-2);border-color:#93C5FD;color:var(--texto-azul);'
                                                                : t.porcentaje_avance > 0
                                                                    ? 'background:var(--pastel-ambar);border-color:#FCD34D;color:var(--texto-ambar);'
                                                                    : 'background:var(--superficie-2);border-color:var(--borde);color:var(--texto-3);'">
                                                        <span class="font-semibold">U{{ t.numero_unidad }}</span>
                                                        <span v-if="t.remisionado">Remisionado ✓</span>
                                                        <span v-else-if="t.porcentaje_avance >= 100">Completado ✓</span>
                                                        <span v-else-if="t.porcentaje_avance > 0">{{ Math.round(t.porcentaje_avance) }}%</span>
                                                        <span v-else>Sin iniciar</span>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Etiqueta térmica -->
                                            <div class="flex items-center gap-2 flex-wrap">
                                                <a :href="`/produccion/ops/${op.id}/etiqueta/${item.id}`" target="_blank"
                                                    @click.stop
                                                    class="text-xs px-2 py-1 rounded-lg border border-borde-aviso-ambar text-aviso-ambar hover:bg-pastel-ambar font-medium flex items-center gap-1">
                                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A2 2 0 013 12V7a2 2 0 012-2z"/>
                                                    </svg>
                                                    Etiqueta
                                                </a>
                                                <button v-if="puedeGestionar && item.estado_item !== 'terminado'"
                                                    @click.stop="marcarTerminado(item)"
                                                    class="text-xs px-2 py-1 rounded-lg border border-borde-aviso-verde text-aviso-verde hover:bg-pastel-verde font-medium flex items-center gap-1">
                                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                                    </svg>
                                                    Marcar alistado
                                                </button>
                                            </div>

                                            <!-- Template de trabajo por ítem -->
                                            <div v-if="item.tipo === 'ensamble' && item.trabajos?.length" class="flex items-center gap-2 flex-wrap mb-3">
                                                <span class="text-xs px-2 py-1 rounded-full bg-pastel-azul text-aviso-azul font-medium">
                                                    {{ item.trabajos.length }} de {{ Math.floor(parseFloat(item.cantidad)) }} trabajo(s)
                                                </span>
                                                <a v-for="t in item.trabajos" :key="t.id"
                                                    :href="`/produccion/ops/${op.id}/items/${item.id}/pdf`" target="_blank"
                                                    @click.stop
                                                    class="text-xs px-2 py-1 rounded-lg border border-borde-aviso-rojo text-aviso-rojo hover:bg-pastel-rojo font-medium">
                                                    PDF U{{ t.numero_unidad }}
                                                </a>
                                                <a :href="`/produccion/ops/${op.id}/items/${item.id}/trabajos/pdf`" target="_blank"
                                                    @click.stop
                                                    class="text-xs px-2 py-1 rounded-lg border border-borde-aviso-rojo text-aviso-rojo hover:bg-pastel-rojo font-medium flex items-center gap-1">
                                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                                                    </svg>
                                                    Todos ({{ item.trabajos.length }})
                                                </a>
                                                <button v-if="puedeGestionar"
                                                    @click.stop="eliminarTrabajos(item)"
                                                    class="text-xs text-red-400 hover:text-aviso-rojo underline ml-2">
                                                    Resetear
                                                </button>
                                            </div>
                                            <div v-if="item.tipo === 'ensamble' && (item.trabajos?.length ?? 0) < Math.floor(parseFloat(item.cantidad)) && puedeGestionar">
                                                <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-2">
                                                    Template de trabajo
                                                </p>
                                                <div class="flex gap-2">
                                                    <select v-model="templatePorItem[item.id]" @click.stop
                                                        class="flex-1 rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2">
                                                        <option value="">Seleccionar template...</option>
                                                        <option v-for="t in templates" :key="t.id" :value="t.id">
                                                            {{ t.nombre }} ({{ t.pasos_count }} pasos)
                                                        </option>
                                                    </select>
                                                    <button @click.stop="iniciarTrabajo(item)"
                                                        :disabled="!templatePorItem[item.id]"
                                                        class="px-4 py-2 rounded-xl text-white text-sm font-medium disabled:opacity-40"
                                                        style="background:var(--marca);">
                                                        Iniciar
                                                    </button>
                                                </div>
                                                <p class="text-xs text-tinta-300 mt-1">
                                                    Trabajo {{ (item.trabajos?.length ?? 0) + 1 }} de {{ Math.floor(parseFloat(item.cantidad)) }}
                                                </p>
                                            </div>

                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile cards -->
                <div class="md:hidden divide-y divide-separador">
                    <div v-for="(item, idx) in op.items" :key="item.id">
                        <div class="px-4 py-3 flex items-start gap-3 cursor-pointer hover:bg-tinta-50 transition-colors"
                            @click="toggleItem(item.id)">
                            <span class="shrink-0 mt-0.5 font-mono text-xs text-tinta-400 bg-tinta-100 px-1.5 py-0.5 rounded">
                                {{ item.codigo_item ?? (idx + 1) }}
                            </span>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 mb-0.5 flex-wrap">
                                    <span class="text-sm font-medium text-tinta-900">{{ item.descripcion }}</span>
                                    <span class="text-xs px-1.5 py-0.5 rounded-full"
                                        :style="item.tipo === 'ensamble' ? 'background:var(--pastel-violeta);color:var(--texto-violeta);'
                                            : item.tipo === 'servicio' ? 'background:var(--pastel-ambar);color:#713F12;'
                                            : 'background:var(--pastel-azul-2);color:#0369A1;'">
                                        {{ item.tipo }}
                                    </span>
                                </div>
                                <p v-if="item.descripcion_larga_texto" class="text-xs text-tinta-300 italic mt-0.5">
                                    <span>{{ descExpandido[item.id]
                                        ? item.descripcion_larga_texto
                                        : item.descripcion_larga_texto.slice(0, 100) + (item.descripcion_larga_texto.length > 100 ? '…' : '') }}</span>
                                    <button v-if="item.descripcion_larga_texto.length > 100"
                                        @click.stop="descExpandido[item.id] = !descExpandido[item.id]"
                                        class="ml-1 text-blue-400 hover:text-aviso-azul underline font-normal not-italic">
                                        {{ descExpandido[item.id] ? 'ver menos' : 'ver más' }}
                                    </button>
                                </p>
                                <div class="flex items-center gap-3 mt-1 text-xs text-tinta-400 flex-wrap">
                                    <span>Cant: <strong class="text-tinta-700">{{ parseFloat(item.cantidad) }}</strong></span>
                                    <template v-if="item.trabajos?.length">
                                        <div class="flex items-center gap-1">
                                            <div class="w-12 bg-tinta-200 rounded-full h-1.5">
                                                <div class="h-1.5 rounded-full transition-all"
                                                    :style="`width:${Math.min(item.trabajos.reduce((s,t) => s + t.porcentaje_avance, 0) / item.trabajos.length, 100)}%; background:var(--marca);`">
                                                </div>
                                            </div>
                                            <span class="font-semibold text-aviso-azul">{{ Math.round(item.trabajos.reduce((s,t) => s + t.porcentaje_avance, 0) / item.trabajos.length) }}%</span>
                                        </div>
                                    </template>
                                    <span v-if="item.numero_serie" class="font-mono bg-tinta-100 px-1.5 py-0.5 rounded text-tinta-500">
                                        {{ item.numero_serie }}
                                    </span>
                                </div>
                            </div>
                            <div class="flex flex-col items-end gap-1 shrink-0 mt-1">
                                <span v-if="item.estado_item"
                                    class="text-xs px-2 py-0.5 rounded-full font-medium"
                                    :style="`background:${ESTADOS_ITEM[item.estado_item]?.bg ?? '#F3F4F6'};color:${ESTADOS_ITEM[item.estado_item]?.text ?? '#374151'};`">
                                    {{ ESTADOS_ITEM[item.estado_item]?.label ?? item.estado_item }}
                                </span>
                                <!-- Badge remisión basado en trabajos -->
                                <template v-if="item.trabajos?.length">
                                    <span v-if="item.remisionado"
                                        class="text-xs px-2 py-0.5 rounded-full font-medium"
                                        style="background:var(--pastel-verde);color:var(--texto-verde);">
                                        Remisionado ✓
                                    </span>
                                    <span v-else-if="item.trabajos.some(t => t.remisionado)"
                                        class="text-xs px-2 py-0.5 rounded-full font-medium"
                                        style="background:var(--pastel-ambar);color:var(--texto-ambar);">
                                        Rem. {{ item.trabajos.filter(t => t.remisionado).length }}/{{ item.trabajos.length }} unid.
                                    </span>
                                </template>
                                <span v-else-if="item.remisionado"
                                    class="text-xs px-2 py-0.5 rounded-full font-medium"
                                    style="background:var(--pastel-verde);color:var(--texto-verde);">
                                    Remisionado ✓
                                </span>
                            </div>
                        </div>

                        <!-- Mobile expanded -->
                        <div v-if="itemExpandido === item.id" class="px-4 pb-4 bg-tinta-50 border-t border-linea space-y-4">

                            <!-- Variables con imágenes de plantilla -->
                            <div v-if="item.variables_instancia && Object.keys(item.variables_instancia).length" class="pt-3">
                                <p class="text-xs font-semibold text-aviso-ambar uppercase tracking-[0.12em] mb-2">Variables</p>
                                <div v-if="item.campos_plantilla?.some(c => c.imagen_referencia)"
                                    class="grid grid-cols-2 gap-2 mb-3">
                                    <div v-for="campo in item.campos_plantilla.filter(c => c.imagen_referencia)"
                                        :key="campo.nombre"
                                        class="rounded-xl overflow-hidden border border-borde-aviso-ambar bg-pastel-ambar">
                                        <a :href="campo.imagen_referencia" target="_blank" rel="noopener">
                                            <img :src="campo.imagen_referencia"
                                                class="w-full h-32 object-contain bg-superficie hover:opacity-90 transition-opacity" />
                                        </a>
                                        <p class="text-xs text-aviso-ambar px-2 py-1.5">
                                            {{ campo.imagen_referencia_titulo ?? campo.etiqueta }}
                                            <strong v-if="item.variables_instancia[campo.nombre] !== undefined">
                                                = {{ item.variables_instancia[campo.nombre] }}
                                            </strong>
                                        </p>
                                    </div>
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <span v-for="(val, key) in item.variables_instancia" :key="key"
                                        class="text-xs px-2 py-1 rounded-lg bg-pastel-ambar border border-borde-aviso-ambar text-aviso-ambar">
                                        {{ key }}: <strong>{{ val }}</strong>
                                    </span>
                                </div>
                            </div>

                            <!-- Imágenes de instancia inline -->
                            <div v-if="item.imagenes_instancia?.length">
                                <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-2">Imágenes</p>
                                <div class="grid grid-cols-2 gap-2">
                                    <div v-for="(img, imgIdx) in item.imagenes_instancia" :key="imgIdx"
                                        class="rounded-xl overflow-hidden border border-linea bg-tinta-50">
                                        <a :href="'/storage/' + img.ruta" target="_blank" rel="noopener">
                                            <img :src="'/storage/' + img.ruta"
                                                class="w-full h-28 object-contain bg-superficie hover:opacity-90 transition-opacity" />
                                        </a>
                                        <p v-if="img.titulo" class="text-xs text-tinta-400 px-2 py-1.5 truncate">{{ img.titulo }}</p>
                                    </div>
                                </div>
                            </div>

                            <div v-if="item.notas_item">
                                <p class="text-xs font-semibold text-tinta-300 uppercase tracking-[0.12em] mb-1">Notas</p>
                                <p class="text-sm text-tinta-500 whitespace-pre-line">{{ item.notas_item }}</p>
                            </div>

                            <!-- Lista de componentes editable -->
                            <div v-if="item.componentes?.length">
                                <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-2">
                                    Componentes
                                </p>
                                <div class="rounded-xl border border-linea overflow-hidden overflow-x-auto">
                                    <table class="w-full text-xs">
                                        <thead>
                                            <tr class="bg-tinta-50 border-b border-linea">
                                                <th class="text-left px-3 py-2 font-semibold text-tinta-400">Componente</th>
                                                <th class="text-left px-3 py-2 font-semibold text-tinta-400">Ref.</th>
                                                <th class="text-center px-3 py-2 font-semibold text-tinta-400">Cant.</th>
                                                <th class="text-left px-3 py-2 font-semibold text-tinta-400">Und.</th>
                                                <th class="text-left px-3 py-2 font-semibold text-tinta-400">Obs.</th>
                                                <th class="w-8"></th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-separador">
                                            <!-- Vista con secciones -->
                                            <template v-if="item.tiene_secciones">
                                                <template v-for="(padres, seccionNombre) in componentesPorSeccion(item.componentes)" :key="seccionNombre">
                                                    <tr>
                                                        <td colspan="6" class="px-3 py-1.5 text-xs font-semibold text-white uppercase tracking-[0.12em]" style="background:var(--marca);">
                                                            {{ seccionNombre }}
                                                        </td>
                                                    </tr>
                                                    <template v-for="padre in padres" :key="padre.id">
                                                        <tr class="hover:bg-tinta-50">
                                                            <td class="px-3 py-2 text-tinta-700 font-semibold">{{ padre.nombre }}</td>
                                                            <td class="px-3 py-2 text-tinta-300 font-mono">{{ padre.referencia ?? '—' }}</td>
                                                            <td class="px-3 py-2 text-center">
                                                                <input v-if="editandoCantidad[padre.id] !== undefined"
                                                                    v-model.number="editandoCantidad[padre.id]"
                                                                    type="number" min="0" step="0.001"
                                                                    @keyup.enter="guardarCantidad(item, padre)"
                                                                    @keyup.escape="cancelarEditCantidad(padre)"
                                                                    class="w-20 rounded-lg border border-blue-400 px-2 py-1 text-center text-xs focus:outline-none focus:ring-2 focus:ring-blue-300"
                                                                    @click.stop />
                                                                <span v-else class="font-semibold text-tinta-900">
                                                                    {{ parseFloat(padre.cantidad).toFixed(3).replace(/\.?0+$/, '') }}
                                                                </span>
                                                            </td>
                                                            <td class="px-3 py-2 text-tinta-300">{{ padre.unidad }}</td>
                                                            <td class="px-3 py-2">
                                                                <input :value="padre.observacion ?? ''"
                                                                    @blur="guardarObservacion(item, padre, $event.target.value)"
                                                                    type="text" placeholder="—"
                                                                    class="w-full min-w-[80px] rounded-lg border border-linea px-2 py-1 text-xs text-tinta-500 focus:outline-none focus:border-borde-aviso-azul focus:ring-1 bg-transparent hover:bg-superficie"
                                                                    @click.stop />
                                                            </td>
                                                            <td class="px-3 py-2">
                                                                <div v-if="editandoCantidad[padre.id] !== undefined" class="flex gap-1">
                                                                    <button @click.stop="guardarCantidad(item, padre)"
                                                                        class="text-aviso-verde hover:text-aviso-verde">
                                                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                                                        </svg>
                                                                    </button>
                                                                    <button @click.stop="cancelarEditCantidad(padre)"
                                                                        class="text-tinta-300 hover:text-tinta-500">
                                                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                                                        </svg>
                                                                    </button>
                                                                </div>
                                                                <button v-else @click.stop="iniciarEditCantidad(padre)"
                                                                    class="text-tinta-200 hover:text-aviso-azul transition-colors">
                                                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                                                    </svg>
                                                                </button>
                                                            </td>
                                                        </tr>
                                                        <tr v-for="hijo in padre.hijos" :key="hijo.id" class="bg-tinta-50/50">
                                                            <td class="pl-6 pr-3 py-1.5 text-tinta-400">
                                                                <span class="text-tinta-300 mr-1">↳</span>{{ hijo.nombre }}
                                                            </td>
                                                            <td class="px-3 py-1.5 text-tinta-200">—</td>
                                                            <td class="px-3 py-1.5 text-center text-tinta-500 font-medium">
                                                                {{ parseFloat(hijo.cantidad).toFixed(3).replace(/\.?0+$/, '') }}
                                                            </td>
                                                            <td class="px-3 py-1.5 text-tinta-300">{{ hijo.unidad }}</td>
                                                            <td class="px-3 py-1.5"></td>
                                                            <td class="px-3 py-1.5"></td>
                                                        </tr>
                                                    </template>
                                                </template>
                                            </template>
                                            <!-- Vista plana (fallback) -->
                                            <template v-else>
                                                <template v-for="comp in componentesConJerarquia(item.componentes)" :key="comp.id">
                                                    <!-- Fila hijo -->
                                                    <tr v-if="comp.parent_componente_id" class="bg-tinta-50/50">
                                                        <td class="pl-6 pr-3 py-1.5 text-tinta-400">
                                                            <span class="text-tinta-300 mr-1">↳</span>{{ comp.nombre }}
                                                        </td>
                                                        <td class="px-3 py-1.5 text-tinta-200">—</td>
                                                        <td class="px-3 py-1.5 text-center text-tinta-500 font-medium">
                                                            {{ parseFloat(comp.cantidad).toFixed(3).replace(/\.?0+$/, '') }}
                                                        </td>
                                                        <td class="px-3 py-1.5 text-tinta-300">{{ comp.unidad }}</td>
                                                        <td class="px-3 py-1.5"></td>
                                                        <td class="px-3 py-1.5"></td>
                                                    </tr>
                                                    <!-- Fila padre / componente normal -->
                                                    <tr v-else class="hover:bg-tinta-50">
                                                        <td class="px-3 py-2 text-tinta-700 font-medium">{{ comp.nombre }}</td>
                                                        <td class="px-3 py-2 text-tinta-300 font-mono">{{ comp.referencia ?? '—' }}</td>
                                                        <td class="px-3 py-2 text-center">
                                                            <input v-if="editandoCantidad[comp.id] !== undefined"
                                                                v-model.number="editandoCantidad[comp.id]"
                                                                type="number" min="0" step="0.001"
                                                                @keyup.enter="guardarCantidad(item, comp)"
                                                                @keyup.escape="cancelarEditCantidad(comp)"
                                                                class="w-20 rounded-lg border border-blue-400 px-2 py-1 text-center text-xs focus:outline-none focus:ring-2 focus:ring-blue-300"
                                                                @click.stop />
                                                            <span v-else class="font-semibold text-tinta-900">
                                                                {{ parseFloat(comp.cantidad).toFixed(3).replace(/\.?0+$/, '') }}
                                                            </span>
                                                        </td>
                                                        <td class="px-3 py-2 text-tinta-300">{{ comp.unidad }}</td>
                                                        <td class="px-3 py-2">
                                                            <input :value="comp.observacion ?? ''"
                                                                @blur="guardarObservacion(item, comp, $event.target.value)"
                                                                type="text" placeholder="—"
                                                                class="w-full min-w-[80px] rounded-lg border border-linea px-2 py-1 text-xs text-tinta-500 focus:outline-none focus:border-borde-aviso-azul focus:ring-1 bg-transparent hover:bg-superficie"
                                                                @click.stop />
                                                        </td>
                                                        <td class="px-3 py-2">
                                                            <div v-if="editandoCantidad[comp.id] !== undefined" class="flex gap-1">
                                                                <button @click.stop="guardarCantidad(item, comp)"
                                                                    class="text-aviso-verde hover:text-aviso-verde">
                                                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                                                    </svg>
                                                                </button>
                                                                <button @click.stop="cancelarEditCantidad(comp)"
                                                                    class="text-tinta-300 hover:text-tinta-500">
                                                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                                                    </svg>
                                                                </button>
                                                            </div>
                                                            <button v-else @click.stop="iniciarEditCantidad(comp)"
                                                                class="text-tinta-200 hover:text-aviso-azul transition-colors">
                                                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                                                </svg>
                                                            </button>
                                                        </td>
                                                    </tr>
                                                </template>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- Estado de trabajos por unidad (mobile) -->
                            <div v-if="item.trabajos?.length">
                                <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-2">Estado por unidad</p>
                                <div class="flex flex-wrap gap-2">
                                    <div v-for="t in item.trabajos" :key="t.id"
                                        class="flex items-center gap-1.5 text-xs px-2 py-1 rounded-lg border"
                                        :style="t.remisionado
                                            ? 'background:var(--pastel-verde);border-color:#6EE7B7;color:var(--texto-verde);'
                                            : t.porcentaje_avance >= 100
                                                ? 'background:var(--pastel-azul-2);border-color:#93C5FD;color:var(--texto-azul);'
                                                : t.porcentaje_avance > 0
                                                    ? 'background:var(--pastel-ambar);border-color:#FCD34D;color:var(--texto-ambar);'
                                                    : 'background:var(--superficie-2);border-color:var(--borde);color:var(--texto-3);'">
                                        <span class="font-semibold">U{{ t.numero_unidad }}</span>
                                        <span v-if="t.remisionado">Remisionado ✓</span>
                                        <span v-else-if="t.porcentaje_avance >= 100">Completado ✓</span>
                                        <span v-else-if="t.porcentaje_avance > 0">{{ Math.round(t.porcentaje_avance) }}%</span>
                                        <span v-else>Sin iniciar</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Etiqueta térmica (mobile) -->
                            <div class="flex items-center gap-2 flex-wrap">
                                <a :href="`/produccion/ops/${op.id}/etiqueta/${item.id}`" target="_blank"
                                    @click.stop
                                    class="text-xs px-2 py-1 rounded-lg border border-borde-aviso-ambar text-aviso-ambar hover:bg-pastel-ambar font-medium flex items-center gap-1">
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A2 2 0 013 12V7a2 2 0 012-2z"/>
                                    </svg>
                                    Etiqueta
                                </a>
                                <button v-if="puedeGestionar && item.estado_item !== 'terminado'"
                                    @click.stop="marcarTerminado(item)"
                                    class="text-xs px-2 py-1 rounded-lg border border-borde-aviso-verde text-aviso-verde hover:bg-pastel-verde font-medium flex items-center gap-1">
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                    </svg>
                                    Marcar alistado
                                </button>
                            </div>

                            <!-- Template de trabajo por ítem -->
                            <div v-if="item.tipo === 'ensamble' && item.trabajos?.length" class="flex items-center gap-2 flex-wrap mb-3">
                                <span class="text-xs px-2 py-1 rounded-full bg-pastel-azul text-aviso-azul font-medium">
                                    {{ item.trabajos.length }} de {{ Math.floor(parseFloat(item.cantidad)) }} trabajo(s)
                                </span>
                                <a v-for="t in item.trabajos" :key="t.id"
                                    :href="`/produccion/ops/${op.id}/items/${item.id}/pdf`" target="_blank"
                                    @click.stop
                                    class="text-xs px-2 py-1 rounded-lg border border-borde-aviso-rojo text-aviso-rojo hover:bg-pastel-rojo font-medium">
                                    PDF U{{ t.numero_unidad }}
                                </a>
                                <a :href="`/produccion/ops/${op.id}/items/${item.id}/trabajos/pdf`" target="_blank"
                                    @click.stop
                                    class="text-xs px-2 py-1 rounded-lg border border-borde-aviso-rojo text-aviso-rojo hover:bg-pastel-rojo font-medium flex items-center gap-1">
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                                    </svg>
                                    Todos ({{ item.trabajos.length }})
                                </a>
                                <button v-if="puedeGestionar"
                                    @click.stop="eliminarTrabajos(item)"
                                    class="text-xs text-red-400 hover:text-aviso-rojo underline ml-2">
                                    Resetear
                                </button>
                            </div>
                            <div v-if="item.tipo === 'ensamble' && (item.trabajos?.length ?? 0) < Math.floor(parseFloat(item.cantidad)) && puedeGestionar">
                                <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-2">
                                    Template de trabajo
                                </p>
                                <div class="flex gap-2">
                                    <select v-model="templatePorItem[item.id]" @click.stop
                                        class="flex-1 rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2">
                                        <option value="">Seleccionar template...</option>
                                        <option v-for="t in templates" :key="t.id" :value="t.id">
                                            {{ t.nombre }} ({{ t.pasos_count }} pasos)
                                        </option>
                                    </select>
                                    <button @click.stop="iniciarTrabajo(item)"
                                        :disabled="!templatePorItem[item.id]"
                                        class="px-4 py-2 rounded-xl text-white text-sm font-medium disabled:opacity-40"
                                        style="background:var(--marca);">
                                        Iniciar
                                    </button>
                                </div>
                                <p class="text-xs text-tinta-300 mt-1">
                                    Trabajo {{ (item.trabajos?.length ?? 0) + 1 }} de {{ Math.floor(parseFloat(item.cantidad)) }}
                                </p>
                            </div>

                        </div>
                    </div>
                </div>
            </div>

            <!-- Condiciones / notas -->
            <div v-if="op.condiciones" class="bg-superficie rounded-2xl border border-linea p-5 mb-4">
                <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-2">Condiciones</p>
                <p class="text-sm text-tinta-500 whitespace-pre-line">{{ op.condiciones }}</p>
            </div>
            <div v-if="op.notas_internas" class="bg-superficie rounded-2xl border border-linea p-5 mb-4">
                <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-2">Notas internas</p>
                <p class="text-sm text-tinta-500 whitespace-pre-line">{{ op.notas_internas }}</p>
            </div>

            <!-- Control de calidad -->
            <div v-if="['calidad', 'reproceso', 'despachada'].includes(op.estado) && (op.observaciones_calidad || op.motivo_rechazo || op.fotos_calidad?.length || op.estado === 'calidad')"
                class="bg-superficie rounded-2xl border border-linea p-5 mb-4">
                <div class="flex items-center gap-2 mb-3">
                    <svg class="w-4 h-4 text-aviso-violeta" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Control de calidad</p>
                </div>

                <div v-if="op.motivo_rechazo" class="mb-3 px-3 py-2 rounded-xl text-xs" style="background:var(--pastel-naranja); color:#9A3412;">
                    <span class="font-semibold">Motivo de rechazo:</span> {{ op.motivo_rechazo }}
                </div>

                <div v-if="op.calidad_aprobada_at" class="mb-3 px-3 py-2 rounded-xl text-xs flex items-center gap-2" style="background:var(--pastel-verde); color:var(--texto-verde);">
                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    Calidad aprobada — ya se puede generar la remisión y despachar.
                </div>

                <!-- Fotos de evidencia -->
                <div class="flex flex-wrap gap-2 mb-3">
                    <a v-for="f in op.fotos_calidad" :key="f.id" :href="f.url" target="_blank"
                        class="w-16 h-16 rounded-lg overflow-hidden border border-linea bg-tinta-50 shrink-0">
                        <img :src="f.url" :alt="f.nombre" class="w-full h-full object-cover"/>
                    </a>
                </div>

                <!-- Dos botones separados en vez de uno solo: el navegador del
                     celular no siempre ofrece elegir entre cámara y galería con
                     un único input, así que aquí se fuerza cada opción por
                     separado y siempre queda clara. -->
                <div v-if="op.estado === 'calidad' && !op.calidad_aprobada_at && puedeGestionar" class="flex gap-2 mb-3">
                    <label class="flex-1 flex items-center justify-center gap-2 py-2.5 rounded-xl border-2 border-dashed border-linea text-tinta-400 hover:border-tinta-200 hover:text-tinta-500 cursor-pointer text-xs font-medium">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 17a4 4 0 100-8 4 4 0 000 8z"/>
                        </svg>
                        Tomar foto
                        <input type="file" accept="image/*" capture="environment" class="hidden" @change="subirFotoCalidad"/>
                    </label>
                    <label class="flex-1 flex items-center justify-center gap-2 py-2.5 rounded-xl border-2 border-dashed border-linea text-tinta-400 hover:border-tinta-200 hover:text-tinta-500 cursor-pointer text-xs font-medium">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14M14 8h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                        Subir foto
                        <input type="file" accept="image/*" multiple class="hidden" @change="subirFotoCalidad"/>
                    </label>
                    <div v-if="subiendoFoto" class="flex items-center px-2">
                        <div class="w-4 h-4 border-2 border-tinta-200 border-t-transparent rounded-full animate-spin"></div>
                    </div>
                </div>

                <template v-if="op.estado === 'calidad' && !op.calidad_aprobada_at && puedeGestionar">
                    <label class="block text-xs font-medium text-tinta-500 mb-1.5">Observaciones (medidas, acabado, hermeticidad...)</label>
                    <textarea v-model="obsCalidad" rows="2" placeholder="Qué se revisó y en qué estado quedó..."
                        class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm mb-3 focus:ring-2 focus:outline-none"></textarea>

                    <div v-if="mostrarRechazo" class="mb-3">
                        <label class="block text-xs font-medium text-tinta-500 mb-1.5">Motivo del rechazo *</label>
                        <textarea v-model="motivoRechazo" rows="2" placeholder="Qué falló y qué hay que corregir..."
                            class="w-full rounded-xl border border-borde-aviso-naranja px-3 py-2 text-sm focus:outline-none"></textarea>
                    </div>

                    <div class="flex gap-2">
                        <button @click="decidirCalidad('aprobar')" :disabled="guardandoCalidad"
                            class="flex-1 py-2.5 rounded-xl text-white text-sm font-medium disabled:opacity-50"
                            style="background:#059669;">
                            Aprobar calidad
                        </button>
                        <button @click="decidirCalidad('rechazar')" :disabled="guardandoCalidad || (mostrarRechazo && !motivoRechazo.trim())"
                            class="flex-1 py-2.5 rounded-xl text-white text-sm font-medium disabled:opacity-50"
                            style="background:#EA580C;">
                            {{ mostrarRechazo ? 'Confirmar rechazo' : 'Rechazar (reproceso)' }}
                        </button>
                    </div>
                </template>
                <p v-else-if="op.observaciones_calidad" class="text-sm text-tinta-500 whitespace-pre-line">{{ op.observaciones_calidad }}</p>
            </div>

            <!-- Módulo Financiero -->
            <FinancieroOP
                v-if="puedeGestionar"
                ref="financieroRef"
                :op-id="op.id"
                :total-op="parseFloat(op.total ?? 0)"
            />

            <!-- Hilo interno del equipo -->
            <div class="mt-4">
                <HiloComentarios documento="op" :id="op.id" />
            </div>

            <!-- Eliminar -->
            <div class="pb-4 mt-4">
                <button @click="eliminar" class="text-xs text-red-400 hover:text-aviso-rojo hover:underline">
                    Eliminar esta orden
                </button>
            </div>

        </div>

        <!-- Modal anticipo obligatorio -->
        <Teleport to="body">
            <ModalAnticipo
                v-if="modalAnticipo"
                :op-id="op.id"
                @confirmada="onAnticipo"
                @cancelar="modalAnticipo = false"
            />
        </Teleport>

        <!-- Modal cambiar estado -->
        <Teleport to="body">
            <div v-if="modalEstado" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-4"
                style="background:rgba(0,0,0,0.5);">
                <div class="bg-superficie rounded-2xl shadow-xl w-full max-w-sm p-5">
                    <h3 class="text-base font-semibold text-tinta-900 mb-4">Cambiar estado</h3>
                    <select v-model="nuevoEstado"
                        class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm mb-2 focus:ring-2 focus:outline-none">
                        <option v-for="e in ESTADOS" :key="e.value" :value="e.value"
                            :disabled="e.value === 'despachada' && !op.calidad_aprobada_at">
                            {{ e.label }}{{ e.value === 'despachada' && !op.calidad_aprobada_at ? ' (falta calidad)' : '' }}
                        </option>
                    </select>
                    <p v-if="!op.calidad_aprobada_at" class="text-xs text-tinta-300 mb-3">
                        "Despachada" no está disponible hasta aprobar control de calidad. Normalmente esto lo hace solo la remisión.
                    </p>
                    <div class="flex gap-3">
                        <button @click="modalEstado = false"
                            class="flex-1 py-2.5 rounded-xl border border-linea text-sm text-tinta-500">
                            Cancelar
                        </button>
                        <button @click="cambiarEstado"
                            class="flex-1 py-2.5 rounded-xl text-white text-sm font-medium"
                            style="background:var(--marca);">
                            Guardar
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
