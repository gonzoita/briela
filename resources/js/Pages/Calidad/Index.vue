<script setup>
/**
 * El tablero de Calidad: una ficha grande por unidad, agrupadas por orden.
 *
 * La revisión se hace de pie y con la unidad enfrente, así que todo está a un toque: el punto
 * se marca en la ficha, la foto se pide cuando el punto la exige, y «Terminar» cierra la
 * unidad entera cuando ya se miró completa. El sello de la orden —el que deja remisionar— se
 * pone en el encabezado del grupo, que es donde de verdad significa algo.
 */
import { ref, computed, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import FichaProceso from '@/Components/FichaProceso.vue'
import ModalFoto from '@/Components/ModalFoto.vue'

const props = defineProps({
    fichas:   { type: Array,  default: () => [] },
    ops:      { type: Array,  default: () => [] },
    metricas: { type: Object, default: () => ({}) },
    filtros:  { type: Object, default: () => ({}) },
})

const csrf = () => {
    const c = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))
    return c ? decodeURIComponent(c.split('=')[1]) : ''
}

const cabeceras = { Accept: 'application/json', 'X-XSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' }

// ── Estado de la pantalla ─────────────────────────────────────────────────────
const fichas   = ref(props.fichas.map(f => ({ ...f })))
const ops      = ref([...props.ops])
const metricas = ref({ ...props.metricas })
const cargando = ref(false)
const ocupadas = ref(new Set())
const avisos   = ref({})

const filtros = ref({
    op_numero: props.filtros?.op_numero ?? '',
    estado:    props.filtros?.estado    ?? 'pendientes',
})

const grupos = computed(() =>
    ops.value.map(op => ({
        ...op,
        fichas: fichas.value.filter(f => f.op_id === op.id),
    })).filter(g => g.fichas.length)
)

// ── Refrescar ─────────────────────────────────────────────────────────────────
let temporizador = null

async function recargar() {
    cargando.value = true
    try {
        const params = new URLSearchParams()
        if (filtros.value.op_numero) params.set('op_numero', filtros.value.op_numero)
        params.set('estado', filtros.value.estado)

        const res  = await fetch(`/calidad/datos?${params}`, { headers: cabeceras, credentials: 'same-origin' })
        const data = await res.json()
        fichas.value   = data.fichas ?? []
        ops.value      = data.ops ?? []
        metricas.value = data.metricas ?? {}
    } catch (e) {
        console.error('No se pudo cargar el tablero de calidad:', e)
    } finally {
        cargando.value = false
    }
}

watch(filtros, () => {
    clearTimeout(temporizador)
    temporizador = setTimeout(recargar, 300)
}, { deep: true })

// ── Marcar un punto ───────────────────────────────────────────────────────────
function marcarOcupada(id, si) {
    const s = new Set(ocupadas.value)
    si ? s.add(id) : s.delete(id)
    ocupadas.value = s
}

function reemplazarCheck(ficha, fila) {
    const i = ficha.checks.findIndex(c => c.id === fila.id)
    if (i >= 0) ficha.checks[i] = { ...ficha.checks[i], ...fila }

    ficha.resueltos  = ficha.checks.filter(c => c.resultado !== 'pendiente').length
    ficha.fallas     = ficha.checks.filter(c => c.resultado === 'falla').length
    ficha.bloquean   = ficha.checks.filter(c => c.resultado === 'pendiente' || (c.resultado === 'falla' && c.es_critico)).length
    ficha.porcentaje = ficha.checks.length ? Math.round(ficha.resueltos / ficha.checks.length * 100) : 0

    recalcularGrupo(ficha.op_id)
}

function recalcularGrupo(opId) {
    const grupo = ops.value.find(o => o.id === opId)
    if (! grupo) return

    const suyas = fichas.value.filter(f => f.op_id === opId)
    grupo.puntos    = suyas.reduce((s, f) => s + f.checks.length, 0)
    grupo.resueltos = suyas.reduce((s, f) => s + f.resueltos, 0)
    grupo.bloquean  = suyas.reduce((s, f) => s + f.bloquean, 0)
}

async function guardarResultado(ficha, check, resultado) {
    marcarOcupada(ficha.id, true)
    avisos.value[ficha.id] = ''

    try {
        const res = await fetch(`/calidad/checks/${check.id}`, {
            method:  'PATCH',
            headers: { ...cabeceras, 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ resultado, observaciones: check.observaciones ?? '' }),
        })
        const data = await res.json()

        if (! res.ok) {
            avisos.value[ficha.id] = data.message ?? 'No se pudo guardar la revisión.'
            return false
        }

        reemplazarCheck(ficha, data)
        return true
    } catch {
        avisos.value[ficha.id] = 'No se pudo guardar la revisión.'
        return false
    } finally {
        marcarOcupada(ficha.id, false)
    }
}

/**
 * Un toque avanza el punto: sin revisar → cumple → sin revisar.
 *
 * La falla se marca aparte —clic derecho, pulsación larga, o desde la ficha de verificación—
 * porque es la excepción: si compartiera el mismo gesto que el cumple, marcar ocho puntos
 * seguidos dejaría fallas puestas sin querer.
 */
function tocarPunto(ficha, boton) {
    const check = ficha.checks.find(c => c.id === boton.id)
    if (! check) return

    if (check.resultado === 'pendiente') {
        // El punto que exige foto no se marca hasta que la foto exista. Es el que después se
        // discute con el cliente.
        if (check.exige_foto && ! check.fotos?.length) {
            pedirFoto(ficha, check, 'cumple')
            return
        }
        guardarResultado(ficha, check, 'cumple')
    } else {
        guardarResultado(ficha, check, 'pendiente')
    }
}

function marcarFalla(ficha, boton) {
    const check = ficha.checks.find(c => c.id === boton.id)
    if (! check) return

    if (check.exige_foto && ! check.fotos?.length) {
        pedirFoto(ficha, check, 'falla')
        return
    }

    guardarResultado(ficha, check, 'falla')
}

// ── La foto que exige un punto ────────────────────────────────────────────────
const modal = ref({ abierto: false, ficha: null, check: null, resultado: 'cumple', cola: [], guardando: false, reintentarCierre: false })

function pedirFoto(ficha, check, resultado, cola = [], reintentarCierre = false) {
    modal.value = { abierto: true, ficha, check, resultado, cola, guardando: false, reintentarCierre }
}

function cerrarModal() {
    modal.value = { ...modal.value, abierto: false, guardando: false }
}

async function subirFoto(archivo) {
    const { ficha, check, resultado, cola, reintentarCierre } = modal.value
    modal.value.guardando = true

    try {
        const fd = new FormData()
        fd.append('fotos[]', archivo)

        const res = await fetch(`/calidad/checks/${check.id}/fotos`, {
            method: 'POST',
            headers: cabeceras,
            credentials: 'same-origin',
            body: fd,
        })

        if (! res.ok) {
            avisos.value[ficha.id] = 'No se pudo subir la foto.'
            cerrarModal()
            return
        }

        reemplazarCheck(ficha, await res.json())

        const fresco = ficha.checks.find(c => c.id === check.id)
        await guardarResultado(ficha, fresco, resultado)

        // Si el cierre de la unidad venía en cola, se sigue con el punto que falte; cuando la
        // cola se acaba se vuelve a intentar el cierre, que ya no tendrá nada que reclamar.
        if (cola.length) {
            const siguiente = ficha.checks.find(c => c.id === cola[0].id)
            pedirFoto(ficha, siguiente, 'cumple', cola.slice(1), reintentarCierre)
            return
        }

        cerrarModal()

        if (reintentarCierre) terminarUnidad(ficha)
    } catch {
        avisos.value[ficha.id] = 'No se pudo subir la foto.'
        cerrarModal()
    }
}

// ── Cerrar una unidad ─────────────────────────────────────────────────────────
async function terminarUnidad(ficha) {
    // «Terminada» se lee de la FIRMA de la unidad, no de su cuenta de puntos. Una unidad sin
    // lista de revisión no tiene puntos que contar, y con el criterio viejo su botón no
    // cambiaba nunca: se apretaba, el servidor no tenía nada que marcar, y volvía igual.
    if (ficha.revisada) {
        // Ya estaba cerrada: el botón la vuelve a abrir, que es lo que se espera de un botón
        // que dice «Terminada».
        return reabrirUnidad(ficha)
    }

    marcarOcupada(ficha.id, true)
    avisos.value[ficha.id] = ''

    try {
        const res  = await fetch(`/calidad/unidades/${ficha.id}/terminar`, {
            method: 'POST', headers: cabeceras, credentials: 'same-origin',
        })
        const data = await res.json()

        if (res.status === 422) {
            avisos.value[ficha.id] = data.message
            const faltan = data.exigen_foto ?? []

            if (faltan.length) {
                const primero = ficha.checks.find(c => c.id === faltan[0].id)
                pedirFoto(ficha, primero, 'cumple', faltan.slice(1), true)
            }
            return
        }

        if (! res.ok) {
            avisos.value[ficha.id] = data.message ?? 'No se pudo cerrar la unidad.'
            return
        }

        aplicarFicha(data.ficha)
    } catch {
        avisos.value[ficha.id] = 'No se pudo cerrar la unidad.'
    } finally {
        marcarOcupada(ficha.id, false)
    }
}

async function reabrirUnidad(ficha) {
    marcarOcupada(ficha.id, true)
    try {
        const res = await fetch(`/calidad/unidades/${ficha.id}/reabrir`, {
            method: 'POST', headers: cabeceras, credentials: 'same-origin',
        })
        if (res.ok) aplicarFicha((await res.json()).ficha)
    } finally {
        marcarOcupada(ficha.id, false)
    }
}

function aplicarFicha(nueva) {
    if (! nueva) return
    const i = fichas.value.findIndex(f => f.id === nueva.id)
    if (i >= 0) fichas.value[i] = nueva
    recalcularGrupo(nueva.op_id)

    const grupo = ops.value.find(o => o.id === nueva.op_id)
    if (grupo) grupo.calidad_aprobada_at = nueva.calidad_aprobada_at
}

// ── Cerrar la orden ───────────────────────────────────────────────────────────
function cerrarOp(op) {
    router.post(`/calidad/ops/${op.id}/terminar`, {}, {
        preserveScroll: true,
        onSuccess: recargar,
    })
}

function mandarAReproceso(op) {
    const motivo = window.prompt(`¿Por qué vuelve ${op.numero} a reproceso?`)
    if (! motivo) return

    router.post(`/calidad/ops/${op.id}/reprocesar`, { motivo_rechazo: motivo }, {
        preserveScroll: true,
        onSuccess: recargar,
    })
}

// ── Lo que ve la ficha ────────────────────────────────────────────────────────
const botonesDe = (ficha) => ficha.checks.map(c => ({
    id:        c.id,
    label:     c.titulo,
    estado:    c.resultado === 'cumple' ? 'ok' : c.resultado === 'falla' ? 'falla' : 'pendiente',
    exigeFoto: c.exige_foto,
    nota:      c.descripcion || (c.es_critico ? 'Punto crítico' : ''),
}))

const chipsDe = (ficha) => ficha.variables.map(v => `${v.etiqueta}: ${v.valor}`)

const sufijoDe = (ficha) => ficha.total_unidades > 1 ? `−${ficha.numero_unidad}` : ''

const cerrada = (ficha) => !! ficha.revisada

const filtrarPor = (estado) => { filtros.value.estado = estado }
</script>

<template>
    <AppLayout title="Calidad">

        <!-- ── Encabezado ───────────────────────────────────────────────────── -->
        <div class="flex items-end justify-between gap-3 flex-wrap mb-4">
            <div>
                <h2 class="text-lg font-semibold text-tinta-900">Control de calidad</h2>
                <p class="text-sm text-tinta-400 mt-0.5">
                    {{ metricas.unidades ?? 0 }} unidad(es) fabricadas en {{ metricas.ops ?? 0 }} orden(es)
                </p>
            </div>
            <button type="button" @click="recargar" :disabled="cargando"
                class="px-3 py-2 rounded-xl border border-linea text-sm font-medium text-tinta-500 hover:bg-realce transition-colors disabled:opacity-40">
                {{ cargando ? 'Actualizando…' : 'Actualizar' }}
            </button>
        </div>

        <!-- ── Métricas, que además filtran ─────────────────────────────────── -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
            <button type="button" @click="filtrarPor('pendientes')"
                class="bg-superficie rounded-2xl border px-4 py-4 text-center transition-all hover:shadow-md"
                :class="filtros.estado === 'pendientes' ? 'border-[var(--marca)] ring-2 ring-[var(--marca-suave)]' : 'border-linea'">
                <p class="text-2xl font-semibold text-tinta-700">{{ metricas.pendientes ?? 0 }}</p>
                <p class="text-xs text-tinta-400 mt-1">Por revisar</p>
            </button>
            <button type="button" @click="filtrarPor('fallas')"
                class="bg-superficie rounded-2xl border px-4 py-4 text-center transition-all hover:shadow-md"
                :class="filtros.estado === 'fallas' ? 'border-[var(--marca)] ring-2 ring-[var(--marca-suave)]' : 'border-borde-aviso-rojo'">
                <p class="text-2xl font-semibold text-aviso-rojo">{{ metricas.fallas ?? 0 }}</p>
                <p class="text-xs text-tinta-400 mt-1">Con fallas</p>
            </button>
            <button type="button" @click="filtrarPor('listas')"
                class="bg-superficie rounded-2xl border px-4 py-4 text-center transition-all hover:shadow-md"
                :class="filtros.estado === 'listas' ? 'border-[var(--marca)] ring-2 ring-[var(--marca-suave)]' : 'border-borde-aviso-verde'">
                <p class="text-2xl font-semibold text-aviso-verde">{{ metricas.listas ?? 0 }}</p>
                <p class="text-xs text-tinta-400 mt-1">Revisadas</p>
            </button>
            <button type="button" @click="filtrarPor('todas')"
                class="bg-superficie rounded-2xl border px-4 py-4 text-center transition-all hover:shadow-md"
                :class="filtros.estado === 'todas' ? 'border-[var(--marca)] ring-2 ring-[var(--marca-suave)]' : 'border-linea'">
                <p class="text-2xl font-semibold text-tinta-700">{{ metricas.unidades ?? 0 }}</p>
                <p class="text-xs text-tinta-400 mt-1">Todas</p>
            </button>
        </div>

        <!-- ── Buscar ───────────────────────────────────────────────────────── -->
        <div class="mb-4">
            <input v-model="filtros.op_numero" type="text" placeholder="Buscar orden…"
                class="w-full md:max-w-xs rounded-xl border border-linea px-3 py-2 text-sm bg-superficie focus:outline-none focus:ring-2 focus:ring-[var(--marca)]/30 focus:border-[var(--marca)]" />
        </div>

        <!-- ── Los grupos ───────────────────────────────────────────────────── -->
        <div v-if="cargando && ! grupos.length" class="text-center py-12 text-tinta-300 text-sm">Cargando…</div>

        <div v-else-if="! grupos.length"
            class="bg-superficie rounded-2xl border border-linea py-14 text-center">
            <p class="text-sm text-tinta-400">No hay unidades esperando revisión.</p>
            <p class="text-xs text-tinta-300 mt-1">
                Aquí entra lo que ya se fabricó por completo y todavía no se despacha.
            </p>
        </div>

        <div v-else class="space-y-6">
            <section v-for="grupo in grupos" :key="grupo.id">

                <!-- Encabezado de la orden: aquí vive el sello que deja remisionar. -->
                <div class="flex items-center gap-3 flex-wrap mb-2 px-1">
                    <button type="button" @click="router.visit(`/produccion/ops/${grupo.id}`)"
                        class="text-sm font-bold text-[var(--marca)] hover:underline">
                        {{ grupo.numero }}
                    </button>
                    <span class="text-sm text-tinta-500 truncate">{{ grupo.cliente }}</span>
                    <span class="text-xs text-tinta-300">
                        {{ grupo.unidades }} unidad(es) · {{ grupo.resueltos }}/{{ grupo.puntos }} puntos
                    </span>

                    <span v-if="grupo.calidad_aprobada_at"
                        class="text-xs px-2 py-1 rounded-full bg-pastel-verde-2 text-aviso-verde font-semibold">
                        Aprobada · {{ grupo.calidad_aprobada_at }}
                    </span>

                    <div class="ml-auto flex items-center gap-2">
                        <button type="button" @click="mandarAReproceso(grupo)"
                            class="px-3 py-1.5 rounded-xl text-xs font-medium text-aviso-rojo border border-borde-aviso-rojo hover:bg-pastel-rojo transition-colors">
                            A reproceso
                        </button>
                        <button type="button" @click="cerrarOp(grupo)" :disabled="grupo.bloquean > 0"
                            class="px-3 py-1.5 rounded-xl text-xs font-semibold text-white disabled:opacity-40 transition-opacity"
                            style="background: var(--marca);"
                            :title="grupo.bloquean > 0 ? `Faltan ${grupo.bloquean} punto(s) por resolver` : 'Sella la calidad de la orden'">
                            Cerrar calidad de la orden
                        </button>
                    </div>
                </div>

                <div class="space-y-3">
                    <FichaProceso v-for="ficha in grupo.fichas" :key="ficha.id"
                        :numero="ficha.op_numero"
                        :sufijo="sufijoDe(ficha)"
                        :titulo="ficha.titulo"
                        :subtitulo="ficha.cliente"
                        :chips="chipsDe(ficha)"
                        :urgencia="ficha.urgencia"
                        :marca="ficha.en_reproceso ? 'En reproceso' : ''"
                        :fecha="ficha.fecha_entrega"
                        :contador="ficha.total_checks ? `${ficha.resueltos}/${ficha.total_checks}` : ''"
                        :porcentaje="ficha.total_checks ? ficha.porcentaje : (ficha.revisada ? 100 : 0)"
                        :botones="botonesDe(ficha)"
                        accion="Terminar"
                        accion-hecha="Terminada"
                        :hecha="cerrada(ficha)"
                        :ocupada="ocupadas.has(ficha.id)"
                        :aviso="avisos[ficha.id] ?? ''"
                        @boton="b => tocarPunto(ficha, b)"
                        @boton-alterno="b => marcarFalla(ficha, b)"
                        @accion="terminarUnidad(ficha)"
                        @abrir="router.visit(`/calidad/unidades/${ficha.id}`)" />
                </div>
            </section>
        </div>

        <p class="text-xs text-tinta-300 mt-6 px-1">
            Un toque marca el punto como cumplido. Pulsación larga (o clic derecho) lo marca
            como falla. Toca el número de la orden para abrir la ficha completa de verificación.
        </p>

        <ModalFoto
            :abierto="modal.abierto"
            :guardando="modal.guardando"
            :titulo="modal.check ? modal.check.titulo : 'Foto de evidencia'"
            :descripcion="modal.check?.descripcion || 'Este punto no se puede cerrar sin foto.'"
            @confirmar="subirFoto"
            @cerrar="cerrarModal" />
    </AppLayout>
</template>
