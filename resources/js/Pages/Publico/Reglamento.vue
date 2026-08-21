<script setup>
/**
 * El reglamento interno, como lo ve un colaborador desde su celular.
 *
 * Todo aquí está al servicio de una sola cosa: que se pueda leer. Un reglamento es un texto
 * largo que nadie lee por gusto, así que la pantalla no compite con él — no hay menú, no hay
 * barra lateral, no hay nada que tocar salvo el contenido.
 *
 * Las decisiones que hacen la diferencia:
 *
 * - **Ancho de lectura**, no ancho de pantalla. Una línea de 120 caracteres obliga al ojo a
 *   buscar dónde empieza la siguiente; a 65 el salto es automático.
 * - **Letra de 18 px con interlineado holgado.** En un texto de una hoja, 14 px alcanzan; en
 *   uno de treinta páginas, cansan a la tercera.
 * - **Un índice armado solo** con los títulos del documento. Un reglamento se consulta más de
 *   lo que se lee de corrido: casi siempre alguien viene a buscar UN artículo.
 * - **Barra de avance**: saber cuánto falta es la diferencia entre seguir y cerrar.
 */
import { computed, onMounted, onUnmounted, ref } from 'vue'

const props = defineProps({
    reglamento: { type: Object, required: true },
    empresa:    { type: Object, default: () => ({}) },
})

const indice  = ref([])
const cuerpo  = ref(null)
const avance  = ref(0)
const activo  = ref('')
const abierto = ref(false)

// El índice se arma del contenido, no de una lista aparte que habría que mantener al día.
function construirIndice() {
    if (! cuerpo.value) return

    const titulos = cuerpo.value.querySelectorAll('h1, h2, h3')

    indice.value = Array.from(titulos).map((el, i) => {
        // El id se pone aquí y no en el HTML guardado: quien escribe el reglamento escribe
        // títulos, no anclas.
        if (! el.id) el.id = `seccion-${i + 1}`

        return { id: el.id, texto: el.textContent.trim(), nivel: Number(el.tagName[1]) }
    }).filter(t => t.texto)
}

function alDesplazar() {
    const alto = document.documentElement.scrollHeight - window.innerHeight
    avance.value = alto > 0 ? Math.min(100, Math.round((window.scrollY / alto) * 100)) : 0

    // Qué sección se está leyendo: la última que ya pasó por el borde superior.
    let actual = ''

    for (const t of indice.value) {
        const el = document.getElementById(t.id)
        if (el && el.getBoundingClientRect().top <= 120) actual = t.id
    }

    activo.value = actual
}

function irA(id) {
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
    abierto.value = false
}

function imprimir() {
    window.print()
}

onMounted(() => {
    construirIndice()
    alDesplazar()
    window.addEventListener('scroll', alDesplazar, { passive: true })
})

onUnmounted(() => window.removeEventListener('scroll', alDesplazar))

const subtitulo = computed(() => {
    const partes = []

    if (props.reglamento.version) partes.push(`Versión ${props.reglamento.version}`)
    if (props.reglamento.vigente_desde) partes.push(`Vigente desde el ${props.reglamento.vigente_desde}`)

    return partes.join(' · ')
})
</script>

<template>
    <div class="min-h-screen bg-lienzo">
        <!-- Barra de avance: sin ella, un documento largo se siente infinito. -->
        <div class="fixed top-0 left-0 right-0 h-1 z-30 no-imprimir" style="background:var(--tinta-100);">
            <div class="h-full transition-all duration-150" :style="`width:${avance}%; background:var(--marca);`" />
        </div>

        <header class="sticky top-0 z-20 border-b border-linea no-imprimir"
            style="background:var(--velo); backdrop-filter:blur(12px);">
            <div class="mx-auto max-w-3xl px-5 py-3 flex items-center gap-3">
                <img v-if="empresa.logo" :src="empresa.logo" :alt="empresa.nombre"
                    class="h-8 w-auto shrink-0 object-contain" />

                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-tinta-900 truncate">{{ empresa.nombre }}</p>
                    <p class="text-xs text-tinta-400 truncate">{{ reglamento.titulo }}</p>
                </div>

                <button v-if="indice.length" type="button" @click="abierto = ! abierto"
                    class="shrink-0 inline-flex items-center gap-1.5 rounded-xl border border-linea px-3 py-2 text-xs font-medium text-tinta-600 hover:bg-realce transition-colors">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h10"/>
                    </svg>
                    <span class="hidden sm:inline">Índice</span>
                </button>

                <button type="button" @click="imprimir" title="Imprimir o guardar en PDF"
                    class="shrink-0 rounded-xl border border-linea p-2 text-tinta-500 hover:bg-realce transition-colors">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4H7v4a2 2 0 002 2zM7 7V5a2 2 0 012-2h6a2 2 0 012 2v2"/>
                    </svg>
                </button>
            </div>

            <!-- El índice, desplegable. Se cierra al elegir: en celular ocupa media pantalla. -->
            <div v-if="abierto && indice.length"
                class="border-t border-linea max-h-[60vh] overflow-y-auto" style="background:var(--superficie);">
                <nav class="mx-auto max-w-3xl px-5 py-3">
                    <button v-for="t in indice" :key="t.id" type="button" @click="irA(t.id)"
                        class="block w-full text-left py-2 text-sm border-b border-separador last:border-0 transition-colors"
                        :class="activo === t.id ? 'text-[var(--marca)] font-semibold' : 'text-tinta-600 hover:text-tinta-900'"
                        :style="`padding-left:${(t.nivel - 1) * 14}px;`">
                        {{ t.texto }}
                    </button>
                </nav>
            </div>
        </header>

        <div class="mx-auto max-w-3xl px-5 pt-10 pb-6">
            <h1 class="text-3xl sm:text-4xl font-semibold text-tinta-900 leading-tight">
                {{ reglamento.titulo }}
            </h1>
            <p v-if="subtitulo" class="mt-3 text-sm text-tinta-400">{{ subtitulo }}</p>
        </div>

        <main class="mx-auto max-w-3xl px-5 pb-24">
            <article ref="cuerpo" class="documento" v-html="reglamento.contenido" />

            <footer class="mt-16 pt-6 border-t border-linea text-center">
                <p class="text-xs text-tinta-300">
                    {{ empresa.nombre }}
                    <template v-if="reglamento.actualizado_el"> · Actualizado el {{ reglamento.actualizado_el }}</template>
                </p>
            </footer>
        </main>
    </div>
</template>

<style scoped>
/*
 * El texto del reglamento. Es lo único que hay en la pantalla, así que se le da el espacio y
 * el tamaño que un documento largo necesita: 18 px con interlineado 1.75 y un ancho de lectura
 * cómodo. A 14 px y línea apretada nadie llega al artículo 40.
 */
.documento {
    font-size: 1.125rem;
    line-height: 1.75;
    color: var(--texto-2);
    max-width: 65ch;
}

.documento :deep(h1),
.documento :deep(h2),
.documento :deep(h3) {
    color: var(--texto);
    font-weight: 600;
    line-height: 1.3;
    /* Para que el título no quede debajo del encabezado fijo al saltar a él desde el índice. */
    scroll-margin-top: 5.5rem;
}

.documento :deep(h1) { font-size: 1.6rem;  margin: 2.5rem 0 1rem; }
.documento :deep(h2) { font-size: 1.35rem; margin: 2.25rem 0 .75rem; }
.documento :deep(h3) { font-size: 1.15rem; margin: 1.75rem 0 .5rem; }

.documento :deep(p) { margin: 0 0 1.15rem; }

.documento :deep(ul),
.documento :deep(ol) { margin: 0 0 1.15rem 1.35rem; }
.documento :deep(li) { margin-bottom: .5rem; }
.documento :deep(ul) { list-style: disc; }
.documento :deep(ol) { list-style: decimal; }

.documento :deep(strong) { color: var(--texto); font-weight: 600; }

.documento :deep(a) {
    color: var(--marca);
    text-decoration: underline;
    text-underline-offset: 2px;
}

.documento :deep(table) {
    width: 100%;
    border-collapse: collapse;
    margin: 0 0 1.15rem;
    font-size: .95rem;
}

.documento :deep(th),
.documento :deep(td) {
    border: 1px solid var(--borde);
    padding: .5rem .65rem;
    text-align: left;
}

.documento :deep(th) {
    background: var(--tinta-50);
    font-weight: 600;
    color: var(--texto);
}

.documento :deep(blockquote) {
    border-left: 3px solid var(--marca);
    padding-left: 1rem;
    margin: 0 0 1.15rem;
    color: var(--texto-3);
}

/* Impreso: sin barras ni índice, y a tamaño de papel. */
@media print {
    .documento { font-size: 11pt; line-height: 1.5; max-width: none; }
    .no-imprimir { display: none !important; }
}
</style>
