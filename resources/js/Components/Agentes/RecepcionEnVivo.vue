<script setup>
/**
 * La recepción en vivo: los agentes de atención a clientes detrás del mostrador, y cada
 * conversación activa de la bandeja como un cliente que llega, hace fila, espera o pasa al asesor.
 *
 * La escena la pinta `RecepcionPixel` (resources/js/oficina/recepcion.js). Los datos salen de
 * `/api/agentes-ia/recepcion`, que respeta el permiso de la bandeja y la sede, y del cliente solo
 * manda el nombre con la inicial del apellido. Se pide al montar la pestaña y cada 15 segundos
 * mientras el lienzo está a la vista y la pestaña del navegador abierta; fuera de esta pantalla
 * no cuesta nada.
 *
 * Tocar a un agente abre su configuración; tocar a un cliente abre su conversación en la bandeja.
 */
import { ref, computed, watch, onMounted, onUnmounted, nextTick } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { get } from '@/composables/useEquipoIa'
import { RecepcionPixel } from '@/oficina/recepcion'

const emit = defineEmits(['configurar'])

const pagina  = usePage()
const empresa = computed(() => pagina.props.marca?.nombre ?? '')

const datos    = ref({ agentes: [], clientes: [], puede_ver: false, tablero: null })
const cargando = ref(true)
const elegido  = ref(null)

const lienzo     = ref(null)
const contenedor = ref(null)
let motor = null
let observadorTamano = null
let observadorVista = null
let aLaVista = true
let temporizador = null

const quieto = typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches

async function cargar() {
    const r = await get('/api/agentes-ia/recepcion')
    cargando.value = false
    if (r.ok) datos.value = r.data
}

function programar() {
    clearTimeout(temporizador)
    temporizador = setTimeout(async () => {
        if (aLaVista && ! document.hidden) await cargar()
        programar()
    }, 15000)
}

function pasarDatos() {
    motor?.datos({ agentes: datos.value.agentes, clientes: datos.value.clientes, empresa: empresa.value, elegido: elegido.value })
}

function animarSiToca() {
    if (! motor) return
    if (aLaVista && ! document.hidden) motor.iniciar()
    else motor.detener()
}

function alCambiarVisibilidad() {
    animarSiToca()
    if (! document.hidden && aLaVista) cargar()
}

function tocar(evento) {
    const cosa = motor?.queHayEn(evento.clientX, evento.clientY)
    if (cosa?.tipo === 'agente') configurar(cosa.id)
    else if (cosa?.tipo === 'cliente') abrirConversacion(cosa.id)
}

const sobreAlgo = ref(false)
function mover(evento) {
    const cosa = motor?.queHayEn(evento.clientX, evento.clientY)
    sobreAlgo.value = cosa?.tipo === 'agente' || cosa?.tipo === 'cliente'
}

function configurar(id) {
    elegido.value = id
    emit('configurar', id)
}

function abrirConversacion(clave) {
    router.visit(`/bandeja?conv=${encodeURIComponent(clave)}`)
}

onMounted(async () => {
    await nextTick()
    motor = new RecepcionPixel(lienzo.value, { quieto })
    pasarDatos()

    observadorTamano = new ResizeObserver(([e]) => motor?.redimensionar(e.contentRect.width, window.devicePixelRatio || 1))
    observadorTamano.observe(contenedor.value)

    observadorVista = new IntersectionObserver(([e]) => { aLaVista = e.isIntersecting; animarSiToca() })
    observadorVista.observe(lienzo.value)

    document.addEventListener('visibilitychange', alCambiarVisibilidad)
    animarSiToca()
    await cargar()
    programar()
})

onUnmounted(() => {
    motor?.detener()
    motor = null
    clearTimeout(temporizador)
    observadorTamano?.disconnect()
    observadorVista?.disconnect()
    document.removeEventListener('visibilitychange', alCambiarVisibilidad)
})

watch(datos, pasarDatos)
watch(empresa, pasarDatos)
watch(elegido, (id) => motor?.elegir(id))

// ─── Lo que va en HTML ───────────────────────────────────────────────────────

const numero = (n) => new Intl.NumberFormat('es-CO').format(n ?? 0)

const filasTablero = computed(() => {
    const t = datos.value.tablero
    if (! t) return []
    const filas = [['Agentes atendiendo', numero(t.agentes_activos)]]
    if (t.activas !== null) {
        filas.push(['Conversaciones activas', numero(t.activas)])
        filas.push(['Sin leer', numero(t.sin_leer)])
        filas.push(['Sin asignar', numero(t.sin_asignar)])
    }
    if (t.leads_hoy !== null) filas.push(['Clientes nuevos hoy', numero(t.leads_hoy)])
    return filas
})

const enFila = computed(() => datos.value.clientes.filter(c => c.esperando && ! c.humano && ! c.escalada).length)

const RED = { whatsapp: 'WhatsApp', instagram: 'Instagram', facebook: 'Facebook', web: 'Web' }
</script>

<template>
    <div class="mb-5">
        <div class="escena relative rounded-2xl overflow-hidden">
            <div ref="contenedor" class="overflow-x-auto">
                <canvas ref="lienzo" role="img"
                    :aria-label="`Recepción: ${datos.agentes.filter(a => a.activo).length} agentes atendiendo, ${datos.clientes.length} clientes en conversación`"
                    :class="['block mx-auto', sobreAlgo ? 'cursor-pointer' : 'cursor-default']"
                    @click="tocar" @mousemove="mover" />
            </div>

            <p v-if="cargando" class="absolute inset-0 flex items-center justify-center text-sm text-slate-300">Abriendo la recepción…</p>

            <div class="grid md:grid-cols-2 gap-2 p-2 items-start">
                <div class="ventana">
                    <p class="px-2.5 py-1.5 border-b border-sky-900/60 text-[11px] tracking-widest text-sky-300 font-semibold">EN LA RECEPCIÓN</p>
                    <div class="px-2.5 py-2 text-[11px] leading-snug text-slate-200 space-y-1">
                        <p v-if="! datos.puede_ver">Para ver a los clientes que llegan necesitas acceso a la bandeja.</p>
                        <p v-else-if="! datos.clientes.length">Nadie en conversación en las últimas 24 horas.</p>
                        <template v-else>
                            <p><span class="text-cyan-300 font-semibold">{{ enFila }}</span> {{ enFila === 1 ? 'espera' : 'esperan' }} respuesta en el mostrador.</p>
                            <p class="text-slate-400">Toca a un cliente para abrir su conversación, o a un agente para configurarlo.</p>
                        </template>
                    </div>
                </div>

                <div v-if="filasTablero.length" class="ventana">
                    <p class="px-2.5 py-1.5 border-b border-sky-900/60 text-[11px] tracking-widest text-sky-300 font-semibold">TABLERO DE ATENCIÓN</p>
                    <dl class="px-2.5 py-2 space-y-1 text-[11px]">
                        <div v-for="[etiqueta, valor] in filasTablero" :key="etiqueta" class="flex justify-between gap-3">
                            <dt class="text-slate-300 uppercase tracking-wide">{{ etiqueta }}:</dt>
                            <dd class="text-white font-semibold tabular-nums">{{ valor }}</dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>

        <!-- Lo mismo como botones: para el teclado y los lectores de pantalla -->
        <div v-if="datos.clientes.length" class="flex flex-wrap gap-2 mt-3">
            <button v-for="c in datos.clientes" :key="c.clave" type="button" @click="abrirConversacion(c.clave)"
                class="inline-flex items-center gap-1.5 text-[11px] px-2.5 py-1 rounded-full border border-linea text-tinta-500 hover:bg-realce transition-colors">
                {{ c.nombre }}
                <span class="px-1.5 rounded-full text-[10px] bg-tinta-100 text-tinta-500">{{ RED[c.red] ?? c.canal }}</span>
                <span v-if="c.humano || c.escalada" class="px-1.5 rounded-full text-[10px] bg-pastel-naranja-2 text-aviso-naranja">con asesor</span>
                <span v-else-if="c.esperando" class="px-1.5 rounded-full text-[10px] bg-pastel-azul-2 text-aviso-azul">esperando</span>
            </button>
        </div>
    </div>
</template>

<style scoped>
/* La escena es una ilustración con su propia paleta, oscura de día y de noche, igual que la
   oficina del Equipo de IA. */
.escena { background: #0b1220; }
.ventana {
    background: rgba(8, 15, 30, 0.92);
    border: 1px solid #1e3a5f;
    border-radius: 6px;
    box-shadow: 0 0 0 1px rgba(56, 189, 248, 0.15), 0 8px 24px rgba(0, 0, 0, 0.35);
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}
</style>
