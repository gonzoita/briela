<script setup>
/**
 * El resultado de un probador, pintado ahí mismo.
 *
 * Tres estados y no dos: una prueba puede funcionar y aun así dejar algo que
 * corregir —el número responde pero es otra línea, el webhook contesta pero no
 * hay App Secret—. Pintar eso de verde hace que nadie lo lea.
 *
 * `resultado` es lo que devuelven los probadores del servidor:
 *   { ok: bool, mensaje: string, detalle?: string[], aviso?: bool }
 */
defineProps({
    resultado: { type: Object, default: null },
    cargando:  { type: Boolean, default: false },
})
</script>

<template>
    <div v-if="cargando" class="mt-2 rounded-lg border border-linea bg-tinta-50 px-3 py-2 text-[11px] text-tinta-400">
        Probando...
    </div>

    <div
        v-else-if="resultado"
        class="mt-2 rounded-lg border px-3 py-2 text-[11px] leading-relaxed"
        :class="!resultado.ok  ? 'bg-pastel-rojo border-borde-aviso-rojo text-aviso-rojo'
              : resultado.aviso ? 'bg-pastel-ambar border-borde-aviso-ambar text-aviso-ambar'
                                : 'bg-pastel-verde border-borde-aviso-verde text-aviso-verde'"
    >
        <p class="font-semibold">{{ resultado.mensaje }}</p>
        <ul v-if="resultado.detalle?.length" class="mt-1 space-y-1 list-disc list-inside opacity-90">
            <li v-for="(d, i) in resultado.detalle" :key="i">{{ d }}</li>
        </ul>
    </div>
</template>
