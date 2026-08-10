<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import ModalSubirArchivo from '@/Components/ModalSubirArchivo.vue'
import { router } from '@inertiajs/vue3'
import { ref, reactive, computed } from 'vue'

const props = defineProps({
    archivos: Object,
    filtros:  Object,
    stats:    Object,
})

const form = reactive({
    buscar:    props.filtros?.buscar    ?? '',
    categoria: props.filtros?.categoria ?? '',
})

const vistaGrid      = ref(true)
const modalSubir     = ref(false)

const categorias = [
    { value: '',             label: 'Todos' },
    { value: 'plano',        label: 'Planos' },
    { value: 'foto_calidad', label: 'Fotos calidad' },
    { value: 'documento',    label: 'Documentos' },
    { value: 'rrss',         label: 'Redes sociales' },
    { value: 'ia',           label: 'Generadas con IA' },
    { value: 'otro',         label: 'Otros' },
]

// ── Generador de imágenes con IA ──────────────────────────────────────────────
// Sirve para banners, fondos, piezas de redes e ilustraciones de capacitación.
// Lo generado queda en esta misma biblioteca para reutilizarlo.
const modalIa       = ref(false)
const iaDescripcion = ref('')
const iaEstilo      = ref('fotografico')
const iaMejorar     = ref(true)
const iaCargando    = ref(false)
const iaError       = ref('')

function getCookie(name) {
    const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'))
    return match ? decodeURIComponent(match[2]) : ''
}

async function generarImagen() {
    if (!iaDescripcion.value.trim()) return

    iaCargando.value = true
    iaError.value    = ''

    try {
        const resp = await fetch('/api/ia/imagen', {
            method:  'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': getCookie('XSRF-TOKEN'),
                'Accept':       'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                descripcion: iaDescripcion.value,
                estilo: iaEstilo.value,
                mejorar: iaMejorar.value,
            }),
        })

        const data = await resp.json()

        if (!resp.ok) {
            iaError.value = data.error ?? 'No se pudo generar la imagen.'
            return
        }

        iaDescripcion.value = ''
        modalIa.value = false
        router.reload({ only: ['archivos', 'stats'] })
    } catch (e) {
        iaError.value = 'No se pudo conectar con la IA.'
    } finally {
        iaCargando.value = false
    }
}

const filtrar = () => {
    router.get('/multimedia', form, { preserveState: true, replace: true })
}

const setCategoria = (v) => {
    form.categoria = v
    filtrar()
}

const onSubido = () => {
    router.reload({ only: ['archivos', 'stats'] })
}

const eliminar = (archivo) => {
    if (!confirm(`¿Eliminar "${archivo.nombre}"?`)) return
    router.delete(`/multimedia/${archivo.id}`, { preserveScroll: true })
}

const tamanoTotal = computed(() => {
    const b = props.stats?.tamano_total ?? 0
    if (b < 1024)       return b + ' B'
    if (b < 1048576)    return (b / 1024).toFixed(1) + ' KB'
    if (b < 1073741824) return (b / 1048576).toFixed(1) + ' MB'
    return (b / 1073741824).toFixed(1) + ' GB'
})

const iconoExtension = (ext) => {
    if (['jpg','jpeg','png','gif','webp'].includes(ext)) return 'imagen'
    if (ext === 'pdf') return 'pdf'
    if (['doc','docx'].includes(ext)) return 'doc'
    if (['xls','xlsx'].includes(ext)) return 'xls'
    return 'generico'
}
</script>

<template>
    <AppLayout title="Multimedia">

        <!-- Header -->
        <div class="flex items-start justify-between mb-6">
            <div>
                <h1 class="text-xl font-semibold text-tinta-900">Biblioteca multimedia</h1>
                <p class="text-sm text-tinta-300 mt-0.5">
                    {{ stats?.total ?? 0 }} archivos ·
                    {{ stats?.imagenes ?? 0 }} imágenes ·
                    {{ stats?.pdfs ?? 0 }} PDFs ·
                    {{ tamanoTotal }}
                </p>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <button
                    @click="modalIa = true"
                    class="flex items-center gap-2 px-4 py-2.5 rounded-xl border border-linea text-sm font-semibold text-[var(--marca)] bg-superficie"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/>
                    </svg>
                    Generar con IA
                </button>
                <button
                    @click="modalSubir = true"
                    class="flex items-center gap-2 px-4 py-2.5 rounded-xl text-white text-sm font-semibold"
                    style="background-color: var(--marca);"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                    </svg>
                    Subir archivo
                </button>
            </div>
        </div>

        <!-- Modal generar imagen con IA -->
        <Teleport to="body">
            <div v-if="modalIa" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-4"
                style="background:rgba(0,0,0,0.5);" @click.self="modalIa = false">
                <div class="bg-superficie rounded-2xl shadow-xl w-full max-w-sm p-5">
                    <h3 class="text-base font-semibold text-tinta-900 mb-1">Generar imagen con IA</h3>
                    <p class="text-sm text-tinta-400 mb-4">
                        Para fondos, banners, piezas de redes o ilustraciones de apoyo.
                        No la uses para fotos de producto: usa la foto real.
                    </p>

                    <label class="block text-xs font-medium text-tinta-500 mb-1.5">¿Qué quieres ver en la imagen?</label>
                    <textarea v-model="iaDescripcion" rows="3" maxlength="1000"
                        placeholder="Ej: fondo abstracto con tonos azules y formas geométricas frías"
                        class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm mb-3 focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]"></textarea>

                    <label class="block text-xs font-medium text-tinta-500 mb-1.5">Estilo</label>
                    <select v-model="iaEstilo"
                        class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm mb-4 bg-superficie">
                        <option value="fotografico">Fotográfico</option>
                        <option value="ilustracion">Ilustración</option>
                        <option value="minimalista">Minimalista</option>
                        <option value="3d">Render 3D</option>
                    </select>

                    <label class="flex items-start gap-2 cursor-pointer mb-4">
                        <input v-model="iaMejorar" type="checkbox" class="rounded mt-0.5" />
                        <span class="text-xs text-tinta-500">
                            Mejorar mi descripción con IA
                            <span class="block text-tinta-300">
                                Le agrega detalles de composición, luz y encuadre antes de generar,
                                como hace ChatGPT. Desmárcalo para enviar tu texto tal cual.
                            </span>
                        </span>
                    </label>

                    <p v-if="iaError" class="text-xs text-red-600 mb-3">{{ iaError }}</p>

                    <div class="flex gap-3">
                        <button @click="modalIa = false"
                            class="flex-1 py-2.5 rounded-xl border border-linea text-sm text-tinta-500">
                            Cancelar
                        </button>
                        <button @click="generarImagen" :disabled="iaCargando || !iaDescripcion.trim()"
                            class="flex-1 py-2.5 rounded-xl text-white text-sm font-semibold disabled:opacity-50"
                            style="background:var(--marca);">
                            {{ iaCargando ? 'Generando…' : 'Generar' }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Barra de herramientas -->
        <div class="flex flex-wrap items-center gap-3 mb-5">
            <!-- Buscador -->
            <div class="relative flex-1 min-w-40 max-w-xs">
                <input
                    v-model="form.buscar"
                    type="text"
                    placeholder="Buscar por nombre..."
                    class="w-full border border-linea rounded-xl pl-9 pr-3 py-2 text-sm bg-superficie focus:outline-none"
                    @keyup.enter="filtrar"
                />
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-tinta-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
            </div>

            <!-- Filtros categoría -->
            <div class="flex gap-1.5 flex-wrap">
                <button
                    v-for="cat in categorias"
                    :key="cat.value"
                    @click="setCategoria(cat.value)"
                    class="px-3 py-1.5 rounded-full text-xs font-medium transition-colors"
                    :class="form.categoria === cat.value
                        ? 'text-white'
                        : 'bg-tinta-100 text-tinta-500 hover:bg-tinta-200'"
                    :style="form.categoria === cat.value ? 'background-color: var(--marca);' : ''"
                >{{ cat.label }}</button>
            </div>

            <!-- Toggle vista -->
            <div class="flex gap-1 ml-auto">
                <button
                    @click="vistaGrid = true"
                    class="w-8 h-8 rounded-lg flex items-center justify-center transition-colors"
                    :class="vistaGrid ? 'bg-tinta-200 text-tinta-900' : 'text-tinta-300 hover:bg-tinta-100'"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/>
                    </svg>
                </button>
                <button
                    @click="vistaGrid = false"
                    class="w-8 h-8 rounded-lg flex items-center justify-center transition-colors"
                    :class="!vistaGrid ? 'bg-tinta-200 text-tinta-900' : 'text-tinta-300 hover:bg-tinta-100'"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 10h16M4 14h16M4 18h16"/>
                    </svg>
                </button>
            </div>
        </div>

        <!-- Estado vacío -->
        <div v-if="!archivos.data?.length" class="text-center py-16 text-tinta-300">
            <svg class="w-12 h-12 mx-auto mb-3 text-gray-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z"/>
            </svg>
            <p class="font-medium text-sm">No hay archivos aún</p>
            <button @click="modalSubir = true" class="mt-3 text-sm font-medium hover:underline" style="color: var(--marca);">
                Subir el primero
            </button>
        </div>

        <!-- Vista GRID -->
        <div
            v-else-if="vistaGrid"
            class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3"
        >
            <div
                v-for="archivo in archivos.data"
                :key="archivo.id"
                class="group relative"
            >
                <!-- Card -->
                <div class="bg-superficie border border-linea rounded-xl overflow-hidden shadow-sm hover:shadow-md transition-shadow">
                    <!-- Thumbnail / icono -->
                    <div class="h-28 flex items-center justify-center bg-tinta-50 relative overflow-hidden">
                        <img
                            v-if="archivo.es_imagen"
                            :src="archivo.url"
                            :alt="archivo.nombre"
                            class="w-full h-28 object-cover"
                        />
                        <div v-else class="flex flex-col items-center gap-1">
                            <!-- PDF -->
                            <svg v-if="iconoExtension(archivo.extension) === 'pdf'" class="w-10 h-10 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/>
                            </svg>
                            <!-- Genérico -->
                            <svg v-else class="w-10 h-10 text-tinta-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/>
                            </svg>
                            <span class="text-xs font-semibold text-tinta-300 uppercase">{{ archivo.extension }}</span>
                        </div>

                        <!-- Overlay hover -->
                        <div class="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center gap-2">
                            <a
                                :href="archivo.url"
                                target="_blank"
                                class="w-8 h-8 rounded-full bg-white/20 hover:bg-white/30 flex items-center justify-center text-white transition-colors"
                                title="Ver"
                                @click.stop
                            >
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.964-7.178z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                </svg>
                            </a>
                            <button
                                @click.stop="eliminar(archivo)"
                                class="w-8 h-8 rounded-full bg-red-500/80 hover:bg-red-500 flex items-center justify-center text-white transition-colors"
                                title="Eliminar"
                            >
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div class="px-2 py-1.5">
                        <p class="text-xs text-tinta-500 truncate font-medium">{{ archivo.nombre }}</p>
                        <p class="text-xs text-tinta-300">{{ archivo.tamano }}</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Vista LISTA -->
        <div v-else class="bg-superficie rounded-2xl border border-linea shadow-sm overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-tinta-50 text-xs text-tinta-400 uppercase tracking-wide">
                    <tr>
                        <th class="px-4 py-3 text-left">Archivo</th>
                        <th class="px-4 py-3 text-left hidden md:table-cell">Categoría</th>
                        <th class="px-4 py-3 text-left hidden md:table-cell">Tamaño</th>
                        <th class="px-4 py-3 text-left hidden lg:table-cell">Subido por</th>
                        <th class="px-4 py-3 text-left hidden lg:table-cell">Fecha</th>
                        <th class="px-4 py-3 text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-linea">
                    <tr v-for="archivo in archivos.data" :key="archivo.id" class="hover:bg-tinta-50 transition-colors">
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-lg bg-tinta-100 flex items-center justify-center shrink-0 overflow-hidden">
                                    <img v-if="archivo.es_imagen" :src="archivo.url" class="w-8 h-8 object-cover rounded-lg"/>
                                    <span v-else class="text-xs font-semibold text-tinta-300 uppercase">{{ archivo.extension }}</span>
                                </div>
                                <span class="text-sm font-medium text-tinta-900 truncate max-w-48">{{ archivo.nombre }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-3 hidden md:table-cell">
                            <span class="text-xs px-2 py-0.5 rounded-full bg-tinta-100 text-tinta-500">{{ archivo.categoria }}</span>
                        </td>
                        <td class="px-4 py-3 text-tinta-400 hidden md:table-cell">{{ archivo.tamano }}</td>
                        <td class="px-4 py-3 text-tinta-400 hidden lg:table-cell">{{ archivo.subido_por }}</td>
                        <td class="px-4 py-3 text-tinta-300 hidden lg:table-cell text-xs">{{ archivo.created_at }}</td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-1">
                                <a :href="archivo.url" target="_blank" class="w-7 h-7 rounded-lg flex items-center justify-center text-tinta-300 hover:text-blue-600 hover:bg-blue-50 transition-colors">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                                    </svg>
                                </a>
                                <button @click="eliminar(archivo)" class="w-7 h-7 rounded-lg flex items-center justify-center text-tinta-300 hover:text-red-500 hover:bg-red-50 transition-colors">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                    </svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Paginación -->
        <div v-if="archivos.last_page > 1" class="flex items-center justify-between mt-4 px-1">
            <p class="text-xs text-tinta-300">{{ archivos.from }}–{{ archivos.to }} de {{ archivos.total }}</p>
            <div class="flex gap-1">
                <template v-for="link in archivos.links" :key="link.label">
                    <button
                        v-if="link.url"
                        @click="router.visit(link.url)"
                        v-html="link.label"
                        class="px-3 py-1.5 rounded-lg text-sm"
                        :style="link.active ? 'background-color: var(--marca); color: white;' : 'color: var(--texto-2);'"
                    />
                </template>
            </div>
        </div>

        <ModalSubirArchivo
            :show="modalSubir"
            @close="modalSubir = false"
            @subido="onSubido"
        />

    </AppLayout>
</template>
