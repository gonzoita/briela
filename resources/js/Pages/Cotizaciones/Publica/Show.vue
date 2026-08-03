<script setup>
import { ref, computed } from 'vue'
import { router, usePage } from '@inertiajs/vue3'

const props = defineProps({
    cotizacion: Object,
    token:      String,
})

const page   = usePage()
const flash  = computed(() => page.props.flash ?? {})
const errors = computed(() => page.props.errors ?? {})

const cot = props.cotizacion

const formatCOP   = (v) =>
    new Intl.NumberFormat('es-CO', { maximumFractionDigits: 0 }).format(v ?? 0)
const formatFecha = (d) =>
    d ? new Date(d).toLocaleDateString('es-CO', { day: '2-digit', month: 'long', year: 'numeric' }) : '—'

// Estado reactivo local — se actualiza sin recargar página
const estadoActual  = ref(props.cotizacion.estado)
const puedeAccionar = ref(props.cotizacion.estado === 'enviada')
const motivoRechazoActual = ref(props.cotizacion.motivo_rechazo ?? '')

const BADGES = {
    borrador:  { bg: '#F3F4F6', text: '#374151', label: 'Borrador'  },
    enviada:   { bg: '#DBEAFE', text: '#1D4ED8', label: 'Enviada'   },
    aprobada:  { bg: '#D1FAE5', text: '#065F46', label: 'Aprobada'  },
    rechazada: { bg: '#FEE2E2', text: '#991B1B', label: 'Rechazada' },
    vencida:   { bg: '#FEF3C7', text: '#92400E', label: 'Vencida'   },
}
const estadoBadge = computed(() => BADGES[estadoActual.value] ?? BADGES.borrador)

const confirmando   = ref(null) // 'aprobar' | 'rechazar' | null
const procesando    = ref(false)
const motivoRechazo = ref('')

function confirmar(accion) { confirmando.value = accion; motivoRechazo.value = '' }
function cancelar()        { confirmando.value = null; motivoRechazo.value = '' }

function ejecutar() {
    if (!confirmando.value || procesando.value) return
    procesando.value = true
    const accion  = confirmando.value
    const payload = accion === 'rechazar' ? { motivo: motivoRechazo.value } : {}
    router.post(`/cotizaciones/${props.token}/${accion}`, payload, {
        onSuccess: () => {
            estadoActual.value  = accion === 'aprobar' ? 'aprobada' : 'rechazada'
            puedeAccionar.value = false
            if (accion === 'rechazar') motivoRechazoActual.value = motivoRechazo.value
        },
        onFinish: () => { procesando.value = false; confirmando.value = null },
    })
}

function calcularTotal(item) {
    const base = (item.cantidad || 0) * (item.precio_unitario || 0)
    const bd   = base - base * ((item.descuento_pct || 0) / 100)
    return bd + bd * ((item.impuesto_pct || 0) / 100)
}
</script>

<template>
    <div class="min-h-screen" style="background:#F8F9FA;">

        <!-- Header -->
        <header class="px-4 py-4 shadow-sm" style="background:var(--marca);">
            <div class="max-w-3xl mx-auto flex items-center justify-between">
                <img src="https://interfrigo.com.co/wp-content/uploads/2024/11/cropped-Diseno-sin-titulo-15.png"
                    alt="Interfrigo" class="h-8 object-contain"
                    style="filter: brightness(0) invert(1);"/>
                <div class="text-right">
                    <p class="text-white text-xs font-semibold leading-none">COTIZACIÓN</p>
                    <p class="text-blue-200 text-sm font-bold mt-0.5">{{ cot.numero }}</p>
                </div>
            </div>
        </header>

        <div class="max-w-3xl mx-auto px-4 py-6 space-y-4">

            <!-- Flash success -->
            <div v-if="flash.success"
                class="rounded-xl px-4 py-3 text-sm font-medium flex items-center gap-3"
                style="background:#DCFCE7; color:#15803D; border:1px solid #BBF7D0;">
                <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                </svg>
                {{ flash.success }}
            </div>

            <!-- Flash error -->
            <div v-if="errors.error"
                class="rounded-xl px-4 py-3 text-sm font-medium flex items-center gap-3"
                style="background:#FEE2E2; color:#DC2626; border:1px solid #FECACA;">
                <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                {{ errors.error }}
            </div>

            <!-- Estado badge -->
            <div class="flex items-center gap-3">
                <span class="text-xs px-3 py-1 rounded-full font-semibold"
                    :style="`background:${estadoBadge.bg};color:${estadoBadge.text};`">
                    {{ estadoBadge.label }}
                </span>
            </div>

            <!-- Info cliente + detalles -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="bg-white rounded-2xl border border-gray-200 p-5">
                    <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">Cliente</p>
                    <p class="text-base font-bold text-gray-900 mb-1">
                        {{ cot.cliente?.nombre ?? cot.nombre_contacto_override ?? '—' }}
                        <span v-if="cot.cliente?.apellido"> {{ cot.cliente.apellido }}</span>
                    </p>
                    <p v-if="cot.cliente?.numero_identificacion" class="text-xs text-gray-400">
                        {{ cot.cliente.tipo_identificacion }}: {{ cot.cliente.numero_identificacion }}
                    </p>
                    <p v-if="cot.cliente?.ciudad" class="text-xs text-gray-400 mt-0.5">{{ cot.cliente.ciudad }}</p>
                    <p v-if="cot.contacto" class="text-sm text-gray-600 mt-2">
                        <span class="font-medium">Contacto:</span>
                        {{ cot.contacto.nombre }} {{ cot.contacto.apellido }}
                    </p>
                </div>
                <div class="bg-white rounded-2xl border border-gray-200 p-5">
                    <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">Detalles</p>
                    <div class="space-y-1.5 text-sm">
                        <div class="flex justify-between">
                            <span class="text-gray-500">Responsable</span>
                            <span class="font-medium">{{ cot.responsable?.name }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Fecha emisión</span>
                            <span>{{ formatFecha(cot.fecha_creacion) }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Válida hasta</span>
                            <span :class="estadoActual === 'vencida' ? 'text-red-500 font-semibold' : ''">
                                {{ formatFecha(cot.fecha_validez) }}
                            </span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">Moneda</span>
                            <span>{{ cot.moneda }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Ítems -->
            <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100">
                    <h2 class="text-sm font-semibold text-gray-700">Ítems de la cotización</h2>
                </div>

                <!-- Desktop tabla -->
                <div class="hidden sm:block overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-gray-50 border-b border-gray-100">
                                <th class="text-left px-4 py-2.5 text-xs font-semibold text-gray-500 uppercase">#</th>
                                <th class="text-left px-4 py-2.5 text-xs font-semibold text-gray-500 uppercase">Descripción</th>
                                <th class="text-right px-3 py-2.5 text-xs font-semibold text-gray-500 uppercase">Cant.</th>
                                <th class="text-right px-3 py-2.5 text-xs font-semibold text-gray-500 uppercase">Precio unit.</th>
                                <th class="text-right px-4 py-2.5 text-xs font-semibold text-gray-500 uppercase">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <tr v-for="(item, idx) in cot.items" :key="item.id">
                                <td class="px-4 py-3 text-gray-400 text-xs align-top">{{ idx + 1 }}</td>
                                <td class="px-4 py-3 text-gray-700">
                                    <div class="flex items-start gap-3">
                                        <img v-if="item.imagen_url" :src="item.imagen_url" alt=""
                                            class="w-14 h-14 object-cover rounded-lg shrink-0 border border-gray-100" />
                                        <div class="min-w-0">
                                            <span class="whitespace-pre-wrap">{{ item.descripcion }}</span>
                                            <div v-if="item.descripcion_larga"
                                                v-html="item.descripcion_larga"
                                                class="text-xs text-gray-400 mt-0.5"></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-3 py-3 text-right text-gray-600 align-top">{{ parseFloat(item.cantidad) }}</td>
                                <td class="px-3 py-3 text-right text-gray-600 align-top">${{ formatCOP(item.precio_unitario) }}</td>
                                <td class="px-4 py-3 text-right font-semibold text-gray-800 align-top">${{ formatCOP(item.total_linea ?? calcularTotal(item)) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile lista -->
                <div class="sm:hidden divide-y divide-gray-100">
                    <div v-for="(item, idx) in cot.items" :key="item.id" class="px-4 py-3">
                        <div class="flex items-start gap-3 mb-1">
                            <img v-if="item.imagen_url" :src="item.imagen_url" alt=""
                                class="w-12 h-12 object-cover rounded-lg shrink-0 border border-gray-100" />
                            <div class="flex-1 min-w-0">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="text-xs text-gray-400 mb-0.5">{{ idx + 1 }}.</p>
                                        <p class="text-sm text-gray-700 whitespace-pre-wrap">{{ item.descripcion }}</p>
                                        <div v-if="item.descripcion_larga"
                                            v-html="item.descripcion_larga"
                                            class="text-xs text-gray-400 mt-0.5"></div>
                                        <p class="text-xs text-gray-400 mt-1">{{ parseFloat(item.cantidad) }} × ${{ formatCOP(item.precio_unitario) }}</p>
                                    </div>
                                    <p class="text-sm font-semibold text-gray-800 shrink-0">${{ formatCOP(item.total_linea ?? calcularTotal(item)) }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Totales -->
                <div class="px-5 py-4 border-t border-gray-100 flex justify-end">
                    <div class="w-64 space-y-1.5">
                        <div class="flex justify-between text-sm text-gray-600">
                            <span>Subtotal</span>
                            <span>${{ formatCOP(cot.subtotal) }}</span>
                        </div>
                        <div v-if="parseFloat(cot.descuento_total) > 0" class="flex justify-between text-sm text-gray-600">
                            <span>Descuento</span>
                            <span class="text-red-500">-${{ formatCOP(cot.descuento_total) }}</span>
                        </div>
                        <div v-if="parseFloat(cot.impuesto_total) > 0" class="flex justify-between text-sm text-gray-600">
                            <span>IVA</span>
                            <span>${{ formatCOP(cot.impuesto_total) }}</span>
                        </div>
                        <div class="flex justify-between text-base font-bold border-t border-gray-200 pt-2 mt-2" style="color:var(--marca);">
                            <span>TOTAL {{ cot.moneda }}</span>
                            <span>${{ formatCOP(cot.total) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Condiciones comerciales -->
            <div v-if="cot.condiciones_comerciales" class="bg-white rounded-2xl border border-gray-200 p-5">
                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Condiciones comerciales</p>
                <p class="text-sm text-gray-600 whitespace-pre-line">{{ cot.condiciones_comerciales }}</p>
            </div>

            <!-- Botones aprobación — solo si puedeAccionar -->
            <div v-if="puedeAccionar" class="bg-white rounded-2xl border border-gray-200 p-5">
                <p class="text-sm font-semibold text-gray-700 mb-1">¿Deseas aprobar esta cotización?</p>
                <p class="text-xs text-gray-400 mb-4">Al aprobar, confirmas tu aceptación de los términos y precios indicados.</p>
                <div class="flex gap-3">
                    <button @click="confirmar('rechazar')"
                        class="flex-1 py-3 rounded-xl border border-gray-300 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                        Rechazar
                    </button>
                    <button @click="confirmar('aprobar')"
                        class="flex-1 py-3 rounded-xl text-sm font-semibold text-white transition-colors"
                        style="background:var(--marca);">
                        Aprobar cotización
                    </button>
                </div>
            </div>

            <!-- Estado final (aprobada / rechazada / vencida) -->
            <div v-else-if="['aprobada','rechazada','vencida'].includes(estadoActual)"
                class="rounded-2xl border p-5"
                :class="estadoActual === 'aprobada' ? 'text-center' : ''"
                :style="estadoActual === 'aprobada'
                    ? 'background:#DCFCE7;border-color:#BBF7D0;'
                    : estadoActual === 'rechazada'
                        ? 'background:#FEE2E2;border-color:#FECACA;'
                        : 'background:#FEF3C7;border-color:#FDE68A;'">
                <p class="text-sm font-semibold"
                    :style="estadoActual === 'aprobada' ? 'color:#15803D;'
                        : estadoActual === 'rechazada' ? 'color:#DC2626;'
                        : 'color:#92400E;'">
                    {{ estadoActual === 'aprobada' ? 'Esta cotización ha sido aprobada' :
                       estadoActual === 'rechazada' ? 'Esta cotización fue rechazada' :
                       'Esta cotización ha vencido' }}
                </p>
                <p v-if="estadoActual === 'rechazada' && motivoRechazoActual"
                    class="text-xs text-red-600 mt-2 whitespace-pre-line">
                    Motivo: {{ motivoRechazoActual }}
                </p>
            </div>

            <!-- Footer -->
            <p class="text-center text-xs text-gray-400 pb-6">
                Interfrigo SAS · sgi.interfrigo.com.co
            </p>

        </div>

        <!-- Modal confirmación -->
        <Teleport to="body">
            <div v-if="confirmando" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-4" style="background:rgba(0,0,0,0.5);">
                <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm p-6">
                    <h3 class="text-base font-semibold text-gray-900 mb-2">
                        {{ confirmando === 'aprobar' ? 'Confirmar aprobación' : 'Confirmar rechazo' }}
                    </h3>
                    <p class="text-sm text-gray-500 mb-3">
                        {{ confirmando === 'aprobar'
                            ? '¿Confirmas que apruebas esta cotización y aceptas los términos indicados?'
                            : '¿Confirmas que rechazas esta cotización?' }}
                    </p>
                    <!-- Motivo de rechazo (opcional) -->
                    <div v-if="confirmando === 'rechazar'" class="mb-4">
                        <label class="block text-xs font-medium text-gray-600 mb-1">Motivo de rechazo (opcional)</label>
                        <textarea v-model="motivoRechazo" rows="3" maxlength="500"
                            class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-red-400 resize-none"
                            placeholder="Escribe el motivo si lo deseas..."></textarea>
                        <p class="text-xs text-gray-400 mt-0.5 text-right">{{ motivoRechazo.length }}/500</p>
                    </div>
                    <div class="flex gap-3">
                        <button @click="cancelar" :disabled="procesando"
                            class="flex-1 py-2.5 rounded-xl border border-gray-200 text-sm text-gray-600 disabled:opacity-50">
                            Cancelar
                        </button>
                        <button @click="ejecutar" :disabled="procesando"
                            class="flex-1 py-2.5 rounded-xl text-sm font-semibold text-white disabled:opacity-60 transition-colors"
                            :style="confirmando === 'aprobar' ? 'background:var(--marca);' : 'background:#DC2626;'">
                            {{ procesando ? 'Procesando...' : (confirmando === 'aprobar' ? 'Sí, aprobar' : 'Sí, rechazar') }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

    </div>
</template>
