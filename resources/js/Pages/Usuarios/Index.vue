<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import { router } from '@inertiajs/vue3'

defineProps({
    usuarios: Object,
})

const rolConfig = {
    administrador:   { label: 'Administrador',     class: 'bg-blue-100 text-blue-800' },
    jefe_produccion: { label: 'Jefe de Producción', class: 'bg-purple-100 text-purple-800' },
    vendedor:        { label: 'Vendedor',           class: 'bg-green-100 text-green-800' },
    operario:        { label: 'Operario',           class: 'bg-tinta-100 text-tinta-700' },
}

const toggleActivo = (usuario) => {
    router.delete(`/usuarios/${usuario.id}`, {
        preserveScroll: true,
    })
}
</script>

<template>
    <AppLayout title="Usuarios">

        <div class="bg-superficie rounded-xl shadow-sm overflow-hidden">

            <!-- Cabecera -->
            <div class="flex items-center justify-between px-6 py-4 border-b border-linea">
                <h2 class="font-semibold text-tinta-900">Gestión de usuarios</h2>
                <a
                    href="/usuarios/create"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-white text-sm font-medium transition-opacity hover:opacity-90"
                    style="background-color: var(--marca);"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                    </svg>
                    Nuevo usuario
                </a>
            </div>

            <!-- Tabla -->
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-tinta-50 text-xs text-tinta-400 uppercase tracking-wide">
                        <tr>
                            <th class="px-6 py-3 text-left">Nombre</th>
                            <th class="px-6 py-3 text-left">Correo</th>
                            <th class="px-6 py-3 text-left">Rol</th>
                            <th class="px-6 py-3 text-center">Estado</th>
                            <th class="px-6 py-3 text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-linea">
                        <tr
                            v-for="u in usuarios.data"
                            :key="u.id"
                            class="hover:bg-tinta-50 transition-colors"
                        >
                            <td class="px-6 py-4 font-medium text-tinta-900">{{ u.name }}</td>
                            <td class="px-6 py-4 text-tinta-500">
                                <div>{{ u.email }}</div>
                                <div v-if="u.telefono" class="text-xs text-tinta-300 mt-0.5">{{ u.telefono }}</div>
                            </td>
                            <td class="px-6 py-4">
                                <span
                                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                                    :class="rolConfig[u.rol]?.class ?? 'bg-tinta-100 text-tinta-700'"
                                >
                                    {{ u.rol_nombre ?? rolConfig[u.rol]?.label ?? u.rol }}
                                </span>
                                <div v-if="u.sede" class="text-xs text-tinta-300 mt-1">{{ u.sede }}</div>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <span
                                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                                    :class="u.activo ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-700'"
                                >
                                    {{ u.activo ? 'Activo' : 'Inactivo' }}
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center justify-center gap-3">
                                    <a
                                        :href="`/usuarios/${u.id}/edit`"
                                        class="text-xs font-medium hover:underline"
                                        style="color: var(--marca);"
                                    >
                                        Editar
                                    </a>
                                    <button
                                        v-if="u.activo"
                                        @click="toggleActivo(u)"
                                        class="text-xs font-medium text-red-600 hover:underline"
                                    >
                                        Desactivar
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!usuarios.data?.length">
                            <td colspan="5" class="px-6 py-10 text-center text-tinta-300">
                                No hay usuarios registrados.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Paginación -->
            <div v-if="usuarios.last_page > 1" class="flex items-center justify-between px-6 py-4 border-t border-linea">
                <p class="text-sm text-tinta-400">
                    Mostrando {{ usuarios.from }}–{{ usuarios.to }} de {{ usuarios.total }} usuarios
                </p>
                <div class="flex gap-1">
                    <a
                        v-for="link in usuarios.links"
                        :key="link.label"
                        :href="link.url ?? '#'"
                        v-html="link.label"
                        class="px-3 py-1.5 rounded text-sm transition-colors"
                        :class="link.active
                            ? 'text-white'
                            : 'text-tinta-500 hover:bg-tinta-100'"
                        :style="link.active ? 'background-color: var(--marca);' : ''"
                        @click.prevent="link.url && router.visit(link.url)"
                    />
                </div>
            </div>

        </div>
    </AppLayout>
</template>
