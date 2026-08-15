<script setup>
// Los porcentajes se guardan con dos decimales: redondearlos al mostrarlos contradecía
// lo que la persona acababa de configurar.
import { formatPct } from '@/formato'
import { ref, computed, watch, onMounted } from 'vue'
import { useForm, router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import EditorTexto from '@/Components/EditorTexto.vue'
import ResultadosBuscadorProducto from '@/Components/ResultadosBuscadorProducto.vue'
import EtiquetaStock from '@/Components/EtiquetaStock.vue'
import ModalNuevoCliente from '@/Components/ModalNuevoCliente.vue'
import { useUnsavedChanges } from '@/composables/useUnsavedChanges'

const props = defineProps({
    cotizacion:            { type: Object, default: null },
    responsables:          Array,
    usuario_actual:        Number,
    condiciones_default:   String,
    plantillas:            { type: Array, default: () => [] },
    lead_preseleccionado:  { type: Object, default: null },
    // Los canales de precio configurados, ya en orden de prioridad.
    canales:               { type: Array, default: () => [] },
    // Las listas de segmentación, para poder crear un cliente sin salir de aquí.
    segmentacion_opciones: { type: Object, default: () => ({}) },
})

const esEdicion = computed(() => !!props.cotizacion)
const { hasChanges, setOriginal, checkChanges, markClean } = useUnsavedChanges()
onMounted(() => setOriginal(form.data()))

const hoy   = new Date().toISOString().split('T')[0]
const hoy30 = new Date(Date.now() + 30 * 86400000).toISOString().split('T')[0]

const page = usePage()

// El costo es un permiso aparte de ver el ensamble. Un vendedor necesita el precio para
// cotizar y no el costo: tenerlo en la pantalla de cotizar es la forma más fácil de que el
// margen de la empresa termine en una conversación con un cliente. El servidor tampoco lo
// manda, así que esto solo decide si se dibuja la caja.
const puedeVerCosto = computed(() => (page.props.auth?.permisosLista ?? []).includes('costos.ver'))

// ── Condiciones comerciales ───────────────────────────────────────────────────
// El texto de esta cotización se edita arriba. Guardarlo como general cambia con qué
// texto NACEN las cotizaciones nuevas; las hechas ya guardaron el suyo y no se tocan.
const guardandoCondiciones = ref(false)
const avisoCondiciones     = ref('')

const puedeConfigurar = computed(() =>
    (page.props.auth?.permisosLista ?? []).includes('configuracion.editar')
)

async function guardarCondicionesGenerales() {
    guardandoCondiciones.value = true
    avisoCondiciones.value = ''

    try {
        const res = await fetch('/api/cotizaciones/condiciones-generales', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': (() => {
                    const c = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))
                    return c ? decodeURIComponent(c.split('=')[1]) : ''
                })(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ condiciones: form.condiciones_comerciales }),
        })

        const data = await res.json().catch(() => null)

        if (! res.ok || ! data?.ok) throw new Error(data?.mensaje || `No se pudo guardar (${res.status}).`)

        avisoCondiciones.value = data.mensaje
    } catch (e) {
        avisoCondiciones.value = e.message
    } finally {
        guardandoCondiciones.value = false
    }
}

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
        // El rango de comisión sale de la fila del canal del cliente, que es la misma fuente
        // que se usa al agregar el ítem. Leerlo de las columnas antiguas del producto dejaba
        // el rango en 0–0 al reabrir la cotización, y con mínimo igual a máximo la barra de
        // negociar no se puede mover.
        // El rango se llena en cuanto se sabe el canal del cliente (ver el watch de más
        // abajo). Aquí no se puede: `canalCliente` es un const declarado después, y leerlo
        // antes de su declaración lanza un error que deja la pantalla en blanco.
        comision_pct_minima:         0,
        comision_pct_maxima:         0,
        // Lo aplicado se respeta tal cual: es lo que se negoció ese día.
        comision_pct_aplicada:       parseFloat(i.comision_pct_aplicada) || 0,
        comision_pct_actual:         parseFloat(i.comision_pct_aplicada) || 0,
        comision_min_distribuidor:   parseFloat(i.producto?.comision_min_distribuidor    ?? i.ensamble?.comision_min_distribuidor    ?? 0),
        comision_max_distribuidor:   parseFloat(i.producto?.comision_max_distribuidor    ?? i.ensamble?.comision_max_distribuidor    ?? 0),
        comision_min_cliente_final:  parseFloat(i.producto?.comision_min_cliente_final   ?? i.ensamble?.comision_min_cliente_final   ?? 0),
        comision_max_cliente_final:  parseFloat(i.producto?.comision_max_cliente_final   ?? i.ensamble?.comision_max_cliente_final   ?? 0),
        precio_lista:                parseFloat(i.precio_unitario),
        precio_minimo_absoluto:      0,
        descuento_max_cliente_final: parseFloat(i.producto?.descuento_max_cliente_final  ?? i.ensamble?.descuento_max_cliente_final  ?? 0),
        descuento_max_distribuidor:  parseFloat(i.producto?.descuento_max_distribuidor   ?? i.ensamble?.descuento_max_distribuidor   ?? 0),
        descuento_max_mayorista:     parseFloat(i.producto?.descuento_max_mayorista      ?? i.ensamble?.descuento_max_mayorista      ?? 0),
        // El stock de hoy, que lo calcula el servidor al abrir. No se guarda en el ítem:
        // es una ayuda de pantalla, y el inventario de verdad se comprueba al despachar.
        stock_disponible: i.stock_disponible ?? null,
        stock_minimo:     Number(i.stock_minimo) || 0,
        inventariable:    i.inventariable !== false,
        // Las filas por canal del producto o del ensamble, para que la comisión y el
        // descuento se puedan negociar igual que al crear.
        canales:          i.canales ?? [],
        // El ensamble completo, para poder reabrir sus medidas y recalcular sin pedirlo otra
        // vez al servidor. Se cotizó con una receta congelada; editarlo es volver a esa
        // pantalla, cambiar una medida y congelar la nueva.
        ensamble:         i.ensamble ?? null,
        // Por qué este ítem no se puede guardar, si es el caso. Lo decide el servidor.
        problema:         i.problema ?? null,
    })) ?? [],
})

watch(() => form.data(), (v) => checkChanges(v), { deep: true })

// ─── Cliente ──────────────────────────────────────────────────────────────────
const clienteQuery       = ref(props.cotizacion?.cliente?.nombre ?? props.lead_preseleccionado?.cliente?.nombre ?? '')
const clienteResultados  = ref([])
const clienteSeleccionado = ref(props.cotizacion?.cliente ?? props.lead_preseleccionado?.cliente ?? null)
const contactos          = ref(props.cotizacion?.cliente?.contactos ?? props.lead_preseleccionado?.cliente?.contactos ?? [])

// ─── Canal de precio según tipo de contacto ───────────────────────────────────
//
// Antes esto comparaba tres nombres escritos aquí —mayorista, distribuidor, y si no,
// cliente final—. Eso significaba que cualquier tipo de contacto que la empresa creara
// caía en «cliente final» sin que nada lo dijera. Ahora los canales llegan del servidor en
// el orden de prioridad que la empresa controla arrastrando la lista en Segmentación.
//
// **Y si al cliente no le corresponde ninguno, se usa el precio público.** Antes no se
// mostraba precio: la idea era evitar vender al precio equivocado sin notarlo. En la
// práctica dejaba cotizaciones en cero, que es peor —una cotización en cero se firma— y
// además el precio público es exactamente el que le corresponde a alguien de quien no
// sabemos nada. Lo que sí hace falta es DECIRLO, y eso se ve en pantalla.

/** El canal marcado como precio público, que es el respaldo. */
const canalPublico = computed(() =>
    (props.canales || []).find(c => c.es_precio_publico) ?? null
)

/** El canal que de verdad le corresponde al cliente por su segmentación, o null. */
const canalPropio = computed(() => {
    const tipos = clienteSeleccionado.value?.tipos_contacto || []
    if (!tipos.length) return null

    return (props.canales || []).find(c => tipos.includes(c.valor)) ?? null
})

/** El canal con el que se cotiza: el suyo, o el público como respaldo. */
const canalCliente = computed(() => canalPropio.value ?? canalPublico.value)

/**
 * Rellena el rango de comisión y el descuento máximo de cada ítem según el canal del cliente.
 *
 * Va en un watch y no en el arranque del formulario por dos razones. La primera es técnica:
 * al construir el formulario todavía no existe `canalCliente`. La segunda importa más — el
 * canal cambia si se cambia el cliente, y con él cambian los topes de negociación: antes se
 * quedaban los del cliente anterior.
 *
 * Lo aplicado no se toca: es lo que alguien negoció y se recorta solo si sale del rango.
 */
watch(canalCliente, () => {
    form.items.forEach(item => {
        const min = getCanalComisionMin(item)
        const max = getCanalComisionMax(item)

        item.comision_pct_minima = min
        item.comision_pct_maxima = max
        item.descuento_max       = getDescuentoMaxSegunCanal(item)

        const aplicada = parseFloat(item.comision_pct_aplicada) || 0

        // Sin nada negociado, se arranca en el máximo: es lo que gana el vendedor si no
        // regala descuento. Y si lo guardado se salió del rango nuevo, se recorta.
        item.comision_pct_actual = aplicada > 0
            ? Math.min(Math.max(aplicada, min), max)
            : max
    })
}, { immediate: true })

/** ¿Se está cotizando con el respaldo en vez de con el canal del cliente? */
const canalEsRespaldo = computed(() =>
    !! (clienteSeleccionado.value && ! canalPropio.value && canalPublico.value)
)

/** Qué está pasando con los precios, en palabras que sirvan en pantalla. */
const motivoSinCanal = computed(() => {
    if (! clienteSeleccionado.value) return 'Elige un cliente para ver los precios que le corresponden.'

    if (canalPropio.value) return null

    const tipos = clienteSeleccionado.value.tipos_contacto || []

    if (! canalPublico.value) {
        return 'Este cliente no tiene un tipo que defina precio, y tampoco hay ningún canal '
             + 'marcado como precio público. Marca uno en Segmentación.'
    }

    if (! tipos.length) {
        return `Este cliente no está segmentado, así que se está cotizando con ${canalPublico.value.etiqueta} `
             + '(el precio público). Asígnale un tipo de contacto en su ficha si le corresponde otro precio.';
    }

    return `Ninguno de los tipos de este cliente define precio, así que se está cotizando con `
         + `${canalPublico.value.etiqueta} (el precio público). Márcale «define precio» al tipo que `
         + 'corresponda en Segmentación.'
})

/** La fila de precios del producto o ensamble para el canal del cliente. */
function filaDelCanal(data) {
    const canal = canalCliente.value
    if (!canal) return null

    return (data?.canales || []).find(f => f.segmentacion_opcion_id === canal.id) ?? null
}

function getPrecioSegunCanal(data) {
    return parseFloat(filaDelCanal(data)?.precio) || 0
}

function getDescuentoMaxSegunCanal(data) {
    return parseFloat(filaDelCanal(data)?.descuento_max_pct) || 0
}

function getPrecioMinimo(data) {
    const fila = filaDelCanal(data)
    if (!fila) return 0

    const precio = parseFloat(fila.precio) || 0

    // El canal base es el piso de utilidad de la empresa: no baja de ahí.
    if (canalCliente.value?.es_canal_base) return precio

    const dtoMax = parseFloat(fila.descuento_max_pct) || 0

    return Math.round(precio * (1 - dtoMax / 100))
}
// Las comisiones salen de la fila del canal. El canal base las tiene en cero guardadas
// —el servicio se encarga—, así que no hace falta un caso especial aquí.
function getCanalComisionMin(data) {
    return parseFloat(filaDelCanal(data)?.comision_min_pct) || 0
}
function getCanalComisionMax(data) {
    return parseFloat(filaDelCanal(data)?.comision_max_pct) || 0
}

/**
 * El precio base contra el que se calcula la comisión: el del canal base.
 *
 * Se congela en la cotización, porque una comisión liquidada meses después tiene que
 * calcularse con el precio que había cuando se vendió, no con el de hoy. Antes se tomaba de
 * la columna `precio_mayorista`; ahora del canal que la empresa marcó como base.
 */
function getPrecioBase(data) {
    const base = (props.canales || []).find(c => c.es_canal_base)
    if (!base) return 0

    const fila = (data?.canales || []).find(f => f.segmentacion_opcion_id === base.id)

    return parseFloat(fila?.precio) || 0
}

/** El precio calculado del ensamble a medida, para el canal del cliente. */
const precioCalculadoDelCanal = computed(() => {
    const canal = canalCliente.value
    if (!canal || !preciosCalculados.value) return 0

    const fila = (preciosCalculados.value.canales || [])
        .find(f => f.segmentacion_opcion_id === canal.id)

    return parseFloat(fila?.precio) || 0
})
/**
 * ¿Este ítem paga comisión al vendedor?
 *
 * El canal base no paga: es el piso de utilidad de la empresa. Los demás pagan si tienen
 * comisión máxima cargada. Antes esto comparaba los tres nombres a mano, y cualquier canal
 * nuevo caía en la rama de «cliente final» leyendo una columna que no le correspondía.
 */
function itemTieneComision(item) {
    if (!canalCliente.value || canalCliente.value.es_canal_base) return false

    return (parseFloat(item.comision_pct_maxima) || 0) > 0
}

/** El canal base no admite descuento: su precio es el piso. */
const canalSinDescuento = computed(() => !canalCliente.value || canalCliente.value.es_canal_base)

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
// ── Cliente nuevo sin salir de la cotización ──────────────────────────────────
// Se abre con lo que el vendedor ya escribió en el buscador: si tecleó el nombre y no
// apareció, ese nombre es justamente el del cliente que hay que crear.
const modalCliente = ref(false)

const puedeCrearClientes = computed(() =>
    (page.props.auth?.permisosLista ?? []).includes('clientes.crear')
)

function clienteCreado(cliente) {
    modalCliente.value = false
    seleccionarCliente(cliente)
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
    itemEditandoIdx.value = null
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

    // Un precio en cero que nadie pidió no es un error que se note: es un error que se
    // firma. Se agrega igual —a veces se cotiza algo para ponerle precio a mano— pero
    // diciendo qué falta y dónde se arregla.
    if (precio <= 0) {
        const canal = canalCliente.value?.etiqueta ?? 'el canal de este cliente'
        alert(
            `«${prod.nombre}» no tiene precio cargado para ${canal}.

`
            + 'Se agrega en cero y puedes escribir el precio a mano, pero conviene cargarlo '
            + 'en el producto para que no vuelva a pasar.'
        )
    }
    form.items.push({
        tipo: prod.tipo ?? 'producto', producto_id: prod.id, ensamble_id: null,
        descripcion:       prod.nombre + (prod.referencia ? ` (${prod.referencia})` : ''),
        descripcion_corta: prod.descripcion_corta ?? '',
        descripcion_larga: prod.descripcion_larga ?? '',
        cantidad: 1, precio_unitario: precio, descuento_pct: 0, impuesto_pct: 0,
        orden: form.items.length,
        precio_lista:                precio,
        precio_mayorista_base:       getPrecioBase(prod),
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
        // El stock que había al elegirlo, para poder avisar si la cantidad cotizada lo
        // pasa. No se guarda en el ítem: es una ayuda de pantalla, y el inventario de
        // verdad se comprueba al despachar.
        stock_disponible: Number(prod.stock_total) || 0,
        stock_minimo:     Number(prod.stock_minimo) || 0,
        inventariable:    prod.inventariable !== false,
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
// Cuál ítem se está editando. Null = se está agregando uno nuevo. Es lo único que distingue
// los dos usos del mismo panel: se abre igual, se calcula igual, y al confirmar uno empuja un
// ítem nuevo y el otro reemplaza el que ya estaba.
const itemEditandoIdx      = ref(null)
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
                total_costo:           e.precio_costo ?? null,
                precio_costo:          e.precio_costo ?? null,
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

/**
 * Reabre las medidas de un ensamble que ya está en la cotización.
 *
 * Un ensamble se cotiza con su receta **congelada**: los materiales y el costo que resultaron
 * de esas medidas ese día. Editarlo no es cambiar un texto — es volver al panel de medidas,
 * cambiar lo que haya que cambiar y congelar la receta nueva. Por eso reusa el mismo panel
 * que al agregarlo, y no un formulario aparte que se iría separando.
 */
async function editarEnsambleDeItem(idx) {
    const item = form.items[idx]

    if (! item?.ensamble_id) return

    try {
        const r    = await fetch(`/api/ensambles/${item.ensamble_id}/variables-instancia`)
        const data = await r.json()

        camposInstancia.value = (data.campos ?? []).map(c => ({
            ...c,
            opciones_selector: typeof c.opciones_selector === 'string'
                ? JSON.parse(c.opciones_selector)
                : (c.opciones_selector ?? []),
        }))
        imagenesReferencia.value = data.imagenes_referencia ?? []
    } catch {
        camposInstancia.value = []
        imagenesReferencia.value = []
    }

    // El ensamble que viene con el ítem trae sus canales, que es lo que el panel necesita
    // para mostrar el precio del canal del cliente.
    ensambleInstancia.value = {
        ...(item.ensamble ?? { id: item.ensamble_id, nombre: item.descripcion }),
        // Las filas por canal vienen en el ítem, no en la relación del ensamble.
        canales: item.canales ?? [],
    }

    valoresInstancia.value  = { ...(item.variables_instancia ?? {}) }
    imagenesInstancia.value = [...(item.imagenes_instancia ?? [])]
    itemEditandoIdx.value   = idx

    // Se calcula de una: quien entra a editar quiere ver lo que hay, no un panel vacío.
    preciosCalculados.value = null
    modalPanel.value = 'ensamble_instancia'
    await calcularInstancia()
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

    // Editando: se reemplazan las medidas, la receta y el precio, y se conservan el id del
    // ítem, su cantidad y lo que se haya negociado. Reemplazar el ítem entero le borraría al
    // vendedor el descuento y la comisión que ya había acordado.
    if (itemEditandoIdx.value !== null) {
        const idx  = itemEditandoIdx.value
        const item = form.items[idx]

        Object.assign(item, {
            variables_snapshot:   e.variables,
            componentes_snapshot: preciosCalculados.value?.componentes ?? item.componentes_snapshot,
            variables_instancia:  { ...valoresInstancia.value },
            imagenes_instancia:   [...imagenesInstancia.value],
            descripcion_larga:    buildDescripcionLargaInstancia(e.descripcion_larga, valoresInstancia.value, camposInstancia.value),
            precio_unitario:      precio,
            precio_lista:         precio,
        })

        cerrarModal()

        return
    }

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

// Los ítems que el servidor ya marcó como imposibles de guardar. Se avisa antes de intentar:
// mandar el formulario para que vuelva rechazado es hacerle perder un viaje a quien lo llena.
const itemsConProblema = computed(() =>
    form.items
        .map((item, idx) => ({ item, numero: idx + 1 }))
        .filter(({ item }) => item.problema)
)

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
    if (canalSinDescuento.value) return

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
    // Se avisa antes de mandar: dejar que el servidor lo rechace le hace perder un viaje a
    // quien llena el formulario, y el aviso vuelve al final de la pantalla sin decir la línea.
    if (itemsConProblema.value.length) {
        const cuales = itemsConProblema.value.map(({ numero }) => numero).join(', ')

        alert(
            [
                `El ítem ${cuales} no se puede guardar: su producto cambió desde que se cotizó.`,
                '',
                'Quítalo con el botón rojo del ítem y agrégalo de nuevo. El resto de la cotización se conserva.',
            ].join('\n')
        )

        return
    }

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
                class="mb-3 inline-flex items-center gap-2 px-3 py-1.5 rounded-xl text-xs font-semibold text-aviso-naranja"
                style="background:var(--pastel-ambar); border:1px solid #F59E0B;">
                ● Cambios sin guardar
            </div>

            <!-- Badge generada desde lead -->
            <div v-if="props.lead_preseleccionado && !esEdicion"
                class="mb-3 inline-flex items-center gap-2 px-3 py-1.5 rounded-xl text-xs font-semibold text-aviso-azul"
                style="background:var(--pastel-azul-2); border:1px solid #93C5FD;">
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
                <div class="bg-superficie rounded-2xl border border-linea p-5">
                    <h2 class="text-sm font-semibold text-tinta-700 mb-4">Datos de la cotización</h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                        <!-- Búsqueda cliente -->
                        <div class="md:col-span-2">
                            <label class="block text-xs font-medium text-tinta-500 mb-1.5">Cliente</label>
                            <div class="relative">
                                <div class="flex gap-2">
                                    <input :value="clienteQuery" @input="buscarCliente($event.target.value)"
                                        type="text" placeholder="Buscar por nombre o identificación..."
                                        class="flex-1 rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:ring-2 focus:outline-none"/>
                                    <!-- Crear sin salir: irse a la pantalla de clientes con la
                                         cotización a medio armar es perder los ítems. -->
                                    <button v-if="puedeCrearClientes" type="button" @click="modalCliente = true"
                                        class="px-3 py-2 rounded-xl border border-linea text-sm text-tinta-500 hover:bg-tinta-50 shrink-0"
                                        title="Crear un cliente nuevo">+ Cliente</button>
                                </div>
                                <div v-if="clienteResultados.length > 0"
                                    class="absolute top-full left-0 right-0 mt-1 bg-superficie rounded-xl shadow-xl border border-linea z-20 overflow-hidden">
                                    <button v-for="c in clienteResultados" :key="c.id" type="button"
                                        @click="seleccionarCliente(c)"
                                        class="w-full flex items-center gap-3 px-4 py-2.5 hover:bg-tinta-50 text-left transition-colors border-b border-separador last:border-0">
                                        <div>
                                            <p class="text-sm font-medium text-tinta-900">{{ c.nombre }}{{ c.apellido ? ' ' + c.apellido : '' }}</p>
                                            <p class="text-xs text-tinta-300">{{ c.tipo_identificacion }}: {{ c.numero_identificacion }} · {{ c.ciudad }}</p>
                                        </div>
                                    </button>
                                </div>
                            </div>
                            <div v-if="clienteSeleccionado"
                                class="mt-2 flex items-center gap-3 px-3 py-2 rounded-xl"
                                style="background:var(--pastel-azul); border:1px solid #BFDBFE;">
                                <svg class="w-4 h-4 shrink-0" style="color:var(--marca)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                </svg>
                                <p class="text-sm font-medium flex-1" style="color:var(--texto-azul);">
                                    {{ clienteSeleccionado.nombre }}{{ clienteSeleccionado.apellido ? ' ' + clienteSeleccionado.apellido : '' }}
                                </p>
                                <!-- El canal que le corresponde, a la vista. Sin esto, dos
                                     clientes distintos daban precios distintos sin que
                                     nada en pantalla explicara por qué. -->
                                <span v-if="canalCliente"
                                    class="text-[11px] font-semibold px-2 py-0.5 rounded-full shrink-0"
                                    :style="canalEsRespaldo
                                        ? 'background:var(--pastel-ambar); color:var(--texto-ambar);'
                                        : `background:${canalCliente.color}1a; color:${canalCliente.color};`"
                                    :title="canalEsRespaldo
                                        ? 'Este cliente no tiene un tipo que defina precio: se usa el precio público'
                                        : 'El canal que le corresponde por su segmentación'">
                                    {{ canalCliente.etiqueta }}{{ canalEsRespaldo ? ' · por omisión' : '' }}
                                </span>
                                <button type="button" @click="limpiarCliente" class="text-blue-300 hover:text-aviso-azul">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                    </svg>
                                </button>
                            </div>

                            <!-- Cliente sin canal: no hay precios, y se dice por qué y qué
                                 hacer. Antes se le cotizaba como cliente final en silencio. -->
                            <div v-if="clienteSeleccionado && motivoSinCanal"
                                class="mt-2 flex items-start gap-2.5 px-3 py-2.5 rounded-xl bg-pastel-ambar border border-borde-aviso-ambar">
                                <svg class="w-4 h-4 shrink-0 mt-0.5 text-aviso-ambar" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>
                                </svg>
                                <p class="text-xs text-aviso-ambar leading-relaxed flex-1">{{ motivoSinCanal }}</p>
                                <button type="button" @click="router.visit('/clientes/' + clienteSeleccionado.id + '/editar')"
                                    class="text-xs font-semibold text-aviso-ambar underline underline-offset-2 shrink-0">
                                    Ver ficha
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
                <div class="bg-superficie rounded-2xl border border-linea p-5">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-sm font-semibold text-tinta-700">Ítems de la cotización</h2>
                        <span v-if="form.items.length > 0" class="text-xs text-tinta-300">{{ form.items.length }} ítem{{ form.items.length !== 1 ? 's' : '' }}</span>
                    </div>

                    <!-- Lista de ítems -->
                    <div v-if="form.items.length > 0" class="space-y-3 mb-4">
                        <div v-for="(item, idx) in form.items" :key="idx"
                            class="rounded-xl border transition-colors"
                            :class="dragOverIdx === idx && draggingIdx !== idx ? 'border-blue-400 bg-pastel-azul' : 'border-linea'"
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
                                <span v-if="item.tipo === 'producto'" class="text-xs px-2 py-0.5 rounded-full font-medium shrink-0" style="background:var(--pastel-azul);color:var(--texto-azul);">Producto</span>
                                <span v-else-if="item.tipo === 'servicio'" class="text-xs px-2 py-0.5 rounded-full font-medium shrink-0" style="background:var(--pastel-verde);color:var(--texto-verde);">Servicio</span>
                                <span v-else-if="item.tipo === 'ensamble'" class="text-xs px-2 py-0.5 rounded-full font-medium shrink-0" style="background:var(--pastel-naranja);color:#C2410C;">Ensamble</span>
                                <span v-else class="text-xs px-2 py-0.5 rounded-full font-medium bg-tinta-100 text-tinta-400 shrink-0">Texto libre</span>

                                <div class="flex-1"/>

                                <!-- ↑↓ botones (solo mobile) -->
                                <div class="flex sm:hidden gap-0.5 shrink-0">
                                    <button type="button" @click="moverItem(idx, -1)" :disabled="idx === 0"
                                        class="w-7 h-7 rounded-lg flex items-center justify-center text-tinta-300 hover:text-tinta-700 hover:bg-tinta-100 disabled:opacity-30 text-sm font-semibold transition-colors">↑</button>
                                    <button type="button" @click="moverItem(idx, 1)" :disabled="idx === form.items.length - 1"
                                        class="w-7 h-7 rounded-lg flex items-center justify-center text-tinta-300 hover:text-tinta-700 hover:bg-tinta-100 disabled:opacity-30 text-sm font-semibold transition-colors">↓</button>
                                </div>

                                <!-- Editar las medidas del ensamble. Solo en los de ensamble:
                                     un producto no tiene medidas que recalcular. -->
                                <button v-if="item.tipo === 'ensamble' && item.ensamble_id"
                                    type="button" @click="editarEnsambleDeItem(idx)"
                                    title="Cambiar medidas y recalcular"
                                    class="w-7 h-7 rounded-lg flex items-center justify-center text-tinta-300 hover:text-[var(--marca)] hover:bg-realce transition-colors shrink-0">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                    </svg>
                                </button>

                                <!-- Eliminar -->
                                <button type="button" @click="eliminarItem(idx)"
                                    class="w-7 h-7 rounded-lg flex items-center justify-center text-tinta-300 hover:text-aviso-rojo hover:bg-pastel-rojo transition-colors shrink-0">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                    </svg>
                                </button>
                            </div>

                            <!-- Este ítem no se puede guardar. Va DENTRO del ítem y con el
                                 botón que lo arregla: el mismo aviso al final de la pantalla
                                 no decía en qué línea, y con ocho ítems eso manda a revisar
                                 el equivocado. -->
                            <div v-if="item.problema"
                                class="mx-3 mt-2 rounded-lg bg-pastel-rojo border border-borde-aviso-rojo px-3 py-2.5">
                                <div class="flex items-start justify-between gap-2">
                                    <p class="text-xs text-aviso-rojo leading-relaxed">{{ item.problema }}</p>
                                    <button type="button" @click="eliminarItem(idx)"
                                        class="text-xs font-semibold text-aviso-rojo underline underline-offset-2 shrink-0">
                                        Quitar
                                    </button>
                                </div>
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
                                        <div class="flex items-center justify-between gap-1 mb-1">
                                            <label class="block text-xs text-tinta-400">Cantidad</label>
                                            <!-- Lo que queda, al lado de lo que se pide: si la
                                                 cantidad pasa el stock lo dice ahí mismo, sin
                                                 tener que abrir el inventario en otra pestaña. -->
                                            <EtiquetaStock
                                                :stock="item.stock_disponible"
                                                :minimo="item.stock_minimo"
                                                :inventariable="item.inventariable"
                                                :pedida="item.cantidad"
                                            />
                                        </div>
                                        <input v-model.number="item.cantidad" type="number" step="0.001" min="0"
                                            :class="['w-full rounded-lg border px-2 py-1.5 text-sm text-right focus:outline-none',
                                                (item.inventariable || Number(item.stock_disponible) !== 0) && item.stock_disponible !== null && Number(item.cantidad) > Number(item.stock_disponible)
                                                    ? 'border-borde-aviso-rojo bg-pastel-rojo' : 'border-tinta-200']"/>
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
                                            :disabled="canalSinDescuento"
                                            class="w-full rounded-lg border border-tinta-200 px-2 py-1.5 text-sm text-right focus:outline-none"
                                            :class="canalSinDescuento ? 'bg-tinta-100 cursor-not-allowed text-tinta-300' : ''"
                                            @change="!canalSinDescuento && (itemTieneComision(item)
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
                                <div v-if="canalSinDescuento"
                                    class="mt-2 p-2 bg-pastel-azul border border-borde-aviso-azul rounded-lg">
                                    <p class="text-xs text-aviso-azul text-center">
                                        Canal mayorista — precio fijo sin descuento ni comisión
                                    </p>
                                </div>

                                <!-- Panel comisión compacto -->
                                <div v-if="itemTieneComision(item)"
                                    class="mt-2 flex items-center gap-2 px-3 py-2 rounded-xl"
                                    style="background:var(--pastel-ambar);border:1px solid #FCD34D;">
                                    <span class="text-xs font-medium shrink-0" style="color:var(--texto-ambar);">Com.</span>
                                    <button type="button" @click="ajustarComision(item, idx, -0.5)"
                                        :disabled="(item.comision_pct_actual || getCanalComisionMax(item)) <= getCanalComisionMin(item)"
                                        class="w-6 h-6 rounded-full flex items-center justify-center font-semibold text-sm disabled:opacity-40 shrink-0 hover:bg-pastel-ambar-2 transition-colors"
                                        style="border:1px solid #FCD34D;color:var(--texto-ambar);">−</button>
                                    <input type="range"
                                        :min="getCanalComisionMin(item)"
                                        :max="getCanalComisionMax(item)"
                                        :step="0.01"
                                        :value="item.comision_pct_actual || getCanalComisionMax(item)"
                                        @input="onComisionChange(item, idx, $event.target.value)"
                                        class="flex-1 h-1.5 accent-amber-500 cursor-pointer" />
                                    <button type="button" @click="ajustarComision(item, idx, 0.5)"
                                        :disabled="(item.comision_pct_actual || getCanalComisionMax(item)) >= getCanalComisionMax(item)"
                                        class="w-6 h-6 rounded-full flex items-center justify-center font-semibold text-sm disabled:opacity-40 shrink-0 hover:bg-pastel-ambar-2 transition-colors"
                                        style="border:1px solid #FCD34D;color:var(--texto-ambar);">+</button>
                                    <span class="text-xs font-semibold shrink-0 text-right" style="color:var(--texto-ambar);min-width:5.5rem;">
                                        {{ formatPct(item.comision_pct_actual || getCanalComisionMax(item)) }}% · ${{ formatCOP(comisionActualValor(item)) }}
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
                        class="w-full py-2.5 rounded-xl border-2 border-dashed border-tinta-200 text-sm font-medium text-tinta-400 hover:border-blue-400 hover:text-aviso-azul hover:bg-realce transition-colors flex items-center justify-center gap-2">
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
                                    <span class="text-aviso-rojo">-${{ formatCOP(totalDescuento) }}</span>
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
                <div class="bg-superficie rounded-2xl border border-linea p-5">
                    <div class="flex flex-wrap items-baseline justify-between gap-2 mb-3">
                        <h2 class="text-sm font-semibold text-tinta-700">Condiciones comerciales</h2>
                        <!-- Se edita donde se ve, y desde aquí mismo se vuelve el texto
                             general: es el momento en que uno se da cuenta de que hay que
                             cambiarlo para todas. -->
                        <button v-if="puedeConfigurar" type="button" @click="guardarCondicionesGenerales"
                            :disabled="guardandoCondiciones"
                            class="text-xs font-semibold text-[var(--marca)] hover:underline disabled:opacity-50">
                            {{ guardandoCondiciones ? 'Guardando…' : 'Usar este texto para todas las cotizaciones nuevas' }}
                        </button>
                    </div>
                    <textarea v-model="form.condiciones_comerciales" rows="4"
                        class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:ring-2 focus:outline-none resize-none"
                        placeholder="Condiciones de pago, entrega, validez..."/>
                    <p v-if="avisoCondiciones" class="mt-2 text-xs text-tinta-500">{{ avisoCondiciones }}</p>
                    <p v-else class="mt-2 text-xs text-tinta-300">
                        Este texto es solo de esta cotización. Las que ya están hechas conservan el
                        suyo, aunque cambies el general.
                    </p>
                </div>

                <!-- Errores -->
                <div v-if="Object.keys(form.errors).length > 0" class="bg-pastel-rojo border border-borde-aviso-rojo rounded-xl p-4">
                    <p v-for="(msg, field) in form.errors" :key="field" class="text-sm text-aviso-rojo">⚠ {{ msg }}</p>
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
                <div class="relative w-full sm:max-w-lg bg-superficie rounded-t-3xl sm:rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">

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
                            class="w-full flex items-center gap-4 p-4 rounded-2xl border-2 border-linea hover:border-blue-400 hover:bg-realce transition-colors text-left group">
                            <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0 group-hover:bg-pastel-azul-2 transition-colors" style="background:var(--pastel-azul);">
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
                            class="w-full flex items-center gap-4 p-4 rounded-2xl border-2 border-linea hover:border-orange-400 hover:bg-pastel-naranja transition-colors text-left group">
                            <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0 transition-colors" style="background:var(--pastel-naranja);">
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
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:ring-2 focus:border-[var(--marca)] focus:outline-none"/>
                        </div>
                        <div class="overflow-y-auto flex-1">
                            <p v-if="!productoQuery" class="text-sm text-tinta-300 text-center py-8">Escribe para buscar...</p>
                            <p v-else-if="productoResultados.length === 0 && productoQuery.length >= 2" class="text-sm text-tinta-300 text-center py-8">Sin resultados para "{{ productoQuery }}"</p>
                            <ResultadosBuscadorProducto :resultados="productoResultados" @elegir="agregarItemDesdeProducto">
                                <template #extra="{ producto }">
                                    <div class="text-right shrink-0">
                                        <p class="text-sm font-semibold" style="color:var(--marca)">${{ formatCOP(getPrecioSegunCanal(producto)) }}</p>
                                        <p class="text-xs text-tinta-300">{{ canalCliente?.etiqueta ?? 'sin canal' }}</p>
                                        <!-- Cuántas quedan. Esta pantalla mostraba solo el
                                             precio: se cotizaba sin saber si había con qué
                                             cumplir, y el faltante aparecía en producción. -->
                                        <div class="mt-1 flex justify-end">
                                            <EtiquetaStock
                                                :stock="producto.stock_total"
                                                :minimo="producto.stock_minimo"
                                                :inventariable="producto.inventariable !== false"
                                                completo
                                            />
                                        </div>
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
                            <div v-for="e in ensambleResultados" :key="e.id" class="border-b border-separador last:border-0">
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
                                <!-- Solo el precio del canal del cliente.
                                     Antes se mostraban los tres y se resaltaba el suyo: eso
                                     le pone delante el precio mayorista a quien está
                                     cotizando a un cliente final, y basta un clic en la
                                     tarjeta equivocada para vender al costo. -->
                                <div v-if="ensambleExpandido?.id === e.id" class="px-5 pb-4 pt-2" style="background:var(--pastel-ambar);">
                                    <div v-if="canalCliente" :class="['grid gap-2', puedeVerCosto ? 'grid-cols-2' : 'grid-cols-1']">
                                        <button type="button" @click="agregarItemDesdeEnsamble(e, getPrecioSegunCanal(e))"
                                            class="text-left p-3 rounded-xl transition-all border-2"
                                            :style="`border-color:${canalCliente.color}; background:${canalCliente.color}12;`">
                                            <div class="flex items-center justify-between mb-1">
                                                <p class="text-xs text-tinta-400">{{ canalCliente.etiqueta }}</p>
                                                <span class="text-xs px-1.5 py-0.5 rounded-full text-white"
                                                    :style="`background:${canalCliente.color};`">{{ canalEsRespaldo ? '★ Por omisión' : '★ Su canal' }}</span>
                                            </div>
                                            <p class="font-semibold text-tinta-900">${{ formatCOP(getPrecioSegunCanal(e)) }}</p>
                                            <p class="text-xs mt-0.5" :style="`color:${canalCliente.color};`">
                                                {{ canalCliente.es_canal_base ? 'Precio fijo · Sin descuento' : 'Precio sugerido' }}
                                            </p>
                                        </button>
                                        <!-- El costo solo para quien tiene permiso de verlo. El
                                             servidor tampoco lo manda al resto. -->
                                        <button v-if="puedeVerCosto" type="button" @click="agregarItemDesdeEnsamble(e, e.precio_costo)"
                                            class="text-left p-3 rounded-xl border border-linea bg-tinta-50 opacity-70 hover:opacity-100 transition-all">
                                            <p class="text-xs text-tinta-300 mb-1">Costo</p>
                                            <p class="font-semibold text-tinta-500">${{ formatCOP(e.precio_costo) }}</p>
                                            <p class="text-xs text-tinta-300 mt-0.5">Solo referencia</p>
                                        </button>
                                    </div>

                                    <div v-else class="p-3 rounded-xl bg-pastel-ambar border border-borde-aviso-ambar">
                                        <p class="text-xs text-aviso-ambar leading-relaxed">{{ motivoSinCanal }}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Configurador variable_instancia -->
                    <div v-else-if="modalPanel === 'ensamble_instancia'" class="flex flex-col overflow-hidden">
                        <div class="px-5 py-3 border-b border-linea shrink-0">
                            <p class="text-sm font-semibold text-tinta-900">
                                {{ ensambleInstancia?.nombre }}
                                <span v-if="itemEditandoIdx !== null"
                                    class="ml-1 text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-pastel-azul text-aviso-azul align-middle">
                                    editando el ítem {{ itemEditandoIdx + 1 }}
                                </span>
                            </p>
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
                                <div v-for="img in imagenesReferencia" :key="img.id" class="rounded-xl overflow-hidden border border-borde-aviso-azul bg-pastel-azul">
                                    <img :src="'/storage/' + img.imagen_referencia" class="w-full max-h-40 object-contain bg-superficie" />
                                    <p class="text-xs text-aviso-azul px-3 py-1.5 font-medium">
                                        {{ img.imagen_referencia_titulo || img.etiqueta || img.nombre }}
                                    </p>
                                </div>
                            </div>

                            <!-- ── Imágenes propias del proyecto ────────────── -->
                            <div class="space-y-2">
                                <p class="text-xs font-semibold text-tinta-400 uppercase">Planos del proyecto <span class="normal-case font-normal text-tinta-300">(opcional)</span></p>
                                <div v-for="(img, idx) in imagenesInstancia" :key="idx" class="relative rounded-xl overflow-hidden border border-linea">
                                    <img :src="'/storage/' + img.ruta" class="w-full max-h-32 object-contain bg-tinta-50" />
                                    <div class="flex items-center justify-between px-3 py-1.5 bg-superficie border-t border-linea">
                                        <input v-model="img.titulo" type="text" placeholder="Título del plano..."
                                            class="flex-1 text-xs text-tinta-700 focus:outline-none bg-transparent" />
                                        <button @click="quitarImagenInstancia(idx)" class="text-red-400 hover:text-aviso-rojo text-sm ml-2">✕</button>
                                    </div>
                                </div>
                                <label class="flex items-center gap-2 justify-center border-2 border-dashed border-linea rounded-xl py-3 cursor-pointer hover:border-borde-aviso-azul hover:bg-realce transition-colors">
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
                                <!-- Solo el canal del cliente, más el costo como referencia. -->
                                <div v-if="canalCliente" :class="['grid gap-2', puedeVerCosto ? 'grid-cols-2' : 'grid-cols-1']">
                                    <button type="button" @click="agregarItemDesdeEnsambleInstancia(precioCalculadoDelCanal)"
                                        class="text-left p-3 rounded-xl transition-all border-2"
                                        :style="`border-color:${canalCliente.color}; background:${canalCliente.color}12;`">
                                        <div class="flex items-center justify-between mb-1">
                                            <p class="text-xs text-tinta-400">{{ canalCliente.etiqueta }}</p>
                                            <span class="text-xs px-1.5 py-0.5 rounded-full text-white"
                                                :style="`background:${canalCliente.color};`">{{ canalEsRespaldo ? '★ Por omisión' : '★ Su canal' }}</span>
                                        </div>
                                        <p class="font-semibold text-tinta-900">${{ formatCOP(precioCalculadoDelCanal) }}</p>
                                        <p class="text-xs mt-0.5" :style="`color:${canalCliente.color};`">
                                            {{ canalCliente.es_canal_base ? 'Precio fijo · Sin descuento' : 'Precio sugerido' }}
                                        </p>
                                    </button>
                                    <button v-if="puedeVerCosto" type="button" @click="agregarItemDesdeEnsambleInstancia(preciosCalculados.total_costo)"
                                        class="text-left p-3 rounded-xl border border-linea bg-tinta-50 opacity-70 hover:opacity-100 transition-all">
                                        <p class="text-xs text-tinta-300 mb-1">Costo</p>
                                        <p class="font-semibold text-tinta-500">${{ formatCOP(preciosCalculados.total_costo) }}</p>
                                        <p class="text-xs text-tinta-300 mt-0.5">Solo referencia</p>
                                    </button>
                                </div>

                                <div v-else class="p-3 rounded-xl bg-pastel-ambar border border-borde-aviso-ambar">
                                    <p class="text-xs text-aviso-ambar leading-relaxed">{{ motivoSinCanal }}</p>
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

        <ModalNuevoCliente
            v-if="modalCliente"
            :segmentacion="segmentacion_opciones"
            :nombre-inicial="clienteQuery"
            @creado="clienteCreado"
            @cerrar="modalCliente = false"
        />
    </AppLayout>
</template>
