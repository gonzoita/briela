<script setup>
/**
 * Cuántas unidades quedan, con el color que corresponde.
 *
 * Existe como componente porque la regla del color tiene que ser una sola. El buscador de
 * productos mostraba el stock siempre en verde, dijera 200 o dijera 1: un número verde se
 * lee como «hay», y quien cotiza le prometía al cliente unidades que no existen.
 *
 * Tres estados, con el mismo criterio que usa el inventario y el aviso diario de stock bajo
 * (`stockTotal() <= stock_minimo`):
 *
 * - **Sin stock** (cero o menos): rojo. No hay nada que despachar.
 * - **Bajo** (hasta el mínimo, y solo si el producto tiene un mínimo puesto): ámbar. Sin
 *   mínimo definido no se puede saber si 4 es poco o es lo normal, así que no se inventa.
 * - **Disponible**: verde.
 *
 * Un servicio no lleva inventario: no muestra nada. Mostrarle «0 disponibles» a una hora de
 * instalación es peor que no decir nada, porque parece un faltante.
 *
 * Pero sí se muestra cuando el producto **tiene** existencias, aunque esté marcado como no
 * inventariable: el interruptor de inventario del formulario nace apagado, así que hay
 * productos físicos con 250 unidades y la marca en falso. Callar ahí sería esconder el dato
 * justo donde hace falta. Si no lleva inventario y además no tiene nada, no se dice nada.
 */
import { computed } from 'vue'

const props = defineProps({
    stock:  { type: [Number, String], default: null },
    minimo: { type: [Number, String], default: 0 },
    // Un producto no inventariable —un servicio, una hora de obra— no tiene stock que mirar.
    inventariable: { type: Boolean, default: true },
    // `completo` agrega la palabra «disponibles». En una lista apretada estorba; al lado de
    // la cantidad que se está cotizando, hace falta para que el número se entienda.
    completo: { type: Boolean, default: false },
    // Cuántas unidades se están pidiendo. Si pasan de lo que hay, se dice.
    pedida: { type: [Number, String], default: null },
})

const hay = computed(() => Number(props.stock) || 0)
const min = computed(() => Number(props.minimo) || 0)

const hayDato = computed(() =>
    props.stock !== null && props.stock !== undefined && props.stock !== ''
)

const mostrar = computed(() => hayDato.value && (props.inventariable || hay.value !== 0))

const estado = computed(() => {
    if (hay.value <= 0) return 'sin'
    if (min.value > 0 && hay.value <= min.value) return 'bajo'

    return 'ok'
})

const noAlcanza = computed(() => {
    const pide = Number(props.pedida) || 0

    return pide > 0 && pide > hay.value
})

// Se redondea solo para mostrar: hay unidades que se miden en metros y llevan decimales,
// pero «3,0000 disponibles» no ayuda a nadie.
const cantidad = computed(() =>
    new Intl.NumberFormat('es-CO', { maximumFractionDigits: 2 }).format(hay.value)
)

const clases = computed(() => ({
    sin:  'bg-red-50 text-red-700',
    bajo: 'bg-amber-50 text-amber-700',
    ok:   'bg-emerald-50 text-emerald-700',
}[estado.value]))

const texto = computed(() => {
    if (estado.value === 'sin') return props.completo ? 'Sin stock' : 'sin stock'

    return props.completo ? `${cantidad.value} disponibles` : cantidad.value
})
</script>

<template>
    <span v-if="mostrar" class="inline-flex items-center gap-1 shrink-0">
        <span class="text-xs font-semibold px-1.5 py-0.5 rounded-full whitespace-nowrap" :class="clases">
            {{ texto }}
        </span>
        <!-- El aviso de que no alcanza va aparte del número: el stock puede estar en verde
             —hay 20— y la cantidad cotizada ser 50. Son dos cosas distintas. -->
        <span v-if="noAlcanza" class="text-xs font-semibold text-red-600 whitespace-nowrap" title="La cantidad cotizada supera lo que hay en inventario">
            ⚠ no alcanza
        </span>
    </span>
</template>
