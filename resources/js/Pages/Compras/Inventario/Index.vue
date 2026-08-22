<script setup>
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import OrdenarLista from '@/Components/OrdenarLista.vue'
import { useOrden } from '@/composables/useOrden'

const props = defineProps({
    items:       Object,
    filters:     Object,
    proveedores: Array,
    bodegas:     Array,
    // Qué bodega se está mirando. 0 = todas las de la sede, que es como abre la pantalla.
    bodega_id:   { type: Number, default: 0 },
    // El orden vigente, que decide el servidor: { campo, dir }.
    orden: { type: Object, default: () => ({}) },
})

// Ordenar mantiene los filtros: reordenar no es empezar de cero.
const { ordenarPor } = useOrden('/compras/inventario', props.orden, props.filters)

const camposOrden = [
    { campo: 'nombre', etiqueta: 'Nombre' },
    { campo: 'referencia', etiqueta: 'Referencia' },
    { campo: 'precio_costo', etiqueta: 'Costo', texto: false },
    { campo: 'stock_minimo', etiqueta: 'Stock mínimo', texto: false },
    { campo: 'created_at', etiqueta: 'Más reciente', texto: false },
]

const buscar     = ref(props.filters?.buscar     ?? '')
const tipo       = ref(props.filters?.tipo       ?? '')
const bajo_stock = ref(props.filters?.bajo_stock === 'true')

const modalItem       = ref(false)
const editandoItem    = ref(null)
const guardando       = ref(false)
const panelMovimientos = ref(false)
const itemSeleccionado = ref(null)
const movimientos      = ref([])
const cargandoMov      = ref(false)

const modalAjuste = ref(false)
const itemAjuste  = ref(null)
const formAjuste  = ref({ bodega_id: '', tipo: 'entrada', cantidad: '', notas: '' })

const form = ref({
    nombre: '', referencia: '', descripcion_corta: '', unidad_medida: 'unidad',
    stock_minimo: 0, stock_maximo: '', proveedor_id: '', activo: true,
})

// Desde qué bodega se mira. Viaja en la URL y no en memoria: el enlace se puede compartir con
// quien está en esa bodega, el botón de atrás funciona y recargar no pierde lo elegido.
const bodegaVista = ref(props.bodega_id || '')

function aplicarFiltros() {
    router.get('/inventario', {
        buscar:     buscar.value || undefined,
        tipo:       tipo.value   || undefined,
        bajo_stock: bajo_stock.value ? 'true' : undefined,
        bodega_id:  bodegaVista.value || undefined,
    }, { preserveState: true, replace: true })
}

/** El nombre de la bodega mirada, o vacío cuando se ven todas. */
const nombreBodega = computed(() =>
    props.bodegas?.find(b => b.id === props.bodega_id)?.nombre ?? ''
)

function abrirCrear() {
    editandoItem.value = null
    form.value = { nombre: '', referencia: '', descripcion_corta: '', unidad_medida: 'unidad', stock_minimo: 0, stock_maximo: '', proveedor_id: '', activo: true }
    modalItem.value = true
}

function abrirEditar(item) {
    editandoItem.value = item
    form.value = {
        nombre: item.nombre, referencia: item.referencia,
        descripcion_corta: item.descripcion_corta ?? '',
        unidad_medida: item.unidad_medida ?? 'unidad',
        stock_minimo: item.stock_minimo ?? 0,
        stock_maximo: item.stock_maximo ?? '',
        proveedor_id: item.proveedor_id ?? '',
        activo: item.activo ?? true,
    }
    modalItem.value = true
}

function cerrarModal() { modalItem.value = false; editandoItem.value = null }

function guardar() {
    guardando.value = true
    const payload = { ...form.value }
    if (!payload.stock_maximo) delete payload.stock_maximo
    if (!payload.proveedor_id) delete payload.proveedor_id

    if (editandoItem.value) {
        router.put(`/inventario/${editandoItem.value.id}`, payload, {
            onSuccess: () => { cerrarModal(); guardando.value = false },
            onError:   () => { guardando.value = false },
        })
    } else {
        router.post('/inventario', payload, {
            onSuccess: () => { cerrarModal(); guardando.value = false },
            onError:   () => { guardando.value = false },
        })
    }
}

function abrirAjuste(item) {
    itemAjuste.value = item
    formAjuste.value = { bodega_id: props.bodegas?.[0]?.id ?? '', tipo: 'entrada', cantidad: '', notas: '' }
    modalAjuste.value = true
}

function guardarAjuste() {
    guardando.value = true
    router.post(`/inventario/${itemAjuste.value.id}/ajuste`, formAjuste.value, {
        onSuccess: () => { modalAjuste.value = false; guardando.value = false },
        onError:   () => { guardando.value = false },
    })
}

async function verMovimientos(item) {
    itemSeleccionado.value = item
    panelMovimientos.value = true
    cargandoMov.value = true
    movimientos.value = []
    try {
        const res = await fetch(`/inventario/${item.id}/movimientos`, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
        movimientos.value = await res.json()
    } catch (e) {
        console.error(e)
    } finally {
        cargandoMov.value = false
    }
}

/**
 * El semaforo de un insumo, contra su stock minimo.
 *
 * Sin minimo definido no dice nada. Antes caia a `pct = 1`, que entra por `pct <= 1` y pintaba
 * «Bajo» a todo lo que nadie habia configurado: una lamina con 250 unidades en bodega salia en
 * rojo. Un tablero que siempre grita deja de leerse.
 */
function semaforo(item) {
    const minimo = Number(item.stock_minimo) || 0

    if (minimo <= 0) return { bg: 'bg-tinta-100', text: 'text-tinta-400', label: 'Sin mínimo' }

    const pct = (item.stock_total ?? 0) / minimo

    if (pct <= 1)   return { bg: 'bg-pastel-rojo-2',    text: 'text-aviso-rojo',    label: '🔴 Bajo' }
    if (pct <= 1.5) return { bg: 'bg-pastel-ambar-2', text: 'text-aviso-ambar', label: '🟡 Cerca' }

    return { bg: 'bg-pastel-verde-2', text: 'text-aviso-verde', label: '🟢 OK' }
}

function fmt(n) {
    return Number(n).toLocaleString('es-CO', { minimumFractionDigits: 0, maximumFractionDigits: 3 })
}
</script>

<template>
    <AppLayout title="Inventario">
        <div class="max-w-6xl mx-auto px-4 py-4">

            <!-- Cabecera -->
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h1 class="text-xl font-semibold text-tinta-900">Inventario</h1>
                    <div class="flex gap-3">
                        <a href="/inventario/dashboard" class="text-sm text-aviso-azul underline">Ver dashboard</a>
                        <a href="/inventario/recetas-corte" class="text-sm text-aviso-azul underline">Recetas de corte</a>
                    </div>
                </div>
                <button @click="abrirCrear"
                    class="inline-flex items-center gap-1 px-3 py-2 rounded-lg text-sm font-medium text-white"
                    style="background:var(--marca)">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M12 4v16m8-8H4"/>
                    </svg>
                    Nuevo ítem
                </button>
            </div>

            <!-- Ordenar. Vale para las listas que son tabla y para las que son tarjetas, y
                 en celular es el único camino: ahí no hay encabezados donde hacer clic. -->
            <div class="mb-3">
                <OrdenarLista :campos="camposOrden" :orden="orden" @ordenar="ordenarPor" />
            </div>

            <!-- Filtros -->
            <div class="bg-superficie rounded-xl border border-linea p-3 mb-4">
                <div class="flex flex-col sm:flex-row gap-2">
                    <input v-model="buscar" type="text" placeholder="Buscar por nombre o código..."
                        class="flex-1 rounded-lg border border-tinta-200 px-3 py-2 text-sm focus:ring-4 focus:ring-[var(--marca-suave)] focus:outline-none"
                        @keyup.enter="aplicarFiltros" />
                    <select v-model="tipo" class="rounded-lg border border-tinta-200 px-3 py-2 text-sm" @change="aplicarFiltros">
                        <option value="">Todos los tipos</option>
                        <option value="materia_prima">Mat. Prima</option>
                        <option value="insumo">Insumo</option>
                        <option value="consumible">Consumible</option>
                        <option value="herramienta">Herramienta</option>
                    </select>
                    <!-- Desde qué bodega. Al elegir una, la lista pasa a ser el contenido de esa
                         bodega: lo que tiene cero ahí no está ahí. -->
                    <select v-model="bodegaVista" class="rounded-lg border border-tinta-200 px-3 py-2 text-sm" @change="aplicarFiltros">
                        <option value="">Todas las bodegas</option>
                        <option v-for="b in bodegas" :key="b.id" :value="b.id">
                            {{ b.nombre }}{{ b.es_principal ? ' · principal' : '' }}
                        </option>
                    </select>
                    <label class="flex items-center gap-2 text-sm text-tinta-700 whitespace-nowrap cursor-pointer">
                        <input v-model="bajo_stock" type="checkbox" class="rounded" @change="aplicarFiltros" />
                        Solo bajo stock
                    </label>
                    <button @click="aplicarFiltros" class="px-4 py-2 rounded-lg bg-tinta-100 text-tinta-700 text-sm font-medium">Buscar</button>
                </div>
            </div>

            <!-- Tabla -->
            <div class="bg-superficie rounded-xl border border-linea overflow-x-auto">
                <table class="w-full text-sm min-w-[700px]">
                    <thead class="bg-tinta-50 border-b border-linea">
                        <tr>
                            <th class="text-left px-4 py-3 font-semibold text-tinta-500">Referencia</th>
                            <th class="text-left px-4 py-3 font-semibold text-tinta-500">Material / Insumo</th>
                            <th class="text-right px-4 py-3 font-semibold text-tinta-500">
                                {{ nombreBodega ? `En ${nombreBodega}` : 'Stock total' }}
                            </th>
                            <th class="text-right px-4 py-3 font-semibold text-tinta-500">Mínimo</th>
                            <th class="text-right px-4 py-3 font-semibold text-tinta-500">Precio Prom.</th>
                            <th class="text-center px-4 py-3 font-semibold text-tinta-500">Estado</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-linea">
                        <tr v-for="item in items.data" :key="item.id"
                            :class="['hover:bg-tinta-50 transition-colors', item.bajo_stock ? 'bg-pastel-rojo' : '']">
                            <td class="px-4 py-3 font-mono text-xs text-tinta-400">{{ item.referencia }}</td>
                            <td class="px-4 py-3">
                                <p class="font-medium text-tinta-900 text-sm">{{ item.nombre }}</p>
                                <p v-if="item.proveedor" class="text-xs text-tinta-300">{{ item.proveedor.nombre }}</p>
                            </td>
                            <td class="px-4 py-3 text-right font-semibold">{{ fmt(item.stock_total) }} <span class="text-xs text-tinta-300">{{ item.unidad_medida }}</span></td>
                            <td class="px-4 py-3 text-right text-tinta-400">{{ fmt(item.stock_minimo) }}</td>
                            <td class="px-4 py-3 text-right text-tinta-400">$ {{ Number(item.precio_promedio_compra).toLocaleString('es-CO') }}</td>
                            <td class="px-4 py-3 text-center">
                                <span :class="['text-xs px-2 py-0.5 rounded-full font-medium', semaforo(item).bg, semaforo(item).text]">
                                    {{ semaforo(item).label }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2 justify-end">
                                    <button @click="verMovimientos(item)" class="text-xs text-aviso-azul font-medium">Movimientos</button>
                                    <button @click="abrirAjuste(item)" class="text-xs text-aviso-verde font-medium">Ajuste</button>
                                    <button @click="abrirEditar(item)" class="text-xs text-tinta-400 font-medium">Editar</button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!items.data?.length">
                            <td colspan="7" class="px-4 py-8 text-center text-tinta-300">No hay materiales / insumos registrados</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Paginación -->
            <div v-if="items.last_page > 1" class="flex justify-center gap-2 mt-4">
                <template v-for="link in items.links" :key="link.label">
                    <button v-if="link.url"
                        @click="router.visit(link.url, { preserveState: true })"
                        :class="['px-3 py-1.5 rounded-lg text-sm', link.active ? 'text-white font-semibold' : 'bg-superficie border border-linea text-tinta-700']"
                        :style="link.active ? 'background:var(--marca)' : ''"
                        v-html="link.label" />
                    <span v-else class="px-3 py-1.5 text-sm text-tinta-200" v-html="link.label" />
                </template>
            </div>
        </div>

        <!-- Panel lateral movimientos -->
        <Teleport to="body">
            <div v-if="panelMovimientos" class="fixed inset-0 z-50 flex justify-end">
                <div class="absolute inset-0 bg-black/30" @click="panelMovimientos = false" />
                <div class="relative bg-superficie w-full max-w-md h-full overflow-y-auto shadow-2xl">
                    <div class="flex items-center justify-between p-4 border-b border-linea sticky top-0 bg-superficie">
                        <div>
                            <p class="font-semibold text-tinta-900">{{ itemSeleccionado?.nombre }}</p>
                            <p class="text-xs text-tinta-400">Historial de movimientos</p>
                        </div>
                        <button @click="panelMovimientos = false" class="text-tinta-300 hover:text-tinta-500">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                    <div class="p-4">
                        <div v-if="cargandoMov" class="text-center py-8 text-tinta-300">Cargando...</div>
                        <div v-else-if="!movimientos.length" class="text-center py-8 text-tinta-300">Sin movimientos</div>
                        <div v-else class="space-y-2">
                            <div v-for="m in movimientos" :key="m.id"
                                class="flex items-start justify-between p-3 rounded-lg border border-linea">
                                <div>
                                    <p class="text-sm font-medium text-tinta-900 capitalize">{{ m.tipo }}</p>
                                    <p class="text-xs text-tinta-400">
                                        <template v-if="m.tipo === 'transferencia'">
                                            {{ m.bodega?.nombre ?? '—' }} → {{ m.bodega_destino?.nombre ?? '—' }}
                                        </template>
                                        <template v-else>{{ m.bodega?.nombre ?? m.notas ?? '—' }}</template>
                                    </p>
                                    <p v-if="m.notas" class="text-xs text-tinta-300">{{ m.notas }}</p>
                                    <p class="text-xs text-tinta-300 mt-1">{{ m.usuario?.name }} · {{ new Date(m.created_at).toLocaleDateString('es-CO') }}</p>
                                </div>
                                <div class="text-right">
                                    <p :class="['font-semibold text-sm', ['entrada','devolucion'].includes(m.tipo) ? 'text-aviso-verde' : 'text-aviso-rojo']">
                                        {{ ['entrada','devolucion'].includes(m.tipo) ? '+' : '-' }}{{ fmt(m.cantidad) }}
                                    </p>
                                    <p class="text-xs text-tinta-300">→ {{ fmt(m.stock_nuevo) }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Modal ajuste -->
        <Teleport to="body">
            <div v-if="modalAjuste" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
                <div class="absolute inset-0 bg-black/40" @click="modalAjuste = false" />
                <div class="relative bg-superficie w-full sm:max-w-sm rounded-t-2xl sm:rounded-2xl p-5">
                    <h2 class="text-lg font-semibold text-tinta-900 mb-1">Ajuste de stock</h2>
                    <p class="text-sm text-tinta-400 mb-4">{{ itemAjuste?.nombre }}</p>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-sm font-medium text-tinta-700 mb-1">Bodega</label>
                            <select v-model="formAjuste.bodega_id" class="w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm">
                                <option v-for="b in bodegas" :key="b.id" :value="b.id">{{ b.nombre }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-tinta-700 mb-1">Tipo de movimiento</label>
                            <select v-model="formAjuste.tipo" class="w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm">
                                <option value="entrada">Entrada</option>
                                <option value="salida">Salida</option>
                                <option value="ajuste">Ajuste</option>
                                <option value="devolucion">Devolución</option>
                                <option value="transferencia">Transferencia</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-tinta-700 mb-1">Cantidad *</label>
                            <input v-model="formAjuste.cantidad" type="number" min="0.001" step="0.001"
                                class="w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-tinta-700 mb-1">Notas</label>
                            <input v-model="formAjuste.notas" type="text"
                                class="w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm" />
                        </div>
                    </div>
                    <div class="flex gap-3 mt-5">
                        <button @click="modalAjuste = false" class="flex-1 py-2.5 rounded-xl border border-tinta-200 text-sm text-tinta-700">Cancelar</button>
                        <button @click="guardarAjuste" :disabled="guardando || !formAjuste.cantidad"
                            class="flex-1 py-2.5 rounded-xl text-sm font-semibold text-white disabled:opacity-50"
                            style="background:var(--marca)">
                            {{ guardando ? 'Guardando...' : 'Registrar' }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Modal material (crear/editar) -->
        <Teleport to="body">
            <div v-if="modalItem" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
                <div class="absolute inset-0 bg-black/40" @click="cerrarModal" />
                <div class="relative bg-superficie w-full sm:max-w-lg rounded-t-2xl sm:rounded-2xl p-5 max-h-[90vh] overflow-y-auto">
                    <h2 class="text-lg font-semibold text-tinta-900 mb-4">
                        {{ editandoItem ? 'Editar material' : 'Nuevo material / insumo' }}
                    </h2>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-sm font-medium text-tinta-700 mb-1">Nombre *</label>
                            <input v-model="form.nombre" type="text"
                                class="w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm" />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-medium text-tinta-700 mb-1">Referencia</label>
                                <input v-model="form.referencia" type="text" placeholder="Auto si se deja vacío"
                                    class="w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-tinta-700 mb-1">Unidad *</label>
                                <input v-model="form.unidad_medida" type="text" placeholder="kg, m, unidad..."
                                    class="w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm" />
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-tinta-700 mb-1">Descripción</label>
                            <input v-model="form.descripcion_corta" type="text"
                                class="w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm" />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-medium text-tinta-700 mb-1">Stock mínimo</label>
                                <input v-model="form.stock_minimo" type="number" min="0" step="0.001"
                                    class="w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-tinta-700 mb-1">Stock máximo</label>
                                <input v-model="form.stock_maximo" type="number" min="0" step="0.001"
                                    class="w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm" />
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-tinta-700 mb-1">Proveedor</label>
                            <select v-model="form.proveedor_id" class="w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm">
                                <option value="">Sin proveedor</option>
                                <option v-for="p in proveedores" :key="p.id" :value="p.id">{{ p.nombre }}</option>
                            </select>
                        </div>
                    </div>
                    <div class="flex gap-3 mt-5">
                        <button @click="cerrarModal" class="flex-1 py-2.5 rounded-xl border border-tinta-200 text-sm text-tinta-700">Cancelar</button>
                        <button @click="guardar" :disabled="guardando || !form.nombre"
                            class="flex-1 py-2.5 rounded-xl text-sm font-semibold text-white disabled:opacity-50"
                            style="background:var(--marca)">
                            {{ guardando ? 'Guardando...' : (editandoItem ? 'Actualizar' : 'Crear') }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
