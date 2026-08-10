<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useUnsavedChanges } from '@/composables/useUnsavedChanges'
import CampoInstancia from '@/Components/CampoInstancia.vue'
import EditorTexto from '@/Components/EditorTexto.vue'
import { colorMarca } from '@/marca'

const props = defineProps({
    plantillas: { type: Array, default: () => [] },
    categorias: { type: Array, default: () => [] },
    ensamble:   { type: Object, default: null },
})

const esEdicion = computed(() => !!props.ensamble)
const { hasChanges, setOriginal, checkChanges, markClean } = useUnsavedChanges()

// ── Estado principal ──────────────────────────────────────────────────────────
const plantillaId              = ref(props.ensamble?.plantilla_id ?? '')
const nombre                   = ref(props.ensamble?.nombre ?? '')
const margenesActuales         = ref({ mayorista: 30, distribuidor: 32.5, cliente_final: 35, por_defecto: 'distribuidor' })
const variables                = reactive({})
const componentes              = ref(props.ensamble?.componentes_resultado ?? [])
const totalCosto               = ref(props.ensamble?.precio_costo ?? 0)
const precioMayor              = ref(props.ensamble?.precio_mayorista ?? 0)
const precioDist               = ref(props.ensamble?.precio_distribuidor ?? 0)
const precioFinal              = ref(props.ensamble?.precio_cliente_final ?? 0)
const page                     = usePage()
const calculado                = ref(!!props.ensamble)
const calculando               = ref(false)
const guardando                = ref(false)
const errorMsg                 = ref('')
const nombreEditadoManualmente = ref(!!props.ensamble)

// ── Estado de catálogo ────────────────────────────────────────────────────────
const categoriaId     = ref(props.ensamble?.categoria_id ?? '')
const descripcionCorta = ref(props.ensamble?.descripcion_corta ?? '')
const descripcionLarga = ref(props.ensamble?.descripcion_larga ?? '')
const listaCats        = ref([...(props.categorias ?? [])])

// Comisiones
const comisionMin             = ref(props.ensamble?.comision_pct_minima ?? 0)
const comisionMax             = ref(props.ensamble?.comision_pct_maxima ?? 0)
const utilidadMinEmpresa      = ref(props.ensamble?.utilidad_minima_empresa_pct ?? 15)
const descuentoCliente        = ref(props.ensamble?.descuento_max_cliente_final ?? 3)
const descuentoDistribuidor   = ref(props.ensamble?.descuento_max_distribuidor ?? 5)
const descuentoMayorista      = ref(props.ensamble?.descuento_max_mayorista ?? 8)
const comisionMinDistribuidor = ref(props.ensamble?.comision_min_distribuidor ?? 0)
const comisionMaxDistribuidor = ref(props.ensamble?.comision_max_distribuidor ?? 0)
const comisionMinClienteFinal = ref(props.ensamble?.comision_min_cliente_final ?? 0)
const comisionMaxClienteFinal = ref(props.ensamble?.comision_max_cliente_final ?? 0)

const errorComisionClienteFinal = ref(false)

// La comisión se paga sobre el excedente por encima del precio mayorista
// (utilidad garantizada de la empresa), no sobre el precio de venta
// completo — mismo criterio que en Cotizaciones/Create.vue.
const excedenteDistribuidor  = computed(() => Math.max(0, (precioDist.value  || 0) - (precioMayor.value || 0)))
const excedenteClienteFinal  = computed(() => Math.max(0, (precioFinal.value || 0) - (precioMayor.value || 0)))

const descuentoMaxRealDistribuidor = computed(() => {
    const base = precioDist.value || 0
    const min  = precioMayor.value || 0
    if (!base) return 0
    return Math.max(0, parseFloat(((base - min) / base * 100).toFixed(2)))
})

const descuentoMaxRealClienteFinal = computed(() => {
    const base = precioFinal.value || 0
    const min  = precioDist.value || 0
    if (!base) return 0
    return Math.max(0, parseFloat(((base - min) / base * 100).toFixed(2)))
})

const validarComisiones = () => {
    errorComisionClienteFinal.value =
        comisionMinClienteFinal.value > 0 &&
        comisionMaxDistribuidor.value > 0 &&
        comisionMinClienteFinal.value < comisionMaxDistribuidor.value
}

const sugerirComisionesEnsamble = () => {
    if (!precioMayor.value || !precioDist.value || !precioFinal.value) {
        alert('Calcula primero los precios antes de sugerir comisiones')
        return
    }
    const pctDisponibleDistrib = ((precioDist.value - precioMayor.value) / precioDist.value) * 100
    comisionMinDistribuidor.value = parseFloat((pctDisponibleDistrib * 0.40).toFixed(2))
    comisionMaxDistribuidor.value = parseFloat((pctDisponibleDistrib * 0.65).toFixed(2))
    const pctDisponibleFinal = ((precioFinal.value - precioMayor.value) / precioFinal.value) * 100
    comisionMinClienteFinal.value = comisionMaxDistribuidor.value
    comisionMaxClienteFinal.value = parseFloat((pctDisponibleFinal * 0.80).toFixed(2))
    if (comisionMaxClienteFinal.value <= comisionMinClienteFinal.value) {
        comisionMaxClienteFinal.value = parseFloat((comisionMinClienteFinal.value * 1.5).toFixed(2))
    }
    validarComisiones()
}

watch(comisionMaxDistribuidor, () => {
    if (comisionMinClienteFinal.value < comisionMaxDistribuidor.value) {
        comisionMinClienteFinal.value = comisionMaxDistribuidor.value
    }
    validarComisiones()
})

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

if (props.ensamble?.variables) {
    Object.assign(variables, props.ensamble.variables)
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
        calculado.value   = true
    } catch {
        errorMsg.value = 'Error al calcular los componentes.'
    } finally {
        calculando.value = false
    }
}

// ── Guardar ───────────────────────────────────────────────────────────────────
async function guardar() {
    if (!plantillaId.value || !nombre.value) {
        errorMsg.value = 'Selecciona una plantilla y asigna un nombre.'
        return
    }
    if (!calculado.value) {
        errorMsg.value = 'Primero calcula los componentes.'
        return
    }
    validarComisiones()
    if (errorComisionClienteFinal.value) {
        errorMsg.value = 'Corrige los rangos de comisión antes de guardar. La comisión mínima de cliente final debe ser ≥ comisión máxima de distribuidor.'
        return
    }
    guardando.value = true
    errorMsg.value  = ''
    markClean()

    const def = margenesActuales.value.por_defecto
    const margenDefault = def === 'mayorista'    ? margenesActuales.value.mayorista
        : def === 'cliente_final' ? margenesActuales.value.cliente_final
        : margenesActuales.value.distribuidor

    const payload = {
        plantilla_id:        plantillaId.value,
        nombre:              nombre.value,
        variables:           { ...variables },
        precio_costo:                  totalCosto.value,
        precio_mayorista:              precioMayor.value,
        precio_distribuidor:           precioDist.value,
        precio_cliente_final:          precioFinal.value,
        margen_aplicado:               margenDefault,
        categoria_id:                  categoriaId.value || null,
        descripcion_corta:             descripcionCorta.value || null,
        descripcion_larga:             descripcionLarga.value || null,
        comision_pct_minima:           comisionMin.value,
        comision_pct_maxima:           comisionMax.value,
        comision_min_distribuidor:     comisionMinDistribuidor.value,
        comision_max_distribuidor:     comisionMaxDistribuidor.value,
        comision_min_cliente_final:    comisionMinClienteFinal.value,
        comision_max_cliente_final:    comisionMaxClienteFinal.value,
        utilidad_minima_empresa_pct:   utilidadMinEmpresa.value,
        descuento_max_cliente_final:   descuentoMaxRealClienteFinal.value,
        descuento_max_distribuidor:    descuentoMaxRealDistribuidor.value,
        descuento_max_mayorista:       0,
    }

    const onError = (errors) => {
        guardando.value = false
        if (errors.descripcion_corta) errorMsg.value = errors.descripcion_corta
    }

    if (esEdicion.value) {
        router.put(`/ensambles/${props.ensamble.id}`, payload, { onError })
    } else {
        router.post('/ensambles', payload, { onError })
    }
}

const formatCOP = (v) => new Intl.NumberFormat('es-CO', { maximumFractionDigits: 0 }).format(v ?? 0)

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
                class="mb-3 inline-flex items-center gap-2 px-3 py-1.5 rounded-xl text-xs font-semibold text-orange-700"
                style="background:#FEF3C7; border:1px solid #F59E0B;">
                ● Cambios sin guardar
            </div>

            <!-- Error -->
            <div v-if="errorMsg" class="mb-4 bg-red-50 border border-red-200 rounded-xl px-4 py-3 text-sm text-red-700 flex items-center justify-between">
                {{ errorMsg }}
                <button @click="errorMsg = ''" class="text-red-400 ml-3">✕</button>
            </div>

            <!-- ── 1. Plantilla y nombre ────────────────────────────────────── -->
            <div class="bg-white rounded-2xl shadow-sm p-5 mb-4">
                <h2 class="text-sm font-semibold text-tinta-700 uppercase tracking-[0.12em] mb-4">1. Plantilla y nombre</h2>

                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-tinta-700 mb-1.5">Plantilla <span class="text-red-500">*</span></label>
                        <select v-model="plantillaId" :disabled="esEdicion"
                            class="w-full border border-linea rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-blue-400 disabled:bg-tinta-50">
                            <option value="">— Seleccionar plantilla —</option>
                            <option v-for="p in plantillas" :key="p.id" :value="p.id">{{ p.nombre }}</option>
                        </select>
                    </div>

                    <div v-if="plantillaSeleccionada">
                        <label class="block text-sm font-medium text-tinta-700 mb-1.5">Nombre del ensamble <span class="text-red-500">*</span></label>
                        <input v-model="nombre" type="text"
                            class="w-full border border-linea rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-blue-400"
                            placeholder="Nombre descriptivo..."
                            @input="onNombreInput" />
                        <p class="text-xs text-tinta-300 mt-1">Se genera automáticamente desde las variables.</p>
                    </div>
                </div>
            </div>

            <!-- ── 2. Información de catálogo ─────────────────────────────────── -->
            <div class="bg-white rounded-2xl shadow-sm p-5 mb-4">
                <h2 class="text-sm font-semibold text-tinta-700 uppercase tracking-[0.12em] mb-4">2. Información de catálogo</h2>

                <!-- Categoría + activo -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-tinta-700 mb-1.5">Categoría</label>
                        <div class="flex gap-2">
                            <select v-model="categoriaId"
                                class="flex-1 border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-blue-400">
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
                        <span class="text-xs" :class="(descripcionCorta ?? '').length > 900 ? 'text-amber-500 font-semibold' : 'text-tinta-300'">
                            {{ (descripcionCorta ?? '').length }}/1000
                        </span>
                    </div>
                    <textarea v-model="descripcionCorta" rows="2" maxlength="1000"
                        class="w-full border rounded-xl px-3 py-2 text-sm focus:outline-none resize-none"
                        :class="page.props.errors?.descripcion_corta ? 'border-red-400 focus:border-red-400' : 'border-linea focus:border-blue-400'"
                        placeholder="Descripción breve para el catálogo..." />
                    <p v-if="page.props.errors?.descripcion_corta" class="mt-1 text-xs text-red-600">
                        {{ page.props.errors.descripcion_corta }}
                    </p>
                </div>

                <!-- Descripción larga -->
                <div class="mb-4">
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="text-sm font-medium text-tinta-700">Descripción larga</label>
                        <span class="text-xs" :class="(descripcionLarga ?? '').replace(/<[^>]*>/g, '').length > 9000 ? 'text-amber-500 font-semibold' : 'text-tinta-300'">
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
                                <label class="w-20 h-20 rounded-xl border-2 border-dashed border-linea flex items-center justify-center cursor-pointer hover:border-blue-300 text-tinta-200 hover:text-blue-400 transition-colors">
                                    <input type="file" accept="image/*" class="hidden" @change="subirImagenSecundaria" />
                                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                                    </svg>
                                </label>
                            </div>
                        </div>
                    </div>
                </template>
                <div v-else class="border-t border-linea pt-3">
                    <p class="text-xs text-tinta-300">Las imágenes se pueden agregar después de guardar el ensamble.</p>
                </div>
            </div>

            <!-- ── 3. Configuración ────────────────────────────────────────── -->
            <div v-if="plantillaSeleccionada" class="bg-white rounded-2xl shadow-sm p-5 mb-4">
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
                                <span v-if="campo.requerido && campo.tipo !== 'checkbox'" class="text-red-500">*</span>
                            </label>
                            <select v-if="campo.tipo === 'select'" v-model="variables[campo.nombre]"
                                class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-blue-400">
                                <option v-for="op in campo.opciones ?? []" :key="op.valor" :value="op.valor">{{ op.etiqueta }}</option>
                            </select>
                            <input v-else-if="campo.tipo === 'decimal' || campo.tipo === 'numero'"
                                v-model.number="variables[campo.nombre]"
                                type="number" step="0.01"
                                :placeholder="campo.placeholder"
                                class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-blue-400" />
                            <input v-else-if="campo.tipo === 'boolean' || campo.tipo === 'checkbox'"
                                v-model="variables[campo.nombre]"
                                type="checkbox"
                                class="w-4 h-4 rounded text-blue-600" />
                            <input v-else
                                v-model="variables[campo.nombre]"
                                type="text"
                                :placeholder="campo.placeholder"
                                class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-blue-400" />
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
            <div v-if="calculado" class="bg-white rounded-2xl shadow-sm p-5 mb-4">
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
                        <tbody class="divide-y divide-gray-50">
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

                <!-- Precios por canal con márgenes de la plantilla -->
                <div class="mt-4 pt-4 border-t border-linea grid grid-cols-3 gap-3">
                    <div class="text-center p-3 rounded-xl transition-all"
                        :style="margenesActuales.por_defecto === 'mayorista'
                            ? 'background:#EFF6FF; border:2px solid #93C5FD;'
                            : 'background:#F0F9FF;'">
                        <p class="text-xs mb-1"
                           :style="margenesActuales.por_defecto === 'mayorista' ? 'color:#1D4ED8; font-weight:500;' : 'color:#6B7280;'">
                            Mayorista ({{ margenesActuales.mayorista }}%)
                        </p>
                        <p class="text-base font-semibold"
                           :style="margenesActuales.por_defecto === 'mayorista' ? 'color:#1D4ED8;' : 'color:#1F2937;'">
                            ${{ formatCOP(precioMayor) }}
                        </p>
                    </div>
                    <div class="text-center p-3 rounded-xl transition-all"
                        :style="margenesActuales.por_defecto === 'distribuidor' || !['mayorista','cliente_final'].includes(margenesActuales.por_defecto)
                            ? 'background:#EFF6FF; border:2px solid #93C5FD;'
                            : 'background:#F0F9FF;'">
                        <p class="text-xs mb-1"
                           :style="margenesActuales.por_defecto === 'distribuidor' || !['mayorista','cliente_final'].includes(margenesActuales.por_defecto) ? 'color:#1D4ED8; font-weight:500;' : 'color:#6B7280;'">
                            Distribuidor ({{ margenesActuales.distribuidor }}%)
                        </p>
                        <p class="text-base font-semibold"
                           :style="margenesActuales.por_defecto === 'distribuidor' || !['mayorista','cliente_final'].includes(margenesActuales.por_defecto) ? 'color:#1D4ED8;' : 'color:#1F2937;'">
                            ${{ formatCOP(precioDist) }}
                        </p>
                    </div>
                    <div class="text-center p-3 rounded-xl transition-all"
                        :style="margenesActuales.por_defecto === 'cliente_final'
                            ? 'background:#EFF6FF; border:2px solid #93C5FD;'
                            : 'background:#F0FDF4;'">
                        <p class="text-xs mb-1"
                           :style="margenesActuales.por_defecto === 'cliente_final' ? 'color:#1D4ED8; font-weight:500;' : 'color:#6B7280;'">
                            Cliente final ({{ margenesActuales.cliente_final }}%)
                        </p>
                        <p class="text-base font-semibold"
                           :style="margenesActuales.por_defecto === 'cliente_final' ? 'color:#1D4ED8;' : 'color:#1F2937;'">
                            ${{ formatCOP(precioFinal) }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- Comisión Vendedor por Canal (solo cuando está calculado) -->
            <div v-if="calculado" class="bg-white rounded-2xl shadow-sm overflow-hidden">
                <div class="px-5 py-3 border-b border-linea flex items-center justify-between">
                    <h3 class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Comisión Vendedor por Canal</h3>
                    <button type="button" @click="sugerirComisionesEnsamble"
                        class="text-xs text-[var(--marca)] border border-[var(--marca)] rounded-lg px-3 py-1.5 hover:bg-blue-50 transition-colors flex items-center gap-1.5">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
                        </svg>
                        ▷ Sugerir comisiones
                    </button>
                </div>
                <div class="p-5 space-y-4">

                    <!-- Mayorista — sin comisión -->
                    <div class="bg-blue-50 border border-blue-100 rounded-lg p-3">
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-semibold text-blue-700 uppercase">Mayorista</span>
                            <span class="bg-blue-100 text-blue-600 text-xs px-2 py-0.5 rounded-full">Sin comisión · Precio fijo</span>
                        </div>
                        <p class="text-xs text-blue-400 mt-1">El precio mayorista (${{ formatCOP(precioMayor) }}) es la utilidad mínima garantizada de la empresa. No hay comisión para el vendedor en este canal.</p>
                    </div>

                    <!-- Distribuidor -->
                    <div class="border border-indigo-100 rounded-lg p-3 space-y-3 bg-indigo-50/30">
                        <div class="flex items-center justify-between flex-wrap gap-1">
                            <span class="text-xs font-semibold text-indigo-700 uppercase">Distribuidor</span>
                            <span class="text-xs text-indigo-500">Base: {{ formatCOP(precioDist) }} · Mín: {{ formatCOP(precioMayor) }} · Desc. máx: {{ descuentoMaxRealDistribuidor }}%</span>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="text-xs text-tinta-400 mb-1 block">Comisión mínima (%)</label>
                                <div class="flex items-center gap-2">
                                    <input type="number" step="0.1" min="0" v-model.number="comisionMinDistribuidor" @input="validarComisiones"
                                        class="w-24 border border-tinta-200 rounded-lg px-2 py-1.5 text-sm focus:ring-2 focus:ring-indigo-300 focus:outline-none" />
                                    <span class="text-xs text-tinta-300">= {{ formatCOP(excedenteDistribuidor * comisionMinDistribuidor / 100) }}</span>
                                </div>
                            </div>
                            <div>
                                <label class="text-xs text-tinta-400 mb-1 block">Comisión máxima (%)</label>
                                <div class="flex items-center gap-2">
                                    <input type="number" step="0.1" min="0" v-model.number="comisionMaxDistribuidor" @input="validarComisiones"
                                        class="w-24 border border-tinta-200 rounded-lg px-2 py-1.5 text-sm focus:ring-2 focus:ring-indigo-300 focus:outline-none" />
                                    <span class="text-xs text-tinta-300">= {{ formatCOP(excedenteDistribuidor * comisionMaxDistribuidor / 100) }}</span>
                                </div>
                            </div>
                        </div>
                        <p v-if="comisionMinDistribuidor > 0 && comisionMaxDistribuidor > 0" class="text-xs text-indigo-600">
                            ✅ Vendedor gana entre {{ formatCOP(excedenteDistribuidor * comisionMinDistribuidor / 100) }} y {{ formatCOP(excedenteDistribuidor * comisionMaxDistribuidor / 100) }}
                        </p>
                    </div>

                    <!-- Cliente Final -->
                    <div class="border border-green-100 rounded-lg p-3 space-y-3 bg-green-50/30">
                        <div class="flex items-center justify-between flex-wrap gap-1">
                            <div class="flex items-center gap-2">
                                <span class="text-xs font-semibold text-green-700 uppercase">Cliente Final</span>
                                <span class="bg-green-100 text-green-600 text-xs px-2 py-0.5 rounded-full">⭐ Mayor incentivo</span>
                            </div>
                            <span class="text-xs text-green-500">Base: {{ formatCOP(precioFinal) }} · Desc. máx: {{ descuentoMaxRealClienteFinal }}%</span>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="text-xs text-tinta-400 mb-1 block">
                                    Comisión mínima (%)
                                    <span class="text-orange-500 ml-1">← mín = máx distribuidor ({{ comisionMaxDistribuidor }}%)</span>
                                </label>
                                <div class="flex items-center gap-2">
                                    <input type="number" step="0.1" :min="comisionMaxDistribuidor" v-model.number="comisionMinClienteFinal" @input="validarComisiones"
                                        :class="['w-24 border rounded-lg px-2 py-1.5 text-sm focus:ring-2 focus:outline-none',
                                            errorComisionClienteFinal ? 'border-red-400 focus:ring-red-300' : 'border-tinta-200 focus:ring-green-300']" />
                                    <span class="text-xs text-tinta-300">= {{ formatCOP(excedenteClienteFinal * comisionMinClienteFinal / 100) }}</span>
                                </div>
                            </div>
                            <div>
                                <label class="text-xs text-tinta-400 mb-1 block">Comisión máxima (%)</label>
                                <div class="flex items-center gap-2">
                                    <input type="number" step="0.1" :min="comisionMinClienteFinal" v-model.number="comisionMaxClienteFinal" @input="validarComisiones"
                                        :class="['w-24 border rounded-lg px-2 py-1.5 text-sm focus:ring-2 focus:outline-none',
                                            errorComisionClienteFinal ? 'border-red-400 focus:ring-red-300' : 'border-tinta-200 focus:ring-green-300']" />
                                    <span class="text-xs text-tinta-300">= {{ formatCOP(excedenteClienteFinal * comisionMaxClienteFinal / 100) }}</span>
                                </div>
                            </div>
                        </div>
                        <p v-if="errorComisionClienteFinal" class="text-xs text-red-600">
                            ⚠️ La comisión mínima de cliente final debe ser ≥ comisión máxima de distribuidor ({{ comisionMaxDistribuidor }}%)
                        </p>
                        <p v-else-if="comisionMinClienteFinal > 0 && comisionMaxClienteFinal > 0" class="text-xs text-green-600">
                            ✅ Vendedor gana entre {{ formatCOP(excedenteClienteFinal * comisionMinClienteFinal / 100) }} y {{ formatCOP(excedenteClienteFinal * comisionMaxClienteFinal / 100) }} (más que en distribuidor ✓)
                        </p>
                    </div>

                    <!-- Comparativa -->
                    <div v-if="comisionMaxDistribuidor > 0 && comisionMaxClienteFinal > 0" class="bg-tinta-50 rounded-lg p-3">
                        <p class="text-xs font-medium text-tinta-500 mb-2">📊 Comparativa de incentivos por canal</p>
                        <div class="space-y-1.5">
                            <div class="flex items-center gap-2">
                                <span class="text-xs text-tinta-400 w-28">Mayorista:</span>
                                <div class="flex-1 bg-tinta-200 rounded-full h-1.5"></div>
                                <span class="text-xs text-tinta-300 w-20 text-right">Sin comisión</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-xs text-indigo-600 w-28">Distribuidor:</span>
                                <div class="flex-1 bg-tinta-200 rounded-full h-1.5">
                                    <div class="bg-indigo-400 h-1.5 rounded-full" :style="`width: ${Math.min(comisionMaxDistribuidor * 5, 100)}%`"></div>
                                </div>
                                <span class="text-xs text-indigo-600 w-20 text-right font-medium">máx {{ comisionMaxDistribuidor }}%</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-xs text-green-600 w-28">Cliente final:</span>
                                <div class="flex-1 bg-tinta-200 rounded-full h-1.5">
                                    <div class="bg-green-500 h-1.5 rounded-full" :style="`width: ${Math.min(comisionMaxClienteFinal * 5, 100)}%`"></div>
                                </div>
                                <span class="text-xs text-green-600 w-20 text-right font-medium">máx {{ comisionMaxClienteFinal }}%</span>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <!-- Botones -->
            <div class="flex gap-3">
                <button @click="router.visit('/ensambles')"
                    class="flex-1 py-3 rounded-xl border border-linea text-sm font-medium text-tinta-500 hover:bg-tinta-50 transition-colors">
                    Cancelar
                </button>
                <button @click="guardar" :disabled="guardando || !calculado"
                    class="flex-1 py-3 rounded-xl text-sm font-semibold text-white disabled:opacity-60 transition-colors"
                    style="background:var(--marca);">
                    {{ guardando ? 'Guardando...' : (esEdicion ? 'Actualizar ensamble' : 'Guardar ensamble') }}
                </button>
            </div>

        </div>

        <!-- Modal nueva categoría -->
        <Teleport to="body">
            <div v-if="showModalCat" class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background:rgba(0,0,0,0.5);">
                <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm p-5">
                    <h3 class="text-sm font-semibold text-tinta-900 mb-4">Nueva categoría</h3>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-xs font-medium text-tinta-500 mb-1">Nombre *</label>
                            <input v-model="nuevaCat.nombre" type="text" @keyup.enter="crearCategoria"
                                class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-blue-400"
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
