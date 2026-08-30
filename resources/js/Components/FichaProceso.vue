<script setup>
/**
 * La ficha grande de una unidad física, con un botón por paso.
 *
 * Es la misma pieza en Trabajos y en Calidad, y eso es a propósito: quien avanza la producción
 * y quien la revisa hacen el mismo gesto —mirar la unidad, tocar el paso, seguir—, así que la
 * pantalla no tiene por qué ser distinta. Escribirla dos veces habría hecho que se separaran
 * al primer arreglo.
 *
 * Los botones son grandes porque se tocan con guante y de pie, no con el ratón sentado. Y
 * llevan el número de la orden y las medidas de ESTA unidad encima: sin eso, cinco fichas del
 * mismo ensamble son cinco fichas idénticas.
 */
import { computed } from 'vue'

const props = defineProps({
    // El encabezado
    numero:    { type: [String, Number], default: '' },
    sufijo:    { type: String, default: '' },     // «−1» cuando la orden tiene varias unidades
    titulo:    { type: String, default: '' },
    subtitulo: { type: String, default: '' },
    chips:     { type: Array,  default: () => [] },   // las medidas de la instancia
    // Aviso de urgencia: { clave, etiqueta }
    urgencia:  { type: Object, default: () => ({ clave: 'normal', etiqueta: '' }) },
    // Una insignia extra, para lo que no es urgencia: hoy, «en reproceso».
    marca:     { type: String, default: '' },
    fecha:     { type: String, default: '' },
    contador:  { type: String, default: '' },     // «3/8»
    porcentaje:{ type: Number, default: 0 },
    // Los botones: [{ id, label, estado: 'ok'|'pendiente'|'falla', nota, exigeFoto, bloqueado }]
    botones:   { type: Array,  default: () => [] },
    // El botón de la derecha
    accion:      { type: String,  default: 'Terminar' },
    accionHecha: { type: String,  default: 'Terminada' },
    hecha:       { type: Boolean, default: false },
    ocupada:     { type: Boolean, default: false },
    aviso:       { type: String,  default: '' },
})

const emit = defineEmits(['boton', 'boton-alterno', 'accion', 'abrir'])

/**
 * La paleta de los pasos.
 *
 * Son colores saturados con texto blanco encima, que es lo único que se ve igual de día y de
 * noche — un pastel fijo quedaría claro sobre claro en un tema y oscuro sobre oscuro en el
 * otro. El paso pendiente no lleva color de relleno: lleva un tinte del suyo con transparencia
 * sobre la superficie, así que se adapta al tema sin dejar de identificarse.
 *
 * Ese tinte usa `color-mix`, que una tableta vieja de planta puede no entender. Un valor que
 * el navegador no entiende se descarta, así que el pendiente lleva además `bg-superficie
 * border-linea` en las clases: ahí no se pierde el color, se pierde el matiz.
 */
const PALETA = ['#1E3A8A', '#1D4ED8', '#0E7490', '#0F766E', '#047857', '#4338CA', '#6D28D9', '#A21CAF']

const color = (i) => PALETA[i % PALETA.length]

function estiloBoton(b, i) {
    if (b.estado === 'falla') {
        return { background: '#B42318', borderColor: '#B42318', color: '#fff' }
    }

    if (b.estado === 'ok') {
        const c = color(i)

        return { background: c, borderColor: c, color: '#fff' }
    }

    const c = color(i)

    return {
        background:  `color-mix(in srgb, ${c} 8%, transparent)`,
        borderColor: `color-mix(in srgb, ${c} 30%, transparent)`,
    }
}

// El acento de la izquierda: rojo si está vencida, ámbar si es hoy o casi, la marca si va bien.
const acento = computed(() => ({
    vencida: 'var(--texto-rojo)',
    hoy:     'var(--texto-ambar)',
    alta:    'var(--texto-ambar)',
    normal:  'var(--marca)',
}[props.urgencia?.clave] ?? 'var(--tinta-300)'))

const claseUrgencia = computed(() => ({
    vencida: 'bg-pastel-rojo-2 text-aviso-rojo',
    hoy:     'bg-pastel-ambar-2 text-aviso-ambar',
    alta:    'bg-pastel-ambar-2 text-aviso-ambar',
    normal:  'bg-tinta-100 text-tinta-400',
}[props.urgencia?.clave] ?? 'bg-tinta-100 text-tinta-400'))

const fondoFicha = computed(() =>
    props.urgencia?.clave === 'vencida' ? 'bg-pastel-rojo' : 'bg-superficie'
)
</script>

<template>
    <div class="rounded-2xl border border-linea shadow-sm overflow-hidden flex" :class="fondoFicha">

        <!-- El acento. Ancho fijo y sin texto: se lee de lejos, cruzando la planta. -->
        <div class="w-1.5 shrink-0" :style="{ background: acento }"></div>

        <div class="flex-1 min-w-0 p-3 md:p-4">

            <!-- ── Encabezado ───────────────────────────────────────────────────── -->
            <div class="flex items-start gap-3 flex-wrap">

                <button type="button" @click="emit('abrir')"
                    class="flex items-baseline gap-1.5 min-w-0 text-left group">
                    <span class="text-2xl md:text-3xl font-black tabular-nums tracking-tight text-tinta-900 group-hover:text-[var(--marca)] transition-colors">
                        {{ numero }}
                    </span>
                    <span v-if="sufijo" class="text-xl md:text-2xl font-black tabular-nums text-tinta-300">{{ sufijo }}</span>
                </button>

                <button type="button" @click="emit('abrir')"
                    class="min-w-0 text-left flex-1 basis-40">
                    <p class="text-sm md:text-base font-bold uppercase tracking-wide text-tinta-800 truncate">
                        {{ titulo }}
                    </p>
                    <p v-if="subtitulo" class="text-xs text-tinta-400 truncate">{{ subtitulo }}</p>
                </button>

                <!-- Las medidas de esta unidad. Sin esto, cinco fichas del mismo ensamble
                     son cinco fichas iguales y no se sabe cuál se tiene en la mano. -->
                <div v-if="chips.length" class="flex flex-wrap items-center gap-x-2 gap-y-1 min-w-0">
                    <span v-for="(c, i) in chips" :key="i"
                        class="text-xs text-tinta-500 whitespace-nowrap">
                        <span v-if="i" class="text-tinta-200 mr-2">·</span>{{ c }}
                    </span>
                </div>

                <span v-if="marca"
                    class="text-[10px] font-bold uppercase tracking-[0.08em] px-2 py-1 rounded-full shrink-0
                           bg-pastel-naranja-2 text-aviso-naranja">
                    {{ marca }}
                </span>

                <span v-if="urgencia?.etiqueta"
                    class="text-[10px] font-bold uppercase tracking-[0.08em] px-2 py-1 rounded-full shrink-0"
                    :class="claseUrgencia">
                    {{ urgencia.etiqueta }}
                </span>

                <span v-if="fecha" class="text-xs text-tinta-400 tabular-nums shrink-0">{{ fecha }}</span>

                <span v-if="contador"
                    class="text-xs font-bold tabular-nums px-2 py-1 rounded-lg bg-tinta-100 text-tinta-500 shrink-0">
                    {{ contador }}
                </span>

                <span class="text-lg md:text-xl font-bold tabular-nums shrink-0"
                    :class="porcentaje >= 100 ? 'text-aviso-verde' : porcentaje > 0 ? 'text-tinta-700' : 'text-tinta-300'">
                    {{ porcentaje }}%
                </span>

                <button type="button" @click="emit('accion')" :disabled="ocupada"
                    class="ml-auto shrink-0 px-4 py-2 rounded-xl text-sm font-semibold border transition-colors disabled:opacity-40"
                    :class="hecha
                        ? 'border-borde-aviso-verde bg-pastel-verde text-aviso-verde'
                        : 'border-[var(--marca)] text-[var(--marca)] hover:bg-realce'">
                    {{ ocupada ? '…' : (hecha ? accionHecha : accion) }}
                </button>
            </div>

            <p v-if="aviso" class="text-xs text-aviso-ambar bg-pastel-ambar border border-borde-aviso-ambar rounded-xl px-3 py-2 mt-3">
                {{ aviso }}
            </p>

            <!-- ── Los pasos ────────────────────────────────────────────────────── -->
            <div v-if="botones.length" class="flex flex-wrap gap-2 mt-3">
                <button v-for="(b, i) in botones" :key="b.id"
                    type="button"
                    @click="emit('boton', b)"
                    @contextmenu.prevent="emit('boton-alterno', b)"
                    :disabled="ocupada || b.bloqueado"
                    :title="b.nota || b.label"
                    class="relative flex-1 basis-[calc(50%-0.25rem)] sm:basis-[calc(33.333%-0.34rem)] md:basis-0 md:min-w-[110px]
                           rounded-xl border px-2 py-2.5 transition-all disabled:opacity-40
                           flex flex-col items-center justify-center gap-1 active:scale-[0.97]"
                    :class="b.estado === 'pendiente' ? 'bg-superficie border-linea' : ''"
                    :style="estiloBoton(b, i)">

                    <!-- La marca de estado: círculo vacío, visto, o aspa. -->
                    <span class="w-5 h-5 rounded-full border-2 flex items-center justify-center shrink-0"
                        :class="b.estado === 'pendiente' ? 'border-tinta-300' : 'border-white/70'">
                        <svg v-if="b.estado === 'ok'" class="w-3 h-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                        </svg>
                        <svg v-else-if="b.estado === 'falla'" class="w-3 h-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </span>

                    <span class="text-[10px] md:text-[11px] font-bold uppercase tracking-[0.04em] text-center leading-tight line-clamp-2"
                        :class="b.estado === 'pendiente' ? 'text-tinta-400' : ''">
                        {{ b.label }}
                    </span>

                    <!-- Un punto que exige foto se anuncia antes de tocarlo, no después. -->
                    <span v-if="b.exigeFoto"
                        class="absolute top-1 right-1 w-4 h-4 rounded-full flex items-center justify-center"
                        :class="b.estado === 'pendiente' ? 'bg-tinta-200 text-tinta-500' : 'bg-white/25 text-white'"
                        title="Exige foto">
                        <svg class="w-2.5 h-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 9a1 1 0 011-1h1.2a1 1 0 00.9-.5l.5-.9a1 1 0 01.9-.6h3a1 1 0 01.9.6l.5.9a1 1 0 00.9.5H19a1 1 0 011 1v8a1 1 0 01-1 1H5a1 1 0 01-1-1V9z"/>
                            <circle cx="12" cy="13" r="2.6"/>
                        </svg>
                    </span>
                </button>
            </div>

            <p v-else class="text-xs text-tinta-300 italic mt-3">
                Esta unidad no tiene pasos definidos.
            </p>
        </div>
    </div>
</template>
