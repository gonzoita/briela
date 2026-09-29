<script setup>
/**
 * Lo que el cliente va a retener al pagar y lo que de verdad llega.
 *
 * La cuenta la hace el servidor (RetencionesService): aquí solo se dibuja. Es una estimación
 * y lo dice, y cuando una retención no aplica dice por qué: un «neto a recibir» que no se
 * puede explicar genera más dudas de las que resuelve.
 */
import { computed } from 'vue'
import { formatMoneda, formatPct } from '@/formato'

const props = defineProps({
    retenciones: { type: Object, default: null },
    cargando:    { type: Boolean, default: false },
    // El total del documento en pesos, con IVA.
    total:       { type: Number, default: 0 },
    moneda:      { type: String, default: 'COP' },
    tasa:        { type: [Number, String], default: 1 },
})

const valor = (pesos) => formatMoneda(pesos, props.moneda, props.tasa)
const hayLineas = computed(() => (props.retenciones?.lineas ?? []).length > 0)
const notas = computed(() => [...(props.retenciones?.avisos ?? []), ...(props.retenciones?.motivos ?? [])])
</script>

<template>
    <div v-if="retenciones" class="mt-3 pt-3 border-t border-linea space-y-1.5" :class="cargando ? 'opacity-60' : ''">
        <p class="text-[11px] font-semibold text-tinta-400 uppercase tracking-[0.12em]">Retenciones estimadas</p>

        <template v-if="hayLineas">
            <div v-for="l in retenciones.lineas" :key="l.clave" class="flex justify-between gap-2 text-xs text-tinta-500">
                <span class="min-w-0">{{ l.nombre }} <span class="text-tinta-300">({{ formatPct(l.tarifa) }}%)</span></span>
                <span class="shrink-0 text-aviso-rojo">-{{ valor(l.valor) }}</span>
            </div>
            <div class="flex justify-between text-sm font-semibold text-tinta-700 pt-1">
                <span>Neto a recibir</span>
                <span>{{ valor(total - retenciones.total) }}</span>
            </div>
        </template>
        <p v-else class="text-xs text-tinta-400">Ninguna.</p>

        <details v-if="notas.length" class="text-[11px] text-tinta-400">
            <summary class="cursor-pointer select-none hover:text-tinta-600">Por qué</summary>
            <ul class="mt-1 space-y-1 list-disc pl-4">
                <li v-for="(n, i) in notas" :key="i">{{ n }}</li>
            </ul>
        </details>
        <p class="text-[10px] text-tinta-300">Estimación con las reglas de Configuración → Perfil fiscal. La cifra final la liquida el cliente al pagar.</p>
    </div>
</template>
