<script setup>
const props = defineProps({
    label:       { type: String,  required: true },
    opciones:    { type: Array,   default: () => [] },
    modelValue:  { type: Array,   default: () => [] },
})

const emit = defineEmits(['update:modelValue'])

function toggle(valor) {
    const sel = [...(props.modelValue ?? [])]
    const idx = sel.indexOf(valor)
    if (idx >= 0) {
        sel.splice(idx, 1)
    } else {
        sel.push(valor)
    }
    emit('update:modelValue', sel)
}

function isSelected(valor) {
    return (props.modelValue ?? []).includes(valor)
}
</script>

<template>
    <div>
        <label class="block text-xs font-medium text-tinta-700 mb-2">{{ label }}</label>
        <div class="flex flex-wrap gap-1.5">
            <button
                v-for="op in opciones"
                :key="op.valor"
                type="button"
                @click="toggle(op.valor)"
                :class="[
                    'px-3 py-1 rounded-full text-sm border transition-all select-none',
                    isSelected(op.valor)
                        ? 'text-white border-transparent'
                        : 'bg-white text-tinta-500 border-tinta-200 hover:border-gray-400',
                ]"
                :style="isSelected(op.valor) ? `background:${op.color ?? 'var(--marca)'}; border-color:${op.color ?? 'var(--marca)'};` : ''"
            >{{ op.etiqueta }}</button>
            <span v-if="opciones.length === 0" class="text-xs text-tinta-300 italic">Sin opciones configuradas</span>
        </div>
    </div>
</template>
