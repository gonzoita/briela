<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'

const props = defineProps({
    token:               String,
    rankingSemanal:      Array,
    opsActivas:          Array,
    pasosHoy:            Array,
    topHoy:              Object,
    trabajosProgramados: Array,
    hora:                String,
    fecha:               String,
})

const horaActual = ref(props.hora)
const ranking    = ref(props.rankingSemanal)
const ops        = ref(props.opsActivas)
const trabajos   = ref(props.trabajosProgramados)
const tickerIdx  = ref(0)
let intervalo        = null
let tickerIntervalo  = null

const mensajesMotivacion = [
    '🏆 ¡El equipo Interfrigo es el mejor!',
    '💪 ¡Cada paso cuenta, cada minuto importa!',
    '⭐ ¡La calidad es nuestra firma!',
    '🚀 ¡Juntos llegamos más lejos!',
    '🎯 ¡Enfoque, precisión, excelencia!',
]

const mensajeTicker = computed(() => mensajesMotivacion[tickerIdx.value])

async function refrescar() {
    try {
        const res  = await fetch(`/planta/${props.token}/datos`)
        const data = await res.json()
        ranking.value  = data.rankingSemanal
        ops.value      = data.opsActivas
        trabajos.value = data.trabajosProgramados ?? []
        horaActual.value = data.hora
    } catch (e) {
        console.error('Error refrescando pantalla:', e)
    }
}

onMounted(() => {
    intervalo       = setInterval(refrescar, 30000)
    tickerIntervalo = setInterval(() => {
        tickerIdx.value = (tickerIdx.value + 1) % mensajesMotivacion.length
    }, 5000)
})

onUnmounted(() => {
    clearInterval(intervalo)
    clearInterval(tickerIntervalo)
})

function nivelColor(nivel) {
    return { Bronce: '#CD7F32', Plata: '#C0C0C0', Oro: '#FFD700', Diamante: '#00B4D8' }[nivel] ?? '#8BA3C1'
}

function nivelEmoji(nivel) {
    return { Bronce: '🥉', Plata: '🥈', Oro: '🥇', Diamante: '💎' }[nivel] ?? '⭐'
}

function pctColor(pct) {
    if (pct >= 100) return '#00C853'
    if (pct >= 60)  return '#00B4D8'
    if (pct >= 30)  return '#FFB300'
    return '#F44336'
}
</script>

<template>
<div class="min-h-screen flex flex-col"
     style="background:#061830; font-family:'Inter',sans-serif; color:#fff;">

    <!-- HEADER -->
    <div class="flex items-center justify-between px-8 py-4 border-b"
         style="background:#0D2E5A; border-color:var(--marca);">
        <div class="flex items-center gap-4">
            <img src="https://interfrigo.com.co/wp-content/uploads/2024/11/cropped-Diseno-sin-titulo-15.png"
                 alt="Interfrigo"
                 class="h-12 object-contain"
                 onerror="this.style.display='none'" />
            <div>
                <h1 class="text-2xl font-bold" style="color:#00B4D8;">
                    Planta de Producción
                </h1>
                <p class="text-sm capitalize" style="color:#8BA3C1;">{{ fecha }}</p>
            </div>
        </div>
        <div class="text-right">
            <p class="text-6xl font-bold tabular-nums" style="color:#00B4D8;">
                {{ horaActual }}
            </p>
        </div>
    </div>

    <!-- TICKER MOTIVACIONAL -->
    <div class="px-8 py-2 text-center text-sm font-medium transition-all duration-500"
         style="background:var(--marca); color:#FFB300;">
        {{ mensajeTicker }}
    </div>

    <!-- CONTENIDO PRINCIPAL -->
    <div class="flex-1 grid p-6 gap-6"
         style="grid-template-columns: 300px 1fr 280px;">

        <!-- COLUMNA IZQUIERDA: Ranking -->
        <div class="flex flex-col gap-4 min-h-0">

            <!-- Top del día -->
            <div v-if="topHoy" class="rounded-2xl p-4 border shrink-0"
                 style="background:#102347; border-color:#FFB300;">
                <p class="text-xs font-bold uppercase tracking-wider mb-2"
                   style="color:#FFB300;">🏆 Mejor del día</p>
                <p class="text-xl font-bold">{{ topHoy.nombre }}</p>
                <p class="text-sm mt-1" :style="{ color: nivelColor(topHoy.nivel) }">
                    {{ nivelEmoji(topHoy.nivel) }} {{ topHoy.nivel }}
                    · +{{ topHoy.puntos_hoy }} pts hoy
                </p>
            </div>

            <!-- Ranking semanal -->
            <div class="flex-1 rounded-2xl p-4 overflow-y-auto" style="background:#102347;">
                <p class="text-xs font-bold uppercase tracking-wider mb-3"
                   style="color:#00B4D8;">🏅 Ranking Semanal</p>

                <div v-if="ranking.length" class="space-y-2">
                    <div v-for="(c, idx) in ranking" :key="idx"
                         class="flex items-center gap-3 p-3 rounded-xl"
                         :style="{
                             background: idx === 0 ? 'var(--marca)' : '#0D2E5A',
                             border: idx === 0 ? '1px solid #FFB300' : '1px solid transparent',
                         }">
                        <span class="text-2xl font-black w-8 text-center"
                              :style="{ color: idx === 0 ? '#FFD700' : idx === 1 ? '#C0C0C0' : idx === 2 ? '#CD7F32' : '#8BA3C1' }">
                            {{ idx + 1 }}
                        </span>
                        <div class="flex-1 min-w-0">
                            <p class="font-semibold text-sm truncate">{{ c.nombre }}</p>
                            <p class="text-xs" :style="{ color: nivelColor(c.nivel) }">
                                {{ nivelEmoji(c.nivel) }} {{ c.nivel }}
                            </p>
                        </div>
                        <div class="text-right shrink-0">
                            <p class="font-bold text-lg" style="color:#00B4D8;">{{ c.puntos_semana }}</p>
                            <p class="text-xs" style="color:#8BA3C1;">pts</p>
                        </div>
                    </div>
                </div>

                <div v-else class="text-center py-8" style="color:#8BA3C1;">
                    <p class="text-3xl mb-2">🏅</p>
                    <p class="text-sm">Sin movimientos esta semana</p>
                    <p class="text-xs mt-1">¡Completa trabajos para aparecer aquí!</p>
                </div>
            </div>
        </div>

        <!-- COLUMNA CENTRAL: Trabajos programados hoy -->
        <div class="rounded-2xl p-4 overflow-y-auto" style="background:#102347;">
            <p class="text-xs font-bold uppercase tracking-wider mb-4"
               style="color:#00B4D8;">⚙️ Programación de Hoy por Estación</p>

            <div v-if="trabajos.length" class="space-y-4">
                <div v-for="estacion in trabajos" :key="estacion.estacion"
                     class="rounded-xl p-3" style="background:#0D2E5A;">

                    <!-- Header estación -->
                    <div class="flex items-center justify-between mb-2">
                        <div class="flex items-center gap-2">
                            <div class="w-3 h-3 rounded-full shrink-0"
                                 :style="{ background: estacion.color }"></div>
                            <span class="font-bold text-sm">{{ estacion.estacion }}</span>
                        </div>
                        <span class="text-xs px-2 py-0.5 rounded-full font-medium"
                              :style="{
                                  background: estacion.completados === estacion.total ? '#00C85330' : 'var(--marca)',
                                  color: estacion.completados === estacion.total ? '#00C853' : '#00B4D8',
                              }">
                            {{ estacion.completados }}/{{ estacion.total }}
                        </span>
                    </div>

                    <!-- Barra progreso estación -->
                    <div class="w-full rounded-full h-1.5 mb-3" style="background:#061830;">
                        <div class="h-1.5 rounded-full transition-all duration-500"
                             :style="{
                                 width: (estacion.total > 0 ? estacion.completados / estacion.total * 100 : 0) + '%',
                                 background: estacion.color,
                             }"></div>
                    </div>

                    <!-- Pasos -->
                    <div class="space-y-1.5">
                        <div v-for="paso in estacion.pasos" :key="paso.nombre"
                             class="flex items-center gap-2 p-2 rounded-lg"
                             :style="{ background: paso.completado ? '#00C85315' : '#061830' }">
                            <span class="text-sm shrink-0">{{ paso.completado ? '✅' : '⏳' }}</span>
                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-medium truncate"
                                   :style="{ color: paso.completado ? '#00C853' : '#fff' }">
                                    {{ paso.nombre }}
                                </p>
                                <p class="text-xs truncate" style="color:#8BA3C1;">
                                    {{ paso.op }}<span v-if="paso.cliente"> · {{ paso.cliente }}</span><span v-if="paso.colaborador"> · {{ paso.colaborador }}</span>
                                </p>
                            </div>
                            <span v-if="paso.tiempo_est" class="text-xs shrink-0" style="color:#8BA3C1;">
                                {{ paso.tiempo_est }}m
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <div v-else class="flex flex-col items-center justify-center h-48"
                 style="color:#8BA3C1;">
                <p class="text-4xl mb-3">📋</p>
                <p class="text-sm">Sin trabajos programados para hoy</p>
                <p class="text-xs mt-1">Usa el Programador para asignar trabajos</p>
            </div>
        </div>

        <!-- COLUMNA DERECHA: OPs + Completados -->
        <div class="flex flex-col gap-4 min-h-0">

            <!-- OPs activas -->
            <div class="flex-1 rounded-2xl p-4 overflow-y-auto" style="background:#102347;">
                <p class="text-xs font-bold uppercase tracking-wider mb-3"
                   style="color:#00B4D8;">📦 Órdenes Activas</p>

                <div v-if="ops.length" class="space-y-3">
                    <div v-for="op in ops" :key="op.numero"
                         class="p-3 rounded-xl" style="background:#0D2E5A;">
                        <div class="flex items-start justify-between mb-1.5">
                            <div class="min-w-0 flex-1">
                                <p class="font-bold text-sm" style="color:#00B4D8;">{{ op.numero }}</p>
                                <p class="text-xs truncate" style="color:#8BA3C1;">{{ op.cliente }}</p>
                            </div>
                            <span class="font-bold text-xl shrink-0 ml-2"
                                  :style="{ color: pctColor(op.porcentaje_avance) }">
                                {{ op.porcentaje_avance }}%
                            </span>
                        </div>
                        <div class="w-full rounded-full h-2" style="background:#061830;">
                            <div class="h-2 rounded-full transition-all duration-700"
                                 :style="{
                                     width: op.porcentaje_avance + '%',
                                     background: pctColor(op.porcentaje_avance),
                                 }"></div>
                        </div>
                        <p v-if="op.fecha_entrega" class="text-xs mt-1" style="color:#8BA3C1;">
                            📅 {{ op.fecha_entrega }}
                        </p>
                    </div>
                </div>

                <div v-else class="text-center py-8" style="color:#8BA3C1;">
                    <p class="text-3xl mb-2">📦</p>
                    <p class="text-sm">Sin órdenes activas</p>
                </div>
            </div>

            <!-- Completados hoy -->
            <div class="rounded-2xl p-4 shrink-0" style="background:#102347;">
                <p class="text-xs font-bold uppercase tracking-wider mb-3"
                   style="color:#00C853;">✅ Completado hoy</p>
                <div v-if="pasosHoy.length" class="space-y-1.5">
                    <div v-for="(p, idx) in pasosHoy.slice(0, 5)" :key="idx"
                         class="p-2 rounded-lg" style="background:#0D2E5A;">
                        <p class="text-xs font-medium truncate">{{ p.nombre }}</p>
                        <p class="text-xs truncate" style="color:#8BA3C1;">
                            {{ p.operarios.join(', ') }} · {{ p.completado_at }}
                        </p>
                    </div>
                </div>
                <div v-else class="text-center py-4" style="color:#8BA3C1;">
                    <p class="text-sm">Sin completados aún</p>
                </div>
            </div>
        </div>
    </div>

    <!-- FOOTER -->
    <div class="px-8 py-2 flex items-center justify-between border-t text-xs"
         style="background:#0D2E5A; border-color:var(--marca); color:#8BA3C1;">
        <span>Interfrigo SAS © {{ new Date().getFullYear() }}</span>
        <span>Sistema de Gestión Integral — Actualización cada 30s</span>
        <span style="color:#00B4D8;">sgi.interfrigo.com.co</span>
    </div>
</div>
</template>
