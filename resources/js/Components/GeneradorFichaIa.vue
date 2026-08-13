<script setup>
/**
 * Genera la ficha técnica con IA y la deja en los dos campos de descripción.
 *
 * Pide los **datos técnicos en bruto** porque son la materia prima: sin ellos la IA solo
 * tiene el nombre y la categoría, y una ficha inventada a partir de un nombre es
 * exactamente lo que el prompt prohíbe. Todo lo demás lo toma del formulario, así que
 * funciona antes de guardar el producto.
 *
 * Muestra el resultado y espera: nada se escribe en el formulario hasta que se toca «Usar
 * esto», y ni siquiera entonces se guarda —eso sigue siendo el botón Guardar de siempre.
 */
import { ref, computed } from 'vue'

const props = defineProps({
    // Lo que se le manda: nombre, referencia, categoria, unidad, tipo, descripcion_corta.
    datos:       { type: Object, required: true },
    // Para un ensamble: sus variables y componentes se leen en el servidor.
    ensambleId:  { type: Number, default: null },
})

const emit = defineEmits(['usar'])

const abierto   = ref(false)
const cargando  = ref(false)
const error     = ref('')
const brutos    = ref('')
const resultado = ref(null)

const csrf = () => {
    const c = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))
    return c ? decodeURIComponent(c.split('=')[1]) : ''
}

const faltaNombre = computed(() => ! (props.datos?.nombre || '').trim())

async function generar() {
    cargando.value = true
    error.value = ''
    resultado.value = null

    try {
        const res = await fetch('/api/ia/ficha-tecnica', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': csrf(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                ...props.datos,
                datos_brutos: brutos.value,
                ensamble_id: props.ensambleId,
            }),
        })

        const data = await res.json().catch(() => null)

        if (! res.ok) throw new Error(data?.error || `No se pudo generar (${res.status}).`)

        resultado.value = data
    } catch (e) {
        error.value = e.message || 'No se pudo conectar con la IA.'
    } finally {
        cargando.value = false
    }
}

function usar() {
    emit('usar', {
        descripcion_corta: resultado.value?.descripcion_corta ?? '',
        descripcion_larga: resultado.value?.ficha_html ?? '',
    })
    abierto.value = false
    resultado.value = null
}
</script>

<template>
    <button type="button" @click="abierto = true"
        class="text-xs font-semibold text-[var(--marca)] hover:underline inline-flex items-center gap-1">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/>
        </svg>
        Ficha técnica con IA
    </button>

    <Teleport to="body">
        <div v-if="abierto" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
            <div class="absolute inset-0 bg-black/40" @click="abierto = false" />

            <div class="relative w-full sm:max-w-2xl bg-superficie rounded-t-3xl sm:rounded-2xl shadow-2xl flex flex-col max-h-[92vh]">
                <div class="flex items-center justify-between px-5 py-4 border-b border-linea shrink-0">
                    <div class="min-w-0">
                        <h3 class="text-base font-semibold text-tinta-900">Ficha técnica con IA</h3>
                        <p class="text-xs text-tinta-400 truncate">{{ datos.nombre || 'Sin nombre todavía' }}</p>
                    </div>
                    <button type="button" @click="abierto = false"
                        class="w-8 h-8 flex items-center justify-center rounded-full text-tinta-300 hover:bg-tinta-100 text-lg">✕</button>
                </div>

                <div class="p-5 overflow-y-auto space-y-4">
                    <p v-if="faltaNombre" class="text-xs px-3 py-2 rounded-xl"
                        style="background:var(--pastel-ambar);color:var(--texto-ambar);">
                        Ponle nombre al producto antes de generar la ficha: es lo primero que la IA
                        necesita para saber de qué está escribiendo.
                    </p>

                    <div>
                        <label class="block text-sm font-medium text-tinta-700 mb-1">
                            Datos técnicos en bruto
                        </label>
                        <textarea v-model="brutos" rows="8"
                            placeholder="Pega aquí las medidas, materiales, potencia, voltaje, acabados, normas, lo que tengas. Como venga: la IA lo organiza. Lo que no le des, no lo va a inventar."
                            class="w-full border border-linea rounded-xl px-3 py-2 text-sm bg-superficie focus:outline-none focus:border-[var(--marca)]"></textarea>
                        <p class="text-xs text-tinta-300 mt-1">
                            El nombre, la referencia, la categoría y la unidad se toman del formulario.
                            <template v-if="ensambleId">Las medidas y los componentes de la receta también.</template>
                        </p>
                    </div>

                    <button type="button" @click="generar" :disabled="cargando || faltaNombre"
                        class="w-full py-2.5 rounded-xl text-white text-sm font-semibold disabled:opacity-50"
                        style="background:var(--marca);">
                        {{ cargando ? 'Redactando la ficha…' : 'Generar ficha' }}
                    </button>

                    <p v-if="error" class="text-xs px-3 py-2 rounded-xl" style="background:#FEF2F2;color:#B91C1C;">
                        {{ error }}
                    </p>

                    <!-- Resultado -->
                    <div v-if="resultado" class="space-y-3 pt-2 border-t border-linea">
                        <p v-if="resultado.aviso" class="text-xs px-3 py-2 rounded-xl"
                            style="background:var(--pastel-ambar);color:var(--texto-ambar);">
                            {{ resultado.aviso }}
                        </p>

                        <div>
                            <p class="text-xs font-semibold text-tinta-400 uppercase tracking-wide mb-1">
                                Descripción corta ({{ (resultado.descripcion_corta || '').length }} caracteres)
                            </p>
                            <p class="text-sm text-tinta-700 p-3 rounded-xl" style="background:var(--superficie-2);">
                                {{ resultado.descripcion_corta || '— vacía —' }}
                            </p>
                        </div>

                        <div>
                            <p class="text-xs font-semibold text-tinta-400 uppercase tracking-wide mb-1">Descripción larga</p>
                            <div class="tiptap-content text-sm text-tinta-700 p-3 rounded-xl max-h-72 overflow-y-auto"
                                style="background:var(--superficie-2);" v-html="resultado.ficha_html"></div>
                        </div>

                        <div class="flex gap-2">
                            <button type="button" @click="generar" :disabled="cargando"
                                class="px-4 py-2.5 rounded-xl border border-linea text-sm text-tinta-500 hover:bg-tinta-50 disabled:opacity-50">
                                Otra versión
                            </button>
                            <button type="button" @click="usar"
                                class="flex-1 py-2.5 rounded-xl text-white text-sm font-semibold"
                                style="background:var(--marca);">
                                Usar esto
                            </button>
                        </div>
                        <p class="text-xs text-tinta-300 text-center">
                            «Usar esto» reemplaza lo que haya en los dos campos. No guarda el producto.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
