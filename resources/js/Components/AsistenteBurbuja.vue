<script setup>
/**
 * Burbuja del asistente de IA, siempre disponible sobre cualquier pantalla.
 *
 * Vive dentro de AppLayout, así que la conversación NO se pierde al navegar
 * entre módulos — solo al recargar la página. Eso permite preguntarle algo
 * mientras se mira una OP sin perder el hilo.
 *
 * Posición: abajo a la derecha. En computador la izquierda la ocupa el menú
 * lateral, y en celular la esquina inferior izquierda es el botón de Inicio.
 */
import { computed, nextTick, onMounted, ref } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { formatearMensaje } from '@/utils/formatoMensaje'
import { useVozAsistente } from '@/composables/useVozAsistente'

const page = usePage()

const {
    soportaDictado, soportaVoz, prefs,
    dictando, hablando, errorVoz,
    iniciarDictado, detenerDictado, hablar, detenerVoz,
} = useVozAsistente()

const ajustesAbiertos = ref(false)

function alternarMicrofono() {
    if (dictando.value) {
        detenerDictado()
        return
    }

    iniciarDictado((texto, definitivo) => {
        entrada.value = texto
        // Al terminar de hablar se envía solo, como en una nota de voz.
        if (definitivo && texto.trim()) enviar()
    })
}

const nombre = computed(() => page.props.asistente?.nombre || 'Asistente')

const abierto  = ref(false)
const mensajes = ref([])
const entrada  = ref('')
const cargando = ref(false)
const error    = ref('')
const scroller = ref(null)

const sugerencias = [
    '¿Cómo va la producción?',
    '¿Cómo van las ventas?',
    'Dame un informe de productividad',
]

function getCookie(name) {
    const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'))
    return match ? decodeURIComponent(match[2]) : ''
}

async function bajar() {
    await nextTick()
    if (scroller.value) scroller.value.scrollTop = scroller.value.scrollHeight
}

// ─── Historial ──────────────────────────────────────────────────────────────
// La conversación se guarda en el servidor, no en el navegador: sobrevive a
// recargas, a cerrar el computador y se ve igual desde el celular.
const cargandoHistorial = ref(true)

onMounted(async () => {
    try {
        const resp = await fetch('/api/asistente/historial', {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
        })
        if (resp.ok) {
            const data = await resp.json()
            mensajes.value = data.mensajes ?? []
        }
    } catch (e) {
        // Sin historial se puede seguir conversando: no vale la pena molestar.
    } finally {
        cargandoHistorial.value = false
    }
})

function alternar() {
    abierto.value = !abierto.value
    if (abierto.value) bajar()
}

/**
 * Abre el chat con una pregunta ya escrita y la envía.
 * La usa el buscador global cuando no encuentra resultados.
 */
function abrirCon(texto = '') {
    abierto.value = true
    bajar()

    if (texto.trim()) enviar(texto)
}

// `abrir` lo usa el lanzador flotante, que ahora es un solo botón compartido
// con el chat: dos círculos fijos tapaban el contenido en celular.
defineExpose({ abrirCon, abrir: () => { abierto.value = true; bajar() } })

async function enviar(texto = null) {
    const pregunta = (texto ?? entrada.value).trim()
    if (!pregunta || cargando.value) return

    mensajes.value.push({ rol: 'usuario', contenido: pregunta })
    entrada.value  = ''
    error.value    = ''
    cargando.value = true
    bajar()

    try {
        // Primero se intenta en vivo. Si el servidor no lo soporta (algunos
        // hostings guardan la respuesta y la mandan junta), se cae al modo
        // normal y el usuario ni se entera.
        const funciono = await enviarEnVivo(pregunta)

        if (!funciono) await enviarDeUnaVez(pregunta)
    } catch (e) {
        error.value = 'No se pudo conectar con el asistente.'
    } finally {
        cargando.value = false
    }
}

/**
 * Respuesta palabra por palabra.
 * @returns {boolean} si alcanzó a escribir algo; false para usar el respaldo.
 */
async function enviarEnVivo(pregunta) {
    const resp = await fetch('/api/asistente/stream', {
        method:  'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': getCookie('XSRF-TOKEN'),
            'Accept':       'text/event-stream',
        },
        credentials: 'same-origin',
        body: JSON.stringify({ mensaje: pregunta }),
    })

    if (!resp.ok || !resp.body) return false

    const lector  = resp.body.getReader()
    const decoder = new TextDecoder()

    let buffer   = ''
    let burbuja  = null   // el mensaje que se va llenando en pantalla
    let completo = ''

    while (true) {
        const { done, value } = await lector.read()
        if (done) break

        buffer += decoder.decode(value, { stream: true })

        // Los eventos vienen separados por una línea en blanco.
        let corte
        while ((corte = buffer.indexOf('\n\n')) !== -1) {
            const bloque = buffer.slice(0, corte)
            buffer = buffer.slice(corte + 2)

            const evento = bloque.match(/^event: (.+)$/m)?.[1]
            const datos  = bloque.match(/^data: (.+)$/m)?.[1]
            if (!evento || !datos) continue

            let carga
            try { carga = JSON.parse(datos) } catch { continue }

            if (evento === 'inicio') {
                burbuja = { rol: 'asistente', contenido: '', consulta: carga.consulta || null, escribiendo: true }
                mensajes.value.push(burbuja)
                bajar()
            } else if (evento === 'trozo' && burbuja) {
                completo += carga.t
                burbuja.contenido = completo
                bajar()
            } else if (evento === 'fin') {
                if (burbuja) {
                    burbuja.contenido   = carga.respuesta
                    burbuja.consulta    = carga.consulta || null
                    burbuja.tiempos     = carga.tiempos || null
                    burbuja.escribiendo = false
                }
                bajar()
                // La voz necesita el texto completo, así que se lee al final.
                if (prefs.leerAuto && carga.respuesta) hablar(carga.respuesta)
                return true
            } else if (evento === 'error') {
                // Se quita la burbuja a medio escribir antes de mostrar el error.
                if (burbuja) mensajes.value = mensajes.value.filter(m => m !== burbuja)
                error.value = carga.mensaje || 'No se pudo obtener respuesta.'
                return true
            }
        }
    }

    // El stream terminó sin evento 'fin': algo lo cortó.
    if (burbuja) mensajes.value = mensajes.value.filter(m => m !== burbuja)

    return false
}

/** Respaldo: la respuesta completa de una sola vez. */
async function enviarDeUnaVez(pregunta) {
    const resp = await fetch('/api/asistente', {
        method:  'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': getCookie('XSRF-TOKEN'),
            'Accept':       'application/json',
        },
        credentials: 'same-origin',
        // El historial no se manda: el servidor lo lee de la base, que es la
        // fuente real de la conversación.
        body: JSON.stringify({ mensaje: pregunta }),
    })

    const data = await resp.json()

    if (!resp.ok) {
        error.value = data.error ?? 'No se pudo obtener respuesta.'
        return
    }

    if (!data.respuesta || !data.respuesta.trim()) {
        error.value = 'El modelo respondió sin texto. Revisa el modelo de texto en Configuración.'
        return
    }

    mensajes.value.push({
        rol: 'asistente',
        contenido: data.respuesta,
        consulta: data.consulta || null,
        tiempos: data.tiempos || null,
    })
    bajar()

    if (prefs.leerAuto) hablar(data.respuesta)
}

async function limpiar() {
    if (!confirm('¿Borrar toda la conversación? No se puede recuperar.')) return

    // Se vacía primero para que responda al instante; si el servidor falla,
    // el historial vuelve solo al recargar.
    mensajes.value = []
    error.value = ''

    try {
        await fetch('/api/asistente/historial', {
            method:  'DELETE',
            headers: { 'X-XSRF-TOKEN': getCookie('XSRF-TOKEN'), 'Accept': 'application/json' },
            credentials: 'same-origin',
        })
    } catch (e) {
        error.value = 'La conversación se borró en pantalla, pero no en el servidor.'
    }
}
</script>

<template>
    <!-- El botón flotante ya no vive aquí: lo dibuja BotonesFlotantes, que
         junta la IA y el chat en un solo lanzador para no tapar el contenido. -->

    <!-- Panel de chat -->
    <Teleport to="body">
        <div v-if="abierto" class="fixed inset-0 z-50 sm:bg-transparent" style="background:rgba(0,0,0,0.35);"
            @click.self="abierto = false">
            <div
                class="absolute bg-superficie shadow-2xl flex flex-col
                       inset-x-0 bottom-0 rounded-t-2xl
                       sm:inset-auto sm:right-5 sm:bottom-5 sm:w-96 sm:rounded-2xl sm:border sm:border-linea"
                style="height: 78vh; max-height: 620px;"
            >
                <!-- Cabecera -->
                <div class="flex items-center gap-3 px-4 py-3 rounded-t-2xl shrink-0" style="background:var(--marca);">
                    <div class="w-8 h-8 rounded-full bg-white/20 flex items-center justify-center">
                        <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-6l-4 4v-4z" />
                        </svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-white text-sm font-semibold truncate">{{ nombre }}</p>
                        <p class="text-blue-200 text-xs">Asistente del sistema de origen</p>
                    </div>
                    <!-- Ajustes de voz -->
                    <button v-if="soportaVoz" @click="ajustesAbiertos = !ajustesAbiertos"
                        class="w-8 h-8 rounded-lg flex items-center justify-center"
                        :style="ajustesAbiertos ? 'background:rgba(255,255,255,0.3);' : ''"
                        title="Ajustes de voz">
                        <svg class="w-4 h-4" :class="ajustesAbiertos ? 'text-white' : 'text-blue-200'"
                            fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                    </button>
                    <button v-if="mensajes.length" @click="limpiar"
                        class="text-blue-200 hover:text-white text-xs px-2 py-1">Limpiar</button>
                    <button @click="abierto = false"
                        class="w-8 h-8 rounded-lg flex items-center justify-center" style="background:rgba(255,255,255,0.15);">
                        <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <!-- Preferencia personal de escucha -->
                <div v-if="ajustesAbiertos" class="px-4 py-3 border-b border-linea bg-superficie shrink-0 space-y-2">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input v-model="prefs.leerAuto" type="checkbox" class="rounded"
                            @change="!prefs.leerAuto && detenerVoz()" />
                        <span class="text-sm text-tinta-700">Que me lea las respuestas en voz alta</span>
                    </label>
                    <p class="text-xs text-tinta-300">
                        Es tu preferencia: no afecta a los demás. La voz de {{ nombre }} se define en
                        Configuración → Perfil de marca y asistente.
                    </p>
                </div>

                <!-- Conversación -->
                <div ref="scroller" class="flex-1 overflow-y-auto p-3 space-y-2.5" style="background:var(--superficie-2);">
                    <div v-if="!mensajes.length" class="py-6 text-center">
                        <p class="text-sm font-medium text-tinta-700">Hola, soy {{ nombre }}</p>
                        <p class="text-xs text-tinta-300 mt-1 mb-3 px-4">
                            Pregúntame por la operación o por la marca. Solo veo lo que tus permisos y tu sede permiten.
                        </p>
                        <div class="flex flex-col gap-1.5 px-4">
                            <button v-for="s in sugerencias" :key="s" @click="enviar(s)"
                                class="text-xs px-3 py-2 rounded-xl border border-linea bg-superficie text-tinta-500 hover:border-borde-aviso-azul text-left">
                                {{ s }}
                            </button>
                        </div>
                    </div>

                    <div v-for="(m, i) in mensajes" :key="i"
                        class="flex" :class="m.rol === 'usuario' ? 'justify-end' : 'justify-start'">
                        <div class="max-w-[85%] rounded-2xl px-3 py-2 text-sm whitespace-pre-wrap"
                            :class="m.rol === 'usuario'
                                ? 'text-white rounded-br-sm'
                                : 'bg-superficie border border-linea text-tinta-900 rounded-bl-sm'"
                            :style="m.rol === 'usuario' ? 'background:var(--marca);' : ''">
                            <!-- Las respuestas vienen en Markdown; se convierten a HTML
                                 seguro (el texto se escapa antes de dar formato). -->
                            <span v-if="m.rol === 'asistente'" v-html="formatearMensaje(m.contenido)" />
                            <span v-else class="whitespace-pre-wrap">{{ m.contenido }}</span>

                            <!-- Cursor mientras escribe, para que se note que
                                 sigue trabajando y no que se quedó a medias. -->
                            <span v-if="m.escribiendo"
                                class="inline-block w-1.5 h-4 align-middle ml-0.5 animate-pulse"
                                style="background: var(--marca);"></span>

                            <span v-if="m.consulta && !m.escribiendo" class="block mt-1.5 text-[11px] text-tinta-300">
                                Datos de: {{ m.consulta.replace(/_/g, ' ') }}
                            </span>

                            <!-- Desglose del tiempo. Solo si la respuesta se
                                 demoró: cuando va rápido nadie quiere verlo. -->
                            <span v-if="m.tiempos && m.tiempos.total > 4000"
                                class="block mt-1 text-[11px] text-tinta-300"
                                :title="`Decidir qué consultar: ${m.tiempos.decision} ms · Consultar la base: ${m.tiempos.consultas} ms · Redactar: ${m.tiempos.redaccion} ms`">
                                {{ (m.tiempos.total / 1000).toFixed(1) }} s
                                <span class="opacity-70">
                                    (decidir {{ (m.tiempos.decision / 1000).toFixed(1) }} s ·
                                    redactar {{ (m.tiempos.redaccion / 1000).toFixed(1) }} s)
                                </span>
                            </span>

                            <button v-if="m.rol === 'asistente' && soportaVoz && !m.escribiendo"
                                @click="hablando ? detenerVoz() : hablar(m.contenido)"
                                class="mt-1.5 text-[11px] text-tinta-300 hover:text-tinta-500">
                                {{ hablando ? 'Detener' : 'Escuchar' }}
                            </button>
                        </div>
                    </div>

                    <!-- Solo mientras no haya empezado a escribir: una vez sale
                         texto, el aviso sobra y estorba. -->
                    <div v-if="cargando && !mensajes.some(m => m.escribiendo)" class="flex justify-start">
                        <div class="bg-superficie border border-linea rounded-2xl rounded-bl-sm px-3 py-2">
                            <span class="text-sm text-tinta-300">{{ nombre }} está escribiendo…</span>
                        </div>
                    </div>

                    <p v-if="error" class="text-xs text-aviso-rojo text-center px-3">{{ error }}</p>
                </div>

                <!-- Entrada -->
                <div class="p-3 border-t border-linea shrink-0 bg-superficie rounded-b-2xl">
                    <p v-if="dictando" class="text-xs text-center mb-2" style="color:var(--marca);">
                        Escuchando… habla y se envía sola al terminar.
                    </p>
                    <p v-else-if="errorVoz" class="text-xs text-aviso-rojo text-center mb-2">{{ errorVoz }}</p>

                    <div class="flex items-end gap-2">
                        <textarea v-model="entrada" rows="1" maxlength="2000"
                            placeholder="Escribe tu pregunta…"
                            @keydown.enter.exact.prevent="enviar()"
                            class="flex-1 rounded-xl border border-tinta-200 px-3 py-2 text-sm resize-none focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]"></textarea>

                        <!-- Dictado por voz -->
                        <button v-if="soportaDictado" @click="alternarMicrofono" :disabled="cargando"
                            class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0 border disabled:opacity-40"
                            :class="dictando ? 'border-transparent text-white' : 'border-tinta-200 text-tinta-400'"
                            :style="dictando ? 'background:#EF4444;' : ''"
                            :title="dictando ? 'Detener' : 'Dictar'">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M19 11a7 7 0 01-14 0m7 7v4m0-4a3 3 0 01-3-3V6a3 3 0 016 0v9a3 3 0 01-3 3z" />
                            </svg>
                        </button>

                        <button @click="enviar()" :disabled="cargando || !entrada.trim()"
                            class="w-10 h-10 rounded-xl flex items-center justify-center text-white shrink-0 disabled:opacity-40"
                            style="background:var(--marca);">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
/* Mismas animaciones que el botón del chat, para que el par se sienta uno solo. */
.burbuja-ia {
    animation: entrar-ia 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
    transition: transform 0.18s ease, box-shadow 0.18s ease;
}
.burbuja-ia:hover  { transform: scale(1.08) rotate(-6deg); box-shadow: 0 10px 25px rgba(0,0,0,0.25); }
.burbuja-ia:active { transform: scale(0.94); }

@keyframes entrar-ia { from { opacity: 0; transform: scale(0.5) translateY(10px); } to { opacity: 1; transform: scale(1); } }

@media (prefers-reduced-motion: reduce) {
    .burbuja-ia { animation: none; transition: none; }
    .burbuja-ia:hover { transform: none; }
}
</style>
