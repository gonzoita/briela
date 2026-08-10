<script setup>
defineProps({
    type: {
        type: String,
        default: 'button',
    },
});
</script>

<template>
    <button
        :type="type"
        class="inline-flex items-center rounded-lg border border-tinta-200 bg-superficie px-4 py-2 text-sm font-semibold text-tinta-700 shadow-sm transition duration-150 ease-in-out hover:bg-tinta-50 focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)] focus:ring-offset-2 disabled:opacity-25"
    >
        <slot />
    </button>
</template>
