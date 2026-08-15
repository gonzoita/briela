<script setup>
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import OrdenarLista from '@/Components/OrdenarLista.vue'
import { useOrden } from '@/composables/useOrden'

const props = defineProps({
    movimientos: Object,
    productos:   Array,
    bodegas:     Array,
    filters:     Object,
    // El orden vigente, que decide el servidor: { campo, dir }.
    orden: { type: Object, default: () => ({}) },
})

// Ordenar mantiene los filtros: reordenar no es empezar de cero.
const { ordenarPor } = useOrden('/compras/inventario/movimientos', props.orden, props.filters)

const camposOrden = [
    { campo: 'created_at', etiqueta: 'Más reciente', texto: false },
    { campo: 'tipo', etiqueta: 'Tipo' },
    { campo: 'cantidad', etiqueta: 'Cantidad', texto: false },
]

const producto_id  = ref(props.filters?.producto_id  ?? '')
const bodega_id    = ref(props.filters?.bodega_id    ?? '')
const tipo         = ref(props.filters?.tipo         ?? '')
const fecha_desde  = ref(props.filters?.fecha_desde  ?? '')
const fecha_hasta  = ref(props.filters?.fecha_hasta  ?? '')

const tiposMovimiento = [
    { value: 'entrada',          label: 'Entrada' },
    { value: 'salida',           label: 'Salida' },
    { value: 'transferencia',    label: 'Transferencia' },
    { value: 'ajuste',           label: 'Ajuste' },
    { value: 'devolucion',       label: 'Devolución' },
    { value: 'consumo_ensamble', label: 'Consumo producción' },
    { value: 'venta',            label: 'Venta' },
]

function aplicarFiltros() {
    router.get('/inventario/movimientos', {
        producto_id: producto_id.value || undefined,
        bodega_id:   bodega_id.value   || undefined,
        tipo:        tipo.value        || undefined,
        fecha_desde: fecha_desde.value || undefined,
        fecha_hasta: fecha_hasta.value || undefined,
    }, { preserveState: true, replace: true })
}

function limpiarFiltros() {
    producto_id.value = ''
    bodega_id.value   = ''
    tipo.value        = ''
    fecha_desde.value = ''
    fecha_hasta.value = ''
    aplicarFiltros()
}

const tipoColor = (t) => {
    const map = {
        entrada:          '#10b981',
        salida:           '#ef4444',
        ajuste:           '#3b82f6',
        transferencia:    '#8b5cf6',
        devolucion:       '#f59e0b',
        consumo_ensamble: '#4f46e5',
        venta:            '#ec4899',
        creacion_producto:'#6b7280',
    }
    return map[t] ?? '#6b7280'
}

const tipoLabel = (t) => {
    const map = {
        entrada:           'Entrada',
        salida:            'Salida',
        ajuste:            'Ajuste',
        transferencia:     'Transferencia',
        devolucion:        'Devolución',
        consumo_ensamble:  'Consumo producción',
        venta:             'Venta',
        creacion_producto: 'Inicial',
    }
    return map[t] ?? t
}

const fmtFecha = (d) => d
    ? new Date(d).toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
    : '—'

const fmt = (n) => Number(n).toLocaleString('es-CO', { minimumFractionDigits: 0, maximumFractionDigits: 3 })

const hayFiltros = computed(() =>
    producto_id.value || bodega_id.value || tipo.value || fecha_desde.value || fecha_hasta.value
)
</script>

<template>
    <AppLayout title="Movimientos de Inventario">
        <div class="max-w-6xl mx-auto px-4 py-4">

            <!-- Cabecera -->
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h1 class="text-xl font-semibold text-tinta-900">Movimientos de Inventario</h1>
                    <p class="text-sm text-tinta-400">
                        <a href="/inventario" class="text-aviso-azul hover:underline">Stock &amp; Materiales</a>
                        <span class="mx-1">·</span>
                        <a href="/inventario/dashboard" class="text-aviso-azul hover:underline">Dashboard</a>
                    </p>
                </div>
                <button disabled
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium bg-tinta-100 text-tinta-300 cursor-not-allowed">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    Exportar (próximamente)
                </button>
            </div>

            <!-- Ordenar. Vale para las listas que son tabla y para las que son tarjetas, y
                 en celular es el único camino: ahí no hay encabezados donde hacer clic. -->
            <div class="mb-3">
                <OrdenarLista :campos="camposOrden" :orden="orden" @ordenar="ordenarPor" />
            </div>

            <!-- Filtros -->
            <div class="bg-superficie rounded-xl border border-linea p-4 mb-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-tinta-500 mb-1">Producto</label>
                        <select v-model="producto_id" class="w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm">
                            <option value="">Todos los productos</option>
                            <option v-for="prod in productos" :key="prod.id" :value="prod.id">
                                {{ prod.referencia }} — {{ prod.nombre }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-tinta-500 mb-1">Bodega</label>
                        <select v-model="bodega_id" class="w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm">
                            <option value="">Todas las bodegas</option>
                            <option v-for="b in bodegas" :key="b.id" :value="b.id">{{ b.nombre }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-tinta-500 mb-1">Tipo</label>
                        <select v-model="tipo" class="w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm">
                            <option value="">Todos los tipos</option>
                            <option v-for="t in tiposMovimiento" :key="t.value" :value="t.value">{{ t.label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-tinta-500 mb-1">Desde</label>
                        <input v-model="fecha_desde" type="date" class="w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-tinta-500 mb-1">Hasta</label>
                        <input v-model="fecha_hasta" type="date" class="w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm" />
                    </div>
                    <div class="flex items-end gap-2">
                        <button @click="aplicarFiltros"
                            class="flex-1 py-2 rounded-lg text-sm font-medium text-white"
                            style="background:var(--marca);">
                            Filtrar
                        </button>
                        <button v-if="hayFiltros" @click="limpiarFiltros"
                            class="px-3 py-2 rounded-lg text-sm text-tinta-500 border border-linea hover:bg-tinta-50">
                            Limpiar
                        </button>
                    </div>
                </div>
            </div>

            <!-- Tabla -->
            <div class="bg-superficie rounded-xl border border-linea overflow-x-auto">
                <table class="w-full text-xs min-w-[700px]">
                    <thead class="bg-tinta-50 border-b border-linea">
                        <tr>
                            <th class="text-left px-4 py-3 font-semibold text-tinta-500">Fecha</th>
                            <th class="text-left px-4 py-3 font-semibold text-tinta-500">Producto</th>
                            <th class="text-left px-3 py-3 font-semibold text-tinta-500">Tipo</th>
                            <th class="text-right px-3 py-3 font-semibold text-tinta-500">Cantidad</th>
                            <th class="text-left px-3 py-3 font-semibold text-tinta-500">Bodega</th>
                            <th class="text-left px-3 py-3 font-semibold text-tinta-500">Usuario</th>
                            <th class="text-left px-3 py-3 font-semibold text-tinta-500">Notas</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-linea">
                        <tr v-for="mv in movimientos.data" :key="mv.id" class="hover:bg-tinta-50 transition-colors">
                            <td class="px-4 py-3 text-tinta-300 whitespace-nowrap">{{ fmtFecha(mv.created_at) }}</td>
                            <td class="px-4 py-3">
                                <p class="font-medium text-tinta-900">{{ mv.producto?.nombre ?? '—' }}</p>
                                <p class="text-tinta-300 font-mono">{{ mv.producto?.referencia }}</p>
                            </td>
                            <td class="px-3 py-3">
                                <span class="px-2 py-0.5 rounded-full text-white text-[11px] font-semibold whitespace-nowrap"
                                    :style="`background:${tipoColor(mv.tipo)};`">
                                    {{ tipoLabel(mv.tipo) }}
                                </span>
                            </td>
                            <td class="px-3 py-3 text-right font-semibold"
                                :style="`color:${tipoColor(mv.tipo)};`">
                                {{ ['entrada','devolucion','creacion_producto'].includes(mv.tipo) ? '+' : '-' }}{{ fmt(mv.cantidad) }}
                                <span class="text-tinta-300 font-normal ml-0.5">{{ mv.producto?.unidad_medida }}</span>
                            </td>
                            <td class="px-3 py-3 text-tinta-500">
                                <template v-if="mv.tipo === 'transferencia'">
                                    {{ mv.bodega?.nombre ?? '—' }} → {{ mv.bodega_destino?.nombre ?? '—' }}
                                </template>
                                <template v-else>{{ mv.bodega?.nombre ?? '—' }}</template>
                            </td>
                            <td class="px-3 py-3 text-tinta-400">{{ mv.usuario?.name ?? '—' }}</td>
                            <td class="px-3 py-3 text-tinta-300 truncate max-w-[180px]">{{ mv.notas ?? '—' }}</td>
                        </tr>
                        <tr v-if="!movimientos.data?.length">
                            <td colspan="7" class="px-4 py-10 text-center text-tinta-300">
                                No hay movimientos con los filtros seleccionados.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Contador -->
            <p class="text-xs text-tinta-300 mt-2 text-right">
                {{ movimientos.total }} movimiento{{ movimientos.total === 1 ? '' : 's' }} en total
            </p>

            <!-- Paginación -->
            <div v-if="movimientos.last_page > 1" class="flex justify-center gap-2 mt-4">
                <template v-for="link in movimientos.links" :key="link.label">
                    <button v-if="link.url"
                        @click="router.visit(link.url, { preserveState: true })"
                        :class="['px-3 py-1.5 rounded-lg text-sm', link.active ? 'text-white font-semibold' : 'bg-superficie border border-linea text-tinta-700']"
                        :style="link.active ? 'background:var(--marca)' : ''"
                        v-html="link.label" />
                    <span v-else class="px-3 py-1.5 text-sm text-tinta-200" v-html="link.label" />
                </template>
            </div>

        </div>
    </AppLayout>
</template>
