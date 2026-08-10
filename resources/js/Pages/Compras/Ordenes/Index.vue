<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    ordenes:     Object,
    filters:     Object,
    proveedores: Array,
})

const buscar      = ref(props.filters?.buscar      ?? '')
const estado      = ref(props.filters?.estado      ?? '')
const proveedor_id= ref(props.filters?.proveedor_id?? '')
const desde       = ref(props.filters?.desde       ?? '')
const hasta       = ref(props.filters?.hasta       ?? '')

function aplicarFiltros() {
    router.get('/compras/ordenes', {
        buscar:       buscar.value       || undefined,
        estado:       estado.value       || undefined,
        proveedor_id: proveedor_id.value || undefined,
        desde:        desde.value        || undefined,
        hasta:        hasta.value        || undefined,
    }, { preserveState: true, replace: true })
}

const estados = {
    borrador:          { label: 'Borrador',          bg: 'bg-tinta-100',    text: 'text-tinta-700'   },
    enviada:           { label: 'Enviada',            bg: 'bg-blue-100',    text: 'text-blue-700'   },
    confirmada:        { label: 'Confirmada',         bg: 'bg-[var(--marca-suave)]',  text: 'text-[var(--marca)]' },
    recibida_parcial:  { label: 'Recib. Parcial',     bg: 'bg-yellow-100',  text: 'text-yellow-700' },
    recibida:          { label: 'Recibida',           bg: 'bg-green-100',   text: 'text-green-700'  },
    cancelada:         { label: 'Cancelada',          bg: 'bg-red-100',     text: 'text-red-700'    },
}

function estadoBadge(e) {
    return estados[e] ?? { label: e, bg: 'bg-tinta-100', text: 'text-tinta-700' }
}

function fmtMoney(n) {
    return '$ ' + Number(n).toLocaleString('es-CO', { minimumFractionDigits: 0, maximumFractionDigits: 0 })
}
</script>

<template>
    <AppLayout title="Órdenes de Compra">
        <div class="max-w-5xl mx-auto px-4 py-4">

            <!-- Cabecera -->
            <div class="flex items-center justify-between mb-4">
                <h1 class="text-xl font-semibold text-tinta-900">Órdenes de Compra</h1>
                <a href="/compras/ordenes/crear"
                    class="inline-flex items-center gap-1 px-3 py-2 rounded-lg text-sm font-medium text-white"
                    style="background:var(--marca)">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M12 4v16m8-8H4"/>
                    </svg>
                    Nueva OC
                </a>
            </div>

            <!-- Filtros -->
            <div class="bg-white rounded-xl border border-linea p-3 mb-4">
                <div class="flex flex-col sm:flex-row gap-2 flex-wrap">
                    <input v-model="buscar" type="text" placeholder="Número de orden..."
                        class="flex-1 rounded-lg border border-tinta-200 px-3 py-2 text-sm" @keyup.enter="aplicarFiltros" />
                    <select v-model="estado" class="rounded-lg border border-tinta-200 px-3 py-2 text-sm" @change="aplicarFiltros">
                        <option value="">Todos los estados</option>
                        <option v-for="(v, k) in estados" :key="k" :value="k">{{ v.label }}</option>
                    </select>
                    <select v-model="proveedor_id" class="rounded-lg border border-tinta-200 px-3 py-2 text-sm" @change="aplicarFiltros">
                        <option value="">Todos los proveedores</option>
                        <option v-for="p in proveedores" :key="p.id" :value="p.id">{{ p.nombre }}</option>
                    </select>
                    <button @click="aplicarFiltros" class="px-4 py-2 rounded-lg bg-tinta-100 text-tinta-700 text-sm font-medium">Buscar</button>
                </div>
            </div>

            <!-- Lista -->
            <div class="space-y-2">
                <a v-for="o in ordenes.data" :key="o.id"
                    :href="`/compras/ordenes/${o.id}`"
                    class="block bg-white rounded-xl border border-linea p-4 hover:border-blue-300 transition-colors">
                    <div class="flex items-start justify-between">
                        <div>
                            <div class="flex items-center gap-2 mb-1">
                                <p class="font-semibold text-tinta-900">{{ o.numero }}</p>
                                <span :class="['text-xs px-2 py-0.5 rounded-full font-medium', estadoBadge(o.estado).bg, estadoBadge(o.estado).text]">
                                    {{ estadoBadge(o.estado).label }}
                                </span>
                            </div>
                            <p class="text-sm text-tinta-500">{{ o.proveedor?.nombre }}</p>
                            <p class="text-xs text-tinta-300 mt-0.5">
                                Por: {{ o.creado_por?.name }}
                                <span v-if="o.fecha_entrega_esperada"> · Entrega: {{ new Date(o.fecha_entrega_esperada).toLocaleDateString('es-CO') }}</span>
                            </p>
                        </div>
                        <div class="text-right">
                            <p class="font-semibold text-tinta-900">{{ fmtMoney(o.total) }}</p>
                            <p class="text-xs text-tinta-300">{{ new Date(o.created_at).toLocaleDateString('es-CO') }}</p>
                        </div>
                    </div>
                </a>

                <div v-if="!ordenes.data?.length" class="text-center py-8 text-tinta-300">
                    No hay órdenes de compra
                </div>
            </div>

            <!-- Paginación -->
            <div v-if="ordenes.last_page > 1" class="flex justify-center gap-2 mt-4">
                <template v-for="link in ordenes.links" :key="link.label">
                    <button v-if="link.url"
                        @click="router.visit(link.url, { preserveState: true })"
                        :class="['px-3 py-1.5 rounded-lg text-sm', link.active ? 'text-white font-semibold' : 'bg-white border border-linea text-tinta-700']"
                        :style="link.active ? 'background:var(--marca)' : ''"
                        v-html="link.label" />
                    <span v-else class="px-3 py-1.5 text-sm text-tinta-200" v-html="link.label" />
                </template>
            </div>
        </div>
    </AppLayout>
</template>
