<script setup>
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

defineProps({
    cuentas: Array,
})

const redes = [
    { key: 'meta',     label: 'Instagram / Facebook', icon: '📷', descripcion: 'Conecta tu página de Facebook (e Instagram, si está ligado a ella). No requiere aprobación de Meta.' },
    { key: 'linkedin',  label: 'LinkedIn (página de empresa)', icon: '💼', descripcion: 'Requiere que LinkedIn haya aprobado el acceso a la Community Management API para esta app.' },
    { key: 'google',    label: 'Google Business Profile', icon: '📍', descripcion: 'Requiere que Google haya aprobado el acceso a la Business Profile API.' },
]

const redLabel = { instagram: 'Instagram', facebook: 'Facebook', linkedin: 'LinkedIn', google_business: 'Google Business Profile' }

function conectar(red) {
    router.visit(`/rrss/cuentas/conectar/${red}`)
}

function desconectar(c) {
    if (!confirm(`¿Desconectar "${c.nombre_cuenta}"?`)) return
    router.delete(`/rrss/cuentas/${c.id}`, { preserveScroll: true })
}

function reactivar(c) {
    router.post(`/rrss/cuentas/${c.id}/reactivar`, {}, { preserveScroll: true })
}
</script>

<template>
    <AppLayout title="Cuentas de Redes Sociales">
        <div class="max-w-2xl mx-auto px-4 py-4">

            <div class="flex items-center gap-3 mb-5">
                <button @click="router.visit('/rrss')" class="p-2 rounded-xl hover:bg-gray-100 text-gray-500">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                    </svg>
                </button>
                <h1 class="text-xl font-bold text-gray-900">Cuentas conectadas</h1>
            </div>

            <!-- Conectar nuevas -->
            <div class="bg-white rounded-2xl border border-gray-200 p-5 mb-4">
                <h2 class="text-sm font-semibold text-gray-700 mb-3">Conectar una cuenta</h2>
                <div class="space-y-2">
                    <button v-for="r in redes" :key="r.key" @click="conectar(r.key)"
                        class="w-full flex items-center gap-3 p-3 rounded-xl border border-gray-200 hover:bg-gray-50 text-left">
                        <span class="text-xl">{{ r.icon }}</span>
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-800">{{ r.label }}</p>
                            <p class="text-xs text-gray-400">{{ r.descripcion }}</p>
                        </div>
                    </button>
                </div>
            </div>

            <!-- Lista de conectadas -->
            <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100">
                    <h2 class="text-sm font-semibold text-gray-700">Cuentas</h2>
                </div>

                <div v-if="!cuentas.length" class="py-10 text-center text-sm text-gray-400">
                    Sin cuentas conectadas todavía.
                </div>

                <div class="divide-y divide-gray-50">
                    <div v-for="c in cuentas" :key="c.id" class="flex items-center gap-3 px-4 py-3">
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-800 truncate">{{ c.nombre_cuenta }}</p>
                            <p class="text-xs text-gray-400 truncate">
                                {{ redLabel[c.red] ?? c.red }}
                                <span v-if="c.ultima_publicacion_en"> · última pub.: {{ new Date(c.ultima_publicacion_en).toLocaleDateString('es-CO') }}</span>
                            </p>
                            <p v-if="c.ultimo_error" class="text-xs text-red-500 truncate mt-0.5" :title="c.ultimo_error">
                                ⚠ {{ c.ultimo_error }}
                            </p>
                        </div>
                        <span class="text-xs px-2 py-0.5 rounded-full shrink-0"
                            :class="c.activa ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'">
                            {{ c.activa ? 'Activa' : 'Inactiva' }}
                        </span>
                        <button v-if="c.activa" @click="desconectar(c)" class="text-xs text-red-500 hover:underline shrink-0">Desconectar</button>
                        <button v-else @click="reactivar(c)" class="text-xs text-blue-600 hover:underline shrink-0">Reactivar</button>
                    </div>
                </div>
            </div>

        </div>
    </AppLayout>
</template>
