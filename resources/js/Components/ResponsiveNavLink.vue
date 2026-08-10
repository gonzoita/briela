<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    href: {
        type: String,
        required: true,
    },
    active: {
        type: Boolean,
    },
});

const classes = computed(() =>
    props.active
        ? 'block w-full ps-3 pe-4 py-2 border-l-4 border-[var(--marca)] text-start text-base font-medium text-[var(--marca)] bg-[var(--marca-suave)] focus:outline-none focus:text-[var(--marca)] focus:bg-[var(--marca-suave)] focus:border-[var(--marca)] transition duration-150 ease-in-out'
        : 'block w-full ps-3 pe-4 py-2 border-l-4 border-transparent text-start text-base font-medium text-tinta-500 hover:text-tinta-900 hover:bg-tinta-50 hover:border-tinta-200 focus:outline-none focus:text-tinta-900 focus:bg-tinta-50 focus:border-tinta-200 transition duration-150 ease-in-out',
);
</script>

<template>
    <Link :href="href" :class="classes">
        <slot />
    </Link>
</template>
