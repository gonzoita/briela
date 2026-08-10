<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import { router } from '@inertiajs/vue3'

const props = defineProps({
    intentos: { type: Array, default: () => [] },
})

function formatearFecha(fecha) {
    if (!fecha) return ''
    return new Date(fecha).toLocaleString('es-CO', { dateStyle: 'medium', timeStyle: 'short' })
}
</script>

<template>
    <AppLayout title="Revisión de evaluaciones">
        <div class="max-w-2xl mx-auto">

            <div class="flex items-center gap-3 mb-5">
                <button @click="router.visit('/capacitacion/cursos')" class="w-9 h-9 rounded-xl flex items-center justify-center text-tinta-400 hover:bg-tinta-100 transition-colors shrink-0">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                    </svg>
                </button>
                <h1 class="text-xl font-semibold text-tinta-900 truncate flex-1">Revisión de evaluaciones</h1>
            </div>

            <div v-if="intentos.length === 0" class="bg-white rounded-2xl shadow-sm py-16 text-center text-tinta-300">
                <p class="text-sm">No hay evaluaciones pendientes de revisión.</p>
            </div>

            <div v-else class="space-y-3">
                <div v-for="intento in intentos" :key="intento.id"
                    class="bg-white rounded-2xl shadow-sm p-4 flex items-center gap-3 cursor-pointer active:scale-[0.99] transition-transform"
                    @click="router.visit(`/capacitacion/revision-evaluaciones/${intento.id}`)">
                    <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0" style="background:#FEF3C7;">
                        <svg class="w-5 h-5" style="color:#92400E;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-semibold text-tinta-900 truncate">{{ intento.estudiante }}</p>
                        <p class="text-xs text-tinta-300 mt-0.5">{{ intento.curso }} · Intento #{{ intento.numero_intento }}</p>
                    </div>
                    <span class="text-xs text-tinta-300 shrink-0">{{ formatearFecha(intento.completado_at) }}</span>
                </div>
            </div>

        </div>
    </AppLayout>
</template>
