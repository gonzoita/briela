<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import EditorTexto from '@/Components/EditorTexto.vue'
import SelectorUnidad from '@/Components/SelectorUnidad.vue'
import GeneradorFichaIa from '@/Components/GeneradorFichaIa.vue'
import PreciosPorCanal from '@/Components/PreciosPorCanal.vue'
import ProveedoresProducto from '@/Components/ProveedoresProducto.vue'
import { usePreciosPorCanal } from '@/composables/usePreciosPorCanal'
import { useForm, router } from '@inertiajs/vue3'
import { ref, computed, watch, reactive, onMounted } from 'vue'
import { useUnsavedChanges } from '@/composables/useUnsavedChanges'
import { colorMarca } from '@/marca'

const props = defineProps({
    tipo:        String,
    categorias:  Array,
    bodegas:     Array,
    proveedores: Array,
    // Los canales de precio configurados en Segmentación, ya con lo que el producto tenga
    // guardado o en cero si es nuevo.
    canales:     { type: Array, default: () => [] },
    // Al duplicar, los datos del producto que sirve de molde. Null cuando se crea de cero.
    base:        { type: Object, default: null },
    origen:      { type: Object, default: null },
})

const tipoSeleccionado = ref(props.tipo || '')

const tiposOpciones = [
    { value: 'producto', label: 'Producto',  desc: 'Ítem físico inventariable',     color: '#DBEAFE', textColor: '#1D4ED8',
      icon: `<path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7l8 4"/>` },
    { value: 'servicio', label: 'Servicio',  desc: 'Trabajo o instalación',          color: '#D1FAE5', textColor: '#065F46',
      icon: `<path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>` },
]

// Stock inicial por bodega
const stockInicial = ref({})
const initStockInicial = () => {
    stockInicial.value = {}
    ;(props.bodegas ?? []).forEach(b => { stockInicial.value[b.id] = '' })
}
initStockInicial()

// ── Variantes ─────────────────────────────────────────────────────────────────
const esPadre = ref(false)
const variantes = ref([])

const agregarVariante = () => {
    const stockPorBodega = {}
    ;(props.bodegas ?? []).forEach(b => { stockPorBodega[b.id] = '' })
    variantes.value.push({ valor_variante: '', referencia: '', stock_inicial: stockPorBodega })
}

const quitarVariante = (idx) => variantes.value.splice(idx, 1)

watch(esPadre, (v) => { if (v && variantes.value.length === 0) agregarVariante() })

const form = useForm({
    tipo:                tipoSeleccionado.value,
    categoria_id:        '',
    proveedor_id:        '',
    // Dentro de useForm a propósito: `data()` solo recorre las claves que existían al
    // crearlo, así que una agregada después nunca se enviaría.
    proveedores_precios: [],
    nombre:              '',
    referencia:          '',
    unidad_medida:       'unidad',
    descripcion_corta:   '',
    // El técnico corto, el que sale en cotizaciones y órdenes de producción.
    descripcion_cotizacion: '',
    descripcion_larga:   '',
    inventariable:       false,
    es_vendible:         false,
    es_insumo:           false,
    atributo_variante:   '',
    stock_minimo:        0,
    stock_maximo:        0,
    precio_costo:                0,
    margen_mayorista:            25,
    margen_distribuidor:         30,
    margen_cliente_final:        35,
    precio_mayorista:            0,
    precio_distribuidor:         0,
    precio_cliente_final:        0,
    comision_pct_minima:          0,
    comision_pct_maxima:          0,
    comision_min_distribuidor:    0,
    comision_max_distribuidor:    0,
    comision_min_cliente_final:   0,
    comision_max_cliente_final:   0,
    utilidad_minima_empresa_pct:  15,
    descuento_max_cliente_final:  3,
    descuento_max_distribuidor:   5,
    descuento_max_mayorista:      8,
    imagenes:                    [],
    // Declarado aquí y no asignado después a propósito: `form.data()` de Inertia recorre
    // las claves con las que nació el formulario, así que un campo agregado más tarde se
    // ve en pantalla, se edita, y **no se envía**. Con tres canales no se notaba —el
    // controlador reconstruía las filas desde las columnas viejas—, pero el cuarto canal
    // que la empresa creara se guardaba sin precio y la cotización salía vacía.
    canales:                     [],
})

watch(tipoSeleccionado, (v) => { form.tipo = v; form.unidad_medida = 'unidad' })

const calcPrecio = (costo, margenPct) => {
    if (!costo || margenPct >= 100) return 0
    return Math.ceil(costo / (1 - margenPct / 100) / 1000) * 1000
}

watch(
    [() => form.precio_costo, () => form.margen_mayorista, () => form.margen_distribuidor, () => form.margen_cliente_final],
    ([costo, mm, md, mcf]) => {
        form.precio_mayorista     = calcPrecio(costo, mm)
        form.precio_distribuidor  = calcPrecio(costo, md)
        form.precio_cliente_final = calcPrecio(costo, mcf)
    }
)

// ─── Canales de precio ────────────────────────────────────────────────────────
//
// Los canales los define la empresa en Segmentación. Antes eran tres cajas fijas aquí, y
// atender a un cliente con un cuarto canal pedía tocar código.
//
// El margen es lo que se escribe; el precio se calcula redondeando hacia arriba al millar,
// igual que antes. Se guardan los dos porque una cotización vieja tiene que poder mostrar
// el precio con el que se hizo.
form.canales = (props.canales ?? []).map(c => ({ ...c }))

// ─── Duplicar ─────────────────────────────────────────────────────────────────
//
// Llega el producto molde y se copia campo por campo sobre el formulario vacío. No se
// copian la referencia —es única y se genera sola—, ni el stock, ni las imágenes.
//
// Los selects guardan el id como texto (sus opciones son `String(c.id)`), así que un id
// numérico no preseleccionaría nada: se ve «Sin categoría» aunque haya llegado una.
if (props.base) {
    const molde = { ...props.base }
    const listaVariantes = molde.variantes ?? []

    delete molde.variantes
    delete molde.es_padre

    Object.entries(molde).forEach(([campo, valor]) => {
        if (! (campo in form.data())) return

        form[campo] = ['categoria_id', 'proveedor_id'].includes(campo)
            ? (valor ? String(valor) : '')
            : valor
    })

    esPadre.value = !! props.base.es_padre

    // Las variantes traen el valor —«Rojo», «120 cm»— pero no su referencia ni su stock:
    // son otras unidades físicas, no las mismas.
    variantes.value = listaVariantes.map(v => {
        const stockPorBodega = {}
        ;(props.bodegas ?? []).forEach(b => { stockPorBodega[b.id] = '' })

        return { valor_variante: v.valor_variante, referencia: '', stock_inicial: stockPorBodega }
    })
}

// Precios y comisiones por canal: la lógica vive en el composable, y la usan esta pantalla
// y la de editar. Tener dos copias fue lo que dejó a editar trabajando con tres cajas fijas
// mientras aquí ya se trabajaba por canales.
const { hayErrorDeEscalera, aplicarDescuentosMax } = usePreciosPorCanal(
    computed(() => form.canales),
    computed(() => Number(form.precio_costo) || 0),
)

const { hasChanges, setOriginal, checkChanges, markClean } = useUnsavedChanges()
onMounted(() => setOriginal(form.data()))
watch(() => form.data(), checkChanges, { deep: true })

const stockInicialTotal = computed(() =>
    Object.values(stockInicial.value).reduce((s, v) => s + (Number(v) || 0), 0)
)

// La lista de unidades ya no vive aquí: la administra la empresa y la trae
// `SelectorUnidad` desde /api/unidades-medida. Estaba escrita en este archivo y otra vez
// en el de editar, así que medir en rollos pedía un cambio de código.

// ── Ficha técnica con IA ──────────────────────────────────────────────────────
// Lo que ya está escrito en el formulario se le pasa a la IA; los datos técnicos en bruto
// los pega el usuario en el modal. Nada se guarda solo: llena los campos y ya.
const datosParaFicha = computed(() => ({
    tipo:              tipoSeleccionado.value || 'producto',
    nombre:            form.nombre,
    referencia:        form.referencia,
    categoria:         listaCats.value.find(c => String(c.id) === String(form.categoria_id))?.nombre ?? null,
    unidad:            form.unidad_medida,
    descripcion_corta: form.descripcion_corta,
}))

function aplicarFicha({ descripcion_corta, descripcion_cotizacion, descripcion_larga }) {
    if (descripcion_corta) form.descripcion_corta = descripcion_corta
    if (descripcion_cotizacion) form.descripcion_cotizacion = descripcion_cotizacion
    if (descripcion_larga) form.descripcion_larga = descripcion_larga
}

const formatCOP = (v) =>
    new Intl.NumberFormat('es-CO', { maximumFractionDigits: 0 }).format(Math.round(v ?? 0))

// ── Imágenes ──────────────────────────────────────────────────────────────────
const previews = ref([])

const onImagenes = (e) => {
    Array.from(e.target.files).forEach((f) => {
        const r = new FileReader()
        r.onload = (ev) => previews.value.push({ file: f, url: ev.target.result, principal: previews.value.length === 0 })
        r.readAsDataURL(f)
    })
    form.imagenes = [...(form.imagenes || []), ...Array.from(e.target.files)]
}

const eliminarPreview = (i) => {
    previews.value.splice(i, 1)
    const arr = Array.from(form.imagenes || []); arr.splice(i, 1); form.imagenes = arr
    if (previews.value.length && !previews.value.some(p => p.principal)) previews.value[0].principal = true
}

const marcarPrincipal = (i) => previews.value.forEach((p, idx) => { p.principal = idx === i })

// ── Modal nueva categoría ─────────────────────────────────────────────────────
const showModalCat = ref(false)
const listaCats = ref([...(props.categorias || [])])
const nuevaCat = ref({ nombre: '', color: colorMarca() })
const guardandoCat = ref(false)

const crearCategoria = async () => {
    guardandoCat.value = true
    try {
        const res = await fetch('/api/categorias-producto', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-XSRF-TOKEN': (()=>{const c=document.cookie.split('; ').find(r=>r.startsWith('XSRF-TOKEN='));return c?decodeURIComponent(c.split('=')[1]):''})() },
            body: JSON.stringify(nuevaCat.value),
        })
        const cat = await res.json()
        listaCats.value.push(cat)
        form.categoria_id = String(cat.id)
        showModalCat.value = false
        nuevaCat.value = { nombre: '', color: colorMarca() }
    } finally { guardandoCat.value = false }
}

// ── Comisiones por canal ──────────────────────────────────────────────────────
const errorComisionClienteFinal = ref(false)

// La comisión se paga sobre el excedente por encima del precio mayorista
// (utilidad garantizada de la empresa), no sobre el precio de venta
// completo — mismo criterio que en Cotizaciones/Create.vue.
const excedenteDistribuidor  = computed(() => Math.max(0, (form.precio_distribuidor  || 0) - (form.precio_mayorista || 0)))
const excedenteClienteFinal  = computed(() => Math.max(0, (form.precio_cliente_final || 0) - (form.precio_mayorista || 0)))

const descuentoMaxRealDistribuidor = computed(() => {
    const base = form.precio_distribuidor || 0
    const min  = form.precio_mayorista || 0
    if (!base) return 0
    return Math.max(0, parseFloat(((base - min) / base * 100).toFixed(2)))
})

const descuentoMaxRealClienteFinal = computed(() => {
    const base = form.precio_cliente_final || 0
    const min  = form.precio_distribuidor || 0
    if (!base) return 0
    return Math.max(0, parseFloat(((base - min) / base * 100).toFixed(2)))
})

const validarComisiones = () => {
    errorComisionClienteFinal.value =
        form.comision_min_cliente_final > 0 &&
        form.comision_max_distribuidor > 0 &&
        form.comision_min_cliente_final < form.comision_max_distribuidor
}

const sugerirComisiones = () => {
    const precioMay    = parseFloat(form.precio_mayorista) || 0
    const precioDistrib = parseFloat(form.precio_distribuidor) || 0
    const precioFinal  = parseFloat(form.precio_cliente_final) || 0
    if (!precioMay || !precioDistrib || !precioFinal) {
        alert('Calcula primero los precios antes de sugerir comisiones')
        return
    }
    const pctDisponibleDistrib = ((precioDistrib - precioMay) / precioDistrib) * 100
    form.comision_min_distribuidor = parseFloat((pctDisponibleDistrib * 0.40).toFixed(2))
    form.comision_max_distribuidor = parseFloat((pctDisponibleDistrib * 0.65).toFixed(2))
    const pctDisponibleFinal = ((precioFinal - precioMay) / precioFinal) * 100
    form.comision_min_cliente_final = form.comision_max_distribuidor
    form.comision_max_cliente_final = parseFloat((pctDisponibleFinal * 0.80).toFixed(2))
    if (form.comision_max_cliente_final <= form.comision_min_cliente_final) {
        form.comision_max_cliente_final = parseFloat((form.comision_min_cliente_final * 1.5).toFixed(2))
    }
    validarComisiones()
}

watch(() => form.comision_max_distribuidor, () => {
    if (form.comision_min_cliente_final < form.comision_max_distribuidor) {
        form.comision_min_cliente_final = form.comision_max_distribuidor
    }
    validarComisiones()
})

// ── Submit ────────────────────────────────────────────────────────────────────
const ic = (field) => [
    'w-full border rounded-xl px-3 py-2 text-sm focus:outline-none transition-colors',
    form.errors[field] ? 'border-red-400 bg-pastel-rojo' : 'border-linea bg-superficie focus:border-[var(--marca)]',
]

const puedeGuardar = computed(() => !esPadre.value || variantes.value.length > 0)

const submit = () => {
    // La escalera se valida sobre la lista de canales, no sobre dos nombres fijos.
    if (hayErrorDeEscalera.value) {
        alert('Corrige los rangos de comisión antes de guardar.\n\n'
            + 'La comisión mínima de cada canal debe ser mayor o igual a la máxima del canal '
            + 'anterior: mientras más lejos del canal base, más incentivo para el vendedor.')
        return
    }
    if (esPadre.value && variantes.value.length === 0) {
        alert('Agrega al menos una variante.')
        return
    }

    // El descuento máximo de cada canal sale de su distancia con el canal de abajo. El canal
    // base no descuenta: su precio es el piso.
    aplicarDescuentosMax()

    markClean()

    const buildStock = (obj) => {
        const data = {}
        Object.entries(obj ?? {}).forEach(([k, v]) => { if (Number(v) > 0) data[k] = Number(v) })
        return data
    }

    form.transform(data => ({
        ...data,
        es_padre:           esPadre.value,
        atributo_variante:  esPadre.value ? data.atributo_variante : null,
        stock_inicial:      esPadre.value ? {} : buildStock(stockInicial.value),
        variantes:          esPadre.value
            ? variantes.value.map(v => ({
                valor_variante: v.valor_variante,
                referencia:     v.referencia || null,
                stock_inicial:  buildStock(v.stock_inicial),
              }))
            : [],
    })).post('/productos', { forceFormData: true })
}

const badgeStyle = {
    producto: 'background:var(--pastel-azul-2);color:var(--texto-azul);',
    servicio: 'background:var(--pastel-verde);color:var(--texto-verde);',
}
</script>

<template>
    <AppLayout :title="origen ? 'Duplicar producto' : 'Nuevo producto'">
        <div class="max-w-4xl mx-auto">

            <!-- Al duplicar: de qué producto viene y qué NO se copió. Si no se dice, la
                 referencia nueva y el stock en cero se leen como un error del sistema. -->
            <div v-if="origen" class="mb-4 rounded-2xl p-4 flex items-start gap-3"
                style="background:var(--pastel-azul); border:1px solid var(--marca);">
                <svg class="w-5 h-5 shrink-0 mt-0.5" style="color:var(--marca);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                </svg>
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-tinta-900">
                        Copia de <a :href="`/productos/${origen.id}`" class="underline">{{ origen.nombre }}</a>
                    </p>
                    <p class="text-xs text-tinta-500 mt-1">
                        Se copiaron los datos, los precios y los canales. No se copian la referencia
                        —se genera nueva—, el stock ni las imágenes. Revisa el nombre antes de guardar:
                        el original sigue igual hasta que guardes este.
                    </p>
                </div>
            </div>

            <!-- Selección de tipo -->
            <div v-if="!tipoSeleccionado" class="mb-5">
                <h2 class="text-sm font-semibold text-tinta-700 mb-3">¿Qué tipo de producto?</h2>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <button
                        v-for="opt in tiposOpciones" :key="opt.value"
                        @click="tipoSeleccionado = opt.value"
                        class="flex flex-col items-center gap-2.5 p-5 rounded-2xl border-2 border-dashed border-linea hover:border-tinta-200 transition-colors bg-superficie"
                    >
                        <div class="w-12 h-12 rounded-xl flex items-center justify-center" :style="`background:${opt.color};`">
                            <svg class="w-6 h-6" :style="`color:${opt.textColor};`" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" v-html="opt.icon"/>
                        </div>
                        <div class="text-center">
                            <p class="font-semibold text-tinta-900 text-sm">{{ opt.label }}</p>
                            <p class="text-xs text-tinta-400 mt-0.5">{{ opt.desc }}</p>
                        </div>
                    </button>
                </div>
            </div>

            <!-- Alerta cambios sin guardar -->
            <div v-if="hasChanges && tipoSeleccionado" class="mb-3 inline-flex items-center gap-2 px-3 py-1.5 rounded-xl text-xs font-semibold text-aviso-naranja" style="background:var(--pastel-ambar); border:1px solid #F59E0B;">
                ● Cambios sin guardar
            </div>

            <!-- Formulario -->
            <form v-if="tipoSeleccionado" @submit.prevent="submit" class="space-y-4">

                <!-- Badge tipo -->
                <div class="flex items-center gap-2">
                    <span class="px-2.5 py-1 rounded-full text-xs font-semibold" :style="badgeStyle[tipoSeleccionado]">
                        {{ tipoSeleccionado === 'producto' ? 'Producto' : 'Servicio' }}
                    </span>
                    <button v-if="!props.tipo" type="button" @click="tipoSeleccionado = ''" class="text-xs text-tinta-300 underline">Cambiar</button>
                </div>

                <!-- Toggle variantes (solo producto) -->
                <div v-if="tipoSeleccionado === 'producto'" class="bg-superficie rounded-2xl shadow-sm p-4">
                    <label class="flex items-center gap-3 cursor-pointer">
                        <div class="relative w-9 h-5 rounded-full transition-colors" :style="esPadre ? 'background:var(--marca);' : 'background:var(--tinta-200);'" @click="esPadre = !esPadre">
                            <div class="absolute top-0.5 w-4 h-4 bg-superficie rounded-full shadow transition-transform" :style="esPadre ? 'transform:translateX(18px);' : 'transform:translateX(2px);'" />
                        </div>
                        <div>
                            <p class="text-sm font-medium text-tinta-900">¿Este producto tiene variantes?</p>
                            <p class="text-xs text-tinta-300">Ej: un perfil con presentaciones de 2m, 3m, 5.25m, 6m</p>
                        </div>
                    </label>
                </div>

                <!-- Información General -->
                <div class="bg-superficie rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-linea">
                        <h3 class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">
                            {{ esPadre ? 'Información general (producto padre)' : 'Información general' }}
                        </h3>
                    </div>
                    <div class="p-5 space-y-3">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-medium text-tinta-700 mb-1">Nombre <span class="text-aviso-rojo">*</span></label>
                                <input v-model="form.nombre" type="text" :class="ic('nombre')" placeholder="Ej: Puerta frigorífica 90x200cm" />
                                <p v-if="form.errors.nombre" class="mt-1 text-xs text-aviso-rojo">{{ form.errors.nombre }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-tinta-700 mb-1">Referencia <span class="text-aviso-rojo">*</span></label>
                                <input v-model="form.referencia" type="text" :class="ic('referencia')" placeholder="Auto-generada si se deja vacío" />
                                <p v-if="form.errors.referencia" class="mt-1 text-xs text-aviso-rojo">{{ form.errors.referencia }}</p>
                            </div>
                        </div>
                        <div v-if="esPadre">
                            <label class="block text-sm font-medium text-tinta-700 mb-1">Atributo de variante <span class="text-aviso-rojo">*</span></label>
                            <input v-model="form.atributo_variante" type="text" :class="ic('atributo_variante')" placeholder="Ej: Longitud" />
                            <p v-if="form.errors.atributo_variante" class="mt-1 text-xs text-aviso-rojo">{{ form.errors.atributo_variante }}</p>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-medium text-tinta-700 mb-1">Categoría</label>
                                <div class="flex gap-2">
                                    <select v-model="form.categoria_id" class="flex-1 border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none bg-superficie">
                                        <option value="">Sin categoría</option>
                                        <option v-for="c in listaCats" :key="c.id" :value="String(c.id)">{{ c.nombre }}</option>
                                    </select>
                                    <button type="button" @click="showModalCat = true" class="px-3 py-2 rounded-xl border border-linea text-tinta-400 hover:bg-tinta-50 text-sm">+</button>
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-tinta-700 mb-1">Unidad de medida</label>
                                <SelectorUnidad
                                    v-model="form.unidad_medida"
                                    :tipo="tipoSeleccionado || 'producto'"
                                    :clase="ic('unidad_medida')"
                                />
                            </div>
                        </div>
                                                
<div>
                            <div class="flex items-center justify-between mb-1">
                                <label class="block text-sm font-medium text-tinta-700">Descripción corta</label>
                                <div class="flex items-center gap-3">
                                    <!-- Llena las dos descripciones de una vez, desde los datos
                                         técnicos en bruto y con la voz de la marca. -->
                                    <GeneradorFichaIa :datos="datosParaFicha" @usar="aplicarFicha" />
                                    <span class="text-xs" :class="(form.descripcion_corta||'').length > 900 ? 'text-aviso-ambar font-semibold' : 'text-tinta-300'">
                                        {{ (form.descripcion_corta||'').length }}/1000
                                    </span>
                                </div>
                            </div>
                            <textarea v-model="form.descripcion_corta" rows="2" maxlength="1000" :class="ic('descripcion_corta')" placeholder="Descripción breve para el catálogo..." />
                        </div>
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <label class="block text-sm font-medium text-tinta-700">Resumen técnico para cotizaciones</label>
                                <span class="text-xs" :class="(form.descripcion_cotizacion||'').length > 500 ? 'text-aviso-ambar font-semibold' : 'text-tinta-300'">
                                    {{ (form.descripcion_cotizacion||'').length }}/600
                                </span>
                            </div>
                            <textarea v-model="form.descripcion_cotizacion" rows="2" maxlength="600"
                                class="w-full border border-linea rounded-xl px-3 py-2 text-sm bg-superficie focus:outline-none focus:border-[var(--marca)]"
                                placeholder="2400 x 2600 mm · lámina galvanizada cal. 22 · motor 1.5 kW 220V · rango -25 °C a 40 °C" />
                            <p class="text-xs text-tinta-300 mt-1">
                                Es lo que se imprime debajo del ítem en las cotizaciones y en las órdenes
                                de producción. Solo datos, sin lenguaje comercial: la ficha completa se
                                queda en la descripción larga.
                            </p>
                        </div>

                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <label class="block text-sm font-medium text-tinta-700">Descripción larga</label>
                                <span class="text-xs" :class="(form.descripcion_larga||'').replace(/<[^>]*>/g, '').length > 9000 ? 'text-aviso-ambar font-semibold' : 'text-tinta-300'">
                                    {{ (form.descripcion_larga||'').replace(/<[^>]*>/g, '').length }}/10000
                                </span>
                            </div>
                            <EditorTexto v-model="form.descripcion_larga" placeholder="Descripción detallada..." :maxLength="10000" />
                        </div>
                    </div>
                </div>

                <!-- Imágenes -->
                <div class="bg-superficie rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-linea">
                        <h3 class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Imágenes</h3>
                    </div>
                    <div class="p-5">
                        <label class="block border-2 border-dashed border-linea rounded-xl p-5 text-center cursor-pointer hover:border-tinta-200 transition-colors mb-3">
                            <svg class="w-7 h-7 text-tinta-200 mx-auto mb-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                            <p class="text-sm text-tinta-400">Toca para seleccionar imágenes</p>
                            <p class="text-xs text-tinta-300 mt-0.5">JPG, PNG o WebP — máx. 5MB</p>
                            <p class="text-xs text-tinta-300 mt-0.5">Recomendado: <strong>1000 × 1000 px</strong> (cuadradas). Se recortan al centro.</p>
                            <input type="file" multiple accept="image/*" class="hidden" @change="onImagenes" />
                        </label>
                        <div v-if="previews.length" class="grid grid-cols-3 sm:grid-cols-5 gap-2">
                            <div v-for="(prev, i) in previews" :key="i" class="relative rounded-xl overflow-hidden border-2" :style="prev.principal ? 'border-color:#F59E0B;' : 'border-color:var(--borde);'">
                                <img :src="prev.url" class="w-full aspect-square object-cover" />
                                <div class="absolute top-1 right-1 flex gap-1">
                                    <button type="button" @click="marcarPrincipal(i)" class="w-5 h-5 rounded-full flex items-center justify-center" :style="prev.principal ? 'background:#F59E0B;' : 'background:rgba(0,0,0,0.4);'">
                                        <svg class="w-2.5 h-2.5 text-white" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                                    </button>
                                    <button type="button" @click="eliminarPreview(i)" class="w-5 h-5 rounded-full bg-red-500/80 flex items-center justify-center">
                                        <svg class="w-2.5 h-2.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Clasificación: Vendible / Insumo -->
                <div class="bg-superficie rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-linea">
                        <h3 class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Clasificación</h3>
                    </div>
                    <div class="p-5 space-y-3">
                        <label class="flex items-center gap-3 cursor-pointer">
                            <div class="relative w-9 h-5 rounded-full transition-colors" :style="form.es_vendible ? 'background:var(--marca);' : 'background:var(--tinta-200);'" @click="form.es_vendible = !form.es_vendible">
                                <div class="absolute top-0.5 w-4 h-4 bg-superficie rounded-full shadow transition-transform" :style="form.es_vendible ? 'transform:translateX(18px);' : 'transform:translateX(2px);'" />
                            </div>
                            <div>
                                <p class="text-sm font-medium text-tinta-900">Es vendible</p>
                                <p class="text-xs text-tinta-300">Aparece en catálogo y cotizaciones</p>
                            </div>
                        </label>
                        <label class="flex items-center gap-3 cursor-pointer">
                            <div class="relative w-9 h-5 rounded-full transition-colors" :style="form.es_insumo ? 'background:#F59E0B;' : 'background:var(--tinta-200);'" @click="form.es_insumo = !form.es_insumo">
                                <div class="absolute top-0.5 w-4 h-4 bg-superficie rounded-full shadow transition-transform" :style="form.es_insumo ? 'transform:translateX(18px);' : 'transform:translateX(2px);'" />
                            </div>
                            <div>
                                <p class="text-sm font-medium text-tinta-900">Es insumo / material</p>
                                <p class="text-xs text-tinta-300">Se usa como componente en ensambles y controla stock en bodegas</p>
                            </div>
                        </label>
                        <!-- Proveedores (solo si es insumo).
                             Antes era un solo selector: alcanzaba para saber a quién se le
                             compró la última vez, no para comparar antes de comprar. -->
                        <div v-if="form.es_insumo" class="pt-2 border-t border-linea space-y-3">
                            <div>
                                <p class="text-xs font-medium text-tinta-500 mb-1">Proveedores y precios</p>
                                <p class="text-xs text-tinta-300 mb-2">
                                    Carga los que lo venden y compara. El preferido es el que queda
                                    en las órdenes de compra.
                                </p>
                                <ProveedoresProducto
                                    :filas="form.proveedores_precios"
                                    :proveedores="props.proveedores ?? []"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Inventario (solo producto) -->
                <div v-if="tipoSeleccionado === 'producto'" class="bg-superficie rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-linea flex items-center justify-between">
                        <h3 class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Inventario</h3>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <span class="text-xs text-tinta-400">Inventariable</span>
                            <div class="relative w-9 h-5 rounded-full transition-colors cursor-pointer" :style="form.inventariable ? 'background:var(--marca);' : 'background:var(--tinta-200);'" @click="form.inventariable = !form.inventariable">
                                <div class="absolute top-0.5 w-4 h-4 bg-superficie rounded-full shadow transition-transform" :style="form.inventariable ? 'transform:translateX(18px);' : 'transform:translateX(2px);'" />
                            </div>
                        </label>
                    </div>
                    <div v-if="form.inventariable" class="p-5 space-y-3">
                        <p v-if="form.es_insumo" class="text-xs text-aviso-ambar bg-pastel-ambar border border-borde-aviso-ambar rounded-lg px-3 py-2">
                            El mínimo y máximo de stock se configuran desde
                            <a href="/inventario" class="underline font-medium" style="color:var(--marca);">Stock &amp; Materiales</a>
                        </p>
                        <!-- Stock inicial por bodega (solo producto simple, no padre) -->
                        <div v-if="!esPadre && props.bodegas?.length">
                            <p class="text-xs font-medium text-tinta-500 mb-1.5">Stock inicial por bodega</p>
                            <div class="space-y-2">
                                <div v-for="b in props.bodegas" :key="b.id" class="flex items-center gap-3">
                                    <span class="text-xs text-tinta-500 w-32 shrink-0">
                                        {{ b.nombre }}
                                        <span v-if="b.es_principal" class="text-aviso-azul ml-1">(principal)</span>
                                    </span>
                                    <input
                                        v-model.number="stockInicial[b.id]"
                                        type="number" min="0" step="0.001"
                                        class="w-24 border border-linea rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:border-[var(--marca)]"
                                        placeholder="0"
                                    />
                                </div>
                            </div>
                            <div v-if="stockInicialTotal > 0" class="mt-2 text-xs text-aviso-verde font-semibold">
                                Total inicial: {{ stockInicialTotal }} {{ form.unidad_medida }}
                            </div>
                        </div>
                        <p v-else-if="esPadre" class="text-xs text-tinta-300">
                            El stock inicial se define por variante, en la sección "Variantes" de abajo.
                        </p>
                    </div>
                </div>

                <!-- Variantes -->
                <div v-if="esPadre" class="bg-superficie rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-linea flex items-center justify-between">
                        <h3 class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Variantes</h3>
                        <button type="button" @click="agregarVariante"
                            class="text-xs text-white px-3 py-1.5 rounded-lg font-medium" style="background:var(--marca);">
                            + Agregar variante
                        </button>
                    </div>
                    <div class="p-5 space-y-4">
                        <p v-if="!variantes.length" class="text-center text-sm text-tinta-300 py-4">
                            Agrega al menos una variante (ej: 2m, 3m, 5.25m, 6m).
                        </p>
                        <div v-for="(v, idx) in variantes" :key="idx" class="border border-linea rounded-xl p-4 space-y-3" style="background:var(--superficie-2);">
                            <div class="flex items-center justify-between">
                                <p class="text-xs font-semibold text-tinta-400 uppercase tracking-wide">Variante {{ idx + 1 }}</p>
                                <button type="button" @click="quitarVariante(idx)" class="text-xs text-aviso-rojo hover:underline">Quitar</button>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs font-medium text-tinta-500 mb-1">
                                        Valor ({{ form.atributo_variante || 'Ej: Longitud' }}) <span class="text-aviso-rojo">*</span>
                                    </label>
                                    <input v-model="v.valor_variante" type="text" placeholder="Ej: 3m"
                                        class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none bg-superficie focus:border-[var(--marca)]" />
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-tinta-500 mb-1">Referencia / SKU</label>
                                    <input v-model="v.referencia" type="text" placeholder="Auto-generada si se deja vacío"
                                        class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none bg-superficie focus:border-[var(--marca)]" />
                                </div>
                            </div>
                            <div v-if="props.bodegas?.length">
                                <p class="text-xs font-medium text-tinta-500 mb-1.5">Stock inicial por bodega</p>
                                <div class="space-y-2">
                                    <div v-for="b in props.bodegas" :key="b.id" class="flex items-center gap-3">
                                        <span class="text-xs text-tinta-500 w-32 shrink-0">
                                            {{ b.nombre }}
                                            <span v-if="b.es_principal" class="text-aviso-azul ml-1">(principal)</span>
                                        </span>
                                        <input
                                            v-model.number="v.stock_inicial[b.id]"
                                            type="number" min="0" step="0.001"
                                            class="w-24 border border-linea rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:border-[var(--marca)] bg-superficie"
                                            placeholder="0"
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ═══ Precios y comisiones por canal ══════════════════════ -->
                <!-- El mismo componente que usa la pantalla de editar: tener dos copias de
                     esta lógica fue lo que dejó a editar escribiendo solo las columnas
                     antiguas mientras crear ya trabajaba por canales. -->
                <PreciosPorCanal
                    :canales="form.canales"
                    v-model:precio-costo="form.precio_costo"
                />

                <!-- Errores de validación -->
                <div v-if="Object.keys(form.errors).length" class="bg-pastel-rojo border border-borde-aviso-rojo rounded-xl p-4">
                    <p class="text-sm font-semibold text-aviso-rojo mb-2">Corrige los siguientes errores:</p>
                    <ul class="list-disc list-inside space-y-1">
                        <li v-for="(msg, field) in form.errors" :key="field" class="text-xs text-aviso-rojo">{{ msg }}</li>
                    </ul>
                </div>

                <!-- Botones -->
                <div class="flex gap-3 pb-4">
                    <button type="button" @click="router.visit('/productos')" class="flex-1 py-3 rounded-xl border border-linea text-sm font-medium text-tinta-500 bg-superficie">Cancelar</button>
                    <button type="submit" :disabled="form.processing || !puedeGuardar" class="flex-1 py-3 rounded-xl text-sm font-medium text-white shadow-sm disabled:opacity-60" style="background-color:var(--marca);">
                        {{ form.processing ? 'Guardando...' : (esPadre ? 'Crear producto y variantes' : 'Crear producto') }}
                    </button>
                </div>
            </form>
        </div>

        <!-- Modal nueva categoría -->
        <Teleport to="body">
            <div v-if="showModalCat" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-4" style="background:rgba(0,0,0,0.5);">
                <div class="bg-superficie rounded-2xl shadow-xl w-full max-w-sm p-5">
                    <h3 class="text-base font-semibold text-tinta-900 mb-4">Nueva categoría</h3>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-sm font-medium text-tinta-700 mb-1">Nombre</label>
                            <input v-model="nuevaCat.nombre" type="text" class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none" placeholder="Ej: Puertas Refrigeradas" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-tinta-700 mb-1">Color</label>
                            <div class="flex items-center gap-3">
                                <input v-model="nuevaCat.color" type="color" class="w-9 h-9 rounded-lg border border-linea cursor-pointer" />
                                <span class="text-sm text-tinta-400 font-mono">{{ nuevaCat.color }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="flex gap-3 mt-4">
                        <button @click="showModalCat = false" class="flex-1 py-2.5 rounded-xl border border-linea text-sm text-tinta-500">Cancelar</button>
                        <button @click="crearCategoria" :disabled="guardandoCat || !nuevaCat.nombre" class="flex-1 py-2.5 rounded-xl text-white text-sm font-medium disabled:opacity-60" style="background-color:var(--marca);">
                            {{ guardandoCat ? 'Guardando...' : 'Crear' }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
