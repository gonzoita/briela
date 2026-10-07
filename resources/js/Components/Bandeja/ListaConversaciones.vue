<script setup>
import { router } from '@inertiajs/vue3'
import IconoMenu from '@/Components/IconoMenu.vue'
import { insignia } from '@/canales'

/**
 * La columna izquierda: quién escribió y qué dijo, lo último arriba.
 *
 * No lleva control de orden —y es la única lista del sistema que no—, porque una bandeja
 * ordenada por cualquier otra cosa deja de ser una bandeja: lo que se busca es lo que acaba de
 * llegar. Lo que sí lleva es filtro por canal, por estado y por quién atiende, que es como se
 * reparte el trabajo de verdad.
 */
const props = defineProps({
    conversaciones: { type: Object, required: true },
    activa:         { type: String, default: null },
})

defineEmits(['abrir'])

/** El tiempo relativo, corto, como en cualquier bandeja: «3 min», «2 h», «ayer». */
function cuando(iso) {
    if (! iso) return ''

    const fecha = new Date(iso.replace(' ', 'T'))
    const minutos = Math.floor((Date.now() - fecha.getTime()) / 60000)

    if (minutos < 1)    return 'ahora'
    if (minutos < 60)   return `${minutos} min`
    if (minutos < 1440) return `${Math.floor(minutos / 60)} h`
    if (minutos < 2880) return 'ayer'

    return fecha.toLocaleDateString('es-CO', { day: 'numeric', month: 'short' })
}

function irA(url) {
    // `preserveState` mantiene el hilo abierto y los filtros escritos: paginar no es empezar
    // de cero.
    router.get(url, {}, { preserveState: true, preserveScroll: true, replace: true })
}
</script>

<template>
    <div class="flex-1 overflow-y-auto divide-y divide-separador">

        <p v-if="! conversaciones.data.length" class="px-4 py-10 text-center text-sm text-tinta-400">
            No hay conversaciones con estos filtros.
        </p>

        <button v-for="c in conversaciones.data" :key="c.clave" type="button"
            @click="$emit('abrir', c.clave)"
            class="w-full text-left px-3 py-3 flex items-start gap-3 hover:bg-realce transition-colors"
            :class="activa === c.clave ? 'bg-realce' : ''">

            <!-- El canal, por su logo. Con cinco canales mezclados es lo primero que se mira. -->
            <span class="shrink-0 w-9 h-9 rounded-xl flex items-center justify-center"
                :class="insignia(c.color)"
                :title="c.canal_label">
                <IconoMenu :nombre="c.icono" clase="text-sm w-4" />
            </span>

            <span class="flex-1 min-w-0">
                <span class="flex items-center gap-2">
                    <span class="flex-1 min-w-0 truncate text-sm"
                        :class="c.sin_leer ? 'font-semibold text-tinta-900' : 'font-medium text-tinta-700'">
                        {{ c.contacto }}
                    </span>
                    <span class="shrink-0 text-[11px] text-tinta-400">{{ cuando(c.ultimo_mensaje_at) }}</span>
                </span>

                <span class="block truncate text-xs mt-0.5"
                    :class="c.sin_leer ? 'text-tinta-700' : 'text-tinta-400'">
                    {{ c.ultimo_texto }}
                </span>

                <span class="flex items-center gap-1.5 mt-1 flex-wrap">
                    <span v-if="c.sin_leer"
                        class="w-2 h-2 rounded-full shrink-0" style="background:var(--marca)" title="Sin leer"></span>

                    <!-- Por dónde entró. En una empresa con tres líneas, saber a cuál le
                         escribieron es lo que dice de quién es la conversación. -->
                    <span v-if="c.origen" class="text-[10px] px-1.5 py-0.5 rounded-full bg-tinta-100 text-tinta-500 truncate max-w-[9rem]">
                        {{ c.origen }}
                    </span>

                    <span v-if="! c.asignado_a"
                        class="text-[10px] px-1.5 py-0.5 rounded-full bg-pastel-ambar-2 text-aviso-ambar">
                        sin dueño
                    </span>

                    <!-- La ventana cerrada se avisa desde la lista: así se ve de un vistazo a
                         quién ya no se le puede escribir sin una plantilla. -->
                    <span v-if="! c.ventana_abierta"
                        class="text-[10px] px-1.5 py-0.5 rounded-full bg-tinta-100 text-tinta-500"
                        title="Pasaron más de 24 horas desde su último mensaje">
                        plazo vencido
                    </span>

                    <span v-if="c.lead_id"
                        class="text-[10px] px-1.5 py-0.5 rounded-full bg-pastel-violeta-2 text-aviso-violeta">
                        lead
                    </span>
                </span>
            </span>
        </button>

        <!-- Paginación -->
        <div v-if="conversaciones.last_page > 1"
            class="flex items-center justify-between gap-2 px-3 py-3 border-t border-linea">
            <button type="button" :disabled="! conversaciones.prev_page_url"
                @click="irA(conversaciones.prev_page_url)"
                class="px-3 py-1.5 rounded-lg text-xs border border-linea text-tinta-700 hover:bg-realce disabled:opacity-40">
                Anteriores
            </button>
            <span class="text-[11px] text-tinta-400">
                {{ conversaciones.current_page }} de {{ conversaciones.last_page }}
            </span>
            <button type="button" :disabled="! conversaciones.next_page_url"
                @click="irA(conversaciones.next_page_url)"
                class="px-3 py-1.5 rounded-lg text-xs border border-linea text-tinta-700 hover:bg-realce disabled:opacity-40">
                Siguientes
            </button>
        </div>
    </div>
</template>
