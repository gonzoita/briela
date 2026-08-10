<script setup>
import { ref } from 'vue'

/**
 * Un solo botón flotante que despliega los dos accesos: IA y chat.
 *
 * Antes había dos círculos de 56 px fijos, uno encima del otro. En celular
 * tapaban el total de la cotización y el botón de agregar ítem; en pantalla
 * chica cualquier cosa que quede bajo ellos se vuelve intocable.
 *
 * Ahora hay uno solo, más pequeño, y las dos opciones aparecen solo cuando se
 * toca. Al abrirse pone un velo transparente detrás: tocar fuera lo cierra,
 * así no se queda estorbando.
 */
const emit = defineEmits(['ia', 'chat'])

defineProps({
    sinLeer: { type: Number, default: 0 },
})

const abierto = ref(false)

function elegir(cual) {
    abierto.value = false
    emit(cual)
}
</script>

<template>
    <!-- Velo: cerrar tocando fuera, sin bloquear la vista -->
    <div v-if="abierto" class="fixed inset-0 z-30" @click="abierto = false"></div>

    <div class="lanzador fixed z-40 flex flex-col items-end gap-2">
        <!-- Opciones, solo cuando está abierto -->
        <Transition name="opciones">
            <div v-if="abierto" class="flex flex-col items-end gap-2">
                <button @click="elegir('chat')"
                    class="opcion flex items-center gap-2 pl-3 pr-1.5 py-1.5 rounded-full shadow-lg bg-white border border-linea">
                    <span class="text-xs font-semibold text-tinta-700">Chat del equipo</span>
                    <span class="w-8 h-8 rounded-full flex items-center justify-center relative" style="background:#0F766E;">
                        <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M17 8h2a2 2 0 012 2v6a2 2 0 01-2 2h-2v4l-4-4H9a1.99 1.99 0 01-1.414-.586m0 0L11 14h4a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2v4l.586-.586z" />
                        </svg>
                        <span v-if="sinLeer"
                            class="absolute -top-1 -right-1 min-w-[16px] h-4 px-1 rounded-full bg-red-500 text-white text-[10px] font-semibold flex items-center justify-center">
                            {{ sinLeer > 9 ? '9+' : sinLeer }}
                        </span>
                    </span>
                </button>

                <button @click="elegir('ia')"
                    class="opcion flex items-center gap-2 pl-3 pr-1.5 py-1.5 rounded-full shadow-lg bg-white border border-linea">
                    <span class="text-xs font-semibold text-tinta-700">Asistente</span>
                    <span class="w-8 h-8 rounded-full flex items-center justify-center" style="background:var(--marca);">
                        <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z" />
                        </svg>
                    </span>
                </button>
            </div>
        </Transition>

        <!-- El único botón siempre visible -->
        <button @click="abierto = !abierto"
            class="principal flex items-center justify-center rounded-full shadow-lg relative"
            :style="`background:${abierto ? '#475569' : 'var(--marca)'};`"
            :title="abierto ? 'Cerrar' : 'Asistente y chat'">
            <svg v-if="!abierto" class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                <path stroke-linecap="round" stroke-linejoin="round"
                    d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.86 9.86 0 01-4-.8L3 20l1.4-3.5A7.94 7.94 0 013 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
            </svg>
            <svg v-else class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
            </svg>

            <span v-if="sinLeer && !abierto"
                class="globito absolute -top-1 -right-1 min-w-[18px] h-[18px] px-1 rounded-full bg-red-500 text-white text-[10px] font-semibold flex items-center justify-center">
                {{ sinLeer > 9 ? '9+' : sinLeer }}
            </span>
        </button>
    </div>
</template>

<style scoped>
/* Celular: pequeño y justo encima de la barra de navegación (64 px), para no
   quedar encima del contenido ni de los botones de la pantalla. */
.lanzador { right: 12px; bottom: 76px; }
.principal { width: 44px; height: 44px; }

/* En pantalla grande no hay barra inferior: baja y puede ser algo más grande. */
@media (min-width: 640px) {
    .lanzador { right: 20px; bottom: 20px; }
    .principal { width: 52px; height: 52px; }
}

.principal { transition: transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease; }
.principal:hover  { transform: scale(1.08); box-shadow: 0 10px 25px rgba(0,0,0,0.25); }
.principal:active { transform: scale(0.92); }

.opcion { transition: transform 0.15s ease; }
.opcion:hover  { transform: translateX(-2px); }
.opcion:active { transform: scale(0.96); }

.opciones-enter-active, .opciones-leave-active { transition: opacity 0.16s ease, transform 0.16s ease; }
.opciones-enter-from, .opciones-leave-to { opacity: 0; transform: translateY(8px) scale(0.95); }

.globito { animation: latir 2s ease-in-out infinite; }
@keyframes latir { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.15); } }

@media (prefers-reduced-motion: reduce) {
    .principal, .opcion, .globito { transition: none; animation: none; }
    .principal:hover, .opcion:hover { transform: none; }
}
</style>
