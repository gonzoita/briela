<script setup>
/**
 * La cola de aprobación: lo que los agentes proponen y una persona decide.
 *
 * Un agente nunca escribe en el negocio. Al aprobar, el servidor vuelve a revisar el permiso, la
 * sede y el estado del documento —pasaron horas desde la propuesta— y hace lo que haría la
 * pantalla correspondiente, a nombre de quien aprobó.
 */
import { ref, watch, onMounted } from 'vue'
import { get, post, mensajeDe, hace } from '@/composables/useEquipoIa'

const props = defineProps({
    permisos: { type: Object, default: () => ({}) },
    // Sube cuando el pulso detecta un cambio: así la lista se refresca sola.
    version:  { type: Number, default: 0 },
})

const emit = defineEmits(['cambio'])

const filtro   = ref('pendiente')
const lista    = ref([])
const cargando = ref(false)
const aviso    = ref(null)        // { tipo: 'ok' | 'error', texto }
const ocupada  = ref(null)        // id de la propuesta en proceso
const rechazando = ref(null)      // id de la propuesta que se está rechazando
const motivo   = ref('')

async function cargar() {
    cargando.value = true
    const r = await get(`/api/equipo-ia/propuestas?estado=${filtro.value}`)
    cargando.value = false
    if (r.ok) lista.value = r.data
}

onMounted(cargar)
watch(filtro, cargar)
watch(() => props.version, cargar)

async function decidir(p, aprobar) {
    ocupada.value = p.id
    aviso.value = null

    const r = await post(`/api/equipo-ia/propuestas/${p.id}/${aprobar ? 'aprobar' : 'rechazar'}`, aprobar ? {} : { motivo: motivo.value })

    ocupada.value = null
    rechazando.value = null
    motivo.value = ''
    aviso.value = r.ok ? { tipo: 'ok', texto: r.data.mensaje } : { tipo: 'error', texto: mensajeDe(r) }

    await cargar()
    emit('cambio')
}

const FILTROS = [['pendiente', 'Por decidir'], ['aprobada', 'Aprobadas'], ['rechazada', 'Rechazadas'], ['vencida', 'Vencidas'], ['todas', 'Todas']]
const ETIQUETA = { pendiente: 'Por decidir', aprobada: 'Aprobada', rechazada: 'Rechazada', vencida: 'Vencida', fallida: 'No se pudo' }
const COLOR = {
    pendiente: 'bg-pastel-naranja-2 text-aviso-naranja', aprobada: 'bg-pastel-verde-2 text-aviso-verde',
    rechazada: 'bg-tinta-100 text-tinta-500', vencida: 'bg-tinta-100 text-tinta-400', fallida: 'bg-pastel-rojo-2 text-aviso-rojo',
}

/** El texto que escribió el agente (el mensaje borrador), sea cual sea la acción. */
const texto = (p) => p.detalle?.descripcion || p.detalle?.nota || ''
</script>

<template>
    <div class="space-y-3">
        <div class="flex gap-1.5 flex-wrap">
            <button v-for="[clave, label] in FILTROS" :key="clave" type="button" @click="filtro = clave"
                :class="['text-xs px-3 py-1.5 rounded-full border transition-colors',
                    filtro === clave ? 'border-[var(--marca)] bg-realce text-tinta-800 font-semibold' : 'border-linea text-tinta-500 hover:bg-realce']">
                {{ label }}
            </button>
        </div>

        <p v-if="aviso" :class="['text-sm rounded-xl border p-3',
            aviso.tipo === 'ok' ? 'border-borde-aviso-verde bg-pastel-verde text-aviso-verde' : 'border-borde-aviso-rojo bg-pastel-rojo text-aviso-rojo']">
            {{ aviso.texto }}
        </p>

        <p v-if="! lista.length" class="text-sm text-tinta-300 text-center py-10 border border-dashed border-linea rounded-2xl">
            {{ cargando ? 'Cargando…' : (filtro === 'pendiente' ? 'No hay nada por decidir.' : 'No hay propuestas en esta lista.') }}
        </p>

        <div v-for="p in lista" :key="p.id" class="bg-superficie border border-linea rounded-2xl p-4 space-y-2.5">
            <div class="flex items-start justify-between gap-3 flex-wrap">
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-tinta-800">
                        <span aria-hidden="true">{{ p.avatar || '🤖' }}</span> {{ p.agente }}
                        <span :class="['text-[10px] px-1.5 py-0.5 rounded-full ml-1 font-semibold', COLOR[p.estado]]">{{ ETIQUETA[p.estado] ?? p.estado }}</span>
                    </p>
                    <p class="text-xs text-tinta-400 mt-0.5">{{ p.etiqueta }} · {{ hace(p.creada) }}</p>
                </div>
                <a v-if="p.entidad_url" :href="p.entidad_url" class="text-xs text-tinta-500 underline underline-offset-2">Abrir el documento</a>
            </div>

            <p class="text-sm text-tinta-700">{{ p.resumen }}</p>

            <div v-if="texto(p)" class="text-sm text-tinta-600 whitespace-pre-line bg-realce rounded-xl p-3 leading-relaxed">{{ texto(p) }}</div>

            <p v-if="p.destinatario" class="text-[11px] text-tinta-400">Responsable del documento: {{ p.destinatario }}</p>
            <p v-if="p.estado === 'pendiente' && p.vence" :class="['text-[11px]', p.vencida ? 'text-aviso-rojo' : 'text-tinta-400']">
                {{ p.vencida ? 'Venció: ya no se puede aprobar.' : 'Vence ' + new Date(p.vence).toLocaleString('es-CO', { dateStyle: 'medium', timeStyle: 'short' }) }}
            </p>
            <p v-if="p.estado !== 'pendiente' && p.decidida_por" class="text-[11px] text-tinta-400">
                Decidió {{ p.decidida_por }}<template v-if="p.motivo"> — «{{ p.motivo }}»</template>
                <template v-if="p.resultado"> · {{ p.resultado }}</template>
            </p>

            <!-- Decidir -->
            <div v-if="p.estado === 'pendiente' && ! p.vencida && permisos.aprobar" class="pt-1">
                <p v-if="! p.puede" class="text-xs text-aviso-ambar">
                    Para aprobarla necesitas además el permiso <code>{{ p.permiso }}</code>.
                </p>

                <div v-else-if="rechazando === p.id" class="space-y-2">
                    <input v-model="motivo" type="text" maxlength="500" placeholder="Motivo (opcional): «Ya lo llamé yo»"
                        class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]" />
                    <div class="flex gap-2">
                        <button type="button" @click="rechazando = null" class="text-xs px-3 py-1.5 rounded-lg border border-linea text-tinta-500 hover:bg-realce">Cancelar</button>
                        <button type="button" @click="decidir(p, false)" :disabled="ocupada === p.id"
                            class="text-xs px-3 py-1.5 rounded-lg border border-borde-aviso-rojo text-aviso-rojo hover:bg-pastel-rojo disabled:opacity-50">
                            Confirmar rechazo
                        </button>
                    </div>
                </div>

                <div v-else class="flex gap-2 flex-wrap">
                    <button type="button" @click="decidir(p, true)" :disabled="ocupada === p.id"
                        class="text-xs px-4 py-1.5 rounded-lg text-white font-semibold disabled:opacity-50" style="background:var(--marca);">
                        {{ ocupada === p.id ? 'Aprobando…' : 'Aprobar' }}
                    </button>
                    <button type="button" @click="rechazando = p.id"
                        class="text-xs px-3 py-1.5 rounded-lg border border-linea text-tinta-500 hover:bg-realce">
                        Rechazar
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
