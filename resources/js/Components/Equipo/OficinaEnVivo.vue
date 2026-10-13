<script setup>
/**
 * La oficina en vivo: cada agente en su escritorio, trabajando a la vista.
 *
 * Es solo una forma de mirar: los datos son los mismos de la oficina de tarjetas (la misma
 * petición y el mismo pulso de `EquipoInterno`), así que no cuesta ni una consulta más. Lo que
 * cambia es que el estado se ve en el personaje —teclea, levanta la mano, echa humo, duerme— y
 * que el globo dice qué está haciendo en este momento (`actual.accion`, que arma el servidor con
 * el último paso de la corrida).
 *
 * Tocar a un agente abre su ficha al lado (abajo en el celular): qué hace, lo último que
 * terminó, y los mismos botones de siempre —editar, ejecutar, probar, ver la corrida en vivo—.
 * Ninguno es un camino propio: todos emiten lo mismo que la oficina de tarjetas.
 *
 * Las animaciones son CSS y se apagan con «reducir movimiento» del sistema.
 */
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { ESTADOS, hace } from '@/composables/useEquipoIa'

const props = defineProps({
    agentes:   { type: Array, default: () => [] },
    permisos:  { type: Object, default: () => ({}) },
    pausado:   { type: Boolean, default: false },
    iaLista:   { type: Boolean, default: true },
    cargando:  { type: Boolean, default: false },
    esperando: { type: Array, default: () => [] },
})

const emit = defineEmits(['configurar', 'ejecutar', 'probar', 'actividad', 'aprobaciones', 'pausar', 'nuevo', 'detalle'])

const elegidoId = ref(null)
const elegido = computed(() => props.agentes.find(a => a.id === elegidoId.value) ?? null)

function elegir(a) {
    elegidoId.value = elegidoId.value === a.id ? null : a.id
}

// Colores saturados para la camisa: se ven igual de día y de noche, como los botones.
const CAMISAS = {
    azul: '#3b82f6', ambar: '#f59e0b', verde: '#10b981', rojo: '#ef4444',
    violeta: '#8b5cf6', naranja: '#f97316',
}
const camisa = (a) => CAMISAS[a.color] ?? 'var(--marca)'

const cuenta = computed(() => {
    const c = { trabajando: 0, aprobacion: 0, error: 0 }
    for (const a of props.agentes) if (c[a.estado] !== undefined) c[a.estado]++
    return c
})

/** Lo que dice el globo encima del escritorio. */
function globo(a) {
    if (props.esperando.includes(a.id) && a.estado !== 'trabajando') return 'Arrancando…'
    switch (a.estado) {
        case 'trabajando': return a.actual?.accion ?? 'Trabajando'
        case 'aprobacion': return `¡${a.pendientes} por revisar!`
        case 'error':      return 'Algo falló'
        case 'terminado':  return '¡Listo!'
        case 'apagado':    return 'Zzz'
        default:           return 'Disponible'
    }
}

// ─── Pantalla completa (para dejarla en un televisor) ───────────────────────

const escena = ref(null)
const completa = ref(false)

function alternarPantallaCompleta() {
    if (document.fullscreenElement) document.exitFullscreen?.()
    else escena.value?.requestFullscreen?.()
}

function alCambiarPantallaCompleta() {
    completa.value = document.fullscreenElement === escena.value
}

onMounted(() => document.addEventListener('fullscreenchange', alCambiarPantallaCompleta))
onUnmounted(() => document.removeEventListener('fullscreenchange', alCambiarPantallaCompleta))

const puedePantallaCompleta = typeof document !== 'undefined' && !! document.documentElement?.requestFullscreen
</script>

<template>
    <div>
        <div v-if="! iaLista" class="mb-3 rounded-xl border border-borde-aviso-ambar bg-pastel-ambar text-aviso-ambar text-sm p-3">
            La IA no está configurada en esta instalación: los agentes no pueden correr hasta que se conecte.
        </div>

        <div class="lg:grid lg:grid-cols-[1fr_320px] lg:gap-4">
            <!-- La escena -->
            <div ref="escena" :class="['oficina bg-lienzo rounded-2xl border border-linea overflow-hidden', completa ? 'p-6 overflow-y-auto' : 'p-3 sm:p-4']">
                <div class="flex items-center justify-between gap-2 flex-wrap mb-3">
                    <p class="text-xs text-tinta-500 flex flex-wrap gap-x-3 gap-y-1">
                        <span><strong class="text-tinta-900">{{ cuenta.trabajando }}</strong> trabajando</span>
                        <button v-if="cuenta.aprobacion" type="button" @click="emit('aprobaciones')" class="underline underline-offset-2 text-aviso-naranja font-semibold">
                            {{ cuenta.aprobacion }} {{ cuenta.aprobacion === 1 ? 'espera' : 'esperan' }} tu decisión
                        </button>
                        <span v-if="cuenta.error" class="text-aviso-rojo font-semibold">{{ cuenta.error }} con error</span>
                        <span v-if="pausado" class="text-aviso-rojo font-semibold">Pausa general</span>
                    </p>
                    <div class="flex gap-2">
                        <button v-if="puedePantallaCompleta" type="button" @click="alternarPantallaCompleta"
                            class="text-xs px-3 py-1.5 rounded-lg border border-linea bg-superficie text-tinta-700 hover:bg-realce transition-colors">
                            {{ completa ? 'Salir de pantalla completa' : 'Pantalla completa' }}
                        </button>
                        <button v-if="permisos.gestionar && ! completa" type="button" @click="emit('nuevo')"
                            class="px-3 py-1.5 rounded-xl text-white text-xs font-semibold" style="background:var(--marca);">
                            + Nuevo agente
                        </button>
                    </div>
                </div>

                <p v-if="cargando && ! agentes.length" class="text-sm text-tinta-300 text-center py-16">Abriendo la oficina…</p>
                <p v-else-if="! agentes.length" class="text-sm text-tinta-300 text-center py-16">La oficina está vacía. Crea un agente con «+ Nuevo agente».</p>

                <!-- Un escritorio por agente. Celular: dos por fila. -->
                <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-x-3 gap-y-14 pt-10">
                    <button v-for="a in agentes" :key="a.id" type="button" @click="elegir(a)"
                        :aria-pressed="elegidoId === a.id"
                        :aria-label="`${a.nombre}: ${ESTADOS[a.estado]?.texto}. ${globo(a)}`"
                        :class="['puesto relative rounded-2xl text-left transition-shadow hover:bg-superficie/60 focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--marca)]',
                            `estado-${a.estado}`, elegidoId === a.id ? 'ring-2 ring-[var(--marca)] bg-superficie/80' : '']">

                        <!-- El globo -->
                        <span :class="['globo absolute left-1/2 -translate-x-1/2 -top-8 z-10 max-w-[96%] text-[10px] sm:text-[11px] leading-tight font-semibold px-2 py-1 rounded-xl shadow-sm line-clamp-2 text-center', ESTADOS[a.estado]?.chip]">
                            {{ globo(a) }}
                        </span>

                        <svg viewBox="0 0 160 120" class="w-full h-auto block" aria-hidden="true">
                            <!-- Sombra en el piso -->
                            <ellipse cx="80" cy="112" rx="62" ry="6" fill="currentColor" class="text-tinta-200" opacity="0.6" />

                            <!-- Silla -->
                            <rect x="62" y="42" width="36" height="44" rx="8" fill="currentColor" class="text-tinta-300" />

                            <!-- El personaje -->
                            <g class="personaje">
                                <rect x="62" y="56" width="36" height="34" rx="10" :fill="camisa(a)" class="torso" />
                                <circle cx="80" cy="42" r="13" fill="#f2c9a0" />
                                <path d="M67 40 Q68 27 80 27 Q92 27 93 40 Q88 33 80 33 Q72 33 67 40Z" fill="#4b3621" />
                                <template v-if="a.estado === 'apagado'">
                                    <path d="M73 44 h4 M83 44 h4" stroke="#4b3621" stroke-width="1.6" stroke-linecap="round" />
                                </template>
                                <template v-else>
                                    <circle cx="75" cy="43" r="1.6" fill="#2b2b2b" />
                                    <circle cx="85" cy="43" r="1.6" fill="#2b2b2b" />
                                    <path v-if="a.estado === 'terminado'" d="M75 48 Q80 52 85 48" stroke="#2b2b2b" stroke-width="1.4" fill="none" stroke-linecap="round" />
                                    <path v-else-if="a.estado === 'error'" d="M75 50 Q80 46 85 50" stroke="#2b2b2b" stroke-width="1.4" fill="none" stroke-linecap="round" />
                                </template>

                                <!-- Brazos: teclean, o uno se levanta para pedir la palabra -->
                                <g v-if="a.estado === 'aprobacion'">
                                    <path d="M95 62 L108 40" stroke="#f2c9a0" stroke-width="6" stroke-linecap="round" class="mano-arriba" />
                                    <path d="M65 64 L70 86" stroke="#f2c9a0" stroke-width="6" stroke-linecap="round" />
                                </g>
                                <g v-else>
                                    <path d="M65 64 L72 86" stroke="#f2c9a0" stroke-width="6" stroke-linecap="round" class="brazo-izq" />
                                    <path d="M95 64 L88 86" stroke="#f2c9a0" stroke-width="6" stroke-linecap="round" class="brazo-der" />
                                </g>
                            </g>

                            <!-- Escritorio -->
                            <rect x="14" y="86" width="132" height="10" rx="3" fill="#b07a4a" />
                            <rect x="20" y="96" width="6" height="14" fill="#8a5d36" />
                            <rect x="134" y="96" width="6" height="14" fill="#8a5d36" />
                            <rect x="66" y="82" width="28" height="5" rx="1.5" fill="#3a3a3a" />

                            <!-- Computador -->
                            <rect x="22" y="58" width="38" height="26" rx="3" fill="#2f2f36" />
                            <rect x="25" y="61" width="32" height="20" rx="1.5" class="pantalla" />
                            <g v-if="a.estado === 'trabajando'" class="lineas">
                                <rect x="28" y="65" width="20" height="2" rx="1" fill="#e5f3ff" />
                                <rect x="28" y="70" width="25" height="2" rx="1" fill="#e5f3ff" />
                                <rect x="28" y="75" width="14" height="2" rx="1" fill="#e5f3ff" />
                            </g>
                            <rect x="38" y="84" width="6" height="3" fill="#2f2f36" />

                            <!-- Taza de café, con vapor cuando está libre -->
                            <rect x="114" y="74" width="12" height="12" rx="2" fill="#f5f5f4" stroke="#a8a29e" />
                            <path d="M126 77 q5 3 0 6" stroke="#a8a29e" fill="none" />
                            <g v-if="a.estado === 'libre'" class="vapor" stroke="#a8a29e" fill="none" stroke-linecap="round">
                                <path d="M118 70 q-3 -4 0 -8" />
                                <path d="M122 70 q3 -4 0 -8" />
                            </g>

                            <!-- Lo que flota encima según el estado -->
                            <g v-if="a.estado === 'error'" class="humo" fill="#9ca3af">
                                <circle cx="34" cy="52" r="4" />
                                <circle cx="42" cy="46" r="5" />
                                <circle cx="50" cy="52" r="3.5" />
                            </g>
                            <g v-if="a.estado === 'terminado'">
                                <circle cx="118" cy="40" r="10" fill="#10b981" />
                                <path d="M113 40 l4 4 l7 -8" stroke="#fff" stroke-width="2.6" fill="none" stroke-linecap="round" stroke-linejoin="round" />
                            </g>
                            <g v-if="a.estado === 'aprobacion'" class="alerta">
                                <circle cx="120" cy="30" r="10" fill="#f97316" />
                                <rect x="118.6" y="23" width="2.8" height="9" rx="1.4" fill="#fff" />
                                <circle cx="120" cy="35.5" r="1.6" fill="#fff" />
                            </g>
                            <g v-if="a.estado === 'apagado'" class="zzz" fill="currentColor" font-size="11" font-weight="700">
                                <text x="98" y="30" class="text-tinta-400">z</text>
                                <text x="106" y="22" class="text-tinta-400">z</text>
                                <text x="115" y="14" class="text-tinta-400">Z</text>
                            </g>
                        </svg>

                        <!-- La placa del escritorio -->
                        <span class="block text-center px-1 pb-2 -mt-1">
                            <span class="block text-xs font-semibold text-tinta-900 truncate">{{ a.avatar }} {{ a.nombre }}</span>
                            <span class="block text-[10px] text-tinta-400 truncate">{{ a.cargo || ESTADOS[a.estado]?.texto }}</span>
                        </span>
                    </button>
                </div>
            </div>

            <!-- La ficha del agente elegido. Escritorio: al lado; celular: hoja que sube desde abajo. -->
            <aside v-if="elegido"
                class="fixed inset-x-0 bottom-0 z-50 max-h-[75vh] overflow-y-auto rounded-t-2xl border-t border-linea bg-superficie p-4 pb-[calc(1rem+env(safe-area-inset-bottom))] shadow-xl
                       lg:static lg:pb-4 lg:z-auto lg:max-h-none lg:rounded-2xl lg:border lg:shadow-none"
                :aria-label="`Ficha de ${elegido.nombre}`">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-tinta-900 truncate">{{ elegido.avatar }} {{ elegido.nombre }}</p>
                        <p v-if="elegido.cargo" class="text-[11px] text-tinta-400">{{ elegido.cargo }}</p>
                        <span :class="['inline-flex items-center gap-1 text-[11px] font-semibold px-2 py-0.5 rounded-full mt-1', ESTADOS[elegido.estado]?.chip]">
                            <span aria-hidden="true">{{ ESTADOS[elegido.estado]?.icono }}</span>{{ ESTADOS[elegido.estado]?.texto }}
                        </span>
                    </div>
                    <button type="button" @click="elegidoId = null" class="text-tinta-400 hover:text-tinta-700 text-xl leading-none" aria-label="Cerrar">×</button>
                </div>

                <!-- Qué está haciendo -->
                <div class="mt-3 rounded-xl border border-linea p-3">
                    <p class="text-[10px] font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1">Ahora mismo</p>
                    <template v-if="elegido.actual">
                        <p class="text-sm font-semibold text-tinta-900">{{ elegido.actual.accion }}</p>
                        <p v-if="elegido.actual.detalle" class="text-xs text-tinta-500 mt-1 leading-relaxed">{{ elegido.actual.detalle }}</p>
                        <p class="text-[11px] text-tinta-400 mt-1">
                            Empezó {{ hace(elegido.actual.desde) }}<template v-if="elegido.actual.pasos"> · paso {{ elegido.actual.pasos }}</template>
                        </p>
                        <button type="button" @click="emit('detalle', elegido.actual.ejecucion_id)"
                            class="mt-2 text-xs font-semibold underline underline-offset-2" style="color:var(--marca);">
                            Ver paso a paso en vivo
                        </button>
                    </template>
                    <p v-else-if="elegido.estado === 'apagado'" class="text-sm text-tinta-500">Está apagado. Enciéndelo al editarlo.</p>
                    <p v-else class="text-sm text-tinta-500">No está corriendo. {{ elegido.frecuencia?.hora ? `Le toca a las ${elegido.frecuencia.hora}.` : 'Solo corre cuando lo pides.' }}</p>
                </div>

                <!-- Lo último que terminó (no la corrida que está en curso) -->
                <div v-if="! (elegido.actual && elegido.ultima?.id === elegido.actual.ejecucion_id)" class="mt-3 rounded-xl border border-linea p-3">
                    <p class="text-[10px] font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1">Lo último</p>
                    <p v-if="elegido.ultima?.estado === 'fallida'" class="text-xs text-aviso-rojo leading-relaxed">
                        Falló {{ hace(elegido.ultima.cuando) }}: {{ elegido.ultima.error }}
                    </p>
                    <p v-else-if="elegido.ultima" class="text-xs text-tinta-700 leading-relaxed">
                        <span class="text-tinta-400">{{ hace(elegido.ultima.cuando) }}:</span> {{ elegido.ultima.resumen || '—' }}
                    </p>
                    <p v-else class="text-xs text-tinta-300">Todavía no ha corrido.</p>
                </div>

                <p class="text-[11px] text-tinta-400 mt-3">
                    {{ elegido.modo === 'propone' ? 'Propone (tú decides)' : 'Solo lectura' }} · {{ elegido.modelo }}
                </p>

                <div class="flex flex-wrap gap-2 mt-3">
                    <button v-if="permisos.gestionar" type="button" @click="emit('configurar', elegido)"
                        class="text-xs px-3 py-1.5 rounded-lg text-white font-semibold" style="background:var(--marca);">
                        Editar
                    </button>
                    <button v-if="elegido.estado === 'aprobacion' && permisos.aprobar" type="button" @click="emit('aprobaciones')"
                        class="text-xs px-3 py-1.5 rounded-lg border border-linea text-tinta-700 hover:bg-realce transition-colors">
                        Revisar propuestas
                    </button>
                    <button v-if="permisos.ejecutar && elegido.activo" type="button" @click="emit('ejecutar', elegido)"
                        :disabled="elegido.estado === 'trabajando' || esperando.includes(elegido.id) || pausado"
                        class="text-xs px-3 py-1.5 rounded-lg border border-linea text-tinta-700 hover:bg-realce disabled:opacity-40 transition-colors">
                        Ejecutar ahora
                    </button>
                    <button v-if="permisos.ejecutar" type="button" @click="emit('probar', elegido)"
                        :disabled="elegido.estado === 'trabajando' || esperando.includes(elegido.id)"
                        class="text-xs px-3 py-1.5 rounded-lg border border-linea text-tinta-700 hover:bg-realce disabled:opacity-40 transition-colors"
                        title="Corre sin escribir nada ni avisar a nadie">
                        Probar
                    </button>
                    <button v-if="elegido.ultima" type="button" @click="emit('actividad', elegido)"
                        class="text-xs px-3 py-1.5 rounded-lg border border-linea text-tinta-700 hover:bg-realce transition-colors">
                        Actividad
                    </button>
                </div>
            </aside>

            <p v-else class="hidden lg:block text-xs text-tinta-400 border border-dashed border-linea rounded-2xl p-4 self-start">
                Toca a un agente para ver qué está haciendo y editarlo.
            </p>
        </div>
    </div>
</template>

<style scoped>
/* El piso de la oficina: una cuadrícula tenue que funciona igual de día y de noche porque es
   gris translúcido sobre el fondo del tema. */
.oficina {
    background-image:
        linear-gradient(rgba(127, 127, 127, 0.09) 1px, transparent 1px),
        linear-gradient(90deg, rgba(127, 127, 127, 0.09) 1px, transparent 1px);
    background-size: 28px 28px;
}

.puesto:hover { box-shadow: 0 4px 14px rgba(0, 0, 0, 0.08); }

.pantalla { fill: #1e293b; }
.estado-trabajando .pantalla { fill: #2563eb; animation: brillo 1.6s ease-in-out infinite; }
.estado-terminado .pantalla { fill: #047857; }
.estado-error .pantalla { fill: #b91c1c; }
.estado-aprobacion .pantalla { fill: #c2410c; }
.estado-apagado .pantalla { fill: #111827; }
.estado-apagado .personaje { opacity: 0.55; }

.estado-trabajando .brazo-izq { animation: teclear 0.45s ease-in-out infinite; }
.estado-trabajando .brazo-der { animation: teclear 0.45s ease-in-out infinite 0.22s; }
.estado-trabajando .lineas { animation: escribir 1.2s steps(3) infinite; }
.estado-libre .torso,
.estado-terminado .torso { transform-origin: 80px 90px; animation: respirar 3.2s ease-in-out infinite; }
.mano-arriba { transform-origin: 95px 62px; animation: saludar 0.9s ease-in-out infinite; }
.alerta { transform-origin: 120px 30px; animation: saltar 1s ease-in-out infinite; }
.vapor path { animation: vapor 2.4s ease-in-out infinite; }
.vapor path:nth-child(2) { animation-delay: 0.8s; }
.humo circle { animation: humo 2s ease-out infinite; }
.humo circle:nth-child(2) { animation-delay: 0.6s; }
.humo circle:nth-child(3) { animation-delay: 1.2s; }
.zzz text { animation: zzz 2.6s ease-in-out infinite; }
.zzz text:nth-child(2) { animation-delay: 0.4s; }
.zzz text:nth-child(3) { animation-delay: 0.8s; }
.estado-aprobacion .globo,
.estado-error .globo { animation: saltar 1.4s ease-in-out infinite; }

@keyframes teclear { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-2.5px); } }
@keyframes escribir { 0% { opacity: 0.4; } 100% { opacity: 1; } }
@keyframes brillo { 0%, 100% { opacity: 1; } 50% { opacity: 0.75; } }
@keyframes respirar { 0%, 100% { transform: scaleY(1); } 50% { transform: scaleY(1.035); } }
@keyframes saludar { 0%, 100% { transform: rotate(0deg); } 50% { transform: rotate(-12deg); } }
@keyframes saltar { 0%, 100% { transform: translate(-50%, 0); } 50% { transform: translate(-50%, -3px); } }
.alerta { animation-name: saltarSvg; }
@keyframes saltarSvg { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-3px); } }
@keyframes vapor { 0% { opacity: 0; transform: translateY(4px); } 40% { opacity: 1; } 100% { opacity: 0; transform: translateY(-6px); } }
@keyframes humo { 0% { opacity: 0.8; transform: translateY(0) scale(0.8); } 100% { opacity: 0; transform: translateY(-14px) scale(1.4); } }
@keyframes zzz { 0%, 100% { opacity: 0.3; } 50% { opacity: 1; } }

/* «Reducir movimiento» del sistema: la escena se queda quieta, los estados se siguen leyendo
   por el globo, el color de la pantalla y los iconos. */
@media (prefers-reduced-motion: reduce) {
    .oficina * { animation: none !important; }
}
</style>
