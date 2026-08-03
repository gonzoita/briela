<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    publicaciones:  Object,
    filtros:        Object,
    cuentasActivas: Number,
})

const estado = ref(props.filtros?.estado ?? '')

function aplicarFiltro() {
    router.get('/rrss', { estado: estado.value || undefined }, { preserveState: true, replace: true })
}

const estados = {
    borrador:   { label: 'Borrador',   bg: 'bg-gray-100',   text: 'text-gray-600'  },
    programada: { label: 'Programada', bg: 'bg-blue-100',   text: 'text-blue-700'  },
    publicando: { label: 'Publicando', bg: 'bg-yellow-100', text: 'text-yellow-700'},
    publicada:  { label: 'Publicada',  bg: 'bg-green-100',  text: 'text-green-700' },
    parcial:    { label: 'Parcial',    bg: 'bg-orange-100', text: 'text-orange-700'},
    fallida:    { label: 'Fallida',    bg: 'bg-red-100',    text: 'text-red-700'   },
}

const redIcono = {
    instagram:       '📷',
    facebook:        '📘',
    linkedin:        '💼',
    google_business: '📍',
}

function estadoBadge(e) {
    return estados[e] ?? { label: e, bg: 'bg-gray-100', text: 'text-gray-700' }
}

function eliminar(p) {
    if (!confirm('¿Eliminar esta publicación?')) return
    router.delete(`/rrss/${p.id}`, { preserveScroll: true })
}

function publicarAhora(p) {
    if (!confirm('¿Publicar ahora mismo, sin esperar a la fecha programada?')) return
    router.post(`/rrss/${p.id}/publicar`, {}, { preserveScroll: true })
}
</script>

<template>
    <AppLayout title="Redes Sociales">
        <div class="max-w-3xl mx-auto px-4 py-4">

            <!-- Cabecera -->
            <div class="flex items-center justify-between mb-4">
                <h1 class="text-xl font-bold text-gray-900">Redes Sociales</h1>
                <a href="/rrss/crear" @click.prevent="router.visit('/rrss/crear')"
                    class="inline-flex items-center gap-1 px-3 py-2 rounded-lg text-sm font-medium text-white"
                    style="background:var(--marca)">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    Nueva publicación
                </a>
            </div>

            <!-- Aviso si no hay cuentas conectadas -->
            <div v-if="!cuentasActivas" class="bg-amber-50 border border-amber-200 rounded-xl p-4 mb-4">
                <p class="text-sm text-amber-800 font-medium">Todavía no tienes cuentas conectadas.</p>
                <p class="text-xs text-amber-700 mt-1">Conecta Instagram, Facebook, LinkedIn o Google Business Profile para poder programar publicaciones.</p>
                <a href="/rrss/cuentas" @click.prevent="router.visit('/rrss/cuentas')"
                    class="inline-block mt-2 text-xs font-semibold text-amber-900 underline">Conectar cuentas →</a>
            </div>

            <!-- Acceso a cuentas -->
            <div v-else class="flex justify-end mb-3">
                <a href="/rrss/cuentas" @click.prevent="router.visit('/rrss/cuentas')"
                    class="text-xs text-gray-500 hover:text-gray-700 underline">Gestionar cuentas conectadas</a>
            </div>

            <!-- Filtro -->
            <div class="bg-white rounded-xl border border-gray-200 p-3 mb-4">
                <select v-model="estado" @change="aplicarFiltro"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white">
                    <option value="">Todos los estados</option>
                    <option v-for="(v, k) in estados" :key="k" :value="k">{{ v.label }}</option>
                </select>
            </div>

            <!-- Lista -->
            <div v-if="!publicaciones.data.length" class="py-12 text-center text-sm text-gray-400">
                Sin publicaciones todavía.
            </div>

            <div class="space-y-2">
                <div v-for="p in publicaciones.data" :key="p.id" class="bg-white rounded-xl border border-gray-200 p-4">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 mb-1 flex-wrap">
                                <span :class="['text-xs px-2 py-0.5 rounded-full font-medium', estadoBadge(p.estado).bg, estadoBadge(p.estado).text]">
                                    {{ estadoBadge(p.estado).label }}
                                </span>
                                <span class="text-xs text-gray-400">{{ p.fecha_programada }}</span>
                            </div>
                            <p class="text-sm text-gray-800 line-clamp-2">{{ p.contenido }}</p>
                            <div class="flex items-center gap-1.5 mt-2 flex-wrap">
                                <span v-for="c in p.cuentas" :key="c.id"
                                    class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full"
                                    :class="c.estado === 'publicada' ? 'bg-green-50 text-green-700' : c.estado === 'fallida' ? 'bg-red-50 text-red-700' : 'bg-gray-50 text-gray-500'"
                                    :title="c.error || ''">
                                    {{ redIcono[c.red] ?? '' }} {{ c.nombre }}
                                </span>
                            </div>
                        </div>
                        <img v-if="p.imagen_url" :src="p.imagen_url" class="w-14 h-14 rounded-lg object-cover shrink-0" />
                    </div>

                    <div class="flex items-center gap-3 mt-3 pt-3 border-t border-gray-50">
                        <button v-if="['borrador','programada','fallida','parcial'].includes(p.estado)"
                            @click="publicarAhora(p)" class="text-xs font-semibold text-[var(--marca)]">
                            Publicar ahora
                        </button>
                        <a v-if="!['publicada','publicando'].includes(p.estado)" :href="`/rrss/${p.id}/editar`"
                            @click.prevent="router.visit(`/rrss/${p.id}/editar`)"
                            class="text-xs text-blue-600 hover:underline">Editar</a>
                        <button @click="eliminar(p)" class="text-xs text-red-500 hover:underline ml-auto">Eliminar</button>
                    </div>
                </div>
            </div>

            <!-- Paginación simple -->
            <div v-if="publicaciones.links?.length > 3" class="flex flex-wrap gap-1 justify-center mt-4">
                <button v-for="link in publicaciones.links" :key="link.label"
                    v-html="link.label"
                    :disabled="!link.url"
                    @click="link.url && router.visit(link.url, { preserveScroll: true })"
                    class="px-3 py-1.5 rounded-lg text-xs"
                    :class="link.active ? 'bg-[var(--marca)] text-white' : 'bg-white border border-gray-200 text-gray-600 disabled:opacity-40'"
                />
            </div>

        </div>
    </AppLayout>
</template>
