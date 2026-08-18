<script setup>
/**
 * El asistente que redacta el objetivo y la descripción de un paso de producción.
 *
 * **Pregunta antes de escribir**, y esa es toda la idea. Un instructivo redactado sin
 * preguntar sale genérico —«realizar el corte con precisión»— y el operario que lo lee ya
 * sabía eso. Primero pide tres o cuatro datos concretos de ESE paso —con qué herramienta, qué
 * tolerancia, cómo se sabe que quedó bien— y con eso sí escribe algo que sirve en la planta.
 *
 * El tono sale del perfil de marca, igual que la ficha técnica.
 */
import { ref, computed } from 'vue'

const props = defineProps({
    // El paso que se está editando, y su vecindario: la IA necesita saber qué va antes y qué
    // va después para no repetir lo que ya dice otro paso.
    paso:        { type: Object, required: true },
    plantilla:   { type: String, default: '' },
    anteriores:  { type: Array,  default: () => [] },
    siguientes:  { type: Array,  default: () => [] },
    variables:   { type: Array,  default: () => [] },
    materiales:  { type: Array,  default: () => [] },
})

const emit = defineEmits(['redactado'])

const abierto   = ref(false)
const cargando  = ref(false)
const error     = ref('')
const preguntas = ref([])

const sinNombre = computed(() => ! (props.paso?.nombre ?? '').trim())

async function pedir(accion) {
    cargando.value = true
    error.value    = ''

    try {
        const res = await fetch('/api/ia/paso-produccion', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                accion,
                paso:        props.paso.nombre,
                plantilla:   props.plantilla,
                objetivo:    props.paso.objetivo ?? '',
                descripcion: props.paso.descripcion ?? '',
                anteriores:  props.anteriores,
                siguientes:  props.siguientes,
                variables:   props.variables,
                materiales:  props.materiales,
                respuestas:  accion === 'redactar'
                    ? preguntas.value.map(p => ({ pregunta: p.texto, respuesta: p.respuesta ?? '' }))
                    : [],
            }),
        })

        const data = await res.json()

        if (! res.ok) {
            error.value = data.error ?? 'No se pudo consultar el asistente.'

            return
        }

        if (accion === 'preguntar') {
            preguntas.value = (data.preguntas ?? []).map(t => ({ texto: t, respuesta: '' }))

            if (! preguntas.value.length) {
                error.value = 'El asistente no devolvió preguntas. Escribe el paso a mano o inténtalo de nuevo.'
            }

            return
        }

        emit('redactado', { objetivo: data.objetivo, descripcion: data.descripcion })
        abierto.value = false
        preguntas.value = []
    } catch {
        error.value = 'No se pudo consultar el asistente.'
    } finally {
        cargando.value = false
    }
}

function abrir() {
    abierto.value = true
    preguntas.value = []
    error.value = ''
    pedir('preguntar')
}
</script>

<template>
    <div>
        <button type="button" @click="abierto ? (abierto = false) : abrir()" :disabled="sinNombre || cargando"
            class="text-xs inline-flex items-center gap-1 text-[var(--marca)] disabled:opacity-40 disabled:cursor-not-allowed hover:underline underline-offset-2">
            <span aria-hidden="true">✦</span>
            {{ cargando && ! abierto ? 'Pensando…' : 'Redactar con IA' }}
        </button>

        <p v-if="sinNombre" class="text-xs text-tinta-300 mt-1">
            Escribe primero el nombre del paso: el asistente pregunta sobre ese paso, no sobre uno cualquiera.
        </p>

        <div v-if="abierto" class="mt-2 rounded-xl border border-linea bg-tinta-50 p-3">
            <p v-if="error" class="text-xs text-aviso-rojo mb-2">{{ error }}</p>

            <p v-if="cargando && ! preguntas.length" class="text-xs text-tinta-400">
                Preparando las preguntas de este paso…
            </p>

            <template v-if="preguntas.length">
                <p class="text-xs text-tinta-500 mb-2">
                    Responde lo que sepas —lo que dejes en blanco simplemente no se menciona, y
                    es mejor así que un instructivo con un dato inventado.
                </p>

                <div class="space-y-2">
                    <div v-for="(p, i) in preguntas" :key="i">
                        <label class="block text-xs text-tinta-500 mb-1">{{ p.texto }}</label>
                        <input v-model="p.respuesta" type="text" maxlength="600"
                            class="w-full border border-linea rounded-lg px-3 py-1.5 text-sm bg-superficie focus:outline-none focus:border-[var(--marca)]" />
                    </div>
                </div>

                <div class="flex items-center gap-2 mt-3">
                    <button type="button" @click="pedir('redactar')" :disabled="cargando"
                        class="text-xs px-3 py-1.5 rounded-lg text-white disabled:opacity-60"
                        style="background:var(--marca);">
                        {{ cargando ? 'Redactando…' : 'Redactar objetivo y descripción' }}
                    </button>
                    <button type="button" @click="abierto = false" class="text-xs text-tinta-400 hover:text-tinta-700">
                        Cancelar
                    </button>
                </div>
            </template>
        </div>
    </div>
</template>
