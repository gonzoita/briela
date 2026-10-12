<script setup>
import { computed, onUnmounted, ref, watch } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { formatCOP, formatPct, monedaBase } from '@/formato'

const props = defineProps({
    importacion: { type: Object, default: null },
    proveedores: { type: Array,  default: () => [] },
    ordenes:     { type: Array,  default: () => [] },
    paises:      { type: Array,  default: () => [] },
    // Las monedas distintas de la base, y las tasas vigentes de cada una.
    monedas:     { type: Array,  default: () => [] },
    tasas:       { type: Object, default: () => ({}) },
    base:        { type: String, default: 'COP' },
    incoterms:   { type: Object, default: () => ({}) },
    metodos:     { type: Object, default: () => ({}) },
    tipos_costo: { type: Object, default: () => ({}) },
    // Lo que propone el país de la sede: nombre y tarifa del impuesto, y si se recupera.
    impuesto:    { type: Object, default: () => ({ nombre: 'IVA', defecto: 0, iva_descontable: true }) },
})

const editando = computed(() => !! props.importacion)
const hoy = new Date().toLocaleDateString('en-CA')

const monedaInicial = props.importacion?.moneda ?? (props.tasas.USD !== undefined ? 'USD' : props.monedas[0]?.codigo ?? props.base)

const form = useForm({
    proveedor_id:     props.importacion?.proveedor_id ?? '',
    orden_compra_id:  props.importacion?.orden_compra_id ?? '',
    fecha:            props.importacion?.fecha ?? hoy,
    referencia:       props.importacion?.referencia ?? '',
    pais_origen:      props.importacion?.pais_origen ?? '',
    incoterm:         props.importacion?.incoterm ?? '',
    moneda:           monedaInicial,
    tasa:             props.importacion?.tasa ?? props.tasas[monedaInicial]?.valor ?? '',
    tasa_fecha:       props.importacion?.tasa_fecha ?? props.tasas[monedaInicial]?.fecha ?? null,
    metodo_prorrateo: props.importacion?.metodo_prorrateo ?? 'valor',
    iva_descontable:  props.importacion?.iva_descontable ?? props.impuesto.iva_descontable,
    notas:            props.importacion?.notas ?? '',
    items:            (props.importacion?.items ?? []).map(i => ({ ...i })),
    costos:           (props.importacion?.costos ?? []).map(c => ({ ...c })),
})

const enBase = computed(() => form.moneda === props.base)
const nombreMoneda = (c) => props.monedas.find(m => m.codigo === c)?.nombre ?? c

// Al cambiar de moneda se pone la tasa del día. No corre al abrir: una importación que se
// reabre conserva la tasa con la que se hizo.
watch(() => form.moneda, (m) => {
    form.tasa = m === props.base ? 1 : (props.tasas[m]?.valor ?? '')
    form.tasa_fecha = m === props.base ? null : (props.tasas[m]?.fecha ?? null)
})

// El proveedor propone el país de origen.
watch(() => form.proveedor_id, (id) => {
    const pais = props.proveedores.find(p => p.id === Number(id))?.pais
    if (pais && ! form.pais_origen) form.pais_origen = pais
})

function tasaEscrita(valor) {
    form.tasa = valor
    form.tasa_fecha = hoy
}

// ─── Productos ───────────────────────────────────────────────────────────────
const buscar = ref('')
const resultados = ref([])
let espera = null

watch(buscar, (q) => {
    clearTimeout(espera)
    if (q.trim().length < 2) { resultados.value = []; return }
    espera = setTimeout(async () => {
        const res = await fetch(`/api/importaciones/productos?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } })
        if (res.ok && q === buscar.value) resultados.value = await res.json()
    }, 250)
})
onUnmounted(() => clearTimeout(espera))

function lineaVacia(extra = {}) {
    return {
        producto_id: null, descripcion: '', cantidad: 1, valor_unitario: 0, peso_kg: null,
        codigo_hs: '', arancel_pct: 0, iva_pct: props.impuesto.defecto ?? 0, ...extra,
    }
}

function agregarProducto(p) {
    form.items.push(lineaVacia({
        producto_id: p.id, descripcion: p.nombre, codigo_hs: p.codigo_hs ?? '', arancel_pct: p.arancel_pct ?? 0,
    }))
    buscar.value = ''
    resultados.value = []
}

const quitarItem = (i) => form.items.splice(i, 1)

// ─── Costos del embarque ─────────────────────────────────────────────────────
function agregarCosto(tipo) {
    form.costos.push({ tipo, concepto: props.tipos_costo[tipo] ?? '', valor: 0, en_moneda_base: tipo === 'gasto' })
}
const quitarCosto = (i) => form.costos.splice(i, 1)

// ─── La cuenta, en el servidor ───────────────────────────────────────────────
// Lo calcula `LiquidacionImportacionService`: aquí solo se pide. Dos copias de una cuenta
// aduanera terminan diciendo cifras distintas del mismo embarque.
const calculo = ref(null)
const calculando = ref(false)
let esperaCalculo = null
let ultimo = ''

async function pedirCalculo(cuerpo) {
    calculando.value = true
    try {
        const xsrf = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))
        const res = await fetch('/api/importaciones/calcular', {
            method: 'POST', credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrf ? decodeURIComponent(xsrf.split('=')[1]) : '',
            },
            body: cuerpo,
        })
        if (res.ok && cuerpo === ultimo) calculo.value = await res.json()
    } catch {
        // Sin conexión no se muestra el cálculo: se puede guardar igual.
    } finally {
        if (cuerpo === ultimo) calculando.value = false
    }
}

watch(() => JSON.stringify({
    tasa: enBase.value ? 1 : Number(form.tasa) || 0, metodo: form.metodo_prorrateo, iva_descontable: form.iva_descontable,
    items: form.items.map(i => ({ cantidad: i.cantidad, valor_unitario: i.valor_unitario, peso_kg: i.peso_kg, arancel_pct: i.arancel_pct, iva_pct: i.iva_pct })),
    costos: form.costos.map(c => ({ tipo: c.tipo, valor: c.valor, en_moneda_base: c.en_moneda_base })),
}), (cuerpo) => {
    clearTimeout(esperaCalculo)
    ultimo = cuerpo
    if (! form.items.length) { calculo.value = null; return }
    esperaCalculo = setTimeout(() => pedirCalculo(cuerpo), 400)
}, { immediate: true })
onUnmounted(() => clearTimeout(esperaCalculo))

// ─── Guardar ─────────────────────────────────────────────────────────────────
function guardar() {
    form.transform((d) => ({
        ...d,
        proveedor_id: d.proveedor_id || null,
        orden_compra_id: d.orden_compra_id || null,
        pais_origen: d.pais_origen || null,
        incoterm: d.incoterm || null,
        tasa: enBase.value ? 1 : d.tasa,
        items: d.items.map(i => ({ ...i, peso_kg: i.peso_kg === '' ? null : i.peso_kg, codigo_hs: i.codigo_hs || null })),
    }))
    editando.value ? form.put(`/compras/importaciones/${props.importacion.id}`) : form.post('/compras/importaciones')
}

const ic = 'w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm bg-superficie focus:ring-4 focus:ring-[var(--marca-suave)] focus:outline-none'
const etiqueta = 'block text-xs font-medium text-tinta-700 mb-1'
const fecha = (f) => f ? new Date(String(f).slice(0, 10) + 'T12:00:00').toLocaleDateString('es-CO', { day: 'numeric', month: 'short', year: 'numeric' }) : ''
</script>

<template>
    <AppLayout :title="editando ? `Editar ${importacion.numero}` : 'Nueva importación'">
        <form @submit.prevent="guardar" class="max-w-3xl mx-auto px-4 py-4 space-y-4 pb-8">

            <a :href="editando ? `/compras/importaciones/${importacion.id}` : '/compras/importaciones'"
                @click.prevent="router.visit(editando ? `/compras/importaciones/${importacion.id}` : '/compras/importaciones')"
                class="inline-flex items-center gap-1.5 text-sm text-tinta-400 hover:text-tinta-700">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                Importaciones
            </a>

            <!-- El embarque -->
            <section class="bg-superficie rounded-xl border border-linea p-4 space-y-3">
                <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">El embarque</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label :class="etiqueta">Proveedor</label>
                        <select v-model="form.proveedor_id" :class="ic">
                            <option value="">Sin proveedor</option>
                            <option v-for="p in proveedores" :key="p.id" :value="p.id">{{ p.nombre }}</option>
                        </select>
                    </div>
                    <div>
                        <label :class="etiqueta">Orden de compra (opcional)</label>
                        <select v-model="form.orden_compra_id" :class="ic">
                            <option value="">Ninguna</option>
                            <option v-for="o in ordenes" :key="o.id" :value="o.id">{{ o.numero }}{{ o.proveedor ? ` · ${o.proveedor}` : '' }}</option>
                        </select>
                    </div>
                    <div>
                        <label :class="etiqueta">Fecha</label>
                        <input v-model="form.fecha" type="date" :class="ic"/>
                        <p v-if="form.errors.fecha" class="text-aviso-rojo text-xs mt-1">{{ form.errors.fecha }}</p>
                    </div>
                    <div>
                        <label :class="etiqueta">Factura o guía de embarque</label>
                        <input v-model="form.referencia" type="text" maxlength="100" placeholder="Ej: INV-2026-0412" :class="ic"/>
                    </div>
                    <div>
                        <label :class="etiqueta">País de origen</label>
                        <select v-model="form.pais_origen" :class="ic">
                            <option value="">—</option>
                            <option v-for="p in paises" :key="p.iso" :value="p.iso">{{ p.nombre }}</option>
                        </select>
                    </div>
                    <div>
                        <label :class="etiqueta">Incoterm</label>
                        <select v-model="form.incoterm" :class="ic">
                            <option value="">—</option>
                            <option v-for="(n, c) in incoterms" :key="c" :value="c">{{ c }} · {{ n }}</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label :class="etiqueta">Moneda de la factura</label>
                        <select v-model="form.moneda" :class="ic">
                            <option :value="base">{{ base }} — moneda base</option>
                            <option v-for="m in monedas" :key="m.codigo" :value="m.codigo">{{ m.codigo }} — {{ m.nombre }}</option>
                        </select>
                    </div>
                    <div v-if="! enBase">
                        <label :class="etiqueta">Tasa ({{ base }} por 1 {{ form.moneda }})</label>
                        <input :value="form.tasa" @input="tasaEscrita(Number($event.target.value) || 0)" type="number" step="any" min="0.00000001" :class="ic"/>
                        <p v-if="form.tasa_fecha" class="text-xs text-tinta-300 mt-1">Tasa del {{ fecha(form.tasa_fecha) }}. Al liquidar queda fija en esta importación.</p>
                        <p v-if="! tasas[form.moneda] && ! form.tasa" class="text-xs text-aviso-ambar mt-1">
                            No hay tasa de {{ form.moneda }} guardada: escríbela aquí, o regístrala en Configuración → Monedas.
                        </p>
                        <p v-if="form.errors.tasa" class="text-aviso-rojo text-xs mt-1">{{ form.errors.tasa }}</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label :class="etiqueta">Cómo se reparten flete, seguro y gastos</label>
                        <select v-model="form.metodo_prorrateo" :class="ic">
                            <option v-for="(n, c) in metodos" :key="c" :value="c">{{ n }}</option>
                        </select>
                        <p class="text-xs text-tinta-300 mt-1">
                            {{ form.metodo_prorrateo === 'peso' ? 'Cada producto carga según su peso: hay que escribirlo en cada línea.'
                              : form.metodo_prorrateo === 'cantidad' ? 'Cada unidad carga lo mismo, valga lo que valga.'
                              : 'Lo más caro carga más del flete y del seguro.' }}
                        </p>
                    </div>
                    <label class="flex items-start gap-2 sm:pt-6 cursor-pointer">
                        <input v-model="form.iva_descontable" type="checkbox" class="rounded mt-0.5"/>
                        <span class="text-sm text-tinta-700">
                            El {{ impuesto.nombre }} de importación se recupera
                            <span class="block text-xs text-tinta-400">Si se recupera no es un costo; si no, se suma al costo del producto. {{ impuesto.nota }}</span>
                        </span>
                    </label>
                </div>
            </section>

            <!-- Productos -->
            <section class="bg-superficie rounded-xl border border-linea p-4 space-y-3">
                <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Lo que viene en el embarque</p>

                <div class="relative">
                    <input v-model="buscar" type="text" placeholder="Buscar un producto para agregarlo…" :class="ic" autocomplete="off"/>
                    <div v-if="resultados.length" class="absolute z-10 left-0 right-0 mt-1 bg-superficie border border-linea rounded-lg shadow-lg max-h-56 overflow-y-auto">
                        <button v-for="p in resultados" :key="p.id" type="button" @click="agregarProducto(p)"
                            class="w-full text-left px-3 py-2 text-sm hover:bg-realce">
                            <span class="text-tinta-900">{{ p.nombre }}</span>
                            <span v-if="p.referencia" class="text-xs text-tinta-400 ml-2">{{ p.referencia }}</span>
                        </button>
                    </div>
                </div>
                <button type="button" @click="form.items.push(lineaVacia())" class="text-xs font-semibold text-[var(--marca)] hover:underline">
                    + Agregar una línea que no está en el catálogo
                </button>
                <p v-if="form.errors.items" class="text-aviso-rojo text-xs">{{ form.errors.items }}</p>

                <div v-for="(it, i) in form.items" :key="i" class="rounded-xl border border-linea p-3 space-y-2">
                    <div class="flex items-start gap-2">
                        <div class="flex-1 min-w-0">
                            <label :class="etiqueta">Descripción</label>
                            <input v-model="it.descripcion" type="text" maxlength="255" :class="ic" :readonly="!! it.producto_id"/>
                            <p v-if="form.errors[`items.${i}.descripcion`]" class="text-aviso-rojo text-xs mt-1">{{ form.errors[`items.${i}.descripcion`] }}</p>
                        </div>
                        <button type="button" @click="quitarItem(i)" class="mt-6 p-2 text-tinta-300 hover:text-aviso-rojo" aria-label="Quitar">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                        <div>
                            <label :class="etiqueta">Cantidad</label>
                            <input v-model.number="it.cantidad" type="number" step="0.001" min="0.001" :class="ic"/>
                        </div>
                        <div>
                            <label :class="etiqueta">Valor unitario ({{ form.moneda }})</label>
                            <input v-model.number="it.valor_unitario" type="number" step="0.0001" min="0" :class="ic"/>
                        </div>
                        <div>
                            <label :class="etiqueta">Peso de la línea (kg)</label>
                            <input v-model.number="it.peso_kg" type="number" step="0.001" min="0" :class="ic"/>
                        </div>
                        <div>
                            <label :class="etiqueta">Código arancelario</label>
                            <input v-model="it.codigo_hs" type="text" maxlength="12" inputmode="numeric" :class="ic"/>
                        </div>
                        <div>
                            <label :class="etiqueta">Arancel (%)</label>
                            <input v-model.number="it.arancel_pct" type="number" step="0.01" min="0" max="100" :class="ic"/>
                        </div>
                        <div>
                            <label :class="etiqueta">{{ impuesto.nombre }} (%)</label>
                            <input v-model.number="it.iva_pct" type="number" step="0.01" min="0" max="100" :class="ic"/>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Costos del embarque -->
            <section class="bg-superficie rounded-xl border border-linea p-4 space-y-3">
                <div>
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Lo que costó traerlo</p>
                    <p class="text-xs text-tinta-400 mt-1">
                        Son del embarque completo, no de un producto: Briela los reparte. El flete y el seguro entran al valor CIF,
                        que es la base del arancel; los gastos de nacionalización se suman después.
                    </p>
                </div>

                <div v-for="(c, i) in form.costos" :key="i" class="rounded-xl border border-linea p-3 grid grid-cols-2 sm:grid-cols-12 gap-2 items-end">
                    <div class="sm:col-span-3">
                        <label :class="etiqueta">Tipo</label>
                        <select v-model="c.tipo" :class="ic">
                            <option v-for="(n, t) in tipos_costo" :key="t" :value="t">{{ n }}</option>
                        </select>
                    </div>
                    <div class="col-span-2 sm:col-span-4">
                        <label :class="etiqueta">Concepto</label>
                        <input v-model="c.concepto" type="text" maxlength="120" :class="ic"/>
                    </div>
                    <div class="sm:col-span-3">
                        <label :class="etiqueta">Valor ({{ c.en_moneda_base ? base : form.moneda }})</label>
                        <input v-model.number="c.valor" type="number" step="0.01" min="0" :class="ic"/>
                    </div>
                    <div class="sm:col-span-2 flex items-center justify-between gap-2">
                        <label class="flex items-center gap-1.5 text-xs text-tinta-700 cursor-pointer" v-if="! enBase">
                            <input v-model="c.en_moneda_base" type="checkbox" class="rounded"/> en {{ base }}
                        </label>
                        <button type="button" @click="quitarCosto(i)" class="p-2 text-tinta-300 hover:text-aviso-rojo ml-auto" aria-label="Quitar">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button v-for="(n, t) in tipos_costo" :key="t" type="button" @click="agregarCosto(t)"
                        class="text-xs font-semibold px-3 py-1.5 rounded-lg border border-linea text-tinta-700 hover:bg-realce">+ {{ n }}</button>
                </div>
            </section>

            <!-- El resultado -->
            <section v-if="calculo" class="bg-superficie rounded-xl border border-linea p-4 space-y-3">
                <div class="flex items-center justify-between gap-2">
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Cómo queda, en {{ base }}</p>
                    <span v-if="calculando" class="text-[11px] text-tinta-300">Calculando…</span>
                </div>

                <p v-for="(a, i) in calculo.avisos" :key="i" class="text-xs rounded-lg px-3 py-2 border bg-pastel-ambar border-borde-aviso-ambar text-aviso-ambar">{{ a }}</p>

                <div v-for="(f, i) in calculo.items" :key="i" class="rounded-xl border border-linea p-3">
                    <p class="text-sm font-medium text-tinta-900 truncate">{{ form.items[i]?.descripcion || `Línea ${i + 1}` }}</p>
                    <dl class="grid grid-cols-2 sm:grid-cols-4 gap-x-3 gap-y-1 mt-2 text-xs">
                        <div><dt class="text-tinta-400">Factura (FOB)</dt><dd class="text-tinta-900">{{ formatCOP(f.fob) }}</dd></div>
                        <div><dt class="text-tinta-400">Flete y seguro</dt><dd class="text-tinta-900">{{ formatCOP(f.flete_seguro) }}</dd></div>
                        <div><dt class="text-tinta-400">Valor CIF</dt><dd class="text-tinta-900">{{ formatCOP(f.cif) }}</dd></div>
                        <div><dt class="text-tinta-400">Arancel</dt><dd class="text-tinta-900">{{ formatCOP(f.arancel) }}</dd></div>
                        <div><dt class="text-tinta-400">{{ impuesto.nombre }}{{ form.iva_descontable ? ' (se recupera)' : '' }}</dt><dd class="text-tinta-900">{{ formatCOP(f.iva) }}</dd></div>
                        <div><dt class="text-tinta-400">Gastos</dt><dd class="text-tinta-900">{{ formatCOP(f.gastos) }}</dd></div>
                        <div><dt class="text-tinta-400">Costo total</dt><dd class="font-semibold text-tinta-900">{{ formatCOP(f.costo_total) }}</dd></div>
                        <div><dt class="text-tinta-400">Costo por unidad</dt><dd class="font-semibold" style="color:var(--marca)">{{ f.costo_unitario !== null ? formatCOP(f.costo_unitario) : '—' }}</dd></div>
                    </dl>
                </div>

                <div class="flex flex-wrap justify-between gap-2 text-sm pt-1">
                    <span class="text-tinta-700">Costo total del embarque: <span class="font-semibold text-tinta-900">{{ formatCOP(calculo.totales.costo_total) }}</span></span>
                    <span v-if="calculo.factor" class="text-tinta-400">
                        Traerlo costó {{ formatPct(Math.round((calculo.factor - 1) * 10000) / 100) }}% más de lo que cobró el proveedor
                    </span>
                </div>
            </section>

            <section class="bg-superficie rounded-xl border border-linea p-4">
                <label :class="etiqueta">Notas</label>
                <textarea v-model="form.notas" rows="2" maxlength="2000" :class="ic"/>
            </section>

            <!-- Errores de validación -->
            <div v-if="Object.keys(form.errors).length" class="bg-pastel-rojo border border-borde-aviso-rojo rounded-xl p-4">
                <p class="text-sm font-semibold text-aviso-rojo mb-2">Corrige los siguientes errores:</p>
                <ul class="list-disc list-inside space-y-1">
                    <li v-for="(msg, campo) in form.errors" :key="campo" class="text-xs text-aviso-rojo">{{ msg }}</li>
                </ul>
            </div>

            <div class="flex justify-end gap-2">
                <button type="submit" :disabled="form.processing || ! form.items.length"
                    class="px-5 py-2 rounded-lg text-sm font-medium text-white disabled:opacity-60" style="background:var(--marca)">
                    {{ form.processing ? 'Guardando…' : 'Guardar borrador' }}
                </button>
            </div>
        </form>
    </AppLayout>
</template>
