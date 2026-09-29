<script setup>
/**
 * Botón para leer un RUT (o una foto de él) con la IA.
 *
 * Solo trae los datos: quien lo usa los pone en su formulario, y la persona los revisa
 * antes de guardar. Lo que la IA avisa —un dígito de verificación que no cuadra, una
 * casilla que no encontró— se queda a la vista hasta la próxima lectura.
 */
import { ref } from 'vue'

const props = defineProps({
    // A dónde se sube: la ficha del cliente y el perfil fiscal usan rutas distintas.
    url:   { type: String, required: true },
    texto: { type: String, default: 'Leer RUT con IA' },
})

const emit = defineEmits(['leido'])

const leyendo = ref(false)
const error   = ref('')
const avisos  = ref([])
const listo   = ref(false)
const input   = ref(null)

async function leer(evento) {
    const archivo = evento.target.files?.[0]
    if (! archivo) return

    leyendo.value = true
    error.value   = ''
    avisos.value  = []
    listo.value   = false

    try {
        const datos = new FormData()
        datos.append('archivo', archivo)

        const xsrf = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))
        const res  = await fetch(props.url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrf ? decodeURIComponent(xsrf.split('=')[1]) : '',
            },
            body: datos,
        })
        const json = await res.json().catch(() => null)

        if (! res.ok || ! json?.ok) {
            error.value = json?.mensaje
                || Object.values(json?.errors ?? {})[0]?.[0]
                || `No se pudo leer el documento (${res.status}).`
            return
        }

        avisos.value = json.avisos ?? []
        listo.value  = true
        emit('leido', json)
    } catch (e) {
        error.value = 'No se pudo conectar para leer el documento.'
    } finally {
        leyendo.value = false
        if (input.value) input.value.value = ''
    }
}
</script>

<template>
    <div>
        <label class="inline-flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-lg border cursor-pointer transition-colors"
            :class="leyendo ? 'opacity-60 pointer-events-none border-linea text-tinta-400' : 'border-[var(--marca)] text-[var(--marca)] hover:bg-realce'">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            {{ leyendo ? 'Leyendo el documento…' : texto }}
            <input ref="input" type="file" class="hidden" accept=".pdf,image/jpeg,image/png,image/webp" @change="leer"/>
        </label>

        <p v-if="error" class="mt-2 text-xs text-aviso-rojo bg-pastel-rojo border border-borde-aviso-rojo rounded-lg px-3 py-2">{{ error }}</p>

        <div v-if="listo" class="mt-2 text-xs rounded-lg px-3 py-2 border"
            :class="avisos.length ? 'bg-pastel-ambar border-borde-aviso-ambar text-aviso-ambar' : 'bg-pastel-verde border-borde-aviso-verde text-aviso-verde'">
            <p class="font-semibold">Datos puestos en el formulario. Revísalos antes de guardar.</p>
            <ul v-if="avisos.length" class="mt-1 list-disc pl-4 space-y-0.5">
                <li v-for="(a, i) in avisos" :key="i">{{ a }}</li>
            </ul>
        </div>
    </div>
</template>
