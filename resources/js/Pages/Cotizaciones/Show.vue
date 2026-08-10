<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import BtnPdf from '@/Components/BtnPdf.vue'
import { useClipboard } from '@/composables/useClipboard'

const props = defineProps({
    cotizacion:   Object,
    responsables: Array,
    // Sedes que tienen fábrica: entre ellas se elige dónde producir la OP.
    sedesFabrica: { type: Array, default: () => [] },
})

const { copyText } = useClipboard()

const copiado = ref(false)
async function copiarLink() {
    const url = `${window.location.origin}/cotizaciones/${props.cotizacion.token_publico}/aprobar`
    if (await copyText(url)) {
        copiado.value = true
        setTimeout(() => { copiado.value = false }, 2000)
    }
    marcarEnviadaSiAplica()
}

const cot = props.cotizacion

const formatCOP = (v) =>
    new Intl.NumberFormat('es-CO', { maximumFractionDigits: 0 }).format(v ?? 0)
const formatFecha = (d) =>
    d ? new Date(d).toLocaleDateString('es-CO', { day: '2-digit', month: 'long', year: 'numeric' }) : '—'

const estadoActual = ref(cot.estado)
const modalEstado  = ref(false)
const nuevoEstado  = ref(cot.estado)

const estadoBadgeActual = ref({ ...(cot.estado_badge ?? {}) })

const BADGES = {
    borrador:  { bg: '#F3F4F6', text: '#374151', label: 'Borrador'  },
    enviada:   { bg: '#DBEAFE', text: '#1D4ED8', label: 'Enviada'   },
    aprobada:  { bg: '#D1FAE5', text: '#065F46', label: 'Aprobada'  },
    rechazada: { bg: '#FEE2E2', text: '#991B1B', label: 'Rechazada' },
    vencida:   { bg: '#FEF3C7', text: '#92400E', label: 'Vencida'   },
}

const estados = [
    { value: 'borrador',  label: 'Borrador'  },
    { value: 'enviada',   label: 'Enviada'   },
    { value: 'aprobada',  label: 'Aprobada'  },
    { value: 'rechazada', label: 'Rechazada' },
    { value: 'vencida',   label: 'Vencida'   },
]

function cambiarEstado() {
    router.post(`/cotizaciones/${cot.id}/estado`, { estado: nuevoEstado.value }, {
        onSuccess: () => {
            estadoActual.value      = nuevoEstado.value
            estadoBadgeActual.value = BADGES[nuevoEstado.value] ?? BADGES.borrador
            modalEstado.value       = false
        },
    })
}

function duplicar() {
    router.post(`/cotizaciones/${cot.id}/duplicar`)
}

function eliminar() {
    if (confirm(`¿Eliminar cotización ${cot.numero}?`)) {
        router.delete(`/cotizaciones/${cot.id}`)
    }
}

// ── Seguimiento ────────────────────────────────────────────────────────────
const seguimientosLocal      = ref(cot.seguimientos ?? [])
const diasSinRespuestaLocal  = ref(cot.dias_sin_respuesta)
const notaSeguimiento        = ref('')
const guardandoSeguimiento   = ref(false)

function registrarSeguimiento() {
    if (!notaSeguimiento.value.trim()) return
    guardandoSeguimiento.value = true
    router.post(`/cotizaciones/${cot.id}/seguimiento`, { nota: notaSeguimiento.value }, {
        preserveScroll: true,
        onSuccess: (page) => {
            seguimientosLocal.value     = page.props.cotizacion?.seguimientos ?? seguimientosLocal.value
            diasSinRespuestaLocal.value = page.props.cotizacion?.dias_sin_respuesta ?? null
            notaSeguimiento.value = ''
        },
        onFinish: () => { guardandoSeguimiento.value = false },
    })
}

const modalGenerarOP   = ref(false)
const sedeFabricaId    = ref(props.sedesFabrica[0]?.id ?? null)
const anticipoInput    = ref('')
const anticipoMedio    = ref('efectivo')
const anticipoFecha    = ref(new Date().toISOString().split('T')[0])
const anticipoRef      = ref('')

function confirmarGenerarOP() {
    router.post(`/produccion/ops/desde-cotizacion/${cot.id}`, {
        sede_id:             sedeFabricaId.value,
        anticipo:            anticipoInput.value || null,
        anticipo_medio_pago: anticipoInput.value ? anticipoMedio.value : null,
        anticipo_fecha_pago: anticipoInput.value ? anticipoFecha.value : null,
        anticipo_referencia: anticipoInput.value ? (anticipoRef.value || null) : null,
    }, {
        onSuccess: () => { modalGenerarOP.value = false },
    })
}

const modalEnviar = ref(false)
const linkPublico = `${window.location.origin}/cotizaciones/${cot.token_publico}/aprobar`
const copiadoEnviar = ref(false)

// Copiar el link o mandarlo por WhatsApp ES la acción de "enviar" — no
// tiene sentido pedir un tercer clic aparte de "marcar como enviada" para
// algo que ya acaba de pasar. Ambos caminos marcan el estado solos.
function marcarEnviadaSiAplica() {
    if (estadoActual.value === 'borrador') {
        router.post(`/cotizaciones/${cot.id}/estado`, { estado: 'enviada' }, {
            onSuccess: () => {
                estadoActual.value      = 'enviada'
                estadoBadgeActual.value = BADGES['enviada']
            },
        })
    }
}

async function copiarLinkEnviar() {
    if (await copyText(linkPublico)) {
        copiadoEnviar.value = true
        setTimeout(() => { copiadoEnviar.value = false }, 2000)
    }
    marcarEnviadaSiAplica()
}

function abrirWhatsApp() {
    const msg = encodeURIComponent(`Hola, te envío la cotización *${cot.numero}* para tu revisión y aprobación:\n\n${linkPublico}`)
    window.open(`https://wa.me/?text=${msg}`, '_blank')
    marcarEnviadaSiAplica()
}

function marcarEnviada() {
    marcarEnviadaSiAplica()
    modalEnviar.value = false
}
</script>

<template>
    <AppLayout :title="cot.numero">
        <div class="max-w-4xl mx-auto">

            <!-- Cabecera nav -->
            <div class="flex items-center justify-between mb-4">
                <a href="/cotizaciones" class="flex items-center gap-2 text-tinta-400 hover:text-tinta-700 text-sm">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M15 19l-7-7 7-7"/>
                    </svg>
                    Cotizaciones
                </a>
                <div class="flex gap-2 flex-wrap justify-end">
                    <button v-if="['borrador','enviada'].includes(estadoActual) && cot.token_publico"
                        @click="modalEnviar = true"
                        class="px-3 py-1.5 rounded-xl text-xs font-semibold text-white flex items-center gap-1.5"
                        style="background:#059669;">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                        </svg>
                        Enviar al cliente
                    </button>
                    <button v-if="!cot.en_produccion" @click="modalEstado = true"
                        class="px-3 py-1.5 rounded-xl border border-tinta-200 text-xs font-medium text-tinta-700 hover:bg-tinta-50">
                        Estado
                    </button>
                    <a v-if="!cot.en_produccion" :href="`/cotizaciones/${cot.id}/editar`"
                       class="px-3 py-1.5 rounded-xl border border-tinta-200 text-xs font-medium text-tinta-700 hover:bg-tinta-50">
                        Editar
                    </a>
                    <BtnPdf
                        :url="`/cotizaciones/${cot.id}/pdf`"
                        modulo="cotizacion"
                        label="PDF"
                    />
                    <button @click="duplicar"
                        class="px-3 py-1.5 rounded-xl border border-tinta-200 text-xs font-medium text-tinta-700 hover:bg-tinta-50">
                        Duplicar
                    </button>
                    <button v-if="estadoActual === 'aprobada' && !cot.en_produccion" @click="modalGenerarOP = true"
                        class="px-3 py-1.5 rounded-xl text-xs font-semibold text-white flex items-center gap-1.5"
                        style="background:var(--marca);">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                        </svg>
                        Generar OP
                    </button>
                    <button v-if="cot.token_publico" @click="copiarLink"
                        class="px-3 py-1.5 rounded-xl border text-xs font-medium transition-colors flex items-center gap-1.5"
                        :class="copiado ? 'border-green-300 text-green-700 bg-green-50' : 'border-tinta-200 text-tinta-700 hover:bg-tinta-50'">
                        <svg v-if="!copiado" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                        </svg>
                        <svg v-else class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                        </svg>
                        {{ copiado ? '¡Copiado!' : 'Link aprobación' }}
                    </button>
                </div>
            </div>

            <!-- Header azul de documento -->
            <div class="rounded-2xl mb-5 overflow-hidden" style="background:var(--marca);">
                <div class="flex items-center justify-between px-6 py-5">
                    <div class="flex items-center gap-4">
                        <img :src="$page.props.marca.logo"
                            :alt="$page.props.marca.nombre" class="h-10 w-auto object-contain"
                            style="filter: brightness(0) invert(1);" />
                        <div class="w-px h-8 bg-white/30"/>
                        <div>
                            <p class="text-xs text-blue-200 font-medium uppercase tracking-widest">Cotización</p>
                            <h1 class="text-2xl font-semibold text-white leading-none">{{ cot.numero }}</h1>
                            <p v-if="cot.lead" class="text-xs text-blue-200 mt-1">
                                Origen: lead "{{ cot.lead.titulo }}"
                            </p>
                        </div>
                    </div>
                    <span class="text-xs px-3 py-1.5 rounded-full font-semibold"
                        :style="`background:${estadoBadgeActual.bg};color:${estadoBadgeActual.text};`">
                        {{ estadoBadgeActual.label }}
                    </span>
                </div>
            </div>

            <!-- Comisión del vendedor: visible siempre aquí para no tener que ir a buscarla a otra página -->
            <div class="mb-5 inline-flex items-center gap-2 px-3 py-1.5 rounded-xl text-xs font-semibold"
                :style="!cot.comision || !cot.comision.total_comision
                    ? 'background:#F3F4F6;color:#6B7280;'
                    : cot.comision.estado === 'confirmada'
                        ? 'background:#D1FAE5;color:#065F46;'
                        : 'background:#FEF3C7;color:#92400E;'">
                <template v-if="cot.comision && cot.comision.total_comision > 0">
                    Comisión: {{ cot.comision.estado === 'confirmada' ? 'Confirmada' : 'Proyectada' }}
                    — ${{ new Intl.NumberFormat('es-CO', { maximumFractionDigits: 0 }).format(cot.comision.total_comision) }}
                </template>
                <template v-else>
                    Sin comisión asignada en esta cotización
                </template>
            </div>

            <!-- Banner en producción -->
            <div v-if="cot.en_produccion"
                class="bg-blue-50 border border-blue-200 rounded-2xl px-5 py-4 mb-5 flex items-center gap-3">
                <svg class="w-5 h-5 text-blue-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                </svg>
                <div>
                    <p class="text-sm font-semibold text-blue-800">Cotización en producción</p>
                    <p class="text-xs text-blue-600 mt-0.5">Esta cotización tiene una Orden de Producción activa y no puede modificarse.</p>
                </div>
            </div>

            <!-- Panel motivo rechazo -->
            <div v-if="estadoActual === 'rechazada' && cot.motivo_rechazo"
                class="bg-red-50 border border-red-200 rounded-2xl px-5 py-4 mb-5">
                <p class="text-xs font-semibold text-red-600 uppercase tracking-[0.12em] mb-1">Motivo de rechazo</p>
                <p class="text-sm text-red-700 whitespace-pre-line">{{ cot.motivo_rechazo }}</p>
            </div>

            <!-- Ficha cliente + detalles -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">

                <div class="bg-white rounded-2xl border border-linea p-5">
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-3">Cliente</p>
                    <p class="text-base font-semibold text-tinta-900 mb-1">
                        {{ cot.cliente?.nombre ?? cot.nombre_contacto_override ?? '—' }}
                    </p>
                    <p v-if="cot.cliente?.numero_identificacion" class="text-xs text-tinta-300">
                        {{ cot.cliente.tipo_identificacion }}: {{ cot.cliente.numero_identificacion }}
                    </p>
                    <p v-if="cot.contacto" class="text-sm text-tinta-500 mt-2">
                        <span class="font-medium">Contacto:</span>
                        {{ cot.contacto.nombre }} {{ cot.contacto.apellido }}
                        <span v-if="cot.contacto.cargo"> — {{ cot.contacto.cargo }}</span>
                    </p>
                </div>

                <div class="bg-white rounded-2xl border border-linea p-5">
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-3">Detalles</p>
                    <div class="space-y-1.5 text-sm">
                        <div class="flex justify-between">
                            <span class="text-tinta-400">Responsable</span>
                            <span class="font-medium">{{ cot.responsable?.name }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-tinta-400">Fecha creación</span>
                            <span>{{ formatFecha(cot.fecha_creacion) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-tinta-400">Válida hasta</span>
                            <span>{{ formatFecha(cot.fecha_validez) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-tinta-400">Moneda</span>
                            <span>{{ cot.moneda }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabla de ítems -->
            <div class="bg-white rounded-2xl border border-linea overflow-hidden mb-5">
                <div class="px-5 py-3 border-b border-linea">
                    <h2 class="text-sm font-semibold text-tinta-700">Ítems</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-tinta-50 border-b border-linea">
                                <th class="text-left px-4 py-2.5 text-xs font-semibold text-tinta-400 uppercase">#</th>
                                <th class="text-left px-4 py-2.5 text-xs font-semibold text-tinta-400 uppercase">Descripción</th>
                                <th class="text-right px-3 py-2.5 text-xs font-semibold text-tinta-400 uppercase">Cant.</th>
                                <th class="text-right px-3 py-2.5 text-xs font-semibold text-tinta-400 uppercase">Precio unit.</th>
                                <th class="text-right px-3 py-2.5 text-xs font-semibold text-tinta-400 uppercase">Dto.</th>
                                <th class="text-right px-3 py-2.5 text-xs font-semibold text-tinta-400 uppercase">IVA</th>
                                <th class="text-right px-4 py-2.5 text-xs font-semibold text-tinta-400 uppercase">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <tr v-for="(item, idx) in cot.items" :key="item.id">
                                <td class="px-4 py-3 text-tinta-300 text-xs">{{ idx + 1 }}</td>
                                <td class="px-4 py-3 text-tinta-700">
                                    <span class="whitespace-pre-wrap">{{ item.descripcion }}</span>
                                    <div v-if="item.descripcion_larga" class="text-xs text-tinta-300 mt-0.5 prose prose-xs max-w-none" v-html="item.descripcion_larga"></div>
                                </td>
                                <td class="px-3 py-3 text-right text-tinta-500">{{ parseFloat(item.cantidad) }}</td>
                                <td class="px-3 py-3 text-right text-tinta-500">${{ formatCOP(item.precio_unitario) }}</td>
                                <td class="px-3 py-3 text-right text-tinta-300 text-xs">{{ parseFloat(item.descuento_pct) > 0 ? parseFloat(item.descuento_pct) + '%' : '—' }}</td>
                                <td class="px-3 py-3 text-right text-tinta-300 text-xs">{{ parseFloat(item.impuesto_pct) > 0 ? parseFloat(item.impuesto_pct) + '%' : '—' }}</td>
                                <td class="px-4 py-3 text-right font-semibold text-tinta-900">${{ formatCOP(item.total_linea) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <!-- Totales -->
                <div class="px-5 py-4 border-t border-linea flex justify-end">
                    <div class="w-56 space-y-1.5">
                        <div class="flex justify-between text-sm text-tinta-500">
                            <span>Subtotal</span>
                            <span>${{ formatCOP(cot.subtotal) }}</span>
                        </div>
                        <div v-if="parseFloat(cot.descuento_total) > 0" class="flex justify-between text-sm text-tinta-500">
                            <span>Descuento</span>
                            <span class="text-red-500">-${{ formatCOP(cot.descuento_total) }}</span>
                        </div>
                        <div v-if="parseFloat(cot.impuesto_total) > 0" class="flex justify-between text-sm text-tinta-500">
                            <span>IVA</span>
                            <span>${{ formatCOP(cot.impuesto_total) }}</span>
                        </div>
                        <div class="flex justify-between text-base font-semibold border-t border-linea pt-2 mt-2" style="color:var(--marca)">
                            <span>TOTAL {{ cot.moneda }}</span>
                            <span>${{ formatCOP(cot.total) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Condiciones -->
            <div v-if="cot.condiciones_comerciales" class="bg-white rounded-2xl border border-linea p-5 mb-4">
                <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-2">Condiciones comerciales</p>
                <p class="text-sm text-tinta-500 whitespace-pre-line">{{ cot.condiciones_comerciales }}</p>
            </div>

            <!-- Seguimiento -->
            <div class="bg-white rounded-2xl border border-linea p-5 mb-4">
                <div class="flex items-center justify-between mb-3">
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Seguimiento</p>
                    <span v-if="diasSinRespuestaLocal >= 5"
                        class="text-xs px-2 py-0.5 rounded-full font-semibold" style="background:#FEF3C7;color:#92400E;">
                        ⚠ {{ diasSinRespuestaLocal }} días sin respuesta
                    </span>
                </div>

                <div class="flex gap-2 mb-4">
                    <textarea v-model="notaSeguimiento" rows="2" placeholder="Ej: Llamé al cliente, dice que la revisa esta semana..."
                        class="flex-1 rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:ring-2 focus:outline-none"></textarea>
                    <button @click="registrarSeguimiento" :disabled="!notaSeguimiento.trim() || guardandoSeguimiento"
                        class="px-4 py-2 rounded-xl text-white text-sm font-medium disabled:opacity-50 self-end"
                        style="background:var(--marca);">
                        Registrar
                    </button>
                </div>

                <div v-if="!seguimientosLocal?.length" class="text-sm text-tinta-300">
                    Sin seguimiento registrado todavía.
                </div>
                <div v-else class="space-y-3">
                    <div v-for="s in seguimientosLocal" :key="s.id" class="flex gap-3">
                        <div class="w-1.5 h-1.5 rounded-full bg-blue-400 mt-1.5 shrink-0"></div>
                        <div class="min-w-0">
                            <p class="text-sm text-tinta-700">{{ s.nota }}</p>
                            <p class="text-xs text-tinta-300 mt-0.5">
                                {{ s.user?.name ?? 'Sistema' }} · {{ new Date(s.created_at).toLocaleString('es-CO', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Eliminar -->
            <div class="pb-4">
                <button @click="eliminar" class="text-xs text-red-400 hover:text-red-600 hover:underline">
                    Eliminar esta cotización
                </button>
            </div>
        </div>

        <!-- Modal generar OP -->
        <Teleport to="body">
            <div v-if="modalGenerarOP" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-4"
                style="background:rgba(0,0,0,0.5);">
                <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm p-5">
                    <h3 class="text-base font-semibold text-tinta-900 mb-1">Generar Orden de Producción</h3>
                    <p class="text-sm text-tinta-400 mb-4">
                        Se creará una OP desde la cotización <strong>{{ cot.numero }}</strong>.
                    </p>
                    <!-- Fábrica donde se va a producir -->
                    <div v-if="sedesFabrica.length" class="mb-3">
                        <label class="block text-xs font-medium text-tinta-500 mb-1.5">¿En qué fábrica se produce?</label>
                        <select v-model="sedeFabricaId"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:ring-2 focus:outline-none">
                            <option v-for="s in sedesFabrica" :key="s.id" :value="s.id">{{ s.nombre }}</option>
                        </select>
                    </div>

                    <label class="block text-xs font-medium text-tinta-500 mb-1.5">Anticipo (opcional)</label>
                    <input v-model="anticipoInput" type="number" min="0" step="1000" placeholder="0"
                        class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm mb-3 focus:ring-2 focus:outline-none" />

                    <div v-if="anticipoInput" class="space-y-3 mb-5">
                        <p class="text-xs text-tinta-300">
                            Este anticipo queda registrado de una vez como pago recibido — no se vuelve a pedir al confirmar la OP.
                        </p>
                        <div>
                            <label class="block text-xs font-medium text-tinta-500 mb-1.5">Medio de pago</label>
                            <select v-model="anticipoMedio"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:ring-2 focus:outline-none">
                                <option value="efectivo">Efectivo</option>
                                <option value="transferencia">Transferencia</option>
                                <option value="cheque">Cheque</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-tinta-500 mb-1.5">Fecha de pago</label>
                            <input v-model="anticipoFecha" type="date"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:ring-2 focus:outline-none" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-tinta-500 mb-1.5">Referencia (opcional)</label>
                            <input v-model="anticipoRef" placeholder="Nº cheque / transferencia"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:ring-2 focus:outline-none" />
                        </div>
                    </div>

                    <div class="flex gap-3">
                        <button @click="modalGenerarOP = false"
                            class="flex-1 py-2.5 rounded-xl border border-linea text-sm text-tinta-500">
                            Cancelar
                        </button>
                        <button @click="confirmarGenerarOP"
                            class="flex-1 py-2.5 rounded-xl text-white text-sm font-medium"
                            style="background:var(--marca);">
                            Crear OP
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Modal cambio de estado -->
        <Teleport to="body">
            <div v-if="modalEstado" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-4" style="background:rgba(0,0,0,0.5);">
                <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm p-5">
                    <h3 class="text-base font-semibold text-tinta-900 mb-4">Cambiar estado</h3>
                    <select v-model="nuevoEstado"
                        class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm mb-5 focus:ring-2 focus:outline-none">
                        <option v-for="e in estados" :key="e.value" :value="e.value">{{ e.label }}</option>
                    </select>
                    <div class="flex gap-3">
                        <button @click="modalEstado = false"
                            class="flex-1 py-2.5 rounded-xl border border-linea text-sm text-tinta-500">Cancelar</button>
                        <button @click="cambiarEstado"
                            class="flex-1 py-2.5 rounded-xl text-white text-sm font-medium"
                            style="background:var(--marca)">
                            Guardar
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Modal enviar al cliente -->
        <Teleport to="body">
            <div v-if="modalEnviar" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-4" style="background:rgba(0,0,0,0.5);">
                <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm p-5">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-base font-semibold text-tinta-900">Enviar al cliente</h3>
                        <button @click="modalEnviar = false" class="text-tinta-300 hover:text-tinta-500">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    <p class="text-xs text-tinta-400 mb-3">
                        Comparte este enlace con el cliente para que pueda ver y aprobar la cotización
                        <strong>{{ cot.numero }}</strong>.
                    </p>

                    <!-- Link -->
                    <div class="bg-tinta-50 rounded-xl px-3 py-2.5 mb-3 flex items-center gap-2">
                        <span class="text-xs text-tinta-500 flex-1 truncate font-mono">{{ linkPublico }}</span>
                        <button @click="copiarLinkEnviar"
                            class="shrink-0 px-2.5 py-1.5 rounded-lg text-xs font-medium transition-colors"
                            :class="copiadoEnviar ? 'bg-green-100 text-green-700' : 'bg-tinta-200 text-tinta-700 hover:bg-gray-300'">
                            {{ copiadoEnviar ? '¡Copiado!' : 'Copiar' }}
                        </button>
                    </div>

                    <!-- WhatsApp -->
                    <button @click="abrirWhatsApp"
                        class="w-full py-2.5 rounded-xl text-sm font-semibold text-white flex items-center justify-center gap-2 mb-3"
                        style="background:#25D366;">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                        </svg>
                        Enviar por WhatsApp
                    </button>

                    <p v-if="estadoActual === 'borrador'" class="text-xs text-tinta-300 text-center mb-2">
                        Al copiar el link o enviarlo por WhatsApp, la cotización pasa sola a "Enviada".
                    </p>
                    <button @click="marcarEnviada"
                        class="w-full py-2.5 rounded-xl border border-linea text-sm text-tinta-500 hover:bg-tinta-50">
                        Cerrar
                    </button>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
