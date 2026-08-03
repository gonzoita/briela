<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    items:       Object,
    filters:     Object,
    proveedores: Array,
    bodegas:     Array,
})

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

function aplicarFiltros() {
    router.get('/inventario', {
        buscar:     buscar.value || undefined,
        tipo:       tipo.value   || undefined,
        bajo_stock: bajo_stock.value ? 'true' : undefined,
    }, { preserveState: true, replace: true })
}

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

function semaforo(item) {
    const stock = item.stock_total ?? 0
    const pct   = item.stock_minimo > 0 ? stock / item.stock_minimo : 1
    if (pct <= 1)   return { bg: 'bg-red-100',    text: 'text-red-700',    label: '🔴 Bajo' }
    if (pct <= 1.5) return { bg: 'bg-yellow-100', text: 'text-yellow-700', label: '🟡 Cerca' }
    return { bg: 'bg-green-100', text: 'text-green-700', label: '🟢 OK' }
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
                    <h1 class="text-xl font-bold text-gray-900">Inventario</h1>
                    <div class="flex gap-3">
                        <a href="/inventario/dashboard" class="text-sm text-blue-600 underline">Ver dashboard</a>
                        <a href="/inventario/recetas-corte" class="text-sm text-blue-600 underline">Recetas de corte</a>
                    </div>
                </div>
                <button @click="abrirCrear"
                    class="inline-flex items-center gap-1 px-3 py-2 rounded-lg text-sm font-medium text-white"
                    style="background:var(--marca)">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    Nuevo ítem
                </button>
            </div>

            <!-- Filtros -->
            <div class="bg-white rounded-xl border border-gray-200 p-3 mb-4">
                <div class="flex flex-col sm:flex-row gap-2">
                    <input v-model="buscar" type="text" placeholder="Buscar por nombre o código..."
                        class="flex-1 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none"
                        @keyup.enter="aplicarFiltros" />
                    <select v-model="tipo" class="rounded-lg border border-gray-300 px-3 py-2 text-sm" @change="aplicarFiltros">
                        <option value="">Todos los tipos</option>
                        <option value="materia_prima">Mat. Prima</option>
                        <option value="insumo">Insumo</option>
                        <option value="consumible">Consumible</option>
                        <option value="herramienta">Herramienta</option>
                    </select>
                    <label class="flex items-center gap-2 text-sm text-gray-700 whitespace-nowrap cursor-pointer">
                        <input v-model="bajo_stock" type="checkbox" class="rounded" @change="aplicarFiltros" />
                        Solo bajo stock
                    </label>
                    <button @click="aplicarFiltros" class="px-4 py-2 rounded-lg bg-gray-100 text-gray-700 text-sm font-medium">Buscar</button>
                </div>
            </div>

            <!-- Tabla -->
            <div class="bg-white rounded-xl border border-gray-200 overflow-x-auto">
                <table class="w-full text-sm min-w-[700px]">
                    <thead class="bg-gray-50 border-b border-gray-200">
                        <tr>
                            <th class="text-left px-4 py-3 font-semibold text-gray-600">Referencia</th>
                            <th class="text-left px-4 py-3 font-semibold text-gray-600">Material / Insumo</th>
                            <th class="text-right px-4 py-3 font-semibold text-gray-600">Stock total</th>
                            <th class="text-right px-4 py-3 font-semibold text-gray-600">Mínimo</th>
                            <th class="text-right px-4 py-3 font-semibold text-gray-600">Precio Prom.</th>
                            <th class="text-center px-4 py-3 font-semibold text-gray-600">Estado</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="item in items.data" :key="item.id"
                            :class="['hover:bg-gray-50 transition-colors', item.bajo_stock ? 'bg-red-50' : '']">
                            <td class="px-4 py-3 font-mono text-xs text-gray-500">{{ item.referencia }}</td>
                            <td class="px-4 py-3">
                                <p class="font-medium text-gray-900 text-sm">{{ item.nombre }}</p>
                                <p v-if="item.proveedor" class="text-xs text-gray-400">{{ item.proveedor.nombre }}</p>
                            </td>
                            <td class="px-4 py-3 text-right font-semibold">{{ fmt(item.stock_total) }} <span class="text-xs text-gray-400">{{ item.unidad_medida }}</span></td>
                            <td class="px-4 py-3 text-right text-gray-500">{{ fmt(item.stock_minimo) }}</td>
                            <td class="px-4 py-3 text-right text-gray-500">$ {{ Number(item.precio_promedio_compra).toLocaleString('es-CO') }}</td>
                            <td class="px-4 py-3 text-center">
                                <span :class="['text-xs px-2 py-0.5 rounded-full font-medium', semaforo(item).bg, semaforo(item).text]">
                                    {{ semaforo(item).label }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2 justify-end">
                                    <button @click="verMovimientos(item)" class="text-xs text-blue-600 font-medium">Movimientos</button>
                                    <button @click="abrirAjuste(item)" class="text-xs text-green-600 font-medium">Ajuste</button>
                                    <button @click="abrirEditar(item)" class="text-xs text-gray-500 font-medium">Editar</button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!items.data?.length">
                            <td colspan="7" class="px-4 py-8 text-center text-gray-400">No hay materiales / insumos registrados</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Paginación -->
            <div v-if="items.last_page > 1" class="flex justify-center gap-2 mt-4">
                <template v-for="link in items.links" :key="link.label">
                    <button v-if="link.url"
                        @click="router.visit(link.url, { preserveState: true })"
                        :class="['px-3 py-1.5 rounded-lg text-sm', link.active ? 'text-white font-semibold' : 'bg-white border border-gray-200 text-gray-700']"
                        :style="link.active ? 'background:var(--marca)' : ''"
                        v-html="link.label" />
                    <span v-else class="px-3 py-1.5 text-sm text-gray-300" v-html="link.label" />
                </template>
            </div>
        </div>

        <!-- Panel lateral movimientos -->
        <Teleport to="body">
            <div v-if="panelMovimientos" class="fixed inset-0 z-50 flex justify-end">
                <div class="absolute inset-0 bg-black/30" @click="panelMovimientos = false" />
                <div class="relative bg-white w-full max-w-md h-full overflow-y-auto shadow-2xl">
                    <div class="flex items-center justify-between p-4 border-b border-gray-200 sticky top-0 bg-white">
                        <div>
                            <p class="font-bold text-gray-900">{{ itemSeleccionado?.nombre }}</p>
                            <p class="text-xs text-gray-500">Historial de movimientos</p>
                        </div>
                        <button @click="panelMovimientos = false" class="text-gray-400 hover:text-gray-600">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                    <div class="p-4">
                        <div v-if="cargandoMov" class="text-center py-8 text-gray-400">Cargando...</div>
                        <div v-else-if="!movimientos.length" class="text-center py-8 text-gray-400">Sin movimientos</div>
                        <div v-else class="space-y-2">
                            <div v-for="m in movimientos" :key="m.id"
                                class="flex items-start justify-between p-3 rounded-lg border border-gray-100">
                                <div>
                                    <p class="text-sm font-medium text-gray-900 capitalize">{{ m.tipo }}</p>
                                    <p class="text-xs text-gray-500">
                                        <template v-if="m.tipo === 'transferencia'">
                                            {{ m.bodega?.nombre ?? '—' }} → {{ m.bodega_destino?.nombre ?? '—' }}
                                        </template>
                                        <template v-else>{{ m.bodega?.nombre ?? m.notas ?? '—' }}</template>
                                    </p>
                                    <p v-if="m.notas" class="text-xs text-gray-400">{{ m.notas }}</p>
                                    <p class="text-xs text-gray-400 mt-1">{{ m.usuario?.name }} · {{ new Date(m.created_at).toLocaleDateString('es-CO') }}</p>
                                </div>
                                <div class="text-right">
                                    <p :class="['font-semibold text-sm', ['entrada','devolucion'].includes(m.tipo) ? 'text-green-600' : 'text-red-600']">
                                        {{ ['entrada','devolucion'].includes(m.tipo) ? '+' : '-' }}{{ fmt(m.cantidad) }}
                                    </p>
                                    <p class="text-xs text-gray-400">→ {{ fmt(m.stock_nuevo) }}</p>
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
                <div class="relative bg-white w-full sm:max-w-sm rounded-t-2xl sm:rounded-2xl p-5">
                    <h2 class="text-lg font-bold text-gray-900 mb-1">Ajuste de stock</h2>
                    <p class="text-sm text-gray-500 mb-4">{{ itemAjuste?.nombre }}</p>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Bodega</label>
                            <select v-model="formAjuste.bodega_id" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                                <option v-for="b in bodegas" :key="b.id" :value="b.id">{{ b.nombre }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Tipo de movimiento</label>
                            <select v-model="formAjuste.tipo" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                                <option value="entrada">Entrada</option>
                                <option value="salida">Salida</option>
                                <option value="ajuste">Ajuste</option>
                                <option value="devolucion">Devolución</option>
                                <option value="transferencia">Transferencia</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Cantidad *</label>
                            <input v-model="formAjuste.cantidad" type="number" min="0.001" step="0.001"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Notas</label>
                            <input v-model="formAjuste.notas" type="text"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
                        </div>
                    </div>
                    <div class="flex gap-3 mt-5">
                        <button @click="modalAjuste = false" class="flex-1 py-2.5 rounded-xl border border-gray-300 text-sm text-gray-700">Cancelar</button>
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
                <div class="relative bg-white w-full sm:max-w-lg rounded-t-2xl sm:rounded-2xl p-5 max-h-[90vh] overflow-y-auto">
                    <h2 class="text-lg font-bold text-gray-900 mb-4">
                        {{ editandoItem ? 'Editar material' : 'Nuevo material / insumo' }}
                    </h2>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Nombre *</label>
                            <input v-model="form.nombre" type="text"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Referencia</label>
                                <input v-model="form.referencia" type="text" placeholder="Auto si se deja vacío"
                                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Unidad *</label>
                                <input v-model="form.unidad_medida" type="text" placeholder="kg, m, unidad..."
                                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Descripción</label>
                            <input v-model="form.descripcion_corta" type="text"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Stock mínimo</label>
                                <input v-model="form.stock_minimo" type="number" min="0" step="0.001"
                                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Stock máximo</label>
                                <input v-model="form.stock_maximo" type="number" min="0" step="0.001"
                                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Proveedor</label>
                            <select v-model="form.proveedor_id" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                                <option value="">Sin proveedor</option>
                                <option v-for="p in proveedores" :key="p.id" :value="p.id">{{ p.nombre }}</option>
                            </select>
                        </div>
                    </div>
                    <div class="flex gap-3 mt-5">
                        <button @click="cerrarModal" class="flex-1 py-2.5 rounded-xl border border-gray-300 text-sm text-gray-700">Cancelar</button>
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
