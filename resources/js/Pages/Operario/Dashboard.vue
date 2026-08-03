<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'

const props = defineProps({
    sin_perfil:          { type: Boolean, default: false },
    operario:            { type: Object,  default: null  },
    metricas:            { type: Object,  default: null  },
    trabajos_activos:    { type: Array,   default: () => [] },
    trabajos_terminados: { type: Array,   default: () => [] },
    puntos_totales:      { type: Number,  default: 0 },
    nivel_actual:        { type: Object,  default: null },
    nivel_siguiente:     { type: Object,  default: null },
    puntos_semanales:    { type: Number,  default: 0 },
    posicion_ranking:    { type: Number,  default: null },
    historial_puntos:    { type: Array,   default: () => [] },
    trabajos_hoy:        { type: Array,   default: () => [] },
})

function formatTiempo(minutos) {
    if (!minutos) return '0 min'
    if (minutos < 60) return `${minutos} min`
    const h = Math.floor(minutos / 60)
    const m = minutos % 60
    return m > 0 ? `${h}h ${m}min` : `${h}h`
}

const inicial = computed(() => props.operario?.nombre?.[0]?.toUpperCase() ?? '?')

const progresoNivel = computed(() => {
    if (!props.nivel_actual || !props.nivel_siguiente) return 100
    const base  = props.nivel_actual.puntos_minimos
    const meta  = props.nivel_siguiente.puntos_minimos
    const actual = props.puntos_totales
    return Math.min(100, Math.round((actual - base) / (meta - base) * 100))
})

function nivelEmoji(nombre) {
    return { Bronce: '🥉', Plata: '🥈', Oro: '🥇', Diamante: '💎' }[nombre] ?? '⭐'
}
</script>

<template>
    <AppLayout title="Mi Panel">

        <!-- ── Sin perfil ──────────────────────────────────────────────────── -->
        <div v-if="sin_perfil" class="flex flex-col items-center justify-center min-h-[60vh] gap-4 text-center px-4">
            <div class="w-16 h-16 rounded-full bg-orange-100 flex items-center justify-center">
                <svg class="w-8 h-8 text-orange-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
            </div>
            <div>
                <h2 class="text-lg font-bold text-gray-800">Sin perfil de operario</h2>
                <p class="text-sm text-gray-500 mt-1">Tu usuario no tiene un perfil de operario asociado.<br>Contacta al administrador.</p>
            </div>
        </div>

        <template v-else>

            <!-- ── Tarjeta perfil ────────────────────────────────────────────── -->
            <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm mb-4">
                <div class="flex items-center gap-4">
                    <div class="w-14 h-14 rounded-full flex items-center justify-center text-xl font-bold text-white shrink-0"
                        style="background-color:var(--marca);">
                        {{ inicial }}
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-base font-bold text-gray-900 truncate">{{ operario?.nombre }}</h1>
                        <p v-if="operario?.especialidad" class="text-sm text-gray-500 truncate">{{ operario?.especialidad }}</p>
                        <p class="text-xs text-gray-400 mt-0.5">Doc: {{ operario?.documento }}</p>
                    </div>
                </div>
            </div>

            <!-- ── Puntos y Nivel ──────────────────────────────────────────────── -->
            <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm mb-4">

                <!-- Nivel actual + ranking -->
                <div class="flex items-center gap-4 mb-4">
                    <div class="w-14 h-14 rounded-full flex items-center justify-center text-2xl shrink-0"
                         :style="{ background: (nivel_actual?.color ?? '#CD7F32') + '20' }">
                        {{ nivelEmoji(nivel_actual?.nombre) }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs text-gray-400 uppercase tracking-wide">Nivel actual</p>
                        <p class="text-xl font-bold" :style="{ color: nivel_actual?.color ?? '#CD7F32' }">
                            {{ nivel_actual?.nombre ?? 'Bronce' }}
                        </p>
                        <p class="text-sm text-gray-500">{{ puntos_totales }} puntos acumulados</p>
                    </div>
                    <div v-if="posicion_ranking" class="text-center shrink-0">
                        <p class="text-3xl font-black" style="color:var(--marca)">#{{ posicion_ranking }}</p>
                        <p class="text-xs text-gray-400">esta semana</p>
                        <p class="text-xs font-medium" style="color:var(--marca)">{{ puntos_semanales }} pts</p>
                    </div>
                </div>

                <!-- Progreso hacia siguiente nivel -->
                <div v-if="nivel_siguiente">
                    <div class="flex justify-between text-xs text-gray-500 mb-1">
                        <span>{{ nivel_actual?.nombre }}</span>
                        <span>{{ nivel_siguiente.nombre }} en {{ nivel_siguiente.puntos_minimos - puntos_totales }} pts</span>
                    </div>
                    <div class="w-full rounded-full h-3 bg-gray-100">
                        <div class="h-3 rounded-full transition-all duration-700"
                             :style="{
                                 width: progresoNivel + '%',
                                 background: nivel_actual?.color ?? '#CD7F32',
                             }"></div>
                    </div>
                    <p class="text-xs text-gray-400 mt-1 text-right">
                        {{ progresoNivel }}% hacia {{ nivel_siguiente.nombre }}
                    </p>
                </div>
                <div v-else class="text-center py-2">
                    <p class="text-sm font-medium" style="color:#FFD700">💎 ¡Nivel máximo alcanzado!</p>
                </div>
            </div>

            <!-- ── Trabajos programados hoy ─────────────────────────────────── -->
            <div v-if="trabajos_hoy.length" class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm mb-4">
                <p class="text-sm font-semibold text-gray-700 mb-3">📋 Mis trabajos de hoy</p>
                <div class="space-y-2">
                    <div v-for="(t, idx) in trabajos_hoy" :key="idx"
                         class="flex items-center gap-3 p-3 rounded-xl bg-blue-50 border border-blue-100">
                        <div class="w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold text-white shrink-0"
                             style="background:var(--marca)">
                            {{ idx + 1 }}
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-800 truncate">{{ t.nombre }}</p>
                            <p class="text-xs text-gray-500">
                                {{ t.op }}
                                <span v-if="t.estacion"> · {{ t.estacion }}</span>
                                <span v-if="t.tiempo"> · {{ t.tiempo }} min</span>
                            </p>
                        </div>
                        <span class="text-xs px-2 py-1 rounded-full bg-blue-100 text-blue-700 font-medium shrink-0">
                            Pendiente
                        </span>
                    </div>
                </div>
            </div>

            <!-- ── Historial de puntos recientes ──────────────────────────── -->
            <div v-if="historial_puntos.length" class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm mb-4">
                <p class="text-sm font-semibold text-gray-700 mb-3">⭐ Mis últimos puntos</p>
                <div class="space-y-2">
                    <div v-for="(p, idx) in historial_puntos" :key="idx"
                         class="flex items-center justify-between py-2 border-b border-gray-50 last:border-0">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm text-gray-700 truncate">{{ p.concepto }}</p>
                            <p class="text-xs text-gray-400">{{ p.created_at }}</p>
                        </div>
                        <span class="font-bold text-sm px-2 py-0.5 rounded-full shrink-0 ml-3"
                              :class="p.puntos > 0 ? 'text-green-600 bg-green-50' : 'text-red-500 bg-red-50'">
                            {{ p.puntos > 0 ? '+' : '' }}{{ p.puntos }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- ── Métricas 2×2 ─────────────────────────────────────────────── -->
            <div class="grid grid-cols-2 gap-3 mb-4">
                <div class="bg-white rounded-2xl border border-gray-200 p-4 shadow-sm">
                    <p class="text-xs text-gray-500 font-medium">Pasos completados</p>
                    <p class="text-2xl font-bold mt-1" style="color:var(--marca);">
                        {{ metricas?.pasos_completados ?? 0 }}
                    </p>
                </div>
                <div class="bg-white rounded-2xl border border-gray-200 p-4 shadow-sm">
                    <p class="text-xs text-gray-500 font-medium">Tiempo registrado</p>
                    <p class="text-2xl font-bold mt-1 text-gray-800">
                        {{ formatTiempo(metricas?.tiempo_total_minutos) }}
                    </p>
                </div>
                <div class="bg-white rounded-2xl border border-gray-200 p-4 shadow-sm">
                    <p class="text-xs text-gray-500 font-medium">Trabajos activos</p>
                    <p class="text-2xl font-bold mt-1 text-amber-600">
                        {{ metricas?.trabajos_activos ?? 0 }}
                    </p>
                </div>
                <div class="bg-white rounded-2xl border border-gray-200 p-4 shadow-sm">
                    <p class="text-xs text-gray-500 font-medium">Terminados</p>
                    <p class="text-2xl font-bold mt-1 text-green-600">
                        {{ metricas?.trabajos_terminados ?? 0 }}
                    </p>
                </div>
            </div>

            <!-- ── Trabajos activos ──────────────────────────────────────────── -->
            <div v-if="trabajos_activos?.length"
                class="bg-white rounded-2xl border border-gray-200 overflow-hidden shadow-sm mb-4">
                <div class="px-4 py-3 border-b border-gray-100">
                    <h2 class="text-sm font-semibold text-gray-700">Trabajos activos</h2>
                </div>
                <div class="divide-y divide-gray-50">
                    <button
                        v-for="t in trabajos_activos"
                        :key="t.id"
                        @click="router.visit(`/trabajo/${t.token}`)"
                        class="w-full text-left px-4 py-3 hover:bg-gray-50 transition-colors"
                    >
                        <div class="flex items-start justify-between gap-2 mb-1.5">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-gray-800 truncate">
                                    {{ t.op_numero }} — U{{ t.numero_unidad }}/{{ t.total_unidades }}
                                </p>
                                <p v-if="t.item_descripcion" class="text-xs text-gray-500 truncate mt-0.5">
                                    {{ t.item_descripcion }}
                                </p>
                            </div>
                            <span class="text-xs font-bold shrink-0 mt-0.5" style="color:var(--marca);">
                                {{ Math.round(t.porcentaje_avance) }}%
                            </span>
                        </div>
                        <!-- Barra de progreso -->
                        <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden mb-2">
                            <div class="h-full rounded-full transition-all"
                                :style="`width:${Math.min(t.porcentaje_avance, 100)}%; background:var(--marca);`" />
                        </div>
                        <!-- Dots de pasos -->
                        <div class="flex gap-1 flex-wrap">
                            <div
                                v-for="idx in t.pasos_total"
                                :key="idx"
                                class="w-2 h-2 rounded-full"
                                :class="idx <= t.pasos_completados ? 'bg-green-500' : 'bg-gray-200'"
                            />
                        </div>
                    </button>
                </div>
            </div>

            <!-- Sin trabajos activos -->
            <div v-else class="bg-white rounded-2xl border border-gray-200 p-6 text-center shadow-sm mb-4">
                <p class="text-sm text-gray-500">No tienes trabajos activos asignados.</p>
            </div>

            <!-- ── Trabajos terminados ────────────────────────────────────────── -->
            <div v-if="trabajos_terminados?.length"
                class="bg-white rounded-2xl border border-gray-200 overflow-hidden shadow-sm mb-4">
                <div class="px-4 py-3 border-b border-gray-100">
                    <h2 class="text-sm font-semibold text-gray-700">Terminados</h2>
                </div>
                <div class="divide-y divide-gray-50">
                    <button
                        v-for="t in trabajos_terminados"
                        :key="t.id"
                        @click="router.visit(`/trabajo/${t.token}`)"
                        class="w-full text-left px-4 py-3 hover:bg-gray-50 transition-colors opacity-60"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-600 truncate">
                                    {{ t.op_numero }} — U{{ t.numero_unidad }}/{{ t.total_unidades }}
                                </p>
                                <p v-if="t.item_descripcion" class="text-xs text-gray-400 truncate mt-0.5">
                                    {{ t.item_descripcion }}
                                </p>
                            </div>
                            <span class="text-xs text-green-600 font-semibold shrink-0">Completo</span>
                        </div>
                    </button>
                </div>
            </div>

        </template>
    </AppLayout>
</template>
