<script setup>
import PortalLayout from '@/Layouts/PortalLayout.vue'
import { router } from '@inertiajs/vue3'
import { reactive, ref, computed } from 'vue'

const props = defineProps({
    curso:             Object,
    evaluacion:        Object,
    preguntas:         Array,
    intentosRestantes: { type: Number, default: null },
    yaAprobado:        { type: Boolean, default: false },
    ultimoEstado:      { type: String, default: null },
    rutaBase:          String,
    esFinal:           { type: Boolean, default: true },
    urlEnviar:         String,
})

const respuestas = reactive(Object.fromEntries(props.preguntas.map(p => [p.id, p.tipo === 'opcion_multiple' ? null : ''])))
const enviando    = ref(false)
const error       = ref('')
const resultado   = ref(props.ultimoEstado === 'pendiente_revision' ? { estado: 'pendiente_revision' } : null)

const todasRespondidas = computed(() =>
    props.preguntas.every(p => {
        const r = respuestas[p.id]
        return p.tipo === 'opcion_multiple' ? r !== null : r.trim().length > 0
    })
)

const csrf    = () => { const c = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN=')); return c ? decodeURIComponent(c.split('=')[1]) : '' }
const jsonHdr = () => ({ 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' })

async function enviar() {
    if (!todasRespondidas.value || enviando.value) return
    enviando.value = true
    error.value    = ''

    const payload = props.preguntas.map(p => ({
        pregunta_id: p.id,
        opcion_id: p.tipo === 'opcion_multiple' ? respuestas[p.id] : null,
        texto_respuesta: p.tipo === 'abierta' ? respuestas[p.id] : null,
    }))

    try {
        const res = await fetch(props.urlEnviar, {
            method: 'POST', headers: jsonHdr(),
            body: JSON.stringify({ respuestas: payload }),
        })
        if (!res.ok) throw new Error()
        resultado.value = await res.json()
    } catch { error.value = 'Ocurrió un error al enviar tus respuestas. Intenta de nuevo.' }
    finally { enviando.value = false }
}

function reintentar() {
    resultado.value = null
    props.preguntas.forEach(p => { respuestas[p.id] = p.tipo === 'opcion_multiple' ? null : '' })
}
</script>

<template>
    <PortalLayout :title="curso.titulo" :es-colaborador="rutaBase === '/mi-capacitacion'">
        <div class="max-w-xl mx-auto space-y-4">

            <!-- Ya aprobado previamente -->
            <div v-if="yaAprobado && !resultado" class="bg-superficie rounded-2xl shadow-sm py-10 px-6 text-center">
                <div class="w-16 h-16 rounded-full mx-auto mb-3 flex items-center justify-center" style="background:var(--pastel-verde);">
                    <svg class="w-8 h-8 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
                <p class="text-lg font-semibold text-tinta-900">Ya aprobaste esta evaluación</p>
                <button @click="router.visit(`${rutaBase}/${curso.id}`)" class="mt-4 px-5 py-2.5 rounded-xl text-sm font-semibold text-white" style="background:var(--marca);">
                    Volver al curso
                </button>
            </div>

            <!-- Pendiente de revisión -->
            <div v-else-if="resultado?.estado === 'pendiente_revision'" class="bg-superficie rounded-2xl shadow-sm py-10 px-6 text-center">
                <div class="w-16 h-16 rounded-full mx-auto mb-3 flex items-center justify-center" style="background:var(--pastel-ambar);">
                    <svg class="w-8 h-8" style="color:var(--texto-ambar);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <p class="text-lg font-semibold text-tinta-900">Tu evaluación está en revisión</p>
                <p class="text-sm text-tinta-400 mt-1">Tiene preguntas de respuesta abierta que un administrador debe calificar. Te avisaremos cuando tengamos el resultado.</p>
                <button @click="router.visit(`${rutaBase}/${curso.id}`)" class="mt-4 px-5 py-2.5 rounded-xl text-sm font-semibold text-tinta-500 border border-linea hover:bg-tinta-50">
                    Volver al curso
                </button>
            </div>

            <!-- Aprobado -->
            <div v-else-if="resultado?.aprobado" class="bg-superficie rounded-2xl shadow-sm py-10 px-6 text-center">
                <p class="text-4xl mb-2">🎉</p>
                <div class="w-16 h-16 rounded-full mx-auto mb-3 flex items-center justify-center" style="background:var(--pastel-verde);">
                    <svg class="w-8 h-8 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
                <p class="text-lg font-semibold text-tinta-900">{{ esFinal ? '¡Curso aprobado!' : '¡Módulo desbloqueado!' }}</p>
                <p class="text-sm text-tinta-400 mt-1">Nota: {{ resultado.nota }}%</p>
                <button @click="router.visit(`${rutaBase}/${curso.id}`)" class="mt-4 px-5 py-2.5 rounded-xl text-sm font-semibold text-white" style="background:var(--marca);">
                    Volver al curso
                </button>
            </div>

            <!-- Reprobado -->
            <div v-else-if="resultado && resultado.aprobado === false" class="bg-superficie rounded-2xl shadow-sm py-10 px-6 text-center">
                <div class="w-16 h-16 rounded-full mx-auto mb-3 flex items-center justify-center" style="background:var(--pastel-rojo);">
                    <svg class="w-8 h-8 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </div>
                <p class="text-lg font-semibold text-tinta-900">No alcanzaste la nota mínima</p>
                <p class="text-sm text-tinta-400 mt-1">Nota: {{ resultado.nota }}% · Mínimo requerido: {{ evaluacion.nota_minima_aprobacion }}%</p>
                <div class="flex gap-2 mt-4 justify-center">
                    <button @click="router.visit(`${rutaBase}/${curso.id}`)" class="px-5 py-2.5 rounded-xl text-sm font-semibold text-tinta-500 border border-linea hover:bg-tinta-50">
                        Volver al curso
                    </button>
                    <button v-if="intentosRestantes === null || intentosRestantes > 0" @click="reintentar" class="px-5 py-2.5 rounded-xl text-sm font-semibold text-white" style="background:var(--marca);">
                        Reintentar
                    </button>
                </div>
            </div>

            <!-- Formulario de evaluación -->
            <template v-else>
                <div class="bg-superficie rounded-2xl shadow-sm p-4">
                    <p class="text-sm font-semibold text-tinta-900">{{ evaluacion.nombre }}</p>
                    <p class="text-xs text-tinta-300 mt-0.5">
                        Nota mínima para aprobar: {{ evaluacion.nota_minima_aprobacion }}%
                        <span v-if="intentosRestantes !== null"> · Intentos restantes: {{ intentosRestantes }}</span>
                    </p>
                </div>

                <div v-for="(pregunta, idx) in preguntas" :key="pregunta.id" class="bg-superficie rounded-2xl shadow-sm p-4">
                    <p class="text-xs text-tinta-300 mb-1">Pregunta {{ idx + 1 }} de {{ preguntas.length }}</p>
                    <p class="text-sm font-semibold text-tinta-900 mb-3">{{ pregunta.enunciado }}</p>

                    <div v-if="pregunta.tipo === 'opcion_multiple'" class="space-y-2">
                        <label v-for="o in pregunta.opciones" :key="o.id"
                            class="flex items-center gap-2.5 px-3 py-2.5 rounded-xl border cursor-pointer transition-colors"
                            :class="respuestas[pregunta.id] === o.id ? 'border-blue-400 bg-blue-50' : 'border-linea'">
                            <input type="radio" :name="`pregunta-${pregunta.id}`" :value="o.id" v-model="respuestas[pregunta.id]" />
                            <span class="text-sm text-tinta-900">{{ o.texto }}</span>
                        </label>
                    </div>

                    <textarea v-else v-model="respuestas[pregunta.id]" rows="4" placeholder="Escribe tu respuesta..."
                        class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]"></textarea>
                </div>

                <p v-if="error" class="text-xs text-red-500">{{ error }}</p>

                <button @click="enviar" :disabled="!todasRespondidas || enviando"
                    class="w-full py-3 rounded-xl text-sm font-semibold text-white disabled:opacity-50" style="background:var(--marca);">
                    {{ enviando ? 'Enviando...' : 'Enviar evaluación' }}
                </button>
            </template>

        </div>
    </PortalLayout>
</template>
