<script setup>
/**
 * La oficina en vivo: el Equipo de IA en pixel art, trabajando a la vista.
 *
 * La escena la pinta `OficinaPixel` (resources/js/oficina/pixel.js) en un lienzo. Aquí van lo que
 * es mejor en HTML: la ventana de chat, el tablero de control, la lista para elegir con el teclado
 * y la ficha del agente, con los mismos botones de siempre —editar, ejecutar, probar, ver la
 * corrida en vivo—. Ninguno es un camino propio: todos emiten lo mismo que la vista de tarjetas.
 *
 * No cuesta consultas: los datos son los mismos de la oficina (la misma petición y el mismo
 * pulso de `EquipoInterno`), y el tablero llega en esa misma respuesta. La animación solo corre
 * mientras el lienzo está a la vista y la pestaña del navegador abierta; con «reducir movimiento»
 * la escena se queda quieta y solo se vuelve a pintar cuando cambia algo.
 */
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { ESTADOS, hace } from '@/composables/useEquipoIa'
import { formatCOP, monedaBase } from '@/formato'
import { OficinaPixel } from '@/oficina/pixel'

const props = defineProps({
    agentes:   { type: Array, default: () => [] },
    permisos:  { type: Object, default: () => ({}) },
    pausado:   { type: Boolean, default: false },
    iaLista:   { type: Boolean, default: true },
    cargando:  { type: Boolean, default: false },
    esperando: { type: Array, default: () => [] },
    tablero:   { type: Object, default: null },
})

const emit = defineEmits(['configurar', 'ejecutar', 'probar', 'actividad', 'aprobaciones', 'pausar', 'nuevo', 'detalle'])

const pagina   = usePage()
const empresa  = computed(() => pagina.props.marca?.nombre ?? '')
const director = computed(() => pagina.props.auth?.user?.name ?? '')

const elegidoId = ref(null)
const elegido   = computed(() => props.agentes.find(a => a.id === elegidoId.value) ?? null)

function elegir(id) {
    elegidoId.value = elegidoId.value === id ? null : id
}

const cuenta = computed(() => {
    const c = { trabajando: 0, aprobacion: 0, error: 0 }
    for (const a of props.agentes) if (c[a.estado] !== undefined) c[a.estado]++
    return c
})

// ─── La escena ───────────────────────────────────────────────────────────────

const lienzo    = ref(null)
const contenedor = ref(null)
const escena    = ref(null)
let motor = null
let observadorTamano = null
let observadorVista = null
let aLaVista = true

const quieto = typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches

function pasarDatos() {
    motor?.datos({ agentes: props.agentes, empresa: empresa.value, director: director.value, elegido: elegidoId.value })
}

function animarSiToca() {
    if (! motor) return
    if (aLaVista && ! document.hidden) motor.iniciar()
    else motor.detener()
}

function alCambiarVisibilidad() { animarSiToca() }

function tocar(evento) {
    const id = motor?.agenteEn(evento.clientX, evento.clientY)
    if (id !== null && id !== undefined) elegir(id)
}

const sobreAgente = ref(false)
function mover(evento) {
    sobreAgente.value = (motor?.agenteEn(evento.clientX, evento.clientY) ?? null) !== null
}

onMounted(async () => {
    await nextTick()
    motor = new OficinaPixel(lienzo.value, { quieto })
    pasarDatos()

    observadorTamano = new ResizeObserver(([e]) => motor?.redimensionar(e.contentRect.width, window.devicePixelRatio || 1))
    observadorTamano.observe(contenedor.value)

    observadorVista = new IntersectionObserver(([e]) => { aLaVista = e.isIntersecting; animarSiToca() })
    observadorVista.observe(lienzo.value)

    document.addEventListener('visibilitychange', alCambiarVisibilidad)
    document.addEventListener('fullscreenchange', alCambiarPantallaCompleta)
    animarSiToca()
})

onUnmounted(() => {
    motor?.detener()
    motor = null
    observadorTamano?.disconnect()
    observadorVista?.disconnect()
    document.removeEventListener('visibilitychange', alCambiarVisibilidad)
    document.removeEventListener('fullscreenchange', alCambiarPantallaCompleta)
    clearInterval(rotacion)
})

watch(() => props.agentes, pasarDatos, { deep: true })
watch(elegidoId, (id) => motor?.elegir(id))
watch([empresa, director], pasarDatos)

// ─── La ventana de chat ──────────────────────────────────────────────────────

// Si nadie está elegido, la ventana va rotando entre los que tienen algo que contar.
const PRIORIDAD = ['trabajando', 'aprobacion', 'error', 'terminado', 'libre']
const turnoChat = ref(0)
const rotacion = setInterval(() => { turnoChat.value++ }, 8000)

const enChat = computed(() => {
    if (elegido.value) return elegido.value
    const candidatos = props.agentes.filter(a => a.estado !== 'apagado')
        .sort((a, b) => PRIORIDAD.indexOf(a.estado) - PRIORIDAD.indexOf(b.estado))
    const conAlgo = candidatos.filter(a => ['trabajando', 'aprobacion', 'error'].includes(a.estado))
    const lista = conAlgo.length ? conAlgo : candidatos
    return lista.length ? lista[turnoChat.value % lista.length] : null
})

const nombreDirector = computed(() => (director.value || 'Director').split(' ')[0])

const respuesta = computed(() => {
    const a = enChat.value
    if (! a) return ''
    switch (a.estado) {
        case 'trabajando':
            return [a.actual?.accion, a.actual?.detalle].filter(Boolean).join('. ') || 'Ya estoy en eso.'
        case 'aprobacion':
            return `Te dejé ${a.pendientes} ${a.pendientes === 1 ? 'propuesta' : 'propuestas'} para revisar. No hago nada hasta que digas.`
        case 'error':
            return `Tuve un problema: ${a.ultima?.error || 'no pude terminar'}.`
        case 'terminado':
            return a.ultima?.resumen || 'Terminé. Todo en orden.'
        default:
            return 'Todo al día. Cuando quieras me pones a trabajar.'
    }
})

// ─── El tablero ──────────────────────────────────────────────────────────────

const ESTADO_OFICINA = {
    operativo: ['OPERATIVO', 'text-emerald-400'],
    pausa:     ['EN PAUSA', 'text-amber-400'],
    sin_ia:    ['SIN IA', 'text-red-400'],
}

const numero = (n) => new Intl.NumberFormat('es-CO').format(n ?? 0)

const filasTablero = computed(() => {
    const t = props.tablero
    if (! t) return []
    const filas = [
        ['Agentes activos', numero(t.agentes_activos)],
        ['Trabajando', numero(t.trabajando)],
        ['Por aprobar', numero(t.por_aprobar)],
        ['Corridas hoy', numero(t.corridas_hoy)],
        ['Tokens de IA hoy', numero(t.tokens_hoy)],
    ]
    if (t.ventas_hoy !== null && t.ventas_hoy !== undefined) {
        filas.push([`Vendido hoy (${monedaBase()})`, formatCOP(t.ventas_hoy)])
        filas.push(['Órdenes en curso', `${numero(t.ops_en_curso)}${t.ops_nuevas_hoy ? ` (+${t.ops_nuevas_hoy} hoy)` : ''}`])
    }
    return filas
})

// ─── Pantalla completa (para dejarla en un televisor) ───────────────────────

const completa = ref(false)

function alternarPantallaCompleta() {
    if (document.fullscreenElement) document.exitFullscreen?.()
    else escena.value?.requestFullscreen?.()
}

function alCambiarPantallaCompleta() {
    completa.value = document.fullscreenElement === escena.value
}

const puedePantallaCompleta = typeof document !== 'undefined' && !! document.documentElement?.requestFullscreen
</script>

<template>
    <div>
        <div v-if="! iaLista" class="mb-3 rounded-xl border border-borde-aviso-ambar bg-pastel-ambar text-aviso-ambar text-sm p-3">
            La IA no está configurada en esta instalación: los agentes no pueden correr hasta que se conecte.
        </div>

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
                    Pantalla completa
                </button>
                <button v-if="permisos.gestionar" type="button" @click="emit('nuevo')"
                    class="px-3 py-1.5 rounded-xl text-white text-xs font-semibold" style="background:var(--marca);">
                    + Nuevo agente
                </button>
            </div>
        </div>

        <div>
            <!-- La escena, a todo el ancho. En pantalla completa se lleva consigo el chat y el tablero. -->
            <div ref="escena" :class="['escena relative rounded-2xl overflow-hidden', completa ? 'flex flex-col justify-center p-4' : '']">
                <div ref="contenedor" class="overflow-x-auto">
                    <canvas ref="lienzo" role="img"
                        :aria-label="`Oficina de ${empresa || 'la empresa'}: ${cuenta.trabajando} agentes trabajando`"
                        :class="['block mx-auto', sobreAgente ? 'cursor-pointer' : 'cursor-default']"
                        @click="tocar" @mousemove="mover" />
                </div>

                <p v-if="cargando && ! agentes.length" class="absolute inset-0 flex items-center justify-center text-sm text-slate-300">Abriendo la oficina…</p>

                <!-- Debajo de la escena; en pantalla completa, flotando sobre ella como en un juego -->
                <div :class="completa ? '' : 'grid md:grid-cols-2 gap-2 p-2 items-start'">
                <!-- Ventana de chat: el director pregunta y el agente responde con lo que de verdad hace -->
                <div v-if="enChat" :class="['ventana', completa ? 'absolute bottom-4 left-4 w-80' : '']">
                    <div class="flex items-center justify-between px-2.5 py-1 border-b border-sky-900/60 text-[10px] tracking-widest text-sky-300">
                        <span>CHAT · {{ enChat.nombre.toUpperCase() }}</span>
                        <span aria-hidden="true">_ ▢ ×</span>
                    </div>
                    <div class="px-2.5 py-2 space-y-1.5 text-[11px] leading-snug">
                        <p><span class="text-emerald-300 font-semibold">{{ nombreDirector }}:</span> <span class="text-slate-200">¿Cómo vas, {{ enChat.nombre.split(' ')[0] }}?</span></p>
                        <p><span class="text-sky-300 font-semibold">{{ enChat.nombre }}:</span> <span class="text-slate-200">{{ respuesta }}</span></p>
                    </div>
                    <button type="button" @click="elegir(enChat.id)"
                        class="w-full text-left px-2.5 py-1.5 border-t border-sky-900/60 text-[10px] text-sky-300 hover:text-white">
                        {{ elegidoId === enChat.id ? 'Cerrar su ficha' : 'Abrir su ficha →' }}
                    </button>
                </div>

                <!-- Tablero de control -->
                <div v-if="filasTablero.length" :class="['ventana', completa ? 'absolute bottom-4 right-4 w-72' : '']">
                    <p class="px-2.5 py-1.5 border-b border-sky-900/60 text-[11px] tracking-widest text-sky-300 font-semibold">TABLERO DE CONTROL</p>
                    <dl class="px-2.5 py-2 space-y-1 text-[11px]">
                        <div v-for="[etiqueta, valor] in filasTablero" :key="etiqueta" class="flex justify-between gap-3">
                            <dt class="text-slate-300 uppercase tracking-wide">{{ etiqueta }}:</dt>
                            <dd class="text-white font-semibold tabular-nums">{{ valor }}</dd>
                        </div>
                        <div class="flex justify-between gap-3 pt-1 border-t border-sky-900/40">
                            <dt class="text-slate-300 uppercase tracking-wide">Estado:</dt>
                            <dd :class="['font-bold', ESTADO_OFICINA[tablero.estado]?.[1]]">{{ ESTADO_OFICINA[tablero.estado]?.[0] }}</dd>
                        </div>
                    </dl>
                </div>
                </div>
            </div>

            <!-- La ficha del agente elegido. Celular: hoja que sube desde abajo; computador: panel flotante a la derecha. -->
            <aside v-if="elegido"
                class="fixed inset-x-0 bottom-0 z-50 max-h-[75vh] overflow-y-auto rounded-t-2xl border-t border-linea bg-superficie p-4 pb-[calc(1rem+env(safe-area-inset-bottom))] shadow-xl
                       lg:inset-x-auto lg:right-6 lg:top-24 lg:bottom-auto lg:w-80 lg:max-h-[80vh] lg:rounded-2xl lg:border lg:pb-4"
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

        </div>

        <!-- Los mismos agentes como botones: para elegir con el teclado o un lector de pantalla -->
        <p class="text-xs text-tinta-400 mt-3 mb-1.5">Toca a un agente en la oficina, o aquí, para ver qué está haciendo y editarlo.</p>
        <div class="flex flex-wrap gap-2">
            <button v-for="a in agentes" :key="a.id" type="button" @click="elegir(a.id)" :aria-pressed="elegidoId === a.id"
                :class="['inline-flex items-center gap-1.5 text-[11px] px-2.5 py-1 rounded-full border transition-colors',
                    elegidoId === a.id ? 'border-[var(--marca)] text-tinta-900 font-semibold' : 'border-linea text-tinta-500 hover:bg-realce']">
                <span aria-hidden="true">{{ a.avatar }}</span>{{ a.nombre }}
                <span :class="['px-1.5 rounded-full text-[10px]', ESTADOS[a.estado]?.chip]">{{ ESTADOS[a.estado]?.texto }}</span>
            </button>
        </div>
    </div>
</template>

<style scoped>
/* La escena es una ilustración con su propia paleta, como la pantalla de un juego: oscura de día
   y de noche. Las ventanas imitan una consola. */
.escena { background: #0b1220; }
.ventana {
    background: rgba(8, 15, 30, 0.92);
    border: 1px solid #1e3a5f;
    border-radius: 6px;
    box-shadow: 0 0 0 1px rgba(56, 189, 248, 0.15), 0 8px 24px rgba(0, 0, 0, 0.35);
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}
</style>
