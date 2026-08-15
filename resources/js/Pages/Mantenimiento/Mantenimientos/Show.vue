<script setup>
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    mantenimiento: Object,
})

const fmt = (n) => Number(n || 0).toLocaleString('es-CO')

const tipoBadge = {
    preventivo: 'bg-pastel-azul-2 text-aviso-azul',
    correctivo: 'bg-pastel-rojo-2 text-aviso-rojo',
    predictivo: 'bg-pastel-violeta-2 text-aviso-violeta',
}
const estadoBadge = {
    programado: 'bg-tinta-100 text-tinta-500',
    en_proceso: 'bg-pastel-azul-2 text-aviso-azul',
    completado: 'bg-pastel-verde-2 text-aviso-verde',
    cancelado:  'bg-pastel-rojo-2 text-aviso-rojo',
}
</script>

<template>
    <AppLayout title="Detalle de mantenimiento">
        <div class="max-w-2xl mx-auto space-y-4">

            <div class="flex items-center gap-3">
                <a href="/mantenimiento/mantenimientos"
                    @click.prevent="router.visit('/mantenimiento/mantenimientos')"
                    class="text-tinta-300 hover:text-tinta-700">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M15 19l-7-7 7-7"/>
                    </svg>
                </a>
                <div class="flex-1">
                    <h1 class="text-xl font-semibold text-tinta-900">{{ mantenimiento.equipo?.nombre }}</h1>
                    <div class="flex gap-2 mt-1">
                        <span class="text-xs px-2 py-0.5 rounded-full font-medium"
                            :class="tipoBadge[mantenimiento.tipo]">{{ mantenimiento.tipo }}</span>
                        <span class="text-xs px-2 py-0.5 rounded-full font-medium"
                            :class="estadoBadge[mantenimiento.estado]">{{ mantenimiento.estado }}</span>
                    </div>
                </div>
                <button @click="router.visit(`/mantenimiento/mantenimientos/${mantenimiento.id}/editar`)"
                    class="px-3 py-1.5 rounded-xl border border-linea text-xs font-medium text-tinta-500 hover:bg-tinta-50 shrink-0">
                    Editar
                </button>
            </div>

            <!-- Info -->
            <div class="bg-superficie rounded-2xl border border-linea p-5">
                <h2 class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-3">Datos</h2>
                <div class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <div>
                        <p class="text-xs text-tinta-300">Fecha programada</p>
                        <p class="font-medium text-tinta-900">{{ mantenimiento.fecha_programada }}</p>
                    </div>
                    <div v-if="mantenimiento.fecha_fin">
                        <p class="text-xs text-tinta-300">Fecha fin</p>
                        <p class="font-medium text-tinta-900">{{ mantenimiento.fecha_fin }}</p>
                    </div>
                    <div v-if="mantenimiento.ejecutor_nombre">
                        <p class="text-xs text-tinta-300">Ejecutor</p>
                        <p class="font-medium text-tinta-900">{{ mantenimiento.ejecutor_nombre }} ({{ mantenimiento.ejecutor_tipo }})</p>
                    </div>
                    <div v-if="mantenimiento.tiempo_horas">
                        <p class="text-xs text-tinta-300">Tiempo</p>
                        <p class="font-medium text-tinta-900">{{ mantenimiento.tiempo_horas }} h</p>
                    </div>
                </div>

                <div v-if="mantenimiento.descripcion" class="mt-4 pt-3 border-t border-linea">
                    <p class="text-xs text-tinta-300 mb-1">Descripción</p>
                    <p class="text-sm text-tinta-700">{{ mantenimiento.descripcion }}</p>
                </div>
                <div v-if="mantenimiento.hallazgos" class="mt-3">
                    <p class="text-xs text-tinta-300 mb-1">Hallazgos</p>
                    <p class="text-sm text-tinta-700">{{ mantenimiento.hallazgos }}</p>
                </div>
                <div v-if="mantenimiento.acciones" class="mt-3">
                    <p class="text-xs text-tinta-300 mb-1">Acciones realizadas</p>
                    <p class="text-sm text-tinta-700">{{ mantenimiento.acciones }}</p>
                </div>
            </div>

            <!-- Repuestos -->
            <div v-if="mantenimiento.repuestos?.length" class="bg-superficie rounded-2xl border border-linea overflow-hidden">
                <div class="px-5 py-3 border-b border-linea">
                    <h2 class="text-sm font-semibold text-tinta-700">Repuestos / materiales</h2>
                </div>
                <div class="divide-y divide-separador">
                    <div v-for="r in mantenimiento.repuestos" :key="r.id"
                        class="flex items-center px-5 py-3 gap-3">
                        <div class="flex-1">
                            <p class="text-sm font-medium text-tinta-900">{{ r.nombre }}</p>
                            <p class="text-xs text-tinta-300">{{ r.referencia ?? '—' }} · {{ r.cantidad }} {{ r.unidad }}</p>
                        </div>
                        <span class="text-sm font-semibold text-tinta-700">${{ fmt(r.subtotal) }}</span>
                    </div>
                </div>
            </div>

            <!-- Costos -->
            <div class="bg-tinta-50 rounded-2xl border border-linea p-4">
                <div class="flex justify-between text-sm mb-1">
                    <span class="text-tinta-400">Mano de obra</span>
                    <span>${{ fmt(mantenimiento.costo_mano_obra) }}</span>
                </div>
                <div class="flex justify-between text-sm mb-1">
                    <span class="text-tinta-400">Repuestos</span>
                    <span>${{ fmt(mantenimiento.costo_repuestos) }}</span>
                </div>
                <div class="flex justify-between font-semibold mt-2 pt-2 border-t border-linea">
                    <span>Total</span>
                    <span style="color:var(--marca);">${{ fmt(mantenimiento.costo_total) }}</span>
                </div>
            </div>

        </div>
    </AppLayout>
</template>
