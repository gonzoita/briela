<script setup>
import { ref, onMounted } from 'vue'
import { router } from '@inertiajs/vue3'

/**
 * Bloque del tablero con lo que espera por mí en el chat: tareas y solicitudes
 * abiertas, mensajes directos sin leer y grupos con novedades.
 *
 * Si no hay nada, **no se pinta**. Un bloque que dice "todo bien" se vuelve
 * ruido y la gente deja de mirarlo — mismo criterio que "Requiere tu atención".
 */
const datos = ref(null)

async function cargar() {
    try {
        const res = await fetch('/api/chat/resumen', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
        if (res.ok) datos.value = await res.json()
    } catch { /* sin resumen, el tablero sigue igual */ }
}

const etiqueta = { solicitud: 'Solicitud', tarea: 'Tarea' }

onMounted(cargar)
</script>

<template>
    <div v-if="datos && datos.total" class="mb-5">
        <p class="text-xs font-semibold text-tinta-300 uppercase tracking-[0.12em] mb-3">
            Del chat del equipo
        </p>

        <div class="space-y-2">
            <!-- Tareas y solicitudes que me asignaron -->
            <div v-for="p in datos.pendientes" :key="'p'+p.id"
                @click="router.visit(p.url)"
                class="flex items-center gap-3 rounded-2xl border p-4 cursor-pointer active:scale-[0.98] transition-transform"
                :class="p.vencida ? 'border-borde-aviso-rojo bg-pastel-rojo/60' : 'border-borde-aviso-ambar bg-pastel-ambar/60'">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0 text-white"
                    :style="`background:${p.vencida ? '#DC2626' : '#D97706'};`">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-tinta-900">
                        {{ etiqueta[p.tipo] }} de {{ p.autor }}
                        <span v-if="p.vencida" class="text-aviso-rojo">· vencida</span>
                        <span v-else-if="p.fecha_limite" class="text-tinta-400 font-normal">· antes del {{ p.fecha_limite }}</span>
                    </p>
                    <p class="text-xs text-tinta-400 truncate">{{ p.contenido }}</p>
                </div>
            </div>

            <!-- Mensajes directos sin leer -->
            <div v-for="d in datos.directos" :key="'d'+d.usuario_id"
                class="flex items-center gap-3 rounded-2xl border border-borde-aviso-verde bg-pastel-verde/60 p-4">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0 text-white font-semibold" style="background:#0F766E;">
                    {{ (d.nombre || '?').charAt(0).toUpperCase() }}
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-tinta-900">
                        {{ d.nombre }}
                        <span class="text-tinta-400 font-normal">· {{ d.cuantos }} sin leer</span>
                    </p>
                    <p class="text-xs text-tinta-400 truncate">{{ d.ultimo }}</p>
                </div>
            </div>

            <!-- Grupos con novedades -->
            <div v-for="g in datos.grupos" :key="'g'+g.id"
                class="flex items-center gap-3 rounded-2xl border border-borde-aviso-verde bg-pastel-verde/60 p-4">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0 text-white font-semibold" style="background:#0F766E;">#</div>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-tinta-900">
                        {{ g.nombre }}
                        <span class="text-tinta-400 font-normal">· {{ g.cuantos }} mensaje(s) nuevo(s)</span>
                    </p>
                </div>
            </div>
        </div>

        <p class="text-[11px] text-tinta-300 mt-2">
            Abre el botón verde de chat, abajo a la derecha, para responder.
        </p>
    </div>
</template>
