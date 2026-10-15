<script setup>
import { computed, ref } from 'vue'
import { router, useForm, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { formatCOP, formatMoneda, formatPct } from '@/formato'

const props = defineProps({
    importacion: { type: Object, required: true },
    items:       { type: Array,  default: () => [] },
    totales:     { type: Object, required: true },
    factor:      { type: Number, default: null },
    avisos:      { type: Array,  default: () => [] },
    canales:     { type: Array,  default: () => [] },
    criterio:    { type: String, default: 'ultimo' },
    base:        { type: String, default: 'COP' },
    incoterms:   { type: Object, default: () => ({}) },
    // Pagos al proveedor y diferencia en cambio (DiferenciaCambioService).
    cambio:      { type: Object, default: null },
    conceptos_pago: { type: Object, default: () => ({}) },
    tasa_hoy:    { type: Number, default: null },
})

const page = usePage()
const permisos = computed(() => page.props.auth?.permisosLista ?? [])
const puedeEditar   = computed(() => permisos.value.includes('importaciones.editar'))
const puedeLiquidar = computed(() => permisos.value.includes('importaciones.liquidar'))

const borrador = computed(() => props.importacion.estado === 'borrador')
const fecha = (f) => f ? new Date(String(f).slice(0, 10) + 'T12:00:00').toLocaleDateString('es-CO', { day: 'numeric', month: 'short', year: 'numeric' }) : ''
const enMoneda = computed(() => props.importacion.moneda !== props.base)

const liquidando = ref(false)
function liquidar() {
    const n = props.items.filter(i => i.producto).length
    const aviso = n > 0
        ? `Al liquidar, el costo de ${n} producto${n === 1 ? '' : 's'} pasa a ser el de este embarque ` +
          `(${props.criterio === 'promedio' ? 'promedio ponderado' : 'el de esta importación'}) y se recalculan sus precios por canal. Después no se puede editar. ¿Liquidar?`
        : 'Después de liquidar no se puede editar. ¿Continuar?'
    if (! confirm(aviso)) return
    router.post(`/compras/importaciones/${props.importacion.id}/liquidar`, {}, {
        preserveScroll: true,
        onStart:  () => { liquidando.value = true },
        onFinish: () => { liquidando.value = false },
    })
}

function eliminar() {
    if (! confirm('¿Eliminar este borrador?')) return
    router.delete(`/compras/importaciones/${props.importacion.id}`)
}

// ─── Pagos al proveedor ──────────────────────────────────────────────────────
const enDivisa = (v) => formatMoneda(v, props.importacion.moneda, 1)
const hoy = new Date().toISOString().slice(0, 10)
const registrando = ref(false)
const pago = useForm({ fecha: hoy, concepto: 'anticipo', valor: '', tasa: props.tasa_hoy ?? '', referencia: '', notas: '' })

function abrirPago() {
    pago.reset()
    pago.clearErrors()
    // Lo natural después del primer pago es el saldo, y por el valor que falta.
    pago.concepto = props.cambio?.pagos?.length ? 'saldo' : 'anticipo'
    if (props.cambio?.pagos?.length && props.cambio.saldo > 0) pago.valor = props.cambio.saldo
    registrando.value = true
}

function guardarPago() {
    pago.post(`/compras/importaciones/${props.importacion.id}/pagos`, {
        preserveScroll: true,
        onSuccess: () => { registrando.value = false },
    })
}

function eliminarPago(p) {
    if (! confirm(`¿Eliminar el pago de ${enDivisa(p.valor)} del ${fecha(p.fecha)}?`)) return
    router.delete(`/compras/importaciones/pagos/${p.id}`, { preserveScroll: true })
}

// Lo que el pago en escritura dejaría como diferencia, mientras se teclea.
const diferenciaPrevia = computed(() => {
    const v = Number(pago.valor) || 0, t = Number(pago.tasa) || 0
    return v > 0 && t > 0 ? Math.round(v * (t - props.cambio.tasa_costo) * 100) / 100 : null
})

const resumen = computed(() => [
    { t: 'Factura del proveedor', v: props.totales.fob },
    { t: 'Flete y seguro', v: props.totales.flete_seguro },
    { t: 'Arancel', v: props.totales.arancel },
    { t: `${props.importacion.iva_descontable ? 'Impuesto (se recupera)' : 'Impuesto (suma al costo)'}`, v: props.totales.iva, tenue: props.importacion.iva_descontable },
    { t: 'Gastos de nacionalización', v: props.totales.gastos },
])
</script>

<template>
    <AppLayout :title="importacion.numero">
        <div class="max-w-4xl mx-auto px-4 py-4 space-y-4 pb-8">

            <a href="/compras/importaciones" @click.prevent="router.visit('/compras/importaciones')"
                class="inline-flex items-center gap-1.5 text-sm text-tinta-400 hover:text-tinta-700">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                Importaciones
            </a>

            <!-- Cabecera -->
            <div class="bg-superficie rounded-xl border border-linea p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h1 class="text-xl font-semibold text-tinta-900">{{ importacion.numero }}
                            <span class="ml-2 text-[11px] font-semibold px-2 py-0.5 rounded-full align-middle"
                                :class="borrador ? 'bg-tinta-100 text-tinta-700' : 'bg-pastel-verde-2 text-aviso-verde'">{{ borrador ? 'Borrador' : 'Liquidada' }}</span>
                        </h1>
                        <p class="text-xs text-tinta-400 mt-1">
                            {{ importacion.proveedor ?? 'Sin proveedor' }}<span v-if="importacion.referencia"> · {{ importacion.referencia }}</span> · {{ fecha(importacion.fecha) }}
                        </p>
                        <p class="text-xs text-tinta-400 mt-0.5">
                            <span v-if="importacion.pais_origen">Origen {{ importacion.pais_origen }} · </span>
                            <span v-if="importacion.incoterm">{{ importacion.incoterm }} ({{ incoterms[importacion.incoterm] }}) · </span>
                            Factura en {{ importacion.moneda }}<span v-if="enMoneda"> a {{ formatCOP(importacion.tasa) }} {{ base }}</span>
                            <span v-if="enMoneda && importacion.tasa_nacionalizacion"> · nacionalizada a {{ formatCOP(importacion.tasa_nacionalizacion) }} {{ base }}<span v-if="importacion.tasa_nacionalizacion_fecha"> ({{ fecha(importacion.tasa_nacionalizacion_fecha) }})</span></span>
                            <span v-if="importacion.orden_compra"> · OC {{ importacion.orden_compra }}</span>
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a v-if="borrador && puedeEditar" :href="`/compras/importaciones/${importacion.id}/editar`"
                            @click.prevent="router.visit(`/compras/importaciones/${importacion.id}/editar`)"
                            class="px-3 py-2 rounded-lg text-sm font-medium border border-linea text-tinta-700 hover:bg-realce">Editar</a>
                        <button v-if="borrador && puedeLiquidar" type="button" @click="liquidar" :disabled="liquidando"
                            class="px-4 py-2 rounded-lg text-sm font-medium text-white disabled:opacity-60" style="background:var(--marca)">
                            {{ liquidando ? 'Liquidando…' : 'Liquidar' }}
                        </button>
                        <button v-if="borrador && puedeEditar" type="button" @click="eliminar"
                            class="px-3 py-2 rounded-lg text-sm font-medium border border-borde-aviso-rojo text-aviso-rojo hover:bg-pastel-rojo">Eliminar</button>
                    </div>
                </div>
            </div>

            <p v-for="(a, i) in avisos" :key="i" class="text-xs rounded-lg px-3 py-2 border bg-pastel-ambar border-borde-aviso-ambar text-aviso-ambar">{{ a }}</p>
            <p v-if="borrador" class="text-xs rounded-lg px-3 py-2 border bg-pastel-azul border-borde-aviso-azul text-aviso-azul">
                Esto es un cálculo con los datos de hoy. Hasta que se liquide, no cambia el costo de ningún producto.
            </p>

            <!-- Resumen -->
            <div class="bg-superficie rounded-xl border border-linea p-4 space-y-3">
                <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">De dónde sale el costo, en {{ base }}</p>
                <dl class="grid grid-cols-2 sm:grid-cols-5 gap-3">
                    <div v-for="r in resumen" :key="r.t">
                        <dt class="text-xs text-tinta-400">{{ r.t }}</dt>
                        <dd class="text-sm font-semibold" :class="r.tenue ? 'text-tinta-300' : 'text-tinta-900'">{{ formatCOP(r.v) }}</dd>
                    </div>
                </dl>
                <div class="flex flex-wrap items-end justify-between gap-2 border-t border-linea pt-3">
                    <div>
                        <p class="text-xs text-tinta-400">Costo total puesto en bodega</p>
                        <p class="text-2xl font-semibold text-tinta-900">{{ formatCOP(totales.costo_total) }} <span class="text-sm font-normal text-tinta-400">{{ base }}</span></p>
                    </div>
                    <p v-if="factor" class="text-sm text-tinta-700">
                        Traerlo costó <span class="font-semibold">{{ formatPct(Math.round((factor - 1) * 10000) / 100) }}%</span> más de lo que cobró el proveedor
                    </p>
                </div>
            </div>

            <!-- Producto por producto -->
            <div class="space-y-3">
                <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Producto por producto</p>

                <div v-for="it in items" :key="it.id" class="bg-superficie rounded-xl border border-linea p-4 space-y-3">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-tinta-900">{{ it.descripcion }}</p>
                            <p class="text-xs text-tinta-400 mt-0.5">
                                {{ it.cantidad }} × {{ it.valor_unitario }} {{ importacion.moneda }}
                                <span v-if="it.codigo_hs"> · HS {{ it.codigo_hs }}</span>
                                · arancel {{ formatPct(it.arancel_pct) }}% · impuesto {{ formatPct(it.iva_pct) }}%
                                <span v-if="it.peso_kg"> · {{ it.peso_kg }} kg</span>
                            </p>
                        </div>
                        <div class="text-right shrink-0">
                            <p class="text-xs text-tinta-400">Costo por unidad</p>
                            <p class="text-lg font-semibold" style="color:var(--marca)">{{ it.costo_unitario !== null ? formatCOP(it.costo_unitario) : '—' }}</p>
                        </div>
                    </div>

                    <!-- La cascada del costo -->
                    <dl class="grid grid-cols-2 sm:grid-cols-6 gap-x-3 gap-y-1 text-xs">
                        <div><dt class="text-tinta-400">Factura</dt><dd class="text-tinta-900">{{ formatCOP(it.fob) }}</dd></div>
                        <div><dt class="text-tinta-400">+ Flete y seguro</dt><dd class="text-tinta-900">{{ formatCOP(it.flete_seguro) }}</dd></div>
                        <div><dt class="text-tinta-400">= Valor CIF</dt><dd class="text-tinta-900">{{ formatCOP(it.cif) }}</dd></div>
                        <div><dt class="text-tinta-400">+ Arancel</dt><dd class="text-tinta-900">{{ formatCOP(it.arancel) }}</dd></div>
                        <div><dt class="text-tinta-400">+ Gastos</dt><dd class="text-tinta-900">{{ formatCOP(it.gastos) }}</dd></div>
                        <div><dt class="text-tinta-400">= Costo total</dt><dd class="font-semibold text-tinta-900">{{ formatCOP(it.costo_total) }}</dd></div>
                    </dl>

                    <!-- Y a cuánto se vendería -->
                    <div v-if="canales.length && Object.keys(it.precios).length" class="rounded-lg bg-tinta-50 p-3">
                        <p class="text-xs font-semibold text-tinta-500 mb-1.5">Precio de venta con el margen de cada canal (por unidad)</p>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                            <div v-for="c in canales" :key="c.id">
                                <p class="text-[11px] text-tinta-400">{{ c.etiqueta }}</p>
                                <p class="text-sm font-semibold text-tinta-900">{{ formatCOP(it.precios[c.id]) }}</p>
                            </div>
                        </div>
                    </div>
                    <p v-if="it.aplicado" class="text-[11px] text-aviso-verde">Este costo ya es el del producto.</p>
                </div>
            </div>

            <!-- Pagos al proveedor y diferencia en cambio -->
            <div v-if="cambio?.aplica" class="bg-superficie rounded-xl border border-linea p-4 space-y-3">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Pagos al proveedor y diferencia en cambio</p>
                        <p class="text-xs text-tinta-400 mt-1">
                            El costo salió a {{ formatCOP(cambio.tasa_costo) }} {{ base }}. Lo que se pague a otra tasa es ganancia o
                            pérdida en cambio: no cambia el costo del producto.
                        </p>
                    </div>
                    <button v-if="puedeEditar && ! registrando" type="button" @click="abrirPago"
                        class="px-3 py-2 rounded-lg text-sm font-medium text-white shrink-0" style="background:var(--marca)">Registrar pago</button>
                </div>

                <dl class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <div><dt class="text-xs text-tinta-400">Factura</dt><dd class="text-sm font-semibold text-tinta-900">{{ enDivisa(cambio.factura) }}</dd></div>
                    <div><dt class="text-xs text-tinta-400">Pagado</dt><dd class="text-sm font-semibold text-tinta-900">{{ enDivisa(cambio.pagado) }}</dd></div>
                    <div><dt class="text-xs text-tinta-400">{{ cambio.saldo < 0 ? 'Pagado de más' : 'Saldo por pagar' }}</dt>
                        <dd class="text-sm font-semibold" :class="cambio.saldo > 0 ? 'text-aviso-ambar' : 'text-tinta-900'">{{ enDivisa(Math.abs(cambio.saldo)) }}</dd></div>
                    <div><dt class="text-xs text-tinta-400">Diferencia en cambio</dt>
                        <dd class="text-sm font-semibold" :class="cambio.resultado === 'perdida' ? 'text-aviso-rojo' : cambio.resultado === 'ganancia' ? 'text-aviso-verde' : 'text-tinta-900'">
                            {{ cambio.resultado === 'perdida' ? 'Pérdida' : cambio.resultado === 'ganancia' ? 'Ganancia' : 'Sin diferencia' }}
                            <span v-if="cambio.resultado">{{ formatCOP(Math.abs(cambio.diferencia)) }} {{ base }}</span>
                        </dd></div>
                </dl>

                <p v-for="(a, i) in cambio.avisos" :key="i" class="text-xs rounded-lg px-3 py-2 border bg-pastel-ambar border-borde-aviso-ambar text-aviso-ambar">{{ a }}</p>

                <!-- Registrar un pago -->
                <form v-if="registrando" @submit.prevent="guardarPago" class="rounded-lg border border-linea p-3 space-y-3">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-tinta-700 mb-1">Fecha del pago</label>
                            <input v-model="pago.fecha" type="date" required class="w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm bg-superficie"/>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-tinta-700 mb-1">Concepto</label>
                            <select v-model="pago.concepto" class="w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm bg-superficie">
                                <option v-for="(n, c) in conceptos_pago" :key="c" :value="c">{{ n }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-tinta-700 mb-1">Valor ({{ importacion.moneda }})</label>
                            <input v-model="pago.valor" type="number" step="0.01" min="0.01" required class="w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm bg-superficie"/>
                            <p v-if="pago.errors.valor" class="text-aviso-rojo text-xs mt-1">{{ pago.errors.valor }}</p>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-tinta-700 mb-1">TRM del pago ({{ base }} por 1 {{ importacion.moneda }})</label>
                            <input v-model="pago.tasa" type="number" step="any" min="0.00000001" required class="w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm bg-superficie"/>
                            <p v-if="pago.errors.tasa" class="text-aviso-rojo text-xs mt-1">{{ pago.errors.tasa }}</p>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-medium text-tinta-700 mb-1">Referencia</label>
                            <input v-model="pago.referencia" type="text" maxlength="120" placeholder="Giro, transferencia, banco…"
                                class="w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm bg-superficie"/>
                        </div>
                    </div>
                    <p v-if="diferenciaPrevia !== null" class="text-xs text-tinta-500">
                        Este pago deja {{ diferenciaPrevia > 0 ? 'una pérdida' : diferenciaPrevia < 0 ? 'una ganancia' : 'cero diferencia' }}
                        <span v-if="diferenciaPrevia !== 0">de {{ formatCOP(Math.abs(diferenciaPrevia)) }} {{ base }}</span> en cambio.
                    </p>
                    <div class="flex gap-2 justify-end">
                        <button type="button" @click="registrando = false" class="px-3 py-2 rounded-lg text-sm border border-linea text-tinta-700 hover:bg-realce">Cancelar</button>
                        <button type="submit" :disabled="pago.processing" class="px-4 py-2 rounded-lg text-sm font-medium text-white disabled:opacity-60" style="background:var(--marca)">
                            {{ pago.processing ? 'Guardando…' : 'Guardar pago' }}
                        </button>
                    </div>
                </form>

                <!-- Los pagos -->
                <div v-if="cambio.pagos.length" class="divide-y divide-separador border-t border-linea">
                    <div v-for="p in cambio.pagos" :key="p.id" class="py-2.5 flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm text-tinta-900"><span class="font-semibold">{{ p.etiqueta }}</span> · {{ enDivisa(p.valor) }} a {{ formatCOP(p.tasa) }}</p>
                            <p class="text-xs text-tinta-400">{{ fecha(p.fecha) }} · {{ formatCOP(p.en_base) }} {{ base }}<span v-if="p.referencia"> · {{ p.referencia }}</span></p>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <span class="text-xs font-semibold" :class="p.diferencia > 0 ? 'text-aviso-rojo' : p.diferencia < 0 ? 'text-aviso-verde' : 'text-tinta-400'">
                                {{ p.diferencia > 0 ? '−' : p.diferencia < 0 ? '+' : '' }}{{ formatCOP(Math.abs(p.diferencia)) }}
                            </span>
                            <button v-if="puedeEditar" type="button" @click="eliminarPago(p)" aria-label="Eliminar el pago"
                                class="p-1 rounded text-tinta-300 hover:text-aviso-rojo hover:bg-realce">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>
                    </div>
                </div>
                <p v-else-if="! registrando" class="text-xs text-tinta-400">Todavía no hay pagos registrados.</p>
            </div>

            <div v-if="importacion.notas" class="bg-superficie rounded-xl border border-linea p-4">
                <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1">Notas</p>
                <p class="text-sm text-tinta-700 whitespace-pre-line">{{ importacion.notas }}</p>
            </div>
        </div>
    </AppLayout>
</template>
