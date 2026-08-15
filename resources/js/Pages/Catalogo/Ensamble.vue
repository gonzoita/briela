<script setup>
import { ref, computed } from 'vue'
import { useClipboard } from '@/composables/useClipboard'

const props = defineProps({
    ensamble:      Object,
    mostrarPrecio: { type: Boolean, default: true },
})

const { copyText } = useClipboard()

const imagenActiva = ref(props.ensamble.imagenes?.[0] ?? null)

/** El precio público lo decide la marca en Segmentación; la columna vieja es el respaldo. */
const precioPublico = computed(() =>
    Number(props.ensamble.precio_publico ?? props.ensamble.precio_cliente_final ?? 0)
)

const imagenPrincipal = computed(() =>
    imagenActiva.value?.url ?? props.ensamble.imagenes?.[0]?.url ?? null
)

async function compartir() {
    const url  = window.location.href
    const text = `${props.ensamble.nombre}`
    if (navigator.share) {
        navigator.share({ title: text, url })
    } else if (await copyText(url)) {
        alert('Enlace copiado al portapapeles')
    } else {
        prompt('No se pudo copiar automáticamente. Copia el enlace:', url)
    }
}
</script>

<template>
    <div class="min-h-screen bg-tinta-50 font-sans">
        <!-- Header -->
        <header class="bg-superficie border-b border-linea sticky top-0 z-20">
            <div class="max-w-5xl mx-auto px-4 py-3 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <img :src="$page.props.marca.logo"
                        :alt="$page.props.marca.nombre" class="h-8 object-contain" />
                    <span class="text-xs text-tinta-300 hidden sm:inline">Catálogo de Configuraciones</span>
                </div>
                <div class="flex items-center gap-2">
                    <button @click="compartir"
                        class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-linea text-xs text-tinta-500 hover:bg-tinta-50">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/>
                        </svg>
                        Compartir
                    </button>
                    <a :href="`/catalogo/ensambles/${ensamble.id}/pdf`"
                        class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs text-white font-medium"
                        style="background:var(--marca);">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        PDF
                    </a>
                </div>
            </div>
        </header>

        <div class="max-w-5xl mx-auto px-4 py-8">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">

                <!-- Galería -->
                <div>
                    <div class="bg-superficie rounded-2xl overflow-hidden border border-linea aspect-square flex items-center justify-center">
                        <img v-if="imagenPrincipal" :src="imagenPrincipal" :alt="ensamble.nombre"
                            class="w-full h-full object-cover" />
                        <div v-else class="text-tinta-200 text-center p-8">
                            <svg class="w-16 h-16 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                    d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                            <p class="text-sm">Sin imagen</p>
                        </div>
                    </div>
                    <div v-if="ensamble.imagenes?.length > 1" class="flex gap-2 mt-3 overflow-x-auto pb-1">
                        <button v-for="img in ensamble.imagenes" :key="img.url"
                            @click="imagenActiva = img"
                            class="shrink-0 w-16 h-16 rounded-xl overflow-hidden border-2 transition-colors"
                            :class="imagenActiva?.url === img.url ? 'border-blue-500' : 'border-transparent hover:border-tinta-200'">
                            <img :src="img.url" :alt="ensamble.nombre" class="w-full h-full object-cover" />
                        </button>
                    </div>
                </div>

                <!-- Info -->
                <div class="space-y-5">
                    <div>
                        <div class="flex gap-2 flex-wrap mb-2">
                            <span v-if="ensamble.plantilla_nombre"
                                class="text-xs font-medium px-2.5 py-1 rounded-full bg-pastel-violeta-2 text-aviso-violeta">
                                {{ ensamble.plantilla_nombre }}
                            </span>
                            <span v-if="ensamble.categoria_nombre"
                                class="text-xs font-medium px-2.5 py-1 rounded-full bg-pastel-azul-2 text-aviso-azul">
                                {{ ensamble.categoria_nombre }}
                            </span>
                        </div>
                        <h1 class="text-2xl font-semibold text-tinta-900">{{ ensamble.nombre }}</h1>
                    </div>

                    <div v-if="ensamble.descripcion_corta" class="text-tinta-500 text-sm leading-relaxed">
                        {{ ensamble.descripcion_corta }}
                    </div>

                    <!-- El precio público lo decide la marca en Segmentación. -->
                    <div v-if="mostrarPrecio && precioPublico > 0"
                        class="bg-pastel-verde border border-borde-aviso-verde rounded-xl p-4">
                        <p class="text-xs text-aviso-verde font-medium mb-0.5">Precio base estimado</p>
                        <p class="text-2xl font-semibold text-aviso-verde">
                            ${{ Number(precioPublico).toLocaleString('es-CO') }}
                        </p>
                        <p class="text-xs text-aviso-verde mt-1">El precio final depende de la configuración · IVA no incluido</p>
                    </div>

                    <div v-if="ensamble.descripcion_larga"
                        class="bg-tinta-50 rounded-xl p-4 border border-linea">
                        <h3 class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-2">Detalles técnicos</h3>
                        <div class="tiptap-content text-sm text-tinta-500 leading-relaxed" v-html="ensamble.descripcion_larga"></div>
                    </div>

                    <div class="border-t border-linea pt-5">
                        <p class="text-xs text-tinta-300 text-center">
                            ¿Tienes preguntas? Contáctanos a través de
                            <a :href="$page.props.marca.web || '#'" target="_blank" class="text-aviso-azul hover:underline">{{ $page.props.marca.web }}</a>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <footer class="mt-12 border-t border-linea bg-superficie py-6 text-center text-xs text-tinta-300">
            {{ $page.props.marca.nombre }} &copy; {{ new Date().getFullYear() }}
        </footer>
    </div>
</template>

<style>
.tiptap-content p { margin-bottom: 0.5rem; }
.tiptap-content p:last-child { margin-bottom: 0; }
.tiptap-content ul { list-style-type: disc; padding-left: 1.5rem; margin-bottom: 0.5rem; }
.tiptap-content ol { list-style-type: decimal; padding-left: 1.5rem; margin-bottom: 0.5rem; }
.tiptap-content li p { margin-bottom: 0; }
.tiptap-content strong { font-weight: 700; }
.tiptap-content em { font-style: italic; }
.tiptap-content u { text-decoration: underline; }
.tiptap-content a { color: var(--marca); text-decoration: underline; }
.tiptap-content h1 { font-size: 1.125rem; font-weight: 700; margin-bottom: 0.5rem; }
.tiptap-content h2 { font-size: 1rem; font-weight: 700; margin-bottom: 0.5rem; }
.tiptap-content h3 { font-size: 0.9rem; font-weight: 600; margin-bottom: 0.4rem; }
</style>
