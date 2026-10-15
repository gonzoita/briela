<script setup>
/**
 * El mínimo o el máximo de stock de un material, editable en el mismo renglón.
 *
 * Se toca el número, se escribe y Enter (o salir del campo) lo guarda; Esc lo cancela. Es lo que
 * se hace cien veces cuando se carga el inventario por primera vez, así que no abre ningún
 * formulario. Vacío o cero = sin límite. Lo guarda `PATCH /inventario/{id}/limites`, que exige
 * `inventario.editar`: sin ese permiso el número se ve y no se puede tocar.
 */
import { ref, nextTick } from 'vue'
import { router } from '@inertiajs/vue3'

const props = defineProps({
    item:   { type: Object, required: true },
    // 'stock_minimo' o 'stock_maximo'
    campo:  { type: String, required: true },
    puede:  { type: Boolean, default: true },
})

const editando = ref(false)
const valor    = ref('')
const error    = ref('')
const entrada  = ref(null)
let guardado = false

const fmt = (n) => Number(n || 0).toLocaleString('es-CO', { minimumFractionDigits: 0, maximumFractionDigits: 3 })
const etiqueta = props.campo === 'stock_minimo' ? 'mínimo' : 'máximo'

async function abrir() {
    if (! props.puede) return
    valor.value   = Number(props.item[props.campo]) > 0 ? String(Number(props.item[props.campo])) : ''
    error.value   = ''
    guardado      = false
    editando.value = true
    await nextTick()
    entrada.value?.focus()
    entrada.value?.select()
}

function cancelar() {
    guardado = true // el blur que sigue no debe guardar
    editando.value = false
}

function guardar() {
    if (guardado) return
    guardado = true

    const nuevo  = valor.value === '' ? 0 : Number(valor.value)
    const actual = Number(props.item[props.campo]) || 0

    if (Number.isNaN(nuevo) || nuevo < 0) { error.value = 'Escribe un número positivo.'; guardado = false; return }
    if (nuevo === actual) { editando.value = false; return }

    router.patch(`/inventario/${props.item.id}/limites`, { [props.campo]: nuevo }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { editando.value = false },
        onError: (e) => { error.value = Object.values(e)[0] ?? 'No se pudo guardar.'; guardado = false },
    })
}
</script>

<template>
    <span class="inline-block text-right">
        <input v-if="editando" ref="entrada" v-model="valor" type="number" min="0" step="0.001" inputmode="decimal"
            :aria-label="`Stock ${etiqueta} de ${item.nombre}`"
            class="w-20 rounded-md border border-[var(--marca)] bg-superficie px-1.5 py-0.5 text-right text-sm text-tinta-900 focus:outline-none"
            @keydown.enter.prevent="guardar" @keydown.esc.prevent="cancelar" @blur="guardar" />
        <button v-else type="button" :disabled="! puede" @click="abrir"
            :title="puede ? `Cambiar el stock ${etiqueta}` : ''"
            :class="['rounded-md px-1.5 py-0.5 text-sm tabular-nums', puede ? 'hover:bg-realce border border-transparent hover:border-linea cursor-text' : 'cursor-default']">
            <span v-if="Number(item[campo]) > 0" class="text-tinta-700">{{ fmt(item[campo]) }}</span>
            <span v-else class="text-tinta-300">{{ puede ? 'Poner' : '—' }}</span>
        </button>
        <span v-if="error" class="block text-[11px] text-aviso-rojo">{{ error }}</span>
    </span>
</template>
