<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import { router } from '@inertiajs/vue3'
import { reactive, ref } from 'vue'

const props = defineProps({
    intento:   Object,
    preguntas: Array,
})

const notas = reactive(
    Object.fromEntries(
        props.preguntas
            .filter(p => p.tipo === 'abierta')
            .map(p => [p.id, false])
    )
)

const guardando = ref(false)
const error      = ref('')

const csrf    = () => { const c = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN=')); return c ? decodeURIComponent(c.split('=')[1]) : '' }

async function calificar() {
    guardando.value = true
    error.value     = ''
    try {
        const res = await fetch(`/capacitacion/revision-evaluaciones/${props.intento.id}/calificar`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': csrf(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                notas: Object.entries(notas).map(([pregunta_id, correcta]) => ({ pregunta_id: Number(pregunta_id), correcta })),
            }),
        })
        if (!res.ok) throw new Error()
        router.visit('/capacitacion/revision-evaluaciones')
    } catch { error.value = 'Error al calificar la evaluación.'; guardando.value = false }
}
</script>

<template>
    <AppLayout title="Calificar evaluación">
        <div class="max-w-2xl mx-auto">

            <div class="flex items-center gap-3 mb-5">
                <button @click="router.visit('/capacitacion/revision-evaluaciones')" class="w-9 h-9 rounded-xl flex items-center justify-center text-tinta-400 hover:bg-tinta-100 transition-colors shrink-0">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                    </svg>
                </button>
                <div class="flex-1 min-w-0">
                    <h1 class="text-lg font-semibold text-tinta-900 truncate">{{ intento.estudiante }}</h1>
                    <p class="text-xs text-tinta-300">{{ intento.curso }} · Intento #{{ intento.numero_intento }}</p>
                </div>
            </div>

            <div class="space-y-3 mb-4">
                <div v-for="(pregunta, idx) in preguntas" :key="pregunta.id" class="bg-white rounded-2xl shadow-sm p-4">
                    <p class="text-xs text-tinta-300 mb-1">Pregunta {{ idx + 1 }}</p>
                    <p class="text-sm font-semibold text-tinta-900 mb-3">{{ pregunta.enunciado }}</p>

                    <template v-if="pregunta.tipo === 'opcion_multiple'">
                        <div v-for="o in pregunta.opciones" :key="o.id"
                            class="text-sm px-3 py-2 rounded-xl mb-1"
                            :class="o.id === pregunta.opcion_id
                                ? (pregunta.es_correcta ? 'bg-green-50 text-green-800 font-semibold' : 'bg-red-50 text-red-800 font-semibold')
                                : 'text-tinta-400'">
                            {{ o.id === pregunta.opcion_id ? '→ ' : '' }}{{ o.texto }}
                        </div>
                        <p class="text-xs mt-1" :class="pregunta.es_correcta ? 'text-green-600' : 'text-red-600'">
                            {{ pregunta.es_correcta ? 'Respuesta correcta (calificada automáticamente)' : 'Respuesta incorrecta (calificada automáticamente)' }}
                        </p>
                    </template>

                    <template v-else>
                        <div class="bg-tinta-50 rounded-xl p-3 text-sm text-tinta-700 mb-3 whitespace-pre-wrap">{{ pregunta.texto_respuesta || '(sin respuesta)' }}</div>
                        <label class="flex items-center gap-2 text-sm text-tinta-700">
                            <input type="checkbox" v-model="notas[pregunta.id]" class="rounded" />
                            Marcar como correcta
                        </label>
                    </template>
                </div>
            </div>

            <p v-if="error" class="text-xs text-red-500 mb-2">{{ error }}</p>
            <button @click="calificar" :disabled="guardando"
                class="w-full py-3 rounded-xl text-sm font-semibold text-white disabled:opacity-50" style="background:var(--marca);">
                {{ guardando ? 'Guardando...' : 'Calificar y finalizar' }}
            </button>

        </div>
    </AppLayout>
</template>
