<script setup>
/**
 * El selector de orden de una lista.
 *
 * Existe además del encabezado de tabla porque no todas las listas son tablas —varias son
 * tarjetas— y porque en celular no hay encabezados donde hacer clic. Este control funciona en
 * los dos casos y es el único que hace falta en una lista de tarjetas.
 *
 * Mobile-first: en celular es un desplegable y un botón para la dirección; en pantalla ancha
 * es lo mismo, porque un desplegable de seis campos no mejora ocupando toda la fila.
 */
import { computed } from 'vue'

const props = defineProps({
    // [{ campo: 'nombre', etiqueta: 'Nombre' }, …] en el orden en que se ofrecen.
    campos: { type: Array, required: true },
    // Lo que devolvió `Orden::aplicar()`.
    orden:  { type: Object, default: () => ({}) },
})

const emit = defineEmits(['ordenar'])

const campoActual = computed(() => props.orden?.campo ?? '')
const dirActual   = computed(() => props.orden?.dir ?? 'desc')

const etiquetaActual = computed(() =>
    props.campos.find(c => c.campo === campoActual.value)?.etiqueta ?? 'Orden'
)

// Los textos se ordenan A→Z; los números y las fechas, de mayor a menor. Decir «ascendente»
// obliga a traducir mentalmente qué significa en cada caso.
const esTexto = computed(() =>
    props.campos.find(c => c.campo === campoActual.value)?.texto !== false
)

const leyenda = computed(() => {
    if (esTexto.value) return dirActual.value === 'asc' ? 'A → Z' : 'Z → A'

    return dirActual.value === 'asc' ? 'menor primero' : 'mayor primero'
})
</script>

<template>
    <div class="flex items-center gap-2">
        <label class="text-xs text-tinta-400 shrink-0 hidden sm:block">Ordenar por</label>

        <select :value="campoActual" @change="emit('ordenar', $event.target.value)"
            class="min-w-0 flex-1 sm:flex-none border border-linea rounded-xl px-2.5 py-2 text-sm bg-superficie focus:outline-none focus:border-[var(--marca)]">
            <option v-for="c in campos" :key="c.campo" :value="c.campo">{{ c.etiqueta }}</option>
        </select>

        <!-- La dirección es un botón y no otra lista: son dos opciones, y verlas escritas
             —«A → Z»— ahorra traducir qué quiere decir ascendente en cada campo. -->
        <button type="button" @click="emit('ordenar', campoActual)"
            class="shrink-0 inline-flex items-center gap-1.5 border border-linea rounded-xl px-2.5 py-2 text-xs text-tinta-500 hover:bg-tinta-50 transition-colors"
            :title="`Cambiar a ${dirActual === 'asc' ? 'descendente' : 'ascendente'}`">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path v-if="dirActual === 'asc'" stroke-linecap="round" stroke-linejoin="round" d="M3 4h13M3 8h9M3 12h5m10 8V8m0 0l-3 3m3-3l3 3"/>
                <path v-else stroke-linecap="round" stroke-linejoin="round" d="M3 4h13M3 8h9M3 12h5m10 0v12m0 0l3-3m-3 3l-3-3"/>
            </svg>
            <span class="whitespace-nowrap">{{ leyenda }}</span>
        </button>
    </div>
</template>
