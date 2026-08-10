<script setup>
const props = defineProps({
    op: Object,
})

const ESTADOS = {
    borrador:      { label: 'Borrador',      bg: '#F3F4F6', text: '#374151' },
    confirmada:    { label: 'Confirmada',    bg: '#DBEAFE', text: '#1D4ED8' },
    en_produccion: { label: 'En producción', bg: '#FEF3C7', text: '#92400E' },
    calidad:       { label: 'Calidad',       bg: '#E9D5FF', text: '#6B21A8' },
    despachada:    { label: 'Despachada',    bg: '#D1FAE5', text: '#065F46' },
}

const ESTADOS_ITEM = {
    pendiente:  { label: 'Pendiente',  bg: '#F3F4F6', text: '#6B7280' },
    en_proceso: { label: 'En proceso', bg: '#DBEAFE', text: '#1D4ED8' },
    terminado:  { label: 'Terminado',  bg: '#D1FAE5', text: '#065F46' },
}

const badge    = (e) => ESTADOS[e] ?? ESTADOS.borrador
const badgeItem = (e) => ESTADOS_ITEM[e] ?? ESTADOS_ITEM.pendiente
</script>

<template>
    <div class="min-h-screen" style="background:#F8F9FA;">
        <!-- Header -->
        <div style="background:var(--marca);" class="px-4 py-4">
            <div class="max-w-xl mx-auto flex items-center justify-between">
                <div>
                    <p class="text-white text-xs opacity-70 font-medium uppercase tracking-[0.12em]">{{ $page.props.marca.nombre }}</p>
                    <h1 class="text-white text-xl font-semibold mt-0.5">{{ op.numero }}</h1>
                </div>
                <img :src="$page.props.marca.logo"
                    :alt="$page.props.marca.nombre" class="h-10 w-auto opacity-90" />
            </div>
        </div>

        <div class="max-w-xl mx-auto px-4 py-5 space-y-4">

            <!-- Estado + cliente -->
            <div class="bg-white rounded-2xl border border-linea p-4 shadow-sm">
                <div class="flex items-center gap-3 mb-3">
                    <span class="text-sm px-3 py-1 rounded-full font-semibold"
                        :style="`background:${badge(op.estado).bg};color:${badge(op.estado).text};`">
                        {{ badge(op.estado).label }}
                    </span>
                </div>
                <div class="space-y-1.5 text-sm">
                    <div v-if="op.cliente_nombre" class="flex justify-between">
                        <span class="text-tinta-400">Cliente</span>
                        <span class="font-medium text-tinta-900">{{ op.cliente_nombre }}</span>
                    </div>
                    <div v-if="op.fecha_creacion" class="flex justify-between">
                        <span class="text-tinta-400">Creada</span>
                        <span class="text-tinta-700">{{ op.fecha_creacion }}</span>
                    </div>
                    <div v-if="op.fecha_entrega" class="flex justify-between">
                        <span class="text-tinta-400">Entrega estimada</span>
                        <span class="text-amber-600 font-medium">{{ op.fecha_entrega }}</span>
                    </div>
                </div>
            </div>

            <!-- Progreso general -->
            <div class="bg-white rounded-2xl border border-linea p-4 shadow-sm">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-sm font-semibold text-tinta-700">Progreso general</span>
                    <span class="text-lg font-semibold" style="color:var(--marca);">
                        {{ parseFloat(op.porcentaje_avance ?? 0).toFixed(1) }}%
                    </span>
                </div>
                <div class="h-3 rounded-full bg-tinta-100 overflow-hidden">
                    <div class="h-full rounded-full transition-all duration-500"
                        :style="`width:${Math.min(parseFloat(op.porcentaje_avance ?? 0), 100)}%; background:var(--marca);`">
                    </div>
                </div>
                <div class="flex justify-between text-xs text-tinta-300 mt-1.5">
                    <span>{{ op.items?.filter(i => i.estado_item === 'terminado').length ?? 0 }} terminados</span>
                    <span>{{ op.items?.length ?? 0 }} ítems</span>
                </div>
            </div>

            <!-- Ítems -->
            <div v-if="op.items?.length" class="bg-white rounded-2xl border border-linea overflow-hidden shadow-sm">
                <div class="px-4 py-3 border-b border-linea">
                    <h2 class="text-sm font-semibold text-tinta-700">Ítems en producción</h2>
                </div>
                <div class="divide-y divide-gray-50">
                    <div v-for="(item, idx) in op.items" :key="idx" class="px-4 py-3">
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-sm font-medium text-tinta-900 flex-1 mr-3">{{ item.descripcion }}</span>
                            <span class="text-xs px-2 py-0.5 rounded-full font-medium shrink-0"
                                :style="`background:${badgeItem(item.estado_item).bg};color:${badgeItem(item.estado_item).text};`">
                                {{ badgeItem(item.estado_item).label }}
                            </span>
                        </div>
                        <!-- Progreso por unidades -->
                        <div v-if="item.trabajos?.length" class="space-y-1">
                            <div v-for="t in item.trabajos" :key="t.numero_unidad"
                                class="flex items-center gap-2">
                                <span class="text-xs text-tinta-300 w-16 shrink-0">
                                    U{{ t.numero_unidad }}/{{ t.total_unidades }}
                                </span>
                                <div class="flex-1 bg-tinta-100 rounded-full h-1.5">
                                    <div class="h-1.5 rounded-full transition-all"
                                        :style="`width:${Math.min(t.porcentaje_avance, 100)}%; background:var(--marca);`">
                                    </div>
                                </div>
                                <span class="text-xs font-semibold shrink-0" style="color:var(--marca); width:32px; text-align:right;">
                                    {{ Math.round(t.porcentaje_avance) }}%
                                </span>
                            </div>
                        </div>
                        <div v-else class="text-xs text-tinta-300 mt-1">Sin trabajos asignados</div>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <p class="text-center text-xs text-tinta-300 pb-6">
                {{ $page.props.marca.nombre }}
            </p>
        </div>
    </div>
</template>
