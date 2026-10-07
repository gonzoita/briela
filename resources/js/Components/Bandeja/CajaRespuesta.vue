<script setup>
import { computed, ref, watch } from 'vue'
import IconoMenu from '@/Components/IconoMenu.vue'

/**
 * Donde se escribe la respuesta, y donde se explica cuándo no se puede.
 *
 * **La ventana de 24 horas es la mitad de este componente.** Meta solo deja escribir texto
 * libre dentro de las 24 horas siguientes al último mensaje de la persona. Si la caja estuviera
 * siempre disponible, quien atiende escribiría, le saldría «enviado», y el cliente no recibiría
 * nada: el error de Meta no dice «se te venció el plazo». Así que cuando el plazo se cerró la
 * caja se reemplaza por lo que sí se puede hacer —una plantilla aprobada—, o por el motivo de
 * que no se pueda hacer nada, según el canal.
 */
const props = defineProps({
    ventana:    { type: Object, default: () => ({ abierta: true, plantillas: false }) },
    plantillas: { type: Array,  default: () => [] },
    adjuntos:   { type: Boolean, default: true },
    enviando:   { type: Boolean, default: false },
    puede:      { type: Boolean, default: true },
})

const emit = defineEmits(['enviar', 'enviar-plantilla'])

const texto    = ref('')
const archivo  = ref(null)
const entrada  = ref(null)

// ── Plantillas ───────────────────────────────────────────────────────────────
const abriendoPlantilla = ref(false)
const plantillaId       = ref(null)
const variables         = ref([])

const plantillaElegida = computed(() =>
    props.plantillas.find(p => p.id === plantillaId.value) ?? null
)

/** La vista previa con lo que se va a mandar de verdad. Lo que falte se queda como {{n}}. */
const previsualizacion = computed(() => {
    if (! plantillaElegida.value) return ''

    // Se llama `armado` y no `texto` para no tapar el ref de la caja de escribir: la misma
    // palabra para dos cosas distintas en el mismo archivo es un error esperando.
    let armado = plantillaElegida.value.texto

    variables.value.forEach((valor, i) => {
        if (valor) armado = armado.replaceAll(`{{${i + 1}}}`, valor)
    })

    return armado
})

const faltanVariables = computed(() =>
    plantillaElegida.value
        ? variables.value.slice(0, plantillaElegida.value.variables).some(v => ! String(v ?? '').trim())
        : false
)

// Al cambiar de plantilla, los huecos se vacían: dejar los valores de la anterior hace que se
// manden datos de otra conversación sin que nadie lo note.
watch(plantillaId, () => {
    variables.value = Array(plantillaElegida.value?.variables ?? 0).fill('')
})

function elegirArchivo(evento) {
    archivo.value = evento.target.files?.[0] ?? null
}

function quitarArchivo() {
    archivo.value = null
    if (entrada.value) entrada.value.value = ''
}

function enviar() {
    const limpio = texto.value.trim()

    if ((! limpio && ! archivo.value) || props.enviando) return

    emit('enviar', { texto: limpio, archivo: archivo.value })
}

/**
 * Lo escrito solo se borra cuando el envío salió.
 *
 * Lo decide el padre llamando a `limpiar()`: si se borrara al tocar «enviar», un error de Meta
 * —la ventana, el token, la línea desactivada— se llevaría el mensaje que la persona acababa
 * de escribir.
 */
function limpiar() {
    texto.value = ''
    quitarArchivo()
}

function enviarPlantilla() {
    if (! plantillaElegida.value || faltanVariables.value || props.enviando) return

    emit('enviar-plantilla', {
        plantilla_id: plantillaElegida.value.id,
        variables: variables.value.slice(0, plantillaElegida.value.variables),
    })
}

function cerrarPlantilla() {
    abriendoPlantilla.value = false
    plantillaId.value = null
}

defineExpose({ limpiar, cerrarPlantilla })

/**
 * Enter manda, Shift+Enter hace salto de línea.
 *
 * En celular no: ahí el Enter del teclado es el salto de línea de siempre, y mandar el mensaje
 * a medias porque alguien quiso separar dos frases es un mensaje que ya no se puede recoger.
 */
function alTeclear(evento) {
    const esCelular = window.matchMedia('(max-width: 767px)').matches

    if (evento.key === 'Enter' && ! evento.shiftKey && ! esCelular) {
        evento.preventDefault()
        enviar()
    }
}
</script>

<template>
    <div class="border-t border-linea bg-superficie px-3 md:px-4 py-3">

        <!-- Sin permiso para contestar: se ve el hilo y no se escribe. -->
        <p v-if="! puede" class="text-xs text-tinta-400 text-center py-2">
            Puedes leer esta conversación, pero no tienes permiso para responder.
        </p>

        <!-- ── La ventana está abierta: se escribe normal ───────────────────── -->
        <template v-else-if="ventana.abierta">
            <div v-if="archivo"
                class="flex items-center gap-2 mb-2 px-2.5 py-2 rounded-xl bg-pastel-azul border border-borde-aviso-azul text-aviso-azul">
                <IconoMenu nombre="paperclip" clase="text-sm w-4" />
                <span class="flex-1 min-w-0 truncate text-xs font-medium">{{ archivo.name }}</span>
                <button type="button" @click="quitarArchivo" class="shrink-0" title="Quitar el archivo">
                    <IconoMenu nombre="xmark" clase="text-sm w-4" />
                </button>
            </div>

            <div class="flex items-end gap-2">
                <label v-if="adjuntos"
                    class="shrink-0 w-10 h-10 rounded-xl border border-linea text-tinta-400 hover:bg-realce
                           flex items-center justify-center cursor-pointer"
                    title="Adjuntar un archivo">
                    <IconoMenu nombre="paperclip" clase="text-sm w-4" />
                    <input ref="entrada" type="file" class="hidden" @change="elegirArchivo" />
                </label>

                <textarea v-model="texto" rows="1" @keydown="alTeclear"
                    placeholder="Escribe tu respuesta…"
                    class="flex-1 min-w-0 border border-linea rounded-xl px-3 py-2.5 text-sm resize-none
                           max-h-32 focus:outline-none focus:border-[var(--marca)]"></textarea>

                <button type="button" @click="enviar"
                    :disabled="enviando || (! texto.trim() && ! archivo)"
                    class="shrink-0 w-10 h-10 rounded-xl text-white flex items-center justify-center
                           disabled:opacity-40 disabled:cursor-not-allowed"
                    style="background:var(--marca)" title="Enviar">
                    <IconoMenu :nombre="enviando ? 'clock' : 'paper-plane'" clase="text-sm w-4" />
                </button>
            </div>

            <p v-if="ventana.restante" class="mt-1.5 text-[11px] text-tinta-400">
                Se puede escribir libremente por {{ ventana.restante }} más.
            </p>
        </template>

        <!-- ── La ventana se cerró y el canal tiene plantillas ──────────────── -->
        <template v-else-if="ventana.plantillas">
            <div class="rounded-xl bg-pastel-ambar border border-borde-aviso-ambar px-3 py-2.5">
                <div class="flex items-start gap-2 text-aviso-ambar">
                    <IconoMenu nombre="clock" clase="text-sm w-4 mt-0.5" />
                    <p class="text-xs flex-1">{{ ventana.motivo }}</p>
                </div>

                <button v-if="! abriendoPlantilla && plantillas.length" type="button"
                    @click="abriendoPlantilla = true"
                    class="mt-2 px-3 py-1.5 rounded-lg text-xs font-semibold text-white"
                    style="background:var(--marca)">
                    Enviar una plantilla
                </button>

                <!-- Sin plantillas cargadas no hay nada que ofrecer, y hay que decir dónde se
                     cargan: el selector vacío fue el que hizo que nadie supiera qué hacer. -->
                <p v-else-if="! plantillas.length" class="mt-2 text-xs text-aviso-ambar">
                    No hay plantillas aprobadas todavía. Se crean en Meta y se traen desde
                    <a href="/configuracion/whatsapp-numeros" class="underline font-medium">Números de WhatsApp</a>.
                </p>
            </div>

            <!-- El armado de la plantilla -->
            <div v-if="abriendoPlantilla" class="mt-3 space-y-2.5">
                <select v-model="plantillaId"
                    class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]">
                    <option :value="null">Elige una plantilla…</option>
                    <option v-for="p in plantillas" :key="p.id" :value="p.id">
                        {{ p.nombre }} ({{ p.idioma }}){{ p.categoria ? ' · ' + p.categoria : '' }}
                    </option>
                </select>

                <div v-if="plantillaElegida" class="space-y-2">
                    <div v-for="n in plantillaElegida.variables" :key="n">
                        <label class="block text-[11px] font-medium text-tinta-500 mb-0.5">Dato {{ n }}</label>
                        <input v-model="variables[n - 1]" type="text"
                            class="w-full border border-linea rounded-lg px-2.5 py-1.5 text-sm focus:outline-none focus:border-[var(--marca)]" />
                    </div>

                    <div class="rounded-xl bg-superficie-2 border border-linea px-3 py-2">
                        <p class="text-[11px] font-semibold text-tinta-500 mb-1">Así le va a llegar</p>
                        <p class="text-sm text-tinta-900 whitespace-pre-wrap">{{ previsualizacion }}</p>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <button type="button" @click="enviarPlantilla"
                        :disabled="! plantillaElegida || faltanVariables || enviando"
                        class="px-4 py-2 rounded-xl text-sm font-semibold text-white disabled:opacity-40"
                        style="background:var(--marca)">
                        {{ enviando ? 'Enviando…' : 'Enviar la plantilla' }}
                    </button>
                    <button type="button" @click="cerrarPlantilla"
                        class="px-3 py-2 rounded-xl text-sm text-tinta-500 border border-linea hover:bg-realce">
                        Cancelar
                    </button>
                </div>
            </div>
        </template>

        <!-- ── La ventana se cerró y el canal no tiene cómo escribir primero ── -->
        <div v-else
            class="rounded-xl bg-pastel-ambar border border-borde-aviso-ambar px-3 py-2.5
                   flex items-start gap-2 text-aviso-ambar">
            <IconoMenu nombre="triangle-exclamation" clase="text-sm w-4 mt-0.5" />
            <p class="text-xs">{{ ventana.motivo }}</p>
        </div>
    </div>
</template>
