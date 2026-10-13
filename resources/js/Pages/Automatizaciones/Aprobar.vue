<script setup>
/**
 * Una automatización pregunta algo y espera la respuesta de esta persona.
 *
 * Es la pantalla a la que lleva el aviso de la campana. Muestra lo que la automatización sabe del
 * documento para decidir sin tener que abrirlo, y un enlace por si hace falta. Decide el primero
 * que conteste; si ya se decidió (o venció), se dice y no se ofrecen los botones.
 */
import { ref, computed } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { post, mensajeDe } from '@/composables/useEquipoIa'

const props = defineProps({
    aprobacion: { type: Object, required: true },
})

const ap      = ref({ ...props.aprobacion })
const nota    = ref('')
const enviando = ref(false)
const error   = ref('')

const vencida = computed(() => ap.value.estado === 'pendiente' && new Date(ap.value.vence) < new Date())
const abierta = computed(() => ap.value.estado === 'pendiente' && ! vencida.value)

async function decidir(decision) {
    enviando.value = true
    error.value = ''
    const r = await post(`/api/automatizaciones/aprobaciones/${ap.value.id}/decidir`, { decision, nota: nota.value || null })
    enviando.value = false

    if (! r.ok) { error.value = mensajeDe(r); return }
    ap.value = r.data
}

const ESTADO = {
    aprobada:  ['Aprobada', 'bg-pastel-verde border-borde-aviso-verde text-aviso-verde'],
    rechazada: ['Rechazada', 'bg-pastel-rojo border-borde-aviso-rojo text-aviso-rojo'],
    vencida:   ['Venció sin respuesta', 'bg-pastel-ambar border-borde-aviso-ambar text-aviso-ambar'],
    cancelada: ['Se canceló', 'bg-superficie border-linea text-tinta-500'],
}
const cuando = (iso) => iso ? new Date(iso).toLocaleString('es-CO', { dateStyle: 'medium', timeStyle: 'short' }) : ''
</script>

<template>
    <AppLayout title="Aprobación">
        <div class="max-w-xl mx-auto px-4 py-6 space-y-4">
            <div>
                <p class="text-xs text-tinta-400 uppercase tracking-[0.12em]">Te piden una aprobación</p>
                <h1 class="text-lg font-bold text-tinta-900 mt-1 break-words">{{ ap.pregunta }}</h1>
                <p class="text-xs text-tinta-500 mt-1">Automatización «{{ ap.automatizacion }}»<span v-if="abierta"> · vence {{ cuando(ap.vence) }}</span></p>
            </div>

            <section v-if="ap.datos?.length" class="rounded-2xl border border-linea bg-superficie p-4">
                <h2 class="text-xs font-semibold text-tinta-500 uppercase tracking-[0.12em] mb-2">{{ ap.documento }}</h2>
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1.5 text-sm">
                    <template v-for="d in ap.datos" :key="d.label">
                        <dt class="text-tinta-400 text-xs sm:text-sm">{{ d.label }}</dt>
                        <dd class="text-tinta-800 break-words">{{ d.valor }}</dd>
                    </template>
                </dl>
                <a v-if="ap.url" :href="ap.url" class="inline-block mt-3 text-sm underline text-tinta-600">Abrir el documento</a>
            </section>

            <template v-if="abierta">
                <div>
                    <label class="block text-xs text-tinta-400 mb-1">Nota (opcional)</label>
                    <textarea v-model="nota" rows="2" maxlength="500" class="w-full border border-linea rounded-xl px-3 py-2 text-sm bg-superficie focus:outline-none focus:border-[var(--marca)]"></textarea>
                </div>
                <p v-if="error" class="text-sm text-aviso-rojo">{{ error }}</p>
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" @click="decidir('rechazar')" :disabled="enviando" class="py-3 rounded-xl border border-borde-aviso-rojo text-aviso-rojo font-semibold hover:bg-pastel-rojo disabled:opacity-50">No</button>
                    <button type="button" @click="decidir('aprobar')" :disabled="enviando" class="py-3 rounded-xl bg-green-600 text-white font-semibold disabled:opacity-50">Sí, adelante</button>
                </div>
                <p class="text-[11px] text-tinta-400">Si dices que no, la automatización se detiene aquí y no hace lo que seguía.</p>
            </template>

            <div v-else :class="['rounded-2xl border p-4 text-sm', (ESTADO[vencida ? 'vencida' : ap.estado] ?? ESTADO.cancelada)[1]]">
                <p class="font-semibold">{{ (ESTADO[vencida ? 'vencida' : ap.estado] ?? ESTADO.cancelada)[0] }}<span v-if="ap.por"> por {{ ap.por }}</span>.</p>
                <p v-if="ap.nota" class="mt-1">«{{ ap.nota }}»</p>
            </div>

            <a href="/" class="inline-block text-sm text-tinta-500 underline">Volver al inicio</a>
        </div>
    </AppLayout>
</template>
