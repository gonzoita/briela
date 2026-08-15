<script setup>
import { ref, computed, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import OrdenarLista from '@/Components/OrdenarLista.vue'
import { useOrden } from '@/composables/useOrden'
import { usePublicacionWeb } from '@/composables/usePublicacionWeb'

const props = defineProps({
    ensambles:  { type: Object, default: () => ({ data: [] }) },
    plantillas: { type: Array, default: () => [] },
    categorias: { type: Array, default: () => [] },
    filters:    { type: Object, default: () => ({}) },
    // El orden vigente, que decide el servidor: { campo, dir }.
    orden: { type: Object, default: () => ({}) },
})

// Ordenar mantiene los filtros: reordenar no es empezar de cero.
const { ordenarPor } = useOrden('/ensambles', props.orden, props.filters)

const camposOrden = [
    { campo: 'nombre', etiqueta: 'Nombre' },
    { campo: 'precio_costo', etiqueta: 'Costo', texto: false },
    { campo: 'created_at', etiqueta: 'Más reciente', texto: false },
]

// ── Publicar en el sitio web, varios de una vez ────────────────────────────────
//
// Se trabaja sobre una copia local para poder marcar «en la web» sin recargar la
// pantalla. La prop no se muta nunca.
const ensamblesLocal = ref([])
watch(() => props.ensambles, (val) => {
    ensamblesLocal.value = (val?.data ?? []).map(e => ({ ...e }))
}, { immediate: true })

const page = usePage()
const puedeEditarEnsambles = computed(() => (page.props.auth?.permisosLista ?? []).includes('ensambles.editar'))

const {
    seleccion,
    publicando,
    todosMarcados,
    alternar: alternarSeleccion,
    alternarTodos,
    limpiar: limpiarSeleccion,
    publicar: publicarSeleccion,
} = usePublicacionWeb('ensamble', ensamblesLocal)

const buscar      = ref(props.filters.buscar ?? '')
const plantillaId = ref(props.filters.plantilla_id ?? '')
let   timer       = null

// Las imágenes nuevas se guardan como ruta relativa, pero las viejas quedaron
// con la URL completa de Google Drive. Se respeta la que ya venga absoluta.
const urlImagen = (v) => (!v ? null : (v.startsWith('http') ? v : `/storage/${v}`))

// ── Toggle vista lista/grid ────────────────────────────────────────────────────
const viewMode = ref(localStorage.getItem('ensambles_view') ?? 'list')
watch(viewMode, (v) => localStorage.setItem('ensambles_view', v))

function aplicarFiltros() {
    clearTimeout(timer)
    timer = setTimeout(() => {
        router.get('/ensambles', {
            buscar:       buscar.value || undefined,
            plantilla_id: plantillaId.value || undefined,
        }, { preserveState: true, replace: true })
    }, 300)
}

const formatCOP = (v) => new Intl.NumberFormat('es-CO', { maximumFractionDigits: 0 }).format(v ?? 0)

function eliminar(id) {
    if (!confirm('¿Eliminar este ensamble?')) return
    router.delete(`/ensambles/${id}`)
}

function inicial(nombre) {
    return (nombre ?? '?').charAt(0).toUpperCase()
}
</script>

<template>
    <AppLayout title="Ensambles">
        <div class="max-w-5xl mx-auto">

            <!-- Cabecera -->
            <div class="flex items-center justify-between mb-5 flex-wrap gap-3">
                <div>
                    <h1 class="text-xl font-semibold text-tinta-900">Ensambles</h1>
                    <p class="text-sm text-tinta-400 mt-0.5">Instancias configuradas desde plantillas de ensamble</p>
                </div>
                <div class="flex items-center gap-2">
                    <!-- Toggle lista/grid -->
                    <div class="flex items-center rounded-xl border border-linea bg-superficie overflow-hidden shadow-sm">
                        <button @click="viewMode = 'list'"
                            class="w-9 h-9 flex items-center justify-center transition-colors"
                            :style="viewMode === 'list' ? 'background:var(--marca);color:white;' : 'color:var(--texto-3);'"
                            title="Vista lista">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 10h16M4 14h16M4 18h16"/>
                            </svg>
                        </button>
                        <button @click="viewMode = 'grid'"
                            class="w-9 h-9 flex items-center justify-center transition-colors"
                            :style="viewMode === 'grid' ? 'background:var(--marca);color:white;' : 'color:var(--texto-3);'"
                            title="Vista grid">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 5a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1V5zm10 0a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1V5zM4 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1v-4zm10 0a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z"/>
                            </svg>
                        </button>
                    </div>
                    <a href="/ensambles/crear"
                        class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold text-white"
                        style="background:var(--marca);"
                        @click.prevent="router.visit('/ensambles/crear')">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                        </svg>
                        Nuevo ensamble
                    </a>
                </div>
            </div>

            <!-- Ordenar. Vale para las listas que son tabla y para las que son tarjetas, y
                 en celular es el único camino: ahí no hay encabezados donde hacer clic. -->
            <div class="mb-3">
                <OrdenarLista :campos="camposOrden" :orden="orden" @ordenar="ordenarPor" />
            </div>

            <!-- Filtros -->
            <div class="bg-superficie rounded-2xl shadow-sm p-4 mb-4 flex flex-wrap gap-3">
                <input
                    v-model="buscar"
                    @input="aplicarFiltros"
                    type="text"
                    placeholder="Buscar por nombre..."
                    class="flex-1 min-w-48 border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]"
                />
                <select v-model="plantillaId" @change="aplicarFiltros"
                    class="border border-linea rounded-xl px-3 py-2 text-sm text-tinta-700 focus:outline-none">
                    <option value="">Todas las plantillas</option>
                    <option v-for="p in plantillas" :key="p.id" :value="p.id">{{ p.nombre }}</option>
                </select>
            </div>

            <!-- Vacío -->
            <div v-if="ensambles.data?.length === 0" class="bg-superficie rounded-2xl shadow-sm py-16 text-center text-tinta-300">
                <svg class="w-12 h-12 mx-auto mb-3 text-tinta-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
                </svg>
                <p class="text-sm">Sin ensambles. <a href="/ensambles/crear" class="text-blue-600 hover:underline" @click.prevent="router.visit('/ensambles/crear')">Crea el primero</a>.</p>
            </div>

            <!-- VISTA LISTA -->
            <div v-else-if="viewMode === 'list'" class="bg-superficie rounded-2xl shadow-sm overflow-hidden">
                <!-- Seleccionar todos: publicar el catálogo la primera vez es de a uno sin esto -->
                <div v-if="puedeEditarEnsambles" class="px-4 py-2 border-b border-linea flex items-center gap-2"
                    style="background:var(--superficie-2);">
                    <input type="checkbox" :checked="todosMarcados" @change="alternarTodos"
                        class="w-4 h-4 rounded border-tinta-300 cursor-pointer" />
                    <span class="text-xs text-tinta-400">Seleccionar todos los de esta página</span>
                </div>
                <ul class="divide-y divide-gray-50">
                    <li v-for="e in ensamblesLocal" :key="e.id"
                        class="flex items-center gap-3 px-4 py-3 hover:bg-blue-50/40 transition-colors cursor-pointer"
                        @click="router.visit(`/ensambles/${e.id}`)">
                        <input v-if="puedeEditarEnsambles" type="checkbox" :checked="seleccion.has(e.id)"
                            @change="alternarSeleccion(e.id)" @click.stop
                            class="w-4 h-4 rounded border-tinta-300 cursor-pointer shrink-0" />
                        <div class="w-10 h-10 rounded-xl overflow-hidden shrink-0 shadow-sm">
                            <img v-if="e.imagen_principal" :src="urlImagen(e.imagen_principal)" :alt="e.nombre" class="w-full h-full object-cover"/>
                            <div v-else class="w-full h-full flex items-center justify-center text-white text-sm font-semibold"
                                :style="`background:${e.categoria_color ?? 'var(--marca)'};`">
                                {{ inicial(e.nombre) }}
                            </div>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold text-tinta-900 truncate">{{ e.nombre }}</p>
                            <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                                <span class="text-xs text-tinta-300 truncate">{{ e.plantilla_nombre }}</span>
                                <span v-if="e.categoria_nombre" class="inline-block px-1.5 py-0.5 rounded-full text-xs font-semibold text-white leading-none"
                                    :style="`background:${e.categoria_color ?? '#64748B'};`">{{ e.categoria_nombre }}</span>
                                <span v-if="e.publicado_web" class="inline-block px-1.5 py-0.5 rounded-full text-xs font-medium leading-none"
                                    style="background:var(--pastel-verde);color:var(--texto-verde);">En la web</span>
                            </div>
                        </div>
                        <div class="hidden sm:flex flex-col items-end shrink-0">
                            <p class="text-sm font-semibold text-tinta-900">${{ formatCOP(e.precio_distribuidor) }}</p>
                            <p class="text-xs text-tinta-300">Distribuidor</p>
                        </div>
                        <div class="flex items-center gap-1 shrink-0" @click.stop>
                            <button @click="router.visit(`/ensambles/${e.id}/editar`)"
                                class="w-8 h-8 rounded-lg flex items-center justify-center text-tinta-300 hover:text-[var(--marca)] hover:bg-[var(--marca-suave)] transition-colors">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
                                </svg>
                            </button>
                            <button @click="eliminar(e.id)"
                                class="w-8 h-8 rounded-lg flex items-center justify-center text-tinta-300 hover:text-red-600 hover:bg-red-50 transition-colors">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                            </button>
                        </div>
                    </li>
                </ul>
                <div v-if="ensambles.last_page > 1" class="px-5 py-4 border-t border-linea flex items-center justify-between">
                    <p class="text-xs text-tinta-400">{{ ensambles.total }} ensambles</p>
                    <div class="flex gap-1">
                        <button v-for="page in ensambles.last_page" :key="page"
                            @click="router.get('/ensambles', { ...filters, page })"
                            :class="['w-8 h-8 rounded-lg text-xs font-medium transition-colors', page === ensambles.current_page ? 'text-white' : 'text-tinta-500 hover:bg-tinta-100']"
                            :style="page === ensambles.current_page ? 'background:var(--marca);' : ''">
                            {{ page }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- VISTA GRID -->
            <div v-else class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                <div v-for="e in ensamblesLocal" :key="e.id"
                    class="bg-superficie rounded-2xl shadow-sm overflow-hidden cursor-pointer active:scale-[0.98] transition-transform"
                    @click="router.visit(`/ensambles/${e.id}`)">
                    <div class="aspect-square overflow-hidden relative" style="background:var(--superficie-2);">
                        <img v-if="e.imagen_principal" :src="urlImagen(e.imagen_principal)" :alt="e.nombre" class="w-full h-full object-cover"/>
                        <div v-else class="w-full h-full flex items-center justify-center text-white text-3xl font-semibold"
                            :style="`background:${e.categoria_color ?? 'var(--marca)'};`">
                            {{ inicial(e.nombre) }}
                        </div>
                        <label v-if="puedeEditarEnsambles" class="absolute top-2 right-2 p-1.5 rounded-lg cursor-pointer"
                            style="background:var(--velo);" @click.stop>
                            <input type="checkbox" :checked="seleccion.has(e.id)" @change="alternarSeleccion(e.id)"
                                class="w-4 h-4 rounded border-tinta-300 cursor-pointer block" />
                        </label>
                        <span v-if="e.publicado_web" class="absolute bottom-2 left-2 text-xs font-semibold px-2 py-0.5 rounded-full"
                            style="background:var(--pastel-verde);color:var(--texto-verde);">En la web</span>
                    </div>
                    <div class="p-3">
                        <p class="text-sm font-semibold text-tinta-900 line-clamp-2 mb-1">{{ e.nombre }}</p>
                        <p class="text-xs text-tinta-300 mb-2">{{ e.plantilla_nombre }}</p>
                        <span v-if="e.categoria_nombre" class="inline-block px-2 py-0.5 rounded-full text-xs font-semibold text-white mb-2"
                            :style="`background:${e.categoria_color ?? '#64748B'};`">{{ e.categoria_nombre }}</span>
                        <p class="text-sm font-semibold" style="color:var(--marca);">${{ formatCOP(e.precio_distribuidor) }}</p>
                    </div>
                </div>
            </div>

        </div>

        <!-- Barra de selección. Va por encima del menú inferior del celular. -->
        <Teleport to="body">
            <div v-if="seleccion.size" class="fixed left-0 right-0 z-40 px-4"
                style="bottom: calc(5.5rem + env(safe-area-inset-bottom));">
                <div class="mx-auto max-w-2xl rounded-2xl shadow-2xl border border-linea bg-superficie p-3
                    flex items-center gap-3 flex-wrap">
                    <span class="text-sm font-semibold text-tinta-900">
                        {{ seleccion.size }} seleccionado{{ seleccion.size === 1 ? '' : 's' }}
                    </span>
                    <button type="button" @click="limpiarSeleccion"
                        class="text-xs text-tinta-400 hover:text-tinta-900 underline">Quitar selección</button>
                    <div class="flex-1"></div>
                    <button type="button" @click="publicarSeleccion(false)" :disabled="publicando"
                        class="px-3 py-2 rounded-xl text-xs font-medium text-tinta-600 border border-linea hover:bg-tinta-50 disabled:opacity-50">
                        Retirar de la web
                    </button>
                    <button type="button" @click="publicarSeleccion(true)" :disabled="publicando"
                        class="px-3 py-2 rounded-xl text-xs font-medium text-white disabled:opacity-50"
                        style="background:var(--marca);">
                        {{ publicando ? 'Publicando...' : 'Publicar en la web' }}
                    </button>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
