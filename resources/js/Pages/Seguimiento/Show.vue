<script setup>
import { Head } from '@inertiajs/vue3'
import QrCode from '@/Components/QrCode.vue'

const props = defineProps({
    op:   { type: Object, required: true },
    item: { type: Object, default: null },
})

const estadosBadge = {
    'en_produccion':    { bg: '#DBEAFE', text: '#1D4ED8', label: 'En producción' },
    'calidad_aprobada': { bg: '#D1FAE5', text: '#065F46', label: 'Calidad aprobada' },
    'empacado':         { bg: '#FEF3C7', text: '#92400E', label: 'Empacado' },
    'despachado':       { bg: '#F3F4F6', text: '#374151', label: 'Despachado' },
    'instalado':        { bg: '#111827', text: '#FFFFFF', label: 'Instalado' },
}

const estadoSerieBadge = (estado) => estadosBadge[estado] ?? { bg: '#F3F4F6', text: '#374151', label: estado }

// Pasos del timeline
const ESTADOS_ORDEN = [
    'borrador', 'pendiente_verificacion', 'en_produccion',
    'control_calidad', 'reproceso', 'empaque', 'despachada', 'cerrada',
]

const pasoActualIndex = ESTADOS_ORDEN.indexOf(props.op.estado)

const timeline = [
    {
        label: 'Anticipo recibido',
        fecha: props.op.fecha_anticipo,
        completado: pasoActualIndex >= 0,
        icono: 'check',
    },
    {
        label: 'En producción',
        fecha: props.op.fecha_inicio_produccion,
        completado: pasoActualIndex >= ESTADOS_ORDEN.indexOf('en_produccion'),
        activo: props.op.estado === 'en_produccion',
        icono: 'wrench',
    },
    {
        label: 'Control de calidad',
        fecha: props.op.fecha_verificacion,
        completado: pasoActualIndex >= ESTADOS_ORDEN.indexOf('control_calidad'),
        activo: props.op.estado === 'control_calidad',
        icono: 'shield',
    },
    {
        label: 'Empaque',
        fecha: null,
        completado: pasoActualIndex >= ESTADOS_ORDEN.indexOf('empaque'),
        activo: props.op.estado === 'empaque',
        icono: 'box',
    },
    {
        label: 'Despachado',
        extra: props.op.numero_remision ? `Remisión: ${props.op.numero_remision}` : null,
        fecha: props.op.fecha_despacho,
        completado: pasoActualIndex >= ESTADOS_ORDEN.indexOf('despachada'),
        activo: props.op.estado === 'despachada',
        icono: 'truck',
    },
]

const compartir = () => {
    if (navigator.share) {
        navigator.share({
            title: `OP ${props.op.numero_op}`,
            text: `Consulta el estado de tu pedido ${props.op.numero_op}`,
            url: window.location.href,
        })
    }
}
</script>

<template>
    <Head :title="`${op.numero_op} — Seguimiento`" />

    <div class="min-h-screen pb-8" style="background: #F8F9FA;">
        <!-- Header -->
        <header class="px-6 pt-8 pb-6" style="background: white; border-bottom: 1px solid #E5E7EB;">
            <img
                :src="$page.props.marca.logo"
                class="h-8 w-auto object-contain mb-4"
                :alt="$page.props.marca.nombre"
            />
            <div class="flex items-start justify-between">
                <div>
                    <p class="text-2xl font-bold" style="color: var(--marca);">{{ op.numero_op }}</p>
                    <p class="text-gray-600 text-sm mt-0.5">{{ op.cliente }}</p>
                </div>
                <button
                    @click="compartir"
                    class="w-10 h-10 rounded-full flex items-center justify-center border border-gray-200"
                >
                    <svg class="w-5 h-5 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" />
                    </svg>
                </button>
            </div>
        </header>

        <div class="px-4 mt-6 space-y-4">
            <!-- Ítem específico (si se buscó por serie) -->
            <div v-if="item" class="rounded-2xl shadow-sm p-5" style="background: white;">
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-3">Ítem</p>
                <div class="flex items-center justify-between mb-2">
                    <span class="font-mono font-bold text-base" style="color: var(--marca);">{{ item.numero_serie }}</span>
                    <span
                        class="text-xs font-semibold px-2.5 py-1 rounded-full"
                        :style="{ background: estadoSerieBadge(item.estado_serie).bg, color: estadoSerieBadge(item.estado_serie).text }"
                    >
                        {{ item.estado_serie_label }}
                    </span>
                </div>
                <p class="text-sm text-gray-600">{{ item.tipo_label }}</p>
                <p class="text-sm text-gray-500 mt-1">{{ item.resumen }}</p>

                <div class="mt-4 pt-4 border-t border-gray-100 flex justify-center">
                    <QrCode
                        :value="`/seguimiento/${item.numero_serie}`"
                        :size="160"
                        :label="item.numero_serie"
                    />
                </div>
            </div>

            <!-- Timeline de estados -->
            <div class="rounded-2xl shadow-sm p-5" style="background: white;">
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-4">Estado del pedido</p>

                <div class="space-y-0">
                    <div
                        v-for="(paso, i) in timeline"
                        :key="i"
                        class="flex gap-4"
                    >
                        <!-- Línea + ícono -->
                        <div class="flex flex-col items-center">
                            <div
                                class="w-8 h-8 rounded-full flex items-center justify-center shrink-0 z-10"
                                :style="{
                                    background: paso.completado ? 'var(--marca)' : (paso.activo ? '#DBEAFE' : '#F3F4F6'),
                                    color: paso.completado ? 'white' : (paso.activo ? 'var(--marca)' : '#9CA3AF'),
                                }"
                            >
                                <!-- Check -->
                                <svg v-if="paso.completado" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                </svg>
                                <!-- Spinning / activo -->
                                <svg v-else-if="paso.activo" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="3" fill="currentColor" />
                                </svg>
                                <!-- Pendiente -->
                                <div v-else class="w-2 h-2 rounded-full bg-gray-300" />
                            </div>
                            <!-- Línea conectora -->
                            <div
                                v-if="i < timeline.length - 1"
                                class="w-0.5 flex-1 my-1"
                                style="min-height: 24px;"
                                :style="{ background: paso.completado ? 'var(--marca)' : '#E5E7EB' }"
                            />
                        </div>

                        <!-- Contenido del paso -->
                        <div class="pb-6 min-w-0 flex-1">
                            <p
                                class="font-medium text-sm"
                                :style="{ color: paso.completado ? '#111827' : (paso.activo ? 'var(--marca)' : '#9CA3AF') }"
                            >
                                {{ paso.label }}
                            </p>
                            <p v-if="paso.fecha" class="text-xs text-gray-400 mt-0.5">{{ paso.fecha }}</p>
                            <p v-if="paso.extra" class="text-xs mt-0.5" style="color: var(--marca);">{{ paso.extra }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Volver -->
            <a
                href="/seguimiento"
                class="block text-center text-sm py-3 rounded-xl border"
                style="color: var(--marca); border-color: var(--marca);"
            >
                Consultar otro pedido
            </a>
        </div>

        <!-- Footer -->
        <footer class="px-6 mt-8 text-center">
            <p class="text-xs text-gray-400">{{ $page.props.marca.nombre }}{{ $page.props.marca.email ? " · " + $page.props.marca.email : "" }}</p>
        </footer>
    </div>
</template>
