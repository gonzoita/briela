<script setup>
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

defineProps({
    templates: Array,
})

function eliminar(t) {
    if (confirm(`¿Eliminar template "${t.nombre}"?`)) {
        router.delete(`/produccion/templates/${t.id}`)
    }
}
</script>

<template>
    <AppLayout title="Templates de Trabajo">
        <div class="max-w-4xl mx-auto">

            <div class="flex items-center justify-between mb-5">
                <h1 class="text-xl font-bold text-gray-900">Templates de Trabajo</h1>
                <a href="/produccion/templates/crear"
                    @click.prevent="router.visit('/produccion/templates/crear')"
                    class="px-4 py-2 rounded-xl text-white text-sm font-semibold"
                    style="background:var(--marca);">
                    + Nuevo
                </a>
            </div>

            <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
                <div v-if="!templates.length" class="py-12 text-center text-sm text-gray-400">
                    No hay templates registrados.
                </div>
                <div v-else class="divide-y divide-gray-50">
                    <div v-for="t in templates" :key="t.id"
                        class="flex items-center px-5 py-4 gap-4">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <p class="text-sm font-semibold text-gray-900">{{ t.nombre }}</p>
                                <span v-if="t.plantilla_nombre"
                                    class="text-xs px-2 py-0.5 rounded-full bg-purple-100 text-purple-700">
                                    {{ t.plantilla_nombre }}
                                </span>
                                <span v-if="!t.activo"
                                    class="text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-500">
                                    Inactivo
                                </span>
                            </div>
                            <p class="text-xs text-gray-400 mt-0.5">{{ t.pasos_count }} pasos</p>
                        </div>

                        <!-- Badge suma pesos -->
                        <div class="shrink-0">
                            <span class="text-xs font-bold px-2.5 py-1 rounded-full"
                                :class="Math.abs(t.suma_pesos - 100) < 0.1
                                    ? 'bg-green-100 text-green-700'
                                    : 'bg-red-100 text-red-700'">
                                {{ t.suma_pesos.toFixed(0) }}%
                            </span>
                        </div>

                        <div class="flex items-center gap-2 shrink-0">
                            <a :href="`/produccion/templates/${t.id}/editar`"
                                @click.prevent="router.visit(`/produccion/templates/${t.id}/editar`)"
                                class="px-3 py-1.5 rounded-xl border border-gray-200 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                Editar
                            </a>
                            <button @click="eliminar(t)"
                                class="px-3 py-1.5 rounded-xl border border-red-100 text-xs font-medium text-red-500 hover:bg-red-50">
                                Eliminar
                            </button>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </AppLayout>
</template>
