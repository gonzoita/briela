<script setup>
import { ref, computed, onMounted } from 'vue'

/**
 * Hilo interno pegado a un documento (una OP, una cotización, un cliente).
 *
 * No pretende reemplazar a WhatsApp para lo urgente: sirve para que la
 * discusión sobre un documento viva DENTRO del documento y deje rastro.
 */
const props = defineProps({
    documento: { type: String, required: true },  // op | cotizacion | cliente | orden_compra
    id:        { type: [Number, String], required: true },
    // Dentro del panel flotante sobra el marco y el título: el panel ya dice
    // "Chat del equipo" y de qué documento se trata.
    embebido:  { type: Boolean, default: false },
})

const comentarios = ref([])
const usuarios    = ref([])
const cargando    = ref(true)
const enviando    = ref(false)
const error       = ref('')

const nuevo = ref({ contenido: '', tipo: 'comentario', asignado_a: '', fecha_limite: '' })

const abiertos = computed(() =>
    comentarios.value.filter(c => c.tipo !== 'comentario' && c.estado === 'pendiente').length
)

const csrf = () => {
    const c = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))
    return c ? decodeURIComponent(c.split('=')[1]) : ''
}

async function pedir(url, opciones = {}) {
    const res = await fetch(url, {
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': csrf(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        ...opciones,
    })
    if (!res.ok) throw new Error((await res.json().catch(() => ({}))).message || 'Error inesperado')
    return res.json()
}

async function cargar() {
    cargando.value = true
    try {
        const d = await pedir(`/api/comentarios/${props.documento}/${props.id}`)
        comentarios.value = d.comentarios
        usuarios.value    = d.usuarios
    } catch (e) { error.value = e.message }
    finally { cargando.value = false }
}

async function enviar() {
    if (!nuevo.value.contenido.trim()) return
    enviando.value = true
    error.value = ''
    try {
        const d = await pedir(`/api/comentarios/${props.documento}/${props.id}`, {
            method: 'POST',
            body: JSON.stringify({
                contenido:    nuevo.value.contenido,
                tipo:         nuevo.value.tipo,
                asignado_a:   nuevo.value.asignado_a || null,
                fecha_limite: nuevo.value.fecha_limite || null,
            }),
        })
        comentarios.value.push(d.comentario)
        nuevo.value = { contenido: '', tipo: 'comentario', asignado_a: '', fecha_limite: '' }
    } catch (e) { error.value = e.message }
    finally { enviando.value = false }
}

async function resolver(c, estado) {
    try {
        const d = await pedir(`/api/comentarios/${c.id}`, {
            method: 'PATCH',
            body: JSON.stringify({ estado }),
        })
        Object.assign(c, d.comentario)
    } catch (e) { error.value = e.message }
}

// No hay función de borrar a propósito: los mensajes quedan como evidencia.
// Ver ComentarioController::destroy.

const fecha = (v) => new Date(v).toLocaleString('es-CO', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })

const etiqueta = { comentario: 'Comentario', solicitud: 'Solicitud', tarea: 'Tarea' }

onMounted(cargar)
</script>

<template>
    <div :class="embebido ? '' : 'bg-white rounded-2xl border border-linea overflow-hidden'">
        <div v-if="!embebido" class="px-5 py-3 border-b border-linea flex items-center gap-2 flex-wrap">
            <h2 class="text-sm font-semibold text-tinta-700">Hilo interno</h2>
            <span v-if="abiertos" class="text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-700 leading-none">
                {{ abiertos }} sin resolver
            </span>
            <span class="text-xs text-tinta-300 ml-auto">Solo lo ve el equipo, nunca el cliente.</span>
        </div>

        <div :class="embebido ? 'space-y-4' : 'px-5 py-4 space-y-4'">
            <p v-if="cargando" class="text-xs text-tinta-300">Cargando...</p>
            <p v-else-if="!comentarios.length" class="text-xs text-tinta-300">
                Todavía no hay nada. Escribe abajo para dejar el primer mensaje.
            </p>

            <!-- Mensajes -->
            <div v-for="c in comentarios" :key="c.id"
                class="rounded-xl border p-3"
                :class="c.tipo !== 'comentario' && c.estado === 'pendiente'
                    ? 'border-amber-200 bg-amber-50/40' : 'border-linea'">
                <div class="flex items-center gap-2 flex-wrap mb-1">
                    <span class="text-xs font-semibold text-tinta-700">{{ c.autor?.name }}</span>
                    <span v-if="c.tipo !== 'comentario'"
                        class="text-[10px] font-semibold px-1.5 py-0.5 rounded-full leading-none"
                        :class="c.estado === 'pendiente' ? 'bg-amber-100 text-amber-700'
                              : c.estado === 'resuelta' ? 'bg-green-100 text-green-700' : 'bg-tinta-200 text-tinta-500'">
                        {{ etiqueta[c.tipo] }}<template v-if="c.estado !== 'pendiente'"> · {{ c.estado }}</template>
                    </span>
                    <span v-if="c.asignado" class="text-[11px] text-tinta-400">para {{ c.asignado.name }}</span>
                    <span v-if="c.fecha_limite" class="text-[11px] text-tinta-400">· antes del {{ c.fecha_limite }}</span>
                    <span class="text-[11px] text-tinta-300 ml-auto">{{ fecha(c.created_at) }}</span>
                </div>

                <p class="text-sm text-tinta-700 whitespace-pre-line">{{ c.contenido }}</p>

                <div class="flex items-center gap-2 mt-2 flex-wrap">
                    <template v-if="c.tipo !== 'comentario' && c.estado === 'pendiente'">
                        <button @click="resolver(c, 'resuelta')"
                            class="px-2 py-1 rounded-lg text-[11px] font-semibold text-green-700 border border-green-200 hover:bg-green-50">
                            Marcar resuelta
                        </button>
                        <button @click="resolver(c, 'rechazada')"
                            class="px-2 py-1 rounded-lg text-[11px] font-semibold text-tinta-500 border border-linea hover:bg-tinta-50">
                            Rechazar
                        </button>
                    </template>
                    <span v-else-if="c.resuelto_por" class="text-[11px] text-tinta-300">
                        Cerrada por {{ c.resuelto_por.name }}
                    </span>
                </div>
            </div>

            <!-- Nuevo mensaje -->
            <div class="border-t border-linea pt-4 space-y-2">
                <textarea v-model="nuevo.contenido" rows="3"
                    placeholder="Escribe aquí. Menciona a alguien con @ y le llega el aviso."
                    class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-blue-400"></textarea>

                <div class="flex flex-wrap gap-2 items-center">
                    <select v-model="nuevo.tipo"
                        class="border border-linea rounded-lg px-2 py-1.5 text-xs focus:outline-none">
                        <option value="comentario">Comentario</option>
                        <option value="solicitud">Solicitud</option>
                        <option value="tarea">Tarea</option>
                    </select>

                    <template v-if="nuevo.tipo !== 'comentario'">
                        <select v-model="nuevo.asignado_a"
                            class="border border-linea rounded-lg px-2 py-1.5 text-xs focus:outline-none">
                            <option value="">¿Para quién?</option>
                            <option v-for="u in usuarios" :key="u.id" :value="u.id">{{ u.name }}</option>
                        </select>
                        <input v-model="nuevo.fecha_limite" type="date"
                            class="border border-linea rounded-lg px-2 py-1.5 text-xs focus:outline-none" />
                    </template>

                    <button @click="enviar" :disabled="enviando || !nuevo.contenido.trim()"
                        class="ml-auto px-4 py-1.5 rounded-lg text-xs font-semibold text-white disabled:opacity-50"
                        style="background:var(--marca);">
                        {{ enviando ? 'Enviando...' : 'Enviar' }}
                    </button>
                </div>

                <p v-if="error" class="text-xs text-red-600">{{ error }}</p>
            </div>
        </div>
    </div>
</template>
