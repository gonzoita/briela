<script setup>
/**
 * Armar una automatización como una lista de pasos: «cuando pasa esto → si se cumple… → espera… →
 * pide aprobación… → haz esto».
 *
 * Por debajo es un grafo (nodos y conexiones) que el servidor valida y el motor recorre; la lista es
 * la forma cómoda de armar una cadena, que es lo que casi todo lo útil necesita. Un grafo con ramas
 * «si no…» —que esta pantalla no sabe dibujar— se abre solo para mirar, sin riesgo de romperlo al
 * guardar.
 *
 * Todo lo que se ofrece sale del catálogo del servidor (documentos, eventos, acciones, campos): la
 * pantalla no conoce ningún módulo por su nombre, así que un módulo nuevo aparece aquí sin tocarla.
 */
import { ref, computed, onMounted } from 'vue'
import { get, post, put, mensajeDe } from '@/composables/useEquipoIa'

const props = defineProps({
    // null = nueva. Si viene, es la automatización a editar. `plantilla` = grafo con el que arrancar.
    id:        { type: Number, default: null },
    plantilla: { type: Object, default: null },
    catalogo:  { type: Object, required: true },
})
const emit = defineEmits(['cerrar', 'guardado'])

const f        = ref(null)
const error    = ref([])
const aviso    = ref('')
const guardando = ref(false)
const soloLectura = ref(false)
let siguienteUid = 1
const uid = () => `p${siguienteUid++}`

const frecuenciaVacia = () => ({ tipo: 'diaria', hora: '07:00', dias: [1, 2, 3, 4, 5], dia_mes: 1, intervalo: 2, desde: '08:00', hasta: '18:00' })

const vacio = () => ({
    id: null, nombre: '', descripcion: '', max_por_hora: 60,
    disparador: { tipo: 'evento', evento: '', para_cada: '', no_repetir_dias: 0, frecuencia: frecuenciaVacia() },
    pasos: [],
})

// ─── Del grafo al formulario, y de vuelta ───────────────────────────────────

function grafoAFormulario(grafo, base) {
    const nodos   = Object.fromEntries((grafo.nodos ?? []).map(n => [n.id, n]))
    const aristas = grafo.aristas ?? []
    const inicio  = (grafo.nodos ?? []).find(n => n.tipo === 'disparador')
    const salidas = (id) => aristas.filter(a => a.de === id)

    const cfg = inicio?.config ?? {}
    const out = { ...base, disparador: { ...base.disparador, ...cfg, para_cada: cfg.para_cada ?? '', no_repetir_dias: cfg.no_repetir_dias ?? 0 }, pasos: [] }
    out.disparador.frecuencia = { ...frecuenciaVacia(), ...(cfg.frecuencia ?? {}) }

    let lineal = true
    let actual = inicio?.id
    const vistos = new Set()

    while (actual && ! vistos.has(actual)) {
        vistos.add(actual)
        const s = salidas(actual)

        if (s.length > 1 || s.some(a => a.rama === 'no')) { lineal = false; break }
        if (! s.length) break

        const n = nodos[s[0].a]
        if (! n) break
        out.pasos.push(nodoAPaso(n))
        actual = n.id
    }

    if (vistos.size < (grafo.nodos ?? []).length) lineal = false

    return { form: out, lineal }
}

const copia = (x) => JSON.parse(JSON.stringify(x ?? {}))

function nodoAPaso(n) {
    if (n.tipo === 'condicion')  return { uid: uid(), tipo: 'condicion', combinador: n.config?.combinador ?? 'y', reglas: copia(n.config?.reglas ?? []) }
    if (n.tipo === 'espera')     return { uid: uid(), tipo: 'espera', cantidad: n.config?.cantidad ?? 1, unidad: n.config?.unidad ?? 'dias' }
    if (n.tipo === 'aprobacion') return { uid: uid(), tipo: 'aprobacion', config: { destinatario: 'responsable', usuarios: [], permiso: '', pregunta: '', vence_horas: 48, ...copia(n.config) } }
    return { uid: uid(), tipo: 'accion', accion: n.accion, config: { ...valoresIniciales(n.accion), ...copia(n.config) } }
}

function formularioAGrafo() {
    const d = f.value.disparador
    const conDoc = entidadActual.value ? { no_repetir_dias: Number(d.no_repetir_dias) || 0 } : {}
    const config = d.tipo === 'evento' ? { tipo: 'evento', evento: d.evento, ...conDoc }
        : d.tipo === 'horario' ? { tipo: 'horario', frecuencia: limpiarFrecuencia(d.frecuencia), ...(d.para_cada ? { para_cada: d.para_cada, ...conDoc } : {}) }
        : { tipo: 'manual' }

    const nodos   = [{ id: 'disparo', tipo: 'disparador', config }]
    const aristas = []
    let previo = 'disparo'
    let conRama = false

    f.value.pasos.forEach((p, i) => {
        const id = `paso_${i + 1}`

        if (p.tipo === 'condicion') nodos.push({ id, tipo: 'condicion', config: { combinador: p.combinador, reglas: p.reglas } })
        else if (p.tipo === 'espera') nodos.push({ id, tipo: 'espera', config: { cantidad: Number(p.cantidad), unidad: p.unidad } })
        else if (p.tipo === 'aprobacion') nodos.push({ id, tipo: 'aprobacion', config: limpiar(p.config, { destinatario: p.config.destinatario }) })
        else nodos.push({ id, tipo: 'accion', accion: p.accion, config: limpiar(p.config, camposVisiblesDe(p)) })

        aristas.push({ de: previo, a: id, ...(conRama ? { rama: 'si' } : {}) })
        previo = id
        conRama = ['condicion', 'aprobacion'].includes(p.tipo)
    })

    return { nodos, aristas }
}

function limpiarFrecuencia(fr) {
    if (fr.tipo === 'cada_horas') return { tipo: 'cada_horas', intervalo: Number(fr.intervalo), desde: fr.desde, hasta: fr.hasta, ...(fr.dias?.length && fr.dias.length < 7 ? { dias: fr.dias } : {}) }
    if (fr.tipo === 'mensual') return { tipo: 'mensual', hora: fr.hora, dia_mes: Number(fr.dia_mes) }
    if (fr.tipo === 'semanal') return { tipo: 'semanal', hora: fr.hora, dias: fr.dias }
    return { tipo: 'diaria', hora: fr.hora }
}

/** Fuera lo vacío y lo que no aplica: el servidor valida los campos que de verdad se llenaron. */
function limpiar(c, visibles = null) {
    return Object.fromEntries(Object.entries(c ?? {}).filter(([k, v]) =>
        v !== '' && v !== null && v !== undefined && ! (Array.isArray(v) && ! v.length)
        && (! visibles || ! Array.isArray(visibles) || visibles.includes(k))
        && ! (k === 'usuarios' && c.destinatario && c.destinatario !== 'usuarios')
        && ! (k === 'permiso' && c.destinatario && c.destinatario !== 'permiso')
    ))
}

onMounted(async () => {
    if (props.id === null) {
        f.value = vacio()
        if (props.plantilla) {
            const { form } = grafoAFormulario(props.plantilla.grafo, { ...vacio(), nombre: props.plantilla.nombre, descripcion: props.plantilla.descripcion })
            f.value = form
        }
        return
    }

    const r = await get(`/api/automatizaciones/${props.id}`)
    if (! r.ok) { error.value = [mensajeDe(r)]; return }

    const { form, lineal } = grafoAFormulario(r.data.grafo, { ...vacio(), id: r.data.id, nombre: r.data.nombre, descripcion: r.data.descripcion ?? '', max_por_hora: r.data.max_por_hora })
    f.value = form
    soloLectura.value = ! lineal
})

// ─── Lo que ofrece el catálogo, según el disparador ─────────────────────────

const entidades = computed(() => props.catalogo.entidades ?? [])
const entidadDe = (clave) => entidades.value.find(e => e.clave === clave) ?? null
const eventoActual = computed(() => props.catalogo.eventos.find(e => e.clave === f.value?.disparador.evento) ?? null)

/** El documento sobre el que trabaja: el del evento, el del barrido, o ninguno. */
const entidadActual = computed(() => {
    const d = f.value?.disparador
    if (! d) return null
    if (d.tipo === 'evento') return entidadDe(eventoActual.value?.entidad)
    if (d.tipo === 'horario' && d.para_cada) return entidadDe(d.para_cada)
    return null
})

// «Estado anterior» solo existe cuando algo CAMBIA: en un barrido por horario siempre estaría vacío.
const campos = computed(() => {
    const todos = entidadActual.value?.campos ?? props.catalogo.globales ?? []
    return f.value?.disparador.tipo === 'evento' ? todos : todos.filter(c => ! c.clave.endsWith('_anterior'))
})
const campoDe = (clave) => campos.value.find(c => c.clave === clave)

/** Los eventos agrupados por documento, para un `<optgroup>` por módulo. */
const eventosAgrupados = computed(() => {
    const grupos = {}
    for (const e of props.catalogo.eventos) {
        const g = entidadDe(e.entidad)?.label ?? 'Otros'
        ;(grupos[g] ??= []).push(e)
    }
    return grupos
})

const barribles = computed(() => entidades.value.filter(e => e.barrido))

const sirveAqui = (a) => a.entidades === null || (entidadActual.value && a.entidades.includes(entidadActual.value.clave))
const accionesDisponibles = computed(() => props.catalogo.acciones.filter(a =>
    a.puede && sirveAqui(a) && ! (a.sin_sensibles && entidadActual.value?.sensible)
))
const accionesAgrupadas = computed(() => {
    const grupos = {}
    for (const a of accionesDisponibles.value) (grupos[a.grupo] ??= []).push(a)
    return grupos
})

const defAccion = (clave) => props.catalogo.acciones.find(a => a.clave === clave)
const operadores = computed(() => props.catalogo.operadores)
const sinValor = (op) => ['vacio', 'no_vacio'].includes(op)
const etapasAbiertas = computed(() => (props.catalogo.etapas ?? []).filter(e => ! e.es_ganado && ! e.es_perdido))

/** ¿Hay antes de este paso uno de IA? Entonces sus resultados se ofrecen en los textos y condiciones. */
const iaAntes = (i) => f.value.pasos.slice(0, i).some(p => p.tipo === 'accion' && ['redactar_ia', 'clasificar_ia'].includes(p.accion))

const camposCondicion = (i) => campos.value.filter(c => ! c.variable || iaAntes(i))

function placeholders(i) {
    const propios = campos.value.filter(c => ! c.global).map(c => c.clave)
    const extra = ['hoy', ...(entidadActual.value ? ['enlace'] : [])]
    const ia = iaAntes(i) ? f.value.pasos.slice(0, i).filter(p => p.tipo === 'accion').map(p => ({ redactar_ia: 'texto_ia', clasificar_ia: 'categoria_ia' })[p.accion]).filter(Boolean) : []
    return [...propios, ...extra, ...new Set(ia)].map(c => '{{' + c + '}}')
}

const hayDinero = computed(() => campos.value.some(c => c.formato === 'dinero'))

function agregar(tipo) {
    if (tipo === 'condicion') {
        const c = campos.value.find(x => ! x.variable)
        f.value.pasos.push({ uid: uid(), tipo, combinador: 'y', reglas: [{ campo: c?.clave ?? '', operador: 'igual', valor: '' }] })
    } else if (tipo === 'espera') {
        f.value.pasos.push({ uid: uid(), tipo, cantidad: 1, unidad: 'dias' })
    } else if (tipo === 'aprobacion') {
        f.value.pasos.push({ uid: uid(), tipo, config: { destinatario: entidadActual.value ? 'responsable' : 'autor', usuarios: [], permiso: '', pregunta: '', vence_horas: 48 } })
    } else {
        const a = accionesDisponibles.value[0]
        f.value.pasos.push({ uid: uid(), tipo: 'accion', accion: a?.clave ?? 'notificar', config: valoresIniciales(a?.clave ?? 'notificar') })
    }
}

function valoresIniciales(clave) {
    const c = {}
    for (const campo of defAccion(clave)?.campos ?? []) {
        if (campo.tipo === 'usuarios') c[campo.clave] = []
        else if (campo.tipo === 'entero') c[campo.clave] = 1
        else if (campo.tipo === 'opciones') c[campo.clave] = campo.opciones[0]
        else if (campo.tipo === 'agente' || campo.tipo === 'etapa') c[campo.clave] = null
        else c[campo.clave] = ''
    }
    if (clave === 'notificar') c.destinatario = entidadActual.value ? 'responsable' : 'autor'
    return c
}

/** Un campo de acción se muestra si no depende de otro (`si`) o si ese otro tiene el valor pedido. */
const campoVisible = (p, campo) => ! campo.si || Object.entries(campo.si).every(([k, v]) => p.config[k] === v)
const camposVisiblesDe = (p) => (defAccion(p.accion)?.campos ?? []).filter(c => campoVisible(p, c)).map(c => c.clave)

function cambiarAccion(p, clave) { p.accion = clave; p.config = valoresIniciales(clave) }
function mover(i, d) { const l = f.value.pasos; const j = i + d; if (j < 0 || j >= l.length) return; [l[i], l[j]] = [l[j], l[i]] }
function quitar(i) { f.value.pasos.splice(i, 1) }
function alternar(lista, v) { const i = lista.indexOf(v); i >= 0 ? lista.splice(i, 1) : lista.push(v) }

function cambiarDocumento() {
    // Los datos que miran las condiciones son los del documento: al cambiarlo, lo que quedó colgando no vale.
    const validos = new Set(campos.value.map(c => c.clave))
    f.value.pasos = f.value.pasos.filter(p =>
        ! (p.tipo === 'condicion' && p.reglas.some(r => ! validos.has(r.campo)))
        && ! (p.tipo === 'accion' && ! accionesDisponibles.value.some(a => a.clave === p.accion))
    )
    prueba.value = null
}

function alternarDia(d) { alternar(f.value.disparador.frecuencia.dias, d) }
const DIAS = [[1, 'L'], [2, 'M'], [3, 'X'], [4, 'J'], [5, 'V'], [6, 'S'], [7, 'D']]
const DESTINOS = { responsable: 'El responsable del documento', usuarios: 'Personas que elijo', permiso: 'Quienes tengan un permiso', autor: 'A mí (quien la armó)' }
const etiquetaOpcion = (campo, o) => campo.etiquetas?.[o] ?? o

// ─── Guardar y probar ────────────────────────────────────────────────────────

async function guardar(activar = false) {
    error.value = []
    aviso.value = ''
    guardando.value = true

    const cuerpo = { nombre: f.value.nombre, descripcion: f.value.descripcion || null, grafo: formularioAGrafo(), max_por_hora: Number(f.value.max_por_hora) || 60 }
    const r = f.value.id ? await put(`/api/automatizaciones/${f.value.id}`, cuerpo) : await post('/api/automatizaciones', cuerpo)

    if (! r.ok) { guardando.value = false; error.value = r.data?.errors?.grafo ?? [mensajeDe(r)]; return }

    f.value.id = r.data.id

    if (activar) {
        const a = await post(`/api/automatizaciones/${r.data.id}/activar`, { activa: true })
        if (! a.ok) { guardando.value = false; error.value = a.data?.errors?.grafo ?? [mensajeDe(a)]; return }
    }

    guardando.value = false
    aviso.value = activar ? 'Guardada y encendida.' : 'Guardada. Sigue apagada hasta que la enciendas.'
    emit('guardado')
    if (activar) emit('cerrar')
}

const prueba = ref(null)
const contextoPrueba = ref({})
const documentoPrueba = ref('')
const probando = ref(false)

async function probar(conDocumento = false) {
    error.value = []
    probando.value = true
    const r = await post('/api/automatizaciones/probar', {
        grafo: formularioAGrafo(), nombre: f.value.nombre || 'Prueba',
        ...(conDocumento ? { documento: documentoPrueba.value } : { contexto: contextoPrueba.value }),
    })
    probando.value = false

    if (! r.ok) { error.value = r.data?.errors?.grafo ?? [mensajeDe(r)]; prueba.value = null; return }

    prueba.value = r.data
    if (! r.data.real) {
        contextoPrueba.value = Object.fromEntries(campos.value.filter(c => ! c.variable).map(c => [c.clave, r.data.contexto[c.clave] ?? '']))
    }
}

const ICONO = { ok: '✓', omitido: '–', espera: '⏳', error: '×' }
const input = 'w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)] bg-superficie'
const etiquetaPaso = { condicion: 'Si se cumple…', espera: 'Esperar', accion: 'Hacer', aprobacion: 'Pedir aprobación' }
const colorPaso = { condicion: 'border-l-aviso-azul', espera: 'border-l-aviso-violeta', accion: 'border-l-aviso-verde', aprobacion: 'border-l-aviso-naranja' }
</script>

<template>
    <div class="bg-superficie border border-linea rounded-2xl p-4 sm:p-5 space-y-6">
        <div class="flex items-start justify-between gap-3">
            <h2 class="text-sm font-semibold text-tinta-700 uppercase tracking-[0.12em]">{{ id === null && ! f?.id ? 'Nueva automatización' : 'Editar automatización' }}</h2>
            <button type="button" @click="emit('cerrar')" class="text-tinta-400 hover:text-tinta-700 text-xl leading-none" aria-label="Cerrar">×</button>
        </div>

        <p v-if="! f" class="text-sm text-tinta-300">{{ error[0] || 'Cargando…' }}</p>

        <template v-else>
            <p v-if="soloLectura" class="text-sm rounded-xl border border-borde-aviso-ambar bg-pastel-ambar text-aviso-ambar p-3">
                Esta automatización tiene ramas («si no, hacer esto otro»). Esta pantalla solo arma listas de pasos, así que la
                abre para mirar y no la deja guardar: guardarla aquí le quitaría las ramas.
            </p>

            <section class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs text-tinta-400 mb-1">Nombre</label>
                    <input v-model="f.nombre" type="text" maxlength="150" :disabled="soloLectura" placeholder="Seguimiento a cotizaciones sin respuesta" :class="input" />
                </div>
                <div>
                    <label class="block text-xs text-tinta-400 mb-1">Para qué sirve</label>
                    <input v-model="f.descripcion" type="text" maxlength="400" :disabled="soloLectura" :class="input" />
                </div>
            </section>

            <!-- Cuándo arranca -->
            <section class="space-y-3 border-l-4 pl-4" style="border-color:var(--marca);">
                <h3 class="text-xs font-semibold text-tinta-500 uppercase tracking-[0.12em]">Cuando…</h3>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs text-tinta-400 mb-1">Arranca</label>
                        <select v-model="f.disparador.tipo" @change="cambiarDocumento" :disabled="soloLectura" :class="input">
                            <option value="evento">Cuando pase algo</option>
                            <option value="horario">A una hora (o revisando documentos)</option>
                            <option value="manual">Cuando yo la lance</option>
                        </select>
                    </div>
                    <div v-if="f.disparador.tipo === 'evento'" class="sm:col-span-2">
                        <label class="block text-xs text-tinta-400 mb-1">Qué pasa</label>
                        <select v-model="f.disparador.evento" @change="cambiarDocumento" :disabled="soloLectura" :class="input">
                            <option value="">— elige —</option>
                            <optgroup v-for="(lista, grupo) in eventosAgrupados" :key="grupo" :label="grupo">
                                <option v-for="e in lista" :key="e.clave" :value="e.clave">{{ e.label }}</option>
                            </optgroup>
                        </select>
                    </div>
                    <template v-if="f.disparador.tipo === 'horario'">
                        <div>
                            <label class="block text-xs text-tinta-400 mb-1">Frecuencia</label>
                            <select v-model="f.disparador.frecuencia.tipo" :disabled="soloLectura" :class="input">
                                <option value="diaria">Todos los días</option>
                                <option value="semanal">Ciertos días de la semana</option>
                                <option value="mensual">Una vez al mes</option>
                                <option value="cada_horas">Cada pocas horas</option>
                            </select>
                        </div>
                        <div v-if="f.disparador.frecuencia.tipo !== 'cada_horas'">
                            <label class="block text-xs text-tinta-400 mb-1">Hora</label>
                            <input v-model="f.disparador.frecuencia.hora" type="time" :disabled="soloLectura" :class="input" />
                        </div>
                        <div v-else>
                            <label class="block text-xs text-tinta-400 mb-1">Cada</label>
                            <select v-model.number="f.disparador.frecuencia.intervalo" :disabled="soloLectura" :class="input">
                                <option v-for="h in [1, 2, 3, 4, 6, 12]" :key="h" :value="h">{{ h }} {{ h === 1 ? 'hora' : 'horas' }}</option>
                            </select>
                        </div>
                    </template>
                </div>

                <template v-if="f.disparador.tipo === 'horario'">
                    <div v-if="f.disparador.frecuencia.tipo === 'cada_horas'" class="grid grid-cols-2 gap-3 max-w-xs">
                        <div>
                            <label class="block text-xs text-tinta-400 mb-1">Desde</label>
                            <input v-model="f.disparador.frecuencia.desde" type="time" :disabled="soloLectura" :class="input" />
                        </div>
                        <div>
                            <label class="block text-xs text-tinta-400 mb-1">Hasta</label>
                            <input v-model="f.disparador.frecuencia.hasta" type="time" :disabled="soloLectura" :class="input" />
                        </div>
                    </div>
                    <div v-if="f.disparador.frecuencia.tipo === 'mensual'" class="max-w-[10rem]">
                        <label class="block text-xs text-tinta-400 mb-1">Día del mes</label>
                        <input v-model.number="f.disparador.frecuencia.dia_mes" type="number" min="1" max="28" step="1" :disabled="soloLectura" :class="input" />
                    </div>
                    <div v-if="['semanal', 'cada_horas'].includes(f.disparador.frecuencia.tipo)" class="flex gap-1 flex-wrap">
                        <button v-for="[n, l] in DIAS" :key="n" type="button" @click="alternarDia(n)" :disabled="soloLectura"
                            :class="['w-9 h-9 rounded-lg text-xs font-semibold border transition-colors', f.disparador.frecuencia.dias.includes(n) ? 'border-[var(--marca)] bg-realce text-tinta-800' : 'border-linea text-tinta-400 hover:bg-realce']">{{ l }}</button>
                    </div>

                    <div>
                        <label class="block text-xs text-tinta-400 mb-1">Y revisa…</label>
                        <select v-model="f.disparador.para_cada" @change="cambiarDocumento" :disabled="soloLectura" :class="input">
                            <option value="">Nada en particular: corre una vez</option>
                            <option v-for="e in barribles" :key="e.clave" :value="e.clave" :disabled="! e.puede">Cada uno de: {{ e.plural }}{{ e.puede ? '' : ' (no tienes permiso)' }}</option>
                        </select>
                        <p v-if="f.disparador.para_cada" class="text-[11px] text-tinta-400 mt-1">Crea una corrida por cada documento que pase las condiciones del principio. Las condiciones se miran antes, así que solo corre para los que importan.</p>
                    </div>
                    <p class="text-[11px] text-tinta-400">Arranca cuando el cron del servidor lo toma, dentro de las 3 horas siguientes a la hora fijada (en «cada pocas horas», una vez por bloque).</p>
                </template>

                <p v-if="f.disparador.tipo === 'manual'" class="text-[11px] text-tinta-400">Se lanza con el botón «Ejecutar» de la lista. No trabaja sobre un documento: sus condiciones solo pueden mirar la hora o el día.</p>

                <div v-if="entidadActual && f.disparador.tipo !== 'manual'" class="flex items-center gap-2 flex-wrap text-xs text-tinta-500">
                    <span>No repetir con el mismo documento en</span>
                    <input v-model.number="f.disparador.no_repetir_dias" type="number" min="0" max="365" step="1" :disabled="soloLectura" class="w-20 border border-linea rounded-lg px-2 py-1 text-xs bg-superficie" />
                    <span>días (0 = siempre que pase).</span>
                </div>
                <p v-if="entidadActual?.sensible" class="text-[11px] rounded-lg bg-pastel-violeta border border-borde-aviso-violeta text-aviso-violeta px-2.5 py-1.5">
                    Son datos de colaboradores: sirven para avisar, pero ningún paso los manda a la IA ni a otro sistema.
                </p>
            </section>

            <!-- Los pasos -->
            <section class="space-y-3">
                <div v-for="(p, i) in f.pasos" :key="p.uid" :class="['border border-linea border-l-4 rounded-2xl p-4 space-y-3', colorPaso[p.tipo]]">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-xs font-semibold text-tinta-500 uppercase tracking-[0.12em]">{{ i + 1 }} · {{ etiquetaPaso[p.tipo] }}</p>
                        <div v-if="! soloLectura" class="flex gap-1">
                            <button type="button" @click="mover(i, -1)" :disabled="i === 0" class="w-8 h-8 rounded-lg border border-linea text-tinta-500 hover:bg-realce disabled:opacity-30" aria-label="Subir">↑</button>
                            <button type="button" @click="mover(i, 1)" :disabled="i === f.pasos.length - 1" class="w-8 h-8 rounded-lg border border-linea text-tinta-500 hover:bg-realce disabled:opacity-30" aria-label="Bajar">↓</button>
                            <button type="button" @click="quitar(i)" class="w-8 h-8 rounded-lg border border-borde-aviso-rojo text-aviso-rojo hover:bg-pastel-rojo" aria-label="Quitar">×</button>
                        </div>
                    </div>

                    <!-- Condición -->
                    <template v-if="p.tipo === 'condicion'">
                        <p class="text-[11px] text-tinta-400">Si no se cumple, la automatización se detiene aquí.</p>
                        <div v-for="(r, k) in p.reglas" :key="k" class="grid grid-cols-1 sm:grid-cols-[1fr_1fr_1fr_auto] gap-2 items-center">
                            <select v-model="r.campo" :disabled="soloLectura" :class="input">
                                <option v-for="c in camposCondicion(i)" :key="c.clave" :value="c.clave">{{ c.label }}</option>
                            </select>
                            <select v-model="r.operador" :disabled="soloLectura" :class="input">
                                <option v-for="o in operadores" :key="o.clave" :value="o.clave">{{ o.label }}</option>
                            </select>
                            <span v-if="sinValor(r.operador)" class="hidden sm:block"></span>
                            <select v-else-if="campoDe(r.campo)?.tipo === 'opciones' && r.operador !== 'en_lista'" v-model="r.valor" :disabled="soloLectura" :class="input">
                                <option v-for="o in campoDe(r.campo).opciones" :key="o" :value="o">{{ o.replaceAll('_', ' ') }}</option>
                            </select>
                            <select v-else-if="campoDe(r.campo)?.tipo === 'si_no'" v-model="r.valor" :disabled="soloLectura" :class="input">
                                <option value="si">sí</option>
                                <option value="no">no</option>
                            </select>
                            <select v-else-if="campoDe(r.campo)?.tipo === 'etapa' && r.operador !== 'en_lista'" v-model="r.valor" :disabled="soloLectura" :class="input">
                                <option v-for="e in catalogo.etapas" :key="e.id" :value="e.nombre">{{ e.nombre }}</option>
                            </select>
                            <input v-else v-model="r.valor" :type="campoDe(r.campo)?.tipo === 'numero' && r.operador !== 'en_lista' ? 'number' : 'text'" step="any" :disabled="soloLectura" :class="input"
                                :placeholder="r.operador === 'en_lista' ? 'valor1, valor2' : ''" />
                            <button v-if="! soloLectura && p.reglas.length > 1" type="button" @click="p.reglas.splice(k, 1)" class="w-8 h-8 rounded-lg border border-linea text-tinta-400 hover:bg-realce" aria-label="Quitar regla">×</button>
                        </div>
                        <div v-if="! soloLectura" class="flex items-center gap-3 flex-wrap">
                            <button type="button" @click="p.reglas.push({ campo: camposCondicion(i)[0]?.clave ?? '', operador: 'igual', valor: '' })" class="text-xs px-2.5 py-1 rounded-lg border border-linea text-tinta-600 hover:bg-realce">+ Otra regla</button>
                            <select v-if="p.reglas.length > 1" v-model="p.combinador" class="border border-linea rounded-lg px-2 py-1 text-xs bg-superficie">
                                <option value="y">Deben cumplirse todas</option>
                                <option value="o">Basta con una</option>
                            </select>
                        </div>
                    </template>

                    <!-- Espera -->
                    <template v-else-if="p.tipo === 'espera'">
                        <div class="flex items-center gap-2">
                            <input v-model.number="p.cantidad" type="number" min="1" step="1" :disabled="soloLectura" :class="input + ' max-w-[7rem]'" />
                            <select v-model="p.unidad" :disabled="soloLectura" :class="input + ' max-w-[10rem]'">
                                <option v-for="u in catalogo.unidades" :key="u" :value="u">{{ u }}</option>
                            </select>
                        </div>
                        <p class="text-[11px] text-tinta-400">No deja nada corriendo: queda guardada y el cron la retoma. Al volver, las condiciones que sigan miran el documento <strong>como está ese día</strong>.</p>
                    </template>

                    <!-- Aprobación -->
                    <template v-else-if="p.tipo === 'aprobacion'">
                        <p class="text-[11px] text-tinta-400">La corrida se detiene hasta que alguien diga que sí. Si dice que no, o nadie contesta a tiempo, se detiene y no hace lo que sigue.</p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs text-tinta-400 mb-1">A quién se le pregunta</label>
                                <select v-model="p.config.destinatario" :disabled="soloLectura" :class="input">
                                    <option v-for="(l, k) in DESTINOS" :key="k" :value="k" :disabled="k === 'responsable' && ! entidadActual">{{ l }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs text-tinta-400 mb-1">Espera la respuesta hasta (horas)</label>
                                <input v-model.number="p.config.vence_horas" type="number" min="1" max="168" step="1" :disabled="soloLectura" :class="input" />
                            </div>
                        </div>
                        <div v-if="p.config.destinatario === 'usuarios'" class="flex gap-x-4 gap-y-1.5 flex-wrap max-h-28 overflow-y-auto">
                            <label v-for="u in catalogo.usuarios" :key="u.id" class="flex items-center gap-1.5 text-xs text-tinta-600 cursor-pointer">
                                <input type="checkbox" :checked="p.config.usuarios.includes(u.id)" @change="alternar(p.config.usuarios, u.id)" :disabled="soloLectura" class="accent-blue-600" />{{ u.name }}
                            </label>
                        </div>
                        <select v-if="p.config.destinatario === 'permiso'" v-model="p.config.permiso" :disabled="soloLectura" :class="input">
                            <option value="">— elige el permiso —</option>
                            <option v-for="x in catalogo.permisos" :key="x.clave" :value="x.clave">{{ x.label }}</option>
                        </select>
                        <div>
                            <label class="block text-xs text-tinta-400 mb-1">Qué se le pregunta *</label>
                            <input v-model="p.config.pregunta" type="text" maxlength="500" :disabled="soloLectura" placeholder="¿Pido {{faltante}} {{unidad}} de {{nombre}}?" :class="input" />
                        </div>
                        <p class="text-[11px] text-tinta-400 break-words">Puedes usar: <code v-for="h in placeholders(i)" :key="h" class="mr-1.5">{{ h }}</code></p>
                    </template>

                    <!-- Acción -->
                    <template v-else>
                        <select :value="p.accion" @change="cambiarAccion(p, $event.target.value)" :disabled="soloLectura" :class="input">
                            <optgroup v-for="(lista, grupo) in accionesAgrupadas" :key="grupo" :label="grupo">
                                <option v-for="a in lista" :key="a.clave" :value="a.clave">{{ a.label }}</option>
                            </optgroup>
                            <option v-if="! accionesDisponibles.some(a => a.clave === p.accion)" :value="p.accion">{{ defAccion(p.accion)?.label ?? p.accion }}</option>
                        </select>
                        <p class="text-[11px] text-tinta-400">{{ defAccion(p.accion)?.descripcion }}</p>

                        <template v-for="campo in defAccion(p.accion)?.campos ?? []" :key="campo.clave">
                            <div v-if="campoVisible(p, campo)">
                                <label class="block text-xs text-tinta-400 mb-1">{{ campo.label }}<span v-if="campo.requerido"> *</span></label>

                                <select v-if="campo.tipo === 'opciones'" v-model="p.config[campo.clave]" :disabled="soloLectura" :class="input">
                                    <option v-for="o in campo.opciones" :key="o" :value="o" :disabled="o === 'responsable' && ! entidadActual">{{ etiquetaOpcion(campo, o) }}</option>
                                </select>
                                <div v-else-if="campo.tipo === 'usuarios'" class="flex gap-x-4 gap-y-1.5 flex-wrap max-h-28 overflow-y-auto">
                                    <label v-for="u in catalogo.usuarios" :key="u.id" class="flex items-center gap-1.5 text-xs text-tinta-600 cursor-pointer">
                                        <input type="checkbox" :checked="(p.config[campo.clave] ?? []).includes(u.id)" @change="alternar(p.config[campo.clave], u.id)" :disabled="soloLectura" class="accent-blue-600" />{{ u.name }}
                                    </label>
                                </div>
                                <select v-else-if="campo.tipo === 'permiso'" v-model="p.config[campo.clave]" :disabled="soloLectura" :class="input">
                                    <option value="">— elige —</option>
                                    <option v-for="x in catalogo.permisos" :key="x.clave" :value="x.clave">{{ x.label }}</option>
                                </select>
                                <select v-else-if="campo.tipo === 'agente'" v-model="p.config[campo.clave]" :disabled="soloLectura" :class="input">
                                    <option :value="null">— elige —</option>
                                    <option v-for="a in catalogo.agentes" :key="a.id" :value="a.id">{{ a.nombre }}</option>
                                </select>
                                <select v-else-if="campo.tipo === 'etapa'" v-model="p.config[campo.clave]" :disabled="soloLectura" :class="input">
                                    <option :value="null">— elige —</option>
                                    <option v-for="e in etapasAbiertas" :key="e.id" :value="e.id">{{ e.nombre }}</option>
                                </select>
                                <input v-else-if="campo.tipo === 'entero'" v-model.number="p.config[campo.clave]" type="number" min="0" step="1" :disabled="soloLectura" :class="input + ' max-w-[8rem]'" />
                                <textarea v-else-if="campo.tipo === 'texto_largo'" v-model="p.config[campo.clave]" rows="3" maxlength="2000" :disabled="soloLectura" :class="input"></textarea>
                                <input v-else-if="campo.tipo === 'url'" v-model="p.config[campo.clave]" type="url" maxlength="500" placeholder="https://" :disabled="soloLectura" :class="input" />
                                <input v-else v-model="p.config[campo.clave]" type="text" maxlength="500" :disabled="soloLectura" :class="input" />
                            </div>
                        </template>
                        <p class="text-[11px] text-tinta-400 break-words">
                            Puedes usar datos en los textos: <code v-for="h in placeholders(i)" :key="h" class="mr-1.5">{{ h }}</code>
                            <span v-if="hayDinero"> · Con formato de dinero: <code v-pre>{{total|dinero}}</code></span>
                        </p>
                    </template>
                </div>

                <div v-if="! soloLectura" class="flex gap-2 flex-wrap">
                    <button type="button" @click="agregar('condicion')" class="text-xs px-3 py-2 rounded-lg border border-linea text-tinta-600 hover:bg-realce">+ Condición</button>
                    <button type="button" @click="agregar('espera')" class="text-xs px-3 py-2 rounded-lg border border-linea text-tinta-600 hover:bg-realce">+ Espera</button>
                    <button type="button" @click="agregar('aprobacion')" class="text-xs px-3 py-2 rounded-lg border border-linea text-tinta-600 hover:bg-realce">+ Pedir aprobación</button>
                    <button type="button" @click="agregar('accion')" class="text-xs px-3 py-2 rounded-lg border border-linea text-tinta-600 hover:bg-realce">+ Acción</button>
                </div>
                <p v-if="! f.pasos.length" class="text-xs text-tinta-300">Todavía no tiene pasos: agrega al menos una acción.</p>
            </section>

            <!-- Límite -->
            <section class="flex items-center gap-2 flex-wrap text-xs text-tinta-500">
                <span>Máximo</span>
                <input v-model.number="f.max_por_hora" type="number" min="1" max="1000" step="1" :disabled="soloLectura" class="w-20 border border-linea rounded-lg px-2 py-1 text-xs bg-superficie" />
                <span>corridas por hora. Es un freno: si algo da vueltas, se detiene y te avisa.</span>
            </section>

            <!-- Probar -->
            <section class="space-y-3 border-t border-linea pt-4">
                <div class="flex items-center gap-2 flex-wrap">
                    <button type="button" @click="probar(false)" :disabled="probando" class="text-xs px-3 py-2 rounded-lg border border-linea text-tinta-700 hover:bg-realce disabled:opacity-40">
                        {{ probando ? 'Probando…' : 'Probar con datos de ejemplo' }}
                    </button>
                    <template v-if="entidadActual">
                        <input v-model="documentoPrueba" type="text" maxlength="60" placeholder="o número de un documento real" class="border border-linea rounded-lg px-2 py-1.5 text-xs bg-superficie w-48" @keyup.enter="probar(true)" />
                        <button type="button" @click="probar(true)" :disabled="probando || ! documentoPrueba" class="text-xs px-3 py-2 rounded-lg border border-linea text-tinta-700 hover:bg-realce disabled:opacity-40">Probar con ese</button>
                    </template>
                </div>
                <p class="text-[11px] text-tinta-400">No ejecuta nada ni avisa a nadie: muestra el camino que seguiría. Las aprobaciones se dan por aprobadas y la IA no se consulta.</p>

                <div v-if="prueba">
                    <p v-if="prueba.real" class="text-[11px] text-aviso-verde mb-2">Con los datos reales del documento.</p>
                    <div v-else-if="entidadActual" class="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-3">
                        <div v-for="c in campos.filter(x => ! x.variable && ! x.global)" :key="c.clave">
                            <label class="block text-[10px] text-tinta-400 mb-0.5">{{ c.label }}</label>
                            <input v-model="contextoPrueba[c.clave]" type="text" :class="input + ' !py-1 !text-xs'" />
                        </div>
                        <p class="col-span-full text-[11px] text-tinta-400">Cambia los datos de ejemplo y vuelve a probar para ver otro camino.</p>
                    </div>
                    <ol class="divide-y divide-separador border border-linea rounded-xl">
                        <li v-for="s in prueba.pasos" :key="s.orden" class="p-2.5 text-xs flex gap-2">
                            <span :class="['w-4 shrink-0 text-center font-semibold', { ok: 'text-aviso-verde', omitido: 'text-tinta-400', espera: 'text-aviso-violeta', error: 'text-aviso-rojo' }[s.estado]]">{{ ICONO[s.estado] }}</span>
                            <span class="text-tinta-600 break-words">{{ s.resumen }}</span>
                        </li>
                    </ol>
                    <p v-if="prueba.error" class="text-xs text-aviso-rojo mt-2">Se detendría con un error: {{ prueba.error }}</p>
                </div>
            </section>

            <ul v-if="error.length" class="text-sm text-aviso-rojo list-disc pl-5 space-y-0.5"><li v-for="e in error" :key="e">{{ e }}</li></ul>
            <p v-if="aviso" class="text-sm text-aviso-verde">{{ aviso }}</p>

            <div class="flex gap-2 flex-wrap pt-2 border-t border-linea">
                <span class="flex-1"></span>
                <button type="button" @click="emit('cerrar')" class="px-4 py-2 rounded-xl border border-linea text-sm text-tinta-500 hover:bg-realce">Cerrar</button>
                <button v-if="! soloLectura" type="button" @click="guardar(false)" :disabled="! f.nombre || guardando" class="px-4 py-2 rounded-xl border border-linea text-sm text-tinta-700 hover:bg-realce disabled:opacity-40">Guardar</button>
                <button v-if="! soloLectura" type="button" @click="guardar(true)" :disabled="! f.nombre || guardando" class="px-4 py-2 rounded-xl text-white text-sm font-semibold disabled:opacity-50" style="background:var(--marca);">Guardar y encender</button>
            </div>
        </template>
    </div>
</template>
