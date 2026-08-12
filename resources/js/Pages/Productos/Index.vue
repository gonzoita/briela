<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import { router } from '@inertiajs/vue3'
import { reactive, ref, computed, watch } from 'vue'
import { colorMarca } from '@/marca'

const props = defineProps({
    productos:  Object,
    categorias: Array,
    filters:    Object,
})

const form = reactive({
    buscar:      props.filters?.buscar      ?? '',
    tipo:        props.filters?.tipo        ?? '',
    categoria:   props.filters?.categoria   ?? '',
    es_vendible: props.filters?.es_vendible ?? '',
    es_insumo:   props.filters?.es_insumo   ?? '',
})

const mostrarFiltros   = ref(false)
const mostrarMenuNuevo = ref(false)

// ── Copia local editable de la lista — nunca se muta la prop directamente.
// Se resincroniza cada vez que cambian los productos que llegan del server
// (filtros, paginación) para no quedar con datos viejos.
const productosLocal = ref([])
watch(() => props.productos, (val) => {
    productosLocal.value = (val?.data ?? []).map(p => ({
        ...p,
        variantes: (p.variantes ?? []).map(v => ({ ...v })),
    }))
}, { immediate: true })

// ── Editar precio de costo directo desde el listado ────────────────────────────
const guardandoCosto = ref(new Set())

async function guardarCosto(item) {
    if (item.es_padre) return
    const valor = Number(item.precio_costo)
    if (Number.isNaN(valor) || valor < 0) return

    guardandoCosto.value = new Set([...guardandoCosto.value, item.id])
    try {
        const res = await fetch(`/productos/${item.id}/precio-costo`, {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ precio_costo: valor }),
        })
        const data = await res.json().catch(() => null)
        if (!res.ok) throw new Error(data?.message || `Error del servidor (${res.status})`)
        item.precio_costo = data.precio_costo
    } catch (e) {
        alert(e.message || 'No se pudo guardar el costo.')
    } finally {
        const s = new Set(guardandoCosto.value)
        s.delete(item.id)
        guardandoCosto.value = s
    }
}

// ── Expandir/colapsar padres con variantes ─────────────────────────────────────
const padresExpandidos = ref(new Set())
const toggleExpandido = (id) => {
    const set = new Set(padresExpandidos.value)
    set.has(id) ? set.delete(id) : set.add(id)
    padresExpandidos.value = set
}

// ── Toggle vista lista/grid ────────────────────────────────────────────────────
const viewMode = ref(localStorage.getItem('productos_view') ?? 'list')
watch(viewMode, (v) => localStorage.setItem('productos_view', v))

const filtrar = () => {
    router.get('/productos', form, { preserveState: true, replace: true })
}

const limpiar = () => {
    form.buscar      = ''
    form.tipo        = ''
    form.categoria   = ''
    form.es_vendible = ''
    form.es_insumo   = ''
    filtrar()
}

const hayFiltros = computed(() => form.buscar || form.tipo || form.categoria || form.es_vendible || form.es_insumo)

// ── Categorías (local para actualizar chips sin recargar) ──────────────────────
const categoriasLocal = ref([...(props.categorias ?? [])])

const categoriasUnicas = computed(() => {
    const vistas = new Set()
    return categoriasLocal.value.filter(c => {
        if (vistas.has(c.id)) return false
        vistas.add(c.id)
        return true
    })
})

// ── Modal gestión de categorías ───────────────────────────────────────────────
const modalCategorias = ref(false)
const catLista        = ref([])
const catCargando     = ref(false)
const catGuardando    = ref(false)
const catError        = ref('')
const catEditando     = ref(null)   // null=cerrado | 'nueva' | id numérico
const catForm         = reactive({ nombre: '', color: colorMarca() })

const csrf = () => { const c = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN=')); return c ? decodeURIComponent(c.split('=')[1]) : '' }

async function abrirModalCategorias() {
    modalCategorias.value = true
    catEditando.value     = null
    catError.value        = ''
    await cargarCategorias()
}

async function cargarCategorias() {
    catCargando.value = true
    try {
        const res = await fetch('/api/categorias-producto', { headers: { Accept: 'application/json' } })
        catLista.value        = await res.json()
        categoriasLocal.value = [...catLista.value]
    } catch {
        catError.value = 'Error al cargar categorías.'
    } finally {
        catCargando.value = false
    }
}

function abrirNueva() {
    catEditando.value = 'nueva'
    catForm.nombre    = ''
    catForm.color     = colorMarca()
    catError.value    = ''
}

function abrirEditar(cat) {
    catEditando.value = cat.id
    catForm.nombre    = cat.nombre
    catForm.color     = cat.color ?? colorMarca()
    catError.value    = ''
}

function cancelarForm() {
    catEditando.value = null
    catError.value    = ''
}

async function guardarCategoria() {
    if (!catForm.nombre.trim()) { catError.value = 'El nombre es requerido.'; return }
    catGuardando.value = true
    catError.value     = ''
    try {
        const esNueva = catEditando.value === 'nueva'
        const url     = esNueva ? '/api/categorias-producto' : `/api/categorias-producto/${catEditando.value}`
        const res  = await fetch(url, {
            method:  esNueva ? 'POST' : 'PUT',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body:    JSON.stringify({ nombre: catForm.nombre.trim(), color: catForm.color }),
        })
        const data = await res.json()
        if (!res.ok) { catError.value = data.message ?? 'Error al guardar.'; return }

        if (esNueva) {
            catLista.value.push(data)
        } else {
            const idx = catLista.value.findIndex(c => c.id === catEditando.value)
            if (idx >= 0) catLista.value[idx] = data
        }
        catLista.value = [...catLista.value].sort((a, b) => a.nombre.localeCompare(b.nombre))
        categoriasLocal.value = [...catLista.value]
        catEditando.value = null
    } catch {
        catError.value = 'Error de conexión.'
    } finally {
        catGuardando.value = false
    }
}

async function eliminarCategoria(cat) {
    if (!confirm(`¿Eliminar la categoría "${cat.nombre}"?`)) return
    catError.value = ''
    try {
        const res  = await fetch(`/api/categorias-producto/${cat.id}`, {
            method:  'DELETE',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
        const data = await res.json()
        if (!res.ok) { catError.value = data.message ?? 'No se pudo eliminar.'; return }
        catLista.value        = catLista.value.filter(c => c.id !== cat.id)
        categoriasLocal.value = [...catLista.value]
    } catch {
        catError.value = 'Error de conexión.'
    }
}

// ── Formato ────────────────────────────────────────────────────────────────────
const formatCOP = (v) =>
    new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', minimumFractionDigits: 0 }).format(v ?? 0)

const badgeStyle = (color) => {
    const map = {
        blue:   { bg: '#DBEAFE', text: '#1D4ED8' },
        green:  { bg: '#D1FAE5', text: '#065F46' },
        orange: { bg: '#FED7AA', text: '#C2410C' },
        gray:   { bg: '#F3F4F6', text: '#374151' },
    }
    return map[color] ?? map.gray
}

const tiposOpciones = [
    { value: '',         label: 'Todos' },
    { value: 'producto', label: 'Productos' },
    { value: 'servicio', label: 'Servicios' },
]

const precioMostrar = (p) => {
    if (p.precio_cliente_final > 0) return p.precio_cliente_final
    if (p.precio_mayorista > 0) return p.precio_mayorista
    return p.precio_costo
}
</script>

<template>
    <AppLayout title="Productos">

        <!-- ── Cabecera ──────────────────────────────────────────────────────── -->
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-base font-semibold text-tinta-900">Catálogo de productos</h2>
            <div class="flex items-center gap-2">

                <!-- Botón Categorías -->
                <button
                    @click="abrirModalCategorias"
                    class="flex items-center gap-1.5 px-3 py-2 rounded-xl text-sm font-medium border border-linea bg-superficie text-tinta-500 shadow-sm hover:bg-tinta-50 transition-colors"
                >
                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                    </svg>
                    <span class="hidden sm:inline">Categorías</span>
                </button>

                <!-- Toggle lista/grid -->
                <div class="flex items-center rounded-xl border border-linea bg-superficie overflow-hidden shadow-sm">
                    <button
                        @click="viewMode = 'list'"
                        class="w-9 h-9 flex items-center justify-center transition-colors"
                        :style="viewMode === 'list' ? 'background:var(--marca);color:var(--marca-texto);' : 'color:var(--texto-3);'"
                        title="Vista lista"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 10h16M4 14h16M4 18h16"/>
                        </svg>
                    </button>
                    <button
                        @click="viewMode = 'grid'"
                        class="w-9 h-9 flex items-center justify-center transition-colors"
                        :style="viewMode === 'grid' ? 'background:var(--marca);color:var(--marca-texto);' : 'color:var(--texto-3);'"
                        title="Vista grid"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 5a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1V5zm10 0a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1V5zM4 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1v-4zm10 0a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z"/>
                        </svg>
                    </button>
                </div>

                <!-- Botón Nuevo -->
                <div class="relative">
                    <button
                        @click="mostrarMenuNuevo = !mostrarMenuNuevo"
                        class="flex items-center gap-1.5 px-3 py-2 rounded-xl text-sm font-medium text-white shadow-sm"
                        style="background-color: var(--marca);"
                    >
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                        </svg>
                        Nuevo
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>
                    <div
                        v-if="mostrarMenuNuevo"
                        class="absolute right-0 top-full mt-1 bg-superficie rounded-xl shadow-lg border border-linea z-30 min-w-[160px] overflow-hidden"
                    >
                        <button
                            v-for="opt in [
                                { tipo: 'producto', label: '+ Producto' },
                                { tipo: 'servicio', label: '+ Servicio' },
                            ]"
                            :key="opt.tipo"
                            @click="router.visit(`/productos/crear?tipo=${opt.tipo}`); mostrarMenuNuevo = false"
                            class="w-full text-left px-4 py-2.5 text-sm text-tinta-700 hover:bg-tinta-50"
                        >
                            {{ opt.label }}
                        </button>
                        <button
                            @click="router.visit('/productos/importar'); mostrarMenuNuevo = false"
                            class="w-full text-left px-4 py-2.5 text-sm text-tinta-700 hover:bg-tinta-50 border-t border-linea"
                        >
                            Importar CSV
                        </button>
                    </div>
                    <div v-if="mostrarMenuNuevo" class="fixed inset-0 z-20" @click="mostrarMenuNuevo = false"/>
                </div>

            </div>
        </div>

        <!-- ── Filtros tipo pill ───────────────────────────────────────────── -->
        <div class="flex gap-2 overflow-x-auto pb-1 mb-3 scrollbar-hide">
            <button
                v-for="opt in tiposOpciones"
                :key="opt.value"
                @click="form.tipo = opt.value; filtrar()"
                class="px-3.5 py-1.5 rounded-full text-sm font-medium whitespace-nowrap border transition-colors shrink-0"
                :style="form.tipo === opt.value
                    ? 'background-color: var(--marca); color: white; border-color: var(--marca);'
                    : 'background: var(--superficie); color: var(--texto-2); border-color: var(--borde);'"
            >
                {{ opt.label }}
            </button>
        </div>

        <!-- ── Filtros Vendible / Insumo ──────────────────────────────────── -->
        <div class="flex gap-2 overflow-x-auto pb-1 mb-3 scrollbar-hide">
            <button
                @click="form.es_vendible = form.es_vendible ? '' : '1'; form.es_insumo = ''; filtrar()"
                class="flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-medium whitespace-nowrap border transition-colors shrink-0"
                :style="form.es_vendible
                    ? 'background:var(--pastel-azul-2); color:var(--texto-azul); border-color:#BFDBFE;'
                    : 'background:var(--superficie); color:var(--texto-2); border-color:var(--borde);'"
            >
                <span>Vendible</span>
            </button>
            <button
                @click="form.es_insumo = form.es_insumo ? '' : '1'; form.es_vendible = ''; filtrar()"
                class="flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-medium whitespace-nowrap border transition-colors shrink-0"
                :style="form.es_insumo
                    ? 'background:var(--pastel-ambar); color:var(--texto-ambar); border-color:#FDE68A;'
                    : 'background:var(--superficie); color:var(--texto-2); border-color:var(--borde);'"
            >
                <span>Insumo</span>
            </button>
        </div>

        <!-- ── Filtro por categoría ────────────────────────────────────────── -->
        <div v-if="categoriasUnicas.length" class="flex gap-2 overflow-x-auto pb-1 mb-3 scrollbar-hide">
            <button
                @click="form.categoria = ''; filtrar()"
                class="px-3 py-1 rounded-full text-xs font-medium whitespace-nowrap border transition-colors shrink-0"
                :style="!form.categoria
                    ? 'background-color: var(--marca); color: var(--marca-texto); border-color: var(--marca);'
                    : 'background: var(--superficie); color: var(--texto-2); border-color: var(--borde);'"
            >
                Todas
            </button>
            <button
                v-for="cat in categoriasUnicas"
                :key="cat.id"
                @click="form.categoria = String(cat.id); filtrar()"
                class="px-3 py-1 rounded-full text-xs font-medium whitespace-nowrap border transition-colors shrink-0"
                :style="String(form.categoria) === String(cat.id)
                    ? `background-color: ${cat.color}; color: white; border-color: ${cat.color};`
                    : `background: var(--superficie); color: ${cat.color}; border-color: ${cat.color}44;`"
            >
                {{ cat.nombre }}
            </button>
        </div>

        <!-- ── Barra de búsqueda ───────────────────────────────────────────── -->
        <div class="sticky z-20 -mx-4 px-4 py-2 mb-4" style="top: 56px; background: var(--superficie-2);">
            <div class="flex items-center gap-2">
                <div class="flex-1 relative">
                    <input
                        v-model="form.buscar"
                        type="text"
                        placeholder="Buscar por nombre o referencia..."
                        class="w-full rounded-xl border border-linea pl-9 pr-3 py-2.5 text-sm bg-superficie focus:outline-none"
                        @keyup.enter="filtrar"
                    />
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-tinta-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                </div>
                <button
                    v-if="hayFiltros"
                    @click="limpiar"
                    class="px-3 py-2.5 rounded-xl text-xs text-tinta-400 border border-linea bg-superficie whitespace-nowrap"
                >
                    Limpiar
                </button>
            </div>
        </div>

        <!-- ── Sin resultados ────────────────────────────────────────────── -->
        <div v-if="!productosLocal?.length" class="text-center text-tinta-300 text-sm py-16">
            No se encontraron productos.
        </div>

        <!-- ── VISTA LISTA ────────────────────────────────────────────────── -->
        <div v-else-if="viewMode === 'list'" class="bg-superficie rounded-2xl shadow-sm overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr style="background:var(--superficie-2); border-bottom:1px solid var(--borde);">
                        <th class="text-left px-4 py-3 text-xs font-semibold text-tinta-400 uppercase w-12"></th>
                        <th class="text-left px-4 py-3 text-xs font-semibold text-tinta-400 uppercase">Nombre</th>
                        <th class="hidden sm:table-cell text-left px-3 py-3 text-xs font-semibold text-tinta-400 uppercase">Referencia</th>
                        <th class="hidden md:table-cell text-left px-3 py-3 text-xs font-semibold text-tinta-400 uppercase">Categoría</th>
                        <th class="hidden lg:table-cell text-right px-3 py-3 text-xs font-semibold text-tinta-400 uppercase">Costo</th>
                        <th class="text-right px-4 py-3 text-xs font-semibold text-tinta-400 uppercase">Precio</th>
                        <th class="hidden sm:table-cell text-right px-4 py-3 text-xs font-semibold text-tinta-400 uppercase">Stock</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <template v-for="p in productosLocal" :key="p.id">
                    <tr
                        class="cursor-pointer transition-colors hover:bg-blue-50/40"
                        @click="p.es_padre ? toggleExpandido(p.id) : router.visit(`/productos/${p.id}`)"
                    >
                        <!-- Imagen / chevron -->
                        <td class="px-4 py-2.5">
                            <div v-if="p.es_padre" class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0" style="background:var(--superficie-2);">
                                <svg class="w-4 h-4 text-tinta-400 transition-transform"
                                    :style="padresExpandidos.has(p.id) ? 'transform:rotate(90deg);' : ''"
                                    fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                                </svg>
                            </div>
                            <div v-else class="w-10 h-10 rounded-xl overflow-hidden shrink-0" style="background:var(--superficie-2);">
                                <img v-if="p.imagen_url" :src="p.imagen_url" :alt="p.nombre" class="w-full h-full object-cover"/>
                                <div v-else class="w-full h-full flex items-center justify-center text-white text-xs font-semibold" style="background:var(--marca);">
                                    {{ p.nombre.charAt(0).toUpperCase() }}
                                </div>
                            </div>
                        </td>
                        <!-- Nombre + tipo + chips vendible/insumo -->
                        <td class="px-4 py-2.5">
                            <p class="font-semibold text-tinta-900 text-sm truncate max-w-xs">{{ p.nombre }}</p>
                            <div class="flex flex-wrap gap-1 mt-0.5">
                                <span class="text-xs font-medium px-1.5 py-0.5 rounded-full"
                                    :style="{ background: badgeStyle(p.tipo_color).bg, color: badgeStyle(p.tipo_color).text }">
                                    {{ p.tipo_label }}
                                </span>
                                <span v-if="p.es_padre" class="text-xs font-medium px-1.5 py-0.5 rounded-full" style="background:var(--pastel-violeta);color:var(--texto-violeta);">
                                    {{ p.variantes?.length ?? 0 }} variante{{ (p.variantes?.length ?? 0) === 1 ? '' : 's' }}
                                </span>
                                <span v-if="p.es_vendible" class="text-xs font-medium px-1.5 py-0.5 rounded-full" style="background:var(--pastel-azul-2);color:var(--texto-azul);">
                                    Vendible
                                </span>
                                <span v-if="p.es_insumo" class="text-xs font-medium px-1.5 py-0.5 rounded-full" style="background:var(--pastel-ambar);color:var(--texto-ambar);">
                                    Insumo
                                </span>
                                <button v-if="p.es_padre" @click.stop="router.visit(`/productos/${p.id}/editar`)"
                                    class="text-xs font-medium text-blue-600 hover:text-blue-800 hover:underline">
                                    Editar
                                </button>
                            </div>
                        </td>
                        <!-- Referencia -->
                        <td class="hidden sm:table-cell px-3 py-2.5 text-xs text-tinta-300 font-mono">{{ p.referencia }}</td>
                        <!-- Categoría -->
                        <td class="hidden md:table-cell px-3 py-2.5">
                            <span v-if="p.categoria_nombre"
                                class="text-xs font-medium px-2 py-0.5 rounded-full"
                                :style="`background:${p.categoria_color}22;color:${p.categoria_color};`">
                                {{ p.categoria_nombre }}
                            </span>
                        </td>
                        <!-- Costo (editable) -->
                        <td class="hidden lg:table-cell px-3 py-2.5 text-right" @click.stop>
                            <span v-if="p.es_padre" class="text-xs text-tinta-200">—</span>
                            <template v-else>
                                <input type="number" min="0" step="1" v-model.number="p.precio_costo"
                                    @blur="guardarCosto(p)" @keyup.enter="$event.target.blur()"
                                    class="w-24 text-right text-xs border border-linea rounded-lg px-2 py-1 focus:outline-none focus:ring-2 focus:ring-blue-300" />
                                <span v-if="guardandoCosto.has(p.id)" class="block text-[10px] text-tinta-300 mt-0.5">guardando...</span>
                            </template>
                        </td>
                        <!-- Precio cliente final -->
                        <td class="px-4 py-2.5 text-right font-semibold text-sm" style="color:var(--marca);">
                            {{ p.es_padre ? '—' : formatCOP(precioMostrar(p)) }}
                        </td>
                        <!-- Stock -->
                        <td class="hidden sm:table-cell px-4 py-2.5 text-right">
                            <template v-if="p.es_padre">
                                <span class="text-xs font-medium text-tinta-400">{{ p.stock_total }}</span>
                            </template>
                            <template v-else-if="p.inventariable">
                                <span class="text-xs font-medium" :style="p.stock_total <= p.stock_minimo ? 'color:#ef4444;' : 'color:#10b981;'">
                                    {{ p.stock_total }}
                                </span>
                                <span v-if="p.stock_total <= p.stock_minimo" class="text-xs text-red-400 ml-1">↓</span>
                            </template>
                            <span v-else class="text-xs text-tinta-200">—</span>
                        </td>
                    </tr>
                    <!-- Filas de variantes (indentadas) -->
                    <tr v-if="p.es_padre && padresExpandidos.has(p.id)" v-for="v in p.variantes" :key="`v-${v.id}`"
                        class="cursor-pointer transition-colors hover:bg-blue-50/40"
                        style="background:var(--superficie-2);"
                        @click="router.visit(`/productos/${v.id}`)"
                    >
                        <td></td>
                        <td class="px-4 py-2 pl-8">
                            <div class="flex items-center gap-2">
                                <span class="text-tinta-200">↳</span>
                                <span class="text-sm text-tinta-700">{{ v.nombre }}</span>
                                <span class="text-xs font-medium px-1.5 py-0.5 rounded-full" style="background:var(--pastel-violeta);color:var(--texto-violeta);">
                                    {{ v.valor_variante }}
                                </span>
                            </div>
                        </td>
                        <td class="hidden sm:table-cell px-3 py-2 text-xs text-tinta-300 font-mono">{{ v.referencia }}</td>
                        <td class="hidden md:table-cell px-3 py-2"></td>
                        <!-- Costo (editable) -->
                        <td class="hidden lg:table-cell px-3 py-2 text-right" @click.stop>
                            <input type="number" min="0" step="1" v-model.number="v.precio_costo"
                                @blur="guardarCosto(v)" @keyup.enter="$event.target.blur()"
                                class="w-24 text-right text-xs border border-linea rounded-lg px-2 py-1 focus:outline-none focus:ring-2 focus:ring-blue-300" />
                            <span v-if="guardandoCosto.has(v.id)" class="block text-[10px] text-tinta-300 mt-0.5">guardando...</span>
                        </td>
                        <td class="px-4 py-2 text-right text-xs text-tinta-200">
                            {{ formatCOP(precioMostrar(v)) }}
                        </td>
                        <td class="hidden sm:table-cell px-4 py-2 text-right">
                            <span class="text-xs font-medium text-green-600">{{ v.stock_total }}</span>
                        </td>
                    </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <!-- ── VISTA GRID ─────────────────────────────────────────────────── -->
        <div v-else class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <div
                v-for="p in productosLocal"
                :key="p.id"
                class="bg-superficie rounded-2xl shadow-sm overflow-hidden cursor-pointer active:scale-[0.98] transition-transform"
                @click="p.es_padre ? toggleExpandido(p.id) : router.visit(`/productos/${p.id}`)"
            >
                <div class="aspect-square relative overflow-hidden" style="background: var(--superficie-2);">
                    <img v-if="p.imagen_url" :src="p.imagen_url" :alt="p.nombre" class="w-full h-full object-cover"/>
                    <div v-else class="w-full h-full flex items-center justify-center">
                        <span class="text-3xl font-semibold text-white rounded-full flex items-center justify-center"
                            style="width: 56px; height: 56px; background-color: var(--marca);">
                            {{ p.nombre.charAt(0).toUpperCase() }}
                        </span>
                    </div>
                    <span class="absolute top-2 left-2 text-xs font-semibold px-2 py-0.5 rounded-full"
                        :style="{ background: badgeStyle(p.tipo_color).bg, color: badgeStyle(p.tipo_color).text }">
                        {{ p.tipo_label }}
                    </span>
                </div>
                <div class="p-3">
                    <p class="text-sm font-semibold text-tinta-900 leading-tight line-clamp-2 mb-1">{{ p.nombre }}</p>
                    <p class="text-xs text-tinta-300 font-mono mb-2">{{ p.referencia }}</p>
                    <span v-if="p.categoria_nombre" class="inline-block text-xs font-medium px-2 py-0.5 rounded-full mb-2"
                        :style="`background-color: ${p.categoria_color}22; color: ${p.categoria_color};`">
                        {{ p.categoria_nombre }}
                    </span>
                    <template v-if="p.es_padre">
                        <span class="inline-block text-xs font-semibold px-2 py-0.5 rounded-full" style="background:var(--pastel-violeta);color:var(--texto-violeta);">
                            {{ p.variantes?.length ?? 0 }} variante{{ (p.variantes?.length ?? 0) === 1 ? '' : 's' }}
                        </span>
                        <p class="text-xs text-tinta-300 mt-1">Toca para ver variantes</p>
                        <button @click.stop="router.visit(`/productos/${p.id}/editar`)"
                            class="mt-1 text-xs font-medium text-blue-600 hover:text-blue-800 hover:underline">
                            Editar producto padre
                        </button>
                    </template>
                    <template v-else>
                        <p class="text-sm font-semibold" style="color: var(--marca);">{{ formatCOP(precioMostrar(p)) }}</p>
                        <div class="flex items-center gap-1 mt-1" @click.stop>
                            <span class="text-xs text-tinta-300">Costo:</span>
                            <input type="number" min="0" step="1" v-model.number="p.precio_costo"
                                @blur="guardarCosto(p)" @keyup.enter="$event.target.blur()"
                                class="w-20 text-xs border border-linea rounded-lg px-1.5 py-0.5 focus:outline-none focus:ring-2 focus:ring-blue-300" />
                            <span v-if="guardandoCosto.has(p.id)" class="text-[10px] text-tinta-300">...</span>
                        </div>
                        <div v-if="p.inventariable" class="flex items-center gap-1 mt-1">
                            <div class="w-2 h-2 rounded-full"
                                :style="p.stock_total <= p.stock_minimo ? 'background: #ef4444;' : 'background: #10b981;'"/>
                            <span class="text-xs text-tinta-400">
                                {{ p.stock_total }} en stock
                                <span v-if="p.stock_total <= p.stock_minimo" class="text-red-500 font-semibold"> (bajo mínimo)</span>
                            </span>
                        </div>
                    </template>
                </div>
                <!-- Variantes expandidas (grid) -->
                <div v-if="p.es_padre && padresExpandidos.has(p.id)" class="border-t border-linea divide-y divide-gray-50">
                    <div v-for="v in p.variantes" :key="v.id"
                        class="flex items-center justify-between px-3 py-2 hover:bg-blue-50/40"
                        @click.stop="router.visit(`/productos/${v.id}`)">
                        <span class="text-xs text-tinta-700">{{ v.valor_variante }}</span>
                        <span class="text-xs font-medium text-green-600">{{ v.stock_total }}</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Paginación ──────────────────────────────────────────────────── -->
        <div v-if="productos.last_page > 1" class="flex items-center justify-between mt-6 px-1">
            <p class="text-xs text-tinta-300">{{ productos.from }}–{{ productos.to }} de {{ productos.total }}</p>
            <div class="flex gap-1">
                <template v-for="link in productos.links" :key="link.label">
                    <button
                        v-if="link.url"
                        @click="router.visit(link.url)"
                        v-html="link.label"
                        class="px-3 py-1.5 rounded-lg text-sm"
                        :style="link.active ? 'background-color: var(--marca); color: white;' : 'color: var(--texto-2);'"
                    />
                </template>
            </div>
        </div>

    </AppLayout>

    <!-- ── Modal Gestión de Categorías ───────────────────────────────────────── -->
    <Teleport to="body">
        <div v-if="modalCategorias" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
            <!-- Backdrop -->
            <div class="absolute inset-0 bg-black/40" @click="modalCategorias = false" />

            <!-- Panel -->
            <div class="relative w-full sm:max-w-lg bg-superficie rounded-t-3xl sm:rounded-2xl shadow-2xl flex flex-col max-h-[90vh]">

                <!-- Header -->
                <div class="flex items-center justify-between px-5 py-4 border-b border-linea shrink-0">
                    <h3 class="text-base font-semibold text-tinta-900">Categorías de producto</h3>
                    <button @click="modalCategorias = false" class="w-8 h-8 flex items-center justify-center rounded-full text-tinta-300 hover:bg-tinta-100 hover:text-tinta-500 transition-colors text-lg">
                        ✕
                    </button>
                </div>

                <!-- Error banner -->
                <div v-if="catError" class="mx-5 mt-3 bg-red-50 border border-red-200 rounded-xl px-4 py-2.5 text-sm text-red-700 flex items-center justify-between shrink-0">
                    <span>{{ catError }}</span>
                    <button @click="catError = ''" class="text-red-400 hover:text-red-600 ml-3 shrink-0">✕</button>
                </div>

                <!-- Contenido scrollable -->
                <div class="overflow-y-auto flex-1 px-5 py-4 space-y-3">

                    <!-- Botón + Nueva / formulario inline -->
                    <div v-if="catEditando === null">
                        <button
                            @click="abrirNueva"
                            class="w-full py-2.5 rounded-xl border-2 border-dashed border-linea text-sm text-tinta-400 hover:border-blue-300 hover:text-blue-600 transition-colors"
                        >
                            + Nueva categoría
                        </button>
                    </div>

                    <div v-if="catEditando !== null" class="rounded-2xl p-4 space-y-3 border border-linea" style="background:var(--superficie-2);">
                        <p class="text-xs font-semibold text-tinta-400 uppercase tracking-wide">
                            {{ catEditando === 'nueva' ? 'Nueva categoría' : 'Editar categoría' }}
                        </p>
                        <div class="flex gap-3 items-center">
                            <input
                                v-model="catForm.nombre"
                                type="text"
                                placeholder="Nombre de la categoría..."
                                maxlength="100"
                                class="flex-1 border border-linea rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-[var(--marca)] bg-superficie"
                                @keyup.enter="guardarCategoria"
                                autofocus
                            />
                            <div class="flex flex-col items-center gap-0.5 shrink-0">
                                <input v-model="catForm.color" type="color"
                                    class="w-10 h-10 rounded-xl border border-linea cursor-pointer p-0.5 bg-superficie" />
                                <span class="text-xs text-tinta-300 font-mono">{{ catForm.color }}</span>
                            </div>
                        </div>
                        <!-- Preview del chip -->
                        <div class="flex items-center gap-2">
                            <span class="text-xs text-tinta-300">Vista previa:</span>
                            <span class="px-3 py-1 rounded-full text-xs font-medium"
                                :style="`background-color: ${catForm.color}22; color: ${catForm.color}; border: 1px solid ${catForm.color}44;`">
                                {{ catForm.nombre || 'Nombre' }}
                            </span>
                        </div>
                        <div class="flex gap-2 pt-1">
                            <button @click="cancelarForm"
                                class="flex-1 py-2 rounded-xl border border-linea text-xs text-tinta-500 hover:bg-tinta-100 transition-colors">
                                Cancelar
                            </button>
                            <button @click="guardarCategoria" :disabled="catGuardando"
                                class="flex-1 py-2 rounded-xl text-xs text-white font-semibold disabled:opacity-60 transition-colors"
                                style="background:var(--marca);">
                                {{ catGuardando ? '...' : (catEditando === 'nueva' ? 'Guardar' : 'Actualizar') }}
                            </button>
                        </div>
                    </div>

                    <!-- Lista -->
                    <div v-if="catCargando" class="text-center text-sm text-tinta-300 py-8">
                        Cargando...
                    </div>
                    <ul v-else class="divide-y divide-gray-50">
                        <li
                            v-for="cat in catLista"
                            :key="cat.id"
                            class="flex items-center gap-3 py-3"
                        >
                            <span class="w-3 h-3 rounded-full shrink-0 border border-black/10" :style="`background:${cat.color};`" />
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-tinta-900 truncate">{{ cat.nombre }}</p>
                                <p class="text-xs text-tinta-300">{{ cat.productos_count ?? 0 }} producto(s)</p>
                            </div>
                            <button @click="abrirEditar(cat)"
                                class="p-1.5 rounded-lg text-tinta-300 hover:text-blue-600 hover:bg-blue-50 transition-colors"
                                title="Editar">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                </svg>
                            </button>
                            <button @click="eliminarCategoria(cat)"
                                class="p-1.5 rounded-lg text-tinta-300 hover:text-red-600 hover:bg-red-50 transition-colors"
                                title="Eliminar">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                            </button>
                        </li>
                        <li v-if="!catLista.length && !catCargando" class="text-center text-sm text-tinta-300 py-8">
                            No hay categorías. Crea la primera.
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </Teleport>
</template>
