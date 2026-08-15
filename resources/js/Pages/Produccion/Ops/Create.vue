<script setup>
import { ref, reactive, computed, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ResultadosBuscadorProducto from '@/Components/ResultadosBuscadorProducto.vue'
import { useUnsavedChanges } from '@/composables/useUnsavedChanges'

const props = defineProps({
    op:             { type: Object, default: null },
    responsables:   Array,
    operarios:      { type: Array, default: () => [] },
    usuario_actual: Number,
})

const esEdicion = computed(() => !!props.op)
const { hasChanges, setOriginal, checkChanges, markClean } = useUnsavedChanges()

const hoy = new Date().toISOString().slice(0, 10)

const form = reactive({
    cliente_id:             props.op?.cliente_id              ?? null,
    responsable_id:         props.op?.responsable_id          ?? props.usuario_actual ?? '',
    estado:                 props.op?.estado                  ?? 'borrador',
    fecha_creacion:         props.op?.fecha_creacion          ?? hoy,
    fecha_entrega_estimada: props.op?.fecha_entrega_estimada  ?? '',
    anticipo:               props.op?.anticipo                ?? '',
    condiciones:            props.op?.condiciones             ?? '',
    notas_internas:         props.op?.notas_internas          ?? '',
})

const items = ref(
    (props.op?.items ?? []).map(i => ({
        _key:              Math.random(),
        _expandido:        false,
        id:                i.id,
        tipo:              i.tipo,
        descripcion:       i.descripcion,
        descripcion_larga: i.descripcion_larga ?? '',
        cantidad:          parseFloat(i.cantidad),
        variables_instancia:   i.variables_instancia   ?? null,
        componentes_snapshot:  i.componentes_snapshot  ?? null,
        componentes_bd:        i.componentes_bd        ?? [],
        ensamble_id:       i.ensamble_id  ?? null,
        producto_id:       i.producto_id  ?? null,
        numero_serie:      i.numero_serie ?? '',
        operario_id:       i.operario_id  ?? null,
        estado_item:       i.estado_item  ?? 'pendiente',
        notas_item:        i.notas_item   ?? '',
    }))
)

setOriginal({ form: { ...form }, items: items.value })
watch([form, items], () => checkChanges({ form: { ...form }, items: items.value }), { deep: true })

const errores    = ref({})
const procesando = ref(false)

// ── Cliente ───────────────────────────────────────────────────────────────────
const clienteBuscar      = ref(props.op?.cliente
    ? ((props.op.cliente.nombre ?? '') + ' ' + (props.op.cliente.apellido ?? '')).trim() : '')
const clienteResultados  = ref([])
const clienteAbierto     = ref(false)
let clienteTimer = null

async function buscarClientes() {
    if (clienteBuscar.value.length < 2) { clienteResultados.value = []; return }
    const r = await fetch(`/api/cotizaciones/clientes?q=${encodeURIComponent(clienteBuscar.value)}`)
    clienteResultados.value = await r.json()
    clienteAbierto.value = true
}
function debounceCliente() { clearTimeout(clienteTimer); clienteTimer = setTimeout(buscarClientes, 300) }
function seleccionarCliente(c) {
    form.cliente_id = c.id
    clienteBuscar.value = (c.nombre + ' ' + (c.apellido ?? '')).trim()
    clienteAbierto.value = false; clienteResultados.value = []
}
function limpiarCliente() { form.cliente_id = null; clienteBuscar.value = '' }

// ── Modal ─────────────────────────────────────────────────────────────────────
const modalPanel = ref(null) // null | 'opciones' | 'producto' | 'ensamble' | 'ensamble_instancia'

function abrirModal() { modalPanel.value = 'opciones' }
function cerrarModal() {
    modalPanel.value = null
    productoQuery.value = ''; productoResultados.value = []
    ensambleQuery.value = ''; ensambleResultados.value = []; ensambleExpandido.value = null
    ensambleInstancia.value = null; camposInstancia.value = []; valoresInstancia.value = {}
}

// ── Búsqueda productos/servicios ──────────────────────────────────────────────
const productoQuery      = ref('')
const productoResultados = ref([])
let timerProd = null

function buscarProducto(q) {
    productoQuery.value = q
    clearTimeout(timerProd)
    if (q.length < 2) { productoResultados.value = []; return }
    timerProd = setTimeout(async () => {
        const r = await fetch(`/api/cotizaciones/productos?q=${encodeURIComponent(q)}`)
        productoResultados.value = await r.json()
    }, 300)
}

function agregarDesdeProducto(prod) {
    items.value.push({
        _key:              Math.random(),
        _expandido:        false,
        tipo:              prod.tipo ?? 'producto',
        producto_id:       prod.id,
        ensamble_id:       null,
        descripcion:       prod.nombre + (prod.referencia ? ` (${prod.referencia})` : ''),
        descripcion_larga: prod.descripcion_larga ?? '',
        cantidad:          1,
        variables_instancia:  null,
        componentes_snapshot: null,
        numero_serie:      '',
        operario_id:       null,
        estado_item:       'pendiente',
        notas_item:        '',
    })
    cerrarModal()
}

// ── Búsqueda ensambles ────────────────────────────────────────────────────────
const ensambleQuery      = ref('')
const ensambleResultados = ref([])
const ensambleExpandido  = ref(null)
let timerEns = null

// Variables instancia
const ensambleInstancia   = ref(null)
const camposInstancia     = ref([])
const valoresInstancia    = ref({})
const confirmandoInstancia = ref(false)

function buscarEnsamble(q) {
    ensambleQuery.value = q
    clearTimeout(timerEns)
    if (q.length < 2) { ensambleResultados.value = []; return }
    timerEns = setTimeout(async () => {
        const r = await fetch(`/api/ensambles/buscar?q=${encodeURIComponent(q)}`)
        ensambleResultados.value = await r.json()
    }, 300)
}

function expandirEnsamble(e) {
    ensambleExpandido.value = ensambleExpandido.value?.id === e.id ? null : e
}

async function seleccionarEnsamble(e) {
    try {
        const r = await fetch(`/api/ensambles/${e.id}/variables-instancia`)
        const campos = await r.json()
        if (campos.length > 0) {
            ensambleInstancia.value = e
            camposInstancia.value = campos.map(c => ({
                ...c,
                opciones_selector: typeof c.opciones_selector === 'string'
                    ? JSON.parse(c.opciones_selector)
                    : (c.opciones_selector ?? []),
            }))
            const vals = {}
            for (const c of camposInstancia.value) {
                if (c.subtipo_variable === 'numero' || c.subtipo_variable === 'decimal') {
                    vals[c.nombre] = c.valor_defecto ? parseFloat(c.valor_defecto) : 0
                } else if (c.subtipo_variable === 'selector') {
                    vals[c.nombre] = c.valor_defecto ?? c.opciones_selector?.[0]?.valor ?? ''
                } else {
                    vals[c.nombre] = c.valor_defecto ?? ''
                }
            }
            valoresInstancia.value = vals
            modalPanel.value = 'ensamble_instancia'
        } else {
            expandirEnsamble(e)
        }
    } catch { expandirEnsamble(e) }
}

function buildDescripcionLarga(base, valores, campos) {
    if (!Object.keys(valores).length) return base ?? ''
    const lineas = campos.map(c => {
        const val = valores[c.nombre]
        if (val === undefined || val === '' || val === null) return null
        const etiq = c.etiqueta || c.nombre
        if (c.subtipo_variable === 'selector') {
            const op = (c.opciones_selector ?? []).find(o => o.valor === val)
            return `${etiq}: ${op ? op.etiqueta : val}`
        }
        return `${etiq}: ${val}`
    }).filter(Boolean)
    if (!lineas.length) return base ?? ''
    return base ? `${base}\n${lineas.join(' | ')}` : lineas.join(' | ')
}

function confirmarInstancia() {
    const e = ensambleInstancia.value
    items.value.push({
        _key:              Math.random(),
        _expandido:        false,
        tipo:              'ensamble',
        producto_id:       null,
        ensamble_id:       e.id,
        descripcion:       e.nombre,
        descripcion_larga: buildDescripcionLarga(e.descripcion_larga, valoresInstancia.value, camposInstancia.value),
        cantidad:          1,
        variables_instancia:  { ...valoresInstancia.value },
        componentes_snapshot: e.componentes_resultado ?? null,
        numero_serie:      '',
        operario_id:       null,
        estado_item:       'pendiente',
        notas_item:        '',
    })
    cerrarModal()
}

function agregarDesdeEnsamble(e) {
    items.value.push({
        _key:              Math.random(),
        _expandido:        false,
        tipo:              'ensamble',
        producto_id:       null,
        ensamble_id:       e.id,
        descripcion:       e.nombre,
        descripcion_larga: e.descripcion_larga ?? '',
        cantidad:          1,
        variables_instancia:  null,
        componentes_snapshot: e.componentes_resultado ?? null,
        numero_serie:      '',
        operario_id:       null,
        estado_item:       'pendiente',
        notas_item:        '',
    })
    cerrarModal()
}

// ── Reordenar ─────────────────────────────────────────────────────────────────

function eliminarItem(idx) { items.value.splice(idx, 1) }
function moverItem(idx, dir) {
    const ni = idx + dir
    if (ni < 0 || ni >= items.value.length) return
    const arr = [...items.value];
    [arr[idx], arr[ni]] = [arr[ni], arr[idx]]
    items.value.splice(0, arr.length, ...arr)
}

const draggingIdx = ref(null)
const dragOverIdx = ref(null)
function onDragStart(idx) { draggingIdx.value = idx }
function onDragOver(e, idx) { e.preventDefault(); dragOverIdx.value = idx }
function onDrop(idx) {
    if (draggingIdx.value === null || draggingIdx.value === idx) {
        draggingIdx.value = null; dragOverIdx.value = null; return
    }
    const arr = [...items.value]
    const [moved] = arr.splice(draggingIdx.value, 1)
    arr.splice(idx, 0, moved)
    items.value.splice(0, arr.length, ...arr)
    draggingIdx.value = null; dragOverIdx.value = null
}
function onDragEnd() { draggingIdx.value = null; dragOverIdx.value = null }

// ── Panel componentes requeridos ──────────────────────────────────────────────
const panelComponentes = computed(() => {
    if (esEdicion.value) {
        return items.value
            .filter(i => i.tipo === 'ensamble' && i.componentes_bd?.length)
            .map(item => ({
                nombre:        item.descripcion,
                cantidad_item: parseFloat(item.cantidad || 1),
                item_id:       item.id,
                componentes:   item.componentes_bd ?? [],
            }))
    }
    return items.value
        .filter(i => i.tipo === 'ensamble' && i.componentes_snapshot?.length)
        .map(item => ({
            nombre:        item.descripcion,
            cantidad_item: parseFloat(item.cantidad || 1),
            item_id:       null,
            componentes:   (item.componentes_snapshot ?? []).map(c => ({
                id:                  null,
                nombre:              c.nombre,
                cantidad:            parseFloat(c.cantidad ?? 0) * parseFloat(item.cantidad || 1),
                unidad:              c.unidad ?? '',
                referencia:          null,
                observacion:         null,
                parent_componente_id: c.parent_componente_id ?? null,
            })),
        }))
})

const csrf = () => {
    const c = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))
    return c ? decodeURIComponent(c.split('=')[1]) : ''
}

const guardarCompEdicion = async (itemId, comp, campo, valor) => {
    if (!comp.id || !itemId) return
    try {
        await fetch(`/produccion/ops/${props.op.id}/items/${itemId}/componentes/${comp.id}`, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': csrf(),
                'Accept': 'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ [campo]: valor }),
        })
    } catch (e) { console.error('Error guardando componente:', e) }
}

function componentesConJerarquia(comps) {
    const padres = comps.filter(c => !c.parent_componente_id)
    const result = []
    for (const padre of padres) {
        result.push(padre)
        const hijos = comps.filter(c => c.parent_componente_id === padre.id)
        result.push(...hijos)
    }
    return result
}

// ── Estados ───────────────────────────────────────────────────────────────────
const ESTADOS_OP = [
    { value: 'borrador',      label: 'Borrador'      },
    { value: 'confirmada',    label: 'Confirmada'    },
    { value: 'en_produccion', label: 'En producción' },
    { value: 'calidad',       label: 'Calidad'       },
    { value: 'despachada',    label: 'Despachada'    },
]

const ESTADOS_ITEM = [
    { value: 'pendiente',  label: 'Pendiente'  },
    { value: 'en_proceso', label: 'En proceso' },
    { value: 'terminado',  label: 'Terminado'  },
]

// ── Submit ────────────────────────────────────────────────────────────────────
function submit() {
    errores.value = {}
    procesando.value = true
    markClean()
    const payload = {
        ...form,
        items: items.value.map((item, idx) => ({
            ...(item.id ? { id: item.id } : {}),
            tipo:                 item.tipo,
            descripcion:          item.descripcion,
            descripcion_larga:    item.descripcion_larga || null,
            cantidad:             item.cantidad,
            orden:                idx,
            variables_instancia:  item.variables_instancia  ?? null,
            componentes_snapshot: item.componentes_snapshot ?? null,
            ensamble_id:          item.ensamble_id  ?? null,
            producto_id:          item.producto_id  ?? null,
            numero_serie:         item.numero_serie || null,
            operario_id:          item.operario_id  ?? null,
            estado_item:          item.estado_item,
            notas_item:           item.notas_item   || null,
        })),
    }
    if (esEdicion.value) {
        router.put(`/produccion/ops/${props.op.id}`, payload, {
            onError: e => { errores.value = e; procesando.value = false },
            onFinish: () => { procesando.value = false },
        })
    } else {
        router.post('/produccion/ops', payload, {
            onError: e => { errores.value = e; procesando.value = false },
            onFinish: () => { procesando.value = false },
        })
    }
}
</script>

<template>
    <AppLayout :title="esEdicion ? `Editar ${op?.numero}` : 'Nueva OP'">
        <div class="max-w-3xl mx-auto">

            <!-- Badge cambios sin guardar -->
            <div v-if="hasChanges"
                class="mb-3 inline-flex items-center gap-2 px-3 py-1.5 rounded-xl text-xs font-semibold text-orange-700"
                style="background:var(--pastel-ambar); border:1px solid #F59E0B;">
                ● Cambios sin guardar
            </div>

            <!-- Encabezado -->
            <div class="flex items-center gap-3 mb-5">
                <a href="/produccion/ops" class="text-tinta-300 hover:text-tinta-700">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M15 19l-7-7 7-7"/>
                    </svg>
                </a>
                <h1 class="text-lg font-semibold text-tinta-900">
                    {{ esEdicion ? `Editar ${op?.numero}` : 'Nueva Orden de Producción' }}
                </h1>
            </div>

            <!-- ── General ──────────────────────────────────────────────────── -->
            <div class="bg-superficie rounded-2xl border border-linea p-5 mb-4 space-y-4">
                <h2 class="text-sm font-semibold text-tinta-700">Datos generales</h2>

                <!-- Cliente -->
                <div class="relative">
                    <label class="block text-xs font-medium text-tinta-400 mb-1">Cliente</label>
                    <div class="relative">
                        <input v-model="clienteBuscar" type="text"
                            placeholder="Buscar por nombre o NIT..."
                            class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]"
                            @input="debounceCliente" @focus="buscarClientes" autocomplete="off"/>
                        <button v-if="form.cliente_id" @click="limpiarCliente"
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-tinta-300 hover:text-tinta-500">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                    <div v-if="clienteAbierto && clienteResultados.length"
                        class="absolute z-20 mt-1 w-full bg-superficie border border-linea rounded-xl shadow-lg overflow-hidden">
                        <button v-for="c in clienteResultados" :key="c.id"
                            @click="seleccionarCliente(c)"
                            class="w-full text-left px-4 py-2.5 text-sm hover:bg-blue-50 border-b border-gray-50 last:border-0">
                            <span class="font-medium text-tinta-900">{{ c.nombre }} {{ c.apellido }}</span>
                            <span v-if="c.numero_identificacion" class="ml-2 text-xs text-tinta-300">{{ c.numero_identificacion }}</span>
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Responsable -->
                    <div>
                        <label class="block text-xs font-medium text-tinta-400 mb-1">Responsable <span class="text-red-500">*</span></label>
                        <select v-model="form.responsable_id"
                            class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]">
                            <option value="">Seleccionar...</option>
                            <option v-for="r in responsables" :key="r.id" :value="r.id">{{ r.name }}</option>
                        </select>
                        <p v-if="errores.responsable_id" class="mt-1 text-xs text-red-600">{{ errores.responsable_id }}</p>
                    </div>

                    <!-- Estado -->
                    <div>
                        <label class="block text-xs font-medium text-tinta-400 mb-1">Estado</label>
                        <select v-model="form.estado"
                            class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]">
                            <option v-for="e in ESTADOS_OP" :key="e.value" :value="e.value">{{ e.label }}</option>
                        </select>
                    </div>

                    <!-- Fecha creación -->
                    <div>
                        <label class="block text-xs font-medium text-tinta-400 mb-1">Fecha creación <span class="text-red-500">*</span></label>
                        <input v-model="form.fecha_creacion" type="date"
                            class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]"/>
                    </div>

                    <!-- Entrega estimada -->
                    <div>
                        <label class="block text-xs font-medium text-tinta-400 mb-1">Entrega estimada</label>
                        <input v-model="form.fecha_entrega_estimada" type="date"
                            class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]"/>
                    </div>

                    <!-- Anticipo -->
                    <div class="md:col-span-2">
                        <label class="block text-xs font-medium text-tinta-400 mb-1">Anticipo (COP)</label>
                        <input v-model="form.anticipo" type="number" min="0" step="0.01"
                            class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]"
                            placeholder="0"/>
                    </div>
                </div>
            </div>

            <!-- ── Ítems ────────────────────────────────────────────────────── -->
            <div class="bg-superficie rounded-2xl border border-linea p-5 mb-4">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-sm font-semibold text-tinta-700">Ítems de la orden</h2>
                    <span v-if="items.length" class="text-xs text-tinta-300">{{ items.length }} ítem{{ items.length !== 1 ? 's' : '' }}</span>
                </div>

                <!-- Lista de ítems inline -->
                <div v-if="items.length" class="space-y-3 mb-4">
                    <div v-for="(item, idx) in items" :key="item._key"
                        class="rounded-xl border transition-colors"
                        :class="dragOverIdx === idx && draggingIdx !== idx ? 'border-blue-400 bg-blue-50' : 'border-linea'"
                        :draggable="true"
                        @dragstart="onDragStart(idx)"
                        @dragover="onDragOver($event, idx)"
                        @drop="onDrop(idx)"
                        @dragend="onDragEnd">

                        <!-- Barra superior -->
                        <div class="flex items-center gap-2 px-3 pt-2.5 pb-0">
                            <!-- Grip desktop -->
                            <div class="hidden sm:flex cursor-grab text-tinta-200 hover:text-tinta-300 shrink-0">
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                                    <circle cx="9" cy="5" r="1.5"/><circle cx="15" cy="5" r="1.5"/>
                                    <circle cx="9" cy="12" r="1.5"/><circle cx="15" cy="12" r="1.5"/>
                                    <circle cx="9" cy="19" r="1.5"/><circle cx="15" cy="19" r="1.5"/>
                                </svg>
                            </div>
                            <!-- Badge tipo -->
                            <span v-if="item.tipo === 'ensamble'" class="text-xs px-2 py-0.5 rounded-full font-medium shrink-0" style="background:var(--pastel-violeta);color:var(--texto-violeta);">Ensamble</span>
                            <span v-else-if="item.tipo === 'servicio'" class="text-xs px-2 py-0.5 rounded-full font-medium shrink-0" style="background:var(--pastel-verde);color:var(--texto-verde);">Servicio</span>
                            <span v-else class="text-xs px-2 py-0.5 rounded-full font-medium shrink-0" style="background:var(--pastel-azul-2);color:#0369A1;">Producto</span>
                            <div class="flex-1"/>
                            <!-- ↑↓ mobile -->
                            <div class="flex sm:hidden gap-0.5 shrink-0">
                                <button type="button" @click="moverItem(idx,-1)" :disabled="idx===0"
                                    class="w-7 h-7 rounded-lg flex items-center justify-center text-tinta-300 hover:text-tinta-700 hover:bg-tinta-100 disabled:opacity-30 text-sm font-semibold">↑</button>
                                <button type="button" @click="moverItem(idx,1)" :disabled="idx===items.length-1"
                                    class="w-7 h-7 rounded-lg flex items-center justify-center text-tinta-300 hover:text-tinta-700 hover:bg-tinta-100 disabled:opacity-30 text-sm font-semibold">↓</button>
                            </div>
                            <!-- Eliminar -->
                            <button type="button" @click="eliminarItem(idx)"
                                class="w-7 h-7 rounded-lg flex items-center justify-center text-tinta-300 hover:text-red-600 hover:bg-red-50 shrink-0">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                            </button>
                        </div>

                        <!-- Cuerpo del ítem -->
                        <div class="px-3 pt-2 pb-3">
                            <textarea v-model="item.descripcion" rows="2"
                                placeholder="Descripción del ítem..."
                                class="w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm focus:ring-2 focus:outline-none resize-none mb-1"/>
                            <div class="mb-2">
                                <div class="w-full rounded-lg border border-linea px-3 py-2 text-xs text-tinta-500 bg-tinta-50 max-h-28 overflow-y-auto leading-relaxed"
                                    v-html="item.descripcion_larga || '<span class=\'text-tinta-200\'>Sin descripción técnica</span>'">
                                </div>
                            </div>

                            <!-- Cantidad -->
                            <div class="flex items-center gap-3">
                                <label class="text-xs text-tinta-400 whitespace-nowrap">Cantidad</label>
                                <input v-model.number="item.cantidad" type="number" step="0.001" min="0.001"
                                    class="w-28 rounded-lg border border-tinta-200 px-2 py-1.5 text-sm text-right focus:outline-none"/>
                            </div>

                            <!-- Variables instancia -->
                            <div v-if="item.variables_instancia && Object.keys(item.variables_instancia).length" class="mt-2 flex flex-wrap gap-1.5">
                                <span v-for="(val, key) in item.variables_instancia" :key="key"
                                    class="text-xs px-2 py-0.5 rounded-lg bg-amber-50 border border-amber-200 text-amber-800">
                                    {{ key }}: <strong>{{ val }}</strong>
                                </span>
                            </div>

                            <!-- Acordeón datos producción -->
                            <button type="button" @click="item._expandido = !item._expandido"
                                class="mt-3 w-full flex items-center justify-between px-4 py-3 min-h-[44px] text-sm font-medium text-tinta-700 bg-tinta-50 rounded-lg active:bg-tinta-100 transition-colors touch-manipulation">
                                <div class="flex items-center gap-2">
                                    <span>Datos de producción</span>
                                    <span v-if="item.numero_serie || item.notas_item"
                                        class="w-2 h-2 rounded-full bg-blue-400 shrink-0"/>
                                </div>
                                <svg class="w-4 h-4 text-tinta-300 transition-transform duration-200 shrink-0"
                                    :class="item._expandido ? 'rotate-180' : ''"
                                    fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </button>

                            <div v-show="item._expandido" class="mt-2 space-y-3 px-1">
                                <div>
                                    <label class="block text-xs font-semibold text-tinta-400 mb-1">Número de serie</label>
                                    <div class="w-full rounded-lg border border-linea bg-tinta-50 px-3 py-2.5 text-sm text-tinta-400 italic">
                                        {{ item.numero_serie || 'Se asigna automáticamente al iniciar trabajo' }}
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-tinta-400 mb-1">Estado</label>
                                    <div class="w-full rounded-lg border border-linea bg-tinta-50 px-3 py-2.5 text-sm">
                                        <span class="px-2 py-0.5 rounded-full text-xs font-medium"
                                            :class="{
                                                'bg-tinta-100 text-tinta-500': item.estado_item === 'pendiente',
                                                'bg-blue-100 text-blue-700': item.estado_item === 'en_proceso',
                                                'bg-green-100 text-green-700': item.estado_item === 'terminado',
                                            }">
                                            {{ item.estado_item === 'pendiente' ? 'Sin iniciar'
                                               : item.estado_item === 'en_proceso' ? 'En proceso'
                                               : 'Terminado' }}
                                        </span>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-tinta-400 mb-1">Notas del ítem</label>
                                    <textarea v-model="item.notas_item" rows="2"
                                        class="w-full rounded-lg border border-linea px-3 py-2.5 text-sm resize-none focus:ring-2 focus:ring-[var(--marca)]/30 focus:border-[var(--marca)] focus:outline-none"
                                        placeholder="Observaciones de producción..."/>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <p v-else class="text-sm text-tinta-300 text-center py-8 border border-dashed border-linea rounded-xl mb-4">
                    Sin ítems. Agrega productos, servicios o ensambles.
                </p>

                <!-- Botón agregar -->
                <button type="button" @click="abrirModal"
                    class="w-full py-2.5 rounded-xl border-2 border-dashed border-tinta-200 text-sm font-medium text-tinta-400 hover:border-blue-400 hover:text-blue-600 hover:bg-blue-50 transition-colors flex items-center justify-center gap-2">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                    </svg>
                    Agregar ítem
                </button>
            </div>

            <!-- ── Panel componentes requeridos ─────────────────────────────── -->
            <div v-if="panelComponentes.length" class="bg-superficie rounded-2xl border border-linea overflow-hidden mb-4">
                <h2 class="text-sm font-semibold text-tinta-700 px-5 py-3 border-b border-linea">Componentes requeridos</h2>
                <div class="divide-y divide-gray-50">
                    <div v-for="grupo in panelComponentes" :key="grupo.nombre" class="px-5 py-4">
                        <p class="text-xs font-semibold text-tinta-500 mb-2">
                            {{ grupo.nombre }}
                            <span class="font-normal text-tinta-300 ml-1">× {{ grupo.cantidad_item }}</span>
                        </p>
                        <div class="rounded-xl border border-linea overflow-hidden overflow-x-auto">
                            <table class="w-full text-xs">
                                <thead>
                                    <tr class="bg-tinta-50 border-b border-linea">
                                        <th class="text-left px-3 py-2 font-semibold text-tinta-400">Componente</th>
                                        <th class="text-left px-3 py-2 font-semibold text-tinta-400">Ref.</th>
                                        <th class="text-center px-3 py-2 font-semibold text-tinta-400">Cant.</th>
                                        <th class="text-left px-3 py-2 font-semibold text-tinta-400">Und.</th>
                                        <th v-if="esEdicion" class="text-left px-3 py-2 font-semibold text-tinta-400">Obs.</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-50">
                                    <template v-for="comp in componentesConJerarquia(grupo.componentes)" :key="comp.id ?? comp.nombre">
                                        <!-- Fila hijo -->
                                        <tr v-if="comp.parent_componente_id" class="bg-tinta-50/50">
                                            <td class="pl-8 pr-3 py-1.5 text-tinta-400 text-sm">
                                                <span class="text-tinta-300 mr-1">↳</span>{{ comp.nombre }}
                                            </td>
                                            <td class="px-3 py-1.5 text-tinta-300 font-mono text-sm">—</td>
                                            <td class="px-3 py-1.5 text-center text-sm text-tinta-400">
                                                {{ parseFloat(comp.cantidad).toFixed(3).replace(/\.?0+$/, '') }}
                                            </td>
                                            <td class="px-3 py-1.5 text-sm text-tinta-400">{{ comp.unidad }}</td>
                                            <td v-if="esEdicion" class="px-3 py-1.5"></td>
                                        </tr>
                                        <!-- Fila padre / componente normal -->
                                        <tr v-else class="hover:bg-tinta-50">
                                            <td class="px-3 py-2 text-tinta-700 font-medium">{{ comp.nombre }}</td>
                                            <td class="px-3 py-2 text-tinta-300 font-mono">{{ comp.referencia ?? '—' }}</td>
                                            <td class="px-3 py-2 text-center">
                                                <template v-if="esEdicion && comp.id">
                                                    <input
                                                        :value="parseFloat(comp.cantidad).toFixed(3).replace(/\.?0+$/, '')"
                                                        @blur="guardarCompEdicion(grupo.item_id, comp, 'cantidad', parseFloat($event.target.value))"
                                                        type="number" step="0.001" min="0"
                                                        class="w-20 rounded-lg border border-linea px-2 py-1 text-center text-xs focus:outline-none focus:border-blue-300 focus:ring-1" />
                                                </template>
                                                <span v-else class="font-semibold text-tinta-900">
                                                    {{ parseFloat(comp.cantidad).toFixed(3).replace(/\.?0+$/, '') }}
                                                </span>
                                            </td>
                                            <td class="px-3 py-2 text-tinta-300">{{ comp.unidad }}</td>
                                            <td v-if="esEdicion" class="px-3 py-2">
                                                <input
                                                    :value="comp.observacion ?? ''"
                                                    @blur="guardarCompEdicion(grupo.item_id, comp, 'observacion', $event.target.value)"
                                                    type="text" placeholder="—"
                                                    class="w-full min-w-[80px] rounded-lg border border-linea px-2 py-1 text-xs text-tinta-500 focus:outline-none focus:border-blue-300 focus:ring-1 bg-transparent hover:bg-superficie" />
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Notas ────────────────────────────────────────────────────── -->
            <div class="bg-superficie rounded-2xl border border-linea p-5 mb-4 space-y-4">
                <h2 class="text-sm font-semibold text-tinta-700">Notas</h2>
                <div>
                    <label class="block text-xs font-medium text-tinta-400 mb-1">Condiciones</label>
                    <textarea v-model="form.condiciones" rows="3"
                        class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)] resize-none"
                        placeholder="Condiciones de entrega, garantías, etc."/>
                </div>
                <div>
                    <label class="block text-xs font-medium text-tinta-400 mb-1">Notas internas</label>
                    <textarea v-model="form.notas_internas" rows="3"
                        class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)] resize-none"
                        placeholder="Notas visibles solo para el equipo..."/>
                </div>
            </div>

            <!-- Errores -->
            <div v-if="Object.keys(errores).length" class="bg-red-50 border border-red-200 rounded-xl p-4 mb-4">
                <p v-for="(msg, field) in errores" :key="field" class="text-sm text-red-600">⚠ {{ msg }}</p>
            </div>

            <!-- Botones -->
            <div class="flex gap-3 pb-6">
                <a href="/produccion/ops"
                    class="flex-1 py-3 rounded-xl border border-linea text-sm font-medium text-tinta-500 text-center hover:bg-tinta-50">
                    Cancelar
                </a>
                <button @click="submit" :disabled="procesando"
                    class="flex-1 py-3 rounded-xl text-white text-sm font-semibold disabled:opacity-60"
                    style="background:var(--marca);">
                    {{ procesando ? 'Guardando...' : (esEdicion ? 'Guardar cambios' : 'Crear orden') }}
                </button>
            </div>
        </div>

        <!-- ══ Modal agregar ítem ══════════════════════════════════════════════ -->
        <Teleport to="body">
            <div v-if="modalPanel" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4">
                <div class="absolute inset-0 bg-black/40" @click="cerrarModal"/>
                <div class="relative w-full sm:max-w-lg bg-superficie rounded-t-3xl sm:rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">

                    <!-- Header modal -->
                    <div class="flex items-center justify-between px-5 pt-4 pb-3 border-b border-linea shrink-0">
                        <div class="flex items-center gap-3">
                            <button v-if="modalPanel !== 'opciones'" type="button"
                                @click="modalPanel = modalPanel === 'ensamble_instancia' ? 'ensamble' : 'opciones'"
                                class="text-tinta-300 hover:text-tinta-500">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                                </svg>
                            </button>
                            <h3 class="text-sm font-semibold text-tinta-900">
                                <span v-if="modalPanel === 'opciones'">Agregar ítem</span>
                                <span v-else-if="modalPanel === 'producto'">Buscar producto / servicio</span>
                                <span v-else-if="modalPanel === 'ensamble'">Buscar ensamble</span>
                                <span v-else-if="modalPanel === 'ensamble_instancia'">Configurar ensamble</span>
                            </h3>
                        </div>
                        <button type="button" @click="cerrarModal" class="text-tinta-300 hover:text-tinta-500">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    <!-- Opciones -->
                    <div v-if="modalPanel === 'opciones'" class="p-5 space-y-3">
                        <button type="button" @click="modalPanel = 'producto'"
                            class="w-full flex items-center gap-4 p-4 rounded-2xl border-2 border-linea hover:border-blue-400 hover:bg-blue-50 transition-colors text-left group">
                            <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0" style="background:var(--pastel-azul);">
                                <svg class="w-5 h-5" style="color:var(--marca);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7l8 4"/>
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-tinta-900">Desde productos / servicios</p>
                                <p class="text-xs text-tinta-400 mt-0.5">Busca en el catálogo</p>
                            </div>
                            <svg class="w-4 h-4 text-tinta-300 ml-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                            </svg>
                        </button>
                        <button type="button" @click="modalPanel = 'ensamble'"
                            class="w-full flex items-center gap-4 p-4 rounded-2xl border-2 border-linea hover:border-purple-400 hover:bg-purple-50 transition-colors text-left">
                            <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0" style="background:var(--pastel-violeta);">
                                <svg class="w-5 h-5" style="color:var(--texto-violeta);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-tinta-900">Desde ensamble</p>
                                <p class="text-xs text-tinta-400 mt-0.5">Agrega un ensamble con sus componentes</p>
                            </div>
                            <svg class="w-4 h-4 text-tinta-300 ml-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                            </svg>
                        </button>
                    </div>

                    <!-- Buscador productos -->
                    <div v-else-if="modalPanel === 'producto'" class="flex flex-col overflow-hidden">
                        <div class="px-5 py-3 shrink-0">
                            <input :value="productoQuery" @input="buscarProducto($event.target.value)"
                                type="text" placeholder="Nombre o referencia..." autofocus
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:ring-2 focus:border-[var(--marca)] focus:outline-none"/>
                        </div>
                        <div class="overflow-y-auto flex-1">
                            <p v-if="!productoQuery" class="text-sm text-tinta-300 text-center py-8">Escribe para buscar...</p>
                            <p v-else-if="productoResultados.length === 0 && productoQuery.length >= 2" class="text-sm text-tinta-300 text-center py-8">Sin resultados</p>
                            <ResultadosBuscadorProducto :resultados="productoResultados" @elegir="agregarDesdeProducto" />
                        </div>
                    </div>

                    <!-- Buscador ensambles -->
                    <div v-else-if="modalPanel === 'ensamble'" class="flex flex-col overflow-hidden">
                        <div class="px-5 py-3 shrink-0">
                            <input :value="ensambleQuery" @input="buscarEnsamble($event.target.value)"
                                type="text" placeholder="Nombre del ensamble..." autofocus
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:ring-2 focus:border-purple-400 focus:outline-none"/>
                        </div>
                        <div class="overflow-y-auto flex-1">
                            <p v-if="!ensambleQuery" class="text-sm text-tinta-300 text-center py-8">Escribe para buscar...</p>
                            <p v-else-if="ensambleResultados.length === 0 && ensambleQuery.length >= 2" class="text-sm text-tinta-300 text-center py-8">Sin resultados</p>
                            <div v-for="e in ensambleResultados" :key="e.id" class="border-b border-gray-50 last:border-0">
                                <div class="flex items-center justify-between px-5 py-3 hover:bg-tinta-50 cursor-pointer"
                                    @click="seleccionarEnsamble(e)">
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-medium text-tinta-900 truncate">{{ e.nombre }}</p>
                                        <p class="text-xs text-tinta-300 mt-0.5">{{ e.plantilla_nombre }}</p>
                                    </div>
                                    <!-- Agregar directo si ya está expandido -->
                                    <div v-if="ensambleExpandido?.id === e.id" class="shrink-0 ml-3">
                                        <button type="button" @click.stop="agregarDesdeEnsamble(e)"
                                            class="px-3 py-1.5 rounded-xl text-white text-xs font-semibold"
                                            style="background:#5B21B6;">
                                            Agregar
                                        </button>
                                    </div>
                                    <svg v-else class="w-4 h-4 text-tinta-200 shrink-0 ml-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Configurador variable_instancia -->
                    <div v-else-if="modalPanel === 'ensamble_instancia'" class="flex flex-col overflow-hidden">
                        <div class="px-5 py-3 border-b border-linea shrink-0">
                            <p class="text-sm font-semibold text-tinta-900">{{ ensambleInstancia?.nombre }}</p>
                            <p class="text-xs text-tinta-300 mt-0.5">Completa los datos específicos de esta unidad</p>
                        </div>
                        <div class="overflow-y-auto flex-1 px-5 py-4 space-y-3">
                            <div v-for="c in camposInstancia" :key="c.nombre">
                                <label class="block text-xs font-medium text-tinta-700 mb-1">
                                    {{ c.etiqueta || c.nombre }}
                                    <span v-if="c.ayuda" class="font-normal text-tinta-300"> — {{ c.ayuda }}</span>
                                </label>
                                <select v-if="c.subtipo_variable === 'selector'" v-model="valoresInstancia[c.nombre]"
                                    class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none">
                                    <option v-for="op in (c.opciones_selector ?? [])" :key="op.valor" :value="op.valor">{{ op.etiqueta }}</option>
                                </select>
                                <input v-else-if="c.subtipo_variable === 'numero'" v-model.number="valoresInstancia[c.nombre]"
                                    type="number" step="1"
                                    class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none"/>
                                <input v-else-if="c.subtipo_variable === 'decimal'" v-model.number="valoresInstancia[c.nombre]"
                                    type="number" step="0.01"
                                    class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none"/>
                                <input v-else v-model="valoresInstancia[c.nombre]" type="text"
                                    class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none"/>
                            </div>
                        </div>
                        <div class="px-5 py-4 border-t border-linea flex gap-3 shrink-0">
                            <button type="button" @click="modalPanel = 'ensamble'"
                                class="flex-1 py-2.5 rounded-xl border border-linea text-sm text-tinta-500">
                                Volver
                            </button>
                            <button type="button" @click="confirmarInstancia"
                                class="flex-1 py-2.5 rounded-xl text-white text-sm font-semibold"
                                style="background:#5B21B6;">
                                Confirmar
                            </button>
                        </div>
                    </div>

                    <!-- Handle móvil -->
                    <div class="sm:hidden flex justify-center py-3 shrink-0">
                        <div class="w-10 h-1 bg-tinta-200 rounded-full"/>
                    </div>
                </div>
            </div>
        </Teleport>

    </AppLayout>
</template>
