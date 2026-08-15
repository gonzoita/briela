<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import SelectorUnidad from '@/Components/SelectorUnidad.vue'
import GeneradorFichaIa from '@/Components/GeneradorFichaIa.vue'
import PreciosPorCanal from '@/Components/PreciosPorCanal.vue'
import ProveedoresProducto from '@/Components/ProveedoresProducto.vue'
import { usePreciosPorCanal } from '@/composables/usePreciosPorCanal'
import EditorTexto from '@/Components/EditorTexto.vue'
import { useForm, router } from '@inertiajs/vue3'
import { ref, computed, reactive, watch, onMounted } from 'vue'
import { useUnsavedChanges } from '@/composables/useUnsavedChanges'
import { colorMarca } from '@/marca'

const props = defineProps({
    producto:    Object,
    categorias:  Array,
    proveedores: Array,
    bodegas:     Array,
    // Los canales configurados, ya con lo que este producto tenga guardado.
    canales:     { type: Array, default: () => [] },
})

const p = props.producto

// ── Redacción con IA ──────────────────────────────────────────────────────────
// Pide el texto y lo pone en el campo; el usuario decide si lo deja o lo edita.
// No guarda nada solo: hay que darle Guardar como siempre.
const iaCargando = ref(false)
const iaError    = ref('')

function getCookie(name) {
    const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'))
    return match ? decodeURIComponent(match[2]) : ''
}

async function generarDescripcion(formato = 'corta') {
    iaCargando.value = true
    iaError.value    = ''

    try {
        const resp = await fetch('/api/ia/descripcion', {
            method:  'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN':  getCookie('XSRF-TOKEN'),
                'Accept':        'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ tipo: 'producto', id: p.id, formato }),
        })

        const data = await resp.json()

        if (!resp.ok) {
            iaError.value = data.error ?? 'No se pudo generar la descripción.'
            return
        }

        if (formato === 'corta') form.descripcion_corta = data.texto
        else                     form.descripcion_larga = data.texto
    } catch (e) {
        iaError.value = 'No se pudo conectar con la IA.'
    } finally {
        iaCargando.value = false
    }
}

// ── Producto padre: formulario reducido + gestión de variantes ────────────────
const formPadre = useForm({
    _method:            'PUT',
    nombre:             p.nombre ?? '',
    categoria_id:       String(p.categoria_id ?? ''),
    atributo_variante:  p.atributo_variante ?? '',
    variantes:           [],
})

const variantesNuevas = ref([])

const agregarVarianteNueva = () => {
    const stockPorBodega = {}
    ;(props.bodegas ?? []).forEach(b => { stockPorBodega[b.id] = '' })
    variantesNuevas.value.push({ valor_variante: '', referencia: '', stock_inicial: stockPorBodega })
}

const quitarVarianteNueva = (idx) => variantesNuevas.value.splice(idx, 1)

const submitPadre = () => {
    const buildStock = (obj) => {
        const data = {}
        Object.entries(obj ?? {}).forEach(([k, v]) => { if (Number(v) > 0) data[k] = Number(v) })
        return data
    }

    formPadre.variantes = variantesNuevas.value.map(v => ({
        valor_variante: v.valor_variante,
        referencia:     v.referencia || null,
        stock_inicial:  buildStock(v.stock_inicial),
    }))

    formPadre.post(`/productos/${p.id}`, {
        onSuccess: () => { variantesNuevas.value = [] },
    })
}

const form = useForm({
    _method:              'PUT',
    tipo:                 p.tipo,
    categoria_id:         String(p.categoria_id ?? ''),
    proveedor_id:         String(p.proveedor_id ?? ''),
    // Dentro de useForm a propósito: `data()` solo recorre las claves que existían al
    // crearlo, así que una agregada después nunca se enviaría.
    proveedores_precios: (p.proveedores_precios ?? []).map(f => ({ ...f })),
    nombre:               p.nombre ?? '',
    referencia:           p.referencia ?? '',
    unidad_medida:        p.unidad_medida ?? 'unidad',
    descripcion_corta:    p.descripcion_corta ?? '',
    descripcion_cotizacion: p.descripcion_cotizacion ?? '',
    descripcion_larga:    p.descripcion_larga ?? '',
    inventariable:        p.inventariable ?? false,
    es_vendible:          p.es_vendible ?? false,
    es_insumo:            p.es_insumo ?? false,
    stock_minimo:         p.stock_minimo ?? 0,
    stock_maximo:         p.stock_maximo ?? 0,
    precio_costo:                p.precio_costo ?? 0,
    margen_mayorista:            p.margen_mayorista ?? 25,
    margen_distribuidor:         p.margen_distribuidor ?? 30,
    margen_cliente_final:        p.margen_cliente_final ?? 35,
    precio_mayorista:            p.precio_mayorista ?? 0,
    precio_distribuidor:         p.precio_distribuidor ?? 0,
    precio_cliente_final:        p.precio_cliente_final ?? 0,
    comision_pct_minima:          p.comision_pct_minima ?? 0,
    comision_pct_maxima:          p.comision_pct_maxima ?? 0,
    comision_min_distribuidor:    p.comision_min_distribuidor ?? 0,
    comision_max_distribuidor:    p.comision_max_distribuidor ?? 0,
    comision_min_cliente_final:   p.comision_min_cliente_final ?? 0,
    comision_max_cliente_final:   p.comision_max_cliente_final ?? 0,
    utilidad_minima_empresa_pct:  p.utilidad_minima_empresa_pct ?? 15,
    descuento_max_cliente_final:  p.descuento_max_cliente_final ?? 3,
    descuento_max_distribuidor:   p.descuento_max_distribuidor ?? 5,
    descuento_max_mayorista:      p.descuento_max_mayorista ?? 8,
    imagenes:                    [],
    // Declarado aquí y no asignado después: `form.data()` de Inertia recorre las claves con
    // las que nació el formulario, así que un campo agregado más tarde se ve, se edita y no
    // se envía. Ya pasó una vez con este mismo campo en la pantalla de crear.
    canales:                     [],
})

form.canales = (props.canales ?? []).map(c => ({ ...c }))

const { hayErrorDeEscalera, aplicarDescuentosMax } = usePreciosPorCanal(
    computed(() => form.canales),
    computed(() => Number(form.precio_costo) || 0),
)

const calcPrecio = (costo, margenPct) => {
    if (!costo || margenPct >= 100) return 0
    return Math.ceil(costo / (1 - margenPct / 100) / 1000) * 1000
}

// Los tres campos antiguos ya no se editan en pantalla, pero se siguen enviando: hay código
// que todavía los lee. De todas formas el servidor los reescribe desde los canales al
// guardar, así que esto solo evita mandar valores incoherentes en el camino.
watch(
    [() => form.precio_costo, () => form.margen_mayorista, () => form.margen_distribuidor, () => form.margen_cliente_final],
    ([costo, mm, md, mcf]) => {
        form.precio_mayorista     = calcPrecio(costo, mm)
        form.precio_distribuidor  = calcPrecio(costo, md)
        form.precio_cliente_final = calcPrecio(costo, mcf)
    }
)

const { hasChanges, setOriginal, checkChanges, markClean } = useUnsavedChanges()
onMounted(() => setOriginal(form.data()))
watch(() => form.data(), checkChanges, { deep: true })

const stocksPorBodega = computed(() => p.stocks ?? [])

// La lista de unidades la administra la empresa; la trae `SelectorUnidad`.

// ── Ficha técnica con IA ──────────────────────────────────────────────────────
const datosParaFicha = computed(() => ({
    tipo:              p.tipo === 'servicio' ? 'servicio' : 'producto',
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

// ── Imágenes existentes ───────────────────────────────────────────────────────
const imagenesExistentes = ref(p.imagenes || [])

const eliminarImagenExistente = (id) => {
    router.delete(`/productos/imagenes/${id}`, { preserveScroll: true, onSuccess: () => {
        imagenesExistentes.value = imagenesExistentes.value.filter(i => i.id !== id)
    }})
}

const setPrincipal = (id) => {
    router.patch(`/productos/imagenes/${id}/principal`, {}, { preserveScroll: true, onSuccess: () => {
        imagenesExistentes.value.forEach(i => { i.es_principal = i.id === id })
    }})
}

const nuevasPreviews = ref([])

const onImagenes = (e) => {
    Array.from(e.target.files).forEach((f) => {
        const r = new FileReader()
        r.onload = (ev) => nuevasPreviews.value.push({ file: f, url: ev.target.result })
        r.readAsDataURL(f)
    })
    form.imagenes = [...(form.imagenes || []), ...Array.from(e.target.files)]
}

const eliminarNueva = (i) => {
    nuevasPreviews.value.splice(i, 1)
    const arr = Array.from(form.imagenes || []); arr.splice(i, 1); form.imagenes = arr
}

const csrfToken = () => {
    const cookie = document.cookie
        .split('; ')
        .find(row => row.startsWith('XSRF-TOKEN='))
    return cookie ? decodeURIComponent(cookie.split('=')[1]) : ''
}

const jsonHeaders = () => ({
    'Content-Type': 'application/json',
    'Accept': 'application/json',
    'X-XSRF-TOKEN': csrfToken(),
    'X-Requested-With': 'XMLHttpRequest',
})

const formatCOP = (v) =>
    new Intl.NumberFormat('es-CO', { maximumFractionDigits: 0 }).format(Math.round(v ?? 0))

// ── Modal nueva categoría ────────────────────────────────────────────────────
const coloresPaleta = [
    colorMarca(), '#1d4ed8', '#7c3aed', '#db2777',
    '#dc2626', '#ea580c', '#d97706', '#65a30d',
    '#16a34a', '#0891b2', '#475569', '#1a1a1a',
]
const showModalCat = ref(false)
const listaCats = ref([...(props.categorias || [])])
const nuevaCat = reactive({ nombre: '', color: colorMarca() })
const guardandoCat = ref(false)

const crearCategoria = async () => {
    if (!nuevaCat.nombre?.trim()) return
    guardandoCat.value = true
    try {
        const cookie = document.cookie
            .split('; ')
            .find(r => r.startsWith('XSRF-TOKEN='))
        const token = cookie ? decodeURIComponent(cookie.split('=')[1]) : ''

        const res = await fetch('/api/categorias-producto', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-XSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                nombre: nuevaCat.nombre,
                color: nuevaCat.color || colorMarca(),
            }),
        })

        if (!res.ok) {
            console.error('Error:', await res.text())
            return
        }

        const cat = await res.json()
        listaCats.value.push(cat)
        form.categoria_id = String(cat.id)
        showModalCat.value = false
        nuevaCat.nombre = ''
        nuevaCat.color = colorMarca()
    } catch (e) {
        console.error('Error creando categoría:', e)
    } finally {
        guardandoCat.value = false
    }
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
    const precioMay     = parseFloat(form.precio_mayorista) || 0
    const precioDistrib = parseFloat(form.precio_distribuidor) || 0
    const precioFinal   = parseFloat(form.precio_cliente_final) || 0
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

const submit = () => {
    // La escalera se valida sobre la lista de canales, no sobre dos nombres fijos: antes
    // decía «la mínima de cliente final debe ser ≥ la máxima de distribuidor», y en una
    // instalación con canales propios eso no significa nada.
    if (hayErrorDeEscalera.value) {
        alert('Corrige los rangos de comisión antes de guardar. '
            + 'La comisión mínima de cada canal debe ser mayor o igual a la máxima del canal '
            + 'anterior: mientras más lejos del canal base, más incentivo para el vendedor.')
        return
    }

    // El descuento máximo de cada canal sale de su distancia con el canal de abajo: se
    // calcula al guardar y no se pide en pantalla.
    aplicarDescuentosMax()

    markClean()
    form.post(`/productos/${p.id}`, { forceFormData: true })
}

const badgeStyle = {
    producto: 'background:var(--pastel-azul-2);color:var(--texto-azul);',
    servicio: 'background:var(--pastel-verde);color:var(--texto-verde);',
}
</script>

<template>
    <AppLayout :title="`Editar — ${p.nombre}`">
        <div class="max-w-4xl mx-auto">

            <!-- ═══ Producto PADRE: formulario reducido + variantes ═══════════ -->
            <div v-if="p.es_padre" class="space-y-4">
                <div class="flex items-center gap-2">
                    <span class="px-2.5 py-1 rounded-full text-xs font-semibold" style="background:var(--pastel-violeta);color:var(--texto-violeta);">Producto padre</span>
                    <span class="font-mono text-xs text-tinta-300">{{ p.referencia }}</span>
                </div>

                <form @submit.prevent="submitPadre" class="space-y-4">
                    <div class="bg-superficie rounded-2xl shadow-sm overflow-hidden">
                        <div class="px-5 py-3 border-b border-linea">
                            <h3 class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Información general (padre)</h3>
                        </div>
                        <div class="p-5 space-y-3">
                            <div>
                                <label class="block text-sm font-medium text-tinta-700 mb-1">Nombre <span class="text-aviso-rojo">*</span></label>
                                <input v-model="formPadre.nombre" type="text"
                                    class="w-full border rounded-xl px-3 py-2 text-sm focus:outline-none bg-superficie focus:border-[var(--marca)] border-linea" />
                                <p v-if="formPadre.errors.nombre" class="mt-1 text-xs text-aviso-rojo">{{ formPadre.errors.nombre }}</p>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-sm font-medium text-tinta-700 mb-1">Categoría</label>
                                    <select v-model="formPadre.categoria_id" class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none bg-superficie">
                                        <option value="">Sin categoría</option>
                                        <option v-for="c in props.categorias" :key="c.id" :value="String(c.id)">{{ c.nombre }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-tinta-700 mb-1">Atributo de variante</label>
                                    <input v-model="formPadre.atributo_variante" type="text" placeholder="Ej: Longitud"
                                        class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none bg-superficie focus:border-[var(--marca)]" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Variantes existentes -->
                    <div class="bg-superficie rounded-2xl shadow-sm overflow-hidden">
                        <div class="px-5 py-3 border-b border-linea">
                            <h3 class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Variantes existentes</h3>
                        </div>
                        <div class="divide-y divide-separador">
                            <div v-for="v in (p.variantes ?? [])" :key="v.id"
                                class="flex items-center justify-between px-5 py-3 cursor-pointer hover:bg-realce"
                                @click="router.visit(`/productos/${v.id}/editar`)">
                                <div>
                                    <p class="text-sm text-tinta-900">{{ v.nombre }}</p>
                                    <p class="text-xs text-tinta-300 font-mono">{{ v.referencia }}</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-medium px-2 py-0.5 rounded-full" style="background:var(--pastel-violeta);color:var(--texto-violeta);">{{ v.valor_variante }}</span>
                                    <span class="text-xs font-semibold text-aviso-verde">{{ v.stock_total }}</span>
                                </div>
                            </div>
                            <p v-if="!p.variantes?.length" class="text-xs text-tinta-300 text-center py-4">Sin variantes.</p>
                        </div>
                    </div>

                    <!-- Agregar nuevas variantes -->
                    <div class="bg-superficie rounded-2xl shadow-sm overflow-hidden">
                        <div class="px-5 py-3 border-b border-linea flex items-center justify-between">
                            <h3 class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Agregar variantes</h3>
                            <button type="button" @click="agregarVarianteNueva"
                                class="text-xs text-white px-3 py-1.5 rounded-lg font-medium" style="background:var(--marca);">
                                + Agregar variante
                            </button>
                        </div>
                        <div class="p-5 space-y-4">
                            <p v-if="!variantesNuevas.length" class="text-center text-sm text-tinta-300 py-2">
                                Agrega nuevas variantes para este producto padre.
                            </p>
                            <div v-for="(v, idx) in variantesNuevas" :key="idx" class="border border-linea rounded-xl p-4 space-y-3" style="background:var(--superficie-2);">
                                <div class="flex items-center justify-between">
                                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-wide">Nueva variante {{ idx + 1 }}</p>
                                    <button type="button" @click="quitarVarianteNueva(idx)" class="text-xs text-aviso-rojo hover:underline">Quitar</button>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs font-medium text-tinta-500 mb-1">
                                            Valor ({{ formPadre.atributo_variante || 'Ej: Longitud' }}) <span class="text-aviso-rojo">*</span>
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
                                            <span class="text-xs text-tinta-500 w-32 shrink-0">{{ b.nombre }}</span>
                                            <input v-model.number="v.stock_inicial[b.id]" type="number" min="0" step="0.001"
                                                class="w-24 border border-linea rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:border-[var(--marca)] bg-superficie" placeholder="0" />
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-if="Object.keys(formPadre.errors).length" class="bg-pastel-rojo border border-borde-aviso-rojo rounded-xl p-4">
                        <p class="text-sm font-semibold text-aviso-rojo mb-2">Corrige los siguientes errores:</p>
                        <ul class="list-disc list-inside space-y-1">
                            <li v-for="(msg, field) in formPadre.errors" :key="field" class="text-xs text-aviso-rojo">{{ msg }}</li>
                        </ul>
                    </div>

                    <div class="flex gap-3 pb-4">
                        <button type="button" @click="router.visit(`/productos/${p.id}`)" class="flex-1 py-3 rounded-xl border border-linea text-sm font-medium text-tinta-500 bg-superficie">Cancelar</button>
                        <button type="submit" :disabled="formPadre.processing" class="flex-1 py-3 rounded-xl text-sm font-medium text-white shadow-sm disabled:opacity-60" style="background-color:var(--marca);">
                            {{ formPadre.processing ? 'Guardando...' : 'Guardar cambios' }}
                        </button>
                    </div>
                </form>
            </div>

            <template v-else>

            <!-- Alerta cambios sin guardar -->
            <div v-if="hasChanges" class="mb-3 inline-flex items-center gap-2 px-3 py-1.5 rounded-xl text-xs font-semibold text-aviso-naranja" style="background:var(--pastel-ambar); border:1px solid #F59E0B;">
                ● Cambios sin guardar
            </div>

            <form @submit.prevent="submit" class="space-y-4">

                <!-- Badge tipo -->
                <div class="flex items-center gap-2">
                    <span class="px-2.5 py-1 rounded-full text-xs font-semibold" :style="badgeStyle[p.tipo]">
                        {{ p.tipo === 'producto' ? 'Producto' : 'Servicio' }}
                    </span>
                    <span class="font-mono text-xs text-tinta-300">{{ p.referencia }}</span>
                    <span v-if="p.valor_variante" class="text-xs font-semibold px-2.5 py-1 rounded-full" style="background:var(--pastel-violeta);color:var(--texto-violeta);">
                        Variante de: {{ p.padre?.nombre }} — {{ p.valor_variante }}
                    </span>
                </div>

                <!-- ═══ Información General ════════════════════════════════ -->
                <div class="bg-superficie rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-linea">
                        <h3 class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Información general</h3>
                    </div>
                    <div class="p-5 space-y-3">

                        <!-- Nombre + Referencia -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-medium text-tinta-700 mb-1">Nombre <span class="text-aviso-rojo">*</span></label>
                                <input v-model="form.nombre" type="text" :class="ic('nombre')" />
                                <p v-if="form.errors.nombre" class="mt-1 text-xs text-aviso-rojo">{{ form.errors.nombre }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-tinta-700 mb-1">Referencia <span class="text-aviso-rojo">*</span></label>
                                <input v-model="form.referencia" type="text" :class="ic('referencia')" />
                                <p v-if="form.errors.referencia" class="mt-1 text-xs text-aviso-rojo">{{ form.errors.referencia }}</p>
                            </div>
                        </div>

                        <!-- Categoría + Unidad -->
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
                                    :tipo="p.tipo === 'servicio' ? 'servicio' : 'producto'"
                                    :clase="ic('unidad_medida')"
                                />
                            </div>
                        </div>

                        <!-- Descripción corta -->
                                                
<div>
                            <div class="flex items-center justify-between mb-1">
                                <label class="block text-sm font-medium text-tinta-700">Descripción corta</label>
                                <div class="flex items-center gap-3">
                                    <!-- Dos cosas distintas: «Redactar» escribe una frase suelta;
                                         «Ficha técnica» arma la ficha completa y llena los dos
                                         campos a partir de los datos técnicos en bruto. -->
                                    <GeneradorFichaIa :datos="datosParaFicha" @usar="aplicarFicha" />
                                    <button type="button" @click="generarDescripcion('corta')" :disabled="iaCargando"
                                        class="text-xs font-semibold text-[var(--marca)] hover:underline disabled:opacity-50">
                                        {{ iaCargando ? 'Redactando…' : 'Redactar' }}
                                    </button>
                                    <span class="text-xs" :class="(form.descripcion_corta||'').length > 900 ? 'text-aviso-ambar font-semibold' : 'text-tinta-300'">
                                        {{ (form.descripcion_corta||'').length }}/1000
                                    </span>
                                </div>
                            </div>
                            <textarea v-model="form.descripcion_corta" rows="2" maxlength="1000" :class="ic('descripcion_corta')" />
                            <p v-if="iaError" class="mt-1 text-xs text-aviso-rojo">{{ iaError }}</p>
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
                            <EditorTexto v-model="form.descripcion_larga" :maxLength="10000" />
                        </div>
                    </div>
                </div>

                <!-- ═══ Imágenes ═══════════════════════════════════════════ -->
                <div class="bg-superficie rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-linea">
                        <h3 class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Imágenes</h3>
                    </div>
                    <div class="p-5">
                        <!-- Existentes -->
                        <div v-if="imagenesExistentes.length" class="grid grid-cols-3 sm:grid-cols-5 gap-2 mb-3">
                            <div v-for="img in imagenesExistentes" :key="img.id" class="relative rounded-xl overflow-hidden border-2" :style="img.es_principal ? 'border-color:#F59E0B;' : 'border-color:var(--borde);'">
                                <img :src="img.url" class="w-full aspect-square object-cover" />
                                <div class="absolute top-1 right-1 flex gap-1">
                                    <button type="button" @click="setPrincipal(img.id)" class="w-5 h-5 rounded-full flex items-center justify-center" :style="img.es_principal ? 'background:#F59E0B;' : 'background:rgba(0,0,0,0.4);'">
                                        <svg class="w-2.5 h-2.5 text-white" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                                    </button>
                                    <button type="button" @click="eliminarImagenExistente(img.id)" class="w-5 h-5 rounded-full bg-red-500/80 flex items-center justify-center">
                                        <svg class="w-2.5 h-2.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <label class="block border-2 border-dashed border-linea rounded-xl p-4 text-center cursor-pointer hover:border-tinta-200 transition-colors mb-2">
                            <p class="text-sm text-tinta-300">+ Agregar más imágenes</p>
                            <p class="text-xs text-tinta-300 mt-0.5">Recomendado: <strong>1000 × 1000 px</strong> (cuadradas), máx. 5 MB. Se recortan al centro.</p>
                            <input type="file" multiple accept="image/*" class="hidden" @change="onImagenes" />
                        </label>
                        <div v-if="nuevasPreviews.length" class="grid grid-cols-3 sm:grid-cols-5 gap-2">
                            <div v-for="(prev, i) in nuevasPreviews" :key="i" class="relative rounded-xl overflow-hidden border-2 border-dashed border-borde-aviso-azul">
                                <img :src="prev.url" class="w-full aspect-square object-cover" />
                                <button type="button" @click="eliminarNueva(i)" class="absolute top-1 right-1 w-5 h-5 rounded-full bg-red-500/80 flex items-center justify-center">
                                    <svg class="w-2.5 h-2.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ═══ Clasificación ══════════════════════════════════════ -->
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
                        <!-- Proveedores. Antes era un solo selector: alcanzaba para saber a
                             quién se le compró la última vez, no para comparar antes de
                             comprar — eso se hacía en un cuaderno. -->
                        <div v-if="form.es_insumo" class="pt-2 border-t border-linea">
                            <p class="text-xs font-medium text-tinta-500 mb-1">Proveedores y precios</p>
                            <p class="text-xs text-tinta-300 mb-2">
                                Carga los que lo venden y compara. El preferido es el que queda en
                                las órdenes de compra.
                            </p>
                            <ProveedoresProducto
                                :filas="form.proveedores_precios"
                                :proveedores="props.proveedores ?? []"
                            />
                        </div>
                    </div>
                </div>

                <!-- ═══ Inventario (solo producto) ═══════════════════════ -->
                <div v-if="p.tipo === 'producto'" class="bg-superficie rounded-2xl shadow-sm overflow-hidden">
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
                        <!-- Stock actual por bodega (solo lectura) -->
                        <div v-if="stocksPorBodega.length">
                            <div class="flex items-center justify-between mb-1.5">
                                <p class="text-xs font-medium text-tinta-500">Stock actual por bodega</p>
                                <a :href="`/productos/${p.id}`" class="text-xs text-aviso-azul font-medium hover:underline">
                                    Ver detalle / Ajustar stock →
                                </a>
                            </div>
                            <div class="divide-y divide-separador border border-linea rounded-xl overflow-hidden">
                                <div v-for="s in stocksPorBodega" :key="s.bodega_id"
                                    class="flex items-center justify-between px-3 py-2">
                                    <span class="text-xs text-tinta-500">{{ s.bodega_nombre }}</span>
                                    <span class="text-xs font-semibold text-tinta-900">{{ s.cantidad }} {{ p.unidad_medida }}</span>
                                </div>
                            </div>
                        </div>
                        <div v-else class="text-xs text-tinta-300 text-center py-2">
                            Sin stock registrado.
                            <a :href="`/productos/${p.id}`" class="text-aviso-azul font-medium hover:underline ml-1">Ajustar desde la vista de detalle →</a>
                        </div>
                    </div>
                </div>

                <!-- ═══ Precios y comisiones por canal ══════════════════════ -->
                <!-- El mismo componente que usa la pantalla de crear. Antes aquí había tres
                     cajas fijas —mayorista, distribuidor, cliente final— que escribían solo
                     las columnas antiguas: un producto con cuatro canales quedaba con datos a
                     medias al guardarlo desde aquí, y el cuarto canal no tenía forma de
                     llenarse. Esa diferencia entre las dos pantallas era el problema de
                     fondo. -->
                <PreciosPorCanal
                    :canales="form.canales"
                    v-model:precio-costo="form.precio_costo"
                />

                <!-- Error de servidor -->
                <div v-if="$page.props.errors?.error" class="bg-pastel-rojo border border-borde-aviso-rojo rounded-xl p-4">
                    <p class="text-sm font-semibold text-aviso-rojo">Error al guardar:</p>
                    <p class="text-xs text-aviso-rojo mt-1">{{ $page.props.errors.error }}</p>
                </div>

                <!-- Errores de validación -->
                <div v-if="Object.keys(form.errors).length" class="bg-pastel-rojo border border-borde-aviso-rojo rounded-xl p-4">
                    <p class="text-sm font-semibold text-aviso-rojo mb-2">Corrige los siguientes errores:</p>
                    <ul class="list-disc list-inside space-y-1">
                        <li v-for="(msg, field) in form.errors" :key="field" class="text-xs text-aviso-rojo">{{ msg }}</li>
                    </ul>
                </div>

                <!-- ── Botones ─────────────────────────────────────────────── -->
                <div class="flex gap-3 pb-4">
                    <button type="button" @click="router.visit(`/productos/${p.id}`)" class="flex-1 py-3 rounded-xl border border-linea text-sm font-medium text-tinta-500 bg-superficie">Cancelar</button>
                    <button type="submit" :disabled="form.processing" class="flex-1 py-3 rounded-xl text-sm font-medium text-white shadow-sm disabled:opacity-60" style="background-color:var(--marca);">
                        {{ form.processing ? 'Guardando...' : 'Guardar cambios' }}
                    </button>
                </div>
            </form>

            </template>
        </div>

        <!-- ── Modal nueva categoría ─────────────────────────────────────── -->
        <div v-if="showModalCat"
             class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-4"
             style="background:rgba(0,0,0,0.5);">
            <div class="bg-superficie rounded-2xl shadow-xl w-full max-w-sm p-5">
                <h3 class="text-base font-semibold text-tinta-900 mb-4">Nueva categoría</h3>
                <div class="space-y-4">

                    <!-- Nombre -->
                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 mb-1">Nombre</label>
                        <input
                            type="text"
                            :value="nuevaCat.nombre"
                            @input="nuevaCat.nombre = $event.target.value"
                            placeholder="Ej: Puertas Refrigeradas"
                            class="w-full border border-linea rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-[var(--marca)] focus:ring-2 focus:ring-blue-100"
                        />
                    </div>

                    <!-- Color -->
                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 mb-2">Color</label>
                        <div class="flex flex-wrap gap-2 mb-3">
                            <button
                                v-for="c in coloresPaleta"
                                :key="c"
                                type="button"
                                @click.stop="nuevaCat.color = c"
                                class="w-8 h-8 rounded-full border-2 transition-all shrink-0"
                                :style="'background-color:' + c"
                                :class="nuevaCat.color === c
                                    ? 'border-gray-900 scale-110'
                                    : 'border-transparent hover:border-gray-400'"
                            />
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-lg border border-linea shrink-0"
                                 :style="'background-color:' + nuevaCat.color"></div>
                            <input
                                type="text"
                                :value="nuevaCat.color"
                                @input="nuevaCat.color = $event.target.value"
                                placeholder="#2563EB"
                                maxlength="7"
                                class="flex-1 border border-linea rounded-lg px-3 py-1.5 text-sm font-mono focus:ring-2 focus:ring-blue-100 focus:border-[var(--marca)]"
                            />
                        </div>
                    </div>

                </div>

                <!-- Botones -->
                <div class="flex gap-3 mt-5">
                    <button
                        type="button"
                        @click.stop="showModalCat = false; nuevaCat.nombre = ''; nuevaCat.color = colorMarca()"
                        class="flex-1 py-2.5 rounded-xl border border-linea text-sm font-medium text-tinta-500 bg-superficie">
                        Cancelar
                    </button>
                    <button
                        type="button"
                        @click.stop="crearCategoria"
                        :disabled="guardandoCat || !nuevaCat.nombre.trim()"
                        class="flex-1 py-2.5 rounded-xl text-white text-sm font-medium disabled:opacity-60"
                        style="background-color:var(--marca);">
                        {{ guardandoCat ? 'Creando...' : 'Crear' }}
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
