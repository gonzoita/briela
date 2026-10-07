<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import IconoMenu from '@/Components/IconoMenu.vue'

/**
 * El hilo de una conversación, igual para los cinco canales.
 *
 * Es una pantalla sola y no una por canal a propósito, por lo mismo que Trabajos y Calidad
 * comparten `FichaProceso`: el gesto es el mismo —leer lo que escribieron, contestar— y
 * escribirla dos veces habría hecho que se separaran al primer arreglo.
 *
 * Los mensajes salientes van con el color de marca y texto blanco, que es lo único que se ve
 * igual de día y de noche; los entrantes, sobre la superficie del tema.
 */
const props = defineProps({
    mensajes: { type: Array, default: () => [] },
    cargando: { type: Boolean, default: false },
})

const contenedor = ref(null)

/**
 * Los mensajes agrupados por día, con su encabezado.
 *
 * Sin el separador, un hilo de meses es una pared de horas sueltas: «14:32» no dice nada si no
 * se sabe de qué día es.
 */
const porDia = computed(() => {
    const grupos = []

    for (const mensaje of props.mensajes) {
        const ultimo = grupos[grupos.length - 1]

        if (ultimo && ultimo.dia === mensaje.dia) {
            ultimo.mensajes.push(mensaje)
        } else {
            grupos.push({ dia: mensaje.dia, etiqueta: etiquetaDeDia(mensaje.dia), mensajes: [mensaje] })
        }
    }

    return grupos
})

function etiquetaDeDia(dia) {
    if (! dia) return ''

    const hoy = new Date()
    const ayer = new Date(hoy.getTime() - 86400000)
    const comoTexto = (f) => `${f.getFullYear()}-${String(f.getMonth() + 1).padStart(2, '0')}-${String(f.getDate()).padStart(2, '0')}`

    if (dia === comoTexto(hoy))  return 'Hoy'
    if (dia === comoTexto(ayer)) return 'Ayer'

    // Se arma con los números y no con `new Date(dia)`: una fecha sin hora la interpreta el
    // navegador como UTC, y en Colombia eso corre el día hacia atrás cinco horas.
    const [a, m, d] = dia.split('-').map(Number)

    return new Date(a, m - 1, d).toLocaleDateString('es-CO', { day: 'numeric', month: 'long', year: 'numeric' })
}

/**
 * Baja al último mensaje cuando llega uno nuevo.
 *
 * Un hilo que abre arriba obliga a desplazarse hasta el final para leer lo que acaba de
 * llegar, que es justo lo único que importa al abrirlo.
 */
watch(() => props.mensajes.length, async () => {
    await nextTick()
    if (contenedor.value) contenedor.value.scrollTop = contenedor.value.scrollHeight
}, { immediate: true })

defineExpose({ alFinal: () => { if (contenedor.value) contenedor.value.scrollTop = contenedor.value.scrollHeight } })
</script>

<template>
    <div ref="contenedor" class="flex-1 overflow-y-auto px-3 md:px-5 py-4 space-y-4">

        <div v-if="cargando" class="flex items-center justify-center py-10 text-sm text-tinta-400 gap-2">
            <IconoMenu nombre="clock" clase="text-sm w-4 animate-pulse" />
            Trayendo la conversación…
        </div>

        <p v-else-if="! mensajes.length" class="text-center text-sm text-tinta-400 py-10">
            Todavía no hay mensajes en esta conversación.
        </p>

        <div v-for="grupo in porDia" :key="grupo.dia" class="space-y-2">

            <!-- El día -->
            <div class="flex items-center gap-3">
                <span class="flex-1 h-px bg-separador"></span>
                <span class="text-[11px] font-medium text-tinta-400 uppercase tracking-wide">{{ grupo.etiqueta }}</span>
                <span class="flex-1 h-px bg-separador"></span>
            </div>

            <div v-for="m in grupo.mensajes" :key="m.id"
                class="flex" :class="m.direccion === 'saliente' ? 'justify-end' : 'justify-start'">

                <div class="max-w-[85%] md:max-w-[70%] min-w-0">

                    <!-- Quién lo escribió de nuestro lado. Un mensaje del agente de IA o de una
                         respuesta automática no lo escribió un compañero, y decirlo evita que
                         alguien crea que sí. -->
                    <p v-if="m.direccion === 'saliente' && m.autor"
                        class="text-[11px] text-tinta-400 mb-0.5 text-right pr-1">{{ m.autor }}</p>

                    <div class="rounded-2xl px-3 py-2 text-sm break-words"
                        :class="m.direccion === 'saliente'
                            ? 'text-white rounded-br-md'
                            : 'bg-superficie-2 border border-linea text-tinta-900 rounded-bl-md'"
                        :style="m.direccion === 'saliente' ? 'background:var(--marca)' : ''">

                        <!-- El adjunto. Una imagen se mira; lo demás se descarga. -->
                        <template v-if="m.archivo">
                            <a v-if="m.archivo.es_imagen" :href="m.archivo.url" target="_blank" rel="noopener"
                                class="block mb-1.5">
                                <img :src="m.archivo.url" :alt="m.archivo.nombre"
                                    class="rounded-xl max-h-72 w-auto object-cover" loading="lazy" />
                            </a>
                            <a v-else :href="m.archivo.url" target="_blank" rel="noopener" download
                                class="flex items-center gap-2 mb-1.5 px-2 py-2 rounded-xl"
                                :class="m.direccion === 'saliente' ? 'bg-white/15' : 'bg-tinta-50'">
                                <IconoMenu nombre="paperclip" clase="text-sm w-4" />
                                <span class="min-w-0">
                                    <span class="block truncate text-xs font-medium">{{ m.archivo.nombre }}</span>
                                    <span class="block text-[11px] opacity-70">{{ m.archivo.tamano }}</span>
                                </span>
                            </a>
                        </template>

                        <p v-if="m.texto" class="whitespace-pre-wrap">{{ m.texto }}</p>

                        <!-- Un archivo que no se pudo bajar: ver «había algo» y no poder abrirlo
                             es confuso, así que se dice. -->
                        <p v-else-if="! m.archivo && m.tipo !== 'texto'" class="italic opacity-80">
                            {{ m.tipo }} — no se pudo guardar el archivo
                        </p>

                        <div class="flex items-center justify-end gap-1.5 mt-1 text-[10px]"
                            :class="m.direccion === 'saliente' ? 'text-white/70' : 'text-tinta-400'">
                            <span v-if="m.plantilla" class="italic truncate max-w-[10rem]">plantilla: {{ m.plantilla }}</span>
                            <span>{{ m.hora }}</span>
                            <span v-if="m.direccion === 'saliente' && m.estado">· {{ m.estado }}</span>
                        </div>
                    </div>

                    <p v-if="m.error"
                        class="mt-1 px-2 py-1 rounded-lg text-[11px] bg-pastel-rojo border border-borde-aviso-rojo text-aviso-rojo">
                        {{ m.error }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
