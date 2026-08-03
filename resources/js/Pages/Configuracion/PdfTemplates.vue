<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import { router } from '@inertiajs/vue3'

const props = defineProps({
    modulos: Array,
})

const ICONOS_MODULO = {
    cotizacion:  { bg: '#DBEAFE', color: '#1D4ED8' },
    op:          { bg: '#D1FAE5', color: '#065F46' },
    remision:    { bg: '#FEF3C7', color: '#92400E' },
    recibo_pago: { bg: '#FCE7F3', color: '#9D174D' },
    trabajo:     { bg: '#EDE9FE', color: '#5B21B6' },
    etiqueta_op: { bg: '#FEE2E2', color: '#991B1B' },
}

const colorPrimario = (modulo) => modulo?.template?.color_primario ?? '#0A4283'
const colorIcono    = (key) => ICONOS_MODULO[key] ?? { bg: '#E5E7EB', color: '#374151' }
</script>

<template>
    <AppLayout title="Plantillas PDF">
        <div class="max-w-4xl mx-auto space-y-6">
            <!-- Cabecera -->
            <div>
                <h2 class="text-xl font-bold text-gray-900">Plantillas PDF</h2>
                <p class="text-sm text-gray-500 mt-1">
                    Personaliza el diseño de los documentos PDF del sistema sin tocar código.
                </p>
            </div>

            <!-- Grid de tarjetas -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <div
                    v-for="modulo in modulos"
                    :key="modulo.key"
                    class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5 flex flex-col gap-4"
                >
                    <!-- Ícono + color -->
                    <div class="flex items-start justify-between">
                        <div
                            class="w-11 h-11 rounded-xl flex items-center justify-center shrink-0"
                            :style="{ backgroundColor: colorIcono(modulo.key).bg }"
                        >
                            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
                                :style="{ color: colorIcono(modulo.key).color }">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                            </svg>
                        </div>
                        <!-- Muestra de color primario -->
                        <div class="flex items-center gap-1.5">
                            <div
                                class="w-5 h-5 rounded-full border-2 border-white shadow"
                                :style="{ backgroundColor: colorPrimario(modulo) }"
                            />
                            <span class="text-xs text-gray-400 font-mono">{{ colorPrimario(modulo) }}</span>
                        </div>
                    </div>

                    <!-- Nombre y secciones -->
                    <div class="flex-1">
                        <h3 class="font-semibold text-gray-900 text-sm">{{ modulo.label }}</h3>
                        <p class="text-xs text-gray-400 mt-0.5">
                            {{ modulo.template?.nombre ?? 'Sin configurar' }}
                        </p>
                        <div class="flex flex-wrap gap-1 mt-2">
                            <span
                                v-for="sec in modulo.secciones.slice(0, 3)"
                                :key="sec"
                                class="text-xs px-1.5 py-0.5 rounded bg-gray-100 text-gray-500"
                            >
                                {{ sec }}
                            </span>
                            <span
                                v-if="modulo.secciones.length > 3"
                                class="text-xs px-1.5 py-0.5 rounded bg-gray-100 text-gray-400"
                            >
                                +{{ modulo.secciones.length - 3 }}
                            </span>
                        </div>
                    </div>

                    <!-- Botón -->
                    <button
                        @click="router.visit(`/configuracion/pdf-templates/${modulo.key}/editar`)"
                        class="w-full py-2 px-4 rounded-xl text-sm font-medium text-white transition-colors"
                        style="background-color: var(--marca);"
                    >
                        Editar plantilla
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
