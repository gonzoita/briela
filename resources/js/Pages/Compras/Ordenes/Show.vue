<script setup>
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    orden: Object,
})

const modalRecepcion = ref(false)
const guardando      = ref(false)

const cantidadesRecibidas = ref(
    props.orden.items.map(i => ({
        id:               i.id,
        descripcion:      i.descripcion,
        cantidad:         i.cantidad,
        cantidad_recibida: 0,
        ya_recibida:      Number(i.cantidad_recibida),
        pendiente:        Number(i.cantidad) - Number(i.cantidad_recibida),
        unidad:           i.unidad,
    }))
)

function pct(item) {
    const total = Number(item.cantidad)
    if (total <= 0) return 100
    return Math.min(100, Math.round((Number(item.cantidad_recibida) / total) * 100))
}

function enviar() {
    if (!confirm('¿Confirmar envío de la orden al proveedor?')) return
    router.post(`/compras/ordenes/${props.orden.id}/enviar`)
}

function abrirRecepcion() {
    cantidadesRecibidas.value = props.orden.items.map(i => ({
        id:                i.id,
        descripcion:       i.descripcion,
        cantidad:          Number(i.cantidad),
        cantidad_recibida: 0,
        ya_recibida:       Number(i.cantidad_recibida),
        pendiente:         Number(i.cantidad) - Number(i.cantidad_recibida),
        unidad:            i.unidad,
    }))
    modalRecepcion.value = true
}

function guardarRecepcion() {
    guardando.value = true
    const items = cantidadesRecibidas.value
        .filter(i => i.cantidad_recibida > 0)
        .map(i => ({ id: i.id, cantidad_recibida: i.cantidad_recibida }))

    if (!items.length) {
        alert('Ingresa al menos una cantidad recibida')
        guardando.value = false
        return
    }

    router.post(`/compras/ordenes/${props.orden.id}/recibir`, { items }, {
        onSuccess: () => { modalRecepcion.value = false; guardando.value = false },
        onError:   () => { guardando.value = false },
    })
}

const estados = {
    borrador:         { label: 'Borrador',         bg: 'bg-tinta-100',   text: 'text-tinta-700'   },
    enviada:          { label: 'Enviada',           bg: 'bg-blue-100',   text: 'text-blue-700'   },
    confirmada:       { label: 'Confirmada',        bg: 'bg-[var(--marca-suave)]', text: 'text-[var(--marca)]' },
    recibida_parcial: { label: 'Recib. Parcial',    bg: 'bg-yellow-100', text: 'text-yellow-700' },
    recibida:         { label: 'Recibida',          bg: 'bg-green-100',  text: 'text-green-700'  },
    cancelada:        { label: 'Cancelada',         bg: 'bg-red-100',    text: 'text-red-700'    },
}

function estadoBadge(e) {
    return estados[e] ?? { label: e, bg: 'bg-tinta-100', text: 'text-tinta-700' }
}

function fmtMoney(n) {
    return '$ ' + Number(n).toLocaleString('es-CO', { minimumFractionDigits: 0, maximumFractionDigits: 0 })
}

function fmt(n) {
    return Number(n).toLocaleString('es-CO', { minimumFractionDigits: 0, maximumFractionDigits: 3 })
}

const puedeRecibir = computed(() =>
    ['enviada', 'confirmada', 'recibida_parcial'].includes(props.orden.estado)
)
</script>

<template>
    <AppLayout :title="`OC ${orden.numero}`">
        <div class="max-w-4xl mx-auto px-4 py-4">

            <!-- Cabecera -->
            <div class="flex items-start justify-between mb-4">
                <div class="flex items-center gap-3">
                    <a href="/compras/ordenes" class="text-tinta-300 hover:text-tinta-500">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M15 19l-7-7 7-7"/>
                        </svg>
                    </a>
                    <div>
                        <div class="flex items-center gap-2">
                            <h1 class="text-xl font-semibold text-tinta-900">{{ orden.numero }}</h1>
                            <span :class="['text-xs px-2 py-0.5 rounded-full font-medium', estadoBadge(orden.estado).bg, estadoBadge(orden.estado).text]">
                                {{ estadoBadge(orden.estado).label }}
                            </span>
                        </div>
                        <p class="text-sm text-tinta-400">{{ orden.proveedor?.nombre }}</p>
                    </div>
                </div>
                <div class="flex gap-2">
                    <a :href="`/compras/ordenes/${orden.id}/pdf`" target="_blank"
                        class="px-3 py-2 rounded-lg border border-tinta-200 text-sm text-tinta-700 font-medium">
                        PDF
                    </a>
                    <button v-if="orden.estado === 'borrador'" @click="enviar"
                        class="px-3 py-2 rounded-lg text-sm font-semibold text-white"
                        style="background:var(--marca)">
                        Enviar al proveedor
                    </button>
                    <button v-if="puedeRecibir" @click="abrirRecepcion"
                        class="px-3 py-2 rounded-lg text-sm font-semibold text-white bg-green-600">
                        Registrar recepción
                    </button>
                </div>
            </div>

            <!-- Info -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
                <div class="bg-superficie rounded-xl border border-linea p-3">
                    <p class="text-xs text-tinta-400">Creado por</p>
                    <p class="font-medium text-tinta-900 text-sm">{{ orden.creado_por?.name }}</p>
                </div>
                <div class="bg-superficie rounded-xl border border-linea p-3">
                    <p class="text-xs text-tinta-400">Fecha entrega est.</p>
                    <p class="font-medium text-tinta-900 text-sm">
                        {{ orden.fecha_entrega_esperada ? new Date(orden.fecha_entrega_esperada).toLocaleDateString('es-CO') : '—' }}
                    </p>
                </div>
                <div class="bg-superficie rounded-xl border border-linea p-3">
                    <p class="text-xs text-tinta-400">SC vinculada</p>
                    <p class="font-medium text-tinta-900 text-sm">{{ orden.solicitud?.numero ?? '—' }}</p>
                </div>
                <div class="bg-superficie rounded-xl border border-linea p-3">
                    <p class="text-xs text-tinta-400">Fecha recepción</p>
                    <p class="font-medium text-tinta-900 text-sm">
                        {{ orden.fecha_recepcion ? new Date(orden.fecha_recepcion).toLocaleDateString('es-CO') : '—' }}
                    </p>
                </div>
            </div>

            <!-- Ítems -->
            <div class="bg-superficie rounded-xl border border-linea overflow-hidden mb-4">
                <div class="px-4 py-3 border-b border-linea">
                    <h2 class="font-semibold text-tinta-900">Ítems</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm min-w-[600px]">
                        <thead class="bg-tinta-50">
                            <tr>
                                <th class="text-left px-4 py-2 font-medium text-tinta-500">Descripción</th>
                                <th class="text-right px-4 py-2 font-medium text-tinta-500">Cant.</th>
                                <th class="text-right px-4 py-2 font-medium text-tinta-500">Recibida</th>
                                <th class="text-right px-4 py-2 font-medium text-tinta-500">Precio</th>
                                <th class="text-right px-4 py-2 font-medium text-tinta-500">Total</th>
                                <th class="px-4 py-2 font-medium text-tinta-500">Progreso</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-linea">
                            <tr v-for="item in orden.items" :key="item.id">
                                <td class="px-4 py-3">
                                    <p class="font-medium text-tinta-900">{{ item.descripcion }}</p>
                                    <p v-if="item.item" class="text-xs text-tinta-300">{{ item.item.referencia }}</p>
                                </td>
                                <td class="px-4 py-3 text-right text-tinta-700">{{ fmt(item.cantidad) }} {{ item.unidad }}</td>
                                <td class="px-4 py-3 text-right">
                                    <span :class="['font-semibold', Number(item.cantidad_recibida) >= Number(item.cantidad) ? 'text-green-600' : 'text-orange-500']">
                                        {{ fmt(item.cantidad_recibida) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right text-tinta-400">{{ fmtMoney(item.precio_unitario) }}</td>
                                <td class="px-4 py-3 text-right font-semibold text-tinta-900">{{ fmtMoney(item.total_linea) }}</td>
                                <td class="px-4 py-3">
                                    <div class="w-24 bg-tinta-100 rounded-full h-2">
                                        <div class="h-2 rounded-full bg-green-500"
                                            :style="`width:${pct(item)}%`" />
                                    </div>
                                    <p class="text-xs text-tinta-300 mt-0.5">{{ pct(item) }}%</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <!-- Totales -->
                <div class="px-4 py-3 border-t border-linea space-y-1 text-sm">
                    <div class="flex justify-between text-tinta-500">
                        <span>Subtotal</span>
                        <span>{{ fmtMoney(orden.subtotal) }}</span>
                    </div>
                    <div class="flex justify-between text-tinta-500">
                        <span>Impuesto</span>
                        <span>{{ fmtMoney(orden.impuesto) }}</span>
                    </div>
                    <div class="flex justify-between font-semibold text-tinta-900 text-base border-t border-linea pt-1">
                        <span>Total</span>
                        <span>{{ fmtMoney(orden.total) }}</span>
                    </div>
                </div>
            </div>

            <!-- Condiciones / notas -->
            <div v-if="orden.condiciones || orden.notas" class="bg-superficie rounded-xl border border-linea p-4">
                <div v-if="orden.condiciones" class="mb-2">
                    <p class="text-sm font-medium text-tinta-700">Condiciones</p>
                    <p class="text-sm text-tinta-400 mt-1">{{ orden.condiciones }}</p>
                </div>
                <div v-if="orden.notas">
                    <p class="text-sm font-medium text-tinta-700">Notas</p>
                    <p class="text-sm text-tinta-400 mt-1">{{ orden.notas }}</p>
                </div>
            </div>
        </div>

        <!-- Modal recepción -->
        <Teleport to="body">
            <div v-if="modalRecepcion" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
                <div class="absolute inset-0 bg-black/40" @click="modalRecepcion = false" />
                <div class="relative bg-superficie w-full sm:max-w-lg rounded-t-2xl sm:rounded-2xl p-5 max-h-[90vh] overflow-y-auto">
                    <h2 class="text-lg font-semibold text-tinta-900 mb-1">Registrar recepción</h2>
                    <p class="text-sm text-tinta-400 mb-4">Ingresa la cantidad recibida de cada ítem</p>

                    <div class="space-y-3">
                        <div v-for="item in cantidadesRecibidas" :key="item.id"
                            class="border border-linea rounded-lg p-3">
                            <p class="text-sm font-medium text-tinta-900 mb-2">{{ item.descripcion }}</p>
                            <div class="flex items-center gap-3 text-sm text-tinta-400 mb-2">
                                <span>Total: {{ fmt(item.cantidad) }}</span>
                                <span>Ya recibido: {{ fmt(item.ya_recibida) }}</span>
                                <span class="text-orange-600">Pendiente: {{ fmt(item.pendiente) }}</span>
                            </div>
                            <div>
                                <label class="block text-xs text-tinta-500 mb-1">Cantidad a recibir ahora</label>
                                <input v-model="item.cantidad_recibida" type="number"
                                    :max="item.pendiente" min="0" step="0.001"
                                    class="w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm" />
                            </div>
                        </div>
                    </div>

                    <div class="flex gap-3 mt-5">
                        <button @click="modalRecepcion = false"
                            class="flex-1 py-2.5 rounded-xl border border-tinta-200 text-sm text-tinta-700">
                            Cancelar
                        </button>
                        <button @click="guardarRecepcion" :disabled="guardando"
                            class="flex-1 py-2.5 rounded-xl text-sm font-semibold text-white bg-green-600 disabled:opacity-50">
                            {{ guardando ? 'Guardando...' : 'Confirmar recepción' }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
