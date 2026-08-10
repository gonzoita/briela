<script setup>
import { ref, reactive, computed, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    op:       Object,
    clientes: Array,
})

// ── Estado del formulario ──────────────────────────────────────────────────
const paso = ref(1)

const form = reactive({
    tipo:           props.op ? 'op' : 'op',
    op_id:          props.op?.id ?? null,
    cliente_id:     null,
    fecha_remision: new Date().toISOString().slice(0, 10),
    notas:          '',
    items:          [],
})

const errors     = ref({})
const guardando  = ref(false)

// ── Datos de OP cargada ────────────────────────────────────────────────────
const opCargada      = ref(props.op ?? null)
const busquedaOp     = ref(props.op?.numero ?? '')
const buscandoOp     = ref(false)
const resultadosOp   = ref([])
const clienteNombre  = ref(props.op?.cliente ? (props.op.cliente.nombre + (props.op.cliente.apellido ? ' ' + props.op.cliente.apellido : '')) : '')

// Ítems seleccionados y unidades a remisionar
const itemsSeleccionados    = ref({})
const cantidadesARemisionar = ref({})

function disponibleItem(item) {
    return item.unidades_disponibles ?? 0
}

function initCantidad(item) {
    if (itemsSeleccionados.value[item.id] && !cantidadesARemisionar.value[item.id]) {
        cantidadesARemisionar.value[item.id] = disponibleItem(item)
    }
}

// Inicializar si ya viene una OP
if (props.op) {
    form.cliente_id = props.op.cliente?.id ?? null
    props.op.items?.forEach(item => {
        itemsSeleccionados.value[item.id] = true
        cantidadesARemisionar.value[item.id] = disponibleItem(item)
    })
}

// ── Búsqueda de OP ────────────────────────────────────────────────────────
let debounceOp = null
async function buscarOp() {
    clearTimeout(debounceOp)
    if (!busquedaOp.value || busquedaOp.value.length < 2) { resultadosOp.value = []; return }
    debounceOp = setTimeout(async () => {
        buscandoOp.value = true
        try {
            const res  = await fetch(`/logistica/api/ops/buscar?q=${encodeURIComponent(busquedaOp.value)}`, {
                headers: { Accept: 'application/json' }, credentials: 'same-origin',
            })
            resultadosOp.value = await res.json()
        } catch { resultadosOp.value = [] } finally { buscandoOp.value = false }
    }, 300)
}

async function seleccionarOp(op) {
    buscandoOp.value = true
    resultadosOp.value = []
    try {
        const res   = await fetch(`/logistica/api/ops/${op.id}/items`, {
            headers: { Accept: 'application/json' }, credentials: 'same-origin',
        })
        const data  = await res.json()
        opCargada.value     = data
        form.op_id          = op.id
        busquedaOp.value    = op.numero
        clienteNombre.value = data.cliente?.nombre ?? '—'
        form.cliente_id     = data.cliente?.id ?? null
        itemsSeleccionados.value    = {}
        cantidadesARemisionar.value = {}
        data.items.forEach(i => {
            itemsSeleccionados.value[i.id]    = true
            cantidadesARemisionar.value[i.id] = disponibleItem(i)
        })
    } catch { } finally { buscandoOp.value = false }
}

// ── Items manuales ────────────────────────────────────────────────────────
const itemsManual = ref([{ descripcion: '', cantidad: 1, unidad: '', notas: '' }])

function agregarItemManual() {
    itemsManual.value.push({ descripcion: '', cantidad: 1, unidad: '', notas: '' })
}
function quitarItemManual(idx) {
    itemsManual.value.splice(idx, 1)
}

// ── Validación y navegación pasos ──────────────────────────────────────────
function irPaso2() {
    errors.value = {}

    if (form.tipo === 'op') {
        if (!opCargada.value) { errors.value.op_id = 'Selecciona una OP'; return }
        const sel = opCargada.value.items?.filter(i => itemsSeleccionados.value[i.id]) ?? []
        if (!sel.length) { errors.value.items = 'Selecciona al menos un ítem'; return }
        for (const item of sel) {
            const qty = parseInt(cantidadesARemisionar.value[item.id] ?? 0, 10)
            const max = disponibleItem(item)
            if (!qty || qty <= 0) {
                errors.value.items = `Las unidades para "${item.descripcion}" deben ser mayor a 0`; return
            }
            if (qty > max) {
                errors.value.items = `Las unidades para "${item.descripcion}" superan las disponibles (${max})`; return
            }
        }
    } else {
        const validos = itemsManual.value.filter(i => i.descripcion.trim() && i.cantidad > 0)
        if (!validos.length) { errors.value.items = 'Agrega al menos un ítem válido'; return }
    }

    paso.value = 2
}

// ── Envío ─────────────────────────────────────────────────────────────────
function enviar() {
    errors.value = {}
    guardando.value = true

    let items = []
    if (form.tipo === 'op') {
        items = opCargada.value.items
            .filter(i => itemsSeleccionados.value[i.id])
            .map(i => ({
                op_item_id:        i.id,
                descripcion:       i.descripcion,
                cantidad:          parseInt(cantidadesARemisionar.value[i.id] ?? disponibleItem(i), 10),
                cantidad_unidades: parseInt(cantidadesARemisionar.value[i.id] ?? disponibleItem(i), 10),
                unidad:            'und',
                numero_serie:      i.numero_serie,
                notas:             '',
            }))
    } else {
        items = itemsManual.value
            .filter(i => i.descripcion.trim() && i.cantidad > 0)
            .map(i => ({
                descripcion: i.descripcion.trim(),
                cantidad:    parseFloat(i.cantidad),
                unidad:      i.unidad,
                notas:       i.notas,
            }))
    }

    router.post('/logistica/remisiones', {
        tipo:           form.tipo,
        op_id:          form.tipo === 'op' ? form.op_id : null,
        cliente_id:     form.cliente_id,
        fecha_remision: form.fecha_remision,
        notas:          form.notas,
        items,
    }, {
        onError: (e) => { errors.value = e; guardando.value = false; paso.value = 1 },
        onSuccess: () => { guardando.value = false },
    })
}

// ── Cambio de tipo ─────────────────────────────────────────────────────────
watch(() => form.tipo, () => {
    opCargada.value             = null
    busquedaOp.value            = ''
    itemsSeleccionados.value    = {}
    cantidadesARemisionar.value = {}
    errors.value                = {}
})
</script>

<template>
    <AppLayout title="Nueva Remisión">
        <div class="max-w-2xl mx-auto">

            <!-- Cabecera -->
            <div class="flex items-center gap-3 mb-6">
                <a href="/logistica/remisiones" class="text-tinta-300 hover:text-tinta-700">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M15 19l-7-7 7-7"/>
                    </svg>
                </a>
                <h1 class="text-xl font-semibold text-tinta-900">Nueva Remisión</h1>
            </div>

            <!-- Indicador de pasos -->
            <div class="flex items-center gap-3 mb-6">
                <div v-for="n in [1,2]" :key="n" class="flex items-center gap-2">
                    <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-semibold transition-colors"
                        :style="paso >= n ? 'background:var(--marca);color:white;' : 'background:var(--tinta-200);color:#6B7280;'">
                        {{ n }}
                    </div>
                    <span class="text-xs text-tinta-400">{{ n === 1 ? 'Origen e ítems' : 'Datos de remisión' }}</span>
                    <div v-if="n < 2" class="w-8 h-px bg-tinta-200"></div>
                </div>
            </div>

            <!-- ═══ PASO 1 — Origen ═══ -->
            <div v-if="paso === 1" class="bg-superficie rounded-2xl border border-linea p-5 space-y-5">

                <!-- Toggle tipo -->
                <div>
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-2">Tipo de remisión</p>
                    <div class="flex rounded-xl border border-linea overflow-hidden">
                        <button @click="form.tipo = 'op'"
                            class="flex-1 py-2.5 text-sm font-medium transition-colors"
                            :style="form.tipo === 'op' ? 'background:var(--marca);color:white;' : 'background:white;color:#374151;'">
                            Desde OP
                        </button>
                        <button @click="form.tipo = 'manual'"
                            class="flex-1 py-2.5 text-sm font-medium transition-colors"
                            :style="form.tipo === 'manual' ? 'background:var(--marca);color:white;' : 'background:white;color:#374151;'">
                            Manual
                        </button>
                    </div>
                </div>

                <!-- ── Desde OP ── -->
                <template v-if="form.tipo === 'op'">
                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">
                            Buscar OP
                        </label>
                        <div class="relative">
                            <input v-model="busquedaOp" @input="buscarOp" type="text"
                                placeholder="Escribe el número de OP o nombre del cliente…"
                                class="w-full border border-linea rounded-xl px-4 py-2.5 text-sm focus:outline-none focus:ring-2"
                                style="--tw-ring-color:var(--marca);"/>
                            <div v-if="buscandoOp" class="absolute right-3 top-2.5">
                                <svg class="w-4 h-4 animate-spin text-tinta-300" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                                </svg>
                            </div>
                            <!-- Dropdown resultados -->
                            <div v-if="resultadosOp.length"
                                class="absolute z-10 w-full mt-1 bg-superficie border border-linea rounded-xl shadow-lg overflow-hidden">
                                <button v-for="op in resultadosOp" :key="op.id"
                                    @click="seleccionarOp(op)"
                                    class="w-full text-left px-4 py-2.5 hover:bg-tinta-50 transition-colors border-b border-gray-50 last:border-0">
                                    <span class="font-mono text-xs font-semibold text-tinta-900">{{ op.numero }}</span>
                                    <span class="text-xs text-tinta-400 ml-2">{{ op.cliente_nombre }}</span>
                                    <span class="text-xs text-blue-500 ml-2">{{ op.items_count }} ítems disponibles</span>
                                </button>
                            </div>
                        </div>
                        <p v-if="errors.op_id" class="text-xs text-red-500 mt-1">{{ errors.op_id }}</p>
                    </div>

                    <!-- Datos de la OP cargada -->
                    <div v-if="opCargada">
                        <div class="bg-blue-50 rounded-xl p-3 mb-3">
                            <p class="text-xs font-semibold text-blue-700">
                                {{ opCargada.op?.numero ?? opCargada.numero }}
                                <span class="font-normal text-blue-500 ml-2">{{ clienteNombre }}</span>
                            </p>
                        </div>

                        <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-2">
                            Ítems disponibles — selecciona los que incluir
                        </p>
                        <div class="flex items-center gap-2 bg-amber-50 border border-amber-200 rounded-xl px-3 py-2 mb-3">
                            <svg class="w-4 h-4 text-amber-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <p class="text-xs text-amber-700">Solo se remisionan <strong>unidades con avance 100%</strong> que no hayan sido remisionadas antes.</p>
                        </div>

                        <div v-if="!opCargada.items?.length" class="text-sm text-tinta-300 py-4 text-center">
                            No hay unidades completadas disponibles para remisionar.
                        </div>

                        <div v-else class="space-y-2">
                            <!-- Seleccionar todos -->
                            <label class="flex items-center gap-2 text-xs text-tinta-400 cursor-pointer mb-3">
                                <input type="checkbox"
                                    :checked="opCargada.items.every(i => itemsSeleccionados[i.id])"
                                    @change="e => opCargada.items.forEach(i => {
                                        itemsSeleccionados[i.id] = e.target.checked
                                        if (e.target.checked) cantidadesARemisionar[i.id] = disponibleItem(i)
                                    })"
                                    class="rounded"/>
                                Seleccionar todos
                            </label>
                            <label v-for="item in opCargada.items" :key="item.id"
                                class="flex items-start gap-3 p-3 rounded-xl border border-linea cursor-pointer hover:bg-tinta-50 transition-colors"
                                :class="itemsSeleccionados[item.id] ? 'border-blue-200 bg-blue-50' : ''">
                                <input type="checkbox" v-model="itemsSeleccionados[item.id]"
                                    @change="initCantidad(item)"
                                    class="mt-0.5 rounded" style="accent-color:var(--marca);"/>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-tinta-900">{{ item.descripcion }}</p>
                                    <div class="flex flex-wrap gap-3 mt-1 text-xs text-tinta-400">
                                        <span>Total: <strong class="text-tinta-700">{{ item.total }}</strong></span>
                                        <span class="text-green-600">Completadas: <strong>{{ item.unidades_completadas }}</strong></span>
                                        <span v-if="item.unidades_remisionadas > 0" class="text-amber-600">
                                            Ya remisionadas: <strong>{{ item.unidades_remisionadas }}</strong>
                                        </span>
                                        <span class="font-semibold" :class="item.unidades_disponibles > 0 ? 'text-blue-600' : 'text-tinta-300'">
                                            Disponibles: {{ item.unidades_disponibles }}
                                        </span>
                                        <span v-if="item.numero_serie" class="font-mono text-tinta-300">{{ item.numero_serie }}</span>
                                    </div>
                                    <!-- Input unidades si está seleccionado -->
                                    <div v-if="itemsSeleccionados[item.id]" class="mt-2" @click.stop @mousedown.stop>
                                        <label class="text-xs text-tinta-400">Unidades a remisionar (máx. {{ item.unidades_disponibles }}):</label>
                                        <input type="number" step="1" min="1" :max="item.unidades_disponibles"
                                            v-model.number="cantidadesARemisionar[item.id]"
                                            class="mt-1 w-full border border-blue-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none"
                                            style="background:white;"/>
                                    </div>
                                </div>
                            </label>
                        </div>
                        <p v-if="errors.items" class="text-xs text-red-500 mt-2">{{ errors.items }}</p>
                    </div>
                </template>

                <!-- ── Manual ── -->
                <template v-else>
                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">
                            Cliente
                        </label>
                        <select v-model="form.cliente_id"
                            class="w-full border border-linea rounded-xl px-4 py-2.5 text-sm focus:outline-none">
                            <option :value="null">Sin cliente</option>
                            <option v-for="c in clientes" :key="c.id" :value="c.id">{{ c.nombre }}</option>
                        </select>
                    </div>

                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Ítems</p>
                            <button @click="agregarItemManual" type="button"
                                class="text-xs font-medium text-blue-600 hover:underline">+ Agregar ítem</button>
                        </div>

                        <div v-for="(item, idx) in itemsManual" :key="idx"
                            class="mb-3 p-3 rounded-xl border border-linea bg-tinta-50">
                            <div class="grid grid-cols-1 gap-2">
                                <input v-model="item.descripcion" type="text" placeholder="Descripción del producto"
                                    class="border border-linea rounded-lg px-3 py-2 text-sm w-full focus:outline-none"/>
                                <div class="flex gap-2">
                                    <input v-model.number="item.cantidad" type="number" step="0.001" min="0.001"
                                        placeholder="Cantidad"
                                        class="border border-linea rounded-lg px-3 py-2 text-sm w-28 focus:outline-none"/>
                                    <input v-model="item.unidad" type="text" placeholder="Unidad (und, m, kg…)"
                                        class="border border-linea rounded-lg px-3 py-2 text-sm flex-1 focus:outline-none"/>
                                </div>
                                <div class="flex gap-2 items-center">
                                    <input v-model="item.notas" type="text" placeholder="Notas (opcional)"
                                        class="border border-linea rounded-lg px-3 py-2 text-sm flex-1 focus:outline-none"/>
                                    <button v-if="itemsManual.length > 1" @click="quitarItemManual(idx)" type="button"
                                        class="text-red-400 hover:text-red-600">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <p v-if="errors.items" class="text-xs text-red-500">{{ errors.items }}</p>
                    </div>
                </template>

                <button @click="irPaso2" type="button"
                    class="w-full py-3 rounded-xl text-sm font-semibold text-white transition-opacity"
                    style="background:var(--marca);">
                    Continuar →
                </button>
            </div>

            <!-- ═══ PASO 2 — Datos de remisión ═══ -->
            <div v-if="paso === 2" class="bg-superficie rounded-2xl border border-linea p-5 space-y-4">
                <button @click="paso = 1" type="button" class="text-xs text-blue-600 hover:underline">← Volver</button>

                <div>
                    <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">
                        Fecha de remisión
                    </label>
                    <input v-model="form.fecha_remision" type="date"
                        class="border border-linea rounded-xl px-4 py-2.5 text-sm w-full focus:outline-none"/>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">
                        Notas internas
                    </label>
                    <textarea v-model="form.notas" rows="3"
                        placeholder="Instrucciones especiales, referencias adicionales…"
                        class="border border-linea rounded-xl px-4 py-2.5 text-sm w-full focus:outline-none resize-none"/>
                </div>

                <!-- Resumen de ítems -->
                <div class="bg-tinta-50 rounded-xl p-3">
                    <p class="text-xs font-semibold text-tinta-400 mb-2">Resumen de ítems</p>
                    <div v-if="form.tipo === 'op' && opCargada">
                        <div v-for="item in opCargada.items?.filter(i => itemsSeleccionados[i.id])" :key="item.id"
                            class="flex justify-between text-xs text-tinta-500 py-0.5">
                            <span>{{ item.descripcion }}</span>
                            <span class="font-medium">{{ cantidadesARemisionar[item.id] ?? disponibleItem(item) }} unid.</span>
                        </div>
                    </div>
                    <div v-else>
                        <div v-for="item in itemsManual.filter(i => i.descripcion.trim())" :key="item.descripcion"
                            class="flex justify-between text-xs text-tinta-500 py-0.5">
                            <span>{{ item.descripcion }}</span>
                            <span class="font-medium">x{{ item.cantidad }} {{ item.unidad }}</span>
                        </div>
                    </div>
                </div>

                <button @click="enviar" type="button" :disabled="guardando"
                    class="w-full py-3 rounded-xl text-sm font-semibold text-white transition-opacity"
                    :style="guardando ? 'background:var(--marca);opacity:0.6;' : 'background:var(--marca);'">
                    {{ guardando ? 'Creando…' : 'Crear Remisión' }}
                </button>
            </div>

        </div>
    </AppLayout>
</template>
