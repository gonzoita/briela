<script setup>
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    informes: Array,
})

const fuenteConfig = {
    ops:           { label: 'OPs',            color: 'bg-pastel-azul-2 text-aviso-azul' },
    colaboradores: { label: 'Colaboradores',  color: 'bg-pastel-verde-2 text-aviso-verde' },
    cotizaciones:  { label: 'Cotizaciones',   color: 'bg-pastel-naranja-2 text-aviso-naranja' },
    pasos:         { label: 'Pasos',          color: 'bg-pastel-violeta-2 text-aviso-violeta' },
}

function eliminar(informe) {
    if (!confirm(`¿Eliminar el informe "${informe.nombre}"?`)) return
    router.delete(`/informes/${informe.id}`)
}
</script>

<template>
    <AppLayout title="Informes">
        <div class="max-w-5xl mx-auto">

            <!-- Cabecera -->
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-xl font-semibold text-tinta-900">Informes Dinámicos</h1>
                    <p class="text-sm text-tinta-400 mt-0.5">Construye y ejecuta informes personalizados</p>
                </div>
                <a
                    href="/informes/crear"
                    @click.prevent="router.visit('/informes/crear')"
                    class="flex items-center gap-2 px-4 py-2 rounded-xl text-white text-sm font-semibold shadow-sm transition-opacity hover:opacity-90"
                    style="background-color: var(--marca);"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                    </svg>
                    Nuevo Informe
                </a>
            </div>

            <!-- Lista vacía -->
            <div v-if="!informes.length" class="text-center py-20">
                <div class="w-16 h-16 mx-auto mb-4 rounded-2xl flex items-center justify-center" style="background:var(--pastel-azul);">
                    <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="var(--marca)" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                </div>
                <p class="text-tinta-400 text-sm">No hay informes todavía.</p>
                <a href="/informes/crear" @click.prevent="router.visit('/informes/crear')"
                    class="mt-3 inline-block text-sm font-semibold" style="color:var(--marca);">
                    Crear el primero
                </a>
            </div>

            <!-- Grid de cards -->
            <div v-else class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div
                    v-for="informe in informes"
                    :key="informe.id"
                    class="bg-superficie rounded-2xl border border-linea shadow-sm p-5 flex flex-col gap-3"
                >
                    <!-- Fila superior: fuente + público -->
                    <div class="flex items-center gap-2">
                        <span
                            class="text-xs font-semibold px-2.5 py-1 rounded-full"
                            :class="fuenteConfig[informe.fuente]?.color ?? 'bg-tinta-100 text-tinta-500'"
                        >
                            {{ fuenteConfig[informe.fuente]?.label ?? informe.fuente }}
                        </span>
                        <span v-if="informe.publico"
                            class="text-xs font-semibold px-2.5 py-1 rounded-full bg-tinta-100 text-tinta-400">
                            Público
                        </span>
                        <span v-if="!informe.es_mio"
                            class="text-xs text-tinta-300 ml-auto">
                            de {{ informe.creador }}
                        </span>
                    </div>

                    <!-- Nombre y descripción -->
                    <div>
                        <h2 class="text-sm font-semibold text-tinta-900">{{ informe.nombre }}</h2>
                        <p v-if="informe.descripcion" class="text-xs text-tinta-400 mt-0.5 line-clamp-2">
                            {{ informe.descripcion }}
                        </p>
                    </div>

                    <!-- Footer: fecha + acciones -->
                    <div class="flex items-center justify-between pt-1 border-t border-separador mt-auto">
                        <span class="text-xs text-tinta-300">{{ informe.created_at }}</span>
                        <div class="flex items-center gap-1">
                            <!-- Ver -->
                            <a
                                :href="`/informes/${informe.id}`"
                                @click.prevent="router.visit(`/informes/${informe.id}`)"
                                class="p-1.5 rounded-lg text-tinta-400 hover:bg-tinta-100 transition-colors"
                                title="Ver informe"
                            >
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                </svg>
                            </a>
                            <!-- CSV -->
                            <a
                                :href="`/informes/${informe.id}/csv`"
                                class="p-1.5 rounded-lg text-tinta-400 hover:bg-tinta-100 transition-colors"
                                title="Exportar CSV"
                            >
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                                </svg>
                            </a>
                            <!-- PDF -->
                            <a
                                :href="`/informes/${informe.id}/pdf`"
                                class="p-1.5 rounded-lg text-tinta-400 hover:bg-tinta-100 transition-colors"
                                title="Exportar PDF"
                            >
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                                </svg>
                            </a>
                            <!-- Eliminar (solo si es mío) -->
                            <button
                                v-if="informe.es_mio"
                                @click="eliminar(informe)"
                                class="p-1.5 rounded-lg text-red-400 hover:bg-pastel-rojo transition-colors"
                                title="Eliminar"
                            >
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
