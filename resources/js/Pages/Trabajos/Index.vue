<script setup>
/**
 * El tablero de Trabajos: una ficha grande por unidad, con un botón por paso.
 *
 * Es la misma pieza que usa Calidad, y a propósito: el gesto es el mismo —mirar la unidad,
 * tocar el paso, seguir— y quien está en planta no tiene por qué aprender dos pantallas. Antes
 * esto era una tabla con puntitos de progreso: para marcar un paso había que entrar al
 * trabajo, bajar hasta el paso y abrirlo. Ocho toques para lo que ahora es uno.
 */
import { ref, computed, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import OrdenarLista from '@/Components/OrdenarLista.vue'
import FichaProceso from '@/Components/FichaProceso.vue'
import ModalBodegasEntrega from '@/Components/ModalBodegasEntrega.vue'
import { useOrden } from '@/composables/useOrden'

const props = defineProps({
    trabajos:               { type: Object, default: () => ({}) },
    operarios:              { type: Array,  default: () => [] },
    templates:              { type: Array,  default: () => [] },
    filters:                { type: Object, default: () => ({}) },
    metricas:               { type: Object, default: () => ({}) },
    variables_disponibles:  { type: Array,  default: () => [] },
    pasos_disponibles:      { type: Array,  default: () => [] },
    bodegas:                { type: Array,  default: () => [] },
    // El orden vigente, que decide el servidor: { campo, dir }.
    orden: { type: Object, default: () => ({}) },
})

// Ordenar mantiene los filtros: reordenar no es empezar de cero.
const { ordenarPor } = useOrden('/trabajos', props.orden, props.filters)

const camposOrden = [
    { campo: 'updated_at', etiqueta: 'Último movimiento', texto: false },
    { campo: 'porcentaje_avance', etiqueta: 'Avance', texto: false },
    { campo: 'numero_unidad', etiqueta: 'Unidad', texto: false },
]

const csrf = () => {
    const c = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))
    return c ? decodeURIComponent(c.split('=')[1]) : ''
}

const cabeceras = () => ({
    Accept: 'application/json',
    'X-XSRF-TOKEN': csrf(),
    'X-Requested-With': 'XMLHttpRequest',
})

// ── Filtros ───────────────────────────────────────────────────────────────────
const filtros = ref({
    op_numero:   props.filters?.op_numero   ?? '',
    template_id: props.filters?.template_id ?? '',
    operario_id: props.filters?.operario_id ?? '',
    estado:      props.filters?.estado      ?? 'activos',
    variable:    props.filters?.variable    ?? '',
    paso:        props.filters?.paso        ?? '',
})

const lista      = ref(props.trabajos?.data ?? [])
const paginacion = ref({ current_page: 1, last_page: 1, total: 0, ...(props.trabajos ?? {}) })
const cargando   = ref(false)
const ocupadas   = ref(new Set())
const avisos     = ref({})

const formatTiempo = (min) => {
    if (!min) return '0 min'
    if (min < 60) return `${min} min`
    const h = Math.floor(min / 60)
    const m = min % 60
    return m > 0 ? `${h}h ${m}min` : `${h}h`
}

// ── Fetch con filtros ─────────────────────────────────────────────────────────
let debounceTimer = null

const fetchTrabajo = async (page = 1) => {
    cargando.value = true
    try {
        const params = new URLSearchParams()
        if (filtros.value.op_numero)   params.set('op_numero',   filtros.value.op_numero)
        if (filtros.value.template_id) params.set('template_id', filtros.value.template_id)
        if (filtros.value.operario_id) params.set('operario_id', filtros.value.operario_id)
        if (filtros.value.estado)      params.set('estado',      filtros.value.estado)
        if (filtros.value.variable)    params.set('variable',    filtros.value.variable)
        if (filtros.value.paso)        params.set('paso',        filtros.value.paso)
        params.set('page', page)

        const res  = await fetch(`/trabajos?${params}`, { headers: cabeceras(), credentials: 'same-origin' })
        const data = await res.json()
        lista.value      = data.data ?? []
        paginacion.value = data
    } catch (e) {
        console.error('Error cargando trabajos:', e)
    } finally {
        cargando.value = false
    }
}

watch(filtros, () => {
    clearTimeout(debounceTimer)
    debounceTimer = setTimeout(() => fetchTrabajo(1), 350)
}, { deep: true })

const irPagina = (page) => {
    if (page < 1 || page > paginacion.value.last_page) return
    fetchTrabajo(page)
}

// ── Marcar pasos desde el tablero ─────────────────────────────────────────────
function marcarOcupada(id, si) {
    const s = new Set(ocupadas.value)
    si ? s.add(id) : s.delete(id)
    ocupadas.value = s
}

/**
 * Marca o desmarca un paso. Devuelve si salió bien.
 *
 * El paso final descuenta materiales y mete la unidad a bodega, así que cuando eso pasa se
 * dice: un movimiento de inventario no puede ocurrir en silencio.
 */
async function alternarPaso(t, paso, bodegas = null) {
    marcarOcupada(t.id, true)
    avisos.value[t.id] = ''

    try {
        const res = await fetch(`/trabajos/pasos/${paso.id}`, {
            method:  'PUT',
            headers: { ...cabeceras(), 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ completado: ! paso.completado, ...(bodegas ?? {}) }),
        })
        const data = await res.json()

        if (! data.success) {
            avisos.value[t.id] = data.message ?? 'No se pudo guardar el paso.'
            return false
        }

        aplicarPaso(t, paso.id, data)

        if (data.entregada_en) {
            avisos.value[t.id] = `La unidad entró a ${data.entregada_en} y sus materiales se descontaron.`
        }

        return true
    } catch {
        avisos.value[t.id] = 'No se pudo guardar el paso.'
        return false
    } finally {
        marcarOcupada(t.id, false)
    }
}

function aplicarPaso(t, pasoId, data) {
    const p = t.pasos.find(x => x.id === pasoId)
    if (p) p.completado = data.paso.completado

    t.porcentaje_avance  = data.porcentaje_avance
    t.pasos_completados  = t.pasos.filter(x => x.completado).length
    t.iniciado           = t.pasos_completados > 0

    // Una dependencia que se acaba de cumplir desbloquea lo que colgaba de ella. Recalcularlo
    // aquí evita tener que recargar la página para poder tocar el paso siguiente.
    t.pasos.forEach(x => {
        x.bloqueado = (x.depende_de ?? []).length > 0
            && t.pasos.some(d => (x.depende_de ?? []).includes(d.orden) && ! d.completado)
    })
}

/**
 * Cierra la unidad: marca lo que falte, en orden, respetando las dependencias.
 *
 * El paso final va aparte y al final: es el que entrega la unidad a bodega, y para eso hay que
 * preguntar las dos bodegas. Cerrarlo dentro del recorrido lo intentaría sin respuesta.
 */
async function terminarUnidad(t) {
    // Una unidad sin pasos no se puede terminar, y el botón no puede quedarse mudo: la
    // plantilla se guardó sin flujo de producción y eso hay que decirlo, no esconderlo.
    if (! t.pasos?.length) {
        avisos.value[t.id] = 'Esta unidad no tiene pasos de producción. '
            + 'Cárgalos en la ficha del ensamble y vuelve a generar el trabajo.'
        return
    }

    // Ya está toda cerrada: el botón «Terminada» es el acuse de que salió a calidad, y lo
    // dice con su hora. Antes se quedaba mudo al tocarlo y parecía roto — que es exactamente
    // lo que era: un botón que no responde no está terminado, está muerto.
    if (t.pasos_completados === t.pasos.length) {
        avisos.value[t.id] = t.terminado_at
            ? `Esta unidad salió de producción el ${t.terminado_at} y está en Calidad.`
            : 'Esta unidad ya tiene todos sus pasos cerrados.'
        return
    }

    const pendientes = [...t.pasos]
        .sort((a, b) => a.orden - b.orden)
        .filter(p => ! p.completado)

    // El que entrega es el marcado como final; si la plantilla es vieja y no marcó ninguno,
    // lo es el último. Sin este respaldo la unidad se cerraba entera y no entraba a bodega.
    const final = pendientes.find(p => p.es_paso_final) ?? pendientes[pendientes.length - 1]

    for (const paso of pendientes.filter(p => p !== final)) {
        const ok = await alternarPaso(t, paso)
        if (! ok) return
    }

    if (final) pedirBodegas(t, final)
}

// ── El paso que entrega ───────────────────────────────────────────────────────
const modalBodegas = ref({ abierto: false, trabajo: null, paso: null, error: '' })

function pedirBodegas(t, paso) {
    modalBodegas.value = { abierto: true, trabajo: t, paso, error: '' }
}

async function confirmarBodegas(valores) {
    const { trabajo, paso } = modalBodegas.value
    const entregada = avisos.value[trabajo.id]
    const ok = await alternarPaso(trabajo, paso, valores)

    if (! ok) {
        modalBodegas.value.error = avisos.value[trabajo.id] || 'No se pudo cerrar la unidad.'
        return
    }

    modalBodegas.value = { abierto: false, trabajo: null, paso: null, error: '' }
    sacarDelTablero(trabajo)
}

/**
 * La unidad terminada se va del tablero.
 *
 * Este es la hoja de trabajo de la planta, no el archivo: lo que ya salió a calidad empuja
 * hacia abajo lo que todavía hay que fabricar. Se dice a dónde fue, porque una ficha que
 * desaparece sin explicación se lee como que se perdió algo.
 *
 * Vuelve sola si calidad la devuelve a reproceso: ahí deja de estar completa.
 */
function sacarDelTablero(t) {
    if (filtros.value.estado !== 'activos') return
    if (t.pasos_completados !== t.pasos_total) return

    lista.value = lista.value.filter(x => x.id !== t.id)
    paginacion.value.total = Math.max(0, (paginacion.value.total ?? 1) - 1)

    const bodega = avisos.value[t.id]?.match(/entró a ([^y]+) y/)?.[1]?.trim()

    salida.value = `${numeroDe(t)}${sufijoDe(t)} salió de producción y pasó a Calidad`
        + (bodega ? `. La unidad entró a ${bodega} y sus materiales se descontaron.` : '.')

    delete avisos.value[t.id]
}

// El acuse de lo último que salió del tablero.
const salida = ref('')

/**
 * Un toque en un paso. El final abre la hoja de las bodegas en vez de cerrarse solo: entrega
 * la unidad a inventario, y eso no puede pasar sin que alguien diga dónde.
 */
async function tocarPaso(t, paso) {
    if (! paso) return

    // El último paso de una plantilla que no marcó ninguno como final entrega igual.
    const esElQueEntrega = paso.es_paso_final
        || (! t.pasos.some(p => p.es_paso_final) && paso.orden === Math.max(...t.pasos.map(p => p.orden)))

    if (esElQueEntrega && ! paso.completado) {
        pedirBodegas(t, paso)
        return
    }

    if (await alternarPaso(t, paso)) sacarDelTablero(t)
}

const page = usePage()
const puedeEliminar = computed(() =>
    ['administrador', 'jefe_produccion'].includes(page.props.auth?.user?.rol)
)

async function eliminarTrabajo(t) {
    if (!confirm(`¿Eliminar el trabajo ${t.op_numero} — ${t.item_descripcion}? Se perderá todo el progreso.`)) return
    try {
        cargando.value = true
        const res = await fetch(`/trabajos/${t.id}`, {
            method: 'DELETE',
            headers: cabeceras(),
            credentials: 'same-origin',
        })
        if (res.ok) {
            lista.value = lista.value.filter(item => item.id !== t.id)
            paginacion.value.total = Math.max(0, (paginacion.value.total ?? 1) - 1)
        }
    } catch (e) {
        console.error('Error eliminando trabajo:', e)
    } finally {
        cargando.value = false
    }
}

// ── Lo que ve la ficha ────────────────────────────────────────────────────────
const botonesDe = (t) => (t.pasos ?? []).map(p => ({
    id:        p.id,
    label:     p.nombre,
    estado:    p.completado ? 'ok' : 'pendiente',
    bloqueado: !! p.bloqueado,
    nota:      p.bloqueado ? 'Espera a que termine el paso del que depende' : '',
}))

const chipsDe = (t) => (t.variables_etiquetadas ?? []).map(v => `${v.etiqueta}: ${v.valor}`)

/**
 * Las fechas del proceso, tal como quedaron registradas.
 *
 * Nadie las escribe: se ponen solas al marcar el primer paso y al cerrar el último. La de
 * cierre es además la hora en que la unidad llegó a Calidad — es el mismo instante.
 */
const fechasDe = (t) => [
    t.iniciado_at  && { etiqueta: 'Inicio', valor: t.iniciado_at },
    t.terminado_at && { etiqueta: 'A calidad', valor: t.terminado_at },
].filter(Boolean)

const sufijoDe = (t) => t.total_unidades > 1 ? `−${t.numero_unidad}` : ''

// El número de la OP viene ya con «[1/3]» pegado desde el servidor para las listas viejas;
// aquí el sufijo es su propia pieza, así que se quita para no decirlo dos veces.
const numeroDe = (t) => (t.op_numero ?? '').replace(/\s*\[\d+\/\d+\]\s*/, '')
</script>

<template>
    <AppLayout title="Trabajos de Producción">

        <!-- ── Topbar ────────────────────────────────────────────────────────── -->
        <div class="flex items-center justify-between mb-4">
            <div>
                <h2 class="text-lg font-semibold text-tinta-900">Trabajos de Producción</h2>
                <p class="text-sm text-tinta-400 mt-0.5">{{ paginacion.total ?? 0 }} trabajo(s) registrado(s)</p>
            </div>
        </div>

        <!-- ── Dashboard métricas ────────────────────────────────────────────── -->
        <div class="mb-4 space-y-4">

            <!-- Fila 1: cards de estado (clickeables) -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <button @click="filtros.estado = filtros.estado === 'sin_iniciar' ? 'activos' : 'sin_iniciar'"
                    class="bg-superficie rounded-2xl border shadow-sm px-4 py-4 text-center w-full transition-all hover:shadow-md"
                    :class="filtros.estado === 'sin_iniciar' ? 'border-[var(--marca)] ring-2 ring-[var(--marca-suave)]' : 'border-linea'">
                    <p class="text-2xl font-semibold text-tinta-400">{{ metricas.sin_iniciar ?? 0 }}</p>
                    <p class="text-xs text-tinta-300 mt-1">Sin iniciar</p>
                </button>
                <button @click="filtros.estado = filtros.estado === 'en_progreso' ? 'activos' : 'en_progreso'"
                    class="bg-superficie rounded-2xl border shadow-sm px-4 py-4 text-center w-full transition-all hover:shadow-md"
                    :class="filtros.estado === 'en_progreso' ? 'border-[var(--marca)] ring-2 ring-[var(--marca-suave)]' : 'border-borde-aviso-ambar'">
                    <p class="text-2xl font-semibold text-aviso-ambar">{{ metricas.en_progreso ?? 0 }}</p>
                    <p class="text-xs text-tinta-300 mt-1">En progreso</p>
                </button>
                <button @click="filtros.estado = filtros.estado === 'reproceso' ? 'activos' : 'reproceso'"
                    class="bg-superficie rounded-2xl border shadow-sm px-4 py-4 text-center w-full transition-all hover:shadow-md"
                    :class="filtros.estado === 'reproceso' ? 'border-[var(--marca)] ring-2 ring-[var(--marca-suave)]' : 'border-borde-aviso-naranja'">
                    <p class="text-2xl font-semibold text-aviso-naranja">{{ metricas.reproceso ?? 0 }}</p>
                    <p class="text-xs text-tinta-300 mt-1">En reproceso</p>
                </button>
                <button @click="filtros.estado = filtros.estado === 'completado' ? 'activos' : 'completado'"
                    class="bg-superficie rounded-2xl border shadow-sm px-4 py-4 text-center w-full transition-all hover:shadow-md"
                    :class="filtros.estado === 'completado' ? 'border-[var(--marca)] ring-2 ring-[var(--marca-suave)]' : 'border-borde-aviso-verde'">
                    <p class="text-2xl font-semibold text-aviso-verde">{{ metricas.completados ?? 0 }}</p>
                    <p class="text-xs text-tinta-300 mt-1">Terminados</p>
                </button>
            </div>

            <!-- Fila 2: pasos + top operarios -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                <div class="bg-superficie rounded-2xl border border-linea shadow-sm p-5">
                    <h3 class="text-sm font-semibold text-tinta-700 mb-3">Pasos de trabajo</h3>
                    <div class="flex items-center gap-4 mb-3">
                        <div class="text-center">
                            <p class="text-2xl font-semibold text-aviso-rojo">{{ metricas.pasos_pendientes ?? 0 }}</p>
                            <p class="text-xs text-tinta-300 mt-0.5">Pendientes</p>
                        </div>
                        <div class="flex-1 h-px bg-tinta-100"></div>
                        <div class="text-center">
                            <p class="text-2xl font-semibold text-aviso-verde">{{ metricas.pasos_completados ?? 0 }}</p>
                            <p class="text-xs text-tinta-300 mt-0.5">Completados</p>
                        </div>
                    </div>
                    <div v-if="(metricas.pasos_pendientes ?? 0) + (metricas.pasos_completados ?? 0) > 0"
                        class="h-2 rounded-full bg-tinta-100 overflow-hidden">
                        <div class="h-full rounded-full bg-green-500 transition-all"
                            :style="`width:${Math.round((metricas.pasos_completados / ((metricas.pasos_pendientes ?? 0) + (metricas.pasos_completados ?? 0))) * 100)}%`">
                        </div>
                    </div>
                    <p class="text-xs text-tinta-300 mt-1.5 text-right">
                        {{ metricas.pasos_completados ?? 0 }} de {{ (metricas.pasos_pendientes ?? 0) + (metricas.pasos_completados ?? 0) }} pasos totales
                    </p>
                </div>

                <div class="bg-superficie rounded-2xl border border-linea shadow-sm p-5">
                    <h3 class="text-sm font-semibold text-tinta-700 mb-3">Top operarios por tiempo</h3>
                    <div v-if="metricas.top_operarios?.length" class="space-y-2">
                        <div v-for="(op, idx) in metricas.top_operarios" :key="idx" class="flex items-center gap-3">
                            <span class="w-5 h-5 rounded-full flex items-center justify-center text-xs font-semibold shrink-0"
                                :class="idx === 0 ? 'bg-pastel-ambar-2 text-aviso-ambar'
                                    : idx === 1 ? 'bg-tinta-100 text-tinta-500'
                                    : idx === 2 ? 'bg-pastel-naranja-2 text-aviso-naranja'
                                    : 'bg-tinta-50 text-tinta-300'">
                                {{ idx + 1 }}
                            </span>
                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-medium text-tinta-900 truncate">{{ op.nombre }}</p>
                                <p class="text-xs text-tinta-300">{{ op.pasos }} paso(s)</p>
                            </div>
                            <span class="text-xs font-semibold text-aviso-azul shrink-0">
                                {{ formatTiempo(op.total_minutos) }}
                            </span>
                        </div>
                    </div>
                    <p v-else class="text-xs text-tinta-300 italic py-2">Sin datos de tiempo aún.</p>
                </div>

            </div>
        </div>

        <!-- ── Filtros ───────────────────────────────────────────────────────── -->
        <div class="bg-superficie rounded-2xl shadow-sm border border-linea p-4 mb-4">
            <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                <div>
                    <label class="text-xs font-medium text-tinta-400 mb-1 block">Buscar OP</label>
                    <input v-model="filtros.op_numero" type="text" placeholder="OP-0001..."
                        class="w-full rounded-xl border border-linea px-3 py-2 text-sm bg-superficie focus:outline-none focus:ring-2 focus:ring-[var(--marca)]/30 focus:border-[var(--marca)]" />
                </div>
                <div>
                    <label class="text-xs font-medium text-tinta-400 mb-1 block">Template</label>
                    <select v-model="filtros.template_id"
                        class="w-full rounded-xl border border-linea px-3 py-2 text-sm bg-superficie focus:outline-none focus:ring-2 focus:ring-[var(--marca)]/30 focus:border-[var(--marca)]">
                        <option value="">Todos</option>
                        <option v-for="t in templates" :key="t.id" :value="t.id">{{ t.nombre }}</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-medium text-tinta-400 mb-1 block">Operario</label>
                    <select v-model="filtros.operario_id"
                        class="w-full rounded-xl border border-linea px-3 py-2 text-sm bg-superficie focus:outline-none focus:ring-2 focus:ring-[var(--marca)]/30 focus:border-[var(--marca)]">
                        <option value="">Todos</option>
                        <option v-for="o in operarios" :key="o.id" :value="o.id">{{ o.nombre }}</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-medium text-tinta-400 mb-1 block">Estado</label>
                    <select v-model="filtros.estado"
                        class="w-full rounded-xl border border-linea px-3 py-2 text-sm bg-superficie focus:outline-none focus:ring-2 focus:ring-[var(--marca)]/30 focus:border-[var(--marca)]">
                        <option value="activos">Por fabricar (lo normal)</option>
                        <option value="sin_iniciar">Sin iniciar</option>
                        <option value="en_progreso">En progreso</option>
                        <option value="reproceso">En reproceso</option>
                        <option value="completado">Terminados</option>
                        <option value="">Todos</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-medium text-tinta-400 mb-1 block">Variable</label>
                    <input v-model="filtros.variable" type="text" list="lista-variables" placeholder="Ej: ancho_vano..."
                        class="w-full rounded-xl border border-linea px-3 py-2 text-sm bg-superficie focus:outline-none focus:ring-2 focus:ring-[var(--marca)]/30 focus:border-[var(--marca)]" />
                    <datalist id="lista-variables">
                        <option v-for="v in variables_disponibles" :key="v" :value="v" />
                    </datalist>
                </div>
                <div>
                    <label class="text-xs font-medium text-tinta-400 mb-1 block">Paso de trabajo</label>
                    <select v-model="filtros.paso"
                        class="w-full rounded-xl border border-linea px-3 py-2 text-sm bg-superficie focus:outline-none focus:ring-2 focus:ring-[var(--marca)]/30 focus:border-[var(--marca)]">
                        <option value="">Todos los pasos</option>
                        <option v-for="p in pasos_disponibles" :key="p" :value="p">{{ p }}</option>
                    </select>
                </div>
            </div>

            <!-- Ordenar. En celular es el único camino: ahí no hay encabezados donde hacer clic. -->
            <div class="mt-3 pt-3 border-t border-linea">
                <OrdenarLista :campos="camposOrden" :orden="orden" @ordenar="ordenarPor" />
            </div>
        </div>

        <!-- Lo último que salió del tablero. Una ficha que desaparece sin explicación se lee
             como que se perdió algo. -->
        <div v-if="salida"
            class="bg-pastel-verde border border-borde-aviso-verde rounded-2xl px-4 py-3 mb-4 flex items-start gap-3">
            <svg class="w-5 h-5 text-aviso-verde shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
            </svg>
            <p class="text-sm text-aviso-verde flex-1">{{ salida }}</p>
            <button type="button" @click="salida = ''" class="text-aviso-verde opacity-60 hover:opacity-100">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <!-- ── Las fichas ────────────────────────────────────────────────────── -->
        <div v-if="cargando" class="text-center py-8 text-tinta-300 text-sm">Cargando...</div>

        <div v-else-if="! lista.length"
            class="bg-superficie rounded-2xl border border-linea py-14 text-center">
            <p class="text-sm text-tinta-400">
                {{ filtros.estado === 'activos' ? 'No queda nada por fabricar.' : 'No hay trabajos con ese filtro.' }}
            </p>
            <p v-if="filtros.estado === 'activos'" class="text-xs text-tinta-300 mt-1">
                Lo terminado pasó a Calidad. Toca «Terminados» para verlo.
            </p>
        </div>

        <div v-else class="space-y-3">
            <div v-for="t in lista" :key="t.id" class="relative group">
                <FichaProceso
                    :numero="numeroDe(t)"
                    :sufijo="sufijoDe(t)"
                    :codigo="t.op_item_codigo ?? ''"
                    :titulo="t.item_descripcion ?? '—'"
                    :subtitulo="t.cliente_nombre"
                    :chips="chipsDe(t)"
                    :urgencia="t.urgencia"
                    :marca="t.en_reproceso ? 'En reproceso' : ''"
                    :fechas="fechasDe(t)"
                    :fecha="t.fecha_entrega"
                    :contador="`${t.pasos_completados}/${t.pasos_total}`"
                    :porcentaje="Math.round(t.porcentaje_avance)"
                    :botones="botonesDe(t)"
                    accion="Terminar"
                    accion-hecha="Terminada"
                    :hecha="t.pasos_total > 0 && t.pasos_completados === t.pasos_total"
                    :ocupada="ocupadas.has(t.id)"
                    :aviso="avisos[t.id] ?? ''"
                    @boton="p => tocarPaso(t, t.pasos.find(x => x.id === p.id))"
                    @accion="terminarUnidad(t)"
                    @abrir="router.visit(`/trabajos/${t.id}`)" />

                <!-- Eliminar vive fuera de la ficha: es lo único que no se deshace con otro
                     toque, y no puede estar al lado de los botones que sí. -->
                <button v-if="puedeEliminar" @click.stop="eliminarTrabajo(t)"
                    class="absolute -top-2 -right-2 w-7 h-7 rounded-full bg-superficie border border-borde-aviso-rojo text-aviso-rojo
                           opacity-0 group-hover:opacity-100 focus:opacity-100 transition-opacity flex items-center justify-center shadow-sm"
                    title="Eliminar el trabajo">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                </button>
            </div>
        </div>

        <p v-if="lista.length" class="text-xs text-tinta-300 mt-4 px-1">
            Un toque marca el paso; otro lo deshace. «Terminar» cierra la unidad completa, y el
            paso final descuenta los materiales y la mete a bodega. Toca el número para abrir la
            hoja del trabajo con tiempos, operarios y fotos.
        </p>

        <ModalBodegasEntrega
            :abierto="modalBodegas.abierto"
            :bodegas="bodegas"
            :entrega="modalBodegas.trabajo?.bodegas_sugeridas?.entrega ?? ''"
            :material="modalBodegas.trabajo?.bodegas_sugeridas?.material ?? ''"
            :titulo="`Cerrar ${modalBodegas.paso?.nombre ?? 'el paso final'}`"
            :guardando="modalBodegas.trabajo ? ocupadas.has(modalBodegas.trabajo.id) : false"
            :error="modalBodegas.error"
            @confirmar="confirmarBodegas"
            @cerrar="modalBodegas = { abierto: false, trabajo: null, paso: null, error: '' }" />

        <!-- ── Paginación ────────────────────────────────────────────────────── -->
        <div v-if="paginacion.last_page > 1" class="flex items-center justify-center gap-2 mt-5">
            <button @click="irPagina(paginacion.current_page - 1)" :disabled="paginacion.current_page <= 1"
                class="px-3 py-1.5 rounded-xl border border-linea text-sm font-medium disabled:opacity-40 hover:bg-realce transition-colors">‹ Anterior</button>
            <span class="text-sm text-tinta-500">Página {{ paginacion.current_page }} de {{ paginacion.last_page }}</span>
            <button @click="irPagina(paginacion.current_page + 1)" :disabled="paginacion.current_page >= paginacion.last_page"
                class="px-3 py-1.5 rounded-xl border border-linea text-sm font-medium disabled:opacity-40 hover:bg-realce transition-colors">Siguiente ›</button>
        </div>

    </AppLayout>
</template>
