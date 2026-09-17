<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import SelloBriela from '@/Components/SelloBriela.vue';

const page = usePage();
</script>

<template>
    <div
        class="flex min-h-screen flex-col items-center bg-tinta-100 pt-6 sm:justify-center sm:pt-0"
    >
        <div>
            <Link href="/">
                <img
                    :src="page.props.marca.logo"
                    class="h-12 w-auto object-contain"
                    :alt="page.props.marca.nombre"
                />
            </Link>
        </div>

        <div
            class="mt-6 w-full overflow-hidden bg-superficie px-6 py-4 shadow-md sm:max-w-md sm:rounded-lg"
        >
            <slot />
        </div>

        <footer class="py-8">
            <SelloBriela />
        </footer>
    </div>
</template>
