<script setup>
// Generador de plantillas con IA, en dos caminos que comparten el mismo meta-prompt
// (lo arma el servidor, PdfPlantillaIaService):
//   A — Briela lo genera por el proxy y lo deja en el editor.
//   B — se copia el meta-prompt para una IA externa y se pega lo que devuelva.
// Las dos terminan igual: encabezado, cuerpo y pie en el modo código.
import { ref } from 'vue'
import { useClipboard } from '@/composables/useClipboard'

const props = defineProps({
    modulo:      String,
    moduloLabel: String,
    actual:      Object, // { header, body, footer }
})
const emit = defineEmits(['cerrar', 'aplicar'])
const { copyText } = useClipboard()

const flujo       = ref('briela')
const instruccion = ref('')
const incluirActual = ref(Boolean((props.actual?.body ?? '').trim()))
const cargando    = ref(false)
const error       = ref('')
const prompt      = ref('')
const copiado     = ref(false)
const pegado      = ref('')

function getCsrf() {
    const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/)
    return m ? decodeURIComponent(m[1]) : ''
}

async function post(url, body) {
    const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-XSRF-TOKEN': getCsrf() },
        credentials: 'same-origin',
        body: JSON.stringify(body),
    })
    const data = await res.json().catch(() => ({}))
    if (!res.ok) throw new Error(data.error ?? Object.values(data.errors ?? {})[0]?.[0] ?? 'No se pudo completar la solicitud')
    return data
}

const actualSiIncluye = () => incluirActual.value ? { ...props.actual } : { header: '', body: '', footer: '' }

async function generarConBriela() {
    if (!instruccion.value.trim()) { error.value = 'Escribe qué quieres que haga Briela.'; return }
    cargando.value = true
    error.value = ''
    try {
        const partes = await post('/configuracion/plantillas-pdf/ia', {
            modulo: props.modulo, instruccion: instruccion.value, ...actualSiIncluye(),
        })
        emit('aplicar', partes)
    } catch (e) {
        error.value = e.message
    } finally {
        cargando.value = false
    }
}

async function compilarPrompt() {
    cargando.value = true
    error.value = ''
    try {
        const data = await post('/configuracion/plantillas-pdf/meta-prompt', {
            modulo: props.modulo, instruccion: instruccion.value, incluir_actual: incluirActual.value, ...props.actual,
        })
        prompt.value = data.prompt
        copiado.value = await copyText(data.prompt)
    } catch (e) {
        error.value = e.message
    } finally {
        cargando.value = false
    }
}

async function aplicarPegado() {
    if (!pegado.value.trim()) { error.value = 'Pega primero el HTML que te devolvió la IA.'; return }
    cargando.value = true
    error.value = ''
    try {
        emit('aplicar', await post('/configuracion/plantillas-pdf/separar', { texto: pegado.value }))
    } catch (e) {
        error.value = e.message
    } finally {
        cargando.value = false
    }
}
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/50 sm:p-4" @click.self="emit('cerrar')">
        <div class="bg-superficie w-full sm:max-w-2xl rounded-t-2xl sm:rounded-2xl shadow-xl max-h-[92vh] flex flex-col">
            <div class="flex items-center justify-between px-4 py-3 border-b border-linea">
                <div>
                    <p class="text-sm font-semibold text-tinta-900">Generar con IA</p>
                    <p class="text-xs text-tinta-400">{{ moduloLabel }}</p>
                </div>
                <button @click="emit('cerrar')" class="p-2 rounded-lg hover:bg-realce text-tinta-400" aria-label="Cerrar">✕</button>
            </div>

            <div class="flex gap-1 p-1 mx-4 mt-3 bg-tinta-100 rounded-xl">
                <button v-for="t in [{ k: 'briela', l: 'Con Briela' }, { k: 'externa', l: 'Con otra IA' }]" :key="t.k"
                    @click="flujo = t.k; error = ''"
                    class="flex-1 py-2 text-xs font-medium rounded-lg transition-colors"
                    :class="flujo === t.k ? 'bg-superficie text-[var(--marca)] shadow-sm' : 'text-tinta-400'">{{ t.l }}</button>
            </div>

            <div class="p-4 space-y-3 overflow-y-auto">
                <div>
                    <label class="text-xs font-medium text-tinta-700">Qué quieres</label>
                    <textarea v-model="instruccion" rows="4"
                        class="mt-1 w-full text-sm border border-linea rounded-xl p-3 focus:outline-none focus:ring-2 focus:ring-[var(--marca)]"
                        :placeholder="`Ej: Cotización elegante con logo a la izquierda, tabla con imagen de cada ítem, totales a la derecha y condiciones al final.`" />
                </div>
                <label class="flex items-center gap-2 text-xs text-tinta-700">
                    <input type="checkbox" v-model="incluirActual" class="rounded" />
                    Partir de la plantilla actual (editarla en vez de empezar de cero)
                </label>

                <template v-if="flujo === 'briela'">
                    <p class="text-xs text-tinta-400">Briela conoce todas las variables de este documento y las reglas del motor de PDF. El resultado queda en el modo código para que lo revises antes de guardar.</p>
                    <button @click="generarConBriela" :disabled="cargando"
                        class="w-full py-2.5 rounded-xl text-sm font-semibold text-white disabled:opacity-60" style="background-color: var(--marca);">
                        {{ cargando ? 'Briela está maquetando…' : 'Generar plantilla' }}
                    </button>
                </template>

                <template v-else>
                    <div class="rounded-xl border border-linea p-3 space-y-2">
                        <p class="text-xs font-semibold text-tinta-700">1. Copia el meta-prompt</p>
                        <p class="text-xs text-tinta-400">Lleva el diccionario de variables, el CSS que soporta el motor y el formato de respuesta.</p>
                        <button @click="compilarPrompt" :disabled="cargando"
                            class="w-full py-2 rounded-lg text-xs font-semibold border border-linea hover:bg-realce disabled:opacity-60">
                            {{ copiado ? '✓ Copiado — pégalo en ChatGPT, Claude o Gemini' : 'Compilar y copiar' }}
                        </button>
                        <textarea v-if="prompt" :value="prompt" readonly rows="5"
                            class="w-full text-xs font-mono border border-linea rounded-lg p-2 bg-tinta-50" @focus="$event.target.select()" />
                    </div>
                    <div class="rounded-xl border border-linea p-3 space-y-2">
                        <p class="text-xs font-semibold text-tinta-700">2. Pega lo que te devolvió</p>
                        <textarea v-model="pegado" rows="6" spellcheck="false"
                            class="w-full text-xs font-mono border border-linea rounded-lg p-2 focus:outline-none focus:ring-2 focus:ring-[var(--marca)]"
                            placeholder="<!-- ENCABEZADO --> … <!-- CUERPO --> … <!-- PIE --> …" />
                        <button @click="aplicarPegado" :disabled="cargando"
                            class="w-full py-2.5 rounded-xl text-sm font-semibold text-white disabled:opacity-60" style="background-color: var(--marca);">
                            Usar este HTML
                        </button>
                    </div>
                </template>

                <p v-if="error" class="text-xs rounded-lg p-2 bg-pastel-rojo text-aviso-rojo border border-borde-aviso-rojo">{{ error }}</p>
            </div>
        </div>
    </div>
</template>
