<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { usePage, router } from '@inertiajs/vue3'
import HiloComentarios from '@/Components/HiloComentarios.vue'

/**
 * Botón flotante del chat interno, hermano del de la IA.
 *
 * Si estás parado en un documento (una OP, una cotización, un cliente), abre
 * su hilo. Si no, muestra lo que tienes pendiente: sin eso, el botón no
 * serviría de nada en el dashboard.
 */
const abierto    = ref(false)
const pendientes = ref([])
const cargando   = ref(false)

// vista: 'inicio' (documento o pendientes) | 'personas' | 'hilo'
const vista          = ref('inicio')
const conversaciones = ref([])
const usuarios       = ref([])
const buscar         = ref('')
const conQuien       = ref(null)
const mensajes       = ref([])
const enviando       = ref(false)
const nuevo          = ref({ contenido: '', tipo: 'comentario', fecha_limite: '' })

// ── Adjuntos ─────────────────────────────────────────────────────────────────
const buscadorAbierto = ref(false)
const buscarDoc       = ref('')
const gruposDoc       = ref([])
const refAdjunta      = ref(null)   // { tipo, titulo, url }
const archivos        = ref([])     // [{ nombre, ruta, url, esImagen }]
const subiendo        = ref(false)

let tempDoc = null
function buscarDocumentos() {
    clearTimeout(tempDoc)
    tempDoc = setTimeout(async () => {
        if (buscarDoc.value.trim().length < 2) { gruposDoc.value = []; return }
        try {
            gruposDoc.value = (await api('/api/chat/adjuntar?buscar=' + encodeURIComponent(buscarDoc.value))).grupos
        } catch { gruposDoc.value = [] }
    }, 250)
}

function elegirDocumento(grupo, r) {
    refAdjunta.value = { tipo: grupo.tipo, titulo: r.titulo, url: r.url }
    buscadorAbierto.value = false
    buscarDoc.value = ''
    gruposDoc.value = []
}

async function subirArchivo(e) {
    const file = e.target.files?.[0]
    if (!file) return
    subiendo.value = true
    try {
        const fd = new FormData()
        fd.append('archivo', file)
        const res = await fetch('/api/chat/subir', {
            method: 'POST', credentials: 'same-origin', body: fd,
            headers: { 'X-XSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        })
        if (res.ok) archivos.value.push(await res.json())
    } catch { /* el usuario puede reintentar */ }
    finally { subiendo.value = false; e.target.value = '' }
}

const grupos       = ref([])
const enGrupo      = ref(null)
const creandoGrupo = ref(false)
const grupoNuevo   = ref({ nombre: '', miembros: [] })

const sinLeerChat = computed(() =>
    conversaciones.value.reduce((s, c) => s + (c.sin_leer || 0), 0) +
    grupos.value.reduce((s, g) => s + (g.sin_leer || 0), 0)
)

const csrf = () => {
    const c = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))
    return c ? decodeURIComponent(c.split('=')[1]) : ''
}

async function api(url, opciones = {}) {
    const res = await fetch(url, {
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json', Accept: 'application/json',
            'X-XSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest',
        },
        ...opciones,
    })
    if (!res.ok) throw new Error('No se pudo completar la acción')
    return res.json()
}

async function abrirPersonas() {
    vista.value = 'personas'
    try {
        const [c, u, g] = await Promise.all([
            api('/api/chat/conversaciones'),
            api('/api/chat/usuarios'),
            api('/api/chat/grupos'),
        ])
        conversaciones.value = c.conversaciones
        usuarios.value = u.usuarios
        grupos.value = g.grupos
    } catch { /* el panel sigue usable */ }
}

async function cargarGrupos() {
    try { grupos.value = (await api('/api/chat/grupos')).grupos } catch {}
}

async function abrirGrupo(g) {
    enGrupo.value = g
    conQuien.value = null
    vista.value = 'hilo'
    mensajes.value = []
    try {
        const d = await api('/api/chat/grupos/' + g.id)
        mensajes.value = d.mensajes
        await cargarGrupos()
    } catch { /* queda vacío */ }
}

async function crearGrupo() {
    if (!grupoNuevo.value.nombre.trim() || !grupoNuevo.value.miembros.length) return
    try {
        await api('/api/chat/grupos', { method: 'POST', body: JSON.stringify(grupoNuevo.value) })
        grupoNuevo.value = { nombre: '', miembros: [] }
        creandoGrupo.value = false
        await cargarGrupos()
    } catch { /* se conserva lo escrito */ }
}

let temporizador = null
function buscarUsuarios() {
    clearTimeout(temporizador)
    temporizador = setTimeout(async () => {
        try {
            usuarios.value = (await api('/api/chat/usuarios?buscar=' + encodeURIComponent(buscar.value))).usuarios
        } catch { /* se deja la lista anterior */ }
    }, 250)
}

async function abrirHilo(usuario) {
    enGrupo.value = null
    conQuien.value = usuario
    vista.value = 'hilo'
    mensajes.value = []
    try {
        // Los paréntesis importan: sin ellos se concatena primero y el ?? nunca
        // se aplica, así que al tocar a alguien de "Personas" (que trae `id` y
        // no `usuario_id`) la URL quedaba /api/chat/undefined.
        const d = await api('/api/chat/' + (usuario.usuario_id ?? usuario.id))
        mensajes.value = d.mensajes
        await cargarConversaciones()
    } catch { /* queda vacío */ }
}

async function cargarConversaciones() {
    try { conversaciones.value = (await api('/api/chat/conversaciones')).conversaciones } catch {}
}

async function enviarMensaje() {
    if (!nuevo.value.contenido.trim() || (!conQuien.value && !enGrupo.value)) return
    enviando.value = true
    try {
        const url = enGrupo.value
            ? '/api/chat/grupos/' + enGrupo.value.id
            : '/api/chat/' + (conQuien.value.usuario_id ?? conQuien.value.id)
        const cuerpo = {
            ...nuevo.value,
            ref_tipo:   refAdjunta.value?.tipo ?? null,
            ref_titulo: refAdjunta.value?.titulo ?? null,
            ref_url:    refAdjunta.value?.url ?? null,
            archivos:   archivos.value.map(a => ({
                nombre: a.nombre, ruta: a.ruta,
                mime: a.mime, extension: a.extension, tamano: a.tamano,
            })),
        }
        const d = await api(url, { method: 'POST', body: JSON.stringify(cuerpo) })
        // El grupo no devuelve el mensaje armado: se recarga el hilo.
        if (enGrupo.value) { await abrirGrupo(enGrupo.value) }
        else { mensajes.value.push(d.mensaje) }
        nuevo.value = { contenido: '', tipo: 'comentario', fecha_limite: '' }
        refAdjunta.value = null
        archivos.value = []
    } catch { /* se conserva lo escrito para reintentar */ }
    finally { enviando.value = false }
}

const page = usePage()

// Se deduce del URL en vez de pasarlo por props: así el botón vive en el
// layout y funciona en cualquier pantalla sin tocar cada página.
const documento = computed(() => {
    const ruta = page.url || window.location.pathname

    const mapa = [
        [/^\/produccion\/ops\/(\d+)/,   'op',           'Orden de producción'],
        [/^\/cotizaciones\/(\d+)/,      'cotizacion',   'Cotización'],
        [/^\/clientes\/(\d+)/,          'cliente',      'Cliente'],
        [/^\/compras\/ordenes\/(\d+)/,  'orden_compra', 'Orden de compra'],
    ]

    for (const [patron, tipo, etiqueta] of mapa) {
        const m = ruta.match(patron)
        if (m) return { tipo, id: m[1], etiqueta }
    }

    return null
})

const sinLeer = computed(() => pendientes.value.length + sinLeerChat.value)

async function cargarPendientes() {
    cargando.value = true
    try {
        const res = await fetch('/api/comentarios/pendientes', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
        if (res.ok) pendientes.value = (await res.json()).pendientes ?? []
    } catch { /* si falla, el botón igual abre: no vale la pena molestar */ }
    finally { cargando.value = false }
}

function alternar() {
    abierto.value = !abierto.value
    if (abierto.value) {
        vista.value = 'inicio'
        cargarConversaciones()
        if (!documento.value) cargarPendientes()
    }
}

function ir(url) {
    abierto.value = false
    router.visit(url)
}

// Al cambiar de pantalla se recuenta, para que el globito esté al día.
watch(() => page.url, () => { if (!abierto.value) cargarPendientes() })

onMounted(() => { cargarPendientes(); cargarConversaciones(); cargarGrupos() })

// El lanzador flotante abre el panel y lee el contador de no leídos.
defineExpose({ abrir: alternar, sinLeer })

const etiquetaTipo = { solicitud: 'Solicitud', tarea: 'Tarea' }
</script>

<template>
    <!-- El botón flotante lo dibuja BotonesFlotantes: dos círculos fijos
         tapaban el contenido, sobre todo en celular. -->

    <!-- Panel -->
    <Teleport to="body">
        <div v-if="abierto" class="fixed inset-0 z-50 sm:bg-transparent" style="background:rgba(0,0,0,0.35);"
            @click.self="abierto = false">
            <div class="panel absolute bg-white shadow-2xl flex flex-col
                        inset-x-0 bottom-0 rounded-t-2xl max-h-[85vh]
                        sm:inset-auto sm:right-5 sm:bottom-5 sm:w-[26rem] sm:rounded-2xl sm:border sm:border-gray-200 sm:max-h-[80vh]">

                <!-- Encabezado -->
                <div class="flex items-center gap-2 px-4 py-3 border-b border-gray-100 shrink-0">
                    <div class="w-8 h-8 rounded-xl flex items-center justify-center shrink-0" style="background:#0F766E;">
                        <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M17 8h2a2 2 0 012 2v6a2 2 0 01-2 2h-2v4l-4-4H9a1.99 1.99 0 01-1.414-.586m0 0L11 14h4a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2v4l.586-.586z" />
                        </svg>
                    </div>
                    <button v-if="vista !== 'inicio'" @click="vista = vista === 'hilo' ? 'personas' : 'inicio'"
                        class="p-1 rounded-lg hover:bg-gray-100 text-gray-400 -ml-1">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                        </svg>
                    </button>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-gray-800 leading-tight">
                            {{ vista === 'hilo' ? (enGrupo?.nombre ?? conQuien?.nombre ?? conQuien?.name) : 'Chat del equipo' }}
                        </p>
                        <p class="text-[11px] text-gray-400 truncate">
                            {{ vista === 'personas' ? 'Escríbele a alguien o a un grupo'
                             : vista === 'hilo' ? (enGrupo ? 'Grupo' : 'Mensaje directo')
                             : documento ? documento.etiqueta : 'Lo que tienes pendiente' }}
                        </p>
                    </div>
                    <button v-if="vista === 'inicio'" @click="abrirPersonas"
                        class="ml-auto p-1.5 rounded-lg hover:bg-gray-100 text-gray-500" title="Escribirle a alguien">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                        </svg>
                    </button>
                    <button @click="abierto = false"
                        :class="vista === 'inicio' ? 'p-1.5' : 'ml-auto p-1.5'"
                        class="rounded-lg hover:bg-gray-100 text-gray-400">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <div class="overflow-y-auto p-3">
                    <!-- ── Buscar persona ─────────────────────────────── -->
                    <template v-if="vista === 'personas'">
                        <input v-model="buscar" @input="buscarUsuarios" type="text" placeholder="Buscar a alguien..."
                            class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm mb-3 focus:outline-none focus:border-blue-400" />

                        <p v-if="conversaciones.length && !buscar" class="text-[11px] font-semibold text-gray-400 uppercase tracking-wider mb-1">Conversaciones</p>
                        <ul v-if="!buscar" class="space-y-1 mb-3">
                            <li v-for="c in conversaciones" :key="'c'+c.usuario_id">
                                <button @click="abrirHilo(c)" class="w-full text-left px-3 py-2 rounded-xl hover:bg-gray-50 flex items-center gap-2">
                                    <span class="w-8 h-8 rounded-full flex items-center justify-center text-white text-xs font-bold shrink-0" style="background:#0F766E;">
                                        {{ (c.nombre || '?').charAt(0).toUpperCase() }}
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-sm text-gray-800 truncate">{{ c.nombre }}</span>
                                        <span class="block text-[11px] text-gray-400 truncate">{{ c.mio ? 'Tú: ' : '' }}{{ c.ultimo }}</span>
                                    </span>
                                    <span v-if="c.sin_leer" class="shrink-0 min-w-[18px] h-[18px] px-1 rounded-full bg-red-500 text-white text-[10px] font-bold flex items-center justify-center">
                                        {{ c.sin_leer }}
                                    </span>
                                </button>
                            </li>
                        </ul>

                        <!-- Grupos -->
                        <div v-if="!buscar" class="mb-3">
                            <div class="flex items-center justify-between mb-1">
                                <p class="text-[11px] font-semibold text-gray-400 uppercase tracking-wider">Grupos</p>
                                <button @click="creandoGrupo = !creandoGrupo"
                                    class="text-[11px] font-semibold" style="color:#0F766E;">
                                    {{ creandoGrupo ? 'Cancelar' : '+ Nuevo grupo' }}
                                </button>
                            </div>

                            <div v-if="creandoGrupo" class="rounded-xl border border-gray-200 p-2 mb-2 space-y-2">
                                <input v-model="grupoNuevo.nombre" type="text" placeholder="Nombre del grupo (ej. Producción)"
                                    class="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-xs focus:outline-none focus:border-blue-400" />
                                <div class="flex flex-wrap gap-1">
                                    <label v-for="u in usuarios" :key="'g'+u.id"
                                        class="inline-flex items-center gap-1 px-2 py-1 rounded-lg border cursor-pointer text-[11px]"
                                        :class="grupoNuevo.miembros.includes(u.id) ? 'border-teal-300 bg-teal-50 text-teal-800' : 'border-gray-200 text-gray-600'">
                                        <input type="checkbox" :value="u.id" v-model="grupoNuevo.miembros" class="hidden" />
                                        {{ u.name }}
                                    </label>
                                </div>
                                <button @click="crearGrupo" :disabled="!grupoNuevo.nombre.trim() || !grupoNuevo.miembros.length"
                                    class="w-full py-1.5 rounded-lg text-[11px] font-semibold text-white disabled:opacity-50"
                                    style="background:#0F766E;">Crear grupo</button>
                            </div>

                            <ul class="space-y-1">
                                <li v-for="g in grupos" :key="'gr'+g.id">
                                    <button @click="abrirGrupo(g)" class="w-full text-left px-3 py-2 rounded-xl hover:bg-gray-50 flex items-center gap-2">
                                        <span class="w-8 h-8 rounded-xl flex items-center justify-center text-white text-xs font-bold shrink-0" style="background:#0F766E;">
                                            #
                                        </span>
                                        <span class="min-w-0 flex-1">
                                            <span class="block text-sm text-gray-800 truncate">{{ g.nombre }}</span>
                                            <span class="block text-[11px] text-gray-400 truncate">
                                                {{ g.miembros }} personas{{ g.ultimo ? ' · ' + g.ultimo : '' }}
                                            </span>
                                        </span>
                                        <span v-if="g.sin_leer" class="shrink-0 min-w-[18px] h-[18px] px-1 rounded-full bg-red-500 text-white text-[10px] font-bold flex items-center justify-center">
                                            {{ g.sin_leer }}
                                        </span>
                                    </button>
                                </li>
                                <li v-if="!grupos.length && !creandoGrupo" class="text-[11px] text-gray-400 px-3 py-1">
                                    Todavía no perteneces a ningún grupo.
                                </li>
                            </ul>
                        </div>

                        <p class="text-[11px] font-semibold text-gray-400 uppercase tracking-wider mb-1">Personas</p>
                        <ul class="space-y-1">
                            <li v-for="u in usuarios" :key="'u'+u.id">
                                <button @click="abrirHilo(u)" class="w-full text-left px-3 py-2 rounded-xl hover:bg-gray-50 flex items-center gap-2">
                                    <span class="w-8 h-8 rounded-full flex items-center justify-center text-white text-xs font-bold shrink-0" style="background:#94A3B8;">
                                        {{ (u.name || '?').charAt(0).toUpperCase() }}
                                    </span>
                                    <span class="min-w-0">
                                        <span class="block text-sm text-gray-800 truncate">{{ u.name }}</span>
                                        <span class="block text-[11px] text-gray-400 truncate">{{ u.rol }}</span>
                                    </span>
                                </button>
                            </li>
                            <li v-if="!usuarios.length" class="text-xs text-gray-400 px-3 py-4">Nadie coincide con esa búsqueda.</li>
                        </ul>
                    </template>

                    <!-- ── Conversación con una persona ───────────────── -->
                    <template v-else-if="vista === 'hilo'">
                        <div class="space-y-2 mb-3">
                            <p v-if="!mensajes.length" class="text-xs text-gray-400 py-6 text-center">
                                Todavía no se han escrito. Manda el primer mensaje.
                            </p>
                            <div v-for="m in mensajes" :key="m.id" class="flex" :class="m.mio ? 'justify-end' : 'justify-start'">
                                <div class="max-w-[85%] rounded-2xl px-3 py-2"
                                    :class="m.mio ? 'text-white' : 'bg-gray-100 text-gray-800'"
                                    :style="m.mio ? 'background:#0F766E;' : ''">
                                    <p v-if="m.tipo !== 'comentario'"
                                        class="text-[10px] font-bold uppercase tracking-wider mb-0.5 opacity-80">
                                        {{ m.tipo }}<template v-if="m.estado"> · {{ m.estado }}</template>
                                    </p>
                                    <p class="text-sm whitespace-pre-line">{{ m.contenido }}</p>
                                    <a v-if="m.referencia" :href="m.referencia.url"
                                        class="mt-1 block text-[11px] underline"
                                        :class="m.mio ? 'text-white/90' : 'text-blue-600'">
                                        📎 {{ m.referencia.etiqueta }}
                                    </a>

                                    <div v-if="m.archivos?.length" class="mt-1.5 space-y-1">
                                        <template v-for="(a, i) in m.archivos" :key="i">
                                            <a v-if="a.esImagen" :href="a.url" target="_blank" rel="noopener" class="block">
                                                <img :src="a.url" :alt="a.nombre"
                                                    class="rounded-lg max-h-40 w-auto border"
                                                    :class="m.mio ? 'border-white/30' : 'border-gray-200'" />
                                            </a>
                                            <a v-else :href="a.url" target="_blank" rel="noopener"
                                                class="block text-[11px] underline truncate"
                                                :class="m.mio ? 'text-white/90' : 'text-blue-600'">
                                                📄 {{ a.nombre }}
                                            </a>
                                        </template>
                                    </div>
                                    <p class="text-[10px] mt-0.5" :class="m.mio ? 'text-white/70' : 'text-gray-400'">
                                        {{ new Date(m.creado).toLocaleString('es-CO', { day:'2-digit', month:'short', hour:'2-digit', minute:'2-digit' }) }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="border-t border-gray-100 pt-3 space-y-2">
                            <!-- Buscador de documentos para adjuntar -->
                            <div v-if="buscadorAbierto" class="rounded-xl border border-gray-200 p-2">
                                <input v-model="buscarDoc" @input="buscarDocumentos" type="text"
                                    placeholder="Buscar cotización, remisión, OP, cliente..."
                                    class="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-xs mb-2 focus:outline-none focus:border-blue-400" />
                                <div class="max-h-48 overflow-y-auto">
                                    <div v-for="g in gruposDoc" :key="g.tipo" class="mb-2">
                                        <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wider mb-0.5">{{ g.etiqueta }}</p>
                                        <button v-for="(r, i) in g.resultados" :key="i" @click="elegirDocumento(g, r)"
                                            class="w-full text-left px-2 py-1.5 rounded-lg hover:bg-gray-50">
                                            <span class="block text-xs text-gray-800 truncate">{{ r.titulo }}</span>
                                            <span class="block text-[11px] text-gray-400 truncate">{{ r.detalle }}</span>
                                        </button>
                                    </div>
                                    <p v-if="buscarDoc.length >= 2 && !gruposDoc.length" class="text-[11px] text-gray-400 px-2 py-2">
                                        Nada coincide.
                                    </p>
                                </div>
                            </div>

                            <!-- Adjuntos listos para enviar -->
                            <div v-if="refAdjunta || archivos.length" class="flex flex-wrap gap-1.5">
                                <span v-if="refAdjunta"
                                    class="inline-flex items-center gap-1 px-2 py-1 rounded-lg bg-teal-50 border border-teal-200 text-[11px] text-teal-800">
                                    📎 {{ refAdjunta.titulo }}
                                    <button @click="refAdjunta = null" class="text-teal-600 hover:text-teal-900">✕</button>
                                </span>
                                <span v-for="(a, i) in archivos" :key="i"
                                    class="inline-flex items-center gap-1 px-2 py-1 rounded-lg bg-gray-100 border border-gray-200 text-[11px] text-gray-700">
                                    {{ a.esImagen ? '🖼️' : '📄' }} {{ a.nombre }}
                                    <button @click="archivos.splice(i, 1)" class="text-gray-500 hover:text-gray-900">✕</button>
                                </span>
                            </div>

                            <textarea v-model="nuevo.contenido" rows="2" placeholder="Escribe un mensaje..."
                                class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-blue-400"></textarea>
                            <div class="flex flex-wrap gap-2 items-center">
                                <button type="button" @click="buscadorAbierto = !buscadorAbierto"
                                    class="w-8 h-8 rounded-lg border border-gray-200 text-gray-500 hover:bg-gray-50 flex items-center justify-center"
                                    title="Adjuntar un documento del sistema">📎</button>
                                <label class="w-8 h-8 rounded-lg border border-gray-200 text-gray-500 hover:bg-gray-50 flex items-center justify-center cursor-pointer"
                                    :title="subiendo ? 'Subiendo...' : 'Adjuntar imagen o archivo'">
                                    <input type="file" class="hidden" @change="subirArchivo" :disabled="subiendo" />
                                    <span>{{ subiendo ? '…' : '🖼️' }}</span>
                                </label>
                                <select v-model="nuevo.tipo" class="border border-gray-200 rounded-lg px-2 py-1.5 text-xs focus:outline-none">
                                    <option value="comentario">Mensaje</option>
                                    <option value="solicitud">Solicitud</option>
                                    <option value="tarea">Tarea</option>
                                </select>
                                <input v-if="nuevo.tipo !== 'comentario'" v-model="nuevo.fecha_limite" type="date"
                                    class="border border-gray-200 rounded-lg px-2 py-1.5 text-xs focus:outline-none" />
                                <button @click="enviarMensaje" :disabled="enviando || !nuevo.contenido.trim()"
                                    class="ml-auto px-4 py-1.5 rounded-lg text-xs font-semibold text-white disabled:opacity-50"
                                    style="background:#0F766E;">
                                    {{ enviando ? 'Enviando...' : 'Enviar' }}
                                </button>
                            </div>
                        </div>
                    </template>

                    <!-- Hilo del documento en el que estoy -->
                    <HiloComentarios v-else-if="documento" :key="documento.tipo + documento.id"
                        :documento="documento.tipo" :id="documento.id" embebido />

                    <!-- Fuera de un documento: mis pendientes -->
                    <template v-else>
                        <p v-if="cargando" class="text-xs text-gray-400 py-6 text-center">Cargando...</p>

                        <!-- Conversaciones y grupos, a la vista desde el primer
                             momento. Antes había que descubrir el ícono de
                             personas para llegar a ellos, y la pantalla inicial
                             era un callejón sin salida. -->
                        <div v-else-if="!pendientes.length && !conversaciones.length && !grupos.length" class="py-6 text-center">
                            <p class="text-sm text-gray-500">Todavía no hay conversaciones.</p>
                            <button @click="abrirPersonas"
                                class="mt-3 px-4 py-2 rounded-xl text-xs font-semibold text-white"
                                style="background:#0F766E;">
                                Escribirle a alguien
                            </button>
                            <p class="text-xs text-gray-400 mt-3">
                                También puedes abrir una orden, cotización o cliente para conversar sobre ella.
                            </p>
                        </div>

                        <template v-else>
                            <!-- Grupos y conversaciones -->
                            <div v-if="grupos.length || conversaciones.length" class="mb-3 space-y-1">
                                <div class="flex items-center justify-between mb-1">
                                    <p class="text-[11px] font-semibold text-gray-400 uppercase tracking-wider">Conversaciones</p>
                                    <button @click="abrirPersonas" class="text-[11px] font-semibold" style="color:#0F766E;">
                                        + Nueva
                                    </button>
                                </div>

                                <button v-for="g in grupos" :key="'ig'+g.id" @click="abrirGrupo(g)"
                                    class="w-full text-left px-3 py-2 rounded-xl hover:bg-gray-50 flex items-center gap-2">
                                    <span class="w-8 h-8 rounded-xl flex items-center justify-center text-white text-xs font-bold shrink-0" style="background:#0F766E;">#</span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-sm text-gray-800 truncate">{{ g.nombre }}</span>
                                        <span class="block text-[11px] text-gray-400 truncate">{{ g.ultimo || (g.miembros + ' personas') }}</span>
                                    </span>
                                    <span v-if="g.sin_leer" class="shrink-0 min-w-[18px] h-[18px] px-1 rounded-full bg-red-500 text-white text-[10px] font-bold flex items-center justify-center">{{ g.sin_leer }}</span>
                                </button>

                                <button v-for="c in conversaciones" :key="'ic'+c.usuario_id" @click="abrirHilo(c)"
                                    class="w-full text-left px-3 py-2 rounded-xl hover:bg-gray-50 flex items-center gap-2">
                                    <span class="w-8 h-8 rounded-full flex items-center justify-center text-white text-xs font-bold shrink-0" style="background:#0F766E;">
                                        {{ (c.nombre || '?').charAt(0).toUpperCase() }}
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-sm text-gray-800 truncate">{{ c.nombre }}</span>
                                        <span class="block text-[11px] text-gray-400 truncate">{{ c.mio ? 'Tú: ' : '' }}{{ c.ultimo }}</span>
                                    </span>
                                    <span v-if="c.sin_leer" class="shrink-0 min-w-[18px] h-[18px] px-1 rounded-full bg-red-500 text-white text-[10px] font-bold flex items-center justify-center">{{ c.sin_leer }}</span>
                                </button>
                            </div>

                            <p v-if="pendientes.length" class="text-[11px] font-semibold text-gray-400 uppercase tracking-wider mb-1">Pendientes</p>
                            <ul class="space-y-2">
                            <li v-for="p in pendientes" :key="p.id">
                                <button @click="ir(p.url)"
                                    class="w-full text-left rounded-xl border border-amber-200 bg-amber-50/50 p-3 hover:bg-amber-50 transition-colors">
                                    <div class="flex items-center gap-2 flex-wrap mb-1">
                                        <span class="text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-700 leading-none">
                                            {{ etiquetaTipo[p.tipo] }}
                                        </span>
                                        <span class="text-[11px] text-gray-500">{{ p.documento }}</span>
                                        <span v-if="p.fecha_limite" class="text-[11px] text-gray-500">· antes del {{ p.fecha_limite }}</span>
                                    </div>
                                    <p class="text-sm text-gray-700 line-clamp-2">{{ p.contenido }}</p>
                                    <p class="text-[11px] text-gray-400 mt-1">de {{ p.autor }}</p>
                                </button>
                            </li>
                        </ul>
                        </template>
                    </template>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
/* Animaciones: entrada suave, reacción al tocar y globito que llama la
   atención sin marear. Se respeta a quien pidió menos movimiento. */
.burbuja-chat {
    animation: entrar 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
    transition: transform 0.18s ease, box-shadow 0.18s ease;
}
.burbuja-chat:hover  { transform: scale(1.08); box-shadow: 0 10px 25px rgba(0,0,0,0.25); }
.burbuja-chat:active { transform: scale(0.94); }

.globito { animation: latir 2s ease-in-out infinite; }

.panel { animation: subir 0.25s ease-out; }

@keyframes entrar { from { opacity: 0; transform: scale(0.5) translateY(10px); } to { opacity: 1; transform: scale(1); } }
@keyframes latir  { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.15); } }
@keyframes subir  { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }

@media (prefers-reduced-motion: reduce) {
    .burbuja-chat, .globito, .panel { animation: none; transition: none; }
}
</style>
