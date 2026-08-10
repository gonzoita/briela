<script setup>
import { onMounted, ref } from 'vue';

const model = defineModel({
    type: String,
    required: true,
});

const input = ref(null);

onMounted(() => {
    if (input.value.hasAttribute('autofocus')) {
        input.value.focus();
    }
});

defineExpose({ focus: () => input.value.focus() });
</script>

<template>
    <input
        class="rounded-lg border-tinta-200 shadow-sm focus:border-[var(--marca)] focus:ring-[var(--marca-suave)]"
        v-model="model"
        ref="input"
    />
</template>
