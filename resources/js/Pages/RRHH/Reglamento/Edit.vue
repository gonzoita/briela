<script setup>
/**
 * El reglamento interno, del lado de adentro.
 *
 * Se escribe aquí y se lee afuera. La pantalla tiene dos mitades a propósito: lo que se
 * escribe, y lo que se reparte —el enlace y su QR—, porque son dos momentos distintos y quien
 * viene a imprimir el QR no viene a redactar.
 *
 * El botón de publicar es lo único que hace visible el documento. Sin él, el enlace no
 * responde: un reglamento a medio escribir no se muestra por accidente.
 */
import { ref, computed } from 'vue'
import { router, useForm, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import EditorTexto from '@/Components/EditorTexto.vue'
import { useClipboard } from '@/composables/useClipboard'

const props = defineProps({
    reglamento: { type: Object, required: true },
    // El QR ya viene dibujado del servidor, en SVG: la pantalla no necesita una librería y el
    // mismo código sirve para verlo aquí y para imprimirlo.
    qr:         { type: String, default: '' },
})

const page = usePage()

// Ver el reglamento lo puede cualquiera de RRHH; editarlo es un permiso aparte, que por
// omisión solo tiene el administrador.
const puedeEditar = computed(() =>
    (page.props.auth?.permisosLista ?? []).includes('reglamento.editar')
)

const form = useForm({
    titulo:        props.reglamento.titulo ?? 'Reglamento Interno de Trabajo',
    contenido:     props.reglamento.contenido ?? '',
    version:       props.reglamento.version ?? '',
    vigente_desde: props.reglamento.vigente_desde ?? '',
    publicado:     !! props.reglamento.publicado,
})

const { copyText } = useClipboard()
const copiado = ref(false)

async function copiarEnlace() {
    if (await copyText(props.reglamento.url_publica)) {
        copiado.value = true
        setTimeout(() => { copiado.value = false }, 2000)
    }
}

const guardar = () => form.put('/rrhh/reglamento', { preserveScroll: true })

function regenerar() {
    if (! confirm('El enlace actual dejará de funcionar y habrá que repartir el QR otra vez. ¿Seguir?')) return

    router.post('/rrhh/reglamento/token', {}, { preserveScroll: true })
}

const flash = computed(() => page.props.flash ?? {})

const avisoTitulos = ref('')

/**
 * Convierte en títulos de verdad los párrafos que se ven como títulos.
 *
 * Un reglamento casi nunca se escribe aquí: se pega de Word o de un PDF, y ahí los capítulos
 * vienen como párrafos en negrita y centrados. Se ven igual, pero el índice del enlace público
 * lee `h2` y `h3` — así que un documento pegado tal cual sale sin índice.
 *
 * Marcar sesenta títulos a mano es lo que hace que nadie lo haga. Esto los reconoce por cómo
 * están escritos:
 *
 * - **CAPÍTULO / TÍTULO / ANEXO** → título de capítulo (`h2`)
 * - **ARTÍCULO 12** → subtítulo (`h3`)
 * - Un párrafo **corto y todo en negrita** → título de capítulo
 *
 * No toca el texto: solo cambia la etiqueta. Y no es automático a propósito — se aprieta, se
 * mira el resultado y si no gustó, se deshace sin guardar.
 */
function detectarTitulos() {
    const doc = new DOMParser().parseFromString(form.contenido || '', 'text/html')
    const parrafos = Array.from(doc.querySelectorAll('p'))

    let capitulos = 0
    let articulos = 0

    for (const p of parrafos) {
        const texto = (p.textContent || '').trim()

        if (! texto || texto.length > 160) continue

        // Sin tildes y en mayúsculas, para que «Artículo» y «ARTICULO» caigan igual.
        const plano = texto.normalize('NFD').replace(/[̀-ͯ]/g, '').toUpperCase()

        let nivel = null

        if (/^(CAPITULO|TITULO|ANEXO|SECCION)/.test(plano) && texto.length <= 90) {
            nivel = 'h2'
        } else {
            // El resto se saca del texto ORIGINAL, no del que ya pasó a mayúsculas: preguntar
            // «¿está en mayúsculas?» a una cadena que acabo de poner en mayúsculas siempre da
            // que sí, y entonces el cuerpo de cada artículo se volvía un título.
            const art = texto.match(/^\s*(?:art[ií]culo|art\.)\s*\d+[.\-–)]?\s*(.*)$/i)

            if (art) {
                const resto = art[1].trim()

                // Es el nombre del artículo si no trae texto, o si lo que trae es corto y va en
                // mayúsculas —«ARTÍCULO 5. JORNADA DE TRABAJO»—. Si trae una frase normal, eso
                // es el cuerpo del artículo y convertirlo dejaría un encabezado con un párrafo
                // adentro.
                if (resto.length === 0 || (resto.length <= 45 && resto === resto.toUpperCase())) {
                    nivel = 'h3'
                }
            } else if (texto.length <= 90 && p.querySelector('strong, b')) {
                const negrita = (p.querySelector('strong, b').textContent || '').trim()

                // Todo el párrafo en negrita y corto: así vienen los encabezados de Word.
                if (negrita.length >= texto.length - 2) nivel = 'h2'
            }
        }

        if (! nivel) continue

        const titulo = doc.createElement(nivel)

        // Se pasa el texto plano: un título no necesita la negrita, ya es un título.
        titulo.textContent = texto
        p.replaceWith(titulo)

        if (nivel === 'h2') capitulos++
        else articulos++
    }

    if (! capitulos && ! articulos) {
        avisoTitulos.value = 'No se reconoció ningún título. Márcalos a mano con los botones T1 y T2 del editor.'
        return
    }

    form.contenido = doc.body.innerHTML
    avisoTitulos.value = `Se marcaron ${capitulos} capítulo(s) y ${articulos} artículo(s). Revisa el resultado antes de guardar.`
}

</script>

<template>
    <AppLayout title="Reglamento interno">
        <div class="max-w-4xl mx-auto px-4 py-4">

            <div class="flex items-start justify-between gap-3 mb-4 flex-wrap">
                <div>
                    <h1 class="text-xl font-semibold text-tinta-900">Reglamento interno de trabajo</h1>
                    <p class="text-sm text-tinta-400 mt-0.5">
                        Se escribe aquí y cualquiera lo lee con el enlace, sin necesidad de tener usuario.
                    </p>
                </div>

                <a v-if="reglamento.publicado && reglamento.tiene_contenido"
                    :href="reglamento.url_publica" target="_blank"
                    class="shrink-0 rounded-xl border border-linea px-3 py-2 text-sm text-tinta-600 hover:bg-realce transition-colors">
                    Ver como lo ven ellos
                </a>
            </div>

            <div v-if="flash.success" class="mb-4 rounded-xl bg-pastel-verde border border-borde-aviso-verde px-4 py-3 text-sm text-aviso-verde">
                {{ flash.success }}
            </div>
            <div v-if="flash.error" class="mb-4 rounded-xl bg-pastel-rojo border border-borde-aviso-rojo px-4 py-3 text-sm text-aviso-rojo">
                {{ flash.error }}
            </div>

            <div v-if="! puedeEditar" class="mb-4 rounded-xl bg-pastel-ambar border border-borde-aviso-ambar px-4 py-3">
                <p class="text-sm text-aviso-ambar">
                    Puedes leer el reglamento, pero no editarlo. Editarlo es un permiso aparte
                    («Reglamento interno») que se asigna desde Roles.
                </p>
            </div>

            <!-- ── Repartirlo ─────────────────────────────────────────────────
                 Va arriba porque es lo que más se viene a buscar una vez escrito:
                 el enlace para pegar en un correo y el QR para la cartelera. -->
            <div class="bg-superficie rounded-2xl shadow-sm p-5 mb-4">
                <h2 class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-4">Cómo compartirlo</h2>

                <div class="flex flex-col sm:flex-row gap-5">
                    <div class="shrink-0 mx-auto sm:mx-0">
                        <div class="rounded-xl border border-linea p-3 bg-superficie w-[180px] h-[180px] flex items-center justify-center"
                            :class="reglamento.publicado ? '' : 'opacity-40'"
                            v-html="qr" />

                        <a href="/rrhh/reglamento/qr"
                            class="mt-2 block text-center text-xs font-semibold" style="color:var(--marca);">
                            Descargar el QR
                        </a>
                    </div>

                    <div class="min-w-0 flex-1 space-y-3">
                        <div>
                            <label class="block text-xs font-medium text-tinta-500 mb-1">Enlace público</label>
                            <div class="flex gap-2">
                                <input :value="reglamento.url_publica" readonly
                                    class="min-w-0 flex-1 rounded-xl border border-linea bg-tinta-50 px-3 py-2 text-sm text-tinta-600" />
                                <button type="button" @click="copiarEnlace"
                                    class="shrink-0 rounded-xl px-3 py-2 text-sm font-semibold text-white transition-colors"
                                    style="background:var(--marca);">
                                    {{ copiado ? 'Copiado' : 'Copiar' }}
                                </button>
                            </div>
                        </div>

                        <p v-if="! reglamento.publicado" class="text-xs text-aviso-ambar">
                            El enlace todavía no responde: falta publicarlo, más abajo.
                        </p>
                        <p v-else class="text-xs text-tinta-400">
                            Cualquiera con este enlace puede leer el reglamento. No pide usuario ni contraseña.
                        </p>

                        <button v-if="puedeEditar" type="button" @click="regenerar"
                            class="text-xs text-tinta-400 underline underline-offset-2 hover:text-aviso-rojo transition-colors">
                            Generar un enlace nuevo
                        </button>
                        <p v-if="puedeEditar" class="text-xs text-tinta-300 -mt-1">
                            Para cuando el enlace llegó a donde no debía. El anterior deja de funcionar.
                        </p>
                    </div>
                </div>
            </div>

            <!-- ── Escribirlo ───────────────────────────────────────────────── -->
            <form @submit.prevent="guardar" class="bg-superficie rounded-2xl shadow-sm p-5 space-y-4">
                <h2 class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">El documento</h2>

                <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium text-tinta-700 mb-1.5">Título</label>
                        <input v-model="form.titulo" type="text" :disabled="! puedeEditar" maxlength="200"
                            class="w-full rounded-xl border border-linea px-3 py-2.5 text-sm focus:outline-none focus:border-[var(--marca)] disabled:bg-tinta-50" />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-tinta-700 mb-1.5">Versión</label>
                        <input v-model="form.version" type="text" :disabled="! puedeEditar" maxlength="30"
                            placeholder="v1"
                            class="w-full rounded-xl border border-linea px-3 py-2.5 text-sm focus:outline-none focus:border-[var(--marca)] disabled:bg-tinta-50" />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-tinta-700 mb-1.5">Vigente desde</label>
                        <input v-model="form.vigente_desde" type="date" :disabled="! puedeEditar"
                            class="w-full rounded-xl border border-linea px-3 py-2.5 text-sm focus:outline-none focus:border-[var(--marca)] disabled:bg-tinta-50" />
                    </div>
                </div>

                <div>
                    <div class="flex items-center justify-between mb-1.5 gap-2 flex-wrap">
                        <label class="block text-sm font-medium text-tinta-700">Contenido</label>
                        <div class="flex items-center gap-2">
                            <span class="text-xs text-tinta-300 hidden sm:inline">
                                Los títulos arman solos el índice
                            </span>
                            <!-- Para el documento que se pega de Word: reconoce los capítulos y
                                 los artículos y los marca como títulos de una vez. -->
                            <button type="button" @click="detectarTitulos"
                                class="text-xs font-semibold border border-linea rounded-lg px-2.5 py-1.5 text-tinta-500 hover:bg-realce hover:text-[var(--marca)] transition-colors">
                                Detectar títulos
                            </button>
                        </div>
                    </div>

                    <p v-if="avisoTitulos" class="text-xs mb-1.5 px-3 py-2 rounded-lg bg-pastel-azul text-aviso-azul">
                        {{ avisoTitulos }}
                    </p>

                    <EditorTexto v-model="form.contenido" titulos
                        placeholder="Escribe o pega aquí el reglamento. Los botones T1 y T2 marcan los títulos…"
                        min-height="420px" />
                </div>

                <!-- Publicar. Es lo único que hace responder el enlace. -->
                <div class="border-t border-linea pt-4 flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-tinta-700">Publicado</p>
                        <p class="text-xs text-tinta-400 mt-0.5">
                            Apagado, el enlace no responde y el QR no lleva a ninguna parte. Nada se borra.
                        </p>
                    </div>

                    <button type="button" @click="puedeEditar && (form.publicado = ! form.publicado)"
                        :disabled="! puedeEditar"
                        class="relative w-11 h-6 rounded-full transition-colors shrink-0 mt-0.5 disabled:opacity-40"
                        :style="form.publicado ? 'background:var(--marca);' : 'background:var(--tinta-200);'"
                        :aria-pressed="form.publicado">
                        <span class="absolute top-0.5 w-5 h-5 bg-superficie rounded-full shadow transition-transform"
                            :style="form.publicado ? 'transform:translateX(22px);' : 'transform:translateX(2px);'" />
                    </button>
                </div>

                <div v-if="puedeEditar" class="flex items-center justify-between gap-3 pt-1 flex-wrap">
                    <p class="text-xs text-tinta-300">
                        <template v-if="reglamento.actualizado_el">
                            Última edición: {{ reglamento.actualizado_el }}
                            <template v-if="reglamento.actualizado_por_nombre"> · {{ reglamento.actualizado_por_nombre }}</template>
                        </template>
                    </p>

                    <button type="submit" :disabled="form.processing"
                        class="rounded-xl px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-60 transition-colors"
                        style="background:var(--marca);">
                        {{ form.processing ? 'Guardando…' : 'Guardar' }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
