<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import EditorTexto from '@/Components/EditorTexto.vue'
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
    nombre:              '',
    referencia:          '',
    unidad_medida:       'unidad',
    descripcion_corta:   '',
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

/** El canal base: el piso de utilidad. No lleva comisión. */
const canalBase = computed(() => form.canales.find(c => c.es_canal_base) ?? null)

/** Los canales que sí pagan comisión, en orden de prioridad. */
const canalesConComision = computed(() => form.canales.filter(c => !c.es_canal_base))

/**
 * Lo que se vende por encima del canal base, que es sobre lo que se paga comisión.
 *
 * La comisión no se calcula sobre el precio de venta completo: sobre el excedente. Vender
 * al precio del canal base no genera comisión porque no hay excedente que repartir.
 */
function excedenteDe(canal) {
    return Math.max(0, (Number(canal.precio) || 0) - (Number(canalBase.value?.precio) || 0))
}

/**
 * La escalera de incentivos: cada canal debe pagar al menos lo que el anterior.
 *
 * Antes esta regla estaba escrita para dos canales concretos —«la mínima de cliente final
 * debe ser ≥ la máxima de distribuidor»—. Ahora se aplica a la lista completa, en el orden
 * que la empresa haya puesto: mientras más lejos del canal base, más incentivo.
 */
function minimoExigido(i) {
    return i > 0 ? (Number(canalesConComision.value[i - 1].comision_max_pct) || 0) : 0
}

function errorEscalera(i) {
    const canal = canalesConComision.value[i]

    return (Number(canal.comision_min_pct) || 0) < minimoExigido(i)
}

const hayErrorDeEscalera = computed(() =>
    canalesConComision.value.some((c, i) => (Number(c.comision_min_pct) || 0) > 0 && errorEscalera(i))
)

/**
 * Hasta dónde puede bajar el precio de un canal sin invadir al de abajo.
 *
 * Cada canal puede descontar hasta llegar al precio del canal anterior en la lista, y el
 * primero hasta el canal base. Así un descuento nunca hace que un cliente pague menos que
 * el canal que tiene mejor precio por derecho.
 */
function descuentoMaxDe(canal) {
    const i    = form.canales.findIndex(c => c.segmentacion_opcion_id === canal.segmentacion_opcion_id)
    const piso = i > 0 ? (Number(form.canales[i - 1].precio) || 0) : 0
    const base = Number(canal.precio) || 0

    if (!base) return 0

    return Math.max(0, parseFloat(((base - piso) / base * 100).toFixed(2)))
}

/**
 * Propone comisiones que respetan la escalera.
 *
 * Reparte parte del excedente de cada canal, y arranca cada uno donde terminó el anterior.
 * Antes había dos porcentajes escritos para dos canales concretos (65 % y 80 %); ahora es la
 * misma proporción para todos, y el que sea precio público lleva un poco más porque traer un
 * cliente nuevo cuesta más que atender a uno que ya compra.
 */
function sugerirComisionesPorCanal() {
    let anterior = 0

    canalesConComision.value.forEach(canal => {
        const precio    = Number(canal.precio) || 0
        const excedente = excedenteDe(canal)

        if (!precio || !excedente) {
            canal.comision_min_pct = anterior
            canal.comision_max_pct = anterior
            return
        }

        const disponible = (excedente / precio) * 100
        const reparto    = canal.es_precio_publico ? 0.8 : 0.65

        canal.comision_min_pct = anterior
        canal.comision_max_pct = parseFloat(Math.max(anterior * 1.2, disponible * reparto).toFixed(2))

        anterior = canal.comision_max_pct
    })
}

watch(
    [() => form.precio_costo, () => form.canales.map(c => c.margen_pct).join(',')],
    () => {
        form.canales.forEach(c => {
            c.precio = calcPrecio(form.precio_costo, c.margen_pct)
        })
    },
    { immediate: true }
)

const { hasChanges, setOriginal, checkChanges, markClean } = useUnsavedChanges()
onMounted(() => setOriginal(form.data()))
watch(() => form.data(), checkChanges, { deep: true })

const stockInicialTotal = computed(() =>
    Object.values(stockInicial.value).reduce((s, v) => s + (Number(v) || 0), 0)
)

const unidadesPorTipo = computed(() => {
    if (form.tipo === 'servicio') return ['unidad', 'hora', 'dia', 'instalacion']
    return ['unidad', 'ml', 'm2', 'kg', 'mm', 'metros', 'litros', 'docenas', 'pack', 'cajas']
})

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
    form.errors[field] ? 'border-red-400 bg-red-50' : 'border-linea bg-superficie focus:border-[var(--marca)]',
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
    form.canales.forEach(canal => {
        canal.descuento_max_pct = canal.es_canal_base ? 0 : descuentoMaxDe(canal)
    })

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
            <div v-if="hasChanges && tipoSeleccionado" class="mb-3 inline-flex items-center gap-2 px-3 py-1.5 rounded-xl text-xs font-semibold text-orange-700" style="background:var(--pastel-ambar); border:1px solid #F59E0B;">
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
                                <label class="block text-sm font-medium text-tinta-700 mb-1">Nombre <span class="text-red-500">*</span></label>
                                <input v-model="form.nombre" type="text" :class="ic('nombre')" placeholder="Ej: Puerta frigorífica 90x200cm" />
                                <p v-if="form.errors.nombre" class="mt-1 text-xs text-red-600">{{ form.errors.nombre }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-tinta-700 mb-1">Referencia <span class="text-red-500">*</span></label>
                                <input v-model="form.referencia" type="text" :class="ic('referencia')" placeholder="Auto-generada si se deja vacío" />
                                <p v-if="form.errors.referencia" class="mt-1 text-xs text-red-600">{{ form.errors.referencia }}</p>
                            </div>
                        </div>
                        <div v-if="esPadre">
                            <label class="block text-sm font-medium text-tinta-700 mb-1">Atributo de variante <span class="text-red-500">*</span></label>
                            <input v-model="form.atributo_variante" type="text" :class="ic('atributo_variante')" placeholder="Ej: Longitud" />
                            <p v-if="form.errors.atributo_variante" class="mt-1 text-xs text-red-600">{{ form.errors.atributo_variante }}</p>
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
                                <select v-model="form.unidad_medida" :class="ic('unidad_medida')">
                                    <option v-for="u in unidadesPorTipo" :key="u" :value="u">{{ u }}</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <label class="block text-sm font-medium text-tinta-700">Descripción corta</label>
                                <span class="text-xs" :class="(form.descripcion_corta||'').length > 900 ? 'text-amber-500 font-semibold' : 'text-tinta-300'">
                                    {{ (form.descripcion_corta||'').length }}/1000
                                </span>
                            </div>
                            <textarea v-model="form.descripcion_corta" rows="2" maxlength="1000" :class="ic('descripcion_corta')" placeholder="Descripción breve para el catálogo..." />
                        </div>
                        <div>
                            <div class="flex items-center justify-between mb-1">
                                <label class="block text-sm font-medium text-tinta-700">Descripción larga</label>
                                <span class="text-xs" :class="(form.descripcion_larga||'').replace(/<[^>]*>/g, '').length > 9000 ? 'text-amber-500 font-semibold' : 'text-tinta-300'">
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
                        <!-- Proveedor (solo si es insumo) -->
                        <div v-if="form.es_insumo" class="pt-2 border-t border-linea space-y-3">
                            <div>
                                <label class="block text-xs font-medium text-tinta-500 mb-1">Proveedor</label>
                                <select v-model="form.proveedor_id" :class="ic('proveedor_id')">
                                    <option value="">Sin proveedor</option>
                                    <option v-for="pv in props.proveedores" :key="pv.id" :value="String(pv.id)">{{ pv.nombre }}</option>
                                </select>
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
                        <p v-if="form.es_insumo" class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
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
                                        <span v-if="b.es_principal" class="text-blue-500 ml-1">(principal)</span>
                                    </span>
                                    <input
                                        v-model.number="stockInicial[b.id]"
                                        type="number" min="0" step="0.001"
                                        class="w-24 border border-linea rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:border-[var(--marca)]"
                                        placeholder="0"
                                    />
                                </div>
                            </div>
                            <div v-if="stockInicialTotal > 0" class="mt-2 text-xs text-green-700 font-semibold">
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
                                <button type="button" @click="quitarVariante(idx)" class="text-xs text-red-500 hover:underline">Quitar</button>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs font-medium text-tinta-500 mb-1">
                                        Valor ({{ form.atributo_variante || 'Ej: Longitud' }}) <span class="text-red-500">*</span>
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
                                            <span v-if="b.es_principal" class="text-blue-500 ml-1">(principal)</span>
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

                <!-- Lista de precios -->
                <div class="bg-superficie rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-linea">
                        <h3 class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Lista de precios</h3>
                    </div>
                    <div class="p-5 space-y-4">
                        <!-- Costo base -->
                        <div>
                            <label class="block text-xs font-medium text-tinta-500 mb-1">Precio Costo</label>
                            <div class="relative">
                                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs text-tinta-300">$</span>
                                <input v-model.number="form.precio_costo" type="number" min="0" step="100"
                                    class="w-full border rounded-xl pl-7 pr-3 py-2 text-sm focus:outline-none border-linea bg-superficie focus:border-[var(--marca)]" />
                            </div>
                        </div>
                        <!-- Una fila por canal configurado en Segmentación.
                             Antes eran tres cajas fijas: mayorista, distribuidor y cliente
                             final. Ahora la empresa crea los canales que necesite y esta
                             pantalla dibuja los que existan. -->
                        <!-- El nombre del canal va como encabezado de la fila, y los campos se
                             llaman «Margen %» y «Precio». Antes se componía «Margen {nombre} %»,
                             y con nombres como «Precio Público» salía «Margen Precio Público %»
                             y «Precio Precio Público». El nombre lo pone la empresa: la pantalla
                             no puede asumir cómo empieza. -->
                        <div v-for="canal in form.canales" :key="canal.segmentacion_opcion_id">
                            <div class="flex items-center gap-1.5 mb-1.5">
                                <span class="w-2 h-2 rounded-full shrink-0" :style="`background:${canal.color};`"/>
                                <span class="text-xs font-semibold text-tinta-600">{{ canal.etiqueta }}</span>
                                <span v-if="canal.es_canal_base" class="text-[10px] px-1.5 py-0.5 rounded-full bg-blue-50 text-blue-700">canal base</span>
                                <span v-if="canal.es_precio_publico" class="text-[10px] px-1.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700">precio público</span>
                            </div>
                            <div class="grid grid-cols-2 gap-3 items-end">
                                <div>
                                    <label class="block text-xs font-medium text-tinta-400 mb-1">Margen %</label>
                                    <input v-model.number="canal.margen_pct" type="number" min="1" max="99" step="0.5"
                                        class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]" />
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-tinta-400 mb-1">Precio</label>
                                    <div class="relative">
                                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs text-tinta-300">$</span>
                                        <input :value="formatCOP(canal.precio)" readonly
                                            class="w-full border border-linea rounded-xl pl-7 pr-3 py-2 text-sm bg-tinta-50 font-semibold text-tinta-700" />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- El precio sale del costo: sin costo, todos quedan en cero y no es
                             evidente por qué. -->
                        <p v-if="form.canales.length && !form.precio_costo" class="text-xs text-amber-700">
                            Escribe el precio de costo y los precios de cada canal se calculan solos.
                        </p>

                        <!-- Sin canales no hay precios que poner, y decirlo aquí evita que
                             alguien crea que el producto quedó mal guardado. -->
                        <div v-if="!form.canales.length"
                            class="rounded-xl bg-amber-50 border border-amber-200 px-3 py-2.5">
                            <p class="text-xs text-amber-800 leading-relaxed">
                                No hay canales de precio configurados. Ve a
                                <button type="button" @click="router.visit('/administracion/segmentacion')"
                                    class="font-semibold underline underline-offset-2">Segmentación</button>
                                y márcale «define precio» a los tipos de contacto que deban tener lista de precios.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Comisión Vendedor por Canal -->
                <div class="bg-superficie rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-linea flex items-center justify-between">
                        <h3 class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Comisión Vendedor por Canal</h3>
                        <button type="button" @click="sugerirComisionesPorCanal"
                            class="text-xs text-[var(--marca)] border border-[var(--marca)] rounded-lg px-3 py-1.5 hover:bg-blue-50 transition-colors flex items-center gap-1.5">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
                            </svg>
                            ▷ Sugerir comisiones
                        </button>
                    </div>
                    <div class="p-5 space-y-4">

                        <!-- Una tarjeta por canal, no tres fijas.
                             El canal base va primero y sin campos: es el piso de utilidad
                             de la empresa, no una venta con margen para repartir. -->
                        <div v-if="canalBase" class="bg-blue-50 border border-blue-100 rounded-lg p-3">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="text-xs font-semibold text-blue-700 uppercase">{{ canalBase.etiqueta }}</span>
                                <span class="bg-blue-100 text-blue-600 text-xs px-2 py-0.5 rounded-full">Sin comisión · Precio fijo</span>
                            </div>
                            <p class="text-xs text-blue-400 mt-1">
                                Su margen ({{ canalBase.margen_pct }}%) es la utilidad mínima garantizada de la
                                empresa. No hay comisión para el vendedor en este canal, y la de los demás se
                                calcula sobre lo que se venda por encima de {{ formatCOP(canalBase.precio) }}.
                            </p>
                        </div>

                        <div v-for="(canal, i) in canalesConComision" :key="canal.segmentacion_opcion_id"
                            class="border rounded-lg p-3 space-y-3"
                            :style="`border-color:${canal.color}40; background:${canal.color}0a;`">
                            <div class="flex items-center justify-between flex-wrap gap-1">
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-semibold uppercase" :style="`color:${canal.color};`">{{ canal.etiqueta }}</span>
                                    <span v-if="canal.es_precio_publico" class="bg-green-100 text-green-600 text-xs px-2 py-0.5 rounded-full">⭐ Mayor incentivo</span>
                                </div>
                                <span class="text-xs text-tinta-400">
                                    Base: {{ formatCOP(canal.precio) }} · Excedente: {{ formatCOP(excedenteDe(canal)) }} · Desc. máx: {{ descuentoMaxDe(canal) }}%
                                </span>
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="text-xs text-tinta-400 mb-1 block">
                                        Comisión mínima (%)
                                        <span v-if="minimoExigido(i) > 0" class="text-orange-500 ml-1">
                                            ← mín = máx {{ canalesConComision[i - 1].etiqueta }} ({{ minimoExigido(i) }}%)
                                        </span>
                                    </label>
                                    <div class="flex items-center gap-2">
                                        <input type="number" step="0.1" :min="minimoExigido(i)" v-model.number="canal.comision_min_pct"
                                            :class="['w-24 border rounded-lg px-2 py-1.5 text-sm focus:ring-2 focus:outline-none',
                                                errorEscalera(i) ? 'border-red-400 focus:ring-red-300' : 'border-tinta-200 focus:ring-[var(--marca-suave)]']" />
                                        <span class="text-xs text-tinta-300">= {{ formatCOP(excedenteDe(canal) * canal.comision_min_pct / 100) }}</span>
                                    </div>
                                </div>
                                <div>
                                    <label class="text-xs text-tinta-400 mb-1 block">Comisión máxima (%)</label>
                                    <div class="flex items-center gap-2">
                                        <input type="number" step="0.1" :min="canal.comision_min_pct" v-model.number="canal.comision_max_pct"
                                            class="w-24 border border-tinta-200 rounded-lg px-2 py-1.5 text-sm focus:ring-2 focus:ring-[var(--marca-suave)] focus:outline-none" />
                                        <span class="text-xs text-tinta-300">= {{ formatCOP(excedenteDe(canal) * canal.comision_max_pct / 100) }}</span>
                                    </div>
                                </div>
                            </div>

                            <p v-if="errorEscalera(i)" class="text-xs text-red-600">
                                ⚠️ La comisión mínima debe ser mayor o igual a la máxima de
                                {{ canalesConComision[i - 1].etiqueta }} ({{ minimoExigido(i) }}%): mientras más lejos
                                del canal base, más incentivo para el vendedor.
                            </p>
                            <p v-else-if="canal.comision_min_pct > 0 && canal.comision_max_pct > 0" class="text-xs" :style="`color:${canal.color};`">
                                ✅ El vendedor gana entre {{ formatCOP(excedenteDe(canal) * canal.comision_min_pct / 100) }}
                                y {{ formatCOP(excedenteDe(canal) * canal.comision_max_pct / 100) }}
                            </p>
                        </div>

                        <!-- Comparativa de incentivos -->
                        <div v-if="canalesConComision.some(c => c.comision_max_pct > 0)" class="bg-tinta-50 rounded-lg p-3">
                            <p class="text-xs font-medium text-tinta-500 mb-2">📊 Comparativa de incentivos por canal</p>
                            <div class="space-y-1.5">
                                <div v-if="canalBase" class="flex items-center gap-2">
                                    <span class="text-xs text-tinta-400 w-28 truncate">{{ canalBase.etiqueta }}:</span>
                                    <div class="flex-1 bg-tinta-200 rounded-full h-1.5"></div>
                                    <span class="text-xs text-tinta-300 w-20 text-right">Sin comisión</span>
                                </div>
                                <div v-for="canal in canalesConComision" :key="'cmp-' + canal.segmentacion_opcion_id" class="flex items-center gap-2">
                                    <span class="text-xs w-28 truncate" :style="`color:${canal.color};`">{{ canal.etiqueta }}:</span>
                                    <div class="flex-1 bg-tinta-200 rounded-full h-1.5">
                                        <div class="h-1.5 rounded-full" :style="`width:${Math.min(canal.comision_max_pct * 5, 100)}%; background:${canal.color};`"></div>
                                    </div>
                                    <span class="text-xs w-20 text-right" :style="`color:${canal.color};`">{{ canal.comision_max_pct }}%</span>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- Errores de validación -->
                <div v-if="Object.keys(form.errors).length" class="bg-red-50 border border-red-200 rounded-xl p-4">
                    <p class="text-sm font-semibold text-red-700 mb-2">Corrige los siguientes errores:</p>
                    <ul class="list-disc list-inside space-y-1">
                        <li v-for="(msg, field) in form.errors" :key="field" class="text-xs text-red-600">{{ msg }}</li>
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
