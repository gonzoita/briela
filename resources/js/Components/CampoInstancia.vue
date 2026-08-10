<script setup>
import { computed } from 'vue'

const props = defineProps({
    campo:      { type: Object, required: true },
    modelValue: { type: [String, Number], default: '' },
})
defineEmits(['update:modelValue'])

const opcionesParseadas = computed(() => {
    if (!props.campo?.opciones_selector) return []
    try {
        return Array.isArray(props.campo.opciones_selector)
            ? props.campo.opciones_selector
            : JSON.parse(props.campo.opciones_selector)
    } catch { return [] }
})
</script>

<template>
    <div>
        <select
            v-if="campo.subtipo_variable === 'selector'"
            :value="modelValue"
            @change="$emit('update:modelValue', $event.target.value)"
            class="w-full border border-tinta-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[var(--marca)] bg-superficie"
        >
            <option value="" disabled>Seleccionar...</option>
            <option v-for="op in opcionesParseadas" :key="op.valor" :value="op.valor">
                {{ op.etiqueta }}
            </option>
        </select>
        <input
            v-else-if="campo.subtipo_variable === 'texto'"
            type="text"
            :value="modelValue"
            @input="$emit('update:modelValue', $event.target.value)"
            class="w-full border border-tinta-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[var(--marca)]"
        />
        <input
            v-else-if="campo.subtipo_variable === 'decimal'"
            type="number" step="0.01"
            :value="modelValue"
            @input="$emit('update:modelValue', parseFloat($event.target.value) || 0)"
            class="w-full border border-tinta-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[var(--marca)]"
        />
        <input
            v-else
            type="number" step="1"
            :value="modelValue"
            @input="$emit('update:modelValue', parseFloat($event.target.value) || 0)"
            class="w-full border border-tinta-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[var(--marca)]"
        />
        <p v-if="campo.ayuda" class="text-xs text-tinta-300 mt-1">{{ campo.ayuda }}</p>
    </div>
</template>
