<script setup>
/**
 * "Con la tecnología de Briela" — el único lugar donde aparece la marca de
 * Briela dentro de una instalación: todo lo demás es de la empresa que la usa.
 * Por eso siempre el mismo logo real (LogoBriela tono="producto"), nunca el
 * color ni el nombre del cliente, y siempre discreto.
 */
import LogoBriela from '@/Components/LogoBriela.vue'

defineProps({
    tamano: { type: Number, default: 16 },
    // El fondo detrás es oscuro (modo de noche de la app, o una pantalla que
    // siempre es oscura): usa la versión del logo hecha para eso.
    oscuro: { type: Boolean, default: false },
})
</script>

<template>
    <div class="flex flex-col items-center gap-1.5">
        <span class="text-[10px] uppercase tracking-[0.14em] text-tinta-300">Con la tecnología de</span>
        <LogoBriela :tamano="tamano" tono="producto" :fondo-oscuro="oscuro" />
    </div>
</template>
