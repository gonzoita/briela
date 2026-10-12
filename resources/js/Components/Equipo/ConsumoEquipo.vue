<script setup>
/**
 * Lo que gastó cada agente, en tokens. El dinero lo lleva el panel de Briela, que es quien
 * factura: aquí no se calcula un costo que podría no coincidir con el cobro.
 */
import { ref, computed, onMounted, watch } from 'vue'
import { get } from '@/composables/useEquipoIa'

const dias  = ref(30)
const datos = ref(null)

async function cargar() {
    const r = await get(`/api/equipo-ia/consumo?dias=${dias.value}`)
    if (r.ok) datos.value = r.data
}

onMounted(cargar)
watch(dias, cargar)

const fmt = (n) => new Intl.NumberFormat('es-CO').format(n)

const totales = computed(() => {
    const d = datos.value
    if (!d) return { llamadas: 0, tokens: 0 }
    return {
        llamadas: d.por_agente.reduce((s, a) => s + a.llamadas, 0),
        tokens:   d.por_agente.reduce((s, a) => s + a.entrada + a.salida, 0),
    }
})

/** Tokens por día, separados por quién gastó: así se ve si un agente se está comiendo al asistente. */
const porDia = computed(() => {
    const mapa = {}
    for (const f of datos.value?.por_dia ?? []) {
        mapa[f.fecha] ??= { fecha: f.fecha, asistente: 0, agentes: 0 }
        mapa[f.fecha][f.origen === 'agentes' ? 'agentes' : 'asistente'] += f.tokens
    }
    return Object.values(mapa).sort((a, b) => b.fecha.localeCompare(a.fecha))
})
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between gap-3 flex-wrap">
            <p class="text-xs text-tinta-400 max-w-xl">
                Son tokens, no dinero: el costo y el cobro los lleva el panel de Briela, y los agentes tienen un cupo
                diario aparte del asistente.
            </p>
            <select v-model.number="dias" class="border border-linea rounded-xl px-3 py-1.5 text-sm bg-superficie">
                <option :value="7">Últimos 7 días</option>
                <option :value="30">Últimos 30 días</option>
                <option :value="90">Últimos 90 días</option>
            </select>
        </div>

        <p v-if="! datos" class="text-sm text-tinta-300 text-center py-10">Cargando…</p>

        <template v-else>
            <div class="grid grid-cols-2 gap-3">
                <div class="bg-superficie border border-linea rounded-2xl p-4">
                    <p class="text-[11px] text-tinta-400 uppercase tracking-[0.12em]">Llamadas de agentes</p>
                    <p class="text-2xl font-semibold text-tinta-800 mt-1">{{ fmt(totales.llamadas) }}</p>
                </div>
                <div class="bg-superficie border border-linea rounded-2xl p-4">
                    <p class="text-[11px] text-tinta-400 uppercase tracking-[0.12em]">Tokens de agentes</p>
                    <p class="text-2xl font-semibold text-tinta-800 mt-1">{{ fmt(totales.tokens) }}</p>
                </div>
            </div>

            <div class="bg-superficie border border-linea rounded-2xl overflow-x-auto">
                <table class="w-full text-sm min-w-[32rem]">
                    <thead>
                        <tr class="text-left text-[11px] text-tinta-400 uppercase tracking-[0.1em] border-b border-linea">
                            <th class="p-3 font-medium">Agente</th>
                            <th class="p-3 font-medium text-right">Llamadas</th>
                            <th class="p-3 font-medium text-right">Entrada</th>
                            <th class="p-3 font-medium text-right">Salida</th>
                            <th class="p-3 font-medium text-right">Fallidas</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-separador">
                        <tr v-for="a in datos.por_agente" :key="a.agente" class="hover:bg-realce">
                            <td class="p-3 text-tinta-700">{{ a.agente }}</td>
                            <td class="p-3 text-right text-tinta-600">{{ fmt(a.llamadas) }}</td>
                            <td class="p-3 text-right text-tinta-600">{{ fmt(a.entrada) }}</td>
                            <td class="p-3 text-right text-tinta-600">{{ fmt(a.salida) }}</td>
                            <td :class="['p-3 text-right', a.fallidas ? 'text-aviso-rojo font-semibold' : 'text-tinta-300']">{{ a.fallidas }}</td>
                        </tr>
                        <tr v-if="! datos.por_agente.length">
                            <td colspan="5" class="p-6 text-center text-tinta-300">Los agentes todavía no han gastado nada en este periodo.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="bg-superficie border border-linea rounded-2xl overflow-x-auto">
                <table class="w-full text-sm min-w-[24rem]">
                    <thead>
                        <tr class="text-left text-[11px] text-tinta-400 uppercase tracking-[0.1em] border-b border-linea">
                            <th class="p-3 font-medium">Día</th>
                            <th class="p-3 font-medium text-right">Asistente</th>
                            <th class="p-3 font-medium text-right">Agentes</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-separador">
                        <tr v-for="d in porDia" :key="d.fecha" class="hover:bg-realce">
                            <td class="p-3 text-tinta-700">{{ d.fecha }}</td>
                            <td class="p-3 text-right text-tinta-600">{{ fmt(d.asistente) }}</td>
                            <td class="p-3 text-right text-tinta-600">{{ fmt(d.agentes) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
    </div>
</template>
