<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useUnsavedChanges } from '@/composables/useUnsavedChanges'
import CampoInstancia from '@/Components/CampoInstancia.vue'
import EditorTexto from '@/Components/EditorTexto.vue'
import GeneradorFichaIa from '@/Components/GeneradorFichaIa.vue'
import PreciosPorCanal from '@/Components/PreciosPorCanal.vue'
import LineasEnsambleDirecto from '@/Components/LineasEnsambleDirecto.vue'
import SelectorUnidad from '@/Components/SelectorUnidad.vue'
import { usePreciosPorCanal } from '@/composables/usePreciosPorCanal'
import { colorMarca } from '@/marca'

const props = defineProps({
    plantillas: { type: Array, default: () => [] },
    categorias: { type: Array, default: () => [] },
    ensamble:   { type: Object, default: null },
    // Los canales de precio configurados en Segmentación, ya con lo que el ensamble tenga
    // guardado. Antes esta pantalla tenía tres cajas fijas que escribían solo las columnas
    // antiguas —invisibles para la cotización—, igual que le pasaba a la de productos.
    canales:    { type: Array, default: () => [] },
    // Duplicar: los datos del ensamble original, sin su id. La pantalla es la misma que
    // crear, así que todo se lee de `inicial` y `esEdicion` sigue siendo «tiene id».
    base:       { type: Object, default: null },
    origen:     { type: Object, default: null },
})

const esEdicion = computed(() => !!props.ensamble)

// De dónde salen los valores del formulario: el ensamble que se edita, o la copia.
const inicial = props.ensamble ?? props.base ?? null
const { hasChanges, setOriginal, checkChanges, markClean } = useUnsavedChanges()

// ── Estado principal ──────────────────────────────────────────────────────────
// Cómo se arma el ensamble. «plantilla» son medidas y fórmulas —lo que hace falta para
// fabricar por medida—; «directo» es la receta escrita a mano, con cantidades exactas. En
// edición no se cambia: sería reescribir la receta completa.
const tipoArmado               = ref(inicial?.tipo_armado ?? 'plantilla')
const esDirecto                = computed(() => tipoArmado.value === 'directo')
const plantillaId              = ref(inicial?.plantilla_id ?? '')
const nombre                   = ref(inicial?.nombre ?? '')
// La referencia se deja en blanco al crear: el servidor genera ENS-0001 y siguientes. Al
// duplicar tampoco se copia — dos ensambles con el mismo código no se pueden distinguir.
const referencia               = ref(props.ensamble?.referencia ?? '')
const unidadMedida             = ref(inicial?.unidad_medida ?? 'unidad')
const margenesActuales         = ref({ mayorista: 30, distribuidor: 32.5, cliente_final: 35, por_defecto: 'distribuidor' })
const variables                = reactive({})
const componentes              = ref(inicial?.componentes_resultado ?? [])
const totalCosto               = ref(inicial?.precio_costo ?? 0)
const precioMayor              = ref(props.ensamble?.precio_mayorista ?? 0)
const precioDist               = ref(props.ensamble?.precio_distribuidor ?? 0)
const precioFinal              = ref(props.ensamble?.precio_cliente_final ?? 0)
const page                     = usePage()
const calculado                = ref(!!inicial)
const calculando               = ref(false)
const guardando                = ref(false)
const errorMsg                 = ref('')
const nombreEditadoManualmente = ref(!!inicial)

// ── Ensamble directo: la receta a mano ────────────────────────────────────────
// Los componentes guardados tienen la misma forma vengan de una fórmula o de aquí, así que
// al editar se leen tal cual y se vuelven líneas escribibles.
const lineas = ref(
    (inicial?.tipo_armado === 'directo' ? (inicial?.componentes_resultado ?? []) : []).map(c => ({
        producto_id: c.producto_id ?? null,
        concepto:    c.nombre ?? '',
        referencia:  c.referencia ?? null,
        unidad:      c.unidad ?? 'unidad',
        cantidad:    Number(c.cantidad) || 0,
        precio_unit: Number(c.precio_unit) || 0,
    }))
)

const costoDeLineas = computed(() =>
    lineas.value.reduce((s, l) => s + (Number(l.cantidad) || 0) * (Number(l.precio_unit) || 0), 0)
)

// El costo de un ensamble directo es la suma de su receta, y tiene que llegar al
// componente de precios para que los márgenes calculen sobre algo.
watch(costoDeLineas, (v) => { if (esDirecto.value) totalCosto.value = v })

// ── Precios por canal ─────────────────────────────────────────────────────────
const canales = ref((props.canales ?? []).map(c => ({ ...c })))

const { hayErrorDeEscalera, aplicarDescuentosMax } = usePreciosPorCanal(
    canales,
    computed(() => Number(totalCosto.value) || 0),
)

/**
 * Siembra los márgenes de los canales con los que trae la plantilla.
 *
 * La plantilla guarda margen para los tres canales originales —mayorista, distribuidor,
 * cliente final—, así que se reparten por el papel de cada canal: el base recibe el de
 * mayorista, el que sea precio público el de cliente final, y el primer intermedio el de
 * distribuidor. Los demás conservan el margen sugerido de Segmentación. Es una siembra: el
 * usuario los ajusta ensamble por ensamble.
 */
function sembrarMargenes(conf) {
    if (! conf) return

    let intermedioUsado = false

    canales.value.forEach(canal => {
        if (canal.es_canal_base && conf.margen_mayorista != null) {
            canal.margen_pct = Number(conf.margen_mayorista)
        } else if (canal.es_precio_publico && conf.margen_cliente_final != null) {
            canal.margen_pct = Number(conf.margen_cliente_final)
        } else if (! canal.es_canal_base && ! intermedioUsado && conf.margen_distribuidor != null) {
            canal.margen_pct = Number(conf.margen_distribuidor)
            intermedioUsado  = true
        }
    })
}

// ── Estado de catálogo ────────────────────────────────────────────────────────
const categoriaId     = ref(inicial?.categoria_id ?? '')
const descripcionCorta = ref(inicial?.descripcion_corta ?? '')
const descripcionLarga = ref(inicial?.descripcion_larga ?? '')
// El técnico corto, el que sale en cotizaciones y órdenes de producción.
const descripcionCotizacion = ref(inicial?.descripcion_cotizacion ?? '')
const listaCats        = ref([...(props.categorias ?? [])])

// ── Ficha técnica con IA ──────────────────────────────────────────────────────
// Un ensamble se describe con sus medidas: si ya está guardado, el servidor le pasa a la
// IA sus variables y su receta calculada, y el usuario solo agrega lo que no está ahí.
const datosParaFicha = computed(() => ({
    tipo:              'ensamble',
    nombre:            nombre.value,
    referencia:        props.ensamble ? `ENS-${props.ensamble.id}` : null,
    categoria:         listaCats.value.find(c => String(c.id) === String(categoriaId.value))?.nombre ?? null,
    unidad:            'unidad',
    descripcion_corta: descripcionCorta.value,
}))

function aplicarFicha({ descripcion_corta, descripcion_cotizacion, descripcion_larga }) {
    if (descripcion_corta) descripcionCorta.value = descripcion_corta
    if (descripcion_cotizacion) descripcionCotizacion.value = descripcion_cotizacion
    if (descripcion_larga) descripcionLarga.value = descripcion_larga
}

// Lo único que sobrevive del esquema viejo de comisiones: el piso de utilidad de la
// empresa. Los rangos por canal los maneja ahora el componente de precios, y las columnas
// antiguas las escribe el espejo del servidor al guardar.
const utilidadMinEmpresa = ref(inicial?.utilidad_minima_empresa_pct ?? 15)

// ── Modal nueva categoría ─────────────────────────────────────────────────────
const showModalCat  = ref(false)
const nuevaCat      = ref({ nombre: '', color: colorMarca() })
const guardandoCat  = ref(false)

const csrf = () => { const c = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN=')); return c ? decodeURIComponent(c.split('=')[1]) : '' }

async function crearCategoria() {
    if (!nuevaCat.value.nombre) return
    guardandoCat.value = true
    try {
        const res = await fetch('/api/categorias-producto', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': csrf() },
            body: JSON.stringify(nuevaCat.value),
        })
        const cat = await res.json()
        listaCats.value.push(cat)
        categoriaId.value  = cat.id
        showModalCat.value = false
        nuevaCat.value = { nombre: '', color: colorMarca() }
    } finally { guardandoCat.value = false }
}

// ── Imágenes al crear ────────────────────────────────────────────────────────
// Se quedan en memoria hasta que se guarda: un ensamble sin id todavía no tiene dónde
// colgarlas. Van dentro del mismo envío y el servidor las guarda al final.
const imagenesNuevas = ref([])

function elegirImagenes(e) {
    for (const file of Array.from(e.target.files ?? [])) {
        const lector = new FileReader()
        lector.onload = (ev) => imagenesNuevas.value.push({ file, url: ev.target.result })
        lector.readAsDataURL(file)
    }

    // Se limpia para que elegir el mismo archivo otra vez vuelva a disparar el evento.
    e.target.value = ''
}

function quitarImagenNueva(i) {
    imagenesNuevas.value.splice(i, 1)
}

// ── Imágenes (solo en modo edición) ──────────────────────────────────────────
const imgSubiendo         = ref(false)
const imagenPrincipal     = ref(props.ensamble?.imagen_principal ?? null)
const imagenesSecundarias = ref(props.ensamble?.imagenes_secundarias ?? [])

// Las imágenes nuevas se guardan como ruta relativa, pero las viejas quedaron
// con la URL completa de Google Drive. Se respeta la que ya venga absoluta.
const urlImagen = (v) => (!v ? null : (v.startsWith('http') ? v : `/storage/${v}`))

const imagenPrincipalUrl = computed(() => urlImagen(imagenPrincipal.value))

async function subirImagenPrincipal(e) {
    const file = e.target.files?.[0]
    if (!file || !props.ensamble?.id) return
    imgSubiendo.value = true
    const fd = new FormData(); fd.append('imagen', file)
    try {
        const res  = await fetch(`/ensambles/${props.ensamble.id}/imagen-principal`, { method: 'POST', headers: { 'X-XSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', body: fd })
        const data = await res.json()
        imagenPrincipal.value = data.ruta
    } catch { errorMsg.value = 'Error al subir imagen.' }
    finally { imgSubiendo.value = false; e.target.value = '' }
}

async function quitarImagenPrincipal() {
    if (!imagenPrincipal.value || !props.ensamble?.id) return
    await fetch(`/ensambles/${props.ensamble.id}/imagen-principal`, { method: 'DELETE', headers: { 'X-XSRF-TOKEN': csrf(), Accept: 'application/json' } })
    imagenPrincipal.value = null
}

async function subirImagenSecundaria(e) {
    const file = e.target.files?.[0]
    if (!file || !props.ensamble?.id) return
    const fd = new FormData(); fd.append('imagen', file)
    try {
        const res  = await fetch(`/ensambles/${props.ensamble.id}/imagenes-secundarias`, { method: 'POST', headers: { 'X-XSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', body: fd })
        const data = await res.json()
        imagenesSecundarias.value = data.imagenes ?? []
    } catch { errorMsg.value = 'Error al subir imagen.' }
    finally { e.target.value = '' }
}

async function quitarImagenSecundaria(ruta) {
    if (!props.ensamble?.id) return
    await fetch(`/ensambles/${props.ensamble.id}/imagenes-secundarias`, {
        method: 'DELETE',
        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': csrf(), Accept: 'application/json' },
        body: JSON.stringify({ ruta }),
    })
    imagenesSecundarias.value = imagenesSecundarias.value.filter(r => r !== ruta)
}

// ── Plantilla seleccionada ────────────────────────────────────────────────────
const plantillaSeleccionada = computed(() =>
    props.plantillas.find(p => p.id == plantillaId.value) ?? null
)

if (inicial?.variables) {
    Object.assign(variables, inicial.variables)
}

watch(plantillaId, (nuevoId) => {
    nombreEditadoManualmente.value = false
    if (!nuevoId) return
    const plantilla = props.plantillas.find(p => p.id == nuevoId)
    if (!plantilla) return

    // Inicializar variables según tipo_campo + subtipo_variable
    for (const key in variables) delete variables[key]
    for (const campo of (plantilla.campos ?? []).filter(c => c.tipo_campo !== 'calculado')) {
        if (campo.tipo_campo === 'variable_instancia') {
            if (campo.subtipo_variable === 'selector') {
                variables[campo.nombre] = campo.valor_defecto ?? campo.opciones_selector?.[0]?.valor ?? ''
            } else if (campo.subtipo_variable === 'decimal' || campo.subtipo_variable === 'numero') {
                variables[campo.nombre] = parseFloat(campo.valor_defecto ?? 0) || 0
            } else {
                variables[campo.nombre] = campo.valor_defecto ?? ''
            }
        } else {
            if (campo.tipo === 'boolean' || campo.tipo === 'checkbox') {
                variables[campo.nombre] = campo.valor_defecto === 'true' || campo.valor_defecto === true || false
            } else if (campo.tipo === 'decimal' || campo.tipo === 'numero') {
                variables[campo.nombre] = parseFloat(campo.valor_defecto ?? 0) || 0
            } else {
                variables[campo.nombre] = campo.valor_defecto ?? ''
            }
        }
    }

    // Actualizar márgenes desde config_salida de la plantilla
    const conf = plantilla.config_salida ?? {}
    margenesActuales.value = {
        mayorista:    conf.margen_mayorista    ?? 30,
        distribuidor: conf.margen_distribuidor  ?? 32.5,
        cliente_final: conf.margen_cliente_final ?? 35,
        por_defecto:  conf.precio_defecto_cotizar ?? 'distribuidor',
    }

    componentes.value = []
    calculado.value   = false
})

function onNombreInput() { nombreEditadoManualmente.value = true }

watch(variables, () => {
    if (!nombreEditadoManualmente.value) nombre.value = autoNombre.value
    checkChanges({ plantillaId: plantillaId.value, nombre: nombre.value, variables: { ...variables } })
}, { deep: true })

const autoNombre = computed(() => {
    if (!plantillaSeleccionada.value) return ''
    const parts = [plantillaSeleccionada.value.nombre]
    for (const [k, v] of Object.entries(variables)) {
        if (v && v !== false) parts.push(v)
    }
    return parts.join(' ')
})

// ── Calcular ──────────────────────────────────────────────────────────────────
// Equivalente a =MULTIPLO.SUPERIOR(costo/(1-margen);5000)
const calcPrecio = (costo, margen) => Math.ceil(costo / (1 - margen / 100) / 5000) * 5000

async function calcular() {
    if (!plantillaId.value) return
    calculando.value = true
    errorMsg.value   = ''
    try {
        const res = await fetch('/api/ensambles/calcular', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': csrf() },
            body: JSON.stringify({ plantilla_id: plantillaId.value, variables: { ...variables } }),
        })
        const data = await res.json()
        componentes.value = data.componentes
        totalCosto.value  = data.total_costo

        const mmay  = data.margen_mayorista    ?? margenesActuales.value.mayorista
        const mdist = data.margen_distribuidor ?? margenesActuales.value.distribuidor
        const mfin  = data.margen_cliente_final ?? margenesActuales.value.cliente_final
        margenesActuales.value = {
            mayorista:     mmay,
            distribuidor:  mdist,
            cliente_final: mfin,
            por_defecto:   data.precio_por_defecto ?? margenesActuales.value.por_defecto,
        }
        precioMayor.value = calcPrecio(data.total_costo, mmay)
        precioDist.value  = calcPrecio(data.total_costo, mdist)
        precioFinal.value = calcPrecio(data.total_costo, mfin)

        // Los precios de verdad son los de los canales: el margen de la plantilla solo
        // siembra el suyo, y el componente de precios recalcula con el costo nuevo.
        sembrarMargenes({
            margen_mayorista:     mmay,
            margen_distribuidor:  mdist,
            margen_cliente_final: mfin,
        })

        calculado.value = true
    } catch {
        errorMsg.value = 'Error al calcular los componentes.'
    } finally {
        calculando.value = false
    }
}

// ── Guardar ───────────────────────────────────────────────────────────────────
async function guardar() {
    if (!nombre.value) {
        errorMsg.value = 'Asigna un nombre al ensamble.'
        return
    }
    if (esDirecto.value) {
        if (!lineas.value.some(l => (Number(l.cantidad) || 0) > 0 && (l.producto_id || l.concepto?.trim()))) {
            errorMsg.value = 'Agrega al menos un componente con cantidad.'
            return
        }
    } else {
        if (!plantillaId.value) {
            errorMsg.value = 'Selecciona una plantilla.'
            return
        }
        if (!calculado.value) {
            errorMsg.value = 'Primero calcula los componentes.'
            return
        }
    }
    // La escalera de comisiones se valida sobre la lista de canales, no sobre dos nombres
    // fijos: la empresa puede tener cuatro.
    if (hayErrorDeEscalera.value) {
        errorMsg.value = 'Corrige los rangos de comisión: la mínima de un canal no puede ser menor que la máxima del canal anterior.'
        return
    }
    guardando.value = true
    errorMsg.value  = ''
    markClean()

    const def = margenesActuales.value.por_defecto
    const margenDefault = def === 'mayorista'    ? margenesActuales.value.mayorista
        : def === 'cliente_final' ? margenesActuales.value.cliente_final
        : margenesActuales.value.distribuidor

    // El descuento máximo de cada canal sale de su distancia con el canal de abajo: se
    // calcula al guardar, no se pide en pantalla.
    aplicarDescuentosMax()

    const payload = {
        tipo_armado:         tipoArmado.value,
        plantilla_id:        esDirecto.value ? null : plantillaId.value,
        nombre:              nombre.value,
        referencia:          referencia.value || null,
        unidad_medida:       unidadMedida.value || 'unidad',
        variables:           esDirecto.value ? {} : { ...variables },
        lineas:              esDirecto.value ? lineas.value : [],
        canales:             canales.value,
        precio_costo:                  totalCosto.value,
        margen_aplicado:               margenDefault,
        categoria_id:                  categoriaId.value || null,
        descripcion_corta:             descripcionCorta.value || null,
        descripcion_larga:             descripcionLarga.value || null,
        descripcion_cotizacion:        descripcionCotizacion.value || null,
        utilidad_minima_empresa_pct:   utilidadMinEmpresa.value,
        // Las comisiones y los descuentos por canal viajan dentro de `canales`. Las
        // columnas antiguas las escribe el espejo del servidor justo después de guardar,
        // así que mandarlas aquí sería mandar el dato dos veces y en dos formatos.
    }

    const onError = (errors) => {
        guardando.value = false
        errorMsg.value = Object.values(errors)[0] ?? 'No se pudo guardar. Revisa los datos.'
    }

    if (esEdicion.value) {
        router.put(`/ensambles/${props.ensamble.id}`, payload, { onError })

        return
    }

    // Con imágenes el envío pasa a multipart, y ahí todo viaja como texto: un `true`
    // llegaría como `'1'`. Las tres estructuras van como JSON y el servidor las
    // desempaca — así `variables` se guarda con sus tipos y la pantalla de editar la
    // vuelve a leer bien.
    if (imagenesNuevas.value.length) {
        router.post('/ensambles', {
            ...payload,
            variables: JSON.stringify(payload.variables),
            lineas:    JSON.stringify(payload.lineas),
            canales:   JSON.stringify(payload.canales),
            imagenes:  imagenesNuevas.value.map(i => i.file),
        }, { forceFormData: true, onError })

        return
    }

    router.post('/ensambles', payload, { onError })
}

const formatCOP = (v) => new Intl.NumberFormat('es-CO', { maximumFractionDigits: 0 }).format(v ?? 0)

// Un ensamble con plantilla necesita el cálculo hecho; uno directo, su receta escrita.
const puedeGuardar = computed(() => (esDirecto.value ? lineas.value.length > 0 : calculado.value))

onMounted(() => {
    setOriginal({ plantillaId: plantillaId.value, nombre: nombre.value, variables: { ...variables } })
    // En edición, inicializar márgenes desde config_salida de la plantilla actual
    if (props.ensamble && plantillaSeleccionada.value?.config_salida) {
        const conf = plantillaSeleccionada.value.config_salida
        margenesActuales.value = {
            mayorista:     conf.margen_mayorista    ?? 30,
            distribuidor:  conf.margen_distribuidor  ?? 32.5,
            cliente_final: conf.margen_cliente_final ?? 35,
            por_defecto:   conf.precio_defecto_cotizar ?? 'distribuidor',
        }
    }
})
</script>

<template>
    <AppLayout :title="esEdicion ? 'Editar Ensamble' : 'Nuevo Ensamble'">
        <div class="max-w-3xl mx-auto">

            <!-- Badge cambios sin guardar -->
            <div v-if="hasChanges"
                class="mb-3 inline-flex items-center gap-2 px-3 py-1.5 rounded-xl text-xs font-semibold text-aviso-naranja"
                style="background:var(--pastel-ambar); border:1px solid #F59E0B;">
                ● Cambios sin guardar
            </div>

            <!-- Copia de otro ensamble -->
            <div v-if="origen" class="mb-4 rounded-2xl p-4 flex items-start gap-3"
                style="background:var(--pastel-azul); border:1px solid var(--marca);">
                <svg class="w-5 h-5 shrink-0 mt-0.5" style="color:var(--marca);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                </svg>
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-tinta-900">
                        Copia de <a :href="`/ensambles/${origen.id}`" class="underline">{{ origen.nombre }}</a>
                    </p>
                    <p class="text-xs text-tinta-500 mt-1">
                        Se copiaron la receta, las descripciones y los precios por canal. No se copian
                        las imágenes. Revisa el nombre antes de guardar: el original sigue igual hasta
                        que guardes este.
                    </p>
                </div>
            </div>

            <!-- Error -->
            <div v-if="errorMsg" class="mb-4 bg-pastel-rojo border border-borde-aviso-rojo rounded-xl px-4 py-3 text-sm text-aviso-rojo flex items-center justify-between">
                {{ errorMsg }}
                <button @click="errorMsg = ''" class="text-red-400 ml-3">✕</button>
            </div>

            <!-- ── 1. Cómo se arma, y nombre ───────────────────────────────── -->
            <div class="bg-superficie rounded-2xl shadow-sm p-5 mb-4">
                <h2 class="text-sm font-semibold text-tinta-700 uppercase tracking-[0.12em] mb-4">1. Cómo se arma</h2>

                <div class="space-y-4">

                    <!-- El modo. En edición no se cambia: sería reescribir la receta entera. -->
                    <div v-if="!esEdicion" class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <button type="button" @click="tipoArmado = 'plantilla'"
                            class="text-left p-3 rounded-xl border-2 transition-all"
                            :class="!esDirecto ? 'border-[var(--marca)] bg-[var(--marca-suave)]' : 'border-linea hover:border-tinta-200'">
                            <p class="text-sm font-semibold" :class="!esDirecto ? 'text-[var(--marca)]' : 'text-tinta-700'">Con plantilla</p>
                            <p class="text-xs text-tinta-400 mt-0.5">
                                Se escriben las medidas y las fórmulas calculan los materiales. Para fabricar por medida.
                            </p>
                        </button>
                        <button type="button" @click="tipoArmado = 'directo'"
                            class="text-left p-3 rounded-xl border-2 transition-all"
                            :class="esDirecto ? 'border-[var(--marca)] bg-[var(--marca-suave)]' : 'border-linea hover:border-tinta-200'">
                            <p class="text-sm font-semibold" :class="esDirecto ? 'text-[var(--marca)]' : 'text-tinta-700'">Directo, sin cálculos</p>
                            <p class="text-xs text-tinta-400 mt-0.5">
                                La lista de componentes con cantidades exactas, escrita a mano. Para lo que siempre lleva lo mismo.
                            </p>
                        </button>
                    </div>

                    <p v-else class="text-xs text-tinta-400">
                        {{ esDirecto ? 'Ensamble directo: la receta se escribe a mano.' : 'Ensamble con plantilla: los materiales salen de las fórmulas.' }}
                    </p>

                    <div v-if="!esDirecto">
                        <label class="block text-sm font-medium text-tinta-700 mb-1.5">Plantilla <span class="text-aviso-rojo">*</span></label>
                        <select v-model="plantillaId" :disabled="esEdicion"
                            class="w-full border border-linea rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-[var(--marca)] disabled:bg-tinta-50">
                            <option value="">— Seleccionar plantilla —</option>
                            <option v-for="p in plantillas" :key="p.id" :value="p.id">{{ p.nombre }}</option>
                        </select>
                    </div>

                    <div v-if="plantillaSeleccionada || esDirecto">
                        <label class="block text-sm font-medium text-tinta-700 mb-1.5">Nombre del ensamble <span class="text-aviso-rojo">*</span></label>
                        <input v-model="nombre" type="text"
                            class="w-full border border-linea rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-[var(--marca)]"
                            placeholder="Nombre descriptivo..."
                            @input="onNombreInput" />
                        <p v-if="!esDirecto" class="text-xs text-tinta-300 mt-1">Se genera automáticamente desde las variables.</p>
                    </div>

                    <!-- Referencia y unidad. Un ensamble era la única línea sin código en
                         una cotización o una orden de producción, y todo se cotizaba «por
                         unidad» aunque el fabricante venda metros o juegos de dos. -->
                    <div v-if="plantillaSeleccionada || esDirecto" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-tinta-700 mb-1.5">Referencia</label>
                            <input v-model="referencia" type="text"
                                class="w-full border border-linea rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-[var(--marca)]"
                                :placeholder="esEdicion ? '' : 'Se genera sola (ENS-0001)'" />
                            <p class="text-xs text-tinta-300 mt-1">Déjala en blanco y el sistema la asigna.</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-tinta-700 mb-1.5">Unidad de medida</label>
                            <SelectorUnidad v-model="unidadMedida" tipo="producto"
                                clase="w-full border border-linea rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-[var(--marca)]" />
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── 2. Información de catálogo ─────────────────────────────────── -->
            <div class="bg-superficie rounded-2xl shadow-sm p-5 mb-4">
                <h2 class="text-sm font-semibold text-tinta-700 uppercase tracking-[0.12em] mb-4">2. Información de catálogo</h2>

                <!-- Categoría + activo -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                    

                    <div>
                        <label class="block text-sm font-medium text-tinta-700 mb-1.5">Categoría</label>
                        <div class="flex gap-2">
                            <select v-model="categoriaId"
                                class="flex-1 border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]">
                                <option value="">— Sin categoría —</option>
                                <option v-for="c in listaCats" :key="c.id" :value="c.id">{{ c.nombre }}</option>
                            </select>
                            <button @click="showModalCat = true" type="button"
                                class="px-3 py-2 rounded-xl border border-linea text-xs text-tinta-500 hover:bg-tinta-50 shrink-0">
                                + Nueva
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Descripción corta -->
                <div class="mb-4">
                    <div class="flex items-center justify-between mb-1">
                        <label class="text-sm font-medium text-tinta-700">Descripción corta</label>
                        <div class="flex items-center gap-3">
                            <!-- Al ensamble la IA le puede leer las medidas y los componentes ya
                                 calculados: son datos técnicos de verdad y no hay que escribirlos. -->
                            <GeneradorFichaIa :datos="datosParaFicha" :ensamble-id="ensamble?.id ?? null"
                                @usar="aplicarFicha" />
                            <span class="text-xs" :class="(descripcionCorta ?? '').length > 900 ? 'text-aviso-ambar font-semibold' : 'text-tinta-300'">
                                {{ (descripcionCorta ?? '').length }}/1000
                            </span>
                        </div>
                    </div>
                    <textarea v-model="descripcionCorta" rows="2" maxlength="1000"
                        class="w-full border rounded-xl px-3 py-2 text-sm focus:outline-none resize-none"
                        :class="page.props.errors?.descripcion_corta ? 'border-red-400 focus:border-red-400' : 'border-linea focus:border-[var(--marca)]'"
                        placeholder="Descripción breve para el catálogo..." />
                    <p v-if="page.props.errors?.descripcion_corta" class="mt-1 text-xs text-aviso-rojo">
                        {{ page.props.errors.descripcion_corta }}
                    </p>
                </div>

                <!-- Resumen técnico: va entre la comercial y la ficha, porque es lo que
                     se imprime en la cotización y en la orden de producción. -->
                <div class="mb-4">
                    <div class="flex items-center justify-between mb-1">
                        <label class="text-sm font-medium text-tinta-700">Resumen técnico para cotizaciones</label>
                        <span class="text-xs" :class="(descripcionCotizacion ?? '').length > 500 ? 'text-aviso-ambar font-semibold' : 'text-tinta-300'">
                            {{ (descripcionCotizacion ?? '').length }}/600
                        </span>
                    </div>
                    <textarea v-model="descripcionCotizacion" rows="2" maxlength="600"
                        class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none resize-none focus:border-[var(--marca)]"
                        placeholder="2400 x 2600 mm · lámina galvanizada cal. 22 · motor 1.5 kW 220V · rango -25 °C a 40 °C" />
                    <p class="text-xs text-tinta-300 mt-1">
                        Es lo que se imprime debajo del ítem en las cotizaciones y en las órdenes de
                        producción. Solo datos, sin lenguaje comercial: la ficha completa se queda en
                        la descripción larga.
                    </p>
                </div>

                <!-- Descripción larga -->
                <div class="mb-4">
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="text-sm font-medium text-tinta-700">Descripción larga</label>
                        <span class="text-xs" :class="(descripcionLarga ?? '').replace(/<[^>]*>/g, '').length > 9000 ? 'text-aviso-ambar font-semibold' : 'text-tinta-300'">
                            {{ (descripcionLarga ?? '').replace(/<[^>]*>/g, '').length }}/10000
                        </span>
                    </div>
                    <EditorTexto v-model="descripcionLarga" placeholder="Descripción detallada del ensamble..." :maxLength="10000" />
                </div>

                <!-- Imágenes (solo en edición) -->
                <template v-if="esEdicion">
                    <div class="border-t border-linea pt-4">
                        <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-3">Imágenes</p>

                        <!-- Imagen principal -->
                        <div class="mb-4">
                            <label class="block text-xs font-medium text-tinta-500 mb-2">Imagen principal</label>
                            <div class="flex items-start gap-4">
                                <div class="relative w-24 h-24 rounded-xl overflow-hidden border border-linea bg-tinta-50 flex items-center justify-center shrink-0">
                                    <img v-if="imagenPrincipalUrl" :src="imagenPrincipalUrl" class="w-full h-full object-cover" />
                                    <svg v-else class="w-8 h-8 text-tinta-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                    </svg>
                                    <button v-if="imagenPrincipalUrl" @click="quitarImagenPrincipal"
                                        class="absolute top-1 right-1 w-5 h-5 bg-red-500 text-white rounded-full text-xs flex items-center justify-center leading-none">✕</button>
                                </div>
                                <div>
                                    <label class="cursor-pointer">
                                        <input type="file" accept="image/*" class="hidden" :disabled="imgSubiendo" @change="subirImagenPrincipal" />
                                        <span class="inline-block px-3 py-2 rounded-xl border border-linea text-xs text-tinta-500 hover:bg-tinta-50 cursor-pointer">
                                            {{ imgSubiendo ? 'Subiendo...' : (imagenPrincipalUrl ? 'Cambiar imagen' : 'Subir imagen') }}
                                        </span>
                                    </label>
                                    <p class="text-xs text-tinta-300 mt-1">JPG, PNG, WebP · máx 5 MB</p>
                                    <p class="text-xs text-tinta-300">
                                        Recomendado: <strong>1000 × 1000 px</strong> (cuadrada). Se muestra
                                        recortada al centro en los listados y en el catálogo.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Imágenes secundarias -->
                        <div>
                            <label class="block text-xs font-medium text-tinta-500 mb-1">Imágenes secundarias</label>
                            <p class="text-xs text-tinta-300 mb-2">Mismo formato: <strong>1000 × 1000 px</strong> (cuadradas), máx 5 MB.</p>
                            <div class="flex flex-wrap gap-2">
                                <div v-for="ruta in imagenesSecundarias" :key="ruta"
                                    class="relative w-20 h-20 rounded-xl overflow-hidden border border-linea bg-tinta-50">
                                    <img :src="urlImagen(ruta)" class="w-full h-full object-cover" />
                                    <button @click="quitarImagenSecundaria(ruta)"
                                        class="absolute top-0.5 right-0.5 w-4 h-4 bg-red-500 text-white rounded-full text-xs flex items-center justify-center leading-none">✕</button>
                                </div>
                                <label class="w-20 h-20 rounded-xl border-2 border-dashed border-linea flex items-center justify-center cursor-pointer hover:border-borde-aviso-azul text-tinta-200 hover:text-blue-400 transition-colors">
                                    <input type="file" accept="image/*" class="hidden" @change="subirImagenSecundaria" />
                                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                                    </svg>
                                </label>
                            </div>
                        </div>
                    </div>
                </template>
                <!-- Imágenes al crear, igual que en productos. Antes aquí solo había un
                     aviso de que se subían después de guardar: había que guardar, volver a
                     entrar a editar y subirlas. Las imágenes de un ensamble se guardan
                     contra su id, así que viajan con el formulario y se guardan al final. -->
                <div v-else class="border-t border-linea pt-4">
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1">Imágenes</p>
                    <p class="text-xs text-tinta-300 mb-3">La primera es la principal. Se pueden cambiar después.</p>

                    <div class="flex flex-wrap gap-3">
                        <div v-for="(img, i) in imagenesNuevas" :key="i"
                            class="relative w-24 h-24 rounded-xl overflow-hidden border border-linea shrink-0">
                            <img :src="img.url" class="w-full h-full object-cover" />
                            <span v-if="i === 0"
                                class="absolute bottom-0 inset-x-0 text-[10px] text-white text-center py-0.5"
                                style="background:var(--marca);">Principal</span>
                            <button type="button" @click="quitarImagenNueva(i)"
                                class="absolute top-1 right-1 w-5 h-5 bg-red-500 text-white rounded-full text-xs flex items-center justify-center leading-none">✕</button>
                        </div>

                        <label class="w-24 h-24 rounded-xl border border-dashed border-linea flex flex-col items-center justify-center gap-1 cursor-pointer hover:bg-tinta-50 shrink-0">
                            <svg class="w-6 h-6 text-tinta-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                            </svg>
                            <span class="text-[10px] text-tinta-400">Agregar</span>
                            <input type="file" accept="image/*" multiple class="hidden" @change="elegirImagenes" />
                        </label>
                    </div>
                </div>
            </div>

            <!-- ── 3. Componentes del ensamble directo ─────────────────────── -->
            <div v-if="esDirecto" class="bg-superficie rounded-2xl shadow-sm p-5 mb-4">
                <h2 class="text-sm font-semibold text-tinta-700 uppercase tracking-[0.12em] mb-1">3. Componentes</h2>
                <p class="text-xs text-tinta-400 mb-4">
                    Cantidades exactas, sin fórmulas. Los materiales del inventario se descuentan al
                    despachar; los conceptos libres solo suman al costo. El cliente no ve esta lista.
                </p>

                <LineasEnsambleDirecto :lineas="lineas" />
            </div>

            <!-- ── 3. Configuración ────────────────────────────────────────── -->
            <div v-if="plantillaSeleccionada && !esDirecto" class="bg-superficie rounded-2xl shadow-sm p-5 mb-4">
                <h2 class="text-sm font-semibold text-tinta-700 uppercase tracking-[0.12em] mb-4">3. Configuración</h2>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <template v-for="campo in (plantillaSeleccionada.campos ?? []).filter(c => c.tipo_campo !== 'calculado')" :key="campo.id">
                        <!-- variable_instancia: renderizar con CampoInstancia según subtipo_variable -->
                        <div v-if="campo.tipo_campo === 'variable_instancia'">
                            <label class="block text-sm font-medium text-tinta-700 mb-1.5">
                                {{ campo.etiqueta || campo.nombre }}
                            </label>
                            <CampoInstancia :campo="campo" v-model="variables[campo.nombre]" />
                        </div>
                        <!-- entrada: renderizar según tipo -->
                        <div v-else :class="campo.tipo === 'checkbox' || campo.tipo === 'boolean' ? 'flex items-center gap-3' : ''">
                            <label :class="['text-sm font-medium text-tinta-700', campo.tipo !== 'checkbox' && campo.tipo !== 'boolean' ? 'block mb-1.5' : '']">
                                {{ campo.etiqueta }}
                                <span v-if="campo.requerido && campo.tipo !== 'checkbox'" class="text-aviso-rojo">*</span>
                            </label>
                            <select v-if="campo.tipo === 'select'" v-model="variables[campo.nombre]"
                                class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]">
                                <option v-for="op in campo.opciones ?? []" :key="op.valor" :value="op.valor">{{ op.etiqueta }}</option>
                            </select>
                            <input v-else-if="campo.tipo === 'decimal' || campo.tipo === 'numero'"
                                v-model.number="variables[campo.nombre]"
                                type="number" step="0.01"
                                :placeholder="campo.placeholder"
                                class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]" />
                            <input v-else-if="campo.tipo === 'boolean' || campo.tipo === 'checkbox'"
                                v-model="variables[campo.nombre]"
                                type="checkbox"
                                class="w-4 h-4 rounded text-aviso-azul" />
                            <input v-else
                                v-model="variables[campo.nombre]"
                                type="text"
                                :placeholder="campo.placeholder"
                                class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]" />
                            <p v-if="campo.ayuda && campo.tipo !== 'checkbox' && campo.tipo !== 'boolean'"
                                class="text-xs text-tinta-300 mt-1">{{ campo.ayuda }}</p>
                        </div>
                    </template>
                </div>

                <div class="mt-5">
                    <button @click="calcular" :disabled="calculando"
                        class="w-full py-2.5 rounded-xl text-sm text-white font-semibold disabled:opacity-60 flex items-center justify-center gap-2"
                        style="background:var(--marca);">
                        <svg v-if="calculando" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                        </svg>
                        {{ calculando ? 'Calculando...' : 'Calcular componentes →' }}
                    </button>
                </div>
            </div>

            <!-- ── 4. Desglose calculado ───────────────────────────────────── -->
            <div v-if="calculado && !esDirecto" class="bg-superficie rounded-2xl shadow-sm p-5 mb-4">
                <h2 class="text-sm font-semibold text-tinta-700 uppercase tracking-[0.12em] mb-4">4. Desglose calculado</h2>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs text-tinta-300 border-b border-linea">
                                <th class="pb-2 font-medium">Componente</th>
                                <th class="pb-2 font-medium text-right">Cant.</th>
                                <th class="pb-2 font-medium text-right">Unidad</th>
                                <th class="pb-2 font-medium text-right">P. Unit.</th>
                                <th class="pb-2 font-medium text-right">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-separador">
                            <tr v-for="(c, i) in componentes" :key="i" class="hover:bg-tinta-50">
                                <td class="py-2 font-medium text-tinta-700">{{ c.nombre }}</td>
                                <td class="py-2 text-right font-mono text-tinta-500">{{ c.cantidad }}</td>
                                <td class="py-2 text-right text-tinta-400">{{ c.unidad }}</td>
                                <td class="py-2 text-right text-tinta-400">${{ formatCOP(c.precio_unit) }}</td>
                                <td class="py-2 text-right font-semibold text-tinta-900">${{ formatCOP(c.subtotal) }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-linea">
                                <td colspan="4" class="pt-3 text-xs font-semibold text-tinta-500 uppercase">Precio costo total</td>
                                <td class="pt-3 text-right font-semibold text-tinta-900">${{ formatCOP(totalCosto) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

            </div>

            <!-- ═══ Precios y comisiones por canal ════════════════════ -->
            <!-- El mismo componente que usan crear y editar producto. Antes aquí había tres
                 cajas fijas —mayorista, distribuidor, cliente final— que escribían solo las
                 columnas antiguas: una empresa con cuatro canales no tenía dónde poner el
                 cuarto, y la cotización lee de las filas por canal. -->
            <div class="space-y-4 mb-4">
                <PreciosPorCanal
                    :canales="canales"
                    :precio-costo="totalCosto"
                    :costo-editable="false"
                />
            </div>

            <!-- Botones -->
            <div class="flex gap-3">
                <button @click="router.visit('/ensambles')"
                    class="flex-1 py-3 rounded-xl border border-linea text-sm font-medium text-tinta-500 hover:bg-tinta-50 transition-colors">
                    Cancelar
                </button>
                <button @click="guardar" :disabled="guardando || !puedeGuardar"
                    class="flex-1 py-3 rounded-xl text-sm font-semibold text-white disabled:opacity-60 transition-colors"
                    style="background:var(--marca);">
                    {{ guardando ? 'Guardando...' : (esEdicion ? 'Actualizar ensamble' : 'Guardar ensamble') }}
                </button>
            </div>

        </div>

        <!-- Modal nueva categoría -->
        <Teleport to="body">
            <div v-if="showModalCat" class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background:rgba(0,0,0,0.5);">
                <div class="bg-superficie rounded-2xl shadow-xl w-full max-w-sm p-5">
                    <h3 class="text-sm font-semibold text-tinta-900 mb-4">Nueva categoría</h3>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-xs font-medium text-tinta-500 mb-1">Nombre *</label>
                            <input v-model="nuevaCat.nombre" type="text" @keyup.enter="crearCategoria"
                                class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]"
                                placeholder="Nombre de la categoría" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-tinta-500 mb-1">Color</label>
                            <input v-model="nuevaCat.color" type="color"
                                class="w-12 h-8 rounded-lg border border-linea cursor-pointer p-0.5" />
                        </div>
                    </div>
                    <div class="flex gap-3 mt-4">
                        <button @click="showModalCat = false" class="flex-1 py-2.5 rounded-xl border border-linea text-sm text-tinta-500">Cancelar</button>
                        <button @click="crearCategoria" :disabled="guardandoCat || !nuevaCat.nombre"
                            class="flex-1 py-2.5 rounded-xl text-white text-sm font-semibold disabled:opacity-60"
                            style="background:var(--marca);">
                            {{ guardandoCat ? 'Creando...' : 'Crear' }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
