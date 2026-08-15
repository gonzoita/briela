<script setup>
import { ref, reactive, computed, watch, nextTick } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    plantillas: { type: Array, default: () => [] },
    productos:  { type: Array, default: () => [] },
})

// ── Estado global ─────────────────────────────────────────────────────────────
const plantillas    = ref(props.plantillas.map(p => ({ ...p })))
const plantillaActual = ref(null)
const tabMobile     = ref('plantillas') // 'plantillas' | 'campos' | 'componentes'
const guardando     = ref(false)
const errorGlobal   = ref('')

const csrf = () => { const c = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN=')); return c ? decodeURIComponent(c.split('=')[1]) : '' }
const jsonHdr = () => ({ 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' })

// ── Seleccionar plantilla ─────────────────────────────────────────────────────
function seleccionar(p) {
    plantillaActual.value = { ...p, campos: [...(p.campos || [])], componentes: [...(p.componentes || [])] }
    editandoCampo.value      = null
    editandoComponente.value = null
    tabMobile.value          = 'campos'
}

// ── CRUD plantilla ────────────────────────────────────────────────────────────
const modalNuevaPlantilla = ref(false)
const nuevaPlantilla = reactive({ nombre: '', descripcion: '' })

async function crearPlantilla() {
    if (!nuevaPlantilla.nombre) return
    guardando.value = true
    try {
        const res  = await fetch('/cotizadores/configurador', { method: 'POST', headers: jsonHdr(), body: JSON.stringify(nuevaPlantilla) })
        const data = await res.json()
        plantillas.value.push(data)
        seleccionar(data)
        modalNuevaPlantilla.value = false
        nuevaPlantilla.nombre     = ''
        nuevaPlantilla.descripcion = ''
    } catch { errorGlobal.value = 'Error al crear plantilla.' }
    finally { guardando.value = false }
}

async function guardarNombrePlantilla() {
    if (!plantillaActual.value) return
    await fetch(`/cotizadores/configurador/${plantillaActual.value.id}`, {
        method: 'PUT', headers: jsonHdr(),
        body: JSON.stringify({ nombre: plantillaActual.value.nombre, descripcion: plantillaActual.value.descripcion }),
    })
    const idx = plantillas.value.findIndex(p => p.id === plantillaActual.value.id)
    if (idx >= 0) { plantillas.value[idx].nombre = plantillaActual.value.nombre }
}

async function eliminarPlantilla(p) {
    if (!confirm(`¿Eliminar la plantilla "${p.nombre}"? Esta acción no se puede deshacer.`)) return
    await fetch(`/cotizadores/configurador/${p.id}`, { method: 'DELETE', headers: jsonHdr() })
    plantillas.value = plantillas.value.filter(x => x.id !== p.id)
    if (plantillaActual.value?.id === p.id) plantillaActual.value = null
}

// ── CRUD Campos ───────────────────────────────────────────────────────────────
const editandoCampo  = ref(null) // null = cerrado, 'nuevo' = nuevo, id = editar
const campoBorrador  = reactive({ nombre: '', etiqueta: '', tipo: 'decimal', valor_defecto: '', requerido: true, opciones: [] })

const TIPOS_CAMPO = [
    { value: 'texto',   label: 'Texto' },
    { value: 'numero',  label: 'Número entero' },
    { value: 'decimal', label: 'Decimal' },
    { value: 'select',  label: 'Selector' },
    { value: 'boolean', label: 'Verdadero/Falso' },
    { value: 'checkbox',label: 'Checkbox' },
]

function abrirNuevoCampo() {
    Object.assign(campoBorrador, { nombre: '', etiqueta: '', tipo: 'decimal', valor_defecto: '', requerido: true, opciones: [] })
    editandoCampo.value = 'nuevo'
}

function abrirEditarCampo(c) {
    Object.assign(campoBorrador, { ...c, opciones: c.opciones ? [...c.opciones] : [] })
    editandoCampo.value = c.id
}

function cancelarCampo() { editandoCampo.value = null }

watch(() => campoBorrador.etiqueta, (v) => {
    if (editandoCampo.value === 'nuevo') {
        campoBorrador.nombre = v.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '')
    }
})

function agregarOpcion() { campoBorrador.opciones.push({ valor: '', etiqueta: '' }) }
function quitarOpcion(i) { campoBorrador.opciones.splice(i, 1) }

async function guardarCampo() {
    if (!plantillaActual.value || !campoBorrador.nombre || !campoBorrador.etiqueta) return
    guardando.value = true
    try {
        const esNuevo = editandoCampo.value === 'nuevo'
        const url = esNuevo
            ? `/cotizadores/configurador/${plantillaActual.value.id}/campos`
            : `/cotizadores/configurador/${plantillaActual.value.id}/campos/${editandoCampo.value}`
        const res  = await fetch(url, { method: esNuevo ? 'POST' : 'PUT', headers: jsonHdr(), body: JSON.stringify(campoBorrador) })
        const data = await res.json()
        if (esNuevo) {
            plantillaActual.value.campos.push(data)
        } else {
            const idx = plantillaActual.value.campos.findIndex(c => c.id === data.id)
            if (idx >= 0) plantillaActual.value.campos[idx] = data
        }
        editandoCampo.value = null
    } catch { errorGlobal.value = 'Error al guardar campo.' }
    finally { guardando.value = false }
}

async function eliminarCampo(c) {
    if (!confirm(`¿Eliminar el campo "${c.etiqueta}"?`)) return
    await fetch(`/cotizadores/configurador/${plantillaActual.value.id}/campos/${c.id}`, { method: 'DELETE', headers: jsonHdr() })
    plantillaActual.value.campos = plantillaActual.value.campos.filter(x => x.id !== c.id)
}

// ── CRUD Componentes ─────────────────────────────────────────────────────────
const editandoComponente = ref(null)
const compBorrador = reactive({ producto_id: null, etiqueta: '', formula: '', condicion: '', unidad: '', notas: '', activo: true })
const busqProducto  = ref('')
const resBusqProd   = ref([])
let timerBusqProd   = null

watch(busqProducto, (v) => {
    clearTimeout(timerBusqProd)
    if (!v || v.length < 2) { resBusqProd.value = []; return }
    timerBusqProd = setTimeout(() => {
        resBusqProd.value = props.productos.filter(p =>
            p.nombre.toLowerCase().includes(v.toLowerCase()) ||
            (p.referencia && p.referencia.toLowerCase().includes(v.toLowerCase()))
        ).slice(0, 10)
    }, 200)
})

function elegirProducto(prod) {
    compBorrador.producto_id = prod.id
    busqProducto.value       = prod.nombre
    if (!compBorrador.etiqueta) compBorrador.etiqueta = prod.nombre
    if (!compBorrador.unidad)   compBorrador.unidad   = prod.unidad_medida ?? ''
    resBusqProd.value = []
}

function abrirNuevoComponente() {
    Object.assign(compBorrador, { producto_id: null, etiqueta: '', formula: '', condicion: '', unidad: '', notas: '', activo: true })
    busqProducto.value      = ''
    editandoComponente.value = 'nuevo'
}

function abrirEditarComponente(c) {
    Object.assign(compBorrador, { ...c })
    busqProducto.value       = c.producto?.nombre ?? (c.etiqueta ?? '')
    editandoComponente.value = c.id
}

function cancelarComponente() { editandoComponente.value = null }

async function guardarComponente() {
    if (!plantillaActual.value || !compBorrador.formula) return
    guardando.value = true
    try {
        const esNuevo = editandoComponente.value === 'nuevo'
        const url = esNuevo
            ? `/cotizadores/configurador/${plantillaActual.value.id}/componentes`
            : `/cotizadores/configurador/${plantillaActual.value.id}/componentes/${editandoComponente.value}`
        const res  = await fetch(url, { method: esNuevo ? 'POST' : 'PUT', headers: jsonHdr(), body: JSON.stringify(compBorrador) })
        const data = await res.json()
        if (esNuevo) {
            plantillaActual.value.componentes.push(data)
        } else {
            const idx = plantillaActual.value.componentes.findIndex(c => c.id === data.id)
            if (idx >= 0) plantillaActual.value.componentes[idx] = data
        }
        editandoComponente.value = null
    } catch { errorGlobal.value = 'Error al guardar componente.' }
    finally { guardando.value = false }
}

async function eliminarComponente(c) {
    if (!confirm(`¿Eliminar el componente "${c.etiqueta || c.formula}"?`)) return
    await fetch(`/cotizadores/configurador/${plantillaActual.value.id}/componentes/${c.id}`, { method: 'DELETE', headers: jsonHdr() })
    plantillaActual.value.componentes = plantillaActual.value.componentes.filter(x => x.id !== c.id)
}

// ── Modal "Probar plantilla" ───────────────────────────────────────────────────
const modalProbar    = ref(false)
const probarValores  = ref({})
const probarResultado = ref(null)
const probarCargando = ref(false)

function abrirProbar() {
    if (!plantillaActual.value) return
    const vals = {}
    for (const c of plantillaActual.value.campos) {
        if (c.tipo === 'checkbox' || c.tipo === 'boolean') vals[c.nombre] = false
        else if (c.tipo === 'decimal' || c.tipo === 'numero') vals[c.nombre] = parseFloat(c.valor_defecto ?? 0)
        else vals[c.nombre] = c.valor_defecto ?? ''
    }
    probarValores.value  = vals
    probarResultado.value = null
    modalProbar.value    = true
}

async function ejecutarProbar() {
    probarCargando.value = true
    try {
        const res  = await fetch('/cotizadores/configurador/probar', {
            method: 'POST', headers: jsonHdr(),
            body: JSON.stringify({ plantilla_id: plantillaActual.value.id, valores: probarValores.value }),
        })
        probarResultado.value = await res.json()
    } catch { errorGlobal.value = 'Error al probar plantilla.' }
    finally { probarCargando.value = false }
}

const formatCOP = (v) => new Intl.NumberFormat('es-CO', { maximumFractionDigits: 0 }).format(v ?? 0)

// ── Variables disponibles (hint para fórmulas) ────────────────────────────────
const variablesDisponibles = computed(() => {
    const siempre = ['ancho_vano', 'alto_vano', 'espesor']
    const deCampos = (plantillaActual.value?.campos ?? []).map(c => c.nombre).filter(Boolean)
    return [...new Set([...siempre, ...deCampos])]
})

const badgesTipo = {
    texto: 'bg-tinta-100 text-tinta-500',
    numero: 'bg-pastel-azul-2 text-aviso-azul',
    decimal: 'bg-[var(--marca-suave)] text-[var(--marca)]',
    select: 'bg-pastel-violeta-2 text-aviso-violeta',
    boolean: 'bg-pastel-verde-2 text-aviso-verde',
    checkbox: 'bg-pastel-naranja-2 text-aviso-naranja',
}
</script>

<template>
    <AppLayout title="Constructor de Plantillas">
        <div class="max-w-full">

            <!-- Error global -->
            <div v-if="errorGlobal" class="mb-4 bg-pastel-rojo border border-borde-aviso-rojo rounded-xl px-4 py-3 text-sm text-aviso-rojo flex items-center justify-between">
                {{ errorGlobal }}
                <button @click="errorGlobal = ''" class="text-red-400 hover:text-aviso-rojo ml-3">✕</button>
            </div>

            <!-- ── TABS MOBILE ── -->
            <div class="flex md:hidden mb-4 rounded-xl overflow-hidden border border-linea">
                <button v-for="tab in [['plantillas','Plantillas'],['campos','Campos'],['componentes','Componentes']]" :key="tab[0]"
                    @click="tabMobile = tab[0]"
                    :class="['flex-1 py-2.5 text-xs font-semibold transition-colors', tabMobile === tab[0] ? 'text-white' : 'text-tinta-500 bg-superficie hover:bg-tinta-50']"
                    :style="tabMobile === tab[0] ? 'background:var(--marca);' : ''">
                    {{ tab[1] }}
                </button>
            </div>

            <!-- ── LAYOUT DESKTOP 3 COL ── -->
            <div class="flex gap-4 items-start">

                <!-- ══ COL 1: Lista de plantillas ══════════════════════════════════ -->
                <aside :class="['md:block md:w-60 shrink-0', tabMobile !== 'plantillas' ? 'hidden' : 'block w-full']">
                    <div class="bg-superficie rounded-2xl shadow-sm overflow-hidden">
                        <div class="px-4 py-3 border-b border-linea flex items-center justify-between">
                            <h2 class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Plantillas</h2>
                            <button @click="modalNuevaPlantilla = true"
                                class="w-6 h-6 rounded-lg flex items-center justify-center text-white text-base font-semibold"
                                style="background:var(--marca);" title="Nueva plantilla">+</button>
                        </div>
                        <ul class="divide-y divide-separador">
                            <li v-for="p in plantillas" :key="p.id"
                                @click="seleccionar(p)"
                                :class="['flex items-center justify-between px-4 py-3 cursor-pointer transition-colors group', plantillaActual?.id === p.id ? 'bg-pastel-azul' : 'hover:bg-tinta-50']">
                                <div class="min-w-0">
                                    <p :class="['text-sm font-medium truncate', plantillaActual?.id === p.id ? 'text-aviso-azul' : 'text-tinta-900']">{{ p.nombre }}</p>
                                    <p class="text-xs text-tinta-300">{{ p.campos?.length ?? 0 }} campos · {{ p.componentes?.length ?? 0 }} comp.</p>
                                </div>
                                <button @click.stop="eliminarPlantilla(p)"
                                    class="opacity-0 group-hover:opacity-100 w-6 h-6 rounded-lg flex items-center justify-center text-red-400 hover:bg-pastel-rojo transition-opacity">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </li>
                            <li v-if="!plantillas.length" class="px-4 py-6 text-center text-sm text-tinta-300">
                                Sin plantillas. Crea una con el botón +.
                            </li>
                        </ul>
                    </div>
                </aside>

                <!-- ══ SIN PLANTILLA ════════════════════════════════════════════════ -->
                <div v-if="!plantillaActual" class="hidden md:flex flex-1 items-center justify-center py-24 text-tinta-300">
                    <div class="text-center">
                        <svg class="w-12 h-12 mx-auto mb-3 text-tinta-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                        </svg>
                        <p class="text-sm">Selecciona o crea una plantilla</p>
                    </div>
                </div>

                <template v-if="plantillaActual">

                    <!-- ══ COL 2: Campos de entrada ═══════════════════════════════ -->
                    <section :class="['flex-1 min-w-0', tabMobile !== 'campos' ? 'hidden md:block' : 'block']">
                        <div class="bg-superficie rounded-2xl shadow-sm overflow-hidden">
                            <!-- Header editable del nombre -->
                            <div class="px-5 py-3 border-b border-linea space-y-2">
                                <input v-model="plantillaActual.nombre"
                                    @blur="guardarNombrePlantilla"
                                    class="w-full text-base font-semibold text-tinta-900 border-0 outline-none bg-transparent focus:ring-1 focus:ring-blue-300 rounded px-1"
                                    placeholder="Nombre de la plantilla" />
                                <div class="flex items-center gap-2">
                                    <h3 class="text-xs font-semibold text-tinta-300 uppercase tracking-[0.12em]">Campos de entrada</h3>
                                    <span class="text-xs text-tinta-200">·</span>
                                    <button @click="abrirProbar"
                                        class="text-xs font-medium text-aviso-azul hover:text-aviso-azul flex items-center gap-1">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        Probar plantilla
                                    </button>
                                </div>
                            </div>

                            <!-- Lista de campos -->
                            <ul class="divide-y divide-separador">
                                <li v-for="c in plantillaActual.campos" :key="c.id"
                                    class="px-4 py-3 flex items-start gap-3 group">
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <span :class="['text-xs px-2 py-0.5 rounded-full font-medium', badgesTipo[c.tipo] ?? 'bg-tinta-100 text-tinta-400']">{{ c.tipo }}</span>
                                            <span class="text-sm font-semibold text-tinta-900 truncate">{{ c.etiqueta }}</span>
                                        </div>
                                        <p class="text-xs text-tinta-300 mt-0.5 font-mono">{{ c.nombre }}{{ c.valor_defecto ? ` = ${c.valor_defecto}` : '' }}</p>
                                        <p v-if="c.opciones?.length" class="text-xs text-tinta-300">{{ c.opciones.map(o => o.etiqueta).join(' · ') }}</p>
                                    </div>
                                    <div class="flex gap-1 opacity-0 group-hover:opacity-100 shrink-0">
                                        <button @click="abrirEditarCampo(c)" class="p-1 rounded-lg text-tinta-300 hover:text-aviso-azul hover:bg-realce">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                                        </button>
                                        <button @click="eliminarCampo(c)" class="p-1 rounded-lg text-tinta-300 hover:text-aviso-rojo hover:bg-pastel-rojo">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                        </button>
                                    </div>
                                </li>
                                <li v-if="!plantillaActual.campos.length" class="px-4 py-6 text-center text-sm text-tinta-300">Sin campos. Agrega el primero.</li>
                            </ul>

                            <!-- Botón agregar -->
                            <div v-if="editandoCampo === null" class="px-4 py-3 border-t border-linea">
                                <button @click="abrirNuevoCampo"
                                    class="w-full py-2 rounded-xl border-2 border-dashed border-linea text-sm text-tinta-400 hover:border-borde-aviso-azul hover:text-aviso-azul transition-colors">
                                    + Agregar campo
                                </button>
                            </div>

                            <!-- Editor de campo (inline) -->
                            <div v-if="editandoCampo !== null" class="border-t border-borde-aviso-azul p-4 space-y-3" style="background:var(--pastel-azul);">
                                <p class="text-xs font-semibold text-aviso-azul uppercase">{{ editandoCampo === 'nuevo' ? 'Nuevo campo' : 'Editar campo' }}</p>

                                <div class="flex flex-wrap gap-2">
                                    <button v-for="t in TIPOS_CAMPO" :key="t.value" type="button"
                                        @click="campoBorrador.tipo = t.value"
                                        :class="['px-2.5 py-1 rounded-lg text-xs font-medium border transition-colors', campoBorrador.tipo === t.value ? 'border-blue-500 bg-blue-600 text-white' : 'border-linea text-tinta-500 hover:bg-tinta-50']">
                                        {{ t.label }}
                                    </button>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                    <div>
                                        <label class="block text-xs font-medium text-tinta-500 mb-1">Etiqueta visible <span class="text-red-400">*</span></label>
                                        <input v-model="campoBorrador.etiqueta" type="text" class="w-full border border-linea rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-[var(--marca)]" placeholder="Ej: Ancho del vano (m)" />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-tinta-500 mb-1">Nombre interno</label>
                                        <input v-model="campoBorrador.nombre" type="text" class="w-full border border-linea rounded-lg px-3 py-1.5 text-sm font-mono focus:outline-none focus:border-[var(--marca)]" placeholder="ancho_vano" />
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                    <div>
                                        <label class="block text-xs font-medium text-tinta-500 mb-1">Valor por defecto</label>
                                        <input v-model="campoBorrador.valor_defecto" type="text" class="w-full border border-linea rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-[var(--marca)]" placeholder="Ej: 1.0" />
                                    </div>
                                    <div class="flex items-center gap-2 pt-4">
                                        <input v-model="campoBorrador.requerido" type="checkbox" id="req-campo" class="rounded" />
                                        <label for="req-campo" class="text-xs text-tinta-500">Requerido</label>
                                    </div>
                                </div>

                                <!-- Opciones para tipo select -->
                                <div v-if="campoBorrador.tipo === 'select'" class="space-y-2">
                                    <p class="text-xs font-medium text-tinta-500">Opciones del selector:</p>
                                    <div v-for="(op, i) in campoBorrador.opciones" :key="i" class="flex gap-2 items-center">
                                        <input v-model="op.valor"    type="text" placeholder="valor"   class="flex-1 border border-linea rounded-lg px-2 py-1 text-xs font-mono focus:outline-none" />
                                        <input v-model="op.etiqueta" type="text" placeholder="etiqueta" class="flex-1 border border-linea rounded-lg px-2 py-1 text-xs focus:outline-none" />
                                        <button @click="quitarOpcion(i)" class="text-red-400 hover:text-aviso-rojo">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                        </button>
                                    </div>
                                    <button @click="agregarOpcion" class="text-xs text-aviso-azul hover:text-aviso-azul font-medium">+ Agregar opción</button>
                                </div>

                                <div class="flex gap-2">
                                    <button @click="cancelarCampo" class="flex-1 py-2 rounded-xl border border-linea text-xs text-tinta-500 hover:bg-tinta-50">Cancelar</button>
                                    <button @click="guardarCampo" :disabled="guardando" class="flex-1 py-2 rounded-xl text-xs text-white font-semibold disabled:opacity-60" style="background:var(--marca);">
                                        {{ guardando ? '...' : 'Guardar campo' }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- ══ COL 3: Componentes y fórmulas ═══════════════════════════ -->
                    <section :class="['flex-1 min-w-0', tabMobile !== 'componentes' ? 'hidden md:block' : 'block']">
                        <div class="bg-superficie rounded-2xl shadow-sm overflow-hidden">
                            <div class="px-5 py-3 border-b border-linea">
                                <h3 class="text-xs font-semibold text-tinta-300 uppercase tracking-[0.12em]">Componentes y fórmulas</h3>
                                <p class="text-xs text-tinta-300 mt-0.5">Variables: <span class="font-mono text-aviso-azul">{{ variablesDisponibles.join(', ') }}</span></p>
                            </div>

                            <!-- Lista de componentes -->
                            <ul class="divide-y divide-separador">
                                <li v-for="c in plantillaActual.componentes" :key="c.id"
                                    :class="['px-4 py-3 flex items-start gap-3 group', !c.activo ? 'opacity-50' : '']">
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-semibold text-tinta-900 truncate">{{ c.etiqueta || c.producto?.nombre || '(sin nombre)' }}</p>
                                        <p class="text-xs font-mono text-[var(--marca)] mt-0.5 break-all">{{ c.formula }}</p>
                                        <p v-if="c.condicion" class="text-xs text-aviso-ambar mt-0.5 break-all font-mono">if {{ c.condicion }}</p>
                                        <span class="text-xs text-tinta-300">{{ c.unidad }}</span>
                                    </div>
                                    <div class="flex gap-1 opacity-0 group-hover:opacity-100 shrink-0">
                                        <button @click="abrirEditarComponente(c)" class="p-1 rounded-lg text-tinta-300 hover:text-aviso-azul hover:bg-realce">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                                        </button>
                                        <button @click="eliminarComponente(c)" class="p-1 rounded-lg text-tinta-300 hover:text-aviso-rojo hover:bg-pastel-rojo">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                        </button>
                                    </div>
                                </li>
                                <li v-if="!plantillaActual.componentes.length" class="px-4 py-6 text-center text-sm text-tinta-300">Sin componentes. Agrega el primero.</li>
                            </ul>

                            <!-- Botón agregar -->
                            <div v-if="editandoComponente === null" class="px-4 py-3 border-t border-linea">
                                <button @click="abrirNuevoComponente"
                                    class="w-full py-2 rounded-xl border-2 border-dashed border-linea text-sm text-tinta-400 hover:border-borde-aviso-azul hover:text-aviso-azul transition-colors">
                                    + Agregar componente
                                </button>
                            </div>

                            <!-- Editor de componente (inline) -->
                            <div v-if="editandoComponente !== null" class="border-t border-[var(--marca-borde)] p-4 space-y-3" style="background:var(--pastel-violeta);">
                                <p class="text-xs font-semibold text-[var(--marca)] uppercase">{{ editandoComponente === 'nuevo' ? 'Nuevo componente' : 'Editar componente' }}</p>

                                <!-- Buscador de producto -->
                                <div class="relative">
                                    <label class="block text-xs font-medium text-tinta-500 mb-1">Producto/Insumo vinculado</label>
                                    <input v-model="busqProducto" type="text"
                                        class="w-full border border-linea rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-[var(--marca)]"
                                        placeholder="Buscar producto..." />
                                    <ul v-if="resBusqProd.length"
                                        class="absolute z-20 left-0 right-0 bg-superficie border border-linea rounded-xl mt-1 shadow-lg overflow-hidden max-h-40 overflow-y-auto">
                                        <li v-for="p in resBusqProd" :key="p.id"
                                            @click="elegirProducto(p)"
                                            class="px-3 py-2 text-sm cursor-pointer hover:bg-tinta-50">
                                            <span class="font-medium">{{ p.nombre }}</span>
                                            <span class="text-tinta-300 text-xs ml-2">{{ p.referencia }}</span>
                                        </li>
                                    </ul>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                    <div>
                                        <label class="block text-xs font-medium text-tinta-500 mb-1">Etiqueta visible</label>
                                        <input v-model="compBorrador.etiqueta" type="text" class="w-full border border-linea rounded-lg px-3 py-1.5 text-sm focus:outline-none" placeholder="Ej: Perfil perimetral 70mm" />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-tinta-500 mb-1">Unidad</label>
                                        <input v-model="compBorrador.unidad" type="text" class="w-full border border-linea rounded-lg px-3 py-1.5 text-sm focus:outline-none font-mono" placeholder="ML, UN, M2..." />
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-xs font-medium text-tinta-500 mb-1">Fórmula <span class="text-red-400">*</span></label>
                                    <input v-model="compBorrador.formula" type="text"
                                        class="w-full border border-linea rounded-lg px-3 py-1.5 text-sm font-mono focus:outline-none focus:border-[var(--marca)]"
                                        placeholder="Ej: 2 * alto_vano + 2 * ancho_vano + 0.664" />
                                    <p class="text-xs text-tinta-300 mt-0.5">Variables: {{ variablesDisponibles.join(', ') }}</p>
                                </div>

                                <div>
                                    <label class="block text-xs font-medium text-tinta-500 mb-1">Condición (opcional)</label>
                                    <input v-model="compBorrador.condicion" type="text"
                                        class="w-full border border-linea rounded-lg px-3 py-1.5 text-sm font-mono focus:outline-none focus:border-amber-400"
                                        placeholder='Ej: temperatura == "BAJA" and tipo_puerta == "BATIENTE_SIMPLE"' />
                                    <p class="text-xs text-tinta-300 mt-0.5">Si la condición es falsa, el componente no se incluye.</p>
                                </div>

                                <div class="flex items-center gap-2">
                                    <input v-model="compBorrador.activo" type="checkbox" id="comp-activo" class="rounded" />
                                    <label for="comp-activo" class="text-xs text-tinta-500">Activo</label>
                                </div>

                                <div class="flex gap-2">
                                    <button @click="cancelarComponente" class="flex-1 py-2 rounded-xl border border-linea text-xs text-tinta-500 hover:bg-tinta-50">Cancelar</button>
                                    <button @click="guardarComponente" :disabled="guardando || !compBorrador.formula" class="flex-1 py-2 rounded-xl text-xs text-white font-semibold disabled:opacity-60" style="background:var(--marca);">
                                        {{ guardando ? '...' : 'Guardar componente' }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </section>

                </template>
            </div>
        </div>

        <!-- ══ Modal nueva plantilla ════════════════════════════════════════════════ -->
        <Teleport to="body">
            <div v-if="modalNuevaPlantilla" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-4" style="background:rgba(0,0,0,0.5);">
                <div class="bg-superficie rounded-2xl shadow-xl w-full max-w-sm p-5">
                    <h3 class="text-base font-semibold text-tinta-900 mb-4">Nueva plantilla de ensamble</h3>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-sm font-medium text-tinta-700 mb-1">Nombre <span class="text-aviso-rojo">*</span></label>
                            <input v-model="nuevaPlantilla.nombre" type="text" class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none" placeholder="Ej: Puerta Frigorífica" @keyup.enter="crearPlantilla" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-tinta-700 mb-1">Descripción</label>
                            <textarea v-model="nuevaPlantilla.descripcion" rows="2" class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none resize-none" placeholder="Descripción opcional..." />
                        </div>
                    </div>
                    <div class="flex gap-3 mt-4">
                        <button @click="modalNuevaPlantilla = false" class="flex-1 py-2.5 rounded-xl border border-linea text-sm text-tinta-500">Cancelar</button>
                        <button @click="crearPlantilla" :disabled="guardando || !nuevaPlantilla.nombre" class="flex-1 py-2.5 rounded-xl text-white text-sm font-semibold disabled:opacity-60" style="background:var(--marca);">
                            {{ guardando ? 'Creando...' : 'Crear' }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- ══ Modal Probar plantilla ════════════════════════════════════════════════ -->
        <Teleport to="body">
            <div v-if="modalProbar" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-4 overflow-y-auto" style="background:rgba(0,0,0,0.5);">
                <div class="bg-superficie rounded-2xl shadow-xl w-full max-w-lg p-5 my-4">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-base font-semibold text-tinta-900">Probar: {{ plantillaActual?.nombre }}</h3>
                        <button @click="modalProbar = false" class="text-tinta-300 hover:text-tinta-500">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>

                    <div class="space-y-3 mb-4">
                        <template v-for="campo in plantillaActual?.campos" :key="campo.id">
                            <div>
                                <label class="block text-xs font-medium text-tinta-700 mb-1">{{ campo.etiqueta }}</label>
                                <select v-if="campo.tipo === 'select'" v-model="probarValores[campo.nombre]"
                                    class="w-full border border-linea rounded-lg px-3 py-1.5 text-sm focus:outline-none">
                                    <option v-for="op in campo.opciones" :key="op.valor" :value="op.valor">{{ op.etiqueta }}</option>
                                </select>
                                <input v-else-if="campo.tipo === 'decimal' || campo.tipo === 'numero'"
                                    v-model.number="probarValores[campo.nombre]" type="number" step="0.01"
                                    class="w-full border border-linea rounded-lg px-3 py-1.5 text-sm focus:outline-none" />
                                <div v-else-if="campo.tipo === 'boolean' || campo.tipo === 'checkbox'" class="flex items-center gap-2">
                                    <input v-model="probarValores[campo.nombre]" type="checkbox" class="rounded" />
                                    <span class="text-xs text-tinta-400">{{ probarValores[campo.nombre] ? 'Sí' : 'No' }}</span>
                                </div>
                                <input v-else v-model="probarValores[campo.nombre]" type="text"
                                    class="w-full border border-linea rounded-lg px-3 py-1.5 text-sm focus:outline-none" />
                            </div>
                        </template>
                    </div>

                    <button @click="ejecutarProbar" :disabled="probarCargando" class="w-full py-2.5 rounded-xl text-sm text-white font-semibold mb-4 disabled:opacity-60" style="background:var(--marca);">
                        {{ probarCargando ? 'Calculando...' : 'Calcular componentes' }}
                    </button>

                    <!-- Resultado -->
                    <div v-if="probarResultado" class="border-t border-linea pt-4">
                        <p class="text-xs font-semibold text-tinta-400 uppercase mb-2">Resultado</p>
                        <table class="w-full text-xs">
                            <thead><tr class="text-left text-tinta-300">
                                <th class="pb-1">Componente</th><th class="pb-1 text-right">Cant.</th><th class="pb-1 text-right">Precio Unit.</th><th class="pb-1 text-right">Subtotal</th>
                            </tr></thead>
                            <tbody class="divide-y divide-separador">
                                <tr v-for="(c, i) in probarResultado.componentes" :key="i" class="hover:bg-tinta-50">
                                    <td class="py-1.5 font-medium text-tinta-700">{{ c.nombre }} <span class="text-tinta-300 font-normal">{{ c.unidad }}</span></td>
                                    <td class="py-1.5 text-right font-mono text-tinta-500">{{ c.cantidad }}</td>
                                    <td class="py-1.5 text-right text-tinta-400">${{ formatCOP(c.precio_unit) }}</td>
                                    <td class="py-1.5 text-right font-semibold text-tinta-900">${{ formatCOP(c.subtotal) }}</td>
                                </tr>
                            </tbody>
                            <tfoot><tr class="border-t border-linea">
                                <td colspan="3" class="pt-2 text-xs font-semibold text-tinta-700 uppercase">Costo total</td>
                                <td class="pt-2 text-right font-semibold text-aviso-azul">${{ formatCOP(probarResultado.total_costo) }}</td>
                            </tr></tfoot>
                        </table>
                        <p v-if="!probarResultado.componentes?.length" class="text-sm text-tinta-300 text-center py-4">Sin componentes para estos valores.</p>
                    </div>
                </div>
            </div>
        </Teleport>

    </AppLayout>
</template>
