<script setup>
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    stats:      Object,
    alertas:    Array,
    proximos:   Array,
    en_proceso: Array,
})

const fmt = (n) => Number(n).toLocaleString('es-CO')
</script>

<template>
    <AppLayout title="Mantenimiento">
        <div class="max-w-4xl mx-auto space-y-5">

            <div class="flex items-center justify-between">
                <h1 class="text-xl font-semibold text-tinta-900">Mantenimiento</h1>
                <a href="/mantenimiento/mantenimientos/crear"
                    @click.prevent="router.visit('/mantenimiento/mantenimientos/crear')"
                    class="px-4 py-2 rounded-xl text-white text-sm font-semibold"
                    style="background:var(--marca);">
                    + Nuevo mantenimiento
                </a>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <div class="bg-superficie rounded-2xl border border-linea p-4 text-center">
                    <p class="text-2xl font-semibold text-tinta-900">{{ stats.total_equipos }}</p>
                    <p class="text-xs text-tinta-400 mt-0.5">Equipos</p>
                </div>
                <div class="bg-green-50 rounded-2xl border border-green-200 p-4 text-center">
                    <p class="text-2xl font-semibold text-green-700">{{ stats.activos }}</p>
                    <p class="text-xs text-green-600 mt-0.5">Activos</p>
                </div>
                <div class="bg-amber-50 rounded-2xl border border-amber-200 p-4 text-center">
                    <p class="text-2xl font-semibold text-amber-700">{{ stats.en_mantenimiento }}</p>
                    <p class="text-xs text-amber-600 mt-0.5">En mant.</p>
                </div>
                <div class="bg-blue-50 rounded-2xl border border-blue-200 p-4 text-center">
                    <p class="text-2xl font-semibold text-blue-700">{{ stats.completados_mes }}</p>
                    <p class="text-xs text-blue-600 mt-0.5">Completados mes</p>
                </div>
            </div>

            <!-- Alertas vencidas -->
            <div v-if="alertas.length" class="bg-red-50 rounded-2xl border border-red-200 p-4">
                <p class="text-sm font-semibold text-red-700 mb-3">
                    <span class="inline-block w-2 h-2 bg-red-500 rounded-full mr-1.5"></span>
                    {{ alertas.length }} equipo(s) con revisión vencida
                </p>
                <div class="space-y-2">
                    <div v-for="e in alertas" :key="e.id"
                        class="flex items-center justify-between bg-superficie rounded-xl p-3 border border-red-100 cursor-pointer hover:bg-red-50"
                        @click="router.visit(`/mantenimiento/equipos/${e.id}`)">
                        <div>
                            <p class="text-sm font-medium text-tinta-900">{{ e.nombre }}</p>
                            <p class="text-xs text-red-500">Venció: {{ e.proxima_revision }}</p>
                        </div>
                        <svg class="w-4 h-4 text-tinta-300" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                        </svg>
                    </div>
                </div>
            </div>

            <!-- Próximos 7 días -->
            <div v-if="proximos.length" class="bg-amber-50 rounded-2xl border border-amber-200 p-4">
                <p class="text-sm font-semibold text-amber-700 mb-3">Revisiones en los próximos 7 días</p>
                <div class="space-y-2">
                    <div v-for="e in proximos" :key="e.id"
                        class="flex items-center justify-between bg-superficie rounded-xl p-3 border border-amber-100 cursor-pointer hover:bg-amber-50"
                        @click="router.visit(`/mantenimiento/equipos/${e.id}`)">
                        <div>
                            <p class="text-sm font-medium text-tinta-900">{{ e.nombre }}</p>
                            <p class="text-xs text-amber-600">Próxima revisión: {{ e.proxima_revision }}</p>
                        </div>
                        <svg class="w-4 h-4 text-tinta-300" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                        </svg>
                    </div>
                </div>
            </div>

            <!-- En proceso -->
            <div v-if="en_proceso.length" class="bg-superficie rounded-2xl border border-linea overflow-hidden">
                <div class="px-5 py-3 border-b border-linea">
                    <h2 class="text-sm font-semibold text-tinta-700">En proceso ahora</h2>
                </div>
                <div class="divide-y divide-separador">
                    <div v-for="m in en_proceso" :key="m.id"
                        class="flex items-center px-5 py-3 gap-3 hover:bg-tinta-50 cursor-pointer"
                        @click="router.visit(`/mantenimiento/mantenimientos/${m.id}`)">
                        <span class="w-2 h-2 rounded-full bg-blue-400 shrink-0"></span>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-tinta-900">{{ m.equipo?.nombre }}</p>
                            <p class="text-xs text-tinta-300">{{ m.tipo }} · {{ m.ejecutor_nombre ?? 'Sin ejecutor' }}</p>
                        </div>
                        <span class="text-xs px-2 py-0.5 rounded-full bg-blue-100 text-blue-700 font-medium shrink-0">En proceso</span>
                    </div>
                </div>
            </div>

            <div v-if="!alertas.length && !proximos.length && !en_proceso.length"
                class="bg-superficie rounded-2xl border border-linea py-12 text-center">
                <div class="text-4xl mb-2">✓</div>
                <p class="text-sm font-medium text-tinta-500">Todo al día</p>
                <p class="text-xs text-tinta-300 mt-1">No hay alertas ni revisiones pendientes esta semana</p>
            </div>

            <div class="flex gap-3">
                <a href="/mantenimiento/equipos"
                    @click.prevent="router.visit('/mantenimiento/equipos')"
                    class="flex-1 py-3 rounded-xl border border-linea text-sm text-tinta-500 font-medium text-center">
                    Ver equipos
                </a>
                <a href="/mantenimiento/mantenimientos"
                    @click.prevent="router.visit('/mantenimiento/mantenimientos')"
                    class="flex-1 py-3 rounded-xl border border-linea text-sm text-tinta-500 font-medium text-center">
                    Ver mantenimientos
                </a>
            </div>

        </div>
    </AppLayout>
</template>
