<script setup>
/**
 * El texto de un encabezado de tabla, convertido en botón para ordenar.
 *
 * Va **dentro** del `<th>` que ya existe, no en su lugar: cada lista de Briela tiene su propio
 * estilo de encabezado —anchos, `hidden sm:table-cell`, mayúsculas— y reemplazar la etiqueta
 * completa obligaba a copiar esas clases dieciséis veces y a que se fueran separando.
 *
 * La flecha aparece solo en la columna activa. Pintar una flecha gris en las seis columnas
 * convierte el encabezado en un adorno y deja de decir cuál manda.
 */
defineProps({
    campo: { type: String, required: true },
    // 'asc', 'desc', o null cuando esta columna no es la que ordena.
    estado: { type: String, default: null },
    // A la derecha para números y dinero, que es como se leen.
    derecha: { type: Boolean, default: false },
})

const emit = defineEmits(['ordenar'])
</script>

<template>
    <button type="button" @click="emit('ordenar', campo)"
        class="inline-flex items-center gap-1 select-none hover:text-[var(--marca)] transition-colors"
        :class="[derecha ? 'flex-row-reverse' : '', estado ? 'text-[var(--marca)]' : '']">
        <slot />
        <svg v-if="estado" class="w-3 h-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
            <path v-if="estado === 'asc'" stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7"/>
            <path v-else stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
        </svg>
        <!-- Un hueco del mismo ancho cuando no está activa: sin él la fila salta al cambiar
             de columna. -->
        <span v-else class="w-3 shrink-0" aria-hidden="true"></span>
    </button>
</template>
