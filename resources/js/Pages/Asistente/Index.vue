<script setup>
import { computed, nextTick, onMounted, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { formatearMensaje } from '@/utils/formatoMensaje'
import { useVozAsistente } from '@/composables/useVozAsistente'

const props = defineProps({
    nombre:        { type: String,  default: 'Asistente' },
    tienePerfil:   { type: Boolean, default: false },
    iaConfigurada: { type: Boolean, default: false },
    // Consultas de datos que este usuario puede hacer, según sus permisos.
    temas:         { type: Array,   default: () => [] },
})

// La conversación se guarda en el servidor, por usuario. Sobrevive a recargas
// y es la misma que se ve en la burbuja flotante y desde el celular.
const mensajes  = ref([])
const entrada   = ref('')
const cargando  = ref(false)
const error     = ref('')
const scroller  = ref(null)

const {
    soportaDictado, soportaVoz, prefs,
    dictando, hablando, errorVoz,
    iniciarDictado, detenerDictado, hablar, detenerVoz,
} = useVozAsistente()

function alternarMicrofono() {
    if (dictando.value) {
        detenerDictado()
        return
    }

    iniciarDictado((texto, definitivo) => {
        entrada.value = texto
        if (definitivo && texto.trim()) enviar()
    })
}

// Se sugieren preguntas de datos solo si el usuario tiene esas consultas
// disponibles; si no, se queda con las de marca.
const sugerenciasDatos = {
    produccion_resumen:    '¿Cómo va la producción?',
    ventas_resumen:        '¿Cómo van las ventas?',
    productividad:         'Dame un informe de productividad',
    estado_op:             '¿Puedo entregar la OP 191?',
    inventario_bajo_stock: '¿Qué insumos están por debajo del mínimo?',
    cartera_pendiente:     '¿Cuánto tengo por cobrar?',
    ops_por_entregar:      '¿Qué entregas tengo próximas o vencidas?',
}

const sugerencias = computed(() => {
    const deDatos = props.temas
        .map(t => sugerenciasDatos[t])
        .filter(Boolean)
        .slice(0, 4)

    return [...deDatos, '¿Cuál es la promesa de la empresa?']
})

function getCookie(name) {
    const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'))
    return match ? decodeURIComponent(match[2]) : ''
}

async function bajar() {
    await nextTick()
    if (scroller.value) scroller.value.scrollTop = scroller.value.scrollHeight
}

// ─── Historial guardado ─────────────────────────────────────────────────────
onMounted(async () => {
    try {
        const resp = await fetch('/api/asistente/historial', {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
        })
        if (resp.ok) {
            mensajes.value = (await resp.json()).mensajes ?? []
            bajar()
        }
    } catch (e) {
        // Sin historial se puede conversar igual.
    }
})

async function limpiar() {
    if (!confirm('¿Borrar toda la conversación? No se puede recuperar.')) return

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

async function enviar(texto = null) {
    const pregunta = (texto ?? entrada.value).trim()
    if (!pregunta || cargando.value) return

    mensajes.value.push({ rol: 'usuario', contenido: pregunta })
    entrada.value = ''
    error.value   = ''
    cargando.value = true
    bajar()

    try {
        const resp = await fetch('/api/asistente', {
            method:  'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': getCookie('XSRF-TOKEN'),
                'Accept':       'application/json',
            },
            credentials: 'same-origin',
            // El historial ya no se manda: el servidor lo lee de la base, que
            // es la fuente real de la conversación.
            body: JSON.stringify({ mensaje: pregunta }),
        })

        const data = await resp.json()

        if (!resp.ok) {
            error.value = data.error ?? 'No se pudo obtener respuesta.'
            return
        }

        // Una respuesta vacía no se pinta como burbuja en blanco: se avisa.
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
    } catch (e) {
        error.value = 'No se pudo conectar con el asistente.'
    } finally {
        cargando.value = false
    }
}
</script>

<template>
    <AppLayout :title="nombre">
        <div class="max-w-2xl mx-auto flex flex-col" style="height: calc(100vh - 190px);">

            <!-- Avisos de configuración -->
            <div v-if="!iaConfigurada" class="bg-amber-50 border border-amber-200 rounded-xl p-4 mb-3">
                <p class="text-sm text-amber-800 font-medium">La IA todavía no está configurada.</p>
                <p class="text-xs text-amber-700 mt-1">
                    Falta la credencial de OpenRouter en el servidor. Mientras tanto el asistente no puede responder.
                </p>
            </div>
            <div v-else-if="!tienePerfil" class="bg-amber-50 border border-amber-200 rounded-xl p-4 mb-3">
                <p class="text-sm text-amber-800 font-medium">El perfil de marca está vacío.</p>
                <a href="/configuracion/perfil-marca" @click.prevent="router.visit('/configuracion/perfil-marca')"
                    class="text-xs font-semibold text-amber-900 underline">Llenarlo ahora →</a>
            </div>

            <!-- Barra de la conversación -->
            <div v-if="mensajes.length" class="flex items-center justify-between mb-2">
                <p class="text-xs text-gray-400">Conversación guardada</p>
                <button type="button" @click="limpiar"
                    class="text-xs font-medium text-gray-500 hover:text-red-600">
                    Borrar conversación
                </button>
            </div>

            <!-- Conversación -->
            <div ref="scroller" class="flex-1 overflow-y-auto space-y-3 pb-3">
                <!-- Estado inicial -->
                <div v-if="!mensajes.length" class="text-center py-8">
                    <div class="w-14 h-14 rounded-2xl mx-auto flex items-center justify-center mb-3"
                        style="background:var(--marca);">
                        <svg class="w-7 h-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-6l-4 4v-4z" />
                        </svg>
                    </div>
                    <p class="text-sm font-semibold text-gray-800">Hola, soy {{ nombre }}</p>
                    <p class="text-xs text-gray-400 mt-1 mb-4 px-6">
                        Respondo sobre la marca y consulto los datos del sistema —ventas, producción,
                        inventario, cartera— dentro de tu sede activa y de lo que tus permisos te dejan ver.
                    </p>
                    <div class="flex flex-wrap gap-2 justify-center px-4">
                        <button v-for="s in sugerencias" :key="s" @click="enviar(s)"
                            class="text-xs px-3 py-1.5 rounded-full border border-gray-200 text-gray-600 hover:bg-gray-50">
                            {{ s }}
                        </button>
                    </div>
                </div>

                <!-- Mensajes -->
                <div v-for="(m, i) in mensajes" :key="i"
                    class="flex" :class="m.rol === 'usuario' ? 'justify-end' : 'justify-start'">
                    <div class="max-w-[85%] rounded-2xl px-4 py-2.5 text-sm whitespace-pre-wrap"
                        :class="m.rol === 'usuario'
                            ? 'text-white rounded-br-sm'
                            : 'bg-white border border-gray-200 text-gray-800 rounded-bl-sm'"
                        :style="m.rol === 'usuario' ? 'background:var(--marca);' : ''">
                        <span v-if="m.rol === 'asistente'" v-html="formatearMensaje(m.contenido)" />
                        <span v-else class="whitespace-pre-wrap">{{ m.contenido }}</span>

                        <!-- De dónde salieron las cifras, para poder verificarlas -->
                        <span v-if="m.tiempos && m.tiempos.total > 4000"
                            class="block mt-2 text-[11px] text-gray-400">
                            {{ (m.tiempos.total / 1000).toFixed(1) }} s
                            <span class="opacity-70">
                                (decidir {{ (m.tiempos.decision / 1000).toFixed(1) }} s ·
                                consultar {{ (m.tiempos.consultas / 1000).toFixed(1) }} s ·
                                redactar {{ (m.tiempos.redaccion / 1000).toFixed(1) }} s)
                            </span>
                        </span>

                        <span v-if="m.consulta" class="block mt-2 text-[11px] text-gray-400">
                            Datos de: {{ m.consulta.replace(/_/g, ' ') }}
                        </span>

                        <button v-if="m.rol === 'asistente' && soportaVoz"
                            @click="hablando ? detenerVoz() : hablar(m.contenido)"
                            class="mt-2 text-[11px] text-gray-400 hover:text-gray-600">
                            {{ hablando ? 'Detener' : 'Escuchar' }}
                        </button>
                    </div>
                </div>

                <div v-if="cargando" class="flex justify-start">
                    <div class="bg-white border border-gray-200 rounded-2xl rounded-bl-sm px-4 py-2.5">
                        <span class="text-sm text-gray-400">{{ nombre }} está escribiendo…</span>
                    </div>
                </div>

                <p v-if="error" class="text-xs text-red-600 text-center">{{ error }}</p>
            </div>

            <!-- Entrada -->
            <div class="pt-2 border-t border-gray-100">
                <div class="flex items-center justify-between mb-2">
                    <p v-if="dictando" class="text-xs" style="color:var(--marca);">
                        Escuchando… habla y se envía sola al terminar.
                    </p>
                    <p v-else-if="errorVoz" class="text-xs text-red-600">{{ errorVoz }}</p>
                    <span v-else />

                    <label v-if="soportaVoz" class="flex items-center gap-1.5 cursor-pointer shrink-0">
                        <input v-model="prefs.leerAuto" type="checkbox" class="rounded"
                            @change="!prefs.leerAuto && detenerVoz()" />
                        <span class="text-xs text-gray-500">Leer respuestas en voz alta</span>
                    </label>
                </div>

                <div class="flex items-end gap-2">
                    <textarea v-model="entrada" rows="1" maxlength="2000"
                        placeholder="Escribe tu pregunta…"
                        @keydown.enter.exact.prevent="enviar()"
                        class="flex-1 rounded-xl border border-gray-300 px-3 py-2.5 text-sm resize-none focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>

                    <button v-if="soportaDictado" @click="alternarMicrofono" :disabled="cargando"
                        class="w-11 h-11 rounded-xl flex items-center justify-center shrink-0 border disabled:opacity-40"
                        :class="dictando ? 'border-transparent text-white' : 'border-gray-300 text-gray-500'"
                        :style="dictando ? 'background:#EF4444;' : ''"
                        :title="dictando ? 'Detener' : 'Dictar'">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M19 11a7 7 0 01-14 0m7 7v4m0-4a3 3 0 01-3-3V6a3 3 0 016 0v9a3 3 0 01-3 3z" />
                        </svg>
                    </button>

                    <button @click="enviar()" :disabled="cargando || !entrada.trim()"
                        class="w-11 h-11 rounded-xl flex items-center justify-center text-white shrink-0 disabled:opacity-40"
                        style="background:var(--marca);">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                        </svg>
                    </button>
                </div>
            </div>

        </div>
    </AppLayout>
</template>
