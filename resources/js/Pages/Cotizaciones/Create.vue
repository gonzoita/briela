<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import EditorTexto from '@/Components/EditorTexto.vue'
import ResultadosBuscadorProducto from '@/Components/ResultadosBuscadorProducto.vue'
import { useUnsavedChanges } from '@/composables/useUnsavedChanges'

const props = defineProps({
    cotizacion:            { type: Object, default: null },
    responsables:          Array,
    usuario_actual:        Number,
    condiciones_default:   String,
    plantillas:            { type: Array, default: () => [] },
    lead_preseleccionado:  { type: Object, default: null },
})

const esEdicion = computed(() => !!props.cotizacion)
const { hasChanges, setOriginal, checkChanges, markClean } = useUnsavedChanges()
onMounted(() => setOriginal(form.data()))

const hoy   = new Date().toISOString().split('T')[0]
const hoy30 = new Date(Date.now() + 30 * 86400000).toISOString().split('T')[0]

const form = useForm({
    lead_id:                  props.cotizacion?.lead_id ?? props.lead_preseleccionado?.id ?? null,
    cliente_id:               props.cotizacion?.cliente_id ?? props.lead_preseleccionado?.cliente?.id ?? null,
    contacto_id:              props.cotizacion?.contacto_id ?? null,
    nombre_contacto_override: props.cotizacion?.nombre_contacto_override ?? '',
    moneda:                   props.cotizacion?.moneda ?? 'COP',
    tasa_cambio:              props.cotizacion?.tasa_cambio ?? 1,
    fecha_creacion:           props.cotizacion?.fecha_creacion ?? hoy,
    fecha_validez:            props.cotizacion?.fecha_validez ?? hoy30,
    responsable_id:           props.cotizacion?.responsable_id ?? props.usuario_actual,
    condiciones_comerciales:  props.cotizacion?.condiciones_comerciales ?? props.condiciones_default,
    notas_internas:           props.cotizacion?.notas_internas ?? '',
    items: props.cotizacion?.items?.map(i => ({
        id:                   i.id,
        tipo:                 i.tipo,
        producto_id:          i.producto_id,
        ensamble_id:          i.ensamble_id,
        variables_snapshot:   i.variables_snapshot,
        componentes_snapshot: i.componentes_snapshot,
        variables_instancia:  i.variables_instancia ?? null,
        imagenes_instancia:   i.imagenes_instancia ?? [],
        orden:                i.orden,
        descripcion:          i.descripcion,
        descripcion_corta:    i.descripcion_corta ?? '',
        descripcion_larga:    i.descripcion_larga ?? '',
        cantidad:             parseFloat(i.cantidad),
        precio_unitario:      parseFloat(i.precio_unitario),
        // Cotizaciones creadas antes de este fix no tienen precio_mayorista_base
        // guardado (queda en 0) — para esas se cae al precio mayorista actual
        // del producto/ensamble como mejor aproximación disponible.
        precio_mayorista_base: parseFloat(i.precio_mayorista_base) || parseFloat(i.producto?.precio_mayorista ?? i.ensamble?.precio_mayorista ?? 0) || 0,
        descuento_pct:        parseFloat(i.descuento_pct),
        impuesto_pct:         parseFloat(i.impuesto_pct),
        comision_pct_minima:         parseFloat(i.producto?.comision_pct_minima          ?? i.ensamble?.comision_pct_minima          ?? 0),
        comision_pct_maxima:         parseFloat(i.producto?.comision_pct_maxima          ?? i.ensamble?.comision_pct_maxima          ?? 0),
        comision_pct_aplicada:       parseFloat(i.comision_pct_aplicada                  ?? i.producto?.comision_pct_maxima          ?? i.ensamble?.comision_pct_maxima          ?? 0),
        comision_pct_actual:         parseFloat(i.comision_pct_aplicada                  ?? i.producto?.comision_pct_maxima          ?? i.ensamble?.comision_pct_maxima          ?? 0),
        comision_min_distribuidor:   parseFloat(i.producto?.comision_min_distribuidor    ?? i.ensamble?.comision_min_distribuidor    ?? 0),
        comision_max_distribuidor:   parseFloat(i.producto?.comision_max_distribuidor    ?? i.ensamble?.comision_max_distribuidor    ?? 0),
        comision_min_cliente_final:  parseFloat(i.producto?.comision_min_cliente_final   ?? i.ensamble?.comision_min_cliente_final   ?? 0),
        comision_max_cliente_final:  parseFloat(i.producto?.comision_max_cliente_final   ?? i.ensamble?.comision_max_cliente_final   ?? 0),
        precio_lista:                parseFloat(i.precio_unitario),
        precio_minimo_absoluto:      0,
        descuento_max_cliente_final: parseFloat(i.producto?.descuento_max_cliente_final  ?? i.ensamble?.descuento_max_cliente_final  ?? 0),
        descuento_max_distribuidor:  parseFloat(i.producto?.descuento_max_distribuidor   ?? i.ensamble?.descuento_max_distribuidor   ?? 0),
        descuento_max_mayorista:     parseFloat(i.producto?.descuento_max_mayorista      ?? i.ensamble?.descuento_max_mayorista      ?? 0),
    })) ?? [],
})

watch(() => form.data(), (v) => checkChanges(v), { deep: true })

// ─── Cliente ──────────────────────────────────────────────────────────────────
const clienteQuery       = ref(props.cotizacion?.cliente?.nombre ?? props.lead_preseleccionado?.cliente?.nombre ?? '')
const clienteResultados  = ref([])
const clienteSeleccionado = ref(props.cotizacion?.cliente ?? props.lead_preseleccionado?.cliente ?? null)
const contactos          = ref(props.cotizacion?.cliente?.contactos ?? props.lead_preseleccionado?.cliente?.contactos ?? [])

// ─── Canal de precio según tipo de contacto ───────────────────────────────────
const canalCliente = computed(() => {
    const tipos = clienteSeleccionado.value?.tipos_contacto || []
    if (tipos.includes('mayorista')) return 'mayorista'
    if (tipos.includes('distribuidor')) return 'distribuidor'
    return 'cliente_final'
})
function getPrecioSegunCanal(data) {
    const canal = canalCliente.value
    if (canal === 'mayorista')    return parseFloat(data.precio_mayorista)    || 0
    if (canal === 'distribuidor') return parseFloat(data.precio_distribuidor) || 0
    return parseFloat(data.precio_cliente_final) || 0
}
function getDescuentoMaxSegunCanal(data) {
    const canal = canalCliente.value
    if (canal === 'mayorista')    return parseFloat(data.descuento_max_mayorista)    || 0
    if (canal === 'distribuidor') return parseFloat(data.descuento_max_distribuidor) || 0
    return parseFloat(data.descuento_max_cliente_final) || 0
}
function getPrecioMinimo(data) {
    const canal  = canalCliente.value
    const precio = getPrecioSegunCanal(data)
    if (canal === 'mayorista') return precio
    if (canal === 'distribuidor') {
        const dtoMax = parseFloat(data.descuento_max_distribuidor) || 0
        return Math.round(precio * (1 - dtoMax / 100))
    }
    const dtoMax = parseFloat(data.descuento_max_cliente_final) || 0
    return Math.round(precio * (1 - dtoMax / 100))
}
function getCanalComisionMin(data) {
    const canal = canalCliente.value
    if (canal === 'distribuidor')  return parseFloat(data.comision_min_distribuidor)  || 0
    if (canal === 'cliente_final') return parseFloat(data.comision_min_cliente_final) || 0
    return 0
}
function getCanalComisionMax(data) {
    const canal = canalCliente.value
    if (canal === 'distribuidor')  return parseFloat(data.comision_max_distribuidor)  || 0
    if (canal === 'cliente_final') return parseFloat(data.comision_max_cliente_final) || 0
    return 0
}
function itemTieneComision(item) {
    const canal = canalCliente.value
    if (canal === 'mayorista') return false
    if (canal === 'distribuidor') return (parseFloat(item.comision_max_distribuidor) || 0) > 0
    return (parseFloat(item.comision_max_cliente_final) || 0) > 0
}
function claseCardPrecio(canal) {
    const esActivo = canal === canalCliente.value
    const clases = {
        mayorista:    esActivo ? 'border-2 border-blue-500 bg-blue-50 ring-2 ring-blue-200'   : 'border border-linea bg-tinta-50 opacity-60',
        distribuidor: esActivo ? 'border-2 border-indigo-500 bg-indigo-50 ring-2 ring-indigo-200' : 'border border-linea bg-tinta-50 opacity-60',
        cliente_final:esActivo ? 'border-2 border-green-500 bg-green-50 ring-2 ring-green-200'  : 'border border-linea bg-tinta-50 opacity-60',
    }
    return clases[canal] || ''
}

let timerCliente = null

function buscarCliente(q) {
    clienteQuery.value = q
    clearTimeout(timerCliente)
    if (q.length < 2) { clienteResultados.value = []; return }
    timerCliente = setTimeout(async () => {
        const r = await fetch(`/api/cotizaciones/clientes?q=${encodeURIComponent(q)}`)
        clienteResultados.value = await r.json()
    }, 300)
}
function seleccionarCliente(c) {
    clienteSeleccionado.value = c
    clienteQuery.value        = c.nombre + (c.apellido ? ' ' + c.apellido : '')
    form.cliente_id           = c.id
    form.contacto_id          = null
    contactos.value           = c.contactos ?? []
    clienteResultados.value   = []
}
function limpiarCliente() {
    clienteSeleccionado.value = null
    form.cliente_id = null; form.contacto_id = null
    clienteQuery.value = ''; contactos.value = []
}

// ─── Modal ────────────────────────────────────────────────────────────────────
const modalPanel = ref(null) // null | 'opciones' | 'producto' | 'ensamble' | 'ensamble_instancia'

function abrirModal() { modalPanel.value = 'opciones' }
function cerrarModal() {
    modalPanel.value = null
    productoQuery.value = ''; productoResultados.value = []; productoExpandido.value = null
    ensambleQuery.value = ''; ensambleResultados.value = []; ensambleExpandido.value = null
    ensambleInstancia.value = null; camposInstancia.value = []; valoresInstancia.value = {}
    preciosCalculados.value = null; calculandoInstancia.value = false
    imagenesReferencia.value = []; imagenesInstancia.value = []
}

// ─── Productos ────────────────────────────────────────────────────────────────
const productoQuery      = ref('')
const productoResultados = ref([])
const productoExpandido  = ref(null)
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
function expandirProducto(p) {
    productoExpandido.value = productoExpandido.value?.id === p.id ? null : p
}
function agregarItemDesdeProducto(prod) {
    const precio = getPrecioSegunCanal(prod)
    form.items.push({
        tipo: prod.tipo ?? 'producto', producto_id: prod.id, ensamble_id: null,
        descripcion:       prod.nombre + (prod.referencia ? ` (${prod.referencia})` : ''),
        descripcion_corta: prod.descripcion_corta ?? '',
        descripcion_larga: prod.descripcion_larga ?? '',
        cantidad: 1, precio_unitario: precio, descuento_pct: 0, impuesto_pct: 0,
        orden: form.items.length,
        precio_lista:                precio,
        precio_mayorista_base:       parseFloat(prod.precio_mayorista) || 0,
        precio_minimo_absoluto:      getPrecioMinimo(prod),
        comision_pct_minima:         getCanalComisionMin(prod),
        comision_pct_maxima:         getCanalComisionMax(prod),
        comision_pct_aplicada:       getCanalComisionMax(prod),
        comision_pct_actual:         getCanalComisionMax(prod),
        comision_min_distribuidor:   parseFloat(prod.comision_min_distribuidor)  || 0,
        comision_max_distribuidor:   parseFloat(prod.comision_max_distribuidor)  || 0,
        comision_min_cliente_final:  parseFloat(prod.comision_min_cliente_final) || 0,
        comision_max_cliente_final:  parseFloat(prod.comision_max_cliente_final) || 0,
        descuento_max:               getDescuentoMaxSegunCanal(prod),
        descuento_max_cliente_final: parseFloat(prod.descuento_max_cliente_final) || 0,
        descuento_max_distribuidor:  parseFloat(prod.descuento_max_distribuidor)  || 0,
        descuento_max_mayorista:     parseFloat(prod.descuento_max_mayorista)     || 0,
    })
    cerrarModal()
}

// ─── Ensambles ────────────────────────────────────────────────────────────────
const ensambleQuery      = ref('')
const ensambleResultados = ref([])
const ensambleExpandido  = ref(null)
let timerEns = null

// Variables de instancia
const ensambleInstancia    = ref(null)   // ensamble seleccionado para paso 2
const camposInstancia      = ref([])     // campos variable_instancia del ensamble
const valoresInstancia     = ref({})     // valores ingresados por el usuario
const preciosCalculados    = ref(null)   // resultado del cálculo
const calculandoInstancia  = ref(false)
const imagenesReferencia   = ref([])     // imágenes fijas del plantilla (solo lectura)
const imagenesInstancia    = ref([])     // imágenes subidas por el usuario para este ítem

const imagenesSeleccionadas = computed(() => {
    const result = {}
    for (const c of camposInstancia.value) {
        if (c.subtipo_variable !== 'selector') continue
        const valor = valoresInstancia.value[c.nombre]
        if (!valor) continue
        const opcion = (c.opciones_selector ?? []).find(o => o.valor === valor)
        if (opcion?.imagen) {
            result[c.nombre] = '/storage/' + opcion.imagen
        }
    }
    return result
})

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
    // Cargar campos variable_instancia del ensamble
    try {
        const r    = await fetch(`/api/ensambles/${e.id}/variables-instancia`)
        const data = await r.json()
        const campos    = data.campos ?? []
        const imgRef    = data.imagenes_referencia ?? []
        if (campos.length > 0 || imgRef.length > 0) {
            // Hay campos o imágenes de referencia → ir a paso 2
            ensambleInstancia.value  = e
            imagenesReferencia.value = imgRef
            imagenesInstancia.value  = []
            camposInstancia.value    = campos.map(c => ({
                ...c,
                opciones_selector: typeof c.opciones_selector === 'string'
                    ? JSON.parse(c.opciones_selector)
                    : (c.opciones_selector ?? []),
            }))
            // Pre-mostrar precios guardados del ensamble (antes de calcular con variables específicas)
            preciosCalculados.value = {
                total_costo:           e.precio_costo ?? 0,
                precio_costo:          e.precio_costo ?? 0,
                precio_mayorista:      e.precio_mayorista ?? 0,
                precio_distribuidor:   e.precio_distribuidor ?? 0,
                precio_cliente_final:  e.precio_cliente_final ?? 0,
            }
            // Inicializar valores con defaults
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
            // Sin campos de instancia → expandir directo
            expandirEnsamble(e)
        }
    } catch {
        expandirEnsamble(e)
    }
}

const xsrf = () => { const c = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN=')); return c ? decodeURIComponent(c.split('=')[1]) : '' }

async function subirImagenInstancia(event) {
    const file = event.target.files?.[0]
    if (!file) return
    const fd = new FormData()
    fd.append('imagen', file)
    try {
        const r    = await fetch('/api/cotizaciones/upload-imagen-instancia', {
            method: 'POST',
            headers: { 'X-XSRF-TOKEN': xsrf(), 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
            body: fd,
        })
        const data = await r.json()
        imagenesInstancia.value.push({ ruta: data.ruta, titulo: data.titulo ?? '' })
    } catch { /* silencioso */ }
    event.target.value = ''
}

function quitarImagenInstancia(idx) {
    imagenesInstancia.value.splice(idx, 1)
}

async function calcularInstancia() {
    if (!ensambleInstancia.value) return
    calculandoInstancia.value = true
    try {
        const r = await fetch('/api/cotizaciones/calcular-ensamble', {
            method:  'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept:         'application/json',
                'X-XSRF-TOKEN': (() => { const c = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN=')); return c ? decodeURIComponent(c.split('=')[1]) : '' })(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                ensamble_id:        ensambleInstancia.value.id,
                variables_instancia: valoresInstancia.value,
            }),
        })
        preciosCalculados.value = await r.json()
    } catch { /* silencioso */ }
    finally { calculandoInstancia.value = false }
}

function buildDescripcionLargaInstancia(base, valores, campos) {
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
    const vars = lineas.join(' | ')
    return base ? `${base}\n${vars}` : vars
}

function agregarItemDesdeEnsambleInstancia(precio) {
    const e = ensambleInstancia.value
    form.items.push({
        tipo: 'ensamble', producto_id: null, ensamble_id: e.id,
        variables_snapshot:   e.variables,
        componentes_snapshot: preciosCalculados.value?.componentes ?? e.componentes_resultado,
        variables_instancia:  { ...valoresInstancia.value },
        imagenes_instancia:   [...imagenesInstancia.value],
        descripcion:       e.nombre,
        descripcion_corta: e.descripcion_corta ?? '',
        descripcion_larga: buildDescripcionLargaInstancia(e.descripcion_larga, valoresInstancia.value, camposInstancia.value),
        cantidad: 1, precio_unitario: precio, descuento_pct: 0, impuesto_pct: 0,
        orden: form.items.length,
        precio_lista:                precio,
        precio_mayorista_base:       parseFloat(preciosCalculados.value?.precio_mayorista) || 0,
        precio_minimo_absoluto:      getPrecioMinimo(e),
        comision_pct_minima:         getCanalComisionMin(e),
        comision_pct_maxima:         getCanalComisionMax(e),
        comision_pct_aplicada:       getCanalComisionMax(e),
        comision_pct_actual:         getCanalComisionMax(e),
        comision_min_distribuidor:   parseFloat(e.comision_min_distribuidor)  || 0,
        comision_max_distribuidor:   parseFloat(e.comision_max_distribuidor)  || 0,
        comision_min_cliente_final:  parseFloat(e.comision_min_cliente_final) || 0,
        comision_max_cliente_final:  parseFloat(e.comision_max_cliente_final) || 0,
        descuento_max:               getDescuentoMaxSegunCanal(e),
        descuento_max_cliente_final: parseFloat(e.descuento_max_cliente_final) || 0,
        descuento_max_distribuidor:  parseFloat(e.descuento_max_distribuidor)  || 0,
        descuento_max_mayorista:     parseFloat(e.descuento_max_mayorista)     || 0,
    })
    cerrarModal()
}

function agregarItemDesdeEnsamble(ensamble, precio) {
    form.items.push({
        tipo: 'ensamble', producto_id: null, ensamble_id: ensamble.id,
        variables_snapshot:   ensamble.variables,
        componentes_snapshot: ensamble.componentes_resultado,
        descripcion:       ensamble.nombre,
        descripcion_corta: ensamble.descripcion_corta ?? '',
        descripcion_larga: ensamble.descripcion_larga ?? '',
        cantidad: 1, precio_unitario: precio, descuento_pct: 0, impuesto_pct: 0,
        orden: form.items.length,
        precio_lista:                precio,
        precio_mayorista_base:       parseFloat(ensamble.precio_mayorista) || 0,
        precio_minimo_absoluto:      getPrecioMinimo(ensamble),
        comision_pct_minima:         getCanalComisionMin(ensamble),
        comision_pct_maxima:         getCanalComisionMax(ensamble),
        comision_pct_aplicada:       getCanalComisionMax(ensamble),
        comision_pct_actual:         getCanalComisionMax(ensamble),
        comision_min_distribuidor:   parseFloat(ensamble.comision_min_distribuidor)  || 0,
        comision_max_distribuidor:   parseFloat(ensamble.comision_max_distribuidor)  || 0,
        comision_min_cliente_final:  parseFloat(ensamble.comision_min_cliente_final) || 0,
        comision_max_cliente_final:  parseFloat(ensamble.comision_max_cliente_final) || 0,
        descuento_max:               getDescuentoMaxSegunCanal(ensamble),
        descuento_max_cliente_final: parseFloat(ensamble.descuento_max_cliente_final) || 0,
        descuento_max_distribuidor:  parseFloat(ensamble.descuento_max_distribuidor)  || 0,
        descuento_max_mayorista:     parseFloat(ensamble.descuento_max_mayorista)     || 0,
    })
    cerrarModal()
}

// ─── Texto libre ──────────────────────────────────────────────────────────────
function agregarItemTextoLibre() {
    form.items.push({
        tipo: 'texto_libre', producto_id: null, ensamble_id: null,
        descripcion: '', cantidad: 1, precio_unitario: 0, descuento_pct: 0, impuesto_pct: 0,
        orden: form.items.length,
    })
    cerrarModal()
}

function eliminarItem(idx) { form.items.splice(idx, 1) }

// ─── Reordenar ────────────────────────────────────────────────────────────────
function moverItem(idx, dir) {
    const ni = idx + dir
    if (ni < 0 || ni >= form.items.length) return
    const arr = [...form.items]
    ;[arr[idx], arr[ni]] = [arr[ni], arr[idx]]
    form.items.splice(0, arr.length, ...arr)
}

const draggingIdx = ref(null)
const dragOverIdx = ref(null)

function onDragStart(idx) { draggingIdx.value = idx }
function onDragOver(e, idx) { e.preventDefault(); dragOverIdx.value = idx }
function onDrop(idx) {
    if (draggingIdx.value === null || draggingIdx.value === idx) {
        draggingIdx.value = null; dragOverIdx.value = null; return
    }
    const arr = [...form.items]
    const [moved] = arr.splice(draggingIdx.value, 1)
    arr.splice(idx, 0, moved)
    form.items.splice(0, arr.length, ...arr)
    draggingIdx.value = null; dragOverIdx.value = null
}
function onDragEnd() { draggingIdx.value = null; dragOverIdx.value = null }

// ─── Totales ──────────────────────────────────────────────────────────────────
function calcularSubtotalItem(item) {
    const base  = (item.cantidad || 0) * (item.precio_unitario || 0)
    const bd    = base - base * ((item.descuento_pct || 0) / 100)
    return bd + bd * ((item.impuesto_pct || 0) / 100)
}
const subtotalBruto  = computed(() => form.items.reduce((s, i) => s + (i.cantidad || 0) * (i.precio_unitario || 0), 0))
const totalDescuento = computed(() => form.items.reduce((s, i) => { const b = (i.cantidad||0)*(i.precio_unitario||0); return s + b*((i.descuento_pct||0)/100) }, 0))
const totalImpuesto  = computed(() => form.items.reduce((s, i) => { const b = (i.cantidad||0)*(i.precio_unitario||0); const bd = b - b*((i.descuento_pct||0)/100); return s + bd*((i.impuesto_pct||0)/100) }, 0))
const total          = computed(() => subtotalBruto.value - totalDescuento.value + totalImpuesto.value)

const formatCOP   = (v) => new Intl.NumberFormat('es-CO', { maximumFractionDigits: 0 }).format(v ?? 0)
const formatPrecio = (v) => (v || v === 0) ? new Intl.NumberFormat('es-CO', { maximumFractionDigits: 0 }).format(v) : ''
const parsePrecio  = (s) => parseFloat(String(s).replace(/[^0-9,]/g, '').replace(',', '.')) || 0

const expandedDescriptions = ref({})
function toggleDescripcion(idx) {
    expandedDescriptions.value = { ...expandedDescriptions.value, [idx]: !expandedDescriptions.value[idx] }
}

// ─── Panel de comisión con slider ─────────────────────────────────────────────
// La comisión se paga sobre el EXCEDENTE por encima del precio mayorista
// (la utilidad garantizada e intocable de la empresa), no sobre el precio
// de venta completo. Por eso el canal mayorista no genera comisión: ahí no
// hay excedente que repartir. Cualquier venta por encima de ese precio en
// Distribuidor/Cliente final sí genera un excedente que se reparte entre
// más utilidad para la empresa y comisión para el vendedor.
function excedenteUnitario(item) {
    const precio = parseFloat(item.precio_lista || item.precio_unitario) || 0
    const base   = parseFloat(item.precio_mayorista_base) || 0
    return Math.max(0, precio - base)
}
function comisionActualValor(item) {
    const cantidad = parseFloat(item.cantidad) || 1
    const pct      = parseFloat(item.comision_pct_actual) || parseFloat(item.comision_pct_maxima) || 0
    return Math.round(excedenteUnitario(item) * cantidad * (pct / 100))
}
function comisionMaxValor(item) {
    return Math.round(excedenteUnitario(item) * (getCanalComisionMax(item) / 100))
}
function comisionMinValor(item) {
    return Math.round(excedenteUnitario(item) * (getCanalComisionMin(item) / 100))
}
function descuentoDisponible(item) {
    const actual = parseFloat(item.comision_pct_actual) || getCanalComisionMax(item)
    return getCanalComisionMax(item) - actual
}
function descuentoMaxDisponible(item) {
    return getDescuentoMaxSegunCanal(item)
}
function onComisionChange(item, index, nuevoPct) {
    const pct = Math.max(
        parseFloat(item.comision_pct_minima),
        Math.min(parseFloat(item.comision_pct_maxima), parseFloat(nuevoPct) || 0)
    )
    form.items[index].comision_pct_actual = pct

    // Descuento proporcional: cuando comisión = max → descuento = 0; cuando = min → descuento = max
    const rangoComision = parseFloat(item.comision_pct_maxima) - parseFloat(item.comision_pct_minima)
    if (rangoComision > 0) {
        const factorSacrificado = (parseFloat(item.comision_pct_maxima) - pct) / rangoComision
        const descuentoMax = getDescuentoMaxSegunCanal(item)
        form.items[index].descuento_pct = parseFloat((factorSacrificado * descuentoMax).toFixed(2))
    }
}
function ajustarComision(item, index, delta) {
    const actual = parseFloat(item.comision_pct_actual) || parseFloat(item.comision_pct_maxima) || 0
    onComisionChange(item, index, actual + delta)
}
function onDescuentoChange(item, index, nuevoPct) {
    if (canalCliente.value === 'mayorista') return

    const descuentoMax = getDescuentoMaxSegunCanal(item)
    const descPct = Math.max(0, Math.min(descuentoMax, parseFloat(nuevoPct) || 0))
    form.items[index].descuento_pct = descPct

    // Comisión proporcional: cuando descuento = 0 → comisión = max; cuando = max → comisión = min
    const rangoComision = parseFloat(item.comision_pct_maxima) - parseFloat(item.comision_pct_minima)
    if (descuentoMax > 0) {
        const factorUsado    = descPct / descuentoMax
        const nuevaComision  = parseFloat(item.comision_pct_maxima) - (factorUsado * rangoComision)
        form.items[index].comision_pct_actual = parseFloat(
            Math.max(parseFloat(item.comision_pct_minima), nuevaComision).toFixed(2)
        )
    }
}

// ─── Submit ───────────────────────────────────────────────────────────────────
function submit() {
    markClean()
    // Mapear comision_pct_actual → comision_pct_aplicada antes de enviar al backend
    form.items = form.items.map(item => {
        const comisionPct = parseFloat(item.comision_pct_actual)
                           || parseFloat(item.comision_pct_maxima)
                           || 0
        const cantidad   = parseFloat(item.cantidad) || 1
        return {
            ...item,
            comision_pct_aplicada: comisionPct,
            comision_valor: excedenteUnitario(item) * cantidad * (comisionPct / 100),
        }
    })
    esEdicion.value ? form.put(`/cotizaciones/${props.cotizacion.id}`) : form.post('/cotizaciones')
}
</script>

<template>
    <AppLayout :title="esEdicion ? `Editar ${cotizacion?.numero}` : 'Nueva Cotización'">
        <div class="max-w-5xl mx-auto">

            <!-- Badge cambios sin guardar -->
            <div v-if="hasChanges"
                class="mb-3 inline-flex items-center gap-2 px-3 py-1.5 rounded-xl text-xs font-semibold text-orange-700"
                style="background:#FEF3C7; border:1px solid #F59E0B;">
                ● Cambios sin guardar
            </div>

            <!-- Badge generada desde lead -->
            <div v-if="props.lead_preseleccionado && !esEdicion"
                class="mb-3 inline-flex items-center gap-2 px-3 py-1.5 rounded-xl text-xs font-semibold text-blue-700"
                style="background:#DBEAFE; border:1px solid #93C5FD;">
                Generada automáticamente desde el lead: {{ props.lead_preseleccionado.titulo }}
            </div>

            <!-- Cabecera -->
            <div class="flex items-center gap-3 mb-5">
                <button type="button" @click="router.visit('/cotizaciones')" class="text-tinta-400 hover:text-tinta-700">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M15 19l-7-7 7-7"/>
                    </svg>
                </button>
                <h1 class="text-xl font-semibold text-tinta-900">
                    {{ esEdicion ? `Editar ${cotizacion?.numero}` : 'Nueva Cotización' }}
                </h1>
            </div>

            <form @submit.prevent="submit" class="space-y-4">

                <!-- ── Encabezado ──────────────────────────────────────────── -->
                <div class="bg-white rounded-2xl border border-linea p-5">
                    <h2 class="text-sm font-semibold text-tinta-700 mb-4">Datos de la cotización</h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                        <!-- Búsqueda cliente -->
                        <div class="md:col-span-2">
                            <label class="block text-xs font-medium text-tinta-500 mb-1.5">Cliente</label>
                            <div class="relative">
                                <input :value="clienteQuery" @input="buscarCliente($event.target.value)"
                                    type="text" placeholder="Buscar por nombre o identificación..."
                                    class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:ring-2 focus:outline-none"/>
                                <div v-if="clienteResultados.length > 0"
                                    class="absolute top-full left-0 right-0 mt-1 bg-white rounded-xl shadow-xl border border-linea z-20 overflow-hidden">
                                    <button v-for="c in clienteResultados" :key="c.id" type="button"
                                        @click="seleccionarCliente(c)"
                                        class="w-full flex items-center gap-3 px-4 py-2.5 hover:bg-tinta-50 text-left transition-colors border-b border-gray-50 last:border-0">
                                        <div>
                                            <p class="text-sm font-medium text-tinta-900">{{ c.nombre }}{{ c.apellido ? ' ' + c.apellido : '' }}</p>
                                            <p class="text-xs text-tinta-300">{{ c.tipo_identificacion }}: {{ c.numero_identificacion }} · {{ c.ciudad }}</p>
                                        </div>
                                    </button>
                                </div>
                            </div>
                            <div v-if="clienteSeleccionado"
                                class="mt-2 flex items-center gap-3 px-3 py-2 rounded-xl"
                                style="background:#EFF6FF; border:1px solid #BFDBFE;">
                                <svg class="w-4 h-4 shrink-0" style="color:var(--marca)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                </svg>
                                <p class="text-sm font-medium flex-1" style="color:#1D4ED8;">
                                    {{ clienteSeleccionado.nombre }}{{ clienteSeleccionado.apellido ? ' ' + clienteSeleccionado.apellido : '' }}
                                </p>
                                <button type="button" @click="limpiarCliente" class="text-blue-300 hover:text-blue-500">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                    </svg>
                                </button>
                            </div>
                            <div v-if="!clienteSeleccionado" class="mt-2">
                                <input v-model="form.nombre_contacto_override" type="text"
                                    placeholder="O escribe el nombre si no está registrado"
                                    class="w-full rounded-xl border border-linea px-3 py-2 text-sm text-tinta-400 focus:ring-1 focus:outline-none"/>
                            </div>
                        </div>

                        <div v-if="clienteSeleccionado && contactos.length > 0">
                            <label class="block text-xs font-medium text-tinta-500 mb-1.5">Contacto</label>
                            <select v-model="form.contacto_id"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:ring-2 focus:outline-none">
                                <option :value="null">Sin contacto específico</option>
                                <option v-for="c in contactos" :key="c.id" :value="c.id">
                                    {{ c.nombre }} {{ c.apellido }}{{ c.cargo ? ` — ${c.cargo}` : '' }}{{ c.es_principal ? ' ★' : '' }}
                                </option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-tinta-500 mb-1.5">Moneda</label>
                            <select v-model="form.moneda"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:ring-2 focus:outline-none">
                                <option value="COP">COP — Peso colombiano</option>
                                <option value="USD">USD — Dólar</option>
                                <option value="EUR">EUR — Euro</option>
                            </select>
                        </div>

                        <div v-if="form.moneda !== 'COP'">
                            <label class="block text-xs font-medium text-tinta-500 mb-1.5">Tasa de cambio</label>
                            <input v-model.number="form.tasa_cambio" type="number" step="0.01" min="1"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:ring-2 focus:outline-none"/>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-tinta-500 mb-1.5">Fecha de creación</label>
                            <input v-model="form.fecha_creacion" type="date"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:ring-2 focus:outline-none"/>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-tinta-500 mb-1.5">Válida hasta</label>
                            <input v-model="form.fecha_validez" type="date"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:ring-2 focus:outline-none"/>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-tinta-500 mb-1.5">Responsable</label>
                            <select v-model="form.responsable_id"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:ring-2 focus:outline-none">
                                <option v-for="r in responsables" :key="r.id" :value="r.id">{{ r.name }}</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- ── Ítems ───────────────────────────────────────────────── -->
                <div class="bg-white rounded-2xl border border-linea p-5">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-sm font-semibold text-tinta-700">Ítems de la cotización</h2>
                        <span v-if="form.items.length > 0" class="text-xs text-tinta-300">{{ form.items.length }} ítem{{ form.items.length !== 1 ? 's' : '' }}</span>
                    </div>

                    <!-- Lista de ítems -->
                    <div v-if="form.items.length > 0" class="space-y-3 mb-4">
                        <div v-for="(item, idx) in form.items" :key="idx"
                            class="rounded-xl border transition-colors"
                            :class="dragOverIdx === idx && draggingIdx !== idx ? 'border-blue-400 bg-blue-50' : 'border-linea'"
                            :draggable="true"
                            @dragstart="onDragStart(idx)"
                            @dragover="onDragOver($event, idx)"
                            @drop="onDrop(idx)"
                            @dragend="onDragEnd">

                            <!-- Barra superior: badge + controles -->
                            <div class="flex items-center gap-2 px-3 pt-2.5 pb-0">
                                <!-- Grip (solo desktop) -->
                                <div class="hidden sm:flex cursor-grab text-tinta-200 hover:text-tinta-300 shrink-0 select-none" title="Arrastrar para reordenar">
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                                        <circle cx="9" cy="5" r="1.5"/><circle cx="15" cy="5" r="1.5"/>
                                        <circle cx="9" cy="12" r="1.5"/><circle cx="15" cy="12" r="1.5"/>
                                        <circle cx="9" cy="19" r="1.5"/><circle cx="15" cy="19" r="1.5"/>
                                    </svg>
                                </div>
                                <!-- Badge tipo -->
                                <span v-if="item.tipo === 'producto'" class="text-xs px-2 py-0.5 rounded-full font-medium shrink-0" style="background:#EFF6FF;color:#1D4ED8;">Producto</span>
                                <span v-else-if="item.tipo === 'servicio'" class="text-xs px-2 py-0.5 rounded-full font-medium shrink-0" style="background:#ECFDF5;color:#065F46;">Servicio</span>
                                <span v-else-if="item.tipo === 'ensamble'" class="text-xs px-2 py-0.5 rounded-full font-medium shrink-0" style="background:#FFF7ED;color:#C2410C;">Ensamble</span>
                                <span v-else class="text-xs px-2 py-0.5 rounded-full font-medium bg-tinta-100 text-tinta-400 shrink-0">Texto libre</span>

                                <div class="flex-1"/>

                                <!-- ↑↓ botones (solo mobile) -->
                                <div class="flex sm:hidden gap-0.5 shrink-0">
                                    <button type="button" @click="moverItem(idx, -1)" :disabled="idx === 0"
                                        class="w-7 h-7 rounded-lg flex items-center justify-center text-tinta-300 hover:text-tinta-700 hover:bg-tinta-100 disabled:opacity-30 text-sm font-semibold transition-colors">↑</button>
                                    <button type="button" @click="moverItem(idx, 1)" :disabled="idx === form.items.length - 1"
                                        class="w-7 h-7 rounded-lg flex items-center justify-center text-tinta-300 hover:text-tinta-700 hover:bg-tinta-100 disabled:opacity-30 text-sm font-semibold transition-colors">↓</button>
                                </div>

                                <!-- Eliminar -->
                                <button type="button" @click="eliminarItem(idx)"
                                    class="w-7 h-7 rounded-lg flex items-center justify-center text-tinta-300 hover:text-red-600 hover:bg-red-50 transition-colors shrink-0">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                    </svg>
                                </button>
                            </div>

                            <!-- Cuerpo del ítem -->
                            <div class="px-3 pt-2 pb-3">
                                <textarea v-model="item.descripcion" rows="2" placeholder="Descripción del ítem..."
                                    class="w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm focus:ring-2 focus:outline-none resize-none mb-1"/>

                                <!-- Descripción corta (solo lectura, referencia catálogo) -->
                                <p v-if="item.descripcion_corta" class="text-xs text-tinta-300 italic mb-1">{{ item.descripcion_corta }}</p>
                                <!-- Descripción larga editable -->
                                <div class="mb-1">
                                    <EditorTexto v-model="item.descripcion_larga"
                                        placeholder="Descripción larga o detalles adicionales..."
                                        min-height="80px" />
                                </div>

                                <div class="grid grid-cols-2 md:grid-cols-4 gap-2 mt-2">
                                    <div>
                                        <label class="block text-xs text-tinta-400 mb-1">Cantidad</label>
                                        <input v-model.number="item.cantidad" type="number" step="0.001" min="0"
                                            class="w-full rounded-lg border border-tinta-200 px-2 py-1.5 text-sm text-right focus:outline-none"/>
                                    </div>
                                    <div>
                                        <label class="block text-xs text-tinta-400 mb-1">Precio unit.</label>
                                        <input
                                            type="text" inputmode="numeric"
                                            :value="formatPrecio(item.precio_unitario)"
                                            @focus="$event.target.value = item.precio_unitario || ''"
                                            @blur="item.precio_unitario = parsePrecio($event.target.value); $event.target.value = formatPrecio(item.precio_unitario)"
                                            class="w-full rounded-lg border border-tinta-200 px-2 py-1.5 text-sm text-right focus:outline-none"/>
                                    </div>
                                    <div>
                                        <label class="block text-xs text-tinta-400 mb-1">Dto. %</label>
                                        <input type="number" step="0.01" min="0"
                                            :max="itemTieneComision(item) ? descuentoMaxDisponible(item) : 100"
                                            :value="item.descuento_pct"
                                            :disabled="canalCliente === 'mayorista'"
                                            class="w-full rounded-lg border border-tinta-200 px-2 py-1.5 text-sm text-right focus:outline-none"
                                            :class="canalCliente === 'mayorista' ? 'bg-tinta-100 cursor-not-allowed text-tinta-300' : ''"
                                            @change="canalCliente !== 'mayorista' && (itemTieneComision(item)
                                                ? onDescuentoChange(item, idx, $event.target.value)
                                                : (item.descuento_pct = parseFloat($event.target.value) || 0))"/>
                                    </div>
                                    <div>
                                        <label class="block text-xs text-tinta-400 mb-1">IVA %</label>
                                        <select v-model.number="item.impuesto_pct"
                                            class="w-full rounded-lg border border-tinta-200 px-2 py-1.5 text-sm focus:outline-none">
                                            <option :value="0">0% (No grava)</option>
                                            <option :value="19">19% (IVA)</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="flex justify-end mt-2">
                                    <span class="text-sm font-semibold" style="color:var(--marca)">
                                        ${{ formatCOP(calcularSubtotalItem(item)) }}
                                    </span>
                                </div>

                                <!-- Badge canal mayorista -->
                                <div v-if="canalCliente === 'mayorista'"
                                    class="mt-2 p-2 bg-blue-50 border border-blue-100 rounded-lg">
                                    <p class="text-xs text-blue-600 text-center">
                                        Canal mayorista — precio fijo sin descuento ni comisión
                                    </p>
                                </div>

                                <!-- Panel comisión compacto -->
                                <div v-if="itemTieneComision(item)"
                                    class="mt-2 flex items-center gap-2 px-3 py-2 rounded-xl"
                                    style="background:#FFFBEB;border:1px solid #FCD34D;">
                                    <span class="text-xs font-medium shrink-0" style="color:#B45309;">Com.</span>
                                    <button type="button" @click="ajustarComision(item, idx, -0.5)"
                                        :disabled="(item.comision_pct_actual || getCanalComisionMax(item)) <= getCanalComisionMin(item)"
                                        class="w-6 h-6 rounded-full flex items-center justify-center font-semibold text-sm disabled:opacity-40 shrink-0 hover:bg-amber-100 transition-colors"
                                        style="border:1px solid #FCD34D;color:#B45309;">−</button>
                                    <input type="range"
                                        :min="getCanalComisionMin(item)"
                                        :max="getCanalComisionMax(item)"
                                        :step="0.1"
                                        :value="item.comision_pct_actual || getCanalComisionMax(item)"
                                        @input="onComisionChange(item, idx, $event.target.value)"
                                        class="flex-1 h-1.5 accent-amber-500 cursor-pointer" />
                                    <button type="button" @click="ajustarComision(item, idx, 0.5)"
                                        :disabled="(item.comision_pct_actual || getCanalComisionMax(item)) >= getCanalComisionMax(item)"
                                        class="w-6 h-6 rounded-full flex items-center justify-center font-semibold text-sm disabled:opacity-40 shrink-0 hover:bg-amber-100 transition-colors"
                                        style="border:1px solid #FCD34D;color:#B45309;">+</button>
                                    <span class="text-xs font-semibold shrink-0 text-right" style="color:#92400E;min-width:5.5rem;">
                                        {{ (item.comision_pct_actual || getCanalComisionMax(item)).toFixed(1) }}% · ${{ formatCOP(comisionActualValor(item)) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <p v-else class="text-sm text-tinta-300 text-center py-8 border border-dashed border-linea rounded-xl mb-4">
                        Sin ítems. Agrega al menos uno.
                    </p>

                    <!-- Botón agregar -->
                    <button type="button" @click="abrirModal"
                        class="w-full py-2.5 rounded-xl border-2 border-dashed border-tinta-200 text-sm font-medium text-tinta-400 hover:border-blue-400 hover:text-blue-600 hover:bg-blue-50 transition-colors flex items-center justify-center gap-2">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                        </svg>
                        Agregar ítem
                    </button>

                    <!-- Totales -->
                    <div v-if="form.items.length > 0" class="mt-5 pt-4 border-t border-linea">
                        <div class="flex justify-end">
                            <div class="w-64 space-y-1.5">
                                <div class="flex justify-between text-sm text-tinta-500">
                                    <span>Subtotal</span>
                                    <span>${{ formatCOP(subtotalBruto) }}</span>
                                </div>
                                <div v-if="totalDescuento > 0" class="flex justify-between text-sm text-tinta-500">
                                    <span>Descuento</span>
                                    <span class="text-red-500">-${{ formatCOP(totalDescuento) }}</span>
                                </div>
                                <div v-if="totalImpuesto > 0" class="flex justify-between text-sm text-tinta-500">
                                    <span>IVA</span>
                                    <span>${{ formatCOP(totalImpuesto) }}</span>
                                </div>
                                <div class="flex justify-between text-base font-semibold border-t border-linea pt-2 mt-2" style="color:var(--marca)">
                                    <span>TOTAL</span>
                                    <span>${{ formatCOP(total) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Condiciones ─────────────────────────────────────────── -->
                <div class="bg-white rounded-2xl border border-linea p-5">
                    <h2 class="text-sm font-semibold text-tinta-700 mb-3">Condiciones comerciales</h2>
                    <textarea v-model="form.condiciones_comerciales" rows="4"
                        class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:ring-2 focus:outline-none resize-none"
                        placeholder="Condiciones de pago, entrega, validez..."/>
                </div>

                <!-- Errores -->
                <div v-if="Object.keys(form.errors).length > 0" class="bg-red-50 border border-red-200 rounded-xl p-4">
                    <p v-for="(msg, field) in form.errors" :key="field" class="text-sm text-red-600">⚠ {{ msg }}</p>
                </div>

                <!-- Acciones -->
                <div class="flex gap-3 pb-4">
                    <button type="button" @click="router.visit('/cotizaciones')"
                        class="flex-1 text-center px-4 py-3 rounded-xl border border-tinta-200 text-sm font-medium text-tinta-700 hover:bg-tinta-50">
                        Cancelar
                    </button>
                    <button type="submit" :disabled="form.processing"
                        class="flex-1 px-4 py-3 rounded-xl text-sm font-medium text-white disabled:opacity-60"
                        style="background:var(--marca)">
                        {{ form.processing ? 'Guardando...' : (esEdicion ? 'Guardar cambios' : 'Crear cotización') }}
                    </button>
                </div>

            </form>
        </div>

        <!-- ── Modal agregar ítem ──────────────────────────────────────────── -->
        <Teleport to="body">
            <div v-if="modalPanel" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4">
                <!-- Backdrop -->
                <div class="absolute inset-0 bg-black/40" @click="cerrarModal"/>

                <!-- Panel -->
                <div class="relative w-full sm:max-w-lg bg-white rounded-t-3xl sm:rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">

                    <!-- Handle / header -->
                    <div class="flex items-center justify-between px-5 pt-4 pb-3 border-b border-linea shrink-0">
                        <div class="flex items-center gap-3">
                            <button v-if="modalPanel !== 'opciones'" type="button" @click="modalPanel = 'opciones'"
                                class="text-tinta-300 hover:text-tinta-500">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                                </svg>
                            </button>
                            <h3 class="text-sm font-semibold text-tinta-900">
                                <span v-if="modalPanel === 'opciones'">Agregar ítem</span>
                                <span v-else-if="modalPanel === 'producto'">Buscar producto</span>
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

                    <!-- Opción A · B · C -->
                    <div v-if="modalPanel === 'opciones'" class="p-5 space-y-3">
                        <!-- Opción A: Producto -->
                        <button type="button" @click="modalPanel = 'producto'"
                            class="w-full flex items-center gap-4 p-4 rounded-2xl border-2 border-linea hover:border-blue-400 hover:bg-blue-50 transition-colors text-left group">
                            <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0 group-hover:bg-blue-100 transition-colors" style="background:#EFF6FF;">
                                <svg class="w-5 h-5" style="color:var(--marca);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7l8 4"/>
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-tinta-900">Desde productos</p>
                                <p class="text-xs text-tinta-400 mt-0.5">Busca en el catálogo de productos y servicios</p>
                            </div>
                            <svg class="w-4 h-4 text-tinta-300 ml-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                            </svg>
                        </button>

                        <!-- Opción B: Ensamble -->
                        <button type="button" @click="modalPanel = 'ensamble'"
                            class="w-full flex items-center gap-4 p-4 rounded-2xl border-2 border-linea hover:border-orange-400 hover:bg-orange-50 transition-colors text-left group">
                            <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0 transition-colors" style="background:#FFF7ED;">
                                <svg class="w-5 h-5" style="color:#C2410C;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-tinta-900">Desde ensamble</p>
                                <p class="text-xs text-tinta-400 mt-0.5">Agrega un ensamble configurado con sus precios por canal</p>
                            </div>
                            <svg class="w-4 h-4 text-tinta-300 ml-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                            </svg>
                        </button>

                        <!-- Opción C: Texto libre -->
                        <button type="button" @click="agregarItemTextoLibre"
                            class="w-full flex items-center gap-4 p-4 rounded-2xl border-2 border-linea hover:border-gray-400 hover:bg-tinta-50 transition-colors text-left">
                            <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0 bg-tinta-100">
                                <svg class="w-5 h-5 text-tinta-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-tinta-900">Texto libre</p>
                                <p class="text-xs text-tinta-400 mt-0.5">Descripción manual, precio y cantidades personalizadas</p>
                            </div>
                        </button>
                    </div>

                    <!-- Buscador de productos -->
                    <div v-else-if="modalPanel === 'producto'" class="flex flex-col overflow-hidden">
                        <div class="px-5 py-3 shrink-0">
                            <input :value="productoQuery" @input="buscarProducto($event.target.value)"
                                type="text" placeholder="Nombre o referencia..." autofocus
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:ring-2 focus:border-blue-400 focus:outline-none"/>
                        </div>
                        <div class="overflow-y-auto flex-1">
                            <p v-if="!productoQuery" class="text-sm text-tinta-300 text-center py-8">Escribe para buscar...</p>
                            <p v-else-if="productoResultados.length === 0 && productoQuery.length >= 2" class="text-sm text-tinta-300 text-center py-8">Sin resultados para "{{ productoQuery }}"</p>
                            <ResultadosBuscadorProducto :resultados="productoResultados" @elegir="agregarItemDesdeProducto">
                                <template #extra="{ producto }">
                                    <div class="text-right shrink-0">
                                        <p class="text-sm font-semibold" style="color:var(--marca)">${{ formatCOP(getPrecioSegunCanal(producto)) }}</p>
                                        <p class="text-xs text-tinta-300 capitalize">{{ canalCliente.replace('_', ' ') }}</p>
                                    </div>
                                </template>
                            </ResultadosBuscadorProducto>
                        </div>
                    </div>

                    <!-- Buscador de ensambles -->
                    <div v-else-if="modalPanel === 'ensamble'" class="flex flex-col overflow-hidden">
                        <div class="px-5 py-3 shrink-0">
                            <input :value="ensambleQuery" @input="buscarEnsamble($event.target.value)"
                                type="text" placeholder="Nombre del ensamble..." autofocus
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:ring-2 focus:border-orange-400 focus:outline-none"/>
                        </div>
                        <div class="overflow-y-auto flex-1">
                            <p v-if="!ensambleQuery" class="text-sm text-tinta-300 text-center py-8">Escribe para buscar...</p>
                            <p v-else-if="ensambleResultados.length === 0 && ensambleQuery.length >= 2" class="text-sm text-tinta-300 text-center py-8">Sin resultados para "{{ ensambleQuery }}"</p>
                            <div v-for="e in ensambleResultados" :key="e.id" class="border-b border-gray-50 last:border-0">
                                <!-- Fila principal -->
                                <div class="flex items-center justify-between px-5 py-3 hover:bg-tinta-50 cursor-pointer transition-colors"
                                    @click="seleccionarEnsamble(e)">
                                    <div class="min-w-0 flex-1 mr-3">
                                        <p class="text-sm font-medium text-tinta-900 truncate">{{ e.nombre }}</p>
                                        <p class="text-xs text-tinta-300 mt-0.5">{{ e.plantilla_nombre }}</p>
                                    </div>
                                    <div class="flex items-center gap-2 shrink-0">
                                        <div class="text-right">
                                            <p class="text-sm font-semibold" style="color:#C2410C">${{ formatCOP(e.precio_distribuidor) }}</p>
                                            <p class="text-xs text-tinta-300">Distribuidor</p>
                                        </div>
                                        <svg class="w-4 h-4 text-tinta-300 transition-transform"
                                            :class="ensambleExpandido?.id === e.id ? 'rotate-180' : ''"
                                            fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                                        </svg>
                                    </div>
                                </div>
                                <!-- Panel de precios resaltados por canal -->
                                <div v-if="ensambleExpandido?.id === e.id" class="px-5 pb-4 pt-2" style="background:#FFFBF5;">
                                    <div class="grid grid-cols-2 gap-2">
                                        <!-- Mayorista -->
                                        <button type="button" @click="agregarItemDesdeEnsamble(e, e.precio_mayorista)"
                                            :class="['text-left p-3 rounded-xl transition-all', claseCardPrecio('mayorista')]">
                                            <div class="flex items-center justify-between mb-1">
                                                <p class="text-xs text-tinta-400">Mayorista</p>
                                                <span v-if="canalCliente === 'mayorista'" class="text-xs bg-blue-500 text-white px-1.5 py-0.5 rounded-full">★ Tu canal</span>
                                            </div>
                                            <p class="font-semibold text-tinta-900">${{ formatCOP(e.precio_mayorista) }}</p>
                                            <p v-if="canalCliente === 'mayorista'" class="text-xs text-blue-600 mt-0.5">Precio fijo · Sin descuento</p>
                                        </button>
                                        <!-- Distribuidor -->
                                        <button type="button" @click="agregarItemDesdeEnsamble(e, e.precio_distribuidor)"
                                            :class="['text-left p-3 rounded-xl transition-all', claseCardPrecio('distribuidor')]">
                                            <div class="flex items-center justify-between mb-1">
                                                <p class="text-xs text-tinta-400">Distribuidor</p>
                                                <span v-if="canalCliente === 'distribuidor'" class="text-xs bg-indigo-500 text-white px-1.5 py-0.5 rounded-full">★ Tu canal</span>
                                            </div>
                                            <p class="font-semibold text-tinta-900">${{ formatCOP(e.precio_distribuidor) }}</p>
                                            <p v-if="canalCliente === 'distribuidor'" class="text-xs text-indigo-600 mt-0.5">Precio sugerido</p>
                                        </button>
                                        <!-- Cliente final -->
                                        <button type="button" @click="agregarItemDesdeEnsamble(e, e.precio_cliente_final)"
                                            :class="['text-left p-3 rounded-xl transition-all', claseCardPrecio('cliente_final')]">
                                            <div class="flex items-center justify-between mb-1">
                                                <p class="text-xs text-tinta-400">Cliente final</p>
                                                <span v-if="canalCliente === 'cliente_final'" class="text-xs bg-green-500 text-white px-1.5 py-0.5 rounded-full">★ Tu canal</span>
                                            </div>
                                            <p class="font-semibold text-tinta-900">${{ formatCOP(e.precio_cliente_final) }}</p>
                                            <p v-if="canalCliente === 'cliente_final'" class="text-xs text-green-600 mt-0.5">Precio sugerido ⭐</p>
                                        </button>
                                        <!-- Costo -->
                                        <button type="button" @click="agregarItemDesdeEnsamble(e, e.precio_costo)"
                                            class="text-left p-3 rounded-xl border border-linea bg-tinta-50 opacity-70 hover:opacity-100 transition-all">
                                            <p class="text-xs text-tinta-300 mb-1">Costo</p>
                                            <p class="font-semibold text-tinta-500">${{ formatCOP(e.precio_costo) }}</p>
                                            <p class="text-xs text-tinta-300 mt-0.5">Solo referencia</p>
                                        </button>
                                    </div>
                                    <!-- Canal indicator -->
                                    <div class="mt-3 p-2 rounded-lg text-xs text-center"
                                        :class="{
                                            'bg-blue-50 text-blue-600':   canalCliente === 'mayorista',
                                            'bg-indigo-50 text-indigo-600': canalCliente === 'distribuidor',
                                            'bg-green-50 text-green-600':  canalCliente === 'cliente_final',
                                        }">
                                        <span v-if="canalCliente === 'mayorista'">📋 Canal Mayorista — precio fijo, sin comisión ni descuento</span>
                                        <span v-else-if="canalCliente === 'distribuidor'">🏪 Canal Distribuidor — precio resaltado es el sugerido</span>
                                        <span v-else>👤 Canal Cliente Final — precio resaltado es el sugerido · Mayor comisión ⭐</span>
                                    </div>
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
                                    class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none" />
                                <input v-else-if="c.subtipo_variable === 'decimal'" v-model.number="valoresInstancia[c.nombre]"
                                    type="number" step="0.01"
                                    class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none" />
                                <input v-else v-model="valoresInstancia[c.nombre]" type="text"
                                    class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none" />
                                <transition
                                    enter-active-class="transition duration-200 ease-out"
                                    enter-from-class="opacity-0 scale-95"
                                    enter-to-class="opacity-100 scale-100"
                                    leave-active-class="transition duration-150 ease-in"
                                    leave-from-class="opacity-100 scale-100"
                                    leave-to-class="opacity-0 scale-95"
                                >
                                    <img v-if="imagenesSeleccionadas[c.nombre]"
                                         :src="imagenesSeleccionadas[c.nombre]"
                                         :key="imagenesSeleccionadas[c.nombre]"
                                         class="mt-2 w-[120px] h-[120px] object-contain rounded-lg border border-linea" />
                                </transition>
                            </div>

                            <!-- ── Imágenes de referencia del plantilla ─────── -->
                            <div v-if="imagenesReferencia.length" class="space-y-2">
                                <p class="text-xs font-semibold text-tinta-400 uppercase">Planos de referencia</p>
                                <div v-for="img in imagenesReferencia" :key="img.id" class="rounded-xl overflow-hidden border border-blue-100 bg-blue-50">
                                    <img :src="'/storage/' + img.imagen_referencia" class="w-full max-h-40 object-contain bg-white" />
                                    <p class="text-xs text-blue-700 px-3 py-1.5 font-medium">
                                        {{ img.imagen_referencia_titulo || img.etiqueta || img.nombre }}
                                    </p>
                                </div>
                            </div>

                            <!-- ── Imágenes propias del proyecto ────────────── -->
                            <div class="space-y-2">
                                <p class="text-xs font-semibold text-tinta-400 uppercase">Planos del proyecto <span class="normal-case font-normal text-tinta-300">(opcional)</span></p>
                                <div v-for="(img, idx) in imagenesInstancia" :key="idx" class="relative rounded-xl overflow-hidden border border-linea">
                                    <img :src="'/storage/' + img.ruta" class="w-full max-h-32 object-contain bg-tinta-50" />
                                    <div class="flex items-center justify-between px-3 py-1.5 bg-white border-t border-linea">
                                        <input v-model="img.titulo" type="text" placeholder="Título del plano..."
                                            class="flex-1 text-xs text-tinta-700 focus:outline-none bg-transparent" />
                                        <button @click="quitarImagenInstancia(idx)" class="text-red-400 hover:text-red-600 text-sm ml-2">✕</button>
                                    </div>
                                </div>
                                <label class="flex items-center gap-2 justify-center border-2 border-dashed border-linea rounded-xl py-3 cursor-pointer hover:border-blue-300 hover:bg-blue-50 transition-colors">
                                    <svg class="w-4 h-4 text-tinta-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
                                    </svg>
                                    <span class="text-xs text-tinta-400">Subir imagen del proyecto</span>
                                    <input type="file" accept="image/*" class="hidden" @change="subirImagenInstancia($event)" />
                                </label>
                            </div>

                            <!-- Botón calcular -->
                            <button type="button" @click="calcularInstancia" :disabled="calculandoInstancia"
                                class="w-full py-2.5 rounded-xl text-sm font-semibold text-white disabled:opacity-60"
                                style="background:var(--marca);">
                                {{ calculandoInstancia ? 'Calculando...' : '▷ Calcular precio' }}
                            </button>

                            <!-- Resultados de precios resaltados por canal -->
                            <div v-if="preciosCalculados" class="space-y-2">
                                <p class="text-xs font-semibold text-tinta-400 uppercase mt-2">Selecciona el precio:</p>
                                <div class="grid grid-cols-2 gap-2">
                                    <!-- Mayorista -->
                                    <button type="button" @click="agregarItemDesdeEnsambleInstancia(preciosCalculados.precio_mayorista)"
                                        :class="['text-left p-3 rounded-xl transition-all', claseCardPrecio('mayorista')]">
                                        <div class="flex items-center justify-between mb-1">
                                            <p class="text-xs text-tinta-400">Mayorista</p>
                                            <span v-if="canalCliente === 'mayorista'" class="text-xs bg-blue-500 text-white px-1.5 py-0.5 rounded-full">★ Tu canal</span>
                                        </div>
                                        <p class="font-semibold text-tinta-900">${{ formatCOP(preciosCalculados.precio_mayorista) }}</p>
                                        <p v-if="canalCliente === 'mayorista'" class="text-xs text-blue-600 mt-0.5">Precio fijo · Sin descuento</p>
                                    </button>
                                    <!-- Distribuidor -->
                                    <button type="button" @click="agregarItemDesdeEnsambleInstancia(preciosCalculados.precio_distribuidor)"
                                        :class="['text-left p-3 rounded-xl transition-all', claseCardPrecio('distribuidor')]">
                                        <div class="flex items-center justify-between mb-1">
                                            <p class="text-xs text-tinta-400">Distribuidor</p>
                                            <span v-if="canalCliente === 'distribuidor'" class="text-xs bg-indigo-500 text-white px-1.5 py-0.5 rounded-full">★ Tu canal</span>
                                        </div>
                                        <p class="font-semibold text-tinta-900">${{ formatCOP(preciosCalculados.precio_distribuidor) }}</p>
                                        <p v-if="canalCliente === 'distribuidor'" class="text-xs text-indigo-600 mt-0.5">Precio sugerido</p>
                                    </button>
                                    <!-- Cliente final -->
                                    <button type="button" @click="agregarItemDesdeEnsambleInstancia(preciosCalculados.precio_cliente_final)"
                                        :class="['text-left p-3 rounded-xl transition-all', claseCardPrecio('cliente_final')]">
                                        <div class="flex items-center justify-between mb-1">
                                            <p class="text-xs text-tinta-400">Cliente final</p>
                                            <span v-if="canalCliente === 'cliente_final'" class="text-xs bg-green-500 text-white px-1.5 py-0.5 rounded-full">★ Tu canal</span>
                                        </div>
                                        <p class="font-semibold text-tinta-900">${{ formatCOP(preciosCalculados.precio_cliente_final) }}</p>
                                        <p v-if="canalCliente === 'cliente_final'" class="text-xs text-green-600 mt-0.5">Precio sugerido ⭐</p>
                                    </button>
                                    <!-- Costo -->
                                    <button type="button" @click="agregarItemDesdeEnsambleInstancia(preciosCalculados.total_costo)"
                                        class="text-left p-3 rounded-xl border border-linea bg-tinta-50 opacity-70 hover:opacity-100 transition-all">
                                        <p class="text-xs text-tinta-300 mb-1">Costo</p>
                                        <p class="font-semibold text-tinta-500">${{ formatCOP(preciosCalculados.total_costo) }}</p>
                                        <p class="text-xs text-tinta-300 mt-0.5">Solo referencia</p>
                                    </button>
                                </div>
                                <!-- Canal indicator -->
                                <div class="mt-1 p-2 rounded-lg text-xs text-center"
                                    :class="{
                                        'bg-blue-50 text-blue-600':   canalCliente === 'mayorista',
                                        'bg-indigo-50 text-indigo-600': canalCliente === 'distribuidor',
                                        'bg-green-50 text-green-600':  canalCliente === 'cliente_final',
                                    }">
                                    <span v-if="canalCliente === 'mayorista'">📋 Canal Mayorista — precio fijo, sin comisión</span>
                                    <span v-else-if="canalCliente === 'distribuidor'">🏪 Canal Distribuidor — precio resaltado es el sugerido</span>
                                    <span v-else>👤 Canal Cliente Final — precio resaltado es el sugerido ⭐</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Drag handle bar (mobile) -->
                    <div class="sm:hidden flex justify-center py-3 shrink-0">
                        <div class="w-10 h-1 bg-tinta-200 rounded-full"/>
                    </div>
                </div>
            </div>
        </Teleport>

    </AppLayout>
</template>
