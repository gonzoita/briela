<script setup>
// Los porcentajes se guardan con dos decimales: redondearlos al mostrarlos contradecía
// lo que la persona acababa de configurar.
import { formatPct } from '@/formato'
import { ref, reactive, computed, watch, nextTick } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ResultadosBuscadorProducto from '@/Components/ResultadosBuscadorProducto.vue'
import RichTextEditor from '@/Components/RichTextEditor.vue'
import { useClipboard } from '@/composables/useClipboard'

const { copyText } = useClipboard()

const props = defineProps({
    plantillas: { type: Array, default: () => [] },
    productos:  { type: Array, default: () => [] },
})

// ── Estado global ─────────────────────────────────────────────────────────────
const plantillas      = ref(props.plantillas.map(p => ({ ...p })))
const plantillaActual = ref(null)
const tabActivo       = ref('info')
const tabMobile       = ref('plantillas')
const guardando       = ref(false)
const errorGlobal     = ref('')
const busqueda        = ref('')
const filtroActivo    = ref('')

const plantillasFiltradas = computed(() => {
    let list = plantillas.value
    if (busqueda.value.trim()) {
        const q = busqueda.value.toLowerCase()
        list = list.filter(p => p.nombre.toLowerCase().includes(q) || (p.descripcion ?? '').toLowerCase().includes(q))
    }
    if (filtroActivo.value === 'activas')   list = list.filter(p => p.activo)
    if (filtroActivo.value === 'inactivas') list = list.filter(p => !p.activo)
    return list
})

const BASE = '/cotizadores/plantillas'
const csrf    = () => { const c = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN=')); return c ? decodeURIComponent(c.split('=')[1]) : '' }
const jsonHdr = () => ({ 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' })

// Antes esto no se validaba: si la sesión expiraba (419) o fallaba una validación (422),
// fetch no lanza excepción y el JSON de error se trataba como si fuera el registro guardado
// (se empujaba al array local como si todo hubiera salido bien). Por eso se perdía trabajo
// sin ningún aviso. parseRes() revienta con el mensaje real cuando la respuesta no es 2xx.
async function parseRes(res) {
    const data = await res.json().catch(() => null)
    if (!res.ok) {
        const msg = data?.message || (data?.errors ? Object.values(data.errors).flat()[0] : null) || `Error del servidor (${res.status})`
        throw new Error(msg)
    }
    return data
}

// ── Seleccionar plantilla ─────────────────────────────────────────────────────
function seleccionar(p) {
    plantillaActual.value = {
        ...p,
        campos:       [...(p.campos ?? [])],
        componentes:  [...(p.componentes ?? [])],
        secciones:    [...(p.secciones ?? [])],
        pasosTrabajo: [...(p.template_trabajo?.pasos ?? [])],
    }
    templateTrabajoId.value   = p.template_trabajo?.id ?? null
    editandoCampo.value      = null
    editandoComponente.value = null
    pasoActivo.value          = null
    tabActivo.value          = 'info'
    tabMobile.value          = 'editor'
}

// ── CRUD Plantilla ────────────────────────────────────────────────────────────
const modalNueva = ref(false)
const nuevaData  = reactive({ nombre: '', descripcion_corta: '' })

async function crearPlantilla() {
    if (!nuevaData.nombre) return
    guardando.value = true
    try {
        const res  = await fetch(BASE, { method: 'POST', headers: jsonHdr(), body: JSON.stringify(nuevaData) })
        const data = await parseRes(res)
        plantillas.value.push(data)
        seleccionar(data)
        modalNueva.value = false
        Object.assign(nuevaData, { nombre: '', descripcion_corta: '' })
        errorGlobal.value = ''
    } catch (e) { errorGlobal.value = e.message || 'Error al crear plantilla.' }
    finally { guardando.value = false }
}

async function guardarInfo() {
    if (!plantillaActual.value) return
    guardando.value = true
    try {
        const payload = {
            nombre: plantillaActual.value.nombre,
            activo: plantillaActual.value.activo,
        }
        const res  = await fetch(`${BASE}/${plantillaActual.value.id}`, { method: 'PUT', headers: jsonHdr(), body: JSON.stringify(payload) })
        const data = await parseRes(res)
        const idx  = plantillas.value.findIndex(p => p.id === data.id)
        if (idx >= 0) plantillas.value[idx] = { ...plantillas.value[idx], ...data }
        errorGlobal.value = ''
    } catch (e) { errorGlobal.value = e.message || 'Error al guardar.' }
    finally { guardando.value = false }
}

async function guardarConfigSalida() {
    if (!plantillaActual.value) return
    guardando.value = true
    try {
        const res = await fetch(`${BASE}/${plantillaActual.value.id}`, {
            method: 'PUT', headers: jsonHdr(),
            body: JSON.stringify({ nombre: plantillaActual.value.nombre, config_salida: plantillaActual.value.config_salida }),
        })
        await parseRes(res)
        errorGlobal.value = ''
    } catch (e) { errorGlobal.value = e.message || 'Error al guardar configuración.' }
    finally { guardando.value = false }
}

async function eliminarPlantilla(p) {
    if (!confirm(`¿Eliminar la plantilla "${p.nombre}"?`)) return
    await fetch(`${BASE}/${p.id}`, { method: 'DELETE', headers: jsonHdr() })
    plantillas.value = plantillas.value.filter(x => x.id !== p.id)
    if (plantillaActual.value?.id === p.id) { plantillaActual.value = null; tabMobile.value = 'plantillas' }
}

async function duplicarPlantilla(p) {
    if (!confirm(`¿Duplicar la plantilla "${p.nombre}"? Se creará una copia inactiva.`)) return
    try {
        const res  = await fetch(`${BASE}/${p.id}/duplicar`, { method: 'POST', headers: jsonHdr() })
        const data = await parseRes(res)
        plantillas.value.unshift(data)
        seleccionar(data)
        errorGlobal.value = ''
    } catch (e) { errorGlobal.value = e.message || 'Error al duplicar plantilla.' }
}

// ── Config. de salida por defecto ─────────────────────────────────────────────
function ensureConfigSalida() {
    if (!plantillaActual.value.config_salida) {
        plantillaActual.value.config_salida = {
            mostrar_desglose: false,
            mostrar_precio_costo: false,
            etiqueta_precio: 'Precio base desde',
            nota_pie: '',
            permitir_editar_precio: true,
        }
    }
}

watch(() => plantillaActual.value, (v) => { if (v) ensureConfigSalida() })

// ── CRUD Campos ───────────────────────────────────────────────────────────────
const editandoCampo = ref(null)
const campoBorrador = reactive({
    nombre: '', etiqueta: '', tipo: 'decimal', tipo_campo: 'entrada', formula_calculo: '',
    subtipo_variable: 'numero', opciones_selector: [],
    valor_defecto: '', placeholder: '', ayuda: '', requerido: true, opciones: [],
    imagen_referencia: null, imagen_referencia_titulo: null,
})

const TIPOS_CAMPO = [
    { value: 'texto',    label: 'Texto' },
    { value: 'numero',   label: 'Número' },
    { value: 'decimal',  label: 'Decimal' },
    { value: 'select',   label: 'Selector' },
    { value: 'boolean',  label: 'Verdadero/Falso' },
    { value: 'checkbox', label: 'Checkbox' },
]

watch(() => campoBorrador.etiqueta, (v) => {
    if (editandoCampo.value === 'nuevo' && campoBorrador.tipo_campo === 'entrada') {
        campoBorrador.nombre = v.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '')
    }
})

function abrirNuevoCampo(tc = 'entrada') {
    Object.assign(campoBorrador, { nombre: '', etiqueta: '', tipo: 'decimal', tipo_campo: tc, formula_calculo: '', subtipo_variable: 'numero', opciones_selector: [], valor_defecto: '', placeholder: '', ayuda: '', requerido: true, opciones: [], imagen_referencia: null, imagen_referencia_titulo: null })
    editandoCampo.value    = 'nuevo'
    formulaCalcQuery.value = ''
}
function abrirEditarCampo(c) {
    Object.assign(campoBorrador, { ...c, tipo_campo: c.tipo_campo ?? 'entrada', formula_calculo: c.formula_calculo ?? '', subtipo_variable: c.subtipo_variable ?? 'numero', opciones_selector: c.opciones_selector ? [...c.opciones_selector] : [], opciones: c.opciones ? [...c.opciones] : [] })
    editandoCampo.value    = c.id
    formulaCalcQuery.value = ''
}
function cancelarCampo() { editandoCampo.value = null; formulaCalcQuery.value = '' }
function agregarOpcion() { campoBorrador.opciones.push({ valor: '', etiqueta: '' }) }
function quitarOpcion(i) { campoBorrador.opciones.splice(i, 1) }

async function moverCampo(idx, dir) {
    const ni = idx + dir
    if (ni < 0 || ni >= plantillaActual.value.campos.length) return
    const arr = [...plantillaActual.value.campos]
    ;[arr[idx], arr[ni]] = [arr[ni], arr[idx]]
    plantillaActual.value.campos = arr
    await fetch(`${BASE}/${plantillaActual.value.id}/campos/reordenar`, {
        method: 'POST', headers: jsonHdr(),
        body: JSON.stringify({ ids: arr.map(c => c.id) }),
    }).catch(() => {})
}

async function guardarCampo() {
    const esCalc      = campoBorrador.tipo_campo === 'calculado'
    const esInstancia = campoBorrador.tipo_campo === 'variable_instancia'
    if (!plantillaActual.value || !campoBorrador.nombre) return
    if (!esCalc && !esInstancia && !campoBorrador.etiqueta) return
    if (esCalc && !campoBorrador.formula_calculo) return
    guardando.value = true
    try {
        const esNuevo = editandoCampo.value === 'nuevo'
        const url = esNuevo
            ? `${BASE}/${plantillaActual.value.id}/campos`
            : `${BASE}/${plantillaActual.value.id}/campos/${editandoCampo.value}`
        const res  = await fetch(url, { method: esNuevo ? 'POST' : 'PUT', headers: jsonHdr(), body: JSON.stringify(campoBorrador) })
        const data = await parseRes(res)
        if (esNuevo) {
            plantillaActual.value.campos.push(data)
        } else {
            const idx = plantillaActual.value.campos.findIndex(c => c.id === data.id)
            if (idx >= 0) plantillaActual.value.campos[idx] = data
        }
        editandoCampo.value    = null
        formulaCalcQuery.value = ''
        errorGlobal.value      = ''
    } catch (e) { errorGlobal.value = e.message || 'Error al guardar campo.' }
    finally { guardando.value = false }
}

async function eliminarCampo(c) {
    if (!confirm(`¿Eliminar el campo "${c.etiqueta || c.nombre}"?`)) return
    await fetch(`${BASE}/${plantillaActual.value.id}/campos/${c.id}`, { method: 'DELETE', headers: jsonHdr() })
    plantillaActual.value.campos = plantillaActual.value.campos.filter(x => x.id !== c.id)
}

// ── Imagen de referencia en campo ─────────────────────────────────────────────
const multipartHdr = () => ({ 'X-XSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' })

async function subirImagenReferencia(event) {
    const file = event.target.files?.[0]
    if (!file || editandoCampo.value === 'nuevo') return
    const fd = new FormData()
    fd.append('imagen', file)
    if (campoBorrador.imagen_referencia_titulo) fd.append('titulo', campoBorrador.imagen_referencia_titulo)
    try {
        const res  = await fetch(`${BASE}/${plantillaActual.value.id}/campos/${editandoCampo.value}/imagen-referencia`, { method: 'POST', headers: multipartHdr(), body: fd })
        const data = await parseRes(res)
        campoBorrador.imagen_referencia        = data.imagen_referencia
        campoBorrador.imagen_referencia_titulo = data.imagen_referencia_titulo
        const idx = plantillaActual.value.campos.findIndex(c => c.id === editandoCampo.value)
        if (idx >= 0) Object.assign(plantillaActual.value.campos[idx], { imagen_referencia: data.imagen_referencia, imagen_referencia_titulo: data.imagen_referencia_titulo })
        errorGlobal.value = ''
    } catch (e) { errorGlobal.value = e.message || 'Error al subir imagen de referencia.' }
    event.target.value = ''
}


async function eliminarImagenReferenciaCampo() {
    if (!confirm('¿Eliminar la imagen de referencia de este campo?')) return
    await fetch(`${BASE}/${plantillaActual.value.id}/campos/${editandoCampo.value}/imagen-referencia`, { method: 'DELETE', headers: multipartHdr() })
    campoBorrador.imagen_referencia        = null
    campoBorrador.imagen_referencia_titulo = null
    const idx = plantillaActual.value.campos.findIndex(c => c.id === editandoCampo.value)
    if (idx >= 0) Object.assign(plantillaActual.value.campos[idx], { imagen_referencia: null, imagen_referencia_titulo: null })
}

async function subirImagenOpcionSelector(event, opcionIndex) {
    const file = event.target.files?.[0]
    if (!file || editandoCampo.value === 'nuevo') return
    const fd = new FormData()
    fd.append('imagen', file)
    fd.append('index', String(opcionIndex))
    try {
        const res  = await fetch(`${BASE}/${plantillaActual.value.id}/campos/${editandoCampo.value}/opcion-selector-imagen`, { method: 'POST', headers: multipartHdr(), body: fd })
        const data = await parseRes(res)
        campoBorrador.opciones_selector = data.opciones_selector
        errorGlobal.value = ''
    } catch (e) { errorGlobal.value = e.message || 'Error al subir imagen de opción.' }
    event.target.value = ''
}

async function eliminarImagenOpcionSelector(opcionIndex) {
    if (!confirm('¿Quitar la imagen de esta opción?')) return
    const fd = new FormData()
    fd.append('index', String(opcionIndex))
    try {
        const res  = await fetch(`${BASE}/${plantillaActual.value.id}/campos/${editandoCampo.value}/opcion-selector-imagen`, { method: 'DELETE', headers: { ...multipartHdr(), 'Content-Type': 'application/json' }, body: JSON.stringify({ index: opcionIndex }) })
        const data = await parseRes(res)
        campoBorrador.opciones_selector = data.opciones_selector
        errorGlobal.value = ''
    } catch (e) { errorGlobal.value = e.message || 'Error al eliminar imagen de opción.' }
}

// ── CRUD Componentes ──────────────────────────────────────────────────────────
const editandoComponente  = ref(null)
const compErrorProducto   = ref('')
const compBorrador = reactive({
    producto_id: null, etiqueta: '', formula: '', formula_real: '', sub_formulas: [], condicion: '',
    unidad: '', incluir_en_precio: true, visible_cliente: false, visible_op: true, notas: '', activo: true,
    seccion_id: null,
})
const busqProducto = ref('')
const resBusqProd  = ref([])
let   timerBusq    = null

watch(busqProducto, (v) => {
    clearTimeout(timerBusq)
    if (!v || v.length < 2) { resBusqProd.value = []; return }
    timerBusq = setTimeout(async () => {
        try {
            const res = await fetch(`/api/productos/buscar?q=${encodeURIComponent(v)}`)
            resBusqProd.value = await res.json()
        } catch { resBusqProd.value = [] }
    }, 300)
})

function elegirProducto(prod) {
    compBorrador.producto_id = prod.id
    busqProducto.value       = prod.nombre
    if (!compBorrador.etiqueta) compBorrador.etiqueta = prod.nombre
    if (!compBorrador.unidad)   compBorrador.unidad   = prod.unidad_medida ?? ''
    resBusqProd.value = []
}

function abrirNuevoComponente() {
    Object.assign(compBorrador, { producto_id: null, etiqueta: '', formula: '', formula_real: '', sub_formulas: [], condicion: '', unidad: '', incluir_en_precio: true, visible_cliente: false, visible_op: true, notas: '', activo: true, seccion_id: null })
    busqProducto.value       = ''
    compErrorProducto.value  = ''
    editandoComponente.value = 'nuevo'
    formulaQuery.value       = ''
    inicializarProbarVals()
}
function abrirEditarComponente(c) {
    Object.assign(compBorrador, { ...c, seccion_id: c.seccion_id ?? null, sub_formulas: Array.isArray(c.sub_formulas) ? c.sub_formulas.map(s => ({ ...s, _probando: false, _resultado: null, _error: null, _probando_real: false, _resultado_real: null, _error_real: null })) : [] })
    busqProducto.value       = c.producto?.nombre ?? (c.etiqueta ?? '')
    compErrorProducto.value  = ''
    editandoComponente.value = c.id
    formulaQuery.value       = ''
    inicializarProbarVals()
}
function cancelarComponente() { editandoComponente.value = null; formulaQuery.value = '' }

async function moverComponente(idx, dir) {
    const ni = idx + dir
    if (ni < 0 || ni >= plantillaActual.value.componentes.length) return
    const arr = [...plantillaActual.value.componentes]
    ;[arr[idx], arr[ni]] = [arr[ni], arr[idx]]
    plantillaActual.value.componentes = arr
    await fetch(`${BASE}/${plantillaActual.value.id}/componentes/reordenar`, {
        method: 'POST', headers: jsonHdr(),
        body: JSON.stringify({ ids: arr.map(c => c.id) }),
    }).catch(() => {})
}

function limpiarSubFormulasParaGuardar(subFormulas) {
    return (subFormulas ?? []).map(({ id, etiqueta, formula, formula_real, unidad }) => ({
        id, etiqueta, formula, formula_real, unidad
    }))
}

function agregarSubFormula(comp) {
    comp.sub_formulas.push({
        id: crypto.randomUUID(),
        etiqueta: '', formula: '', formula_real: '', unidad: '',
        _probando: false, _resultado: null, _error: null,
        _probando_real: false, _resultado_real: null, _error_real: null,
    })
}

function eliminarSubFormula(comp, index) {
    if (confirm('¿Eliminar esta sub-fórmula?')) comp.sub_formulas.splice(index, 1)
}

async function probarSubFormula(sub, campo = 'formula') {
    const formulaKey  = campo === 'real' ? 'formula_real' : 'formula'
    const probandoKey = campo === 'real' ? '_probando_real' : '_probando'
    const resultKey   = campo === 'real' ? '_resultado_real' : '_resultado'
    const errorKey    = campo === 'real' ? '_error_real' : '_error'

    sub[probandoKey] = true
    sub[resultKey]   = null
    sub[errorKey]    = null

    try {
        const res = await fetch(`${BASE}/${plantillaActual.value.id}/componentes/probar-subformula`, {
            method: 'POST', headers: jsonHdr(),
            body: JSON.stringify({ formula: sub[formulaKey], variables: probarFVals.value }),
        })
        const data = await res.json()
        sub[resultKey] = data.resultado
        sub[errorKey]  = data.error
    } catch (e) {
        sub[errorKey] = e.message
    } finally {
        sub[probandoKey] = false
    }
}

async function guardarComponente() {
    if (!plantillaActual.value || !compBorrador.formula) return
    if (!compBorrador.producto_id) {
        compErrorProducto.value = 'Debes vincular un producto para calcular el costo.'
        return
    }
    compErrorProducto.value = ''
    guardando.value = true
    try {
        const esNuevo = editandoComponente.value === 'nuevo'
        const url = esNuevo
            ? `${BASE}/${plantillaActual.value.id}/componentes`
            : `${BASE}/${plantillaActual.value.id}/componentes/${editandoComponente.value}`
        const payload = {
            ...compBorrador,
            formula:      normalizarFormula(compBorrador.formula),
            sub_formulas: limpiarSubFormulasParaGuardar(compBorrador.sub_formulas),
        }
        const res  = await fetch(url, { method: esNuevo ? 'POST' : 'PUT', headers: jsonHdr(), body: JSON.stringify(payload) })
        const data = await parseRes(res)
        if (esNuevo) {
            plantillaActual.value.componentes.push(data)
        } else {
            const idx = plantillaActual.value.componentes.findIndex(c => c.id === data.id)
            if (idx >= 0) plantillaActual.value.componentes[idx] = data
        }
        editandoComponente.value = null
        errorGlobal.value        = ''
    } catch (e) { errorGlobal.value = e.message || 'Error al guardar componente.' }
    finally { guardando.value = false }
}

async function eliminarComponente(c) {
    if (!confirm(`¿Eliminar "${c.etiqueta || c.formula}"?`)) return
    await fetch(`${BASE}/${plantillaActual.value.id}/componentes/${c.id}`, { method: 'DELETE', headers: jsonHdr() })
    plantillaActual.value.componentes = plantillaActual.value.componentes.filter(x => x.id !== c.id)
}

// ── Variables disponibles ─────────────────────────────────────────────────────
const camposEntrada           = computed(() => (plantillaActual.value?.campos ?? []).filter(c => !['calculado', 'variable_instancia'].includes(c.tipo_campo ?? 'entrada')))
const camposCalculados        = computed(() => (plantillaActual.value?.campos ?? []).filter(c => c.tipo_campo === 'calculado'))
const camposVariableInstancia = computed(() => (plantillaActual.value?.campos ?? []).filter(c => c.tipo_campo === 'variable_instancia'))
const camposParaProbar        = computed(() => (plantillaActual.value?.campos ?? []).filter(c => c.tipo_campo !== 'calculado'))

const variablesDisponibles = computed(() =>
    (plantillaActual.value?.campos ?? []).map(c => c.nombre).filter(Boolean)
)

const seccionesOrdenadas = computed(() =>
    [...(plantillaActual.value?.secciones ?? [])].sort((a, b) => (a.orden ?? 0) - (b.orden ?? 0))
)

function componentesDeSeccion(secId) {
    return (plantillaActual.value?.componentes ?? [])
        .filter(c => c.seccion_id === secId)
        .sort((a, b) => (a.orden ?? 0) - (b.orden ?? 0))
}

const componentesSinSeccion = computed(() =>
    (plantillaActual.value?.componentes ?? []).filter(c => !c.seccion_id)
)

// ── Autocomplete para formula_calculo de campo calculado ──────────────────────
const formulaCalcInputEl = ref(null)
const formulaCalcQuery   = ref('')

const formulaCalcSugs = computed(() => {
    if (!formulaCalcQuery.value) return []
    const q = formulaCalcQuery.value.toLowerCase()
    return variablesDisponibles.value.filter(v => v.toLowerCase().includes(q) && v !== formulaCalcQuery.value).slice(0, 8)
})

function onFormulaCalcInput(e) {
    const pos    = e.target.selectionStart
    const before = e.target.value.slice(0, pos)
    const match  = before.match(/[a-z_][a-z0-9_]*$/i)
    formulaCalcQuery.value = match ? match[0] : ''
}
function onFormulaCalcBlur() { setTimeout(() => { formulaCalcQuery.value = '' }, 200) }
function insertarVariableCalc(v) {
    const el = formulaCalcInputEl.value
    if (!el) { campoBorrador.formula_calculo += v; formulaCalcQuery.value = ''; return }
    const start  = el.selectionStart
    const val    = el.value
    const before = val.slice(0, start)
    const after  = val.slice(el.selectionEnd)
    const match  = before.match(/[a-z_][a-z0-9_]*$/i)
    const rs     = match ? start - match[0].length : start
    campoBorrador.formula_calculo = val.slice(0, rs) + v + after
    formulaCalcQuery.value = ''
    nextTick(() => { el.focus(); const p = rs + v.length; el.setSelectionRange(p, p) })
}

// ── Preview en vivo de la variable calculada ──────────────────────────────────
const previewCalculo = computed(() => {
    const expr = campoBorrador.formula_calculo
    if (!expr || campoBorrador.tipo_campo !== 'calculado') return null
    const sv = {}
    for (const c of (plantillaActual.value?.campos ?? []).filter(c => c.tipo_campo !== 'calculado')) {
        sv[c.nombre] = parseFloat(c.valor_defecto ?? 0) || 0
    }
    try {
        const result = new Function(...Object.keys(sv), '"use strict"; return (' + expr + ')').call(null, ...Object.values(sv))
        return { ok: true, valor: typeof result === 'number' ? result.toFixed(4) : String(result) }
    } catch (e) {
        return { ok: false, error: e.message }
    }
})

// ── Enviar variable calculada a tab Componentes ───────────────────────────────
function enviarAComponentes(campo) {
    abrirNuevoComponente()
    compBorrador.formula  = campo.nombre
    compBorrador.etiqueta = campo.etiqueta || campo.nombre
    tabActivo.value       = 'componentes'
}

// ── Chips de variables (insertar en fórmula activa o copiar) ─────────────────
const chipCopiado  = ref('')
const formulaActiva = ref(false)

function copiarVariable(nombre) {
    const inputFormula = document.querySelector('.input-formula-activa')

    if (inputFormula) {
        const start      = inputFormula.selectionStart
        const end        = inputFormula.selectionEnd
        const valorActual = inputFormula.value
        const nuevoValor  = valorActual.substring(0, start) + nombre + valorActual.substring(end)

        inputFormula.value = nuevoValor
        inputFormula.dispatchEvent(new Event('input'))

        nextTick(() => {
            inputFormula.selectionStart = start + nombre.length
            inputFormula.selectionEnd   = start + nombre.length
            inputFormula.focus()
        })
    } else {
        copyText(nombre)
    }
    chipCopiado.value = nombre
    setTimeout(() => { if (chipCopiado.value === nombre) chipCopiado.value = '' }, 1500)
}

// ── Ayuda/manual de componentes ───────────────────────────────────────────────
const ayudaVisible = ref(localStorage.getItem('plantilla_ayuda_componentes') === 'true')
watch(ayudaVisible, val => localStorage.setItem('plantilla_ayuda_componentes', String(val)))

const seccionesColapsadas = reactive({})
function toggleSeccion(key) { seccionesColapsadas[key] = !seccionesColapsadas[key] }
const varsMobileOpen = ref(false)

// ── Drag & drop: componentes entre secciones ──────────────────────────────────
const dragCompId    = ref(null)
const dropCompTarget = ref(undefined)

function onDragStartComp(e, comp) {
    dragCompId.value = comp.id
    e.dataTransfer.effectAllowed = 'move'
}

async function onDropComp(e, secId) {
    if (dragCompId.value === null) return
    const compId = dragCompId.value
    dragCompId.value     = null
    dropCompTarget.value = undefined
    await moverComponenteASeccion(compId, secId)
}

async function moverComponenteASeccion(compId, secId) {
    const comp = plantillaActual.value.componentes.find(c => c.id === compId)
    if (!comp || comp.seccion_id === secId) return
    const prevId = comp.seccion_id
    comp.seccion_id = secId
    try {
        await fetch(`${BASE}/${plantillaActual.value.id}/componentes/${compId}/mover`, {
            method: 'PATCH', headers: jsonHdr(),
            body: JSON.stringify({ seccion_id: secId, orden: comp.orden ?? 0 }),
        })
    } catch { comp.seccion_id = prevId }
}

// ── Drag & drop: reordenar secciones ─────────────────────────────────────────
const dragSecId     = ref(null)
const dropSecTarget = ref(null)

function onDragStartSec(e, sec) {
    dragSecId.value = sec.id
    e.dataTransfer.effectAllowed = 'move'
}

function onDropSec(e, targetSec) {
    if (!dragSecId.value || dragSecId.value === targetSec.id) {
        dragSecId.value = null; dropSecTarget.value = null; return
    }
    const secs = [...(plantillaActual.value?.secciones ?? [])].sort((a, b) => (a.orden ?? 0) - (b.orden ?? 0))
    const fromIdx = secs.findIndex(s => s.id === dragSecId.value)
    const toIdx   = secs.findIndex(s => s.id === targetSec.id)
    if (fromIdx < 0 || toIdx < 0) { dragSecId.value = null; dropSecTarget.value = null; return }
    const [moved] = secs.splice(fromIdx, 1)
    secs.splice(toIdx, 0, moved)
    secs.forEach((s, i) => { s.orden = i })
    plantillaActual.value.secciones = secs
    reordenarSecciones(secs.map(s => s.id))
    dragSecId.value = null; dropSecTarget.value = null
}

async function reordenarSecciones(ids) {
    try {
        await fetch(`${BASE}/${plantillaActual.value.id}/secciones/reordenar`, {
            method: 'PATCH', headers: jsonHdr(),
            body: JSON.stringify({ ids }),
        })
    } catch {}
}

// ── CRUD Secciones ────────────────────────────────────────────────────────────
const showNuevaSec    = ref(false)
const nuevaSecNombre  = ref('')
const creandoSeccion  = ref(false)
const renombrandoSecId  = ref(null)
const renombrandoNombre = ref('')

async function crearSeccion() {
    if (!nuevaSecNombre.value.trim() || !plantillaActual.value) return
    creandoSeccion.value = true
    try {
        const res  = await fetch(`${BASE}/${plantillaActual.value.id}/secciones`, {
            method: 'POST', headers: jsonHdr(),
            body: JSON.stringify({ nombre: nuevaSecNombre.value.trim() }),
        })
        const data = await parseRes(res)
        plantillaActual.value.secciones.push(data)
        nuevaSecNombre.value = ''
        showNuevaSec.value   = false
        errorGlobal.value    = ''
    } catch (e) { errorGlobal.value = e.message || 'Error al crear sección.' }
    finally { creandoSeccion.value = false }
}

function iniciarRenombrar(sec) {
    renombrandoSecId.value  = sec.id
    renombrandoNombre.value = sec.nombre
}

async function confirmarRenombrar(sec) {
    const nombre = renombrandoNombre.value.trim()
    renombrandoSecId.value = null
    if (!nombre || nombre === sec.nombre) return
    const prev  = sec.nombre
    sec.nombre  = nombre
    try {
        await fetch(`${BASE}/${plantillaActual.value.id}/secciones/${sec.id}`, {
            method: 'PATCH', headers: jsonHdr(),
            body: JSON.stringify({ nombre }),
        })
    } catch { sec.nombre = prev }
}

async function eliminarSeccion(sec) {
    if (!confirm(`¿Eliminar la sección "${sec.nombre}"? Los componentes quedarán sin sección.`)) return
    await fetch(`${BASE}/${plantillaActual.value.id}/secciones/${sec.id}`, {
        method: 'DELETE', headers: jsonHdr(),
    }).catch(() => {})
    plantillaActual.value.componentes.forEach(c => { if (c.seccion_id === sec.id) c.seccion_id = null })
    plantillaActual.value.secciones = plantillaActual.value.secciones.filter(s => s.id !== sec.id)
}

// ── Pasos de producción (fusión con Plantillas de Trabajo) ────────────────────
// Cada plantilla de ensamble tiene emparejado 1 a 1 su propio flujo de
// producción — no hay que crear ni elegir ninguna "plantilla de trabajo"
// aparte. El peso de cada paso se calcula solo a partir de la dificultad
// (mismo criterio que usaba el editor viejo de /produccion/templates), nadie
// tiene que cuadrar porcentajes a mano. Los cambios se guardan todos juntos
// con el botón "Guardar pasos" — igual que hacía el editor viejo.
const templateTrabajoId = ref(null)
const pasoActivo        = ref(null) // índice del paso seleccionado, o null
const guardandoPasos    = ref(false)
const subiendoAdjunto   = ref(null) // 'imagen' | 'plano' | null mientras sube

const labelDificultad = ['', 'Fácil', 'Normal', 'Moderado', 'Difícil', 'Muy difícil']
const colorDificultad = ['', 'text-green-600', 'text-lime-500', 'text-yellow-500', 'text-orange-500', 'text-red-500']

const sumaPesosPasos = computed(() =>
    (plantillaActual.value?.pasosTrabajo ?? []).reduce((s, p) => s + (Number(p.peso_porcentaje) || 0), 0)
)

function recalcularPesosProduccion() {
    const pasos = plantillaActual.value?.pasosTrabajo ?? []
    const totalDificultad = pasos.reduce((s, p) => s + (p.nivel_dificultad || 1), 0)
    if (!pasos.length || totalDificultad === 0) return
    pasos.forEach(p => {
        p.peso_porcentaje = parseFloat(((p.nivel_dificultad || 1) / totalDificultad * 100).toFixed(2))
    })
    const sumaActual = pasos.reduce((s, p) => s + p.peso_porcentaje, 0)
    const diff = parseFloat((100 - sumaActual).toFixed(2))
    pasos[pasos.length - 1].peso_porcentaje = parseFloat((pasos[pasos.length - 1].peso_porcentaje + diff).toFixed(2))
}

function agregarPasoProduccion() {
    if (!plantillaActual.value.pasosTrabajo) plantillaActual.value.pasosTrabajo = []
    plantillaActual.value.pasosTrabajo.push({
        nombre: '', objetivo: '', descripcion: '', peso_porcentaje: 0,
        orden: plantillaActual.value.pasosTrabajo.length, nivel_dificultad: 2,
        depende_de: [], es_paso_final: false, imagen: null, archivo_plano: null,
    })
    recalcularPesosProduccion()
    pasoActivo.value = plantillaActual.value.pasosTrabajo.length - 1
}

function quitarPasoProduccion(idx) {
    const pasos = plantillaActual.value.pasosTrabajo
    pasos.splice(idx, 1)
    pasos.forEach((p, i) => {
        p.orden = i
        p.depende_de = (p.depende_de ?? []).filter(d => d !== idx).map(d => d > idx ? d - 1 : d)
    })
    recalcularPesosProduccion()
    if (pasoActivo.value === idx) pasoActivo.value = null
    else if (pasoActivo.value > idx) pasoActivo.value -= 1
}

function subirPasoProduccion(idx) {
    if (idx === 0) return
    const pasos = plantillaActual.value.pasosTrabajo
    ;[pasos[idx - 1], pasos[idx]] = [pasos[idx], pasos[idx - 1]]
    pasos.forEach((p, i) => {
        p.orden = i
        p.depende_de = (p.depende_de ?? []).map(d => d === idx - 1 ? idx : d === idx ? idx - 1 : d)
    })
    if (pasoActivo.value === idx) pasoActivo.value = idx - 1
    else if (pasoActivo.value === idx - 1) pasoActivo.value = idx
}

function bajarPasoProduccion(idx) {
    const pasos = plantillaActual.value.pasosTrabajo
    if (idx === pasos.length - 1) return
    ;[pasos[idx], pasos[idx + 1]] = [pasos[idx + 1], pasos[idx]]
    pasos.forEach((p, i) => {
        p.orden = i
        p.depende_de = (p.depende_de ?? []).map(d => d === idx ? idx + 1 : d === idx + 1 ? idx : d)
    })
    if (pasoActivo.value === idx) pasoActivo.value = idx + 1
    else if (pasoActivo.value === idx + 1) pasoActivo.value = idx
}

function marcarPasoFinalProduccion(idx) {
    const pasos = plantillaActual.value.pasosTrabajo
    if (pasos[idx].es_paso_final) pasos.forEach((p, i) => { if (i !== idx) p.es_paso_final = false })
}

function urlAdjunto(ruta) { return ruta ? `/storage/${ruta}` : null }

async function subirAdjuntoPasoProduccion(idx, tipo, file) {
    if (!file) return
    subiendoAdjunto.value = tipo
    try {
        const fd = new FormData()
        fd.append('tipo', tipo)
        fd.append('archivo', file)
        const res = await fetch(`${BASE}/${plantillaActual.value.id}/pasos-trabajo/adjunto`, {
            method: 'POST', headers: multipartHdr(), body: fd,
        })
        const data  = await parseRes(res)
        const campo = tipo === 'imagen' ? 'imagen' : 'archivo_plano'
        plantillaActual.value.pasosTrabajo[idx][campo] = data.ruta
        errorGlobal.value = ''
    } catch (e) { errorGlobal.value = e.message || 'Error al subir el archivo.' }
    finally { subiendoAdjunto.value = null }
}

async function guardarPasosProduccion() {
    if (!plantillaActual.value) return
    guardandoPasos.value = true
    try {
        const payload = (plantillaActual.value.pasosTrabajo ?? []).map((p, idx) => ({
            nombre: p.nombre,
            objetivo: p.objetivo ?? '',
            descripcion: p.descripcion ?? '',
            peso_porcentaje: p.peso_porcentaje,
            orden: idx,
            nivel_dificultad: p.nivel_dificultad ?? 1,
            depende_de: p.depende_de ?? [],
            es_paso_final: !!p.es_paso_final,
            imagen: p.imagen ?? null,
            archivo_plano: p.archivo_plano ?? null,
        }))
        const res  = await fetch(`${BASE}/${plantillaActual.value.id}/pasos-trabajo`, {
            method: 'POST', headers: jsonHdr(), body: JSON.stringify({ pasos: payload }),
        })
        const data = await parseRes(res)
        templateTrabajoId.value            = data.template_id
        plantillaActual.value.pasosTrabajo = data.pasos
        errorGlobal.value = ''
    } catch (e) { errorGlobal.value = e.message || 'Error al guardar pasos de producción.' }
    finally { guardandoPasos.value = false }
}

// ── Normalizar fórmula antes de guardar ───────────────────────────────────────
function normalizarFormula(formula) {
    return formula.split('\n').map(l => l.trim()).filter(l => l.length > 0).join(' ').replace(/\s+/g, ' ').trim()
}

// ── Sugerencias de fórmula ────────────────────────────────────────────────────
const formulaInputEl = ref(null)
const formulaQuery   = ref('')

const formulaSugs = computed(() => {
    if (!formulaQuery.value) return []
    const q = formulaQuery.value.toLowerCase()
    return variablesDisponibles.value.filter(v => v.toLowerCase().includes(q) && v !== formulaQuery.value).slice(0, 8)
})

function onFormulaInput(e) {
    const pos    = e.target.selectionStart
    const before = e.target.value.slice(0, pos)
    const match  = before.match(/[a-z_][a-z0-9_]*$/i)
    formulaQuery.value = match ? match[0] : ''
}
function onFormulaBlur() {
    setTimeout(() => { formulaQuery.value = '' }, 200)
    setTimeout(() => { formulaActiva.value = false }, 250)
}
function insertarVariable(v) {
    const el = formulaInputEl.value
    if (!el) { compBorrador.formula += v; formulaQuery.value = ''; return }
    const start  = el.selectionStart
    const val    = el.value
    const before = val.slice(0, start)
    const after  = val.slice(el.selectionEnd)
    const match  = before.match(/[a-z_][a-z0-9_]*$/i)
    const rs     = match ? start - match[0].length : start
    compBorrador.formula = val.slice(0, rs) + v + after
    formulaQuery.value   = ''
    nextTick(() => { el.focus(); const p = rs + v.length; el.setSelectionRange(p, p) })
}

// ── Probar plantilla (drawer) ─────────────────────────────────────────────────
const drawerProbar    = ref(false)
const probarValores   = ref({})
const probarResultado = ref(null)
const probarCargando  = ref(false)

const probarFVals = ref({})
const probarFRes     = ref(null)
const probarFResReal = ref(null)
const probarFCarg    = ref(false)

function abrirProbar() {
    if (!plantillaActual.value) return
    const vals = {}
    for (const c of (plantillaActual.value.campos ?? []).filter(c => (c.tipo_campo ?? 'entrada') !== 'calculado')) {
        if (c.tipo === 'checkbox' || c.tipo === 'boolean') vals[c.nombre] = false
        else if (c.subtipo_variable === 'selector') vals[c.nombre] = c.valor_defecto ?? c.opciones_selector?.[0]?.valor ?? ''
        else if (c.tipo === 'select') vals[c.nombre] = c.valor_defecto ?? c.opciones?.[0]?.valor ?? ''
        else if (c.tipo === 'decimal' || c.tipo === 'numero' || c.subtipo_variable === 'decimal' || c.subtipo_variable === 'numero') vals[c.nombre] = parseFloat(c.valor_defecto ?? 0) || 0
        else vals[c.nombre] = c.valor_defecto ?? ''
    }
    probarValores.value   = vals
    probarResultado.value = null
    drawerProbar.value    = true
}

async function ejecutarProbar() {
    probarCargando.value = true
    try {
        const res  = await fetch(`${BASE}/probar`, {
            method: 'POST', headers: jsonHdr(),
            body: JSON.stringify({ plantilla_id: plantillaActual.value.id, valores: probarValores.value }),
        })
        probarResultado.value = await res.json()
    } catch { errorGlobal.value = 'Error al probar.' }
    finally { probarCargando.value = false }
}

function inicializarProbarVals() {
    const vals = {}
    for (const c of (plantillaActual.value?.campos ?? []).filter(c => c.tipo_campo !== 'calculado')) {
        if (c.tipo === 'boolean' || c.tipo === 'checkbox') vals[c.nombre] = false
        else if (c.subtipo_variable === 'selector') vals[c.nombre] = c.valor_defecto ?? c.opciones_selector?.[0]?.valor ?? ''
        else if (c.tipo === 'select') vals[c.nombre] = c.valor_defecto ?? c.opciones?.[0]?.valor ?? ''
        else if (['decimal','numero'].includes(c.tipo) || ['decimal','numero'].includes(c.subtipo_variable))
            vals[c.nombre] = parseFloat(c.valor_defecto ?? 0) || 0
        else vals[c.nombre] = c.valor_defecto ?? ''
    }
    probarFVals.value = vals
    probarFRes.value     = null
    probarFResReal.value = null
}

async function ejecutarProbarFormula() {
    if (!plantillaActual.value || !compBorrador.formula) return
    probarFCarg.value    = true
    probarFRes.value     = null
    probarFResReal.value = null
    try {
        const llamadas = [
            fetch(`${BASE}/${plantillaActual.value.id}/probar-formula`, {
                method: 'POST', headers: jsonHdr(),
                body: JSON.stringify({ formula: normalizarFormula(compBorrador.formula), valores: probarFVals.value }),
            }).then(r => r.json()),
        ]
        if (compBorrador.formula_real) {
            llamadas.push(
                fetch(`${BASE}/${plantillaActual.value.id}/probar-formula`, {
                    method: 'POST', headers: jsonHdr(),
                    body: JSON.stringify({ formula: normalizarFormula(compBorrador.formula_real), valores: probarFVals.value }),
                }).then(r => r.json())
            )
        }
        const [resMain, resReal] = await Promise.all(llamadas)
        probarFRes.value     = resMain
        probarFResReal.value = resReal ?? null
    } catch {
        probarFRes.value = { resultado: null, error: 'Error de conexión.' }
    } finally {
        probarFCarg.value = false
    }
    const promesas = []
    for (const sub of compBorrador.sub_formulas ?? []) {
        if (sub.formula)      promesas.push(probarSubFormula(sub, 'formula'))
        if (sub.formula_real) promesas.push(probarSubFormula(sub, 'real'))
    }
    if (promesas.length) await Promise.all(promesas)
}

// ── Export / Import ───────────────────────────────────────────────────────────
const importando = ref(false)
const toastMsg   = ref('')
const toastTipo  = ref('success')
let   toastTimer = null

function mostrarToast(msg, tipo = 'success') {
    toastMsg.value  = msg
    toastTipo.value = tipo
    clearTimeout(toastTimer)
    toastTimer = setTimeout(() => { toastMsg.value = '' }, 5000)
}

async function importarArchivo(e) {
    const file = e.target.files?.[0]
    if (!file) return
    importando.value = true

    const fd = new FormData()
    fd.append('archivo', file)

    try {
        const res  = await fetch('/cotizadores/plantillas/importar', {
            method:  'POST',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body:    fd,
        })
        const data = await res.json()
        if (!res.ok) {
            mostrarToast(data.message ?? 'Error al importar.', 'error')
            return
        }
        const msg = `Se importaron ${data.importadas} plantilla(s) correctamente.` +
            (data.errores?.length ? ` Errores: ${data.errores.join('; ')}` : '')
        mostrarToast(msg, data.errores?.length ? 'warning' : 'success')
        router.reload({ only: ['plantillas'] })
    } catch {
        mostrarToast('Error de conexión al importar.', 'error')
    } finally {
        importando.value = false
        e.target.value   = ''
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
const formatCOP = (v) => new Intl.NumberFormat('es-CO', { maximumFractionDigits: 0 }).format(v ?? 0)

const badgesTipo = {
    texto:    'bg-tinta-100 text-tinta-500',
    numero:   'bg-blue-100 text-blue-700',
    decimal:  'bg-[var(--marca-suave)] text-[var(--marca)]',
    select:   'bg-purple-100 text-purple-700',
    boolean:  'bg-green-100 text-green-700',
    checkbox: 'bg-orange-100 text-orange-700',
}
</script>

<template>
    <AppLayout title="Plantillas de Ensamble">
        <div class="max-w-full">

            <!-- Error global -->
            <div v-if="errorGlobal" class="mb-4 bg-red-50 border border-red-200 rounded-xl px-4 py-3 text-sm text-red-700 flex items-center justify-between">
                {{ errorGlobal }}
                <button @click="errorGlobal = ''" class="ml-3">✕</button>
            </div>

            <!-- ── Tabs mobile ── -->
            <div class="flex md:hidden mb-4 rounded-xl overflow-hidden border border-linea">
                <button @click="tabMobile = 'plantillas'"
                    :class="['flex-1 py-2.5 text-xs font-semibold transition-colors', tabMobile === 'plantillas' ? 'text-white' : 'text-tinta-500 bg-superficie']"
                    :style="tabMobile === 'plantillas' ? 'background:var(--marca);' : ''">
                    Plantillas
                </button>
                <button @click="tabMobile = 'editor'" :disabled="!plantillaActual"
                    :class="['flex-1 py-2.5 text-xs font-semibold transition-colors disabled:opacity-40', tabMobile === 'editor' ? 'text-white' : 'text-tinta-500 bg-superficie']"
                    :style="tabMobile === 'editor' ? 'background:var(--marca);' : ''">
                    Editor
                </button>
            </div>

            <div class="flex gap-4 items-start">

                <!-- ── Lista de plantillas ──────────────────────────────────── -->
                <aside :class="['md:block md:w-64 shrink-0', tabMobile !== 'plantillas' ? 'hidden' : 'block w-full']">
                    <div class="bg-superficie rounded-2xl shadow-sm overflow-hidden">
                        <div class="px-4 py-3 border-b border-linea flex items-center justify-between">
                            <h2 class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Plantillas</h2>
                            <div class="flex items-center gap-2">
                                <a href="/cotizadores/plantillas/exportar-todas"
                                    class="flex items-center gap-1 text-xs text-tinta-300 hover:text-blue-600 transition-colors"
                                    title="Exportar todas como JSON">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                                    </svg>
                                    <span class="hidden sm:inline">Exportar</span>
                                </a>
                                <button @click="modalNueva = true"
                                    class="w-6 h-6 rounded-lg flex items-center justify-center text-white font-semibold"
                                    style="background:var(--marca);">+</button>
                            </div>
                        </div>
                        <!-- Buscador -->
                        <div class="px-3 py-2 border-b border-linea space-y-1.5">
                            <input v-model="busqueda" type="text" placeholder="Buscar plantilla..."
                                class="w-full px-2.5 py-1.5 text-xs border border-linea rounded-lg focus:outline-none focus:ring-1 focus:ring-blue-300" />
                            <div class="flex gap-1">
                                <button v-for="f in [['', 'Todas'], ['activas', 'Activas'], ['inactivas', 'Inactivas']]" :key="f[0]"
                                    @click="filtroActivo = f[0]"
                                    class="flex-1 py-0.5 rounded text-xs font-medium transition-colors"
                                    :class="filtroActivo === f[0] ? 'text-white' : 'text-tinta-400 bg-tinta-100 hover:bg-tinta-200'"
                                    :style="filtroActivo === f[0] ? 'background:var(--marca);' : ''">
                                    {{ f[1] }}
                                </button>
                            </div>
                        </div>
                        <ul class="divide-y divide-gray-50">
                            <li v-for="p in plantillasFiltradas" :key="p.id"
                                @click="seleccionar(p)"
                                :class="['flex items-center justify-between px-4 py-3 cursor-pointer transition-colors group', plantillaActual?.id === p.id ? 'bg-blue-50' : 'hover:bg-tinta-50']">
                                <div class="min-w-0">
                                    <p :class="['text-sm font-medium truncate', plantillaActual?.id === p.id ? 'text-blue-700' : 'text-tinta-900']">{{ p.nombre }}</p>
                                    <p class="text-xs text-tinta-300">{{ p.campos?.length ?? 0 }} campos · {{ p.componentes?.length ?? 0 }} comp.</p>
                                </div>
                                <div class="flex items-center gap-1">
                                    <span v-if="!p.activo" class="text-xs bg-tinta-100 text-tinta-300 px-1.5 py-0.5 rounded-full">Inactiva</span>
                                    <button @click.stop="duplicarPlantilla(p)" title="Duplicar plantilla"
                                        class="opacity-0 group-hover:opacity-100 w-6 h-6 rounded-lg flex items-center justify-center text-tinta-300 hover:text-blue-600 hover:bg-blue-50">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                                        </svg>
                                    </button>
                                    <button @click.stop="eliminarPlantilla(p)"
                                        class="opacity-0 group-hover:opacity-100 w-6 h-6 rounded-lg flex items-center justify-center text-red-400 hover:bg-red-50">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                        </svg>
                                    </button>
                                </div>
                            </li>
                            <li v-if="!plantillas.length" class="px-4 py-6 text-center text-sm text-tinta-300">Sin plantillas.</li>
                        </ul>
                    </div>
                </aside>

                <!-- ── Sin plantilla seleccionada ───────────────────────────── -->
                <div v-if="!plantillaActual" class="hidden md:flex flex-1 items-center justify-center py-24 text-tinta-300">
                    <div class="text-center">
                        <svg class="w-12 h-12 mx-auto mb-3 text-tinta-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                        </svg>
                        <p class="text-sm">Selecciona o crea una plantilla</p>
                    </div>
                </div>

                <!-- ── Editor de plantilla ──────────────────────────────────── -->
                <section v-if="plantillaActual" :class="['flex-1 min-w-0', tabMobile !== 'editor' ? 'hidden md:block' : 'block']">

                    <!-- Tabs del editor -->
                    <div class="flex border-b border-linea mb-4 bg-superficie rounded-t-2xl overflow-hidden">
                        <button v-for="tab in [['info','Info'],['campos','Campos'],['componentes','Componentes'],['produccion','Producción'],['salida','Salida']]"
                            :key="tab[0]"
                            @click="tabActivo = tab[0]"
                            :class="['px-4 py-3 text-sm font-medium transition-colors border-b-2 -mb-px', tabActivo === tab[0] ? 'text-blue-700 border-blue-600' : 'text-tinta-400 border-transparent hover:text-tinta-700']">
                            {{ tab[1] }}
                        </button>
                        <div class="flex-1" />
                        <button @click="abrirProbar"
                            class="px-4 py-3 text-xs font-medium text-blue-600 hover:text-blue-800 flex items-center gap-1 border-b-2 border-transparent">
                            ▷ Probar
                        </button>
                    </div>

                    <!-- ══════════════ Tab: Info general ══════════════════════ -->
                    <div v-if="tabActivo === 'info'" class="bg-superficie rounded-b-2xl shadow-sm p-5 space-y-4">
                        <div class="space-y-4">
                            <div>
                                <label class="block text-xs font-medium text-tinta-500 mb-1">Nombre *</label>
                                <input v-model="plantillaActual.nombre" type="text"
                                    class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]" />
                            </div>
                            <div class="flex items-center gap-3">
                                <input v-model="plantillaActual.activo" type="checkbox" id="activa" class="rounded" />
                                <label for="activa" class="text-sm text-tinta-700">Plantilla activa</label>
                            </div>
                        </div>

                        <div class="pt-2 flex items-center gap-3 flex-wrap">
                            <button @click="guardarInfo" :disabled="guardando"
                                class="px-5 py-2.5 rounded-xl text-sm text-white font-semibold disabled:opacity-60"
                                style="background:var(--marca);">
                                {{ guardando ? 'Guardando...' : 'Guardar información' }}
                            </button>
                            <button @click="duplicarPlantilla(plantillaActual)" :disabled="guardando"
                                class="px-4 py-2.5 rounded-xl text-sm font-semibold border border-linea text-tinta-500 hover:bg-tinta-50 hover:border-blue-300 hover:text-blue-600 disabled:opacity-50 flex items-center gap-2 transition-colors">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                                </svg>
                                Duplicar plantilla
                            </button>
                        </div>

                        <!-- Respaldo -->
                        <div class="border-t border-linea pt-5 space-y-3">
                            <h3 class="text-xs font-semibold text-tinta-300 uppercase tracking-[0.12em]">Respaldo</h3>

                            <!-- Exportar esta plantilla -->
                            <a :href="`/cotizadores/plantillas/${plantillaActual.id}/exportar`"
                                class="flex items-center gap-2 px-4 py-2.5 rounded-xl border border-linea text-sm text-tinta-500 hover:bg-tinta-50 hover:border-blue-300 hover:text-blue-600 transition-colors">
                                <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                                </svg>
                                Exportar esta plantilla como JSON
                            </a>

                            <!-- Importar desde JSON -->
                            <div class="border border-dashed border-linea rounded-xl p-4 space-y-2">
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="file" accept=".json" class="hidden" :disabled="importando" @change="importarArchivo" />
                                    <span class="flex items-center gap-2 px-4 py-2.5 rounded-xl border border-linea text-sm w-full transition-colors"
                                        :class="importando ? 'text-tinta-300 cursor-not-allowed' : 'text-tinta-500 hover:bg-tinta-50 hover:border-blue-300 hover:text-blue-600 cursor-pointer'">
                                        <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l4-4m0 0l4 4m-4-4v12"/>
                                        </svg>
                                        {{ importando ? 'Importando...' : 'Importar plantillas desde JSON' }}
                                    </span>
                                </label>
                                <p class="text-xs text-tinta-300">
                                    Importa una o varias plantillas desde un archivo <code class="font-mono">.json</code> exportado previamente.
                                    Si la plantilla ya existe se creará con sufijo de fecha.
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- ══════════════ Tab: Campos ═════════════════════════════ -->
                    <div v-if="tabActivo === 'campos'" class="bg-superficie rounded-b-2xl shadow-sm overflow-hidden">
                        <ul class="divide-y divide-gray-50">
                            <template v-for="(c, idx) in plantillaActual.campos" :key="c.id">
                                <!-- Separador: variables calculadas -->
                                <li v-if="idx > 0 && c.tipo_campo === 'calculado' && (plantillaActual.campos[idx-1].tipo_campo ?? 'entrada') !== 'calculado'"
                                    class="px-4 py-1.5 bg-purple-50 border-b border-purple-100">
                                    <span class="text-xs font-semibold text-purple-500 uppercase tracking-wide">Variables calculadas</span>
                                </li>
                                <li v-if="idx === 0 && c.tipo_campo === 'calculado'"
                                    class="px-4 py-1.5 bg-purple-50 border-b border-purple-100">
                                    <span class="text-xs font-semibold text-purple-500 uppercase tracking-wide">Variables calculadas</span>
                                </li>
                                <!-- Separador: variables de instancia -->
                                <li v-if="idx > 0 && c.tipo_campo === 'variable_instancia' && plantillaActual.campos[idx-1].tipo_campo !== 'variable_instancia'"
                                    class="px-4 py-1.5 bg-amber-50 border-b border-amber-100">
                                    <span class="text-xs font-semibold text-amber-600 uppercase tracking-wide">Variables de instancia</span>
                                </li>
                                <li v-if="idx === 0 && c.tipo_campo === 'variable_instancia'"
                                    class="px-4 py-1.5 bg-amber-50 border-b border-amber-100">
                                    <span class="text-xs font-semibold text-amber-600 uppercase tracking-wide">Variables de instancia</span>
                                </li>
                                <li class="px-4 py-3 flex items-center gap-2 group">
                                    <!-- ↑↓ reorder -->
                                    <div class="flex flex-col shrink-0">
                                        <button @click="moverCampo(idx, -1)" :disabled="idx === 0"
                                            class="p-0.5 text-tinta-200 hover:text-tinta-500 disabled:opacity-20 leading-none" title="Subir">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7"/>
                                            </svg>
                                        </button>
                                        <button @click="moverCampo(idx, 1)" :disabled="idx === plantillaActual.campos.length - 1"
                                            class="p-0.5 text-tinta-200 hover:text-tinta-500 disabled:opacity-20 leading-none" title="Bajar">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                                            </svg>
                                        </button>
                                    </div>

                                    <!-- Info: campo de entrada -->
                                    <div v-if="(c.tipo_campo ?? 'entrada') === 'entrada'" class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <span :class="['text-xs px-2 py-0.5 rounded-full font-medium', badgesTipo[c.tipo] ?? 'bg-tinta-100 text-tinta-400']">{{ c.tipo }}</span>
                                            <span class="text-sm font-semibold text-tinta-900">{{ c.etiqueta }}</span>
                                            <span v-if="c.requerido" class="text-xs text-red-400">*</span>
                                        </div>
                                        <p class="text-xs text-tinta-300 mt-0.5 font-mono">{{ c.nombre }}{{ c.valor_defecto ? ` = ${c.valor_defecto}` : '' }}</p>
                                        <p v-if="c.ayuda" class="text-xs text-tinta-300 italic">{{ c.ayuda }}</p>
                                    </div>

                                    <!-- Info: variable calculada -->
                                    <div v-else-if="c.tipo_campo === 'calculado'" class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <span class="text-xs px-2 py-0.5 rounded-full font-medium bg-purple-100 text-purple-700">⨍x</span>
                                            <span class="text-sm font-semibold text-tinta-900 font-mono">{{ c.nombre }}</span>
                                        </div>
                                        <p v-if="c.formula_calculo" class="text-xs font-mono text-purple-600 mt-0.5 break-all">= {{ c.formula_calculo }}</p>
                                    </div>

                                    <!-- Info: variable de instancia -->
                                    <div v-else class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <span class="text-xs px-2 py-0.5 rounded-full font-medium bg-amber-100 text-amber-700">⬦ instancia</span>
                                            <span class="text-sm font-semibold text-tinta-900">{{ c.etiqueta || c.nombre }}</span>
                                        </div>
                                        <p class="text-xs font-mono text-amber-600 mt-0.5">{{ c.nombre }} · {{ c.subtipo_variable ?? 'numero' }}{{ c.subtipo_variable === 'selector' && c.opciones_selector?.length ? ` (${c.opciones_selector.length} opciones)` : '' }}</p>
                                    </div>

                                    <!-- Acciones -->
                                    <div class="flex gap-1 opacity-0 group-hover:opacity-100 shrink-0">
                                        <button v-if="(c.tipo_campo ?? 'entrada') === 'calculado'"
                                            @click="enviarAComponentes(c)"
                                            class="p-1 rounded-lg text-tinta-300 hover:text-purple-600 hover:bg-purple-50"
                                            title="Usar en componente">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                                            </svg>
                                        </button>
                                        <button @click="abrirEditarCampo(c)" class="p-1 rounded-lg text-tinta-300 hover:text-blue-600 hover:bg-blue-50">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                                        </button>
                                        <button @click="eliminarCampo(c)" class="p-1 rounded-lg text-tinta-300 hover:text-red-600 hover:bg-red-50">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                        </button>
                                    </div>
                                </li>
                            </template>
                            <li v-if="!plantillaActual.campos.length" class="px-4 py-6 text-center text-sm text-tinta-300">Sin campos.</li>
                        </ul>

                        <div v-if="editandoCampo === null" class="px-4 py-3 border-t border-linea flex gap-2">
                            <button @click="abrirNuevoCampo('entrada')"
                                class="flex-1 py-2 rounded-xl border-2 border-dashed border-linea text-sm text-tinta-400 hover:border-blue-300 hover:text-blue-600 transition-colors">
                                + Entrada
                            </button>
                            <button @click="abrirNuevoCampo('calculado')"
                                class="flex-1 py-2 rounded-xl border-2 border-dashed border-purple-200 text-sm text-purple-500 hover:border-purple-400 hover:text-purple-700 transition-colors">
                                ⨍ Calculada
                            </button>
                            <button @click="abrirNuevoCampo('variable_instancia')"
                                class="flex-1 py-2 rounded-xl border-2 border-dashed border-amber-200 text-sm text-amber-600 hover:border-amber-400 hover:text-amber-700 transition-colors">
                                ⬦ Instancia
                            </button>
                        </div>

                        <!-- Editor inline de campo -->
                        <div v-if="editandoCampo !== null"
                            :class="['border-t p-4 space-y-3', campoBorrador.tipo_campo === 'calculado' ? 'border-purple-100' : campoBorrador.tipo_campo === 'variable_instancia' ? 'border-amber-100' : 'border-blue-100']"
                            :style="campoBorrador.tipo_campo === 'calculado' ? 'background:var(--pastel-violeta);' : campoBorrador.tipo_campo === 'variable_instancia' ? 'background:var(--pastel-ambar);' : 'background:var(--pastel-azul);'">

                            <div class="flex items-center justify-between">
                                <p :class="['text-xs font-semibold uppercase', campoBorrador.tipo_campo === 'calculado' ? 'text-purple-700' : campoBorrador.tipo_campo === 'variable_instancia' ? 'text-amber-700' : 'text-blue-700']">
                                    {{ editandoCampo === 'nuevo'
                                        ? (campoBorrador.tipo_campo === 'calculado' ? 'Nueva variable calculada' : campoBorrador.tipo_campo === 'variable_instancia' ? 'Nueva variable de instancia' : 'Nuevo campo de entrada')
                                        : (campoBorrador.tipo_campo === 'calculado' ? 'Editar variable calculada' : campoBorrador.tipo_campo === 'variable_instancia' ? 'Editar variable de instancia' : 'Editar campo de entrada') }}
                                </p>
                            </div>

                            <!-- ─── Formulario: Campo de entrada ──────────────── -->
                            <template v-if="campoBorrador.tipo_campo === 'entrada'">
                                <div class="flex flex-wrap gap-2">
                                    <button v-for="t in TIPOS_CAMPO" :key="t.value" type="button"
                                        @click="campoBorrador.tipo = t.value"
                                        :class="['px-2.5 py-1 rounded-lg text-xs font-medium border transition-colors', campoBorrador.tipo === t.value ? 'border-blue-500 bg-blue-600 text-white' : 'border-linea text-tinta-500 hover:bg-tinta-50']">
                                        {{ t.label }}
                                    </button>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                    <div>
                                        <label class="block text-xs font-medium text-tinta-500 mb-1">Etiqueta visible *</label>
                                        <input v-model="campoBorrador.etiqueta" type="text" class="w-full border border-linea rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-[var(--marca)]" placeholder="Ej: Ancho del vano (m)" />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-tinta-500 mb-1">Nombre interno</label>
                                        <input v-model="campoBorrador.nombre" type="text" class="w-full border border-linea rounded-lg px-3 py-1.5 text-sm font-mono focus:outline-none focus:border-[var(--marca)]" placeholder="ancho_vano" />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-tinta-500 mb-1">Valor por defecto</label>
                                        <input v-model="campoBorrador.valor_defecto" type="text" class="w-full border border-linea rounded-lg px-3 py-1.5 text-sm focus:outline-none" placeholder="Ej: 1.0" />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-tinta-500 mb-1">Placeholder</label>
                                        <input v-model="campoBorrador.placeholder" type="text" class="w-full border border-linea rounded-lg px-3 py-1.5 text-sm focus:outline-none" placeholder="Ej: 1.20" />
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label class="block text-xs font-medium text-tinta-500 mb-1">Texto de ayuda</label>
                                        <input v-model="campoBorrador.ayuda" type="text" class="w-full border border-linea rounded-lg px-3 py-1.5 text-sm focus:outline-none" placeholder="Ej: Mide de pared a pared" />
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <input v-model="campoBorrador.requerido" type="checkbox" id="req-campo" class="rounded" />
                                        <label for="req-campo" class="text-xs text-tinta-500">Requerido</label>
                                    </div>
                                </div>

                                <!-- Opciones para select -->
                                <div v-if="campoBorrador.tipo === 'select'" class="space-y-2">
                                    <p class="text-xs font-medium text-tinta-500">Opciones:</p>
                                    <div v-for="(op, i) in campoBorrador.opciones" :key="i" class="flex gap-2 items-center">
                                        <input v-model="op.valor"    type="text" placeholder="valor"    class="flex-1 border border-linea rounded-lg px-2 py-1 text-xs font-mono focus:outline-none" />
                                        <input v-model="op.etiqueta" type="text" placeholder="etiqueta" class="flex-1 border border-linea rounded-lg px-2 py-1 text-xs focus:outline-none" />
                                        <button @click="quitarOpcion(i)" class="text-red-400 hover:text-red-600 text-sm">✕</button>
                                    </div>
                                    <button @click="agregarOpcion" class="text-xs text-blue-600 hover:text-blue-800 font-medium">+ Agregar opción</button>
                                </div>
                            </template>

                            <!-- ─── Formulario: Variable calculada ───────────── -->
                            <template v-else-if="campoBorrador.tipo_campo === 'calculado'">
                                <div>
                                    <label class="block text-xs font-medium text-tinta-500 mb-1">Nombre de la variable *</label>
                                    <input v-model="campoBorrador.nombre" type="text"
                                        class="w-full border border-linea rounded-lg px-3 py-1.5 text-sm font-mono focus:outline-none focus:border-purple-400"
                                        placeholder="Ej: perimetro_marco" />
                                    <p class="text-xs text-tinta-300 mt-1">Usa este nombre en las fórmulas de componentes.</p>
                                </div>

                                <div>
                                    <label class="block text-xs font-medium text-tinta-500 mb-1">Fórmula * <span class="font-normal text-tinta-300">(escribe para autocompletar)</span></label>
                                    <div class="relative">
                                        <input
                                            ref="formulaCalcInputEl"
                                            v-model="campoBorrador.formula_calculo"
                                            type="text"
                                            class="w-full border border-linea rounded-lg px-3 py-1.5 text-sm font-mono focus:outline-none focus:border-purple-400"
                                            placeholder="Ej: 2 * alto_vano + 2 * ancho_vano"
                                            @input="onFormulaCalcInput"
                                            @blur="onFormulaCalcBlur"
                                        />
                                        <ul v-if="formulaCalcSugs.length"
                                            class="absolute z-30 left-0 right-0 mt-1 bg-superficie border border-purple-200 rounded-xl shadow-lg overflow-hidden">
                                            <li v-for="s in formulaCalcSugs" :key="s"
                                                @mousedown.prevent="insertarVariableCalc(s)"
                                                class="px-3 py-1.5 text-xs font-mono text-purple-700 hover:bg-purple-50 cursor-pointer">
                                                {{ s }}
                                            </li>
                                        </ul>
                                    </div>
                                </div>

                                <!-- Preview en vivo -->
                                <div v-if="previewCalculo" :class="['px-3 py-2 rounded-lg text-xs font-mono', previewCalculo.ok ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-600']">
                                    <span v-if="previewCalculo.ok">Preview (valores de ejemplo): <strong>{{ previewCalculo.valor }}</strong></span>
                                    <span v-else>Error en fórmula: {{ previewCalculo.error }}</span>
                                </div>
                            </template>

                            <!-- ─── Formulario: Variable de instancia ─────────── -->
                            <template v-else-if="campoBorrador.tipo_campo === 'variable_instancia'">
                                <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
                                    Las variables de instancia se llenan <strong>por el vendedor al cotizar</strong> y pueden usarse en fórmulas de componentes igual que las entradas.
                                </p>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                    <div>
                                        <label class="block text-xs font-medium text-tinta-500 mb-1">Nombre interno *</label>
                                        <input v-model="campoBorrador.nombre" type="text"
                                            class="w-full border border-linea rounded-lg px-3 py-1.5 text-sm font-mono focus:outline-none focus:border-amber-400"
                                            placeholder="Ej: temperatura_camara" />
                                        <p class="text-xs text-tinta-300 mt-0.5">Usa este nombre en las fórmulas de componentes.</p>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-tinta-500 mb-1">Etiqueta visible</label>
                                        <input v-model="campoBorrador.etiqueta" type="text"
                                            class="w-full border border-linea rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:border-amber-400"
                                            placeholder="Ej: Temperatura de la cámara" />
                                    </div>
                                </div>

                                <!-- Subtipo -->
                                <div>
                                    <label class="block text-xs font-medium text-tinta-500 mb-1.5">Tipo de dato</label>
                                    <div class="flex flex-wrap gap-2">
                                        <button v-for="st in [['numero','Número entero'],['decimal','Decimal'],['texto','Texto'],['selector','Selector']]"
                                            :key="st[0]" type="button"
                                            @click="campoBorrador.subtipo_variable = st[0]"
                                            :class="['px-3 py-1.5 rounded-lg text-xs font-medium border transition-colors', campoBorrador.subtipo_variable === st[0] ? 'border-amber-500 bg-amber-500 text-white' : 'border-linea text-tinta-500 hover:bg-tinta-50']">
                                            {{ st[1] }}
                                        </button>
                                    </div>
                                </div>

                                <!-- Opciones si selector -->
                                <div v-if="campoBorrador.subtipo_variable === 'selector'" class="space-y-2">
                                    <p class="text-xs font-medium text-tinta-500">Opciones del selector:</p>
                                    <div v-for="(op, i) in campoBorrador.opciones_selector" :key="i" class="border border-linea rounded-lg p-2 space-y-1.5">
                                        <div class="flex gap-2 items-center">
                                            <input v-model="op.valor" type="text" placeholder="valor"
                                                class="flex-1 border border-linea rounded-lg px-2 py-1 text-xs font-mono focus:outline-none" />
                                            <input v-model="op.etiqueta" type="text" placeholder="etiqueta visible"
                                                class="flex-1 border border-linea rounded-lg px-2 py-1 text-xs focus:outline-none" />
                                            <button @click="campoBorrador.opciones_selector.splice(i, 1)" class="text-red-400 hover:text-red-600 text-sm shrink-0">✕</button>
                                        </div>
                                        <!-- Imagen por opción (solo en edición, no en nuevo) -->
                                        <div v-if="editandoCampo !== 'nuevo'" class="flex items-center gap-2">
                                            <img v-if="op.imagen" :src="`/storage/${op.imagen}`" class="w-10 h-10 object-cover rounded border border-linea" />
                                            <div v-else class="w-10 h-10 bg-tinta-100 rounded border border-dashed border-linea flex items-center justify-center text-tinta-200 text-xs">+</div>
                                            <label class="cursor-pointer text-xs text-amber-600 hover:text-amber-800 font-medium">
                                                {{ op.imagen ? 'Cambiar imagen' : 'Agregar imagen' }}
                                                <input type="file" accept="image/*" class="hidden"
                                                    @change="subirImagenOpcionSelector($event, i)" />
                                            </label>
                                            <button v-if="op.imagen" @click="eliminarImagenOpcionSelector(i)"
                                                class="text-xs text-red-400 hover:text-red-600">Quitar</button>
                                        </div>
                                    </div>
                                    <button @click="campoBorrador.opciones_selector.push({ valor: '', etiqueta: '' })"
                                        class="text-xs text-amber-600 hover:text-amber-800 font-medium">+ Agregar opción</button>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                    <div>
                                        <label class="block text-xs font-medium text-tinta-500 mb-1">Valor por defecto</label>
                                        <input v-model="campoBorrador.valor_defecto" type="text"
                                            class="w-full border border-linea rounded-lg px-3 py-1.5 text-sm focus:outline-none"
                                            placeholder="Opcional..." />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-tinta-500 mb-1">Texto de ayuda</label>
                                        <input v-model="campoBorrador.ayuda" type="text"
                                            class="w-full border border-linea rounded-lg px-3 py-1.5 text-sm focus:outline-none"
                                            placeholder="Ej: Temp. interior en °C" />
                                    </div>
                                </div>
                            </template>

                            <!-- ─── Imagen de referencia (solo campo existente) ── -->
                            <div v-if="editandoCampo !== 'nuevo'" class="space-y-2 pt-1">
                                <p class="text-xs font-medium text-tinta-500">Imagen de referencia <span class="font-normal text-tinta-300">(plano técnico o guía visual)</span></p>
                                <div v-if="campoBorrador.imagen_referencia">
                                    <div class="relative rounded-lg overflow-hidden border border-linea bg-tinta-50">
                                        <img :src="'/storage/' + campoBorrador.imagen_referencia" class="w-full max-h-52 object-contain" />
                                        <button @click="eliminarImagenReferenciaCampo"
                                            class="absolute top-2 right-2 bg-red-500 hover:bg-red-600 text-white text-xs rounded-full w-6 h-6 flex items-center justify-center shadow">✕</button>
                                    </div>
                                    <input v-model="campoBorrador.imagen_referencia_titulo" type="text"
                                        placeholder="Título del plano (ej: Vista frontal)"
                                        class="mt-1.5 w-full border border-linea rounded-lg px-3 py-1.5 text-sm focus:outline-none" />
                                    <p class="text-xs text-tinta-300 mt-0.5">Edita el título y haz clic en «Actualizar» para guardarlo.</p>
                                </div>
                                <div v-else>
                                    <label class="flex flex-col items-center justify-center gap-1 border-2 border-dashed border-linea rounded-lg py-5 cursor-pointer hover:border-blue-300 hover:bg-blue-50 transition-colors">
                                        <svg class="w-5 h-5 text-tinta-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z"/>
                                        </svg>
                                        <span class="text-xs text-tinta-400">Subir imagen de referencia</span>
                                        <span class="text-xs text-tinta-300">JPG, PNG o PDF — máx. 5 MB</span>
                                        <input type="file" accept="image/*" class="hidden" @change="subirImagenReferencia($event)" />
                                    </label>
                                </div>
                            </div>

                            <div class="flex gap-2">
                                <button @click="cancelarCampo" class="flex-1 py-2 rounded-xl border border-linea text-xs text-tinta-500 hover:bg-tinta-50">Cancelar</button>
                                <button @click="guardarCampo" :disabled="guardando"
                                    :class="['flex-1 py-2 rounded-xl text-xs text-white font-semibold disabled:opacity-60', campoBorrador.tipo_campo === 'calculado' ? 'bg-purple-700' : campoBorrador.tipo_campo === 'variable_instancia' ? 'bg-amber-600' : '']"
                                    :style="campoBorrador.tipo_campo === 'entrada' ? 'background:var(--marca);' : ''">
                                    {{ guardando ? '...' : (editandoCampo === 'nuevo' ? 'Guardar' : 'Actualizar') }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- ══════════════ Tab: Componentes ════════════════════════ -->
                    <div v-if="tabActivo === 'componentes'" class="bg-superficie rounded-b-2xl shadow-sm">

                        <!-- MOBILE: Panel variables colapsable (< lg) -->
                        <div class="lg:hidden border-b border-linea">
                            <button @click="varsMobileOpen = !varsMobileOpen"
                                class="w-full flex items-center justify-between px-4 py-2.5 text-left hover:bg-tinta-50 transition-colors">
                                <span class="text-xs font-semibold text-tinta-700">Variables disponibles</span>
                                <svg class="w-4 h-4 text-tinta-300 transition-transform duration-200" :class="varsMobileOpen ? 'rotate-180' : ''"
                                    fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </button>
                            <div v-if="varsMobileOpen" class="px-4 pb-3 space-y-2 border-t border-linea">
                                <p class="text-xs text-tinta-300 pt-2">Clic para insertar en fórmula activa, o copiar:</p>
                                <div v-if="camposEntrada.length">
                                    <p class="text-xs text-tinta-300 mb-1">Entradas:</p>
                                    <div class="flex flex-wrap gap-1.5">
                                        <button v-for="c in camposEntrada" :key="c.nombre" @click="copiarVariable(c.nombre)"
                                            :class="['px-2 py-0.5 rounded-full text-xs font-mono border transition-all', chipCopiado === c.nombre ? 'bg-green-100 text-green-700 border-green-300' : 'bg-blue-50 text-blue-700 border-blue-200 hover:bg-blue-100']">
                                            {{ chipCopiado === c.nombre ? '✓ ' : '' }}{{ '{' + c.nombre + '}' }}
                                        </button>
                                    </div>
                                </div>
                                <div v-if="camposCalculados.length">
                                    <p class="text-xs text-tinta-300 mb-1">Calculadas:</p>
                                    <div class="flex flex-wrap gap-1.5">
                                        <button v-for="c in camposCalculados" :key="c.nombre" @click="copiarVariable(c.nombre)"
                                            :class="['px-2 py-0.5 rounded-full text-xs font-mono border transition-all', chipCopiado === c.nombre ? 'bg-green-100 text-green-700 border-green-300' : 'bg-purple-50 text-purple-700 border-purple-200 hover:bg-purple-100']">
                                            {{ chipCopiado === c.nombre ? '✓ ' : '' }}{{ '{' + c.nombre + '}' }}
                                        </button>
                                    </div>
                                </div>
                                <div v-if="camposVariableInstancia.length">
                                    <p class="text-xs text-tinta-300 mb-1">Instancia <span class="text-tinta-200">(rellenadas al cotizar):</span></p>
                                    <div class="flex flex-wrap gap-1.5">
                                        <button v-for="c in camposVariableInstancia" :key="c.nombre" @click="copiarVariable(c.nombre)"
                                            :class="['px-2 py-0.5 rounded-full text-xs font-mono border transition-all', chipCopiado === c.nombre ? 'bg-green-100 text-green-700 border-green-300' : 'bg-amber-50 text-amber-700 border-amber-200 hover:bg-amber-100']">
                                            {{ chipCopiado === c.nombre ? '✓ ' : '' }}{{ '{' + c.nombre + '}' }}
                                        </button>
                                    </div>
                                </div>
                                <p v-if="!camposEntrada.length && !camposCalculados.length && !camposVariableInstancia.length"
                                    class="text-xs text-tinta-300 italic py-2">Sin variables definidas. Ve al tab Campos.</p>
                            </div>
                        </div>

                        <!-- Layout dos columnas -->
                        <div class="lg:flex lg:items-start">
                        <div class="lg:w-[70%] overflow-hidden">

                        <!-- Panel de ayuda colapsable -->
                        <div class="border-b border-linea">
                            <button @click="ayudaVisible = !ayudaVisible"
                                class="w-full flex items-center justify-between px-4 py-2.5 text-left hover:bg-blue-50 transition-colors">
                                <span class="flex items-center gap-2 text-xs font-medium text-blue-700">
                                    <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                    Cómo configurar componentes
                                </span>
                                <span class="text-xs text-blue-500">{{ ayudaVisible ? '▲ Ocultar' : '▼ Ver' }}</span>
                            </button>

                            <div v-if="ayudaVisible" class="px-4 pb-5 pt-2 text-xs text-tinta-700 space-y-4" style="background:var(--pastel-azul); border-left: 3px solid var(--marca);">

                                <!-- 1. Producto -->
                                <div class="space-y-1">
                                    <p class="font-semibold text-tinta-900">1. PRODUCTO VINCULADO <span class="text-red-500">*</span></p>
                                    <p class="text-tinta-400">Obligatorio — búscalo en el catálogo para que el sistema tome su precio unitario y calcule el costo del componente.</p>
                                </div>

                                <!-- 2. Fórmula -->
                                <div class="space-y-1.5">
                                    <p class="font-semibold text-tinta-900">2. FÓRMULA DE CANTIDAD</p>
                                    <p class="text-tinta-400">Define cuántas unidades se necesitan. Acepta expresiones matemáticas con variables. Puedes escribirla en varias líneas — se normaliza al guardar.</p>
                                    <div class="bg-superficie rounded-lg p-2.5 space-y-0.5 font-mono text-tinta-700 text-[11px]">
                                        <p class="font-semibold text-tinta-300 font-sans not-italic">Simples:</p>
                                        <p>1 <span class="text-tinta-300 font-sans">→ cantidad fija</span></p>
                                        <p>ancho_vano <span class="text-tinta-300 font-sans">→ una variable</span></p>
                                        <p>2 * alto_vano + 2 * ancho_vano + 0.664</p>
                                        <p>perimetro_borde * 1.05 <span class="text-tinta-300 font-sans">→ con margen del 5 %</span></p>
                                    </div>
                                    <div class="bg-superficie rounded-lg p-2.5 space-y-0.5 font-mono text-tinta-700 text-[11px]">
                                        <p class="font-semibold text-tinta-300 font-sans not-italic">Funciones disponibles:</p>
                                        <p>round(area_vidrio, 2) <span class="text-tinta-300 font-sans">→ redondear a 2 decimales</span></p>
                                        <p>ceil(alto_vano / 0.6) <span class="text-tinta-300 font-sans">→ redondear hacia arriba</span></p>
                                        <p>floor(ancho_vano * 3) <span class="text-tinta-300 font-sans">→ redondear hacia abajo</span></p>
                                        <p>max(alto_vano, 2.0) <span class="text-tinta-300 font-sans">→ el mayor de dos valores</span></p>
                                        <p>min(ancho_vano, 1.2) <span class="text-tinta-300 font-sans">→ el menor de dos valores</span></p>
                                        <p>abs(alto_vano - 2.1) <span class="text-tinta-300 font-sans">→ valor absoluto</span></p>
                                    </div>
                                    <div class="bg-superficie rounded-lg p-2.5 space-y-0.5 font-mono text-tinta-700 text-[11px]">
                                        <p class="font-semibold text-tinta-300 font-sans not-italic">Ternario (condicional en cantidad):</p>
                                        <p>alto_vano > 2 ? 4 : 2 <span class="text-tinta-300 font-sans">→ si > 2 usa 4, sino 2</span></p>
                                        <p>espesor == 80 ? 1.2 : 1.0</p>
                                    </div>
                                </div>

                                <!-- 3. Condición -->
                                <div class="space-y-1.5">
                                    <p class="font-semibold text-tinta-900">3. CONDICIÓN (opcional)</p>
                                    <p class="text-tinta-400">El componente solo se incluye si la condición es verdadera. Vacía = incluir siempre.</p>
                                    <div class="bg-superficie rounded-lg p-2.5 space-y-0.5 font-mono text-tinta-700 text-[11px]">
                                        <p>temperatura == "BAJA" <span class="text-tinta-300 font-sans">→ solo en cámara fría</span></p>
                                        <p>ancho_vano > 1.2 <span class="text-tinta-300 font-sans">→ solo si vano ancho</span></p>
                                        <p>alto_vano >= 2.5 <span class="text-tinta-300 font-sans">→ refuerzo en vano alto</span></p>
                                        <p>temperatura == "BAJA" and espesor >= 100</p>
                                        <p>tipo_puerta == "corredera" or tipo_puerta == "batiente"</p>
                                        <p>not (ancho_vano > 2.0 and alto_vano > 3.0)</p>
                                    </div>
                                </div>

                                <!-- 4. Operadores -->
                                <div class="space-y-1.5">
                                    <p class="font-semibold text-tinta-900">4. OPERADORES</p>
                                    <div class="bg-superficie rounded-lg p-2.5 space-y-0.5 text-tinta-700 text-[11px]">
                                        <p><span class="font-mono">+  -  *  /  %</span>  <span class="text-tinta-300">aritmética básica</span></p>
                                        <p><span class="font-mono">**</span>  <span class="text-tinta-300">potencia (2 ** 3 = 8)</span></p>
                                        <p><span class="font-mono">==  !=  >  &lt;  >=  &lt;=</span>  <span class="text-tinta-300">comparación (en Condición)</span></p>
                                        <p><span class="font-mono">and  or  not</span>  <span class="text-tinta-300">lógicos (en Condición)</span></p>
                                        <p><span class="font-mono">? :</span>  <span class="text-tinta-300">ternario: condición ? valor_si : valor_no (en Fórmula)</span></p>
                                    </div>
                                </div>

                                <!-- 5. Variables -->
                                <div class="space-y-1.5">
                                    <p class="font-semibold text-tinta-900">5. VARIABLES DISPONIBLES</p>
                                    <div class="space-y-1.5">
                                        <div>
                                            <span class="px-2 py-0.5 rounded-full bg-blue-50 text-blue-700 font-mono text-[11px]">Entradas</span>
                                            <span class="text-tinta-400 ml-1">— campos del tab Campos, los llena el usuario al cotizar</span>
                                        </div>
                                        <div>
                                            <span class="px-2 py-0.5 rounded-full bg-purple-50 text-purple-700 font-mono text-[11px]">⨍x Calculadas</span>
                                            <span class="text-tinta-400 ml-1">— derivadas de otras variables (tab Campos → +Calculada)</span>
                                        </div>
                                        <div>
                                            <span class="px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 font-mono text-[11px]">⬦ Instancia</span>
                                            <span class="text-tinta-400 ml-1">— valores por ítem, los ingresa el vendedor al agregar a la cotización</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- 6. Consejos -->
                                <div class="space-y-1">
                                    <p class="font-semibold text-tinta-900">6. CONSEJOS</p>
                                    <ul class="space-y-0.5 text-tinta-400 list-disc list-inside">
                                        <li>Marca "Incluir en precio" solo en materiales con costo real.</li>
                                        <li>Usa "Visible cliente" solo en ítems que el cliente necesita ver.</li>
                                        <li>Desmarca "Visible en OP" en materiales de solo costeo — no aparecerán en la Orden de Producción.</li>
                                        <li>Crea variables calculadas (⨍x) para fórmulas que se repiten en varios componentes.</li>
                                        <li>Usa <strong class="text-tinta-700">▷ Probar esta fórmula</strong> en el editor de cada componente para verificar el resultado con valores reales antes de guardar.</li>
                                        <li>Usa <strong class="text-tinta-700">▷ Probar</strong> en la barra superior para ver el desglose completo de la plantilla.</li>
                                        <li>Puedes escribir fórmulas largas en varias líneas — se normalizan a una sola al guardar.</li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <!-- ── Botón nueva sección ── -->
                        <div class="px-4 py-3 border-b border-linea">
                            <template v-if="!showNuevaSec">
                                <button @click="showNuevaSec = true"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border-2 border-dashed border-blue-300 text-xs text-blue-600 hover:bg-blue-50 font-medium transition-colors">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                                    </svg>
                                    Nueva sección
                                </button>
                            </template>
                            <template v-else>
                                <div class="flex items-center gap-2">
                                    <input v-model="nuevaSecNombre"
                                        @keyup.enter="crearSeccion"
                                        @keyup.escape="showNuevaSec = false; nuevaSecNombre = ''"
                                        class="flex-1 border border-blue-300 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-1 focus:ring-[var(--marca-suave)]"
                                        placeholder="Nombre de la sección..."
                                        autofocus />
                                    <button @click="crearSeccion" :disabled="creandoSeccion || !nuevaSecNombre.trim()"
                                        class="px-3 py-1.5 rounded-lg text-xs font-semibold text-white disabled:opacity-60"
                                        style="background:var(--marca);">{{ creandoSeccion ? '...' : 'Crear' }}</button>
                                    <button @click="showNuevaSec = false; nuevaSecNombre = ''"
                                        class="p-1.5 rounded-lg text-tinta-300 hover:bg-tinta-100">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                        </svg>
                                    </button>
                                </div>
                            </template>
                        </div>

                        <!-- ── Lista de secciones y componentes ── -->
                        <div>

                            <!-- Sección real (draggable entre sí) -->
                            <div v-for="sec in seccionesOrdenadas" :key="sec.id"
                                class="border-b border-linea transition-colors"
                                :class="dropCompTarget === sec.id && dragCompId !== null ? 'bg-blue-50' : ''"
                                @dragover.prevent="dragCompId !== null && (dropCompTarget = sec.id)"
                                @drop.prevent="onDropComp($event, sec.id)">

                                <!-- Encabezado sección -->
                                <div class="flex items-center gap-1.5 px-3 py-2 select-none transition-colors"
                                    style="background:var(--marca);"
                                    :class="dropSecTarget === sec.id && dragSecId !== null && dragSecId !== sec.id ? 'opacity-60' : ''"
                                    @dragover.prevent.stop="dragSecId !== null && dragSecId !== sec.id && (dropSecTarget = sec.id)"
                                    @dragleave.self="dropSecTarget === sec.id && (dropSecTarget = null)"
                                    @drop.stop.prevent="onDropSec($event, sec)">

                                    <!-- Handle grip sección -->
                                    <div draggable="true"
                                        @dragstart.stop="onDragStartSec($event, sec)"
                                        @dragend.stop="dragSecId = null; dropSecTarget = null"
                                        class="cursor-grab text-blue-300 hover:text-white p-0.5 shrink-0 rounded transition-colors">
                                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                            <path d="M7 2a2 2 0 1 0 .001 4.001A2 2 0 0 0 7 2zm0 6a2 2 0 1 0 .001 4.001A2 2 0 0 0 7 8zm0 6a2 2 0 1 0 .001 4.001A2 2 0 0 0 7 14zm6-8a2 2 0 1 0-.001-4.001A2 2 0 0 0 13 6zm0 2a2 2 0 1 0 .001 4.001A2 2 0 0 0 13 8zm0 6a2 2 0 1 0 .001 4.001A2 2 0 0 0 13 14z"/>
                                        </svg>
                                    </div>

                                    <!-- Nombre editable -->
                                    <div class="flex-1 min-w-0" @click.stop="renombrandoSecId !== sec.id && toggleSeccion(sec.id)">
                                        <input v-if="renombrandoSecId === sec.id"
                                            v-model="renombrandoNombre"
                                            @keyup.enter.stop="confirmarRenombrar(sec)"
                                            @keyup.escape.stop="renombrandoSecId = null"
                                            @blur="confirmarRenombrar(sec)"
                                            @click.stop
                                            class="w-full bg-white/20 text-white text-xs px-2 py-0.5 rounded border border-superficie/40 focus:outline-none font-semibold tracking-wide" />
                                        <div v-else class="flex items-center gap-2">
                                            <span class="text-xs font-semibold text-white uppercase tracking-wide truncate">{{ sec.nombre }}</span>
                                            <span class="text-xs bg-white/20 text-white px-1.5 py-0.5 rounded-full font-medium leading-none shrink-0">{{ componentesDeSeccion(sec.id).length }}</span>
                                        </div>
                                    </div>

                                    <!-- Acciones sección -->
                                    <div class="flex items-center gap-0.5 shrink-0">
                                        <button @click.stop="iniciarRenombrar(sec)" title="Renombrar"
                                            class="p-1 rounded text-blue-200 hover:text-white hover:bg-blue-800 transition-colors">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
                                            </svg>
                                        </button>
                                        <button @click.stop="eliminarSeccion(sec)" title="Eliminar sección"
                                            class="p-1 rounded text-blue-200 hover:text-red-300 hover:bg-blue-900 transition-colors">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                            </svg>
                                        </button>
                                        <svg class="w-4 h-4 text-blue-200 ml-0.5 transition-transform duration-200 cursor-pointer"
                                            :class="seccionesColapsadas[sec.id] ? '-rotate-90' : ''"
                                            @click.stop="toggleSeccion(sec.id)"
                                            fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                                        </svg>
                                    </div>
                                </div>

                                <!-- Componentes de la sección -->
                                <template v-if="!seccionesColapsadas[sec.id]">
                                    <template v-for="c in componentesDeSeccion(sec.id)" :key="c.id">

                                    <!-- READ row -->
                                    <div v-if="editandoComponente !== c.id"
                                        draggable="true"
                                        @dragstart="onDragStartComp($event, c)"
                                        @dragend="dragCompId = null; dropCompTarget = undefined"
                                        :class="['px-4 py-3 flex items-center gap-2 group border-b border-gray-50 transition-colors', !c.activo ? 'opacity-50' : '', dragCompId === c.id ? 'opacity-40 bg-blue-50' : 'hover:bg-tinta-50']">
                                        <!-- Grip componente -->
                                        <svg class="w-4 h-4 text-tinta-200 cursor-grab shrink-0 hover:text-tinta-400 transition-colors" fill="currentColor" viewBox="0 0 20 20">
                                            <path d="M7 2a2 2 0 1 0 .001 4.001A2 2 0 0 0 7 2zm0 6a2 2 0 1 0 .001 4.001A2 2 0 0 0 7 8zm0 6a2 2 0 1 0 .001 4.001A2 2 0 0 0 7 14zm6-8a2 2 0 1 0-.001-4.001A2 2 0 0 0 13 6zm0 2a2 2 0 1 0 .001 4.001A2 2 0 0 0 13 8zm0 6a2 2 0 1 0 .001 4.001A2 2 0 0 0 13 14z"/>
                                        </svg>
                                        <!-- ↑↓ -->
                                        <div class="flex flex-col shrink-0">
                                            <button @click="moverComponente(plantillaActual.componentes.indexOf(c), -1)"
                                                :disabled="plantillaActual.componentes.indexOf(c) === 0"
                                                class="p-0.5 text-tinta-200 hover:text-tinta-500 disabled:opacity-20 leading-none">
                                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7"/></svg>
                                            </button>
                                            <button @click="moverComponente(plantillaActual.componentes.indexOf(c), 1)"
                                                :disabled="plantillaActual.componentes.indexOf(c) === plantillaActual.componentes.length - 1"
                                                class="p-0.5 text-tinta-200 hover:text-tinta-500 disabled:opacity-20 leading-none">
                                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                                            </button>
                                        </div>
                                        <!-- Info -->
                                        <div class="flex-1 min-w-0">
                                            <p class="text-sm font-semibold text-tinta-900 truncate">{{ c.etiqueta || c.producto?.nombre || '(sin nombre)' }}</p>
                                            <p v-if="!c.sub_formulas?.length" class="text-xs font-mono text-[var(--marca)] mt-0.5 break-all" style="white-space:pre-wrap;">{{ c.formula }}</p>
                                            <p v-else class="text-xs text-blue-600 mt-0.5 font-medium">{{ c.sub_formulas.length }} sub-fórmula{{ c.sub_formulas.length !== 1 ? 's' : '' }}</p>
                                            <p v-if="c.condicion" class="text-xs text-tinta-300 mt-0.5 italic">if {{ c.condicion }}</p>
                                            <div class="flex items-center gap-2 mt-1 flex-wrap">
                                                <span v-if="c.unidad" class="text-xs text-tinta-300">{{ c.unidad }}</span>
                                                <span v-if="!c.incluir_en_precio" class="text-xs bg-tinta-100 text-tinta-400 px-1.5 py-0.5 rounded-full">No suma</span>
                                                <span v-if="c.visible_cliente" class="text-xs bg-green-100 text-green-700 px-1.5 py-0.5 rounded-full">Visible cliente</span>
                                                <span v-if="!c.visible_op" class="text-xs bg-orange-100 text-orange-600 px-1.5 py-0.5 rounded-full">Oculto en OP</span>
                                                <span v-if="c.formula_real && !c.sub_formulas?.length" class="text-xs px-1.5 py-0.5 rounded bg-blue-50 text-blue-600 font-medium">Real ≠ Cot.</span>
                                            </div>
                                        </div>
                                        <!-- Acciones -->
                                        <div class="flex gap-1 opacity-0 group-hover:opacity-100 shrink-0">
                                            <button @click="abrirEditarComponente(c)" class="p-1 rounded-lg text-tinta-300 hover:text-blue-600 hover:bg-blue-50">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                                            </button>
                                            <button @click="eliminarComponente(c)" class="p-1 rounded-lg text-tinta-300 hover:text-red-600 hover:bg-red-50">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                            </button>
                                        </div>
                                    </div>

                                    <!-- INLINE EDIT row -->
                                    <div v-else class="border-t border-[var(--marca-borde)] p-4 space-y-3" style="background:var(--pastel-violeta);">
                                    <div class="flex items-center justify-between">
                                        <p class="text-xs font-semibold text-[var(--marca)] uppercase">Editar componente</p>
                                        <button @click="cancelarComponente" class="w-6 h-6 rounded-full flex items-center justify-center text-tinta-300 hover:bg-[var(--marca-suave)] text-sm leading-none">✕</button>
                                    </div>

                                    <div class="relative">
                                        <label class="block text-xs font-medium text-tinta-500 mb-1">Producto vinculado <span class="text-red-500">*</span></label>
                                        <input v-model="busqProducto" type="text"
                                            :class="['w-full border rounded-lg px-3 py-1.5 text-sm focus:outline-none', compErrorProducto ? 'border-red-400 focus:border-red-500' : 'border-linea']"
                                            placeholder="Buscar por nombre o referencia..." />
                                        <ResultadosBuscadorProducto v-if="resBusqProd.length" :resultados="resBusqProd" @elegir="elegirProducto"
                                            class="absolute z-20 left-0 right-0 bg-superficie border border-linea rounded-xl mt-1 shadow-lg max-h-40 overflow-y-auto" />
                                        <p v-if="compErrorProducto" class="mt-1 text-xs text-red-500">{{ compErrorProducto }}</p>
                                    </div>

                                    <div class="grid grid-cols-2 gap-2">
                                        <div>
                                            <label class="block text-xs font-medium text-tinta-500 mb-1">Etiqueta</label>
                                            <input v-model="compBorrador.etiqueta" type="text" class="w-full border border-linea rounded-lg px-3 py-1.5 text-sm focus:outline-none" />
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium text-tinta-500 mb-1">Unidad</label>
                                            <input v-model="compBorrador.unidad" type="text" class="w-full border border-linea rounded-lg px-3 py-1.5 text-sm font-mono focus:outline-none" placeholder="ML, UN..." />
                                        </div>
                                    </div>

                                    <div>
                                        <label class="block text-xs font-medium text-tinta-500 mb-1">Sección</label>
                                        <select v-model="compBorrador.seccion_id"
                                            class="w-full border border-linea rounded-lg px-3 py-1.5 text-sm focus:outline-none bg-superficie">
                                            <option :value="null">Sin sección</option>
                                            <option v-for="sec in seccionesOrdenadas" :key="sec.id" :value="sec.id">{{ sec.nombre }}</option>
                                        </select>
                                    </div>

                                    <div>
                                        <label class="block text-xs font-medium text-tinta-500 mb-1">Fórmula * <span class="font-normal text-tinta-300">(escribe para autocompletar)</span></label>
                                        <div class="relative">
                                            <textarea
                                                ref="formulaInputEl"
                                                v-model="compBorrador.formula"
                                                rows="4"
                                                spellcheck="false"
                                                :class="['w-full border border-linea rounded-lg px-3 py-1.5 text-sm font-mono focus:outline-none focus:border-[var(--marca)] resize-y min-h-[80px]', formulaActiva ? 'input-formula-activa' : '']"
                                                placeholder="Ej: 2 * alto_vano + 2 * ancho_vano + 0.664"
                                                @input="onFormulaInput"
                                                @focus="formulaActiva = true"
                                                @blur="onFormulaBlur"
                                            />
                                            <ul v-if="formulaSugs.length"
                                                class="absolute z-30 left-0 right-0 mt-1 bg-superficie border border-[var(--marca-borde)] rounded-xl shadow-lg overflow-hidden">
                                                <li v-for="s in formulaSugs" :key="s"
                                                    @mousedown.prevent="insertarVariable(s)"
                                                    class="px-3 py-1.5 text-xs font-mono text-[var(--marca)] hover:bg-[var(--marca-suave)] cursor-pointer">
                                                    {{ s }}
                                                </li>
                                            </ul>
                                        </div>
                                    </div>

                                    <div class="mt-3">
                                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1">
                                            Fórmula Real
                                            <span class="text-tinta-300 font-normal ml-1">(opcional — si vacío usa fórmula cotización)</span>
                                        </label>
                                        <textarea v-model="compBorrador.formula_real" rows="2" placeholder="Ej: largo * ancho (sin desperdicio)"
                                            class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 font-mono resize-none" />
                                        <p v-if="compBorrador.formula_real" class="text-xs text-blue-600 mt-1">
                                            ✓ Se usará esta fórmula para calcular el costo real en producción
                                        </p>
                                        <p v-else class="text-xs text-tinta-300 mt-1">
                                            Si se deja vacío, el costo real = costo cotización
                                        </p>
                                    </div>

                                    <!-- Mini-probador inline -->
                                    <div v-if="compBorrador.formula" class="bg-superficie border border-[var(--marca-borde)] rounded-lg p-3 space-y-2">
                                        <div class="flex items-center justify-between">
                                            <p class="text-xs font-semibold text-[var(--marca)]">▷ Probar esta fórmula</p>
                                            <button @click="ejecutarProbarFormula" :disabled="probarFCarg"
                                                class="px-2.5 py-1 rounded-lg text-xs font-semibold text-white disabled:opacity-60"
                                                style="background:#4338CA;">
                                                {{ probarFCarg ? '...' : 'Calcular' }}
                                            </button>
                                        </div>
                                        <div v-if="camposParaProbar.length" class="grid grid-cols-2 gap-1.5">
                                            <div v-for="c in camposParaProbar" :key="c.nombre">
                                                <label class="block text-[10px] text-[var(--marca)] mb-0.5 font-mono">{{ c.nombre }}</label>
                                                <select v-if="c.subtipo_variable === 'selector'" v-model="probarFVals[c.nombre]"
                                                    class="w-full border border-[var(--marca-borde)] rounded px-2 py-0.5 text-xs font-mono focus:outline-none bg-[var(--marca-suave)]">
                                                    <option v-for="op in c.opciones_selector ?? []" :key="op.valor" :value="op.valor">{{ op.etiqueta }}</option>
                                                </select>
                                                <select v-else-if="c.tipo === 'select'" v-model="probarFVals[c.nombre]"
                                                    class="w-full border border-[var(--marca-borde)] rounded px-2 py-0.5 text-xs font-mono focus:outline-none bg-[var(--marca-suave)]">
                                                    <option v-for="op in c.opciones ?? []" :key="op.valor" :value="op.valor">{{ op.etiqueta }}</option>
                                                </select>
                                                <input v-else-if="c.tipo === 'texto' || c.subtipo_variable === 'texto'" type="text" v-model="probarFVals[c.nombre]"
                                                    class="w-full border border-[var(--marca-borde)] rounded px-2 py-0.5 text-xs font-mono focus:outline-none bg-[var(--marca-suave)]" />
                                                <input v-else type="number" :step="c.tipo === 'decimal' || c.subtipo_variable === 'decimal' ? '0.01' : '1'"
                                                    v-model.number="probarFVals[c.nombre]"
                                                    class="w-full border border-[var(--marca-borde)] rounded px-2 py-0.5 text-xs font-mono focus:outline-none bg-[var(--marca-suave)]" />
                                            </div>
                                        </div>
                                        <p v-else class="text-xs text-tinta-300 italic">Esta plantilla no tiene variables de entrada.</p>
                                        <div v-if="probarFRes !== null" class="bg-[var(--marca-suave)] border border-[var(--marca-borde)] rounded-lg px-3 py-2 space-y-1">
                                            <div class="flex items-baseline justify-between gap-2">
                                                <span class="text-[10px] text-[var(--marca)] shrink-0 whitespace-nowrap">Fórmula cotización:</span>
                                                <span v-if="probarFRes.error" class="text-xs font-mono text-red-600 break-all">✕ {{ probarFRes.error }}</span>
                                                <span v-else class="text-xs font-mono font-semibold text-green-700">= {{ probarFRes.resultado }}</span>
                                            </div>
                                            <div v-if="compBorrador.formula_real && probarFResReal !== null" class="flex items-baseline justify-between gap-2">
                                                <span class="text-[10px] text-[var(--marca)] shrink-0 whitespace-nowrap">Fórmula real:</span>
                                                <span v-if="probarFResReal.error" class="text-xs font-mono text-red-600 break-all">✕ {{ probarFResReal.error }}</span>
                                                <span v-else class="text-xs font-mono font-semibold text-blue-700">= {{ probarFResReal.resultado }}</span>
                                            </div>
                                            <template v-if="compBorrador.sub_formulas?.some(s => s.formula)">
                                                <div class="border-t border-[var(--marca-borde)] pt-1 mt-0.5">
                                                    <span class="text-[10px] text-[var(--marca)]">── Sub-fórmulas de producción ──</span>
                                                </div>
                                                <div v-for="sub in compBorrador.sub_formulas.filter(s => s.formula)" :key="sub.id" class="flex items-baseline justify-between gap-2">
                                                    <span class="text-[10px] text-[var(--marca)] shrink-0 whitespace-nowrap">{{ sub.etiqueta || sub.formula }}:</span>
                                                    <span v-if="sub._probando" class="text-xs font-mono text-[var(--marca)]">...</span>
                                                    <span v-else-if="sub._error" class="text-xs font-mono text-red-600 break-all">✕ {{ sub._error }}</span>
                                                    <span v-else-if="sub._resultado !== null" class="text-xs font-mono font-semibold text-green-700">= {{ sub._resultado }}</span>
                                                    <span v-else class="text-xs font-mono text-tinta-300">—</span>
                                                </div>
                                            </template>
                                        </div>
                                    </div>

                                    <!-- Sub-fórmulas — sección adicional para el documento de producción -->
                                    <div class="border-t border-[var(--marca-borde)] pt-3 mt-1">
                                        <p class="text-xs font-semibold text-blue-700 uppercase tracking-wide mb-2">
                                            Sub-fórmulas de producción
                                            <span class="font-normal text-tinta-300 normal-case ml-1">(opcional — generan filas separadas en la OP)</span>
                                        </p>
                                        <div v-if="compBorrador.sub_formulas?.length" class="space-y-3 mb-2">
                                            <div v-for="(sub, sIdx) in compBorrador.sub_formulas" :key="sub.id"
                                                class="bg-blue-50 border border-blue-200 rounded-lg p-3 space-y-2">
                                                <div class="flex gap-2">
                                                    <input v-model="sub.etiqueta" type="text" placeholder="Etiqueta (ej: Perfil altura izquierdo)"
                                                        class="flex-1 rounded border-tinta-200 text-sm px-2 py-1 focus:outline-none focus:border-[var(--marca)]" />
                                                    <input v-model="sub.unidad" type="text" placeholder="Unidad"
                                                        class="w-20 rounded border-tinta-200 text-sm px-2 py-1 focus:outline-none" />
                                                </div>
                                                <div class="flex gap-2 items-center">
                                                    <input v-model="sub.formula" type="text" placeholder="Fórmula"
                                                        class="flex-1 rounded border-tinta-200 text-sm font-mono px-2 py-1 focus:outline-none focus:border-[var(--marca)]" />
                                                    <button type="button" @click="probarSubFormula(sub, 'formula')" :disabled="sub._probando"
                                                        class="px-2 py-1 text-xs bg-gray-700 text-white rounded hover:bg-gray-900 disabled:opacity-50 shrink-0">
                                                        {{ sub._probando ? '...' : 'Probar' }}
                                                    </button>
                                                    <span :class="['text-xs w-20 truncate shrink-0', sub._error ? 'text-red-600' : 'text-green-700']">
                                                        {{ sub._error ?? (sub._resultado !== null ? sub._resultado : '—') }}
                                                    </span>
                                                </div>
                                                <div class="flex gap-2 items-center">
                                                    <input v-model="sub.formula_real" type="text" placeholder="Fórmula real (opcional)"
                                                        class="flex-1 rounded border-tinta-200 text-sm font-mono px-2 py-1 focus:outline-none" />
                                                    <button type="button" @click="probarSubFormula(sub, 'real')" :disabled="sub._probando_real"
                                                        class="px-2 py-1 text-xs bg-gray-700 text-white rounded hover:bg-gray-900 disabled:opacity-50 shrink-0">
                                                        {{ sub._probando_real ? '...' : 'Probar' }}
                                                    </button>
                                                    <span :class="['text-xs w-20 truncate shrink-0', sub._error_real ? 'text-red-600' : 'text-green-700']">
                                                        {{ sub._error_real ?? (sub._resultado_real !== null ? sub._resultado_real : '—') }}
                                                    </span>
                                                </div>
                                                <button type="button" @click="eliminarSubFormula(compBorrador, sIdx)"
                                                    class="text-xs text-red-500 hover:text-red-700">Eliminar</button>
                                            </div>
                                        </div>
                                        <button type="button" @click="agregarSubFormula(compBorrador)"
                                            class="text-sm text-blue-600 font-medium hover:text-blue-800">
                                            + {{ compBorrador.sub_formulas?.length ? 'Agregar sub-fórmula' : 'Usar sub-fórmulas' }}
                                        </button>
                                    </div>

                                    <div>
                                        <label class="block text-xs font-medium text-tinta-500 mb-1">Condición (opcional)</label>
                                        <input v-model="compBorrador.condicion" type="text"
                                            class="w-full border border-linea rounded-lg px-3 py-1.5 text-sm font-mono focus:outline-none focus:border-amber-400"
                                            placeholder='temperatura == "BAJA"' />
                                    </div>

                                    <div class="flex flex-wrap gap-4">
                                        <label class="flex items-center gap-2 text-xs text-tinta-500 cursor-pointer">
                                            <input v-model="compBorrador.incluir_en_precio" type="checkbox" class="rounded" />
                                            Incluir en precio
                                        </label>
                                        <label class="flex items-center gap-2 text-xs text-tinta-500 cursor-pointer">
                                            <input v-model="compBorrador.visible_cliente" type="checkbox" class="rounded" />
                                            Visible al cliente
                                        </label>
                                        <label class="flex items-center gap-2 text-xs text-tinta-500 cursor-pointer">
                                            <input v-model="compBorrador.visible_op" type="checkbox" class="rounded" />
                                            <span>Visible en OP<span class="block text-[10px] text-tinta-300 font-normal leading-tight">Aparece en la Orden de Producción</span></span>
                                        </label>
                                        <label class="flex items-center gap-2 text-xs text-tinta-500 cursor-pointer">
                                            <input v-model="compBorrador.activo" type="checkbox" class="rounded" />
                                            Activo
                                        </label>
                                    </div>

                                    <div class="flex gap-2">
                                        <button @click="cancelarComponente" class="flex-1 py-2 rounded-xl border border-linea text-xs text-tinta-500 hover:bg-tinta-50">Cancelar</button>
                                        <button @click="guardarComponente" :disabled="guardando || !compBorrador.formula || !compBorrador.producto_id"
                                            class="flex-1 py-2 rounded-xl text-xs text-white font-semibold disabled:opacity-60" style="background:var(--marca);">
                                            {{ guardando ? '...' : 'Actualizar componente' }}
                                        </button>
                                    </div>
                                    </div><!-- /edit row -->
                                    </template><!-- /v-for componentes -->

                                    <!-- Zona vacía (drop hint) -->
                                    <div v-if="!componentesDeSeccion(sec.id).length"
                                        class="px-4 py-6 text-center text-xs italic transition-colors"
                                        :class="dropCompTarget === sec.id && dragCompId !== null ? 'bg-blue-50 text-blue-500' : 'text-tinta-300'">
                                        {{ dragCompId !== null ? '↓ Suelta aquí' : 'Sin componentes — arrastra aquí' }}
                                    </div>
                                </template><!-- /v-if !collapsed -->
                            </div><!-- /section container -->

                            <!-- ── Zona Sin sección ── -->
                            <div class="border-b border-linea transition-colors"
                                :class="dropCompTarget === null && dragCompId !== null ? 'bg-tinta-50' : ''"
                                @dragover.prevent="dragCompId !== null && (dropCompTarget = null)"
                                @drop.prevent="onDropComp($event, null)">

                                <div class="px-4 py-2 flex items-center gap-2 cursor-pointer select-none" style="background:var(--superficie-2);"
                                    @click="toggleSeccion('__sin__')">
                                    <span class="text-xs font-semibold text-tinta-400 uppercase tracking-wide">Sin sección</span>
                                    <span class="text-xs bg-tinta-200 text-tinta-500 px-1.5 py-0.5 rounded-full font-medium leading-none">{{ componentesSinSeccion.length }}</span>
                                    <svg class="w-4 h-4 text-tinta-300 ml-auto transition-transform duration-200"
                                        :class="seccionesColapsadas['__sin__'] ? '-rotate-90' : ''"
                                        fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                                    </svg>
                                </div>

                                <template v-if="!seccionesColapsadas['__sin__']">
                                    <template v-for="c in componentesSinSeccion" :key="c.id">

                                    <!-- READ row sin sección -->
                                    <div v-if="editandoComponente !== c.id"
                                        draggable="true"
                                        @dragstart="onDragStartComp($event, c)"
                                        @dragend="dragCompId = null; dropCompTarget = undefined"
                                        :class="['px-4 py-3 flex items-center gap-2 group border-b border-gray-50 transition-colors', !c.activo ? 'opacity-50' : '', dragCompId === c.id ? 'opacity-40 bg-blue-50' : 'hover:bg-tinta-50']">
                                        <svg class="w-4 h-4 text-tinta-200 cursor-grab shrink-0 hover:text-tinta-400 transition-colors" fill="currentColor" viewBox="0 0 20 20">
                                            <path d="M7 2a2 2 0 1 0 .001 4.001A2 2 0 0 0 7 2zm0 6a2 2 0 1 0 .001 4.001A2 2 0 0 0 7 8zm0 6a2 2 0 1 0 .001 4.001A2 2 0 0 0 7 14zm6-8a2 2 0 1 0-.001-4.001A2 2 0 0 0 13 6zm0 2a2 2 0 1 0 .001 4.001A2 2 0 0 0 13 8zm0 6a2 2 0 1 0 .001 4.001A2 2 0 0 0 13 14z"/>
                                        </svg>
                                        <div class="flex flex-col shrink-0">
                                            <button @click="moverComponente(plantillaActual.componentes.indexOf(c), -1)"
                                                :disabled="plantillaActual.componentes.indexOf(c) === 0"
                                                class="p-0.5 text-tinta-200 hover:text-tinta-500 disabled:opacity-20 leading-none">
                                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7"/></svg>
                                            </button>
                                            <button @click="moverComponente(plantillaActual.componentes.indexOf(c), 1)"
                                                :disabled="plantillaActual.componentes.indexOf(c) === plantillaActual.componentes.length - 1"
                                                class="p-0.5 text-tinta-200 hover:text-tinta-500 disabled:opacity-20 leading-none">
                                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                                            </button>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <p class="text-sm font-semibold text-tinta-900 truncate">{{ c.etiqueta || c.producto?.nombre || '(sin nombre)' }}</p>
                                            <p v-if="!c.sub_formulas?.length" class="text-xs font-mono text-[var(--marca)] mt-0.5 break-all" style="white-space:pre-wrap;">{{ c.formula }}</p>
                                            <p v-else class="text-xs text-blue-600 mt-0.5 font-medium">{{ c.sub_formulas.length }} sub-fórmula{{ c.sub_formulas.length !== 1 ? 's' : '' }}</p>
                                            <p v-if="c.condicion" class="text-xs text-tinta-300 mt-0.5 italic">if {{ c.condicion }}</p>
                                            <div class="flex items-center gap-2 mt-1 flex-wrap">
                                                <span v-if="c.unidad" class="text-xs text-tinta-300">{{ c.unidad }}</span>
                                                <span v-if="!c.incluir_en_precio" class="text-xs bg-tinta-100 text-tinta-400 px-1.5 py-0.5 rounded-full">No suma</span>
                                                <span v-if="c.visible_cliente" class="text-xs bg-green-100 text-green-700 px-1.5 py-0.5 rounded-full">Visible cliente</span>
                                                <span v-if="!c.visible_op" class="text-xs bg-orange-100 text-orange-600 px-1.5 py-0.5 rounded-full">Oculto en OP</span>
                                                <span v-if="c.formula_real && !c.sub_formulas?.length" class="text-xs px-1.5 py-0.5 rounded bg-blue-50 text-blue-600 font-medium">Real ≠ Cot.</span>
                                            </div>
                                        </div>
                                        <div class="flex gap-1 opacity-0 group-hover:opacity-100 shrink-0">
                                            <button @click="abrirEditarComponente(c)" class="p-1 rounded-lg text-tinta-300 hover:text-blue-600 hover:bg-blue-50">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                                            </button>
                                            <button @click="eliminarComponente(c)" class="p-1 rounded-lg text-tinta-300 hover:text-red-600 hover:bg-red-50">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                            </button>
                                        </div>
                                    </div>

                                    <!-- EDIT row sin sección -->
                                    <div v-else class="border-t border-[var(--marca-borde)] p-4 space-y-3" style="background:var(--pastel-violeta);">
                                        <div class="flex items-center justify-between">
                                            <p class="text-xs font-semibold text-[var(--marca)] uppercase">Editar componente</p>
                                            <button @click="cancelarComponente" class="w-6 h-6 rounded-full flex items-center justify-center text-tinta-300 hover:bg-[var(--marca-suave)] text-sm leading-none">✕</button>
                                        </div>
                                        <div class="relative">
                                            <label class="block text-xs font-medium text-tinta-500 mb-1">Producto vinculado <span class="text-red-500">*</span></label>
                                            <input v-model="busqProducto" type="text"
                                                :class="['w-full border rounded-lg px-3 py-1.5 text-sm focus:outline-none', compErrorProducto ? 'border-red-400 focus:border-red-500' : 'border-linea']"
                                                placeholder="Buscar por nombre o referencia..." />
                                            <ResultadosBuscadorProducto v-if="resBusqProd.length" :resultados="resBusqProd" @elegir="elegirProducto"
                                                class="absolute z-20 left-0 right-0 bg-superficie border border-linea rounded-xl mt-1 shadow-lg max-h-40 overflow-y-auto" />
                                            <p v-if="compErrorProducto" class="mt-1 text-xs text-red-500">{{ compErrorProducto }}</p>
                                        </div>
                                        <div class="grid grid-cols-2 gap-2">
                                            <div>
                                                <label class="block text-xs font-medium text-tinta-500 mb-1">Etiqueta</label>
                                                <input v-model="compBorrador.etiqueta" type="text" class="w-full border border-linea rounded-lg px-3 py-1.5 text-sm focus:outline-none" />
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-tinta-500 mb-1">Unidad</label>
                                                <input v-model="compBorrador.unidad" type="text" class="w-full border border-linea rounded-lg px-3 py-1.5 text-sm font-mono focus:outline-none" placeholder="ML, UN..." />
                                            </div>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium text-tinta-500 mb-1">Sección</label>
                                            <select v-model="compBorrador.seccion_id"
                                                class="w-full border border-linea rounded-lg px-3 py-1.5 text-sm focus:outline-none bg-superficie">
                                                <option :value="null">Sin sección</option>
                                                <option v-for="sec in seccionesOrdenadas" :key="sec.id" :value="sec.id">{{ sec.nombre }}</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-medium text-tinta-500 mb-1">Fórmula * <span class="font-normal text-tinta-300">(escribe para autocompletar)</span></label>
                                            <div class="relative">
                                                <textarea ref="formulaInputEl" v-model="compBorrador.formula" rows="4" spellcheck="false"
                                                    :class="['w-full border border-linea rounded-lg px-3 py-1.5 text-sm font-mono focus:outline-none focus:border-[var(--marca)] resize-y min-h-[80px]', formulaActiva ? 'input-formula-activa' : '']"
                                                    placeholder="Ej: 2 * alto_vano + 2 * ancho_vano + 0.664"
                                                    @input="onFormulaInput" @focus="formulaActiva = true" @blur="onFormulaBlur" />
                                                <ul v-if="formulaSugs.length" class="absolute z-30 left-0 right-0 mt-1 bg-superficie border border-[var(--marca-borde)] rounded-xl shadow-lg overflow-hidden">
                                                    <li v-for="s in formulaSugs" :key="s" @mousedown.prevent="insertarVariable(s)"
                                                        class="px-3 py-1.5 text-xs font-mono text-[var(--marca)] hover:bg-[var(--marca-suave)] cursor-pointer">{{ s }}</li>
                                                </ul>
                                            </div>
                                        </div>
                                        <div class="mt-3">
                                            <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1">Fórmula Real <span class="text-tinta-300 font-normal ml-1">(opcional)</span></label>
                                            <textarea v-model="compBorrador.formula_real" rows="2" placeholder="Ej: largo * ancho (sin desperdicio)"
                                                class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 font-mono resize-none" />
                                        </div>
                                        <div v-if="compBorrador.formula" class="bg-superficie border border-[var(--marca-borde)] rounded-lg p-3 space-y-2">
                                            <div class="flex items-center justify-between">
                                                <p class="text-xs font-semibold text-[var(--marca)]">▷ Probar esta fórmula</p>
                                                <button @click="ejecutarProbarFormula" :disabled="probarFCarg" class="px-2.5 py-1 rounded-lg text-xs font-semibold text-white disabled:opacity-60" style="background:#4338CA;">{{ probarFCarg ? '...' : 'Calcular' }}</button>
                                            </div>
                                            <div v-if="camposParaProbar.length" class="grid grid-cols-2 gap-1.5">
                                                <div v-for="c in camposParaProbar" :key="c.nombre">
                                                    <label class="block text-[10px] text-[var(--marca)] mb-0.5 font-mono">{{ c.nombre }}</label>
                                                    <select v-if="c.subtipo_variable === 'selector'" v-model="probarFVals[c.nombre]" class="w-full border border-[var(--marca-borde)] rounded px-2 py-0.5 text-xs font-mono focus:outline-none bg-[var(--marca-suave)]"><option v-for="op in c.opciones_selector ?? []" :key="op.valor" :value="op.valor">{{ op.etiqueta }}</option></select>
                                                    <select v-else-if="c.tipo === 'select'" v-model="probarFVals[c.nombre]" class="w-full border border-[var(--marca-borde)] rounded px-2 py-0.5 text-xs font-mono focus:outline-none bg-[var(--marca-suave)]"><option v-for="op in c.opciones ?? []" :key="op.valor" :value="op.valor">{{ op.etiqueta }}</option></select>
                                                    <input v-else-if="c.tipo === 'texto' || c.subtipo_variable === 'texto'" type="text" v-model="probarFVals[c.nombre]" class="w-full border border-[var(--marca-borde)] rounded px-2 py-0.5 text-xs font-mono focus:outline-none bg-[var(--marca-suave)]" />
                                                    <input v-else type="number" :step="c.tipo === 'decimal' || c.subtipo_variable === 'decimal' ? '0.01' : '1'" v-model.number="probarFVals[c.nombre]" class="w-full border border-[var(--marca-borde)] rounded px-2 py-0.5 text-xs font-mono focus:outline-none bg-[var(--marca-suave)]" />
                                                </div>
                                            </div>
                                            <p v-else class="text-xs text-tinta-300 italic">Sin variables de entrada.</p>
                                            <div v-if="probarFRes !== null" class="bg-[var(--marca-suave)] border border-[var(--marca-borde)] rounded-lg px-3 py-2 space-y-1">
                                                <div class="flex items-baseline justify-between gap-2">
                                                    <span class="text-[10px] text-[var(--marca)] shrink-0 whitespace-nowrap">Fórmula cotización:</span>
                                                    <span v-if="probarFRes.error" class="text-xs font-mono text-red-600 break-all">✕ {{ probarFRes.error }}</span>
                                                    <span v-else class="text-xs font-mono font-semibold text-green-700">= {{ probarFRes.resultado }}</span>
                                                </div>
                                                <div v-if="compBorrador.formula_real && probarFResReal !== null" class="flex items-baseline justify-between gap-2">
                                                    <span class="text-[10px] text-[var(--marca)] shrink-0 whitespace-nowrap">Fórmula real:</span>
                                                    <span v-if="probarFResReal.error" class="text-xs font-mono text-red-600 break-all">✕ {{ probarFResReal.error }}</span>
                                                    <span v-else class="text-xs font-mono font-semibold text-blue-700">= {{ probarFResReal.resultado }}</span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="flex flex-wrap gap-4">
                                            <label class="flex items-center gap-2 text-xs text-tinta-500 cursor-pointer"><input v-model="compBorrador.incluir_en_precio" type="checkbox" class="rounded" /> Incluir en precio</label>
                                            <label class="flex items-center gap-2 text-xs text-tinta-500 cursor-pointer"><input v-model="compBorrador.visible_cliente" type="checkbox" class="rounded" /> Visible al cliente</label>
                                            <label class="flex items-center gap-2 text-xs text-tinta-500 cursor-pointer"><input v-model="compBorrador.visible_op" type="checkbox" class="rounded" /> Visible en OP</label>
                                            <label class="flex items-center gap-2 text-xs text-tinta-500 cursor-pointer"><input v-model="compBorrador.activo" type="checkbox" class="rounded" /> Activo</label>
                                        </div>
                                        <div class="flex gap-2">
                                            <button @click="cancelarComponente" class="flex-1 py-2 rounded-xl border border-linea text-xs text-tinta-500 hover:bg-tinta-50">Cancelar</button>
                                            <button @click="guardarComponente" :disabled="guardando || !compBorrador.formula || !compBorrador.producto_id"
                                                class="flex-1 py-2 rounded-xl text-xs text-white font-semibold disabled:opacity-60" style="background:var(--marca);">
                                                {{ guardando ? '...' : 'Actualizar componente' }}
                                            </button>
                                        </div>
                                    </div><!-- /edit row sin sección -->

                                    </template><!-- /v-for sin sección -->

                                    <!-- Vacío sin sección -->
                                    <div v-if="!componentesSinSeccion.length"
                                        class="px-4 py-6 text-center text-xs italic transition-colors"
                                        :class="dropCompTarget === null && dragCompId !== null ? 'bg-tinta-50 text-tinta-400' : 'text-tinta-300'">
                                        {{ dragCompId !== null ? '↓ Suelta aquí para quitar de sección' : 'Todos los componentes están en una sección' }}
                                    </div>
                                </template><!-- /v-if !collapsed sin sección -->
                            </div><!-- /sin sección -->

                        </div><!-- /secciones container -->

                        <div v-if="editandoComponente === null" class="px-4 py-3 border-t border-linea">
                            <button @click="abrirNuevoComponente"
                                class="w-full py-2 rounded-xl border-2 border-dashed border-linea text-sm text-tinta-400 hover:border-blue-300 hover:text-blue-600 transition-colors">
                                + Agregar componente
                            </button>
                        </div>

                        <!-- Nuevo componente (bottom) -->
                        <div v-if="editandoComponente === 'nuevo'" class="border-t border-[var(--marca-borde)] p-4 space-y-3" style="background:var(--pastel-violeta);">
                            <p class="text-xs font-semibold text-[var(--marca)] uppercase">Nuevo componente</p>

                            <div class="relative">
                                <label class="block text-xs font-medium text-tinta-500 mb-1">Producto vinculado <span class="text-red-500">*</span></label>
                                <input v-model="busqProducto" type="text"
                                    :class="['w-full border rounded-lg px-3 py-1.5 text-sm focus:outline-none', compErrorProducto ? 'border-red-400 focus:border-red-500' : 'border-linea']"
                                    placeholder="Buscar por nombre o referencia..." />
                                <ResultadosBuscadorProducto v-if="resBusqProd.length" :resultados="resBusqProd" @elegir="elegirProducto"
                                    class="absolute z-20 left-0 right-0 bg-superficie border border-linea rounded-xl mt-1 shadow-lg max-h-40 overflow-y-auto" />
                                <p v-if="compErrorProducto" class="mt-1 text-xs text-red-500">{{ compErrorProducto }}</p>
                            </div>

                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="block text-xs font-medium text-tinta-500 mb-1">Etiqueta</label>
                                    <input v-model="compBorrador.etiqueta" type="text" class="w-full border border-linea rounded-lg px-3 py-1.5 text-sm focus:outline-none" />
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-tinta-500 mb-1">Unidad</label>
                                    <input v-model="compBorrador.unidad" type="text" class="w-full border border-linea rounded-lg px-3 py-1.5 text-sm font-mono focus:outline-none" placeholder="ML, UN..." />
                                </div>
                            </div>

                            <div>
                                <label class="block text-xs font-medium text-tinta-500 mb-1">Sección</label>
                                <select v-model="compBorrador.seccion_id"
                                    class="w-full border border-linea rounded-lg px-3 py-1.5 text-sm focus:outline-none bg-superficie">
                                    <option :value="null">Sin sección</option>
                                    <option v-for="sec in seccionesOrdenadas" :key="sec.id" :value="sec.id">{{ sec.nombre }}</option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-xs font-medium text-tinta-500 mb-1">Fórmula * <span class="font-normal text-tinta-300">(escribe para autocompletar)</span></label>
                                <div class="relative">
                                    <textarea
                                        v-model="compBorrador.formula"
                                        rows="4"
                                        spellcheck="false"
                                        :class="['w-full border border-linea rounded-lg px-3 py-1.5 text-sm font-mono focus:outline-none focus:border-[var(--marca)] resize-y min-h-[80px]', formulaActiva ? 'input-formula-activa' : '']"
                                        placeholder="Ej: 2 * alto_vano + 2 * ancho_vano + 0.664"
                                        @input="onFormulaInput"
                                        @focus="formulaActiva = true"
                                        @blur="onFormulaBlur"
                                    />
                                    <ul v-if="formulaSugs.length"
                                        class="absolute z-30 left-0 right-0 mt-1 bg-superficie border border-[var(--marca-borde)] rounded-xl shadow-lg overflow-hidden">
                                        <li v-for="s in formulaSugs" :key="s"
                                            @mousedown.prevent="insertarVariable(s)"
                                            class="px-3 py-1.5 text-xs font-mono text-[var(--marca)] hover:bg-[var(--marca-suave)] cursor-pointer">
                                            {{ s }}
                                        </li>
                                    </ul>
                                </div>
                            </div>

                            <div class="mt-3">
                                <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1">
                                    Fórmula Real
                                    <span class="text-tinta-300 font-normal ml-1">(opcional — si vacío usa fórmula cotización)</span>
                                </label>
                                <textarea v-model="compBorrador.formula_real" rows="2" placeholder="Ej: largo * ancho (sin desperdicio)"
                                    class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 font-mono resize-none" />
                                <p v-if="compBorrador.formula_real" class="text-xs text-blue-600 mt-1">
                                    ✓ Se usará esta fórmula para calcular el costo real en producción
                                </p>
                                <p v-else class="text-xs text-tinta-300 mt-1">
                                    Si se deja vacío, el costo real = costo cotización
                                </p>
                            </div>

                            <!-- Mini-probador inline -->
                            <div v-if="compBorrador.formula" class="bg-superficie border border-[var(--marca-borde)] rounded-lg p-3 space-y-2">
                                <div class="flex items-center justify-between">
                                    <p class="text-xs font-semibold text-[var(--marca)]">▷ Probar esta fórmula</p>
                                    <button @click="ejecutarProbarFormula" :disabled="probarFCarg"
                                        class="px-2.5 py-1 rounded-lg text-xs font-semibold text-white disabled:opacity-60"
                                        style="background:#4338CA;">
                                        {{ probarFCarg ? '...' : 'Calcular' }}
                                    </button>
                                </div>
                                <div v-if="camposParaProbar.length" class="grid grid-cols-2 gap-1.5">
                                    <div v-for="c in camposParaProbar" :key="c.nombre">
                                        <label class="block text-[10px] text-[var(--marca)] mb-0.5 font-mono">{{ c.nombre }}</label>
                                        <select v-if="c.subtipo_variable === 'selector'" v-model="probarFVals[c.nombre]"
                                            class="w-full border border-[var(--marca-borde)] rounded px-2 py-0.5 text-xs font-mono focus:outline-none bg-[var(--marca-suave)]">
                                            <option v-for="op in c.opciones_selector ?? []" :key="op.valor" :value="op.valor">{{ op.etiqueta }}</option>
                                        </select>
                                        <select v-else-if="c.tipo === 'select'" v-model="probarFVals[c.nombre]"
                                            class="w-full border border-[var(--marca-borde)] rounded px-2 py-0.5 text-xs font-mono focus:outline-none bg-[var(--marca-suave)]">
                                            <option v-for="op in c.opciones ?? []" :key="op.valor" :value="op.valor">{{ op.etiqueta }}</option>
                                        </select>
                                        <input v-else-if="c.tipo === 'texto' || c.subtipo_variable === 'texto'" type="text" v-model="probarFVals[c.nombre]"
                                            class="w-full border border-[var(--marca-borde)] rounded px-2 py-0.5 text-xs font-mono focus:outline-none bg-[var(--marca-suave)]" />
                                        <input v-else type="number" :step="c.tipo === 'decimal' || c.subtipo_variable === 'decimal' ? '0.01' : '1'"
                                            v-model.number="probarFVals[c.nombre]"
                                            class="w-full border border-[var(--marca-borde)] rounded px-2 py-0.5 text-xs font-mono focus:outline-none bg-[var(--marca-suave)]" />
                                    </div>
                                </div>
                                <p v-else class="text-xs text-tinta-300 italic">Esta plantilla no tiene variables de entrada.</p>
                                <div v-if="probarFRes !== null" class="bg-[var(--marca-suave)] border border-[var(--marca-borde)] rounded-lg px-3 py-2 space-y-1">
                                    <div class="flex items-baseline justify-between gap-2">
                                        <span class="text-[10px] text-[var(--marca)] shrink-0 whitespace-nowrap">Fórmula cotización:</span>
                                        <span v-if="probarFRes.error" class="text-xs font-mono text-red-600 break-all">✕ {{ probarFRes.error }}</span>
                                        <span v-else class="text-xs font-mono font-semibold text-green-700">= {{ probarFRes.resultado }}</span>
                                    </div>
                                    <div v-if="compBorrador.formula_real && probarFResReal !== null" class="flex items-baseline justify-between gap-2">
                                        <span class="text-[10px] text-[var(--marca)] shrink-0 whitespace-nowrap">Fórmula real:</span>
                                        <span v-if="probarFResReal.error" class="text-xs font-mono text-red-600 break-all">✕ {{ probarFResReal.error }}</span>
                                        <span v-else class="text-xs font-mono font-semibold text-blue-700">= {{ probarFResReal.resultado }}</span>
                                    </div>
                                    <template v-if="compBorrador.sub_formulas?.some(s => s.formula)">
                                        <div class="border-t border-[var(--marca-borde)] pt-1 mt-0.5">
                                            <span class="text-[10px] text-[var(--marca)]">── Sub-fórmulas de producción ──</span>
                                        </div>
                                        <div v-for="sub in compBorrador.sub_formulas.filter(s => s.formula)" :key="sub.id" class="flex items-baseline justify-between gap-2">
                                            <span class="text-[10px] text-[var(--marca)] shrink-0 whitespace-nowrap">{{ sub.etiqueta || sub.formula }}:</span>
                                            <span v-if="sub._probando" class="text-xs font-mono text-[var(--marca)]">...</span>
                                            <span v-else-if="sub._error" class="text-xs font-mono text-red-600 break-all">✕ {{ sub._error }}</span>
                                            <span v-else-if="sub._resultado !== null" class="text-xs font-mono font-semibold text-green-700">= {{ sub._resultado }}</span>
                                            <span v-else class="text-xs font-mono text-tinta-300">—</span>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <!-- Sub-fórmulas — sección adicional para el documento de producción -->
                            <div class="border-t border-[var(--marca-borde)] pt-3 mt-1">
                                <p class="text-xs font-semibold text-blue-700 uppercase tracking-wide mb-2">
                                    Sub-fórmulas de producción
                                    <span class="font-normal text-tinta-300 normal-case ml-1">(opcional — generan filas separadas en la OP)</span>
                                </p>
                                <div v-if="compBorrador.sub_formulas?.length" class="space-y-3 mb-2">
                                    <div v-for="(sub, sIdx) in compBorrador.sub_formulas" :key="sub.id"
                                        class="bg-blue-50 border border-blue-200 rounded-lg p-3 space-y-2">
                                        <div class="flex gap-2">
                                            <input v-model="sub.etiqueta" type="text" placeholder="Etiqueta (ej: Perfil altura izquierdo)"
                                                class="flex-1 rounded border-tinta-200 text-sm px-2 py-1 focus:outline-none focus:border-[var(--marca)]" />
                                            <input v-model="sub.unidad" type="text" placeholder="Unidad"
                                                class="w-20 rounded border-tinta-200 text-sm px-2 py-1 focus:outline-none" />
                                        </div>
                                        <input v-model="sub.formula" type="text" placeholder="Fórmula"
                                            class="w-full rounded border-tinta-200 text-sm font-mono px-2 py-1 focus:outline-none focus:border-[var(--marca)]" />
                                        <input v-model="sub.formula_real" type="text" placeholder="Fórmula real (opcional)"
                                            class="w-full rounded border-tinta-200 text-sm font-mono px-2 py-1 focus:outline-none" />
                                        <button type="button" @click="eliminarSubFormula(compBorrador, sIdx)"
                                            class="text-xs text-red-500 hover:text-red-700">Eliminar</button>
                                    </div>
                                </div>
                                <button type="button" @click="agregarSubFormula(compBorrador)"
                                    class="text-sm text-blue-600 font-medium hover:text-blue-800">
                                    + {{ compBorrador.sub_formulas?.length ? 'Agregar sub-fórmula' : 'Usar sub-fórmulas' }}
                                </button>
                            </div>

                            <div>
                                <label class="block text-xs font-medium text-tinta-500 mb-1">Condición (opcional)</label>
                                <input v-model="compBorrador.condicion" type="text"
                                    class="w-full border border-linea rounded-lg px-3 py-1.5 text-sm font-mono focus:outline-none focus:border-amber-400"
                                    placeholder='temperatura == "BAJA"' />
                            </div>

                            <div class="flex flex-wrap gap-4">
                                <label class="flex items-center gap-2 text-xs text-tinta-500 cursor-pointer">
                                    <input v-model="compBorrador.incluir_en_precio" type="checkbox" class="rounded" />
                                    Incluir en precio
                                </label>
                                <label class="flex items-center gap-2 text-xs text-tinta-500 cursor-pointer">
                                    <input v-model="compBorrador.visible_cliente" type="checkbox" class="rounded" />
                                    Visible al cliente
                                </label>
                                <label class="flex items-center gap-2 text-xs text-tinta-500 cursor-pointer">
                                    <input v-model="compBorrador.visible_op" type="checkbox" class="rounded" />
                                    <span>Visible en OP<span class="block text-[10px] text-tinta-300 font-normal leading-tight">Aparece en la Orden de Producción</span></span>
                                </label>
                                <label class="flex items-center gap-2 text-xs text-tinta-500 cursor-pointer">
                                    <input v-model="compBorrador.activo" type="checkbox" class="rounded" />
                                    Activo
                                </label>
                            </div>

                            <div class="flex gap-2">
                                <button @click="cancelarComponente" class="flex-1 py-2 rounded-xl border border-linea text-xs text-tinta-500 hover:bg-tinta-50">Cancelar</button>
                                <button @click="guardarComponente" :disabled="guardando || !compBorrador.formula || !compBorrador.producto_id"
                                    class="flex-1 py-2 rounded-xl text-xs text-white font-semibold disabled:opacity-60" style="background:var(--marca);">
                                    {{ guardando ? '...' : 'Guardar componente' }}
                                </button>
                            </div>
                        </div>
                        </div><!-- /col-izquierda -->

                        <!-- ── Columna derecha: Variables sticky (solo lg+) ── -->
                        <div class="hidden lg:block lg:w-[30%] shrink-0 border-l border-linea self-start sticky top-[72px] max-h-[calc(100vh-5rem)] overflow-y-auto">
                                <div class="px-4 py-3" style="background:var(--marca);">
                                    <p class="text-xs font-semibold text-white uppercase tracking-[0.12em]">Variables disponibles</p>
                                    <p class="text-xs text-blue-200 mt-0.5">Clic para insertar en fórmula activa</p>
                                </div>
                                <div class="px-4 py-3 space-y-3">
                                    <div v-if="camposEntrada.length">
                                        <p class="text-xs font-semibold text-tinta-400 uppercase tracking-wide mb-1.5">Entradas</p>
                                        <div class="flex flex-wrap gap-1.5">
                                            <button v-for="c in camposEntrada" :key="c.nombre" @click="copiarVariable(c.nombre)"
                                                :class="['px-2 py-0.5 rounded-full text-xs font-mono border transition-all', chipCopiado === c.nombre ? 'bg-green-100 text-green-700 border-green-300' : 'bg-blue-50 text-blue-700 border-blue-200 hover:bg-blue-100']">
                                                {{ chipCopiado === c.nombre ? '✓ ' : '' }}{{ '{' + c.nombre + '}' }}
                                            </button>
                                        </div>
                                    </div>
                                    <div v-if="camposCalculados.length">
                                        <p class="text-xs font-semibold text-tinta-400 uppercase tracking-wide mb-1.5">Calculadas</p>
                                        <div class="flex flex-wrap gap-1.5">
                                            <button v-for="c in camposCalculados" :key="c.nombre" @click="copiarVariable(c.nombre)"
                                                :class="['px-2 py-0.5 rounded-full text-xs font-mono border transition-all', chipCopiado === c.nombre ? 'bg-green-100 text-green-700 border-green-300' : 'bg-purple-50 text-purple-700 border-purple-200 hover:bg-purple-100']">
                                                {{ chipCopiado === c.nombre ? '✓ ' : '' }}{{ '{' + c.nombre + '}' }}
                                            </button>
                                        </div>
                                    </div>
                                    <div v-if="camposVariableInstancia.length">
                                        <p class="text-xs font-semibold text-tinta-400 uppercase tracking-wide mb-1.5">Instancia</p>
                                        <div class="flex flex-wrap gap-1.5">
                                            <button v-for="c in camposVariableInstancia" :key="c.nombre" @click="copiarVariable(c.nombre)"
                                                :class="['px-2 py-0.5 rounded-full text-xs font-mono border transition-all', chipCopiado === c.nombre ? 'bg-green-100 text-green-700 border-green-300' : 'bg-amber-50 text-amber-700 border-amber-200 hover:bg-amber-100']">
                                                {{ chipCopiado === c.nombre ? '✓ ' : '' }}{{ '{' + c.nombre + '}' }}
                                            </button>
                                        </div>
                                    </div>
                                    <p v-if="!camposEntrada.length && !camposCalculados.length && !camposVariableInstancia.length"
                                        class="text-xs text-tinta-300 italic py-2">Sin variables. Ve al tab Campos.</p>
                                </div>
                        </div>
                        </div><!-- /layout-dos-columnas -->
                    </div>

                    <!-- ══════════════ Tab: Pasos de producción ═══════════════ -->
                    <div v-if="tabActivo === 'produccion'" class="bg-superficie rounded-b-2xl shadow-sm p-5 space-y-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3 class="text-sm font-semibold text-tinta-700">Pasos de producción</h3>
                                <p class="text-xs text-tinta-300 mt-0.5">Se generan solos al crear la OP — nadie tiene que asignarlos a mano en producción. El peso de cada paso se calcula solo según la dificultad.</p>
                            </div>
                            <span :class="['shrink-0 text-xs font-semibold px-2 py-1 rounded-lg', Math.abs(sumaPesosPasos - 100) < 0.05 ? 'bg-green-50 text-green-700' : 'bg-amber-50 text-amber-700']">
                                Suma: {{ sumaPesosPasos.toFixed(1) }}%
                            </span>
                        </div>

                        <p v-if="!(plantillaActual.pasosTrabajo ?? []).length"
                            class="text-center py-6 text-tinta-300 text-sm bg-tinta-50 rounded-xl">
                            Todavía no hay pasos cargados. Los ítems de esta plantilla igual generan su trabajo al crear la OP, pero sin pasos hasta que agregues alguno acá.
                        </p>

                        <!-- Lista de pasos -->
                        <div class="space-y-2">
                            <div v-for="(paso, idx) in (plantillaActual.pasosTrabajo ?? [])" :key="idx"
                                @click="pasoActivo = (pasoActivo === idx ? null : idx)"
                                :class="['border rounded-xl px-3 py-2.5 cursor-pointer transition-colors',
                                    pasoActivo === idx ? 'border-blue-300 bg-blue-50/40' : 'border-linea hover:bg-tinta-50']">
                                <div class="flex items-center gap-3">
                                    <div class="flex flex-col shrink-0" @click.stop>
                                        <button @click="subirPasoProduccion(idx)" :disabled="idx === 0" class="text-tinta-200 hover:text-tinta-500 disabled:opacity-30 leading-none">▲</button>
                                        <button @click="bajarPasoProduccion(idx)" :disabled="idx === plantillaActual.pasosTrabajo.length - 1" class="text-tinta-200 hover:text-tinta-500 disabled:opacity-30 leading-none">▼</button>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-medium text-tinta-900 truncate">
                                            {{ paso.nombre || 'Sin nombre' }}
                                            <span v-if="paso.es_paso_final" class="ml-1 text-[10px] font-semibold text-purple-600 bg-purple-50 px-1.5 py-0.5 rounded align-middle">★ FINAL</span>
                                            <span v-if="paso.depende_de?.length" class="ml-1 text-[10px] text-tinta-300">→ requiere {{ paso.depende_de.length }} paso(s)</span>
                                        </p>
                                        <p class="text-xs text-tinta-300 truncate" v-html="paso.objetivo || paso.descripcion || 'Sin descripción'"></p>
                                    </div>
                                    <span class="shrink-0 text-xs" :class="colorDificultad[paso.nivel_dificultad ?? 1]">{{ labelDificultad[paso.nivel_dificultad ?? 1] }}</span>
                                    <span class="shrink-0 text-xs font-mono text-tinta-400">{{ formatPct(paso.peso_porcentaje) }}%</span>
                                    <button @click.stop="quitarPasoProduccion(idx)" class="shrink-0 text-xs text-red-500 hover:text-red-700">Eliminar</button>
                                </div>

                                <!-- Detalle expandido del paso -->
                                <div v-if="pasoActivo === idx" @click.stop class="mt-3 pt-3 border-t border-linea space-y-3">
                                    <div>
                                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1">Nombre del paso *</label>
                                        <input v-model="paso.nombre" placeholder="Ej: Cortar lámina"
                                            class="w-full border border-linea rounded-lg px-3 py-2 text-sm focus:outline-none" />
                                    </div>

                                    <div>
                                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1">Objetivo</label>
                                        <input v-model="paso.objetivo" placeholder="Qué se busca lograr con este paso"
                                            class="w-full border border-linea rounded-lg px-3 py-2 text-sm focus:outline-none" />
                                    </div>

                                    <div>
                                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1">
                                            Descripción <span class="text-tinta-300 font-normal normal-case">(puedes usar {variable})</span>
                                        </label>
                                        <RichTextEditor v-model="paso.descripcion" placeholder="Detalle del paso — usa negrita, viñetas, etc." />
                                        <p v-if="variablesDisponibles.length" class="text-xs text-tinta-300 mt-1">
                                            Variables:
                                            <span v-for="v in variablesDisponibles" :key="v" class="font-mono bg-superficie border border-linea rounded px-1 mr-1">{{ '{' + v + '}' }}</span>
                                        </p>
                                    </div>

                                    <div>
                                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1">Dificultad</label>
                                        <div class="flex items-center gap-3">
                                            <div class="flex items-center gap-0.5">
                                                <button v-for="s in 5" :key="s" type="button"
                                                    @click="paso.nivel_dificultad = s; recalcularPesosProduccion()"
                                                    class="text-2xl leading-none transition-colors"
                                                    :class="s <= (paso.nivel_dificultad ?? 1) ? 'text-yellow-400' : 'text-gray-200'">★</button>
                                            </div>
                                            <span class="text-xs text-tinta-300 font-mono">Peso automático: {{ formatPct(paso.peso_porcentaje) }}%</span>
                                        </div>
                                    </div>

                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="checkbox" v-model="paso.es_paso_final"
                                            @change="marcarPasoFinalProduccion(idx)" class="rounded accent-purple-600" />
                                        <span class="text-xs font-semibold text-purple-700">Paso final</span>
                                        <span class="text-xs text-tinta-300">(cierra el trabajo al completarse)</span>
                                    </label>

                                    <div v-if="(plantillaActual.pasosTrabajo ?? []).length > 1">
                                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1">Requiere completar primero:</label>
                                        <div class="space-y-1">
                                            <template v-for="(otroPaso, oIdx) in plantillaActual.pasosTrabajo" :key="oIdx">
                                                <label v-if="oIdx !== idx" class="flex items-center gap-2 cursor-pointer">
                                                    <input type="checkbox" :value="oIdx" v-model="paso.depende_de" class="rounded" />
                                                    <span class="text-xs text-tinta-500">Paso {{ oIdx + 1 }}: {{ otroPaso.nombre || 'Sin nombre' }}</span>
                                                </label>
                                            </template>
                                        </div>
                                    </div>

                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1">Imagen de referencia</label>
                                            <img v-if="paso.imagen" :src="urlAdjunto(paso.imagen)" class="w-full h-20 object-cover rounded-lg border border-linea mb-1.5" />
                                            <label class="block w-full text-center py-1.5 rounded-lg border border-dashed border-tinta-200 text-xs text-tinta-400 hover:border-blue-300 hover:text-blue-600 cursor-pointer">
                                                {{ subiendoAdjunto === 'imagen' ? 'Subiendo...' : (paso.imagen ? 'Cambiar imagen' : '+ Adjuntar imagen') }}
                                                <input type="file" accept="image/*" class="hidden"
                                                    @change="e => subirAdjuntoPasoProduccion(idx, 'imagen', e.target.files[0])" />
                                            </label>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1">Plano</label>
                                            <a v-if="paso.archivo_plano" :href="urlAdjunto(paso.archivo_plano)" target="_blank"
                                                class="block text-xs text-blue-600 hover:underline truncate mb-1.5">Ver plano adjunto</a>
                                            <label class="block w-full text-center py-1.5 rounded-lg border border-dashed border-tinta-200 text-xs text-tinta-400 hover:border-blue-300 hover:text-blue-600 cursor-pointer">
                                                {{ subiendoAdjunto === 'plano' ? 'Subiendo...' : (paso.archivo_plano ? 'Cambiar plano' : '+ Adjuntar plano') }}
                                                <input type="file" accept="image/*,.pdf" class="hidden"
                                                    @change="e => subirAdjuntoPasoProduccion(idx, 'plano', e.target.files[0])" />
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <button @click="agregarPasoProduccion"
                            class="w-full py-2.5 rounded-xl border-2 border-dashed border-linea text-sm text-tinta-400 hover:border-blue-300 hover:text-blue-600">
                            + Agregar paso de producción
                        </button>

                        <div class="flex justify-end pt-1">
                            <button @click="guardarPasosProduccion" :disabled="guardandoPasos"
                                class="px-5 py-2 rounded-lg text-sm text-white font-semibold disabled:opacity-60" style="background:var(--marca);">
                                {{ guardandoPasos ? 'Guardando...' : 'Guardar pasos' }}
                            </button>
                        </div>
                    </div>

                    <!-- ══════════════ Tab: Config. Salida ════════════════════ -->
                    <div v-if="tabActivo === 'salida' && plantillaActual.config_salida" class="bg-superficie rounded-b-2xl shadow-sm p-5 space-y-5">
                        <div>
                            <h3 class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-3">Comportamiento en cotización</h3>
                            <div class="space-y-3">
                                <label class="flex items-center gap-3 cursor-pointer">
                                    <input v-model="plantillaActual.config_salida.mostrar_desglose" type="checkbox" class="rounded" />
                                    <span class="text-sm text-tinta-700">Mostrar desglose al agregar a cotización</span>
                                </label>
                                <label class="flex items-center gap-3 cursor-pointer">
                                    <input v-model="plantillaActual.config_salida.permitir_editar_precio" type="checkbox" class="rounded" />
                                    <span class="text-sm text-tinta-700">Permitir editar precio manualmente</span>
                                </label>
                                <label class="flex items-center gap-3 cursor-pointer">
                                    <input v-model="plantillaActual.config_salida.mostrar_precio_costo" type="checkbox" class="rounded" />
                                    <span class="text-sm text-tinta-700">Mostrar precio costo</span>
                                </label>
                            </div>
                        </div>

                        <div>
                            <h3 class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-2">Precio por canal</h3>
                            <div class="bg-blue-50 border border-blue-100 rounded-lg px-4 py-3">
                                <p class="text-xs text-blue-700 leading-relaxed">
                                    El precio se determina automáticamente según el canal del cliente al cotizar:
                                    <strong>mayorista, distribuidor o cliente final</strong>.
                                    Los precios se configuran directamente al crear o editar el ensamble desde esta plantilla.
                                </p>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-medium text-tinta-500 mb-1">Etiqueta del precio</label>
                                <input v-model="plantillaActual.config_salida.etiqueta_precio" type="text"
                                    class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none" />
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-tinta-500 mb-1">Nota al pie</label>
                                <input v-model="plantillaActual.config_salida.nota_pie" type="text"
                                    class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none" />
                            </div>
                        </div>

                        <button @click="guardarConfigSalida" :disabled="guardando"
                            class="px-5 py-2.5 rounded-xl text-sm text-white font-semibold disabled:opacity-60"
                            style="background:var(--marca);">
                            {{ guardando ? 'Guardando...' : 'Guardar configuración' }}
                        </button>
                    </div>

                </section>
            </div>
        </div>

        <!-- ── Toast notificación ─────────────────────────────────────────────────── -->
        <Teleport to="body">
            <Transition enter-active-class="transition-all duration-300" enter-from-class="opacity-0 translate-y-4" leave-active-class="transition-all duration-200" leave-to-class="opacity-0 translate-y-4">
                <div v-if="toastMsg"
                    class="fixed bottom-24 sm:bottom-6 left-1/2 -translate-x-1/2 z-[60] px-5 py-3 rounded-2xl shadow-xl text-sm font-medium flex items-center gap-3 min-w-[280px] max-w-sm"
                    :style="toastTipo === 'success' ? 'background:#065F46; color:white;' : toastTipo === 'warning' ? 'background:#92400E; color:white;' : 'background:#991B1B; color:white;'">
                    <span class="text-base leading-none">{{ toastTipo === 'success' ? '✓' : toastTipo === 'warning' ? '⚠' : '✕' }}</span>
                    <span class="flex-1">{{ toastMsg }}</span>
                    <button @click="toastMsg = ''" class="opacity-70 hover:opacity-100 leading-none">✕</button>
                </div>
            </Transition>
        </Teleport>

        <!-- ── Modal nueva plantilla ────────────────────────────────────────────── -->
        <Teleport to="body">
            <div v-if="modalNueva" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-4" style="background:rgba(0,0,0,0.5);">
                <div class="bg-superficie rounded-2xl shadow-xl w-full max-w-sm p-5">
                    <h3 class="text-base font-semibold text-tinta-900 mb-4">Nueva plantilla de ensamble</h3>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-sm font-medium text-tinta-700 mb-1">Nombre *</label>
                            <input v-model="nuevaData.nombre" type="text" @keyup.enter="crearPlantilla"
                                class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none"
                                placeholder="Ej: Puerta Frigorífica Batiente" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-tinta-700 mb-1">Descripción corta</label>
                            <input v-model="nuevaData.descripcion_corta" type="text"
                                class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none"
                                placeholder="Opcional..." />
                        </div>
                    </div>
                    <div class="flex gap-3 mt-4">
                        <button @click="modalNueva = false" class="flex-1 py-2.5 rounded-xl border border-linea text-sm text-tinta-500">Cancelar</button>
                        <button @click="crearPlantilla" :disabled="guardando || !nuevaData.nombre"
                            class="flex-1 py-2.5 rounded-xl text-white text-sm font-semibold disabled:opacity-60" style="background:var(--marca);">
                            {{ guardando ? 'Creando...' : 'Crear' }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- ── Drawer: Probar plantilla ─────────────────────────────────────────── -->
        <Teleport to="body">
            <div v-if="drawerProbar" class="fixed inset-0 z-50 flex justify-end" style="background:rgba(0,0,0,0.45);" @click.self="drawerProbar = false">
                <!-- Panel: bottom sheet en mobile, sidebar derecha en desktop -->
                <div class="w-full sm:w-[440px] bg-superficie shadow-2xl flex flex-col h-[90vh] sm:h-full rounded-t-3xl sm:rounded-none mt-auto sm:mt-0">

                    <!-- Header -->
                    <div class="shrink-0 flex items-center justify-between px-5 py-4 border-b border-linea">
                        <div>
                            <p class="text-xs text-tinta-300">Probando</p>
                            <h3 class="text-sm font-semibold text-tinta-900">{{ plantillaActual?.nombre }}</h3>
                        </div>
                        <button @click="drawerProbar = false" class="w-8 h-8 rounded-full flex items-center justify-center text-tinta-300 hover:bg-tinta-100">✕</button>
                    </div>

                    <!-- Campos del formulario + resultados -->
                    <div class="flex-1 overflow-y-auto p-5 space-y-3">
                        <template v-for="campo in (plantillaActual?.campos ?? []).filter(c => (c.tipo_campo ?? 'entrada') !== 'calculado')" :key="campo.id">
                            <div>
                                <label class="block text-xs font-medium text-tinta-700 mb-1">
                                    {{ campo.etiqueta || campo.nombre }}
                                    <span v-if="campo.ayuda" class="font-normal text-tinta-300"> — {{ campo.ayuda }}</span>
                                </label>
                                <select v-if="campo.subtipo_variable === 'selector'" v-model="probarValores[campo.nombre]"
                                    class="w-full border border-linea rounded-lg px-3 py-1.5 text-sm focus:outline-none">
                                    <option v-for="op in campo.opciones_selector ?? []" :key="op.valor" :value="op.valor">{{ op.etiqueta }}</option>
                                </select>
                                <select v-else-if="campo.tipo === 'select'" v-model="probarValores[campo.nombre]"
                                    class="w-full border border-linea rounded-lg px-3 py-1.5 text-sm focus:outline-none">
                                    <option v-for="op in campo.opciones ?? []" :key="op.valor" :value="op.valor">{{ op.etiqueta }}</option>
                                </select>
                                <input v-else-if="campo.tipo === 'decimal' || campo.tipo === 'numero' || campo.subtipo_variable === 'decimal' || campo.subtipo_variable === 'numero'"
                                    v-model.number="probarValores[campo.nombre]" type="number"
                                    :step="campo.tipo === 'decimal' || campo.subtipo_variable === 'decimal' ? '0.01' : '1'"
                                    class="w-full border border-linea rounded-lg px-3 py-1.5 text-sm focus:outline-none" />
                                <div v-else-if="campo.tipo === 'boolean' || campo.tipo === 'checkbox'" class="flex items-center gap-2">
                                    <input v-model="probarValores[campo.nombre]" type="checkbox" class="rounded" />
                                    <span class="text-xs text-tinta-500">{{ probarValores[campo.nombre] ? 'Sí' : 'No' }}</span>
                                </div>
                                <input v-else v-model="probarValores[campo.nombre]" type="text"
                                    class="w-full border border-linea rounded-lg px-3 py-1.5 text-sm focus:outline-none" />
                            </div>
                        </template>

                        <!-- Resultados -->
                        <div v-if="probarResultado" class="border-t border-linea pt-4 mt-2">
                            <p class="text-xs font-semibold text-tinta-400 uppercase mb-3">Resultado</p>
                            <table class="w-full text-xs">
                                <thead><tr class="text-left text-tinta-300">
                                    <th class="pb-2">Componente</th>
                                    <th class="pb-2 text-right">Cant.</th>
                                    <th class="pb-2 text-right">P.Unit.</th>
                                    <th class="pb-2 text-right">Subtotal</th>
                                </tr></thead>
                                <tbody class="divide-y divide-gray-50">
                                    <tr v-for="(c, i) in probarResultado.componentes" :key="i">
                                        <td class="py-1.5 font-medium text-tinta-700">{{ c.nombre }} <span class="text-tinta-300 font-normal">{{ c.unidad }}</span></td>
                                        <td class="py-1.5 text-right font-mono">{{ c.cantidad }}</td>
                                        <td class="py-1.5 text-right text-tinta-400">${{ formatCOP(c.precio_unit) }}</td>
                                        <td class="py-1.5 text-right font-semibold">${{ formatCOP(c.subtotal) }}</td>
                                    </tr>
                                </tbody>
                                <tfoot><tr class="border-t border-linea">
                                    <td colspan="3" class="pt-2 font-semibold text-tinta-700 text-xs uppercase">Total costo</td>
                                    <td class="pt-2 text-right font-semibold text-blue-700">${{ formatCOP(probarResultado.total_costo) }}</td>
                                </tr></tfoot>
                            </table>
                        </div>

                        <div v-if="!(plantillaActual?.campos ?? []).filter(c => (c.tipo_campo ?? 'entrada') !== 'calculado').length" class="text-center py-8 text-tinta-300 text-sm">
                            Esta plantilla no tiene campos de entrada configurados.
                        </div>
                    </div>

                    <!-- Footer: botón calcular -->
                    <div class="shrink-0 px-5 py-4 border-t border-linea">
                        <button @click="ejecutarProbar" :disabled="probarCargando"
                            class="w-full py-2.5 rounded-xl text-sm text-white font-semibold disabled:opacity-60"
                            style="background:var(--marca);">
                            {{ probarCargando ? 'Calculando...' : '▷ Calcular' }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

    </AppLayout>
</template>
