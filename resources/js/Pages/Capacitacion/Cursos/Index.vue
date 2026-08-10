<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import { router } from '@inertiajs/vue3'
import { colorMarca } from '@/marca'

const props = defineProps({
    cursos: { type: Array, default: () => [] },
})

const publicoLabel = (v) => ({
    colaborador: 'Colaboradores',
    contratista: 'Contratistas',
    cliente:     'Clientes',
    todos:       'Todos',
}[v] ?? v)

const publicoColor = (v) => ({
    colaborador: colorMarca(),
    contratista: '#B45309',
    cliente:     '#065F46',
    todos:       '#4B5563',
}[v] ?? '#4B5563')

const inicial = (t) => (t ?? '?').charAt(0).toUpperCase()

function toggleActivo(curso) {
    router.post(`/capacitacion/cursos/${curso.id}/toggle-activo`, {}, { preserveScroll: true })
}

function eliminar(curso) {
    if (!confirm(`¿Eliminar el curso "${curso.titulo}"? Esta acción no se puede deshacer.`)) return
    router.delete(`/capacitacion/cursos/${curso.id}`)
}
</script>

<template>
    <AppLayout title="Capacitación">
        <div class="max-w-5xl mx-auto">

            <!-- Cabecera -->
            <div class="flex items-center justify-between mb-5 flex-wrap gap-3">
                <div>
                    <h1 class="text-xl font-semibold text-tinta-900">Capacitación</h1>
                    <p class="text-sm text-tinta-400 mt-0.5">Cursos virtuales para colaboradores, contratistas y clientes</p>
                </div>
                <a href="/capacitacion/cursos/crear"
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold text-white"
                    style="background:var(--marca);"
                    @click.prevent="router.visit('/capacitacion/cursos/crear')">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                    </svg>
                    Nuevo curso
                </a>
            </div>

            <!-- Vacío -->
            <div v-if="cursos.length === 0" class="bg-white rounded-2xl shadow-sm py-16 text-center text-tinta-300">
                <svg class="w-12 h-12 mx-auto mb-3 text-tinta-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.422A12.083 12.083 0 0121 15.5V17a2 2 0 01-2 2H5a2 2 0 01-2-2v-1.5c0-.994.212-1.964.582-2.858L12 14z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 14v7"/>
                </svg>
                <p class="text-sm">Sin cursos. <a href="/capacitacion/cursos/crear" class="text-blue-600 hover:underline" @click.prevent="router.visit('/capacitacion/cursos/crear')">Crea el primero</a>.</p>
            </div>

            <!-- Grid de tarjetas -->
            <div v-else class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                <div v-for="c in cursos" :key="c.id"
                    class="bg-white rounded-2xl shadow-sm overflow-hidden cursor-pointer active:scale-[0.98] transition-transform"
                    :class="{ 'opacity-50': !c.activo }"
                    @click="router.visit(`/capacitacion/cursos/${c.id}`)">
                    <div class="aspect-video overflow-hidden" style="background:#F1F5F9;">
                        <img v-if="c.imagen_portada" :src="c.imagen_portada" :alt="c.titulo" class="w-full h-full object-cover"/>
                        <div v-else class="w-full h-full flex items-center justify-center text-white text-3xl font-semibold" style="background:var(--marca);">
                            {{ inicial(c.titulo) }}
                        </div>
                    </div>
                    <div class="p-3">
                        <p class="text-sm font-semibold text-tinta-900 line-clamp-2 mb-1">{{ c.titulo }}</p>
                        <p v-if="c.categoria" class="text-xs text-tinta-300 mb-2">{{ c.categoria }}</p>
                        <div class="flex items-center gap-1.5 flex-wrap mb-2">
                            <span class="inline-block px-2 py-0.5 rounded-full text-xs font-semibold text-white"
                                :style="`background:${publicoColor(c.publico_objetivo)};`">{{ publicoLabel(c.publico_objetivo) }}</span>
                            <span v-if="c.obligatorio" class="inline-block px-2 py-0.5 rounded-full text-xs font-semibold text-white" style="background:#B91C1C;">Obligatorio</span>
                        </div>
                        <p class="text-xs text-tinta-400 mb-2">{{ c.modulos_count }} módulo{{ c.modulos_count === 1 ? '' : 's' }} · {{ c.lecciones_count }} lección{{ c.lecciones_count === 1 ? '' : 'es' }}</p>
                        <div class="flex items-center justify-between" @click.stop>
                            <button @click="toggleActivo(c)"
                                class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors shrink-0"
                                :style="c.activo ? 'background:var(--marca);' : 'background:#D1D5DB;'">
                                <span class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform"
                                    :style="c.activo ? 'transform:translateX(18px);' : 'transform:translateX(2px);'"/>
                            </button>
                            <button @click="eliminar(c)" class="w-7 h-7 rounded-lg flex items-center justify-center text-tinta-300 hover:text-red-600 hover:bg-red-50 transition-colors">
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
