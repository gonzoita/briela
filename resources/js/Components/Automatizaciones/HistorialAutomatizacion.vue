<script setup>
/**
 * Las últimas corridas de una automatización y, al abrir una, su caja negra nodo por nodo.
 *
 * Una corrida que espera muestra cuándo sigue y se puede cancelar. Una que sigue en cola o corriendo
 * se actualiza sola cada pocos segundos.
 */
import { ref, onMounted, onUnmounted } from 'vue'
import { get, post, mensajeDe, hace } from '@/composables/useEquipoIa'

const props = defineProps({
    id:       { type: Number, required: true },
    nombre:   { type: String, default: '' },
    ejecutar: { type: Boolean, default: false },
})
const emit = defineEmits(['cerrar', 'cambio'])

const lista   = ref([])
const abierta = ref(null)
const detalle = ref(null)
const aviso   = ref('')
let timer = null

async function cargar() {
    const r = await get(`/api/automatizaciones/${props.id}/ejecuciones`)
    if (r.ok) lista.value = r.data

    if (abierta.value) {
        const d = await get(`/api/automatizaciones/ejecuciones/${abierta.value}`)
        if (d.ok) detalle.value = d.data
    }
}

onMounted(() => { cargar(); timer = setInterval(cargar, 5000) })
onUnmounted(() => { if (timer) clearInterval(timer) })

async function abrir(e) { abierta.value = e.id; detalle.value = null; await cargar() }

async function cancelar(e) {
    const r = await post(`/api/automatizaciones/ejecuciones/${e.id}/cancelar`)
    aviso.value = r.ok ? 'Corrida cancelada.' : mensajeDe(r)
    await cargar()
    emit('cambio')
}

const ESTADO = {
    en_cola:   ['En cola',    'bg-pastel-azul-2 text-aviso-azul'],
    corriendo: ['Corriendo',  'bg-pastel-ambar-2 text-aviso-ambar'],
    esperando: ['Esperando',  'bg-pastel-violeta-2 text-aviso-violeta'],
    terminada: ['Terminó',    'bg-pastel-verde-2 text-aviso-verde'],
    fallida:   ['Falló',      'bg-pastel-rojo-2 text-aviso-rojo'],
    cancelada: ['Cancelada',  'bg-tinta-100 text-tinta-500'],
}
const PASO = { ok: 'text-aviso-verde', omitido: 'text-tinta-400', espera: 'text-aviso-violeta', error: 'text-aviso-rojo' }
const cuando = (iso) => iso ? new Date(iso).toLocaleString('es-CO', { dateStyle: 'medium', timeStyle: 'short' }) : ''
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/40 p-0 sm:p-4" @click.self="emit('cerrar')">
        <div class="bg-superficie w-full sm:max-w-2xl max-h-[90vh] overflow-y-auto rounded-t-2xl sm:rounded-2xl shadow-xl p-5 space-y-4" role="dialog" aria-modal="true">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="text-base font-semibold text-tinta-900">Historial</h3>
                    <p class="text-xs text-tinta-400 mt-0.5">{{ nombre }}</p>
                </div>
                <button type="button" @click="emit('cerrar')" class="text-tinta-400 hover:text-tinta-700 text-xl leading-none" aria-label="Cerrar">×</button>
            </div>

            <p v-if="aviso" class="text-sm rounded-xl border border-borde-aviso-azul bg-pastel-azul text-aviso-azul p-3">{{ aviso }}</p>
            <p v-if="! lista.length" class="text-sm text-tinta-300 text-center py-8">Todavía no ha corrido.</p>

            <ul class="divide-y divide-separador border border-linea rounded-xl">
                <li v-for="e in lista" :key="e.id" class="p-3">
                    <div class="flex items-center justify-between gap-2 flex-wrap">
                        <button type="button" @click="abrir(e)" class="text-left min-w-0">
                            <span :class="['text-[11px] px-2 py-0.5 rounded-full font-semibold', ESTADO[e.estado]?.[1]]">{{ ESTADO[e.estado]?.[0] ?? e.estado }}</span>
                            <span class="text-xs text-tinta-600 ml-2">{{ e.documento || '—' }}</span>
                            <span class="text-[11px] text-tinta-400 ml-2">{{ hace(e.creada) }} · {{ e.origen }}</span>
                        </button>
                        <button v-if="ejecutar && ['en_cola', 'esperando'].includes(e.estado)" type="button" @click="cancelar(e)"
                            class="text-xs px-2.5 py-1 rounded-lg border border-linea text-tinta-500 hover:bg-realce">Cancelar</button>
                    </div>
                    <p v-if="e.estado === 'esperando'" class="text-[11px] text-aviso-violeta mt-1">Sigue el {{ cuando(e.reanudar) }}</p>
                    <p v-if="e.error" class="text-[11px] text-tinta-500 mt-1">{{ e.error }}</p>

                    <ol v-if="abierta === e.id && detalle" class="mt-2 divide-y divide-separador border border-linea rounded-lg">
                        <li v-for="p in detalle.pasos" :key="p.orden" class="p-2 text-xs">
                            <span :class="['font-semibold', PASO[p.estado]]">{{ p.orden }}. {{ p.tipo }}</span>
                            <span class="text-tinta-400"> · {{ p.ms }} ms</span>
                            <p class="text-tinta-500 break-words">{{ p.resumen }}</p>
                        </li>
                        <li v-if="! detalle.pasos.length" class="p-2 text-xs text-tinta-300">Todavía sin pasos.</li>
                    </ol>
                </li>
            </ul>
        </div>
    </div>
</template>
