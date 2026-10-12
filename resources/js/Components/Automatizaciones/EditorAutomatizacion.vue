<script setup>
/**
 * Armar una automatización como una lista de pasos: «cuando pasa esto → si se cumple… → espera… → haz esto».
 *
 * Por debajo es un grafo (nodos y conexiones) que el servidor valida y el motor recorre; la lista es
 * la forma cómoda de armar una cadena, que es lo que casi todo lo útil necesita. Un grafo con ramas
 * —que esta pantalla no sabe dibujar— se abre solo para mirar, sin riesgo de romperlo al guardar.
 *
 * En el celular es una columna de tarjetas; el lienzo con nodos, cuando llegue, será la vista de
 * escritorio del MISMO grafo.
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

const vacio = () => ({
    id: null, nombre: '', descripcion: '', max_por_hora: 60,
    disparador: { tipo: 'evento', evento: '', frecuencia: { tipo: 'diaria', hora: '06:00', dias: [1, 2, 3, 4, 5] } },
    pasos: [],
})

// ─── Del grafo al formulario, y de vuelta ───────────────────────────────────

function grafoAFormulario(grafo, base) {
    const nodos   = Object.fromEntries((grafo.nodos ?? []).map(n => [n.id, n]))
    const aristas = grafo.aristas ?? []
    const inicio  = (grafo.nodos ?? []).find(n => n.tipo === 'disparador')
    const salidas = (id) => aristas.filter(a => a.de === id)

    const out = { ...base, disparador: { ...base.disparador, ...(inicio?.config ?? {}) }, pasos: [] }
    out.disparador.frecuencia = { tipo: 'diaria', hora: '06:00', dias: [1, 2, 3, 4, 5], ...(inicio?.config?.frecuencia ?? {}) }

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

    return { form: out, lineal }
}

function nodoAPaso(n) {
    if (n.tipo === 'condicion') return { uid: uid(), tipo: 'condicion', combinador: n.config?.combinador ?? 'y', reglas: JSON.parse(JSON.stringify(n.config?.reglas ?? [])) }
    if (n.tipo === 'espera')    return { uid: uid(), tipo: 'espera', cantidad: n.config?.cantidad ?? 1, unidad: n.config?.unidad ?? 'dias' }
    return { uid: uid(), tipo: 'accion', accion: n.accion, config: JSON.parse(JSON.stringify(n.config ?? {})) }
}

function formularioAGrafo() {
    const d = f.value.disparador
    const config = d.tipo === 'evento' ? { tipo: 'evento', evento: d.evento }
        : d.tipo === 'horario' ? { tipo: 'horario', frecuencia: { ...d.frecuencia, dias: d.frecuencia.tipo === 'semanal' ? d.frecuencia.dias : [] } }
        : { tipo: 'manual' }

    const nodos   = [{ id: 'disparo', tipo: 'disparador', config }]
    const aristas = []
    let previo = 'disparo'
    let previoEsCondicion = false

    f.value.pasos.forEach((p, i) => {
        const id = `paso_${i + 1}`

        if (p.tipo === 'condicion') nodos.push({ id, tipo: 'condicion', config: { combinador: p.combinador, reglas: p.reglas } })
        else if (p.tipo === 'espera') nodos.push({ id, tipo: 'espera', config: { cantidad: Number(p.cantidad), unidad: p.unidad } })
        else nodos.push({ id, tipo: 'accion', accion: p.accion, config: limpiar(p.config) })

        aristas.push({ de: previo, a: id, ...(previoEsCondicion ? { rama: 'si' } : {}) })
        previo = id
        previoEsCondicion = p.tipo === 'condicion'
    })

    return { nodos, aristas }
}

/** Fuera lo vacío: el servidor valida los campos que de verdad se llenaron. */
const limpiar = (c) => Object.fromEntries(Object.entries(c ?? {}).filter(([, v]) => v !== '' && v !== null && v !== undefined && ! (Array.isArray(v) && ! v.length)))

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

const eventoActual = computed(() => props.catalogo.eventos.find(e => e.clave === f.value?.disparador.evento) ?? null)
const camposDelEvento = computed(() => eventoActual.value?.campos ?? [])
const conEvento = computed(() => f.value?.disparador.tipo === 'evento' && !! eventoActual.value)

const accionesDisponibles = computed(() => props.catalogo.acciones.filter(a =>
    a.puede && (a.entidades === null || (conEvento.value && a.entidades.includes(eventoActual.value.entidad)))
))

const defAccion = (clave) => props.catalogo.acciones.find(a => a.clave === clave)
const operadores = computed(() => props.catalogo.operadores)

const placeholders = computed(() => camposDelEvento.value.map(c => '{{' + c.clave + '}}'))

function agregar(tipo) {
    if (tipo === 'condicion') {
        const c = camposDelEvento.value[0]
        f.value.pasos.push({ uid: uid(), tipo, combinador: 'y', reglas: [{ campo: c?.clave ?? '', operador: 'igual', valor: '' }] })
    } else if (tipo === 'espera') {
        f.value.pasos.push({ uid: uid(), tipo, cantidad: 1, unidad: 'dias' })
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
        else c[campo.clave] = ''
    }
    return c
}

function cambiarAccion(p, clave) { p.accion = clave; p.config = valoresIniciales(clave) }
function mover(i, d) { const l = f.value.pasos; const j = i + d; if (j < 0 || j >= l.length) return; [l[i], l[j]] = [l[j], l[i]] }
function quitar(i) { f.value.pasos.splice(i, 1) }
function alternar(lista, v) { const i = lista.indexOf(v); i >= 0 ? lista.splice(i, 1) : lista.push(v) }

function cambiarEvento() {
    // Los datos que miran las condiciones son los del evento: al cambiarlo, lo que quedó colgando no vale.
    f.value.pasos = f.value.pasos.filter(p => p.tipo !== 'condicion' && ! (p.tipo === 'accion' && ! accionesDisponibles.value.some(a => a.clave === p.accion)))
}

const campoDe = (clave) => camposDelEvento.value.find(c => c.clave === clave)

function alternarDia(d) { alternar(f.value.disparador.frecuencia.dias, d) }
const DIAS = [[1, 'L'], [2, 'M'], [3, 'X'], [4, 'J'], [5, 'V'], [6, 'S'], [7, 'D']]

// ─── Guardar y probar ────────────────────────────────────────────────────────

async function guardar(activar = false) {
    error.value = []
    aviso.value = ''
    guardando.value = true

    const cuerpo = { nombre: f.value.nombre, descripcion: f.value.descripcion || null, grafo: formularioAGrafo(), max_por_hora: f.value.max_por_hora || 60 }
    const r = f.value.id ? await put(`/api/automatizaciones/${f.value.id}`, cuerpo) : await post('/api/automatizaciones', cuerpo)

    if (! r.ok) { guardando.value = false; error.value = r.data?.errors?.grafo ?? [mensajeDe(r)]; return }

    f.value.id = r.data.id

    if (activar) {
        const a = await post(`/api/automatizaciones/${r.data.id}/activar`, { activa: true })
        if (! a.ok) { guardando.value = false; error.value = a.data?.errors?.grafo ?? [mensajeDe(a)]; return }
    }

    guardando.value = false
    aviso.value = activar ? 'Guardada y activa.' : 'Guardada.'
    emit('guardado')
    if (activar) emit('cerrar')
}

const prueba = ref(null)
const contextoPrueba = ref({})
const probando = ref(false)

async function probar() {
    error.value = []
    probando.value = true
    const r = await post('/api/automatizaciones/probar', { grafo: formularioAGrafo(), contexto: contextoPrueba.value })
    probando.value = false

    if (! r.ok) { error.value = r.data?.errors?.grafo ?? [mensajeDe(r)]; prueba.value = null; return }

    prueba.value = r.data
    contextoPrueba.value = Object.fromEntries(camposDelEvento.value.map(c => [c.clave, r.data.contexto[c.clave] ?? '']))
}

const ICONO = { ok: '✓', omitido: '–', espera: '⏳', error: '×' }
const input = 'w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)] bg-superficie'
const etiquetaPaso = { condicion: 'Si se cumple…', espera: 'Esperar', accion: 'Hacer' }
</script>

<template>
    <div class="bg-superficie border border-linea rounded-2xl p-5 space-y-6">
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

            <section class="space-y-3">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-tinta-400 mb-1">Nombre</label>
                        <input v-model="f.nombre" type="text" maxlength="150" :disabled="soloLectura" placeholder="Seguimiento a cotizaciones sin respuesta" :class="input" />
                    </div>
                    <div>
                        <label class="block text-xs text-tinta-400 mb-1">Para qué sirve</label>
                        <input v-model="f.descripcion" type="text" maxlength="400" :disabled="soloLectura" :class="input" />
                    </div>
                </div>
            </section>

            <!-- Cuándo arranca -->
            <section class="space-y-3 border-l-4 pl-4" style="border-color:var(--marca);">
                <h3 class="text-xs font-semibold text-tinta-500 uppercase tracking-[0.12em]">Cuando…</h3>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs text-tinta-400 mb-1">Arranca</label>
                        <select v-model="f.disparador.tipo" :disabled="soloLectura" :class="input">
                            <option value="evento">Cuando pase algo</option>
                            <option value="horario">A una hora</option>
                            <option value="manual">Cuando yo la lance</option>
                        </select>
                    </div>
                    <div v-if="f.disparador.tipo === 'evento'" class="sm:col-span-2">
                        <label class="block text-xs text-tinta-400 mb-1">Qué pasa</label>
                        <select v-model="f.disparador.evento" @change="cambiarEvento" :disabled="soloLectura" :class="input">
                            <option value="">— elige —</option>
                            <option v-for="e in catalogo.eventos" :key="e.clave" :value="e.clave">{{ e.label }}</option>
                        </select>
                    </div>
                    <template v-if="f.disparador.tipo === 'horario'">
                        <div>
                            <label class="block text-xs text-tinta-400 mb-1">Frecuencia</label>
                            <select v-model="f.disparador.frecuencia.tipo" :disabled="soloLectura" :class="input">
                                <option value="diaria">Todos los días</option>
                                <option value="semanal">Ciertos días</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-tinta-400 mb-1">Hora</label>
                            <input v-model="f.disparador.frecuencia.hora" type="time" :disabled="soloLectura" :class="input" />
                        </div>
                    </template>
                </div>
                <div v-if="f.disparador.tipo === 'horario' && f.disparador.frecuencia.tipo === 'semanal'" class="flex gap-1">
                    <button v-for="[n, l] in DIAS" :key="n" type="button" @click="alternarDia(n)" :disabled="soloLectura"
                        :class="['w-8 h-8 rounded-lg text-xs font-semibold border transition-colors', f.disparador.frecuencia.dias.includes(n) ? 'border-[var(--marca)] bg-realce text-tinta-800' : 'border-linea text-tinta-400 hover:bg-realce']">{{ l }}</button>
                </div>
                <p v-if="f.disparador.tipo === 'horario'" class="text-[11px] text-tinta-400">Arranca dentro de las 3 horas siguientes a esa hora, cuando el cron del servidor lo tome. Una vez por día como máximo.</p>
                <p v-if="f.disparador.tipo === 'manual'" class="text-[11px] text-tinta-400">Se lanza con el botón «Ejecutar» de la lista. No tiene documento, así que no puede usar condiciones ni avisar al «responsable».</p>
            </section>

            <!-- Los pasos -->
            <section class="space-y-3">
                <div v-for="(p, i) in f.pasos" :key="p.uid" class="border border-linea rounded-2xl p-4 space-y-3">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-xs font-semibold text-tinta-500 uppercase tracking-[0.12em]">{{ i + 1 }} · {{ etiquetaPaso[p.tipo] }}</p>
                        <div v-if="! soloLectura" class="flex gap-1">
                            <button type="button" @click="mover(i, -1)" :disabled="i === 0" class="w-7 h-7 rounded-lg border border-linea text-tinta-500 hover:bg-realce disabled:opacity-30" aria-label="Subir">↑</button>
                            <button type="button" @click="mover(i, 1)" :disabled="i === f.pasos.length - 1" class="w-7 h-7 rounded-lg border border-linea text-tinta-500 hover:bg-realce disabled:opacity-30" aria-label="Bajar">↓</button>
                            <button type="button" @click="quitar(i)" class="w-7 h-7 rounded-lg border border-borde-aviso-rojo text-aviso-rojo hover:bg-pastel-rojo" aria-label="Quitar">×</button>
                        </div>
                    </div>

                    <!-- Condición -->
                    <template v-if="p.tipo === 'condicion'">
                        <p class="text-[11px] text-tinta-400">Si no se cumple, la automatización se detiene aquí.</p>
                        <div v-for="(r, k) in p.reglas" :key="k" class="grid grid-cols-1 sm:grid-cols-[1fr_1fr_1fr_auto] gap-2 items-center">
                            <select v-model="r.campo" :disabled="soloLectura" :class="input">
                                <option v-for="c in camposDelEvento" :key="c.clave" :value="c.clave">{{ c.label }}</option>
                            </select>
                            <select v-model="r.operador" :disabled="soloLectura" :class="input">
                                <option v-for="o in operadores" :key="o.clave" :value="o.clave">{{ o.label }}</option>
                            </select>
                            <select v-if="campoDe(r.campo)?.tipo === 'opciones'" v-model="r.valor" :disabled="soloLectura" :class="input">
                                <option v-for="o in campoDe(r.campo).opciones" :key="o" :value="o">{{ o }}</option>
                            </select>
                            <input v-else v-model="r.valor" :type="campoDe(r.campo)?.tipo === 'numero' ? 'number' : 'text'" step="any" :disabled="soloLectura" :class="input" />
                            <button v-if="! soloLectura && p.reglas.length > 1" type="button" @click="p.reglas.splice(k, 1)" class="w-8 h-8 rounded-lg border border-linea text-tinta-400 hover:bg-realce" aria-label="Quitar regla">×</button>
                        </div>
                        <div v-if="! soloLectura" class="flex items-center gap-3 flex-wrap">
                            <button type="button" @click="p.reglas.push({ campo: camposDelEvento[0]?.clave ?? '', operador: 'igual', valor: '' })" class="text-xs px-2.5 py-1 rounded-lg border border-linea text-tinta-600 hover:bg-realce">+ Otra regla</button>
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

                    <!-- Acción -->
                    <template v-else>
                        <select :value="p.accion" @change="cambiarAccion(p, $event.target.value)" :disabled="soloLectura" :class="input">
                            <option v-for="a in accionesDisponibles" :key="a.clave" :value="a.clave">{{ a.label }}</option>
                            <option v-if="! accionesDisponibles.some(a => a.clave === p.accion)" :value="p.accion">{{ defAccion(p.accion)?.label ?? p.accion }}</option>
                        </select>
                        <p class="text-[11px] text-tinta-400">{{ defAccion(p.accion)?.descripcion }}</p>

                        <div v-for="campo in defAccion(p.accion)?.campos ?? []" :key="campo.clave"
                            v-show="p.accion !== 'notificar' || campo.clave === 'destinatario' || campo.clave === 'titulo' || campo.clave === 'mensaje' || (campo.clave === 'usuarios' && p.config.destinatario === 'usuarios') || (campo.clave === 'permiso' && p.config.destinatario === 'permiso')">
                            <label class="block text-xs text-tinta-400 mb-1">{{ campo.label }}<span v-if="campo.requerido"> *</span></label>

                            <select v-if="campo.tipo === 'opciones'" v-model="p.config[campo.clave]" :disabled="soloLectura" :class="input">
                                <option v-for="o in campo.opciones" :key="o" :value="o">{{ { responsable: 'El responsable del documento', usuarios: 'Personas que elijo', permiso: 'Quienes tengan un permiso' }[o] ?? o }}</option>
                            </select>
                            <div v-else-if="campo.tipo === 'usuarios'" class="flex gap-x-4 gap-y-1.5 flex-wrap max-h-28 overflow-y-auto">
                                <label v-for="u in catalogo.usuarios" :key="u.id" class="flex items-center gap-1.5 text-xs text-tinta-600 cursor-pointer">
                                    <input type="checkbox" :checked="p.config[campo.clave].includes(u.id)" @change="alternar(p.config[campo.clave], u.id)" :disabled="soloLectura" class="accent-blue-600" />{{ u.name }}
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
                            <input v-else-if="campo.tipo === 'entero'" v-model.number="p.config[campo.clave]" type="number" min="0" step="1" :disabled="soloLectura" :class="input + ' max-w-[8rem]'" />
                            <input v-else v-model="p.config[campo.clave]" type="text" maxlength="500" :disabled="soloLectura" :class="input" />
                        </div>
                        <p v-if="placeholders.length" class="text-[11px] text-tinta-400">Puedes usar datos del documento en los textos: <code v-for="h in placeholders" :key="h" class="mr-1.5">{{ h }}</code></p>
                    </template>
                </div>

                <div v-if="! soloLectura" class="flex gap-2 flex-wrap">
                    <button v-if="conEvento" type="button" @click="agregar('condicion')" class="text-xs px-3 py-1.5 rounded-lg border border-linea text-tinta-600 hover:bg-realce">+ Condición</button>
                    <button type="button" @click="agregar('espera')" class="text-xs px-3 py-1.5 rounded-lg border border-linea text-tinta-600 hover:bg-realce">+ Espera</button>
                    <button type="button" @click="agregar('accion')" class="text-xs px-3 py-1.5 rounded-lg border border-linea text-tinta-600 hover:bg-realce">+ Acción</button>
                </div>
                <p v-if="! f.pasos.length" class="text-xs text-tinta-300">Todavía no tiene pasos: agrega al menos una acción.</p>
            </section>

            <!-- Probar -->
            <section class="space-y-3 border-t border-linea pt-4">
                <div class="flex items-center gap-3 flex-wrap">
                    <button type="button" @click="probar" :disabled="probando" class="text-xs px-3 py-1.5 rounded-lg border border-linea text-tinta-700 hover:bg-realce disabled:opacity-40">
                        {{ probando ? 'Probando…' : 'Probar con datos de ejemplo' }}
                    </button>
                    <span class="text-[11px] text-tinta-400">No ejecuta nada ni avisa a nadie: muestra el camino que seguiría.</span>
                </div>

                <div v-if="prueba">
                    <div v-if="camposDelEvento.length" class="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-3">
                        <div v-for="c in camposDelEvento" :key="c.clave">
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
                <button v-if="! soloLectura" type="button" @click="guardar(true)" :disabled="! f.nombre || guardando" class="px-4 py-2 rounded-xl text-white text-sm font-semibold disabled:opacity-50" style="background:var(--marca);">Guardar y activar</button>
            </div>
        </template>
    </div>
</template>
