<script setup>
/**
 * La oficina: cada agente es un personaje con su estado a la vista.
 *
 * El estado no se guarda: lo deriva el servidor de las ejecuciones y las propuestas, así que
 * nunca se queda «trabajando» porque un proceso murió sin avisar. Aquí solo se pinta.
 */
import { computed } from 'vue'
import { ESTADOS, hace } from '@/composables/useEquipoIa'

const props = defineProps({
    agentes:   { type: Array, default: () => [] },
    permisos:  { type: Object, default: () => ({}) },
    pausado:   { type: Boolean, default: false },
    iaLista:   { type: Boolean, default: true },
    cargando:  { type: Boolean, default: false },
    // IDs de agentes a los que se les acaba de pedir una corrida y aún no aparece «trabajando».
    esperando: { type: Array, default: () => [] },
})

const emit = defineEmits(['configurar', 'ejecutar', 'probar', 'actividad', 'aprobaciones', 'pausar', 'nuevo'])

const aprobacionesTotales = computed(() => props.agentes.reduce((s, a) => s + (a.pendientes || 0), 0))

const cuando = (a) => a.ultima ? hace(a.ultima.cuando) : 'nunca'

const horario = (a) => {
    const f = a.frecuencia
    if (!f || f.tipo === 'manual' || !f.hora) return 'Solo manual'
    return f.tipo === 'diaria' ? `Todos los días a las ${f.hora}` : `Semanal a las ${f.hora}`
}
</script>

<template>
    <div>
        <!-- Avisos de contexto: lo que impide que la oficina trabaje -->
        <div v-if="! iaLista" class="mb-3 rounded-xl border border-borde-aviso-ambar bg-pastel-ambar text-aviso-ambar text-sm p-3">
            La IA no está configurada en esta instalación: los agentes no pueden correr hasta que se conecte.
        </div>
        <div v-if="pausado" class="mb-3 rounded-xl border border-borde-aviso-rojo bg-pastel-rojo text-aviso-rojo text-sm p-3 flex items-center justify-between gap-3 flex-wrap">
            <span><strong>Pausa general.</strong> Ningún agente arranca solo ni por botón. Las pruebas siguen funcionando.</span>
            <button v-if="permisos.gestionar" type="button" @click="emit('pausar', false)"
                class="text-xs px-3 py-1.5 rounded-lg border border-borde-aviso-rojo hover:bg-pastel-rojo-2 transition-colors">
                Reanudar
            </button>
        </div>

        <div class="flex items-center justify-between gap-3 flex-wrap mb-3">
            <p class="text-sm text-tinta-500">
                <template v-if="aprobacionesTotales > 0">
                    <button type="button" @click="emit('aprobaciones')" class="font-semibold text-aviso-naranja underline underline-offset-2">
                        {{ aprobacionesTotales }} {{ aprobacionesTotales === 1 ? 'propuesta espera' : 'propuestas esperan' }} tu decisión
                    </button>
                </template>
                <template v-else>Nada espera tu aprobación.</template>
            </p>
            <div class="flex items-center gap-2">
                <button v-if="permisos.gestionar && ! pausado" type="button" @click="emit('pausar', true)"
                    class="text-xs px-3 py-1.5 rounded-lg border border-linea text-tinta-500 hover:bg-realce transition-colors"
                    title="Detiene a todos los agentes de inmediato">
                    Pausar todo
                </button>
                <button v-if="permisos.gestionar" type="button" @click="emit('nuevo')"
                    class="px-3 py-1.5 rounded-xl text-white text-xs font-semibold" style="background:var(--marca);">
                    + Nuevo agente
                </button>
            </div>
        </div>

        <p v-if="cargando && ! agentes.length" class="text-sm text-tinta-300 text-center py-10">Cargando la oficina…</p>

        <!-- Móvil: una columna. Escritorio: cuadrícula. -->
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
            <div v-for="a in agentes" :key="a.id"
                :class="['rounded-2xl border p-4 flex flex-col gap-3 transition-colors', ESTADOS[a.estado]?.caja ?? ESTADOS.libre.caja]">

                <div class="flex items-start gap-3">
                    <!-- El personaje -->
                    <div :class="['w-12 h-12 shrink-0 rounded-2xl flex items-center justify-center text-2xl bg-superficie shadow-sm',
                            a.estado === 'trabajando' ? 'motion-safe:animate-pulse' : '']"
                        aria-hidden="true">
                        {{ a.avatar || '🤖' }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-tinta-800 leading-tight">{{ a.nombre }}</p>
                        <p v-if="a.cargo && a.cargo !== a.nombre" class="text-[11px] text-tinta-400">{{ a.cargo }}</p>
                        <!-- El estado va con texto e icono, nunca solo con color -->
                        <span :class="['inline-flex items-center gap-1 text-[11px] font-semibold px-2 py-0.5 rounded-full mt-1', ESTADOS[a.estado]?.chip]">
                            <span aria-hidden="true">{{ ESTADOS[a.estado]?.icono }}</span>
                            {{ ESTADOS[a.estado]?.texto }}
                            <span v-if="a.estado === 'aprobacion'">({{ a.pendientes }})</span>
                        </span>
                    </div>
                </div>

                <p v-if="a.descripcion" class="text-xs text-tinta-500 leading-relaxed">{{ a.descripcion }}</p>

                <!-- Lo último que hizo, con su fecha -->
                <div class="text-xs text-tinta-500 bg-superficie/70 rounded-xl p-2.5 min-h-[3.25rem]">
                    <template v-if="a.estado === 'trabajando'">
                        <span class="font-medium text-aviso-ambar">En marcha…</span>
                        <span class="text-tinta-400"> Puede tardar un par de minutos.</span>
                    </template>
                    <template v-else-if="a.ultima?.estado === 'fallida'">
                        <span class="font-medium text-aviso-rojo">Falló {{ cuando(a) }}:</span> {{ a.ultima.error }}
                    </template>
                    <template v-else-if="a.ultima">
                        <span class="text-tinta-400">Última corrida {{ cuando(a) }}:</span> {{ a.ultima.resumen || '—' }}
                    </template>
                    <span v-else class="text-tinta-300">Todavía no ha corrido.</span>
                </div>

                <p class="text-[11px] text-tinta-400">
                    {{ horario(a) }} · {{ a.modo === 'propone' ? 'Propone (tú decides)' : 'Solo lectura' }}
                </p>
                <p class="text-[11px] text-tinta-300 truncate" :title="a.modelo">Modelo: {{ a.modelo }}</p>

                <div class="flex flex-wrap gap-2 mt-auto">
                    <button v-if="a.estado === 'aprobacion' && permisos.aprobar" type="button" @click="emit('aprobaciones')"
                        class="text-xs px-3 py-1.5 rounded-lg text-white font-semibold" style="background:var(--marca);">
                        Revisar
                    </button>
                    <button v-if="permisos.ejecutar && a.activo" type="button" @click="emit('ejecutar', a)"
                        :disabled="a.estado === 'trabajando' || esperando.includes(a.id) || pausado"
                        class="text-xs px-3 py-1.5 rounded-lg border border-linea text-tinta-600 hover:bg-realce disabled:opacity-40 transition-colors">
                        Ejecutar ahora
                    </button>
                    <button v-if="permisos.ejecutar" type="button" @click="emit('probar', a)"
                        :disabled="a.estado === 'trabajando' || esperando.includes(a.id)"
                        class="text-xs px-3 py-1.5 rounded-lg border border-linea text-tinta-600 hover:bg-realce disabled:opacity-40 transition-colors"
                        title="Corre sin escribir nada ni avisar a nadie">
                        Probar
                    </button>
                    <button v-if="a.ultima" type="button" @click="emit('actividad', a)"
                        class="text-xs px-3 py-1.5 rounded-lg border border-linea text-tinta-600 hover:bg-realce transition-colors">
                        Actividad
                    </button>
                    <button v-if="permisos.gestionar" type="button" @click="emit('configurar', a)"
                        class="text-xs px-3 py-1.5 rounded-lg border border-linea text-tinta-600 hover:bg-realce transition-colors">
                        Configurar
                    </button>
                </div>
            </div>
        </div>

        <p v-if="! cargando && ! agentes.length" class="text-sm text-tinta-300 text-center py-10 border border-dashed border-linea rounded-2xl">
            Todavía no hay agentes. Crea uno con «+ Nuevo agente».
        </p>
    </div>
</template>
