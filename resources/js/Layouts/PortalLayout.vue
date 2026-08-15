<script setup>
import { Head, router } from '@inertiajs/vue3'

const props = defineProps({
    title: { type: String, default: 'Mi Capacitación' },
    esColaborador: { type: Boolean, default: false },
})

function salir() {
    if (props.esColaborador) {
        router.visit('/dashboard')
    } else {
        router.post('/portal-capacitacion/logout')
    }
}
</script>

<template>
    <Head :title="title" />

    <div class="min-h-screen" style="background-color: var(--superficie-2);">
        <header class="sticky top-0 z-30 flex items-center justify-between px-4 md:px-8 h-16 bg-superficie shadow-sm">
            <div class="flex items-center gap-3 min-w-0">
                <img
                    :src="$page.props.marca.logo"
                    class="h-8 w-auto object-contain shrink-0"
                    :alt="$page.props.marca.nombre"
                />
                <span class="text-sm md:text-base font-semibold text-tinta-900 truncate">{{ title }}</span>
            </div>
            <button @click="salir"
                class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl text-sm font-medium text-tinta-400 hover:bg-tinta-100 transition-colors shrink-0">
                <svg v-if="esColaborador" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                </svg>
                <span class="hidden sm:inline">{{ esColaborador ? 'Volver a Briela' : 'Cerrar sesión' }}</span>
            </button>
        </header>

        <main class="px-4 md:px-8 py-6 max-w-4xl mx-auto">
            <slot />
        </main>
    </div>
</template>
