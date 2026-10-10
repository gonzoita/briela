<script setup>
import { computed } from 'vue'

// La nota de un proveedor, calculada por el sistema con las órdenes y los precios: nadie la
// escribe. Ver `CalificacionProveedorService`.
//
// Sin nota —menos de tres órdenes evaluables— se dice «Sin calificar» y por qué, en vez de
// pintar un número que una sola orden no sostiene.
const props = defineProps({
    // { puntaje, nivel, muestras, minimo_muestras, mensaje, componentes: { clave: { etiqueta, peso, valor, texto } } }
    calificacion: { type: Object, default: null },
    // Con el desglose: cada componente, su barra y lo que lo explica.
    detalle:      { type: Boolean, default: false },
})

// Familias del tema, no colores fijos: de noche un `bg-green-100` queda ilegible.
const niveles = {
    excelente:  { etiqueta: 'Excelente',  clases: 'bg-pastel-verde-2 text-aviso-verde' },
    bueno:      { etiqueta: 'Bueno',      clases: 'bg-pastel-azul-2 text-aviso-azul' },
    regular:    { etiqueta: 'Regular',    clases: 'bg-pastel-ambar-2 text-aviso-ambar' },
    deficiente: { etiqueta: 'Deficiente', clases: 'bg-pastel-rojo-2 text-aviso-rojo' },
}

const nivel = computed(() => niveles[props.calificacion?.nivel] ?? null)

const componentes = computed(() => Object.values(props.calificacion?.componentes ?? {}))
</script>

<template>
    <div>
        <span v-if="nivel" class="inline-flex items-center gap-1.5 text-xs font-semibold px-2.5 py-0.5 rounded-full"
            :class="nivel.clases"
            :title="`Calculada con ${calificacion.muestras} órdenes del último año`">
            <span>{{ calificacion.puntaje }}</span>
            <span class="font-medium">{{ nivel.etiqueta }}</span>
        </span>
        <span v-else class="inline-flex text-xs px-2.5 py-0.5 rounded-full bg-tinta-100 text-tinta-400"
            :title="calificacion?.mensaje ?? 'Todavía no hay órdenes para calificar'">
            Sin calificar
        </span>

        <template v-if="detalle && calificacion">
            <p v-if="calificacion.mensaje" class="mt-2 text-xs text-tinta-400">{{ calificacion.mensaje }}</p>
            <ul class="mt-3 space-y-3">
                <li v-for="c in componentes" :key="c.etiqueta">
                    <div class="flex items-baseline justify-between gap-2">
                        <span class="text-xs font-medium text-tinta-700">
                            {{ c.etiqueta }} <span class="text-tinta-300">· pesa {{ c.peso }}</span>
                        </span>
                        <span class="text-xs font-semibold text-tinta-700">{{ c.valor === null ? '—' : c.valor }}</span>
                    </div>
                    <div class="mt-1 h-1.5 rounded-full bg-tinta-100 overflow-hidden">
                        <div class="h-full rounded-full" :style="`width:${c.valor ?? 0}%; background:var(--marca);`" />
                    </div>
                    <p class="mt-1 text-xs text-tinta-400">{{ c.texto }}</p>
                </li>
            </ul>
        </template>
    </div>
</template>
