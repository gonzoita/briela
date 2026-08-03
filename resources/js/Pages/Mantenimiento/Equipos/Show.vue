<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    equipo: Object,
})

const tab = ref('info')

const estadoBadge = {
    activo:          { label: 'Activo',           class: 'bg-green-100 text-green-700' },
    en_mantenimiento:{ label: 'En mantenimiento',  class: 'bg-amber-100 text-amber-700' },
    fuera_servicio:  { label: 'Fuera de servicio', class: 'bg-red-100 text-red-700'    },
}

const tipoBadge = {
    preventivo: 'bg-blue-100 text-blue-700',
    correctivo: 'bg-red-100 text-red-700',
    predictivo: 'bg-purple-100 text-purple-700',
}

const fmt = (n) => Number(n || 0).toLocaleString('es-CO')

function nuevo() {
    router.visit(`/mantenimiento/mantenimientos/crear?equipo_id=${props.equipo.id}`)
}
</script>

<template>
    <AppLayout :title="equipo.nombre">
        <div class="max-w-3xl mx-auto">

            <div class="flex items-center gap-3 mb-5">
                <a href="/mantenimiento/equipos" @click.prevent="router.visit('/mantenimiento/equipos')"
                    class="text-gray-400 hover:text-gray-700">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                    </svg>
                </a>
                <div class="flex-1 min-w-0">
                    <h1 class="text-xl font-bold text-gray-900 truncate">{{ equipo.nombre }}</h1>
                    <p class="text-xs text-gray-400">{{ equipo.codigo ?? '—' }} · {{ equipo.tipo ?? '' }}</p>
                </div>
                <span class="text-xs px-2 py-0.5 rounded-full font-medium shrink-0"
                    :class="estadoBadge[equipo.estado]?.class">
                    {{ estadoBadge[equipo.estado]?.label }}
                </span>
            </div>

            <!-- Tabs -->
            <div class="flex gap-1 bg-gray-100 p-1 rounded-2xl mb-5">
                <button @click="tab = 'info'"
                    class="flex-1 py-2 rounded-xl text-sm font-semibold transition-colors"
                    :class="tab === 'info' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500'">
                    Info
                </button>
                <button @click="tab = 'historial'"
                    class="flex-1 py-2 rounded-xl text-sm font-semibold transition-colors"
                    :class="tab === 'historial' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500'">
                    Historial ({{ equipo.mantenimientos?.length ?? 0 }})
                </button>
            </div>

            <!-- INFO -->
            <div v-show="tab === 'info'" class="space-y-4">
                <div class="bg-white rounded-2xl border border-gray-200 p-5">
                    <h2 class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3">Datos del equipo</h2>
                    <div class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                        <div v-for="[label, val] in [
                            ['Marca', equipo.marca],
                            ['Modelo', equipo.modelo],
                            ['Serial', equipo.serial],
                            ['Ubicación', equipo.ubicacion],
                            ['Responsable', equipo.responsable],
                            ['Inst. desde', equipo.fecha_instalacion],
                            ['Frec. mantenimiento', equipo.frecuencia_dias + ' días'],
                            ['Próxima revisión', equipo.proxima_revision],
                        ]" :key="label">
                            <div v-if="val">
                                <p class="text-xs text-gray-400">{{ label }}</p>
                                <p class="font-medium text-gray-800 mt-0.5">{{ val }}</p>
                            </div>
                        </div>
                    </div>
                    <!-- Estación de trabajo -->
                    <div class="mt-4 pt-4 border-t border-gray-100">
                        <p class="text-xs text-gray-400 mb-1.5">Estación de trabajo</p>
                        <div v-if="equipo.estacion" class="flex items-center gap-2">
                            <div class="w-3 h-3 rounded-full shrink-0"
                                :style="{ background: equipo.estacion.color }"></div>
                            <span class="text-sm font-medium text-gray-800">{{ equipo.estacion.nombre }}</span>
                            <span class="text-xs px-2 py-0.5 rounded-full"
                                :class="equipo.estacion.activa ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'">
                                {{ equipo.estacion.activa ? 'Activa' : 'Inactiva' }}
                            </span>
                        </div>
                        <span v-else class="text-sm text-gray-400 italic">Sin estación asignada</span>
                    </div>

                    <div v-if="equipo.observaciones" class="mt-4 pt-4 border-t border-gray-100">
                        <p class="text-xs text-gray-400 mb-1">Observaciones</p>
                        <p class="text-sm text-gray-700">{{ equipo.observaciones }}</p>
                    </div>
                </div>

                <!-- Componentes -->
                <div v-if="equipo.componentes?.length" class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-100">
                        <h2 class="text-sm font-semibold text-gray-700">Componentes ({{ equipo.componentes.length }})</h2>
                    </div>
                    <div class="divide-y divide-gray-50">
                        <div v-for="c in equipo.componentes" :key="c.id" class="flex items-center px-5 py-3 gap-3">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-800">{{ c.nombre }}</p>
                                <p class="text-xs text-gray-400">{{ c.referencia ?? '—' }} · {{ c.unidad }}</p>
                            </div>
                            <span class="text-sm font-semibold text-gray-600 shrink-0">x{{ c.cantidad }}</span>
                        </div>
                    </div>
                </div>

                <div class="flex gap-3">
                    <button @click="nuevo"
                        class="flex-1 py-3 rounded-xl text-white text-sm font-semibold"
                        style="background:var(--marca);">
                        + Nuevo mantenimiento
                    </button>
                    <a href="#" @click.prevent="router.visit(`/mantenimiento/equipos/${equipo.id}/editar`)"
                        class="flex-1 py-3 rounded-xl border border-gray-200 text-sm text-gray-600 font-medium text-center">
                        Editar equipo
                    </a>
                </div>
            </div>

            <!-- HISTORIAL -->
            <div v-show="tab === 'historial'" class="space-y-3">
                <button @click="nuevo"
                    class="w-full py-3 rounded-xl text-white text-sm font-semibold"
                    style="background:var(--marca);">
                    + Registrar mantenimiento
                </button>

                <div v-if="!equipo.mantenimientos?.length" class="bg-white rounded-2xl border border-gray-200 py-10 text-center text-sm text-gray-400">
                    Sin mantenimientos registrados.
                </div>

                <div v-else class="space-y-3">
                    <div v-for="m in equipo.mantenimientos" :key="m.id"
                        class="bg-white rounded-2xl border border-gray-200 p-4 hover:border-blue-200 cursor-pointer"
                        @click="router.visit(`/mantenimiento/mantenimientos/${m.id}`)">
                        <div class="flex items-center gap-3 mb-2">
                            <span class="text-xs px-2 py-0.5 rounded-full font-medium"
                                :class="tipoBadge[m.tipo] ?? 'bg-gray-100 text-gray-600'">
                                {{ m.tipo }}
                            </span>
                            <span class="text-xs px-2 py-0.5 rounded-full font-medium"
                                :class="m.estado === 'completado' ? 'bg-green-100 text-green-700'
                                    : m.estado === 'en_proceso' ? 'bg-blue-100 text-blue-700'
                                    : 'bg-gray-100 text-gray-500'">
                                {{ m.estado }}
                            </span>
                            <span class="ml-auto text-xs text-gray-400">{{ m.fecha_programada }}</span>
                        </div>
                        <p v-if="m.descripcion" class="text-sm text-gray-600 line-clamp-2">{{ m.descripcion }}</p>
                        <p class="text-xs text-gray-400 mt-1">
                            Costo: ${{ fmt(m.costo_total) }}
                            <span v-if="m.ejecutor_nombre"> · {{ m.ejecutor_nombre }}</span>
                        </p>
                    </div>
                </div>
            </div>

        </div>
    </AppLayout>
</template>
