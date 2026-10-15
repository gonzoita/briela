<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    totalItems:         Number,
    itemsBajoStock:     Array,
    valorTotal:         Number,
    ultimosMovimientos: Array,
    movimientosPorDia:  Array,
    // Lo pedido a proveedores que todavía no llega (InventarioTransitoService::resumen).
    transito:           { type: Object, default: () => ({ unidades: 0, valor: 0, ordenes: 0, productos: 0, atrasadas: [] }) },
})

const fechaCorta = (f) => f ? new Date(String(f).slice(0, 10) + 'T12:00:00').toLocaleDateString('es-CO', { day: 'numeric', month: 'short' }) : ''

function fmt(n) {
    return Number(n).toLocaleString('es-CO', { minimumFractionDigits: 0, maximumFractionDigits: 3 })
}

function fmtMoney(n) {
    return '$ ' + Number(n).toLocaleString('es-CO', { minimumFractionDigits: 0, maximumFractionDigits: 0 })
}

function tipoColor(tipo) {
    const map = { entrada: 'text-aviso-verde', salida: 'text-aviso-rojo', ajuste: 'text-aviso-azul', devolucion: 'text-aviso-naranja' }
    return map[tipo] ?? 'text-tinta-500'
}

function tipoSimbolo(tipo) {
    return ['entrada','devolucion'].includes(tipo) ? '+' : '-'
}
</script>

<template>
    <AppLayout title="Dashboard Inventario">
        <div class="max-w-5xl mx-auto px-4 py-4">

            <div class="flex items-center justify-between mb-4">
                <h1 class="text-xl font-semibold text-tinta-900">Dashboard Inventario</h1>
                <div class="flex items-center gap-3">
                    <a href="/inventario/movimientos" class="text-sm text-tinta-400 font-medium hover:text-tinta-700">Todos los movimientos →</a>
                    <a href="/inventario" class="text-sm text-aviso-azul font-medium">Ver ítems →</a>
                </div>
            </div>

            <!-- Tarjetas -->
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 mb-6">
                <div class="bg-superficie rounded-xl border border-linea p-4">
                    <p class="text-xs text-tinta-400 font-medium uppercase tracking-wide">Total ítems</p>
                    <p class="text-3xl font-semibold text-tinta-900 mt-1">{{ totalItems }}</p>
                </div>
                <div class="bg-superficie rounded-xl border border-borde-aviso-rojo p-4">
                    <p class="text-xs text-aviso-rojo font-medium uppercase tracking-wide">Bajo stock</p>
                    <p class="text-3xl font-semibold text-aviso-rojo mt-1">{{ itemsBajoStock.length }}</p>
                </div>
                <div class="bg-superficie rounded-xl border border-linea p-4 col-span-2 sm:col-span-1">
                    <p class="text-xs text-tinta-400 font-medium uppercase tracking-wide">Valor total</p>
                    <p class="text-2xl font-semibold text-tinta-900 mt-1">{{ fmtMoney(valorTotal) }}</p>
                </div>
            </div>

            <!-- En tránsito: pedido a proveedores, sin recibir -->
            <div v-if="transito.ordenes > 0" class="bg-superficie rounded-xl border border-borde-aviso-azul p-4 mb-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="font-semibold text-aviso-azul flex items-center gap-2"><span>🚚</span> En tránsito</h2>
                        <p class="text-xs text-tinta-400 mt-0.5">Pedido en órdenes de compra enviadas y todavía sin recibir. Entra a bodega al recibir la orden contra su remisión.</p>
                    </div>
                    <a href="/compras/ordenes" class="text-sm text-aviso-azul font-medium shrink-0">Órdenes de compra →</a>
                </div>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-3">
                    <div><p class="text-xs text-tinta-400">Unidades</p><p class="text-xl font-semibold text-tinta-900">{{ fmt(transito.unidades) }}</p></div>
                    <div><p class="text-xs text-tinta-400">Valor</p><p class="text-xl font-semibold text-tinta-900">{{ fmtMoney(transito.valor) }}</p></div>
                    <div><p class="text-xs text-tinta-400">Productos</p><p class="text-xl font-semibold text-tinta-900">{{ transito.productos }}</p></div>
                    <div><p class="text-xs text-tinta-400">Órdenes</p><p class="text-xl font-semibold text-tinta-900">{{ transito.ordenes }}</p></div>
                </div>
                <div v-if="transito.atrasadas.length" class="mt-4">
                    <p class="text-xs font-semibold text-aviso-rojo uppercase tracking-wide mb-1">Atrasadas: pasaron su fecha y no han llegado</p>
                    <div class="divide-y divide-separador">
                        <a v-for="o in transito.atrasadas" :key="o.id" :href="`/compras/ordenes/${o.id}`"
                            class="flex items-center justify-between gap-3 py-2 hover:bg-realce rounded px-1">
                            <span class="text-sm text-tinta-900 truncate">{{ o.numero }}<span v-if="o.proveedor" class="text-tinta-400"> · {{ o.proveedor }}</span></span>
                            <span class="text-xs text-aviso-rojo shrink-0">{{ fmt(o.pendiente) }} sin llegar · esperada {{ fechaCorta(o.fecha_esperada) }} ({{ o.dias }} {{ o.dias === 1 ? 'día' : 'días' }})</span>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Items bajo stock -->
            <div v-if="itemsBajoStock.length" class="bg-superficie rounded-xl border border-borde-aviso-rojo p-4 mb-6">
                <h2 class="font-semibold text-aviso-rojo mb-3 flex items-center gap-2">
                    <span>⚠️</span> Ítems bajo stock mínimo
                </h2>
                <div class="space-y-2">
                    <div v-for="item in itemsBajoStock" :key="item.id"
                        class="flex items-center justify-between py-2 border-b border-red-50 last:border-0">
                        <div>
                            <p class="text-sm font-medium text-tinta-900">{{ item.nombre }}</p>
                            <p class="text-xs text-tinta-400">{{ item.referencia }} · {{ item.proveedor?.nombre ?? 'Sin proveedor' }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-semibold text-aviso-rojo">{{ fmt(item.stock_total) }} {{ item.unidad_medida }}</p>
                            <p class="text-xs text-tinta-300">mín. {{ fmt(item.stock_minimo) }}</p>
                        </div>
                    </div>
                </div>
                <a href="/compras/solicitudes/crear" class="mt-3 inline-flex items-center gap-1 text-sm font-medium text-aviso-azul">
                    + Crear solicitud de compra
                </a>
            </div>

            <!-- Últimos movimientos -->
            <div class="bg-superficie rounded-xl border border-linea p-4 mb-6">
                <h2 class="font-semibold text-tinta-900 mb-3">Últimos movimientos</h2>
                <div v-if="!ultimosMovimientos.length" class="text-center py-4 text-tinta-300 text-sm">Sin movimientos</div>
                <div v-else class="space-y-2">
                    <div v-for="m in ultimosMovimientos" :key="m.id"
                        class="flex items-center justify-between py-2 border-b border-separador last:border-0">
                        <div>
                            <p class="text-sm font-medium text-tinta-900">{{ m.producto?.nombre }}</p>
                            <p class="text-xs text-tinta-400">{{ m.usuario?.name }} · {{ new Date(m.created_at).toLocaleDateString('es-CO') }}</p>
                        </div>
                        <div class="text-right">
                            <p :class="['text-sm font-semibold capitalize', tipoColor(m.tipo)]">
                                {{ tipoSimbolo(m.tipo) }}{{ fmt(m.cantidad) }}
                            </p>
                            <p class="text-xs text-tinta-300 capitalize">{{ m.tipo }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Gráfica movimientos por día -->
            <div class="bg-superficie rounded-xl border border-linea p-4">
                <h2 class="font-semibold text-tinta-900 mb-3">Movimientos últimos 30 días</h2>
                <div v-if="!movimientosPorDia.length" class="text-center py-4 text-tinta-300 text-sm">Sin datos</div>
                <div v-else class="flex items-end gap-1 h-24">
                    <template v-for="d in movimientosPorDia" :key="d.fecha">
                        <div class="flex-1 flex flex-col items-center gap-1 group relative">
                            <div class="w-full rounded-t"
                                :style="`height:${(d.total / Math.max(...movimientosPorDia.map(x => x.total))) * 80}px; background:var(--marca); opacity:0.7;`" />
                            <div class="absolute bottom-full mb-1 hidden group-hover:block bg-gray-800 text-white text-xs rounded px-1.5 py-0.5 whitespace-nowrap">
                                {{ d.fecha }}: {{ d.total }}
                            </div>
                        </div>
                    </template>
                </div>
                <div class="flex justify-between text-xs text-tinta-300 mt-1">
                    <span>{{ movimientosPorDia[0]?.fecha }}</span>
                    <span>{{ movimientosPorDia[movimientosPorDia.length - 1]?.fecha }}</span>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
