<script setup>
import { ref, reactive, computed } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import BtnPdf from '@/Components/BtnPdf.vue'
import FirmaPad from '@/Components/FirmaPad.vue'
import { formatCOP, formatCantidad } from '@/formato'

const props = defineProps({
    remision: Object,
})

const page  = usePage()
const flash = computed(() => page.props.flash)

// ── Modal de cambio de estado ──────────────────────────────────────────────
const modalEstado     = ref(false)
const nuevoEstado     = ref('')
const datosTransporte = reactive({ transportista: '', celular_transportista: '', placa: '', costo_flete: '' })
const datosEntrega    = reactive({ nombre_receptor: '' })
const enviandoEstado  = ref(false)

function abrirModalEstado(estado) {
    nuevoEstado.value = estado
    modalEstado.value = true
}

function confirmarEstado() {
    enviandoEstado.value = true
    const payload = { estado: nuevoEstado.value }

    if (nuevoEstado.value === 'en_camino') Object.assign(payload, datosTransporte)
    if (nuevoEstado.value === 'entregada') Object.assign(payload, datosEntrega)

    router.patch(`/logistica/remisiones/${props.remision.id}/estado`, payload, {
        onSuccess: () => { modalEstado.value = false; enviandoEstado.value = false },
        onError: () => { enviandoEstado.value = false },
    })
}

// ── Firmas ─────────────────────────────────────────────────────────────────
// Despacho: desde que se confirma. Recibido: desde que sale. La de despacho puede ser la firma
// guardada en el perfil de quien despacha («Usar mi firma»), para no dibujarla en cada remisión.
const padDespacho     = ref(null)
const padRecibido     = ref(null)
const firmando        = reactive({ despacho: false, recibido: false })
const guardandoFirma  = ref(null)
const errorFirma      = reactive({ despacho: '', recibido: '' })
const receptor        = ref(props.remision.nombre_receptor ?? '')

const puedeFirmarDespacho = computed(() => ['confirmada', 'en_camino', 'entregada', 'cerrada'].includes(props.remision.estado))
const puedeFirmarRecibido = computed(() => ['en_camino', 'entregada', 'cerrada'].includes(props.remision.estado))

function enviarFirma(tipo, firma) {
    errorFirma[tipo] = ''
    if (! firma) {
        errorFirma[tipo] = 'Firma en el recuadro o sube la imagen de la firma.'
        return
    }
    if (tipo === 'recibido' && ! receptor.value.trim()) {
        errorFirma[tipo] = 'Escribe el nombre de quien recibe.'
        return
    }

    guardandoFirma.value = tipo
    router.post(`/logistica/remisiones/${props.remision.id}/firma`, {
        tipo, firma, nombre_receptor: tipo === 'recibido' ? receptor.value.trim() : undefined,
    }, {
        preserveScroll: true,
        onSuccess: () => { firmando[tipo] = false },
        onError:   (e) => { errorFirma[tipo] = Object.values(e)[0] ?? 'No se pudo guardar la firma.' },
        onFinish:  () => { guardandoFirma.value = null },
    })
}

function guardarFirma(tipo) {
    const pad = tipo === 'despacho' ? padDespacho.value : padRecibido.value
    enviarFirma(tipo, pad?.valor())
}

// ── Helpers ────────────────────────────────────────────────────────────────
const formatFecha = (d) => {
    if (!d) return '—'
    return new Date(d).toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

function eliminar() {
    if (!confirm('¿Eliminar esta remisión? Se desbloquearán los ítems de la OP.')) return
    router.delete(`/logistica/remisiones/${props.remision.id}`)
}

</script>

<template>
    <AppLayout :title="remision.numero">
        <div class="max-w-3xl mx-auto">

            <!-- Flash -->
            <div v-if="flash?.success"
                class="mb-4 px-4 py-3 rounded-xl bg-pastel-verde border border-borde-aviso-verde text-aviso-verde text-sm">
                {{ flash.success }}
            </div>
            <!-- Un error que no se ve es un botón que «no hace nada»: así parecía rota la firma. -->
            <div v-if="flash?.error || page.props.errors?.estado"
                class="mb-4 px-4 py-3 rounded-xl bg-pastel-rojo border border-borde-aviso-rojo text-aviso-rojo text-sm">
                {{ flash?.error ?? page.props.errors.estado }}
            </div>

            <!-- Entregada pero sin cobrar: no está terminada. Se cierra sola al quedar pagada. -->
            <div v-if="remision.estado === 'entregada' && remision.cobro && ! remision.cobro.pagada"
                class="mb-4 px-4 py-3 rounded-xl bg-pastel-ambar border border-borde-aviso-ambar text-aviso-ambar text-sm">
                <strong>Entregada — pendiente de pago.</strong>
                Falta verificar {{ formatCOP(remision.cobro.por_verificar) }} de la orden {{ remision.op?.numero }}.
                La remisión se cierra sola cuando la orden quede pagada y los pagos verificados.
                <a v-if="remision.op" :href="`/produccion/ops/${remision.op.id}`" class="underline font-medium ml-1">Ver cartera</a>
            </div>
            <div v-else-if="remision.estado === 'cerrada'"
                class="mb-4 px-4 py-3 rounded-xl bg-pastel-violeta border border-borde-aviso-violeta text-aviso-violeta text-sm">
                <strong>Cerrada.</strong> Entregada y pagada{{ remision.cerrada_at ? ' · ' + formatFecha(remision.cerrada_at) : '' }}.
            </div>

            <!-- Cabecera -->
            <div class="flex items-start justify-between mb-5 gap-3 flex-wrap">
                <div class="flex items-center gap-3">
                    <a href="/logistica/remisiones" class="text-tinta-300 hover:text-tinta-700">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M15 19l-7-7 7-7"/>
                        </svg>
                    </a>
                    <div>
                        <h1 class="text-xl font-semibold text-tinta-900 font-mono">{{ remision.numero }}</h1>
                        <div class="flex gap-2 mt-1">
                            <span class="text-xs px-2.5 py-0.5 rounded-full font-semibold"
                                :style="`background:${remision.estado_bg};color:${remision.estado_text};`">
                                {{ remision.estado_label }}
                            </span>
                            <span class="text-xs px-2 py-0.5 rounded-full"
                                :style="remision.tipo === 'op'
                                    ? 'background:var(--pastel-violeta);color:var(--texto-violeta);'
                                    : 'background:var(--pastel-azul-2);color:var(--texto-azul);'">
                                {{ remision.tipo === 'op' ? 'Desde OP' : 'Manual' }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Acciones por estado -->
                <div class="flex items-center gap-2 flex-wrap">
                    <template v-if="remision.estado === 'borrador'">
                        <a :href="`/logistica/remisiones/${remision.id}/editar`"
                            class="px-3 py-1.5 rounded-xl border border-tinta-200 text-xs font-medium text-tinta-700 hover:bg-tinta-50">
                            Editar
                        </a>
                        <button @click="abrirModalEstado('confirmada')"
                            class="px-3 py-1.5 rounded-xl text-xs font-semibold text-white"
                            style="background:var(--marca);">
                            Confirmar
                        </button>
                        <button @click="eliminar"
                            class="px-3 py-1.5 rounded-xl border border-borde-aviso-rojo text-xs font-medium text-aviso-rojo hover:bg-pastel-rojo">
                            Eliminar
                        </button>
                    </template>

                    <template v-if="remision.estado === 'confirmada'">
                        <button @click="abrirModalEstado('en_camino')"
                            class="px-3 py-1.5 rounded-xl text-xs font-semibold text-white"
                            style="background:var(--marca);">
                            Marcar En Camino
                        </button>
                        <button @click="abrirModalEstado('anulada')"
                            class="px-3 py-1.5 rounded-xl border border-borde-aviso-rojo text-xs font-medium text-aviso-rojo hover:bg-pastel-rojo">
                            Anular
                        </button>
                    </template>

                    <template v-if="remision.estado === 'en_camino'">
                        <button @click="abrirModalEstado('entregada')"
                            class="px-3 py-1.5 rounded-xl text-xs font-semibold text-white"
                            style="background:#059669;">
                            Marcar Entregada
                        </button>
                    </template>

                    <BtnPdf
                        v-if="remision.estado !== 'borrador'"
                        :url="`/logistica/remisiones/${remision.id}/pdf`"
                        modulo="remision"
                        label="PDF Remisión"
                    />
                </div>
            </div>

            <!-- Sección Cliente / OP -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">
                <div class="bg-superficie rounded-2xl border border-linea p-5">
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-3">Cliente</p>
                    <p class="text-base font-semibold text-tinta-900">{{ remision.cliente?.nombre ?? '—' }}</p>
                </div>
                <div v-if="remision.op" class="bg-superficie rounded-2xl border border-linea p-5">
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-3">Orden de Producción</p>
                    <a :href="`/produccion/ops/${remision.op.id}`"
                        class="text-base font-semibold text-aviso-azul hover:underline font-mono">
                        {{ remision.op.numero }}
                    </a>
                </div>
            </div>

            <!-- Ítems -->
            <div class="bg-superficie rounded-2xl border border-linea overflow-hidden mb-5">
                <div class="px-5 py-3 border-b border-linea">
                    <h2 class="text-sm font-semibold text-tinta-700">Ítems remisionados</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-tinta-50 border-b border-linea">
                                <th class="text-left px-4 py-2.5 text-xs font-semibold text-tinta-400 uppercase">Descripción</th>
                                <th class="text-center px-3 py-2.5 text-xs font-semibold text-tinta-400 uppercase w-20">Cant.</th>
                                <th class="text-left px-3 py-2.5 text-xs font-semibold text-tinta-400 uppercase w-20">Unidad</th>
                                <th class="text-left px-3 py-2.5 text-xs font-semibold text-tinta-400 uppercase">N° Serie</th>
                                <th class="text-left px-3 py-2.5 text-xs font-semibold text-tinta-400 uppercase">Notas</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-separador">
                            <tr v-for="item in remision.items" :key="item.id">
                                <td class="px-4 py-3 text-sm text-tinta-900">{{ item.descripcion }}</td>
                                <td class="px-3 py-3 text-center text-sm font-medium">{{ formatCantidad(item.cantidad) }}</td>
                                <td class="px-3 py-3 text-sm text-tinta-400">{{ item.unidad ?? '—' }}</td>
                                <td class="px-3 py-3 font-mono text-xs text-tinta-400">{{ item.numero_serie ?? '—' }}</td>
                                <td class="px-3 py-3 text-xs text-tinta-300 italic">
                                    <span v-if="item.cantidad_pendiente > 0" class="not-italic inline-block mb-0.5 px-1.5 py-0.5 rounded bg-pastel-ambar text-aviso-ambar font-medium">
                                        Pendiente por entregar: {{ formatCantidad(item.cantidad_pendiente) }}
                                    </span>
                                    <span v-if="item.notas" class="block">{{ item.notas }}</span>
                                    <span v-else-if="! (item.cantidad_pendiente > 0)">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Despacho (desde confirmada) -->
            <div v-if="['confirmada','en_camino','entregada','cerrada'].includes(remision.estado)"
                class="bg-superficie rounded-2xl border border-linea p-5 mb-5">
                <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-3">Despacho</p>
                <div class="grid grid-cols-2 gap-4 text-sm">
                    <div>
                        <span class="text-tinta-300 text-xs">Transportista</span>
                        <p class="font-medium text-tinta-900 mt-0.5">{{ remision.transportista ?? '—' }}</p>
                    </div>
                    <div>
                        <span class="text-tinta-300 text-xs">Celular</span>
                        <p class="font-medium text-tinta-900 mt-0.5">{{ remision.celular_transportista ?? '—' }}</p>
                    </div>
                    <div>
                        <span class="text-tinta-300 text-xs">Placa</span>
                        <p class="font-medium text-tinta-900 mt-0.5">{{ remision.placa ?? '—' }}</p>
                    </div>
                    <div>
                        <span class="text-tinta-300 text-xs">Costo flete</span>
                        <p class="font-medium text-tinta-900 mt-0.5">
                            {{ remision.costo_flete ? '$ ' + parseFloat(remision.costo_flete).toLocaleString('es-CO', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '—' }}
                        </p>
                    </div>
                    <div>
                        <span class="text-tinta-300 text-xs">Fecha salida</span>
                        <p class="font-medium text-tinta-900 mt-0.5">{{ formatFecha(remision.fecha_salida) }}</p>
                    </div>
                    <div>
                        <span class="text-tinta-300 text-xs">Fecha entrega</span>
                        <p class="font-medium text-tinta-900 mt-0.5">{{ formatFecha(remision.fecha_entrega) }}</p>
                    </div>
                    <div v-if="remision.nombre_receptor" class="col-span-2">
                        <span class="text-tinta-300 text-xs">Recibido por</span>
                        <p class="font-medium text-tinta-900 mt-0.5">{{ remision.nombre_receptor }}</p>
                    </div>
                </div>
            </div>

            <!-- Firmas: despacho desde que se confirma, recibido desde que sale -->
            <div v-if="puedeFirmarDespacho" class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">

                <!-- Firma despacho -->
                <div class="bg-superficie rounded-2xl border border-linea p-5">
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-3">Despachado por</p>
                    <template v-if="remision.firma_despacho && ! firmando.despacho">
                        <div class="rounded-lg border border-linea bg-white p-2">
                            <img :src="remision.firma_despacho" alt="Firma de despacho" class="w-full h-24 object-contain"/>
                        </div>
                        <p class="text-xs text-aviso-verde mt-1.5">
                            ✓ {{ remision.firma_despacho_por ?? 'Firmada' }}{{ remision.firma_despacho_at ? ' · ' + formatFecha(remision.firma_despacho_at) : '' }}
                        </p>
                        <button v-if="remision.estado !== 'cerrada'" type="button" @click="firmando.despacho = true"
                            class="mt-1 text-xs text-tinta-400 underline underline-offset-2 hover:text-tinta-600">Volver a firmar</button>
                    </template>
                    <template v-else>
                        <FirmaPad ref="padDespacho" etiqueta="Firma de quien despacha" />
                        <p v-if="errorFirma.despacho" class="mt-1 text-xs text-aviso-rojo">{{ errorFirma.despacho }}</p>
                        <div class="flex gap-2 mt-2">
                            <button v-if="remision.mi_firma" type="button" @click="enviarFirma('despacho', remision.mi_firma)"
                                :disabled="guardandoFirma === 'despacho'"
                                class="flex-1 py-1.5 rounded-lg border border-linea text-xs text-tinta-600 hover:bg-realce">
                                Usar mi firma
                            </button>
                            <button @click="guardarFirma('despacho')" type="button"
                                :disabled="guardandoFirma === 'despacho'"
                                class="flex-1 py-1.5 rounded-lg text-xs font-semibold text-white transition-opacity disabled:opacity-60"
                                style="background:var(--marca);">
                                {{ guardandoFirma === 'despacho' ? 'Guardando…' : 'Guardar firma' }}
                            </button>
                        </div>
                    </template>
                </div>

                <!-- Firma recibido -->
                <div class="bg-superficie rounded-2xl border border-linea p-5">
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-3">Recibido por</p>
                    <p v-if="! puedeFirmarRecibido" class="text-sm text-tinta-400">
                        Se firma cuando la remisión salga («En camino»).
                    </p>
                    <template v-else-if="remision.firma_recibido && ! firmando.recibido">
                        <div class="rounded-lg border border-linea bg-white p-2">
                            <img :src="remision.firma_recibido" alt="Firma de recibido" class="w-full h-24 object-contain"/>
                        </div>
                        <p class="text-xs text-aviso-verde mt-1.5">
                            ✓ {{ remision.nombre_receptor ?? 'Firmada' }}{{ remision.firma_recibido_at ? ' · ' + formatFecha(remision.firma_recibido_at) : '' }}
                        </p>
                        <button v-if="remision.estado !== 'cerrada'" type="button" @click="firmando.recibido = true"
                            class="mt-1 text-xs text-tinta-400 underline underline-offset-2 hover:text-tinta-600">Volver a firmar</button>
                    </template>
                    <template v-else>
                        <input v-model="receptor" type="text" placeholder="Nombre de quien recibe"
                            class="w-full mb-2 border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]"/>
                        <FirmaPad ref="padRecibido" etiqueta="Firma de quien recibe" :permitir-subir="false" />
                        <p v-if="errorFirma.recibido" class="mt-1 text-xs text-aviso-rojo">{{ errorFirma.recibido }}</p>
                        <button @click="guardarFirma('recibido')" type="button"
                            :disabled="guardandoFirma === 'recibido'"
                            class="mt-2 w-full py-1.5 rounded-lg text-xs font-semibold text-white transition-opacity disabled:opacity-60"
                            style="background:var(--marca);">
                            {{ guardandoFirma === 'recibido' ? 'Guardando…' : (remision.estado === 'en_camino' ? 'Guardar firma y marcar entregada' : 'Guardar firma') }}
                        </button>
                    </template>
                </div>
            </div>

            <!-- Footer info -->
            <div class="text-xs text-tinta-300 text-right mb-10">
                Creado por {{ remision.creado_por }} ·
                {{ formatFecha(remision.created_at) }}
            </div>

        </div>

        <!-- Modal cambio de estado -->
        <Teleport to="body">
            <div v-if="modalEstado" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-4"
                style="background:rgba(0,0,0,0.4);">
                <div class="bg-superficie rounded-2xl w-full max-w-sm p-6 space-y-4 shadow-xl">
                    <h3 class="text-base font-semibold text-tinta-900">
                        {{
                            nuevoEstado === 'confirmada' ? 'Confirmar remisión' :
                            nuevoEstado === 'en_camino'  ? 'Marcar en camino' :
                            nuevoEstado === 'entregada'  ? 'Marcar como entregada' :
                            'Anular remisión'
                        }}
                    </h3>

                    <!-- Datos de transporte al marcar en camino -->
                    <template v-if="nuevoEstado === 'en_camino'">
                        <input v-model="datosTransporte.transportista" type="text" placeholder="Nombre transportista"
                            class="w-full border border-linea rounded-xl px-4 py-2.5 text-sm focus:outline-none"/>
                        <input v-model="datosTransporte.celular_transportista" type="text" placeholder="Celular"
                            class="w-full border border-linea rounded-xl px-4 py-2.5 text-sm focus:outline-none"/>
                        <input v-model="datosTransporte.placa" type="text" placeholder="Placa del vehículo"
                            class="w-full border border-linea rounded-xl px-4 py-2.5 text-sm focus:outline-none"/>
                        <input v-model.number="datosTransporte.costo_flete" type="number" step="0.01" placeholder="Costo del flete (opcional)"
                            class="w-full border border-linea rounded-xl px-4 py-2.5 text-sm focus:outline-none"/>
                    </template>

                    <!-- Datos de entrega -->
                    <template v-if="nuevoEstado === 'entregada'">
                        <input v-model="datosEntrega.nombre_receptor" type="text" placeholder="Nombre de quien recibe"
                            class="w-full border border-linea rounded-xl px-4 py-2.5 text-sm focus:outline-none"/>
                    </template>

                    <p v-if="nuevoEstado === 'anulada'" class="text-sm text-aviso-rojo">
                        Se desbloquearán los ítems de la OP asociada. Esta acción no se puede deshacer.
                    </p>

                    <div class="flex gap-3">
                        <button @click="modalEstado = false" type="button"
                            class="flex-1 py-2.5 rounded-xl border border-linea text-sm text-tinta-700">
                            Cancelar
                        </button>
                        <button @click="confirmarEstado" type="button" :disabled="enviandoEstado"
                            class="flex-1 py-2.5 rounded-xl text-sm font-semibold text-white"
                            :style="nuevoEstado === 'anulada' ? 'background:#DC2626;' : 'background:var(--marca);'">
                            {{ enviandoEstado ? 'Procesando…' : 'Confirmar' }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

    </AppLayout>
</template>
