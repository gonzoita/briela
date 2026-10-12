<script setup>
/**
 * Crear o configurar un agente: cargo, instrucciones, modelo, herramientas y horario.
 *
 * Todo es editable, pero con límites que el servidor también hace cumplir:
 * - Las herramientas se recortan al techo del cargo.
 * - Un modelo sin soporte de herramientas no se acepta si el agente las usa.
 * - Un agente solo se enciende si tiene un cargo con rol activo y al menos una herramienta.
 *
 * El prompt se arma por capas: la primera (reglas del sistema) es fija y las demás son tuyas. La
 * vista previa muestra EXACTAMENTE lo que recibirá el modelo, con su tamaño aproximado.
 */
import { ref, computed, watch, onMounted } from 'vue'
import { get, post, put, del, mensajeDe, hace } from '@/composables/useEquipoIa'

const props = defineProps({
    // null = agente nuevo. Si viene, es el id del agente a configurar.
    agenteId:     { type: Number, default: null },
    cargos:       { type: Array, default: () => [] },
    herramientas: { type: Array, default: () => [] },
    usuarios:     { type: Array, default: () => [] },
    sedes:        { type: Array, default: () => [] },
    variables:    { type: Array, default: () => [] },
    permisos:     { type: Object, default: () => ({}) },
})

const emit = defineEmits(['cerrar', 'guardado', 'probar'])

const f        = ref(null)
const error    = ref('')
const aviso    = ref('')
const guardando = ref(false)
const cargandoAgente = ref(false)

const vacio = () => ({
    id: null, nombre: '', descripcion: '', avatar: '🤖', cargo_id: '', instrucciones: '', tarea: '',
    herramientas: [], modelo: '', modelo_respaldo: '', nivel_modelo: '', temperatura: null,
    max_tokens: null, max_pasos: null, max_tokens_ejecucion: 60000,
    frecuencia: { tipo: 'manual', hora: '06:30', dias: [1, 2, 3, 4, 5] }, modo: 'solo_lectura',
    avisar_a: [], sede_id: '', activo: false,
})

onMounted(async () => {
    if (props.agenteId === null) { f.value = vacio(); return }

    cargandoAgente.value = true
    const r = await get(`/api/equipo-ia/agentes/${props.agenteId}`)
    cargandoAgente.value = false

    if (! r.ok) { error.value = mensajeDe(r); return }

    f.value = {
        ...vacio(), ...r.data,
        cargo_id: r.data.cargo_id ?? '', sede_id: r.data.sede_id ?? '', modelo: r.data.modelo ?? '',
        modelo_respaldo: r.data.modelo_respaldo ?? '', nivel_modelo: r.data.nivel_modelo ?? '',
        // `herramientas: null` significa «todas las del cargo»: en pantalla se ven marcadas.
        herramientas: r.data.herramientas_efectivas ?? [],
        frecuencia: { tipo: 'manual', hora: '06:30', dias: [1, 2, 3, 4, 5], ...(r.data.frecuencia ?? {}) },
    }
    cargarVersiones()
})

// ─── Cargo y herramientas ────────────────────────────────────────────────────

const cargo = computed(() => props.cargos.find(c => c.id === Number(f.value?.cargo_id)) ?? null)

const delCargo = computed(() => {
    const permitidas = new Set(cargo.value?.herramientas ?? [])
    const g = {}
    for (const h of props.herramientas.filter(h => permitidas.has(h.clave))) (g[h.grupo] ??= []).push(h)
    return g
})

/** Al cambiar de cargo, el agente arranca con todas las herramientas del nuevo y su nivel. */
function elegirCargo(c) {
    f.value.cargo_id = c.id
    f.value.herramientas = [...c.herramientas]
    if (! f.value.avatar || f.value.avatar === '🤖') f.value.avatar = c.avatar || '🤖'
    if (! f.value.nombre) f.value.nombre = c.nombre
    vistaPrevia.value = null
}

function alternarHerramienta(clave) {
    const l = f.value.herramientas
    const i = l.indexOf(clave)
    i >= 0 ? l.splice(i, 1) : l.push(clave)
}

const esPropuesta = (clave) => props.herramientas.find(h => h.clave === clave)?.tipo === 'propuesta'

const AVATARES = ['🤖', '📊', '🏭', '🤝', '🧾', '📦', '🛒', '📣', '🔎', '🧠', '⚙️', '📞']
const DIAS = [[1, 'L'], [2, 'M'], [3, 'X'], [4, 'J'], [5, 'V'], [6, 'S'], [7, 'D']]

function alternarDia(d) {
    const l = f.value.frecuencia.dias
    const i = l.indexOf(d)
    i >= 0 ? l.splice(i, 1) : l.push(d)
}

function alternarAvisar(id) {
    const l = f.value.avisar_a
    const i = l.indexOf(id)
    i >= 0 ? l.splice(i, 1) : l.push(id)
}

// ─── Prompt: vista previa por capas ──────────────────────────────────────────

const vistaPrevia = ref(null)
const cargandoVista = ref(false)

async function verPrompt() {
    cargandoVista.value = true
    const r = await post('/api/equipo-ia/prompt/vista-previa', {
        cargo_id: f.value.cargo_id || null, nombre: f.value.nombre, instrucciones: f.value.instrucciones,
        herramientas: f.value.herramientas, sede_id: f.value.sede_id || null,
    })
    cargandoVista.value = false
    vistaPrevia.value = r.ok ? r.data : null
    if (! r.ok) error.value = mensajeDe(r)
}

// ─── Versiones del prompt ────────────────────────────────────────────────────

const versiones = ref([])
const verVersiones = ref(false)

async function cargarVersiones() {
    if (! f.value?.id) return
    const r = await get(`/api/equipo-ia/versiones/agente/${f.value.id}`)
    if (r.ok) versiones.value = r.data
}

async function restaurar(v) {
    if (! confirm(`¿Volver a la versión ${v.version}? La actual queda en el historial.`)) return
    const r = await post(`/api/equipo-ia/agentes/${f.value.id}/restaurar/${v.version}`)
    if (! r.ok) { error.value = mensajeDe(r); return }
    f.value.instrucciones = r.data.instrucciones ?? ''
    f.value.tarea = r.data.tarea ?? ''
    vistaPrevia.value = null
    cargarVersiones()
}

// ─── Modelo ──────────────────────────────────────────────────────────────────

const modelos = ref([])
const modelosCargados = ref(false)
const recomendacion = ref(null)
const cargandoReco = ref(false)

async function cargarModelos() {
    if (modelosCargados.value) return
    const r = await get('/api/equipo-ia/modelos')
    if (r.ok) { modelos.value = r.data.modelos; modelosCargados.value = true }
}
onMounted(cargarModelos)

const modeloInfo = (id) => modelos.value.find(m => m.id === id) ?? null

/** El aviso de «este modelo no sirve» sale de la lista de OpenRouter, antes de guardar. */
const avisoModelo = computed(() => {
    const usa = (f.value?.herramientas?.length ?? 0) > 0
    for (const [campo, etiqueta] of [['modelo', 'El modelo'], ['modelo_respaldo', 'El modelo de respaldo']]) {
        const m = modeloInfo(f.value?.[campo])
        if (usa && m && m.herramientas === false) return `${etiqueta} «${m.nombre}» no soporta herramientas, y este agente las usa.`
    }
    return ''
})

async function recomendar() {
    cargandoReco.value = true
    const r = await post('/api/equipo-ia/recomendar-modelo', {
        herramientas: f.value.herramientas, instrucciones: f.value.instrucciones, tarea: f.value.tarea, cargo_id: f.value.cargo_id || null,
    })
    cargandoReco.value = false
    recomendacion.value = r.ok ? r.data : null
    if (! r.ok) error.value = mensajeDe(r)
}

function usarModelo(id) {
    f.value.modelo = id
}

const precio = (n) => n === null || n === undefined ? '—' : '$' + n.toFixed(2)

// ─── Guardar ─────────────────────────────────────────────────────────────────

async function guardar(despues = null) {
    error.value = ''
    aviso.value = ''
    guardando.value = true

    const c = f.value
    const cuerpo = {
        ...c,
        cargo_id: c.cargo_id || null, sede_id: c.sede_id || null,
        modelo: c.modelo || null, modelo_respaldo: c.modelo_respaldo || null, nivel_modelo: c.nivel_modelo || null,
        temperatura: c.temperatura === '' ? null : c.temperatura,
        max_tokens: c.max_tokens || null, max_pasos: c.max_pasos || null,
    }

    const r = c.id ? await put(`/api/equipo-ia/agentes/${c.id}`, cuerpo) : await post('/api/equipo-ia/agentes', cuerpo)

    guardando.value = false

    if (! r.ok) { error.value = mensajeDe(r); return false }

    f.value.id = r.data.id
    aviso.value = 'Guardado.'
    cargarVersiones()
    emit('guardado', r.data)

    if (despues === 'cerrar') emit('cerrar')

    return true
}

async function guardarYProbar() {
    if (await guardar()) emit('probar', f.value.id)
}

async function eliminar() {
    if (! confirm(`¿Eliminar el agente «${f.value.nombre}»? Deja de correr; su historial se conserva.`)) return
    const r = await del(`/api/equipo-ia/agentes/${f.value.id}`)
    if (! r.ok) { error.value = mensajeDe(r); return }
    emit('guardado', null)
    emit('cerrar')
}

const hayCargo = computed(() => !! cargo.value)
const frecuenciaProgramada = computed(() => f.value?.frecuencia?.tipo !== 'manual')
const input = 'w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)] bg-superficie'
</script>

<template>
    <div class="bg-superficie border border-linea rounded-2xl p-5 space-y-6">
        <div class="flex items-start justify-between gap-3">
            <h2 class="text-sm font-semibold text-tinta-700 uppercase tracking-[0.12em]">
                {{ agenteId === null && ! f?.id ? 'Nuevo agente' : 'Configurar agente' }}
            </h2>
            <button type="button" @click="emit('cerrar')" class="text-tinta-400 hover:text-tinta-700 text-xl leading-none" aria-label="Cerrar">×</button>
        </div>

        <p v-if="cargandoAgente || ! f" class="text-sm text-tinta-300">{{ error || 'Cargando…' }}</p>

        <template v-else>
            <!-- 1. Cargo -->
            <section class="space-y-2.5">
                <h3 class="text-xs font-semibold text-tinta-500 uppercase tracking-[0.12em]">1 · Cargo</h3>
                <p class="text-[11px] text-tinta-400">El cargo trae las instrucciones base, el techo de herramientas y el rol que limita lo que el agente puede leer.</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                    <button v-for="c in cargos" :key="c.id" type="button" @click="elegirCargo(c)"
                        :class="['text-left border rounded-xl p-3 transition-colors', Number(f.cargo_id) === c.id ? 'border-[var(--marca)] bg-realce' : 'border-linea hover:bg-realce']">
                        <p class="text-sm font-semibold text-tinta-800">
                            <span aria-hidden="true">{{ c.avatar || '🤖' }}</span> {{ c.nombre }}
                            <span v-if="c.de_fabrica" class="text-[10px] px-1.5 py-0.5 rounded-full ml-1 bg-pastel-violeta-2 text-aviso-violeta font-semibold">de fábrica</span>
                        </p>
                        <p v-if="c.descripcion" class="text-xs text-tinta-400 mt-0.5">{{ c.descripcion }}</p>
                        <p class="text-[11px] mt-1" :class="c.rol && c.rol_activo ? 'text-tinta-400' : 'text-aviso-rojo'">
                            {{ c.herramientas.length }} herramientas · rol: {{ c.rol || 'sin rol' }}<template v-if="c.rol && ! c.rol_activo"> (inactivo)</template>
                        </p>
                    </button>
                </div>
                <p v-if="! cargos.length" class="text-xs text-tinta-300">Todavía no hay cargos. Crea uno en la pestaña «Cargos».</p>
            </section>

            <!-- 2. Identidad -->
            <section class="space-y-3">
                <h3 class="text-xs font-semibold text-tinta-500 uppercase tracking-[0.12em]">2 · Identidad</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-tinta-400 mb-1">Nombre</label>
                        <input v-model="f.nombre" type="text" maxlength="120" :class="input" />
                    </div>
                    <div>
                        <label class="block text-xs text-tinta-400 mb-1">Sede que revisa</label>
                        <select v-model="f.sede_id" :class="input">
                            <option value="">Todas las sedes</option>
                            <option v-for="s in sedes" :key="s.id" :value="s.id">{{ s.nombre }}</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-xs text-tinta-400 mb-1">Para qué es</label>
                    <input v-model="f.descripcion" type="text" maxlength="300" :class="input" />
                </div>
                <div>
                    <label class="block text-xs text-tinta-400 mb-1">Personaje</label>
                    <div class="flex gap-1.5 flex-wrap">
                        <button v-for="a in AVATARES" :key="a" type="button" @click="f.avatar = a"
                            :class="['w-9 h-9 rounded-xl text-lg border transition-colors', f.avatar === a ? 'border-[var(--marca)] bg-realce' : 'border-linea hover:bg-realce']">
                            {{ a }}
                        </button>
                    </div>
                </div>
                <div>
                    <label class="block text-xs text-tinta-400 mb-1">A quién avisa (vacío = a quienes puedan verlo o aprobarlo)</label>
                    <div class="flex gap-x-4 gap-y-1.5 flex-wrap max-h-28 overflow-y-auto">
                        <label v-for="u in usuarios" :key="u.id" class="flex items-center gap-1.5 text-xs text-tinta-600 cursor-pointer">
                            <input type="checkbox" :checked="f.avisar_a.includes(u.id)" @change="alternarAvisar(u.id)" class="accent-blue-600" />
                            {{ u.name }}
                        </label>
                    </div>
                </div>
            </section>

            <!-- 3. Instrucciones -->
            <section class="space-y-3">
                <h3 class="text-xs font-semibold text-tinta-500 uppercase tracking-[0.12em]">3 · Instrucciones</h3>
                <div>
                    <label class="block text-xs text-tinta-400 mb-1">Instrucciones propias del agente (se suman a las del cargo)</label>
                    <textarea v-model="f.instrucciones" rows="5" maxlength="8000" :class="input"
                        placeholder="Lo que distingue a ESTE agente: prioridades, qué ignorar, cómo entregar…"></textarea>
                    <p class="text-[11px] text-tinta-400 mt-1">
                        Variables:
                        <code v-for="v in variables" :key="v" class="mr-1.5">{{ '{' + '{' + v + '}' + '}' }}</code>
                    </p>
                </div>
                <div>
                    <label class="block text-xs text-tinta-400 mb-1">La tarea de cada corrida</label>
                    <textarea v-model="f.tarea" rows="2" maxlength="2000" :class="input"
                        placeholder="Revisa las OP con entrega esta semana y diagnostica las que estén en riesgo."></textarea>
                </div>

                <div class="flex gap-2 flex-wrap">
                    <button type="button" @click="verPrompt" :disabled="cargandoVista || ! hayCargo"
                        class="text-xs px-3 py-1.5 rounded-lg border border-linea text-tinta-600 hover:bg-realce disabled:opacity-40 transition-colors">
                        {{ cargandoVista ? 'Armando…' : 'Ver el prompt final' }}
                    </button>
                    <button v-if="f.id" type="button" @click="verVersiones = ! verVersiones"
                        class="text-xs px-3 py-1.5 rounded-lg border border-linea text-tinta-600 hover:bg-realce transition-colors">
                        Versiones ({{ versiones.length }})
                    </button>
                </div>

                <div v-if="vistaPrevia" class="border border-linea rounded-xl divide-y divide-separador">
                    <div class="p-3 flex items-center justify-between gap-2">
                        <p class="text-xs font-semibold text-tinta-600">Lo que recibirá el modelo</p>
                        <p class="text-[11px] text-tinta-400">≈ {{ vistaPrevia.tokens }} tokens por llamada, antes de las herramientas</p>
                    </div>
                    <div v-for="c in vistaPrevia.capas" :key="c.clave" class="p-3">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.1em] mb-1" :class="c.editable ? 'text-aviso-azul' : 'text-tinta-400'">
                            {{ c.titulo }} · {{ c.editable ? 'editable' : 'fija' }}
                        </p>
                        <p class="text-xs text-tinta-600 whitespace-pre-line leading-relaxed">{{ c.texto }}</p>
                    </div>
                </div>

                <div v-if="verVersiones" class="border border-linea rounded-xl divide-y divide-separador max-h-64 overflow-y-auto">
                    <div v-for="v in versiones" :key="v.version" class="p-3 flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-xs font-semibold text-tinta-700">Versión {{ v.version }} <span class="font-normal text-tinta-400">· {{ hace(v.cuando) }}<template v-if="v.por"> · {{ v.por }}</template></span></p>
                            <p v-if="v.nota" class="text-[11px] text-tinta-400">{{ v.nota }}</p>
                            <p class="text-xs text-tinta-500 mt-0.5 line-clamp-2 break-words">{{ v.instrucciones || '(sin instrucciones propias)' }}</p>
                        </div>
                        <button type="button" @click="restaurar(v)" class="text-xs px-2.5 py-1 rounded-lg border border-linea text-tinta-600 hover:bg-realce shrink-0">Restaurar</button>
                    </div>
                    <p v-if="! versiones.length" class="p-3 text-xs text-tinta-300">Todavía no hay versiones.</p>
                </div>
            </section>

            <!-- 4. Modelo -->
            <section class="space-y-3">
                <h3 class="text-xs font-semibold text-tinta-500 uppercase tracking-[0.12em]">4 · Modelo</h3>

                <div class="flex items-center gap-2 flex-wrap">
                    <button type="button" @click="recomendar" :disabled="cargandoReco || ! hayCargo"
                        class="text-xs px-3 py-1.5 rounded-lg border border-linea text-tinta-600 hover:bg-realce disabled:opacity-40 transition-colors">
                        {{ cargandoReco ? 'Pensando…' : 'Recomiéndame un modelo' }}
                    </button>
                    <span class="text-[11px] text-tinta-400">
                        Hoy usaría: <strong class="text-tinta-600">{{ f.modelo || 'el modelo ' + (f.nivel_modelo || cargo?.nivel_modelo || 'economico') + ' de la instalación' }}</strong>
                    </span>
                </div>

                <div v-if="recomendacion" class="rounded-xl border border-borde-aviso-azul bg-pastel-azul p-3 space-y-2">
                    <p class="text-sm text-aviso-azul font-semibold">
                        Sugerencia: nivel {{ recomendacion.nivel === 'potente' ? 'potente' : 'económico' }}
                    </p>
                    <ul class="text-xs text-tinta-600 list-disc pl-4 space-y-0.5">
                        <li v-for="r in recomendacion.razones" :key="r">{{ r }}</li>
                    </ul>
                    <p v-if="recomendacion.aviso" class="text-xs text-aviso-ambar">{{ recomendacion.aviso }}</p>
                    <div v-if="recomendacion.candidatos.length">
                        <p class="text-[11px] text-tinta-500 mb-1">Candidatos con herramientas (precio por millón de tokens, entrada / salida):</p>
                        <div class="space-y-1">
                            <button v-for="m in recomendacion.candidatos" :key="m.id" type="button" @click="usarModelo(m.id)"
                                class="w-full text-left text-xs px-2.5 py-1.5 rounded-lg bg-superficie border border-linea hover:bg-realce flex items-center justify-between gap-2">
                                <span class="truncate"><strong class="text-tinta-700">{{ m.nombre }}</strong> <span class="text-tinta-400">{{ m.id }}</span></span>
                                <span class="text-tinta-400 shrink-0">{{ precio(m.precio_in) }} / {{ precio(m.precio_out) }}</span>
                            </button>
                        </div>
                    </div>
                    <p class="text-[11px] text-tinta-500">{{ recomendacion.nota }}</p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-tinta-400 mb-1">Modelo (vacío = el del nivel)</label>
                        <input v-model="f.modelo" type="text" list="modelos-equipo" placeholder="anthropic/claude-…" :class="input" />
                    </div>
                    <div>
                        <label class="block text-xs text-tinta-400 mb-1">Modelo de respaldo (si el principal falla)</label>
                        <input v-model="f.modelo_respaldo" type="text" list="modelos-equipo" :class="input" />
                    </div>
                </div>
                <datalist id="modelos-equipo">
                    <option v-for="m in modelos" :key="m.id" :value="m.id">{{ m.nombre }}{{ m.herramientas ? ' · herramientas ✓' : ' · sin herramientas' }}</option>
                </datalist>
                <p v-if="avisoModelo" class="text-xs text-aviso-rojo">{{ avisoModelo }}</p>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <div>
                        <label class="block text-xs text-tinta-400 mb-1">Nivel</label>
                        <select v-model="f.nivel_modelo" :class="input">
                            <option value="">Del cargo</option>
                            <option value="economico">Económico</option>
                            <option value="potente">Potente</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-tinta-400 mb-1">Temperatura</label>
                        <input v-model.number="f.temperatura" type="number" min="0" max="2" step="0.01" placeholder="auto" :class="input" />
                    </div>
                    <div>
                        <label class="block text-xs text-tinta-400 mb-1">Tokens por respuesta</label>
                        <input v-model.number="f.max_tokens" type="number" min="200" max="8000" step="1" :placeholder="String(cargo?.max_tokens ?? 1500)" :class="input" />
                    </div>
                    <div>
                        <label class="block text-xs text-tinta-400 mb-1">Pasos máximos</label>
                        <input v-model.number="f.max_pasos" type="number" min="1" max="20" step="1" :placeholder="String(cargo?.max_pasos ?? 8)" :class="input" />
                    </div>
                </div>
                <div>
                    <label class="block text-xs text-tinta-400 mb-1">Tope de tokens por corrida (freno contra bucles)</label>
                    <input v-model.number="f.max_tokens_ejecucion" type="number" min="5000" max="500000" step="1000" :class="input + ' sm:max-w-xs'" />
                </div>
            </section>

            <!-- 5. Herramientas y modo -->
            <section class="space-y-3">
                <h3 class="text-xs font-semibold text-tinta-500 uppercase tracking-[0.12em]">5 · Qué puede hacer</h3>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                    <button type="button" @click="f.modo = 'solo_lectura'"
                        :class="['text-left border rounded-xl p-3 transition-colors', f.modo === 'solo_lectura' ? 'border-[var(--marca)] bg-realce' : 'border-linea hover:bg-realce']">
                        <p class="text-sm font-semibold text-tinta-800">Solo lectura</p>
                        <p class="text-xs text-tinta-400 mt-0.5">Consulta y avisa con un resumen. No deja propuestas.</p>
                    </button>
                    <button type="button" @click="f.modo = 'propone'"
                        :class="['text-left border rounded-xl p-3 transition-colors', f.modo === 'propone' ? 'border-[var(--marca)] bg-realce' : 'border-linea hover:bg-realce']">
                        <p class="text-sm font-semibold text-tinta-800">Propone</p>
                        <p class="text-xs text-tinta-400 mt-0.5">Además deja propuestas que una persona aprueba o rechaza. Nunca escribe por su cuenta.</p>
                    </button>
                </div>

                <div v-if="! hayCargo" class="text-xs text-tinta-300">Elige un cargo para ver sus herramientas.</div>
                <div v-for="(lista, grupo) in delCargo" :key="grupo">
                    <p class="text-[11px] font-semibold text-tinta-500 uppercase tracking-[0.1em] mb-1">{{ grupo }}</p>
                    <div class="space-y-1">
                        <label v-for="h in lista" :key="h.clave" class="flex items-start gap-2 cursor-pointer">
                            <input type="checkbox" :checked="f.herramientas.includes(h.clave)" @change="alternarHerramienta(h.clave)" class="accent-blue-600 mt-0.5" />
                            <span class="text-xs text-tinta-600">
                                <code class="text-tinta-800">{{ h.clave }}</code>
                                <span v-if="esPropuesta(h.clave)" class="ml-1 text-[10px] px-1.5 py-0.5 rounded-full bg-pastel-naranja-2 text-aviso-naranja font-semibold">propone</span>
                                — {{ h.descripcion }}
                            </span>
                        </label>
                    </div>
                </div>
                <p v-if="f.modo === 'solo_lectura' && f.herramientas.some(esPropuesta)" class="text-[11px] text-aviso-ambar">
                    En modo «solo lectura» las herramientas que proponen no se le ofrecen al agente.
                </p>
            </section>

            <!-- 6. Cuándo corre -->
            <section class="space-y-3">
                <h3 class="text-xs font-semibold text-tinta-500 uppercase tracking-[0.12em]">6 · Cuándo corre</h3>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs text-tinta-400 mb-1">Frecuencia</label>
                        <select v-model="f.frecuencia.tipo" :class="input">
                            <option value="manual">Solo cuando yo lo pida</option>
                            <option value="diaria">Todos los días</option>
                            <option value="semanal">Ciertos días de la semana</option>
                        </select>
                    </div>
                    <div v-if="frecuenciaProgramada">
                        <label class="block text-xs text-tinta-400 mb-1">Hora</label>
                        <input v-model="f.frecuencia.hora" type="time" :class="input" />
                    </div>
                    <div v-if="f.frecuencia.tipo === 'semanal'">
                        <label class="block text-xs text-tinta-400 mb-1">Días</label>
                        <div class="flex gap-1">
                            <button v-for="[n, l] in DIAS" :key="n" type="button" @click="alternarDia(n)"
                                :class="['w-8 h-8 rounded-lg text-xs font-semibold border transition-colors', f.frecuencia.dias.includes(n) ? 'border-[var(--marca)] bg-realce text-tinta-800' : 'border-linea text-tinta-400 hover:bg-realce']">
                                {{ l }}
                            </button>
                        </div>
                    </div>
                </div>
                <p v-if="frecuenciaProgramada" class="text-[11px] text-tinta-400">
                    Arranca dentro de las 3 horas siguientes a esa hora, cuando el cron del servidor lo tome. Una vez por día como máximo.
                </p>
            </section>

            <!-- Activar -->
            <section class="flex items-center gap-2 border-t border-linea pt-4">
                <label class="flex items-center gap-2 text-sm text-tinta-700 cursor-pointer">
                    <input type="checkbox" v-model="f.activo" class="accent-emerald-600" />
                    Activo
                </label>
                <span class="text-[11px] text-tinta-400">Apagado no corre solo ni con «Ejecutar ahora». «Probar» siempre funciona.</span>
            </section>

            <p v-if="error" class="text-sm text-aviso-rojo whitespace-pre-line">{{ error }}</p>
            <p v-if="aviso" class="text-sm text-aviso-verde">{{ aviso }}</p>

            <div class="flex gap-2 flex-wrap pt-2 border-t border-linea">
                <button v-if="f.id" type="button" @click="eliminar"
                    class="text-xs px-3 py-2 rounded-xl border border-borde-aviso-rojo text-aviso-rojo hover:bg-pastel-rojo transition-colors">Eliminar</button>
                <span class="flex-1"></span>
                <button type="button" @click="emit('cerrar')" class="px-4 py-2 rounded-xl border border-linea text-sm text-tinta-500 hover:bg-realce transition-colors">Cerrar</button>
                <button v-if="permisos.ejecutar" type="button" @click="guardarYProbar" :disabled="! f.nombre || ! f.cargo_id || guardando"
                    class="px-4 py-2 rounded-xl border border-linea text-sm text-tinta-700 hover:bg-realce disabled:opacity-40 transition-colors">
                    Guardar y probar
                </button>
                <button type="button" @click="guardar('cerrar')" :disabled="! f.nombre || ! f.cargo_id || guardando"
                    class="px-4 py-2 rounded-xl text-white text-sm font-semibold disabled:opacity-50" style="background:var(--marca);">
                    {{ guardando ? 'Guardando…' : 'Guardar agente' }}
                </button>
            </div>
        </template>
    </div>
</template>
