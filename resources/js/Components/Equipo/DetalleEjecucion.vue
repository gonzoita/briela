<script setup>
/**
 * La caja negra de una corrida: qué pidió el modelo, qué herramienta usó y qué respondió.
 *
 * Si la corrida sigue en curso, se consulta sola cada pocos segundos hasta que termina. En un
 * hosting compartido el cron toma lo que hay en cola una vez por minuto, así que una corrida
 * recién pedida puede tardar hasta un minuto en arrancar: la pantalla lo dice.
 */
import { ref, onMounted, onUnmounted } from 'vue'
import { get, hace } from '@/composables/useEquipoIa'

const props = defineProps({ id: { type: Number, required: true } })
const emit  = defineEmits(['cerrar', 'terminada'])

const e = ref(null)
const error = ref('')
let timer = null

async function cargar() {
    const r = await get(`/api/equipo-ia/ejecuciones/${props.id}`)

    if (! r.ok) { error.value = 'No se pudo leer la corrida.'; detener(); return }

    e.value = r.data

    if (! ['en_cola', 'corriendo'].includes(r.data.estado)) {
        detener()
        emit('terminada', r.data)
    }
}

function detener() { if (timer) { clearInterval(timer); timer = null } }

onMounted(() => { cargar(); timer = setInterval(cargar, 3000) })
onUnmounted(detener)

const ETIQUETAS = { en_cola: 'En cola', corriendo: 'Corriendo', terminada: 'Terminada', fallida: 'Falló', cancelada: 'Cancelada' }
const TIPOS = { mensaje: 'Modelo', herramienta: 'Herramienta', error: 'Aviso' }
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/40 p-0 sm:p-4" @click.self="emit('cerrar')">
        <div class="bg-superficie w-full sm:max-w-2xl max-h-[90vh] overflow-y-auto rounded-t-2xl sm:rounded-2xl shadow-xl p-5 space-y-4" role="dialog" aria-modal="true">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="text-base font-semibold text-tinta-900">Actividad de la corrida</h3>
                    <p v-if="e" class="text-xs text-tinta-400 mt-0.5">
                        {{ ETIQUETAS[e.estado] ?? e.estado }}<template v-if="e.prueba"> · prueba (no escribió nada)</template>
                        · {{ hace(e.terminada || e.iniciada || e.creada) }}
                    </p>
                </div>
                <button type="button" @click="emit('cerrar')" class="text-tinta-400 hover:text-tinta-700 text-xl leading-none" aria-label="Cerrar">×</button>
            </div>

            <p v-if="error" class="text-sm text-aviso-rojo">{{ error }}</p>
            <p v-else-if="! e" class="text-sm text-tinta-300">Cargando…</p>

            <template v-else>
                <p v-if="e.estado === 'en_cola'" class="text-xs rounded-xl border border-borde-aviso-azul bg-pastel-azul text-aviso-azul p-3">
                    En cola. El proceso programado la toma en menos de un minuto.
                </p>
                <p v-if="e.estado === 'corriendo'" class="text-xs rounded-xl border border-borde-aviso-ambar bg-pastel-ambar text-aviso-ambar p-3">
                    Trabajando… se actualiza sola.
                </p>
                <p v-if="e.error" class="text-sm rounded-xl border border-borde-aviso-rojo bg-pastel-rojo text-aviso-rojo p-3">{{ e.error }}</p>

                <div v-if="e.resumen">
                    <p class="text-xs font-semibold text-tinta-500 uppercase tracking-[0.12em] mb-1.5">Lo que entregó</p>
                    <div class="text-sm text-tinta-700 whitespace-pre-line leading-relaxed bg-realce rounded-xl p-3">{{ e.resumen }}</div>
                </div>

                <div v-if="e.propuestas?.length">
                    <p class="text-xs font-semibold text-tinta-500 uppercase tracking-[0.12em] mb-1.5">Propuestas que dejó</p>
                    <ul class="space-y-1.5">
                        <li v-for="p in e.propuestas" :key="p.id" class="text-xs text-tinta-600 border border-linea rounded-xl p-2.5">
                            <span class="font-semibold">{{ p.etiqueta }}</span> — {{ p.resumen }}
                            <span class="text-tinta-400"> ({{ p.estado }})</span>
                        </li>
                    </ul>
                </div>

                <div v-if="e.pasos_log?.length">
                    <p class="text-xs font-semibold text-tinta-500 uppercase tracking-[0.12em] mb-1.5">Paso a paso</p>
                    <ol class="divide-y divide-separador border border-linea rounded-xl">
                        <li v-for="p in e.pasos_log" :key="p.orden" class="p-2.5 text-xs">
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-semibold text-tinta-700">
                                    {{ p.orden }}. {{ TIPOS[p.tipo] ?? p.tipo }}<template v-if="p.herramienta"> · {{ p.herramienta }}</template>
                                </span>
                                <span v-if="p.ms" class="text-tinta-300">{{ p.ms }} ms</span>
                            </div>
                            <p v-if="p.argumentos && Object.keys(p.argumentos).length" class="text-tinta-400 mt-0.5 break-words">
                                {{ JSON.stringify(p.argumentos) }}
                            </p>
                            <p v-if="p.resumen" class="text-tinta-500 mt-0.5 break-words">{{ p.resumen }}</p>
                        </li>
                    </ol>
                </div>

                <p class="text-[11px] text-tinta-300">
                    Modelo: {{ e.modelo || '—' }} · {{ e.pasos }} pasos · {{ e.tokens_entrada + e.tokens_salida }} tokens
                    ({{ e.tokens_entrada }} de entrada, {{ e.tokens_salida }} de salida)
                </p>
            </template>
        </div>
    </div>
</template>
