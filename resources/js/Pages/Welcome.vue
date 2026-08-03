<script setup>
/**
 * Sobra: la ruta / redirige a /dashboard o a /login, así que esta página no
 * se renderiza nunca.
 *
 * Se deja como archivo vacío en vez de borrarla porque el resolve de Inertia
 * usa import.meta.glob con eager: true — todo lo que esté en Pages/ entra al
 * bundle. Antes esto traía la página de bienvenida de Laravel completa, con
 * su publicidad y sus enlaces a la documentación, cargando en cada visita.
 *
 * Se puede borrar el archivo sin problema.
 */
import { onMounted } from 'vue'
import { router } from '@inertiajs/vue3'

onMounted(() => router.visit('/dashboard'))
</script>

<template>
    <div></div>
</template>
