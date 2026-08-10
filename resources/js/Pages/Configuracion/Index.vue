<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useClipboard } from '@/composables/useClipboard'

const { copyText } = useClipboard()

const props = defineProps({
    configuraciones:  Array,
    tiposColaborador: Array,
    estaciones:       Array,
    niveles:          Array,
    notificaciones:   { type: Object, default: () => ({}) },
})

const tab = ref('general')

// ─── Notificaciones (copia local editable para los switches) ──────────────────
const notifLocal = ref(JSON.parse(JSON.stringify(props.notificaciones ?? {})))
const guardandoNotif = ref(false)

function guardarNotificaciones() {
    guardandoNotif.value = true
    const plano = []
    for (const grupo of Object.values(notifLocal.value)) {
        for (const n of grupo) plano.push({ tipo: n.tipo, activa: !!n.activa, email: !!n.email })
    }
    router.post('/configuracion/notificaciones', { notificaciones: plano }, {
        preserveScroll: true,
        onFinish: () => { guardandoNotif.value = false },
    })
}

// ─── General — configuraciones agrupadas por grupo ────────────────────────────
//
// Se excluyen los grupos que tienen pantalla propia. 'marca' necesita selector
// de color, subida de imagen y vista previa; mostrarlo aquí como cajas de texto
// confunde (el favicon salía como un campo para escribir) y además permitiría
// guardar el mismo ajuste desde dos lados.
const GRUPOS_CON_PANTALLA_PROPIA = ['email', 'marca']

const configPorGrupo = computed(() => {
    const grupos = {}
    for (const c of props.configuraciones) {
        if (GRUPOS_CON_PANTALLA_PROPIA.includes(c.grupo)) continue
        if (!grupos[c.grupo]) grupos[c.grupo] = []
        grupos[c.grupo].push(c)
    }
    return grupos
})

const grupoLabel = {
    general:    'General',
    empresa:    'Empresa',
    alertas:    'Alertas',
    produccion: 'Producción',
    rrhh:       'RRHH',
    seguridad:  'Seguridad',
}

function abrirSelectorLogo() {
    document.getElementById('input-logo-empresa')?.click()
}

async function subirLogoEmpresa(e) {
    const file = e.target.files?.[0]
    if (!file) return
    const fd = new FormData()
    fd.append('logo', file)
    try {
        const res = await fetch('/configuracion/logo-empresa', {
            method: 'POST',
            headers: { 'X-XSRF-TOKEN': getCsrf() },
            credentials: 'same-origin',
            body: fd,
        })
        if (!res.ok) throw new Error('Error al subir logo')
        const data = await res.json()
        if (data.url) configForm.value['empresa_logo_url'] = data.url
    } catch (err) {
        alert('Error al subir el logo')
    } finally {
        e.target.value = ''
    }
}

// ─── Email / SMTP ────────────────────────────────────────────────────────────
const mostrarPassword = ref(false)
const emailPrueba     = ref('')
const smtpCargando    = ref(false)
const smtpResultado   = ref(null)

async function probarSmtp() {
    if (!emailPrueba.value) return
    smtpCargando.value = true
    smtpResultado.value = null
    try {
        saveConfig()
        await new Promise(r => setTimeout(r, 500))
        const res = await fetch('/configuracion/smtp/probar', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': getCsrf() },
            credentials: 'same-origin',
            body: JSON.stringify({ email: emailPrueba.value }),
        })
        smtpResultado.value = await res.json()
    } catch (e) {
        smtpResultado.value = { ok: false, mensaje: e.message }
    } finally {
        smtpCargando.value = false
    }
}

const configForm = ref(
    Object.fromEntries(props.configuraciones.map(c => [c.clave, c.valor]))
)

watch(() => props.configuraciones, (vals) => {
    for (const c of vals) configForm.value[c.clave] = c.valor
}, { deep: true })

function saveConfig() {
    router.post('/configuracion/save', { configuraciones: configForm.value }, {
        preserveScroll: true,
    })
}

// ─── Tipos de Colaborador ────────────────────────────────────────────────────
const tipos       = ref(props.tiposColaborador.map(t => ({ ...t })))
const tipoForm    = ref({ nombre: '', color: '#6B7280', activo: true })
const editandoTipo = ref(null)

watch(() => props.tiposColaborador, (vals) => {
    tipos.value = vals.map(t => ({ ...t }))
}, { deep: true })

function editarTipo(t) {
    editandoTipo.value = t.id
    tipoForm.value = { nombre: t.nombre, color: t.color, activo: t.activo }
}
function cancelarTipo() {
    editandoTipo.value = null
    tipoForm.value = { nombre: '', color: '#6B7280', activo: true }
}
function storeTipo() {
    if (editandoTipo.value) {
        router.put(`/configuracion/tipos-colaborador/${editandoTipo.value}`, tipoForm.value, {
            preserveScroll: true,
            onSuccess: () => cancelarTipo(),
        })
    } else {
        router.post('/configuracion/tipos-colaborador', tipoForm.value, {
            preserveScroll: true,
            onSuccess: () => cancelarTipo(),
        })
    }
}
function destroyTipo(id) {
    if (!confirm('¿Eliminar este tipo de colaborador?')) return
    router.delete(`/configuracion/tipos-colaborador/${id}`, { preserveScroll: true })
}

// drag-and-drop tipos
const dragTipoIdx     = ref(null)
const dragOverTipoIdx = ref(null)

function onDragStartTipo(idx) { dragTipoIdx.value = idx }
function onDragOverTipo(e, idx) { e.preventDefault(); dragOverTipoIdx.value = idx }
function onDragEndTipo() { dragTipoIdx.value = null; dragOverTipoIdx.value = null }
function onDropTipo(idx) {
    const from = dragTipoIdx.value
    if (from === null || from === idx) { onDragEndTipo(); return }
    const arr = [...tipos.value]
    const [item] = arr.splice(from, 1)
    arr.splice(idx, 0, item)
    arr.forEach((t, i) => { t.orden = i + 1 })
    tipos.value = arr
    onDragEndTipo()
    fetch('/configuracion/tipos-colaborador/reordenar', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': getCsrf() },
        body: JSON.stringify({ orden: arr.map((t, i) => ({ id: t.id, orden: i + 1 })) }),
    })
}

// ─── Estaciones de Trabajo ───────────────────────────────────────────────────
const estaciones       = ref(props.estaciones.map(e => ({ ...e })))
const estacionForm     = ref({ nombre: '', descripcion: '', color: '#6B7280', capacidad_simultanea: 1, activa: true })
const editandoEstacion = ref(null)

watch(() => props.estaciones, (vals) => {
    estaciones.value = vals.map(e => ({ ...e }))
}, { deep: true })

function editarEstacion(e) {
    editandoEstacion.value = e.id
    estacionForm.value = {
        nombre:               e.nombre,
        descripcion:          e.descripcion ?? '',
        color:                e.color,
        capacidad_simultanea: e.capacidad_simultanea,
        activa:               e.activa,
    }
}
function cancelarEstacion() {
    editandoEstacion.value = null
    estacionForm.value = { nombre: '', descripcion: '', color: '#6B7280', capacidad_simultanea: 1, activa: true }
}
function storeEstacion() {
    if (editandoEstacion.value) {
        router.put(`/configuracion/estaciones/${editandoEstacion.value}`, estacionForm.value, {
            preserveScroll: true,
            onSuccess: () => cancelarEstacion(),
        })
    } else {
        router.post('/configuracion/estaciones', estacionForm.value, {
            preserveScroll: true,
            onSuccess: () => cancelarEstacion(),
        })
    }
}
function destroyEstacion(id) {
    if (!confirm('¿Eliminar esta estación de trabajo?')) return
    router.delete(`/configuracion/estaciones/${id}`, { preserveScroll: true })
}

// drag-and-drop estaciones
const dragEstacionIdx     = ref(null)
const dragOverEstacionIdx = ref(null)

function onDragStartEstacion(idx) { dragEstacionIdx.value = idx }
function onDragOverEstacion(e, idx) { e.preventDefault(); dragOverEstacionIdx.value = idx }
function onDragEndEstacion() { dragEstacionIdx.value = null; dragOverEstacionIdx.value = null }
function onDropEstacion(idx) {
    const from = dragEstacionIdx.value
    if (from === null || from === idx) { onDragEndEstacion(); return }
    const arr = [...estaciones.value]
    const [item] = arr.splice(from, 1)
    arr.splice(idx, 0, item)
    arr.forEach((e, i) => { e.orden = i + 1 })
    estaciones.value = arr
    onDragEndEstacion()
    fetch('/configuracion/estaciones/reordenar', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': getCsrf() },
        body: JSON.stringify({ orden: arr.map((e, i) => ({ id: e.id, orden: i + 1 })) }),
    })
}

function getCsrf() {
    const match = document.cookie.split(';').find(c => c.trim().startsWith('XSRF-TOKEN='))
    return match ? decodeURIComponent(match.split('=')[1]) : ''
}

// ─── Niveles de Colaborador ──────────────────────────────────────────────────
const niveles       = ref(props.niveles.map(n => ({ ...n })))
const nivelForm     = ref({ nombre: '', color: '#CD7F32', icono: '', puntos_minimos: 0, puntos_maximos: null, orden: 0 })
const editandoNivel = ref(null)

watch(() => props.niveles, (vals) => {
    niveles.value = vals.map(n => ({ ...n }))
}, { deep: true })

function editarNivel(n) {
    editandoNivel.value = n.id
    nivelForm.value = {
        nombre:         n.nombre,
        color:          n.color,
        icono:          n.icono ?? '',
        puntos_minimos: n.puntos_minimos,
        puntos_maximos: n.puntos_maximos ?? null,
        orden:          n.orden,
    }
}
function cancelarNivel() {
    editandoNivel.value = null
    nivelForm.value = { nombre: '', color: '#CD7F32', icono: '', puntos_minimos: 0, puntos_maximos: null, orden: 0 }
}
function storeNivel() {
    if (editandoNivel.value) {
        router.put(`/configuracion/niveles/${editandoNivel.value}`, nivelForm.value, {
            preserveScroll: true,
            onSuccess: () => cancelarNivel(),
        })
    } else {
        router.post('/configuracion/niveles', nivelForm.value, {
            preserveScroll: true,
            onSuccess: () => cancelarNivel(),
        })
    }
}
function destroyNivel(id) {
    if (!confirm('¿Eliminar este nivel?')) return
    router.delete(`/configuracion/niveles/${id}`, { preserveScroll: true })
}

// ─── CRM — Etapas Pipeline ───────────────────────────────────────────────────
const crmEtapas         = ref([])
const crmEtapaForm      = ref({ nombre: '', color: '#6B7280', accion_automatica: 'ninguna', es_ganado: false, es_perdido: false })
const crmEditandoEtapa  = ref(null)
const crmDragIdx        = ref(null)
const crmDragOverIdx    = ref(null)

onMounted(() => {
    fetch('/crm/etapas', { credentials: 'same-origin' })
        .then(r => r.json())
        .then(d => { crmEtapas.value = d.etapas })
        .catch(() => {})
})

function crmEditarEtapa(e) {
    crmEditandoEtapa.value = e.id
    crmEtapaForm.value = { nombre: e.nombre, color: e.color, accion_automatica: e.accion_automatica, es_ganado: e.es_ganado, es_perdido: e.es_perdido, activa: e.activa }
}
function crmCancelarEtapa() {
    crmEditandoEtapa.value = null
    crmEtapaForm.value = { nombre: '', color: '#6B7280', accion_automatica: 'ninguna', es_ganado: false, es_perdido: false }
}

async function crmStoreEtapa() {
    const method = crmEditandoEtapa.value ? 'PUT' : 'POST'
    const url    = crmEditandoEtapa.value ? `/crm/etapas/${crmEditandoEtapa.value}` : '/crm/etapas'
    const res = await fetch(url, {
        method,
        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': getCsrf() },
        credentials: 'same-origin',
        body: JSON.stringify(crmEtapaForm.value),
    })
    const data = await res.json()
    if (crmEditandoEtapa.value) {
        const idx = crmEtapas.value.findIndex(e => e.id === crmEditandoEtapa.value)
        if (idx !== -1) crmEtapas.value[idx] = data.etapa
    } else {
        crmEtapas.value.push(data.etapa)
    }
    crmCancelarEtapa()
}

async function crmDestroyEtapa(id) {
    if (!confirm('¿Eliminar esta etapa? Solo es posible si no tiene leads.')) return
    const res = await fetch(`/crm/etapas/${id}`, {
        method: 'DELETE',
        headers: { 'X-XSRF-TOKEN': getCsrf() },
        credentials: 'same-origin',
    })
    const data = await res.json()
    if (data.error) { alert(data.error); return }
    crmEtapas.value = crmEtapas.value.filter(e => e.id !== id)
}

function crmOnDragStart(idx) { crmDragIdx.value = idx }
function crmOnDragOver(e, idx) { e.preventDefault(); crmDragOverIdx.value = idx }
function crmOnDragEnd() { crmDragIdx.value = null; crmDragOverIdx.value = null }
function crmOnDrop(idx) {
    const from = crmDragIdx.value
    if (from === null || from === idx) { crmOnDragEnd(); return }
    const arr = [...crmEtapas.value]
    const [item] = arr.splice(from, 1)
    arr.splice(idx, 0, item)
    arr.forEach((e, i) => { e.orden = i + 1 })
    crmEtapas.value = arr
    crmOnDragEnd()
    fetch('/crm/etapas/reordenar', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': getCsrf() },
        credentials: 'same-origin',
        body: JSON.stringify({ orden: arr.map((e, i) => ({ id: e.id, orden: i + 1 })) }),
    })
}

const crmAccionLabel = { ninguna: 'Ninguna', cotizacion: 'Crear cotización', op: 'Crear OP' }

// ─── Pantalla de Planta ──────────────────────────────────────────────────────
const tokenPantalla = computed(() =>
    props.configuraciones.find(c => c.clave === 'pantalla_planta_token')?.valor ?? ''
)
const tokenPantallaNuevo = ref('')
const regenerandoPantalla = ref(false)

// Si se acaba de regenerar, la URL nueva se muestra de una sin recargar.
const urlPantalla     = computed(() => `/planta/${tokenPantallaNuevo.value || tokenPantalla.value}`)
const urlPantallaFull = computed(() => window.location.origin + urlPantalla.value)

async function regenerarTokenPantalla() {
    const aviso = 'Se va a generar una URL nueva para la pantalla de planta.\n\n'
        + 'La URL actual dejará de funcionar de inmediato: hay que ir a cada '
        + 'pantalla de la planta y cargar la nueva.\n\n¿Continuar?'

    if (!confirm(aviso)) return

    regenerandoPantalla.value = true
    try {
        const res = await fetch('/configuracion/pantalla-planta/regenerar', {
            method: 'POST',
            headers: { 'X-XSRF-TOKEN': getCsrf() },
            credentials: 'same-origin',
        })
        if (!res.ok) throw new Error('No se pudo regenerar')
        const data = await res.json()
        tokenPantallaNuevo.value = data.token
    } catch (e) {
        alert('No se pudo generar la URL nueva. Intenta otra vez.')
    } finally {
        regenerandoPantalla.value = false
    }
}

async function copiarUrlPantalla() {
    if (await copyText(urlPantallaFull.value)) {
        alert('URL copiada al portapapeles')
    }
}

// Puntos de la config para el tab puntos
const configPuntosKeys = [
    'puntos_dificultad_1','puntos_dificultad_2','puntos_dificultad_3',
    'puntos_dificultad_4','puntos_dificultad_5','puntos_bonus_tiempo_pct',
    'puntos_bonus_paso_final','puntos_penalizacion_inasistencia',
    'puntos_penalizacion_tardanza','puntos_penalizacion_calidad',
]
const configPuntos = computed(() =>
    props.configuraciones.filter(c => configPuntosKeys.includes(c.clave))
)
</script>

<template>
    <AppLayout title="Configuración del Sistema">
        <div class="max-w-3xl mx-auto">

            <h1 class="text-xl font-semibold text-tinta-900 mb-5">Configuración del Sistema</h1>

            <!-- Tabs -->
            <div class="flex gap-1 bg-tinta-100 p-1 rounded-2xl mb-5 overflow-x-auto">
                <button
                    v-for="t in ['general', 'notificaciones', 'email', 'tipos', 'estaciones', 'puntos', 'crm', 'sistema']"
                    :key="t"
                    @click="tab = t"
                    class="flex-1 py-2 px-3 rounded-xl text-xs font-semibold transition-colors whitespace-nowrap"
                    :class="tab === t ? 'bg-white text-tinta-900 shadow-sm' : 'text-tinta-400'"
                >
                    {{ { general: 'General', notificaciones: 'Notificaciones', email: 'Email', tipos: 'Tipos Colaborador', estaciones: 'Estaciones', puntos: 'Puntos e Hitos', crm: 'Pipeline CRM', sistema: 'Sistema' }[t] }}
                </button>
            </div>

            <!-- ── GENERAL ─────────────────────────────────────────────────── -->
            <div v-show="tab === 'general'" class="space-y-4">
                <div
                    v-for="(items, grupo) in configPorGrupo"
                    :key="grupo"
                    class="bg-white rounded-2xl border border-linea overflow-hidden"
                >
                    <div class="px-5 py-3 border-b border-linea">
                        <h2 class="text-sm font-semibold text-tinta-700">{{ grupoLabel[grupo] ?? grupo }}</h2>
                        <p v-if="grupo === 'empresa'" class="text-xs text-tinta-400 mt-0.5">
                            El nombre se usa en el título de las pestañas del navegador, en los PDF
                            y en la vista previa de los enlaces que compartes.
                        </p>
                    </div>
                    <div class="p-5 space-y-4">
                        <template v-for="c in items" :key="c.clave">
                            <div v-if="c.clave !== 'empresa_logo_url'">
                                <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">
                                    {{ c.etiqueta ?? c.clave }}
                                </label>
                                <label v-if="c.tipo === 'boolean'" class="flex items-center gap-2 cursor-pointer">
                                    <input v-model="configForm[c.clave]" type="checkbox" class="rounded" />
                                    <span class="text-sm text-tinta-700">Habilitado</span>
                                </label>
                                <input
                                    v-else
                                    :type="c.tipo === 'integer' ? 'number' : 'text'"
                                    v-model="configForm[c.clave]"
                                    class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                                />
                                <p v-if="c.descripcion" class="text-xs text-tinta-300 mt-1">{{ c.descripcion }}</p>
                            </div>
                        </template>

                        <!-- Logo de empresa — fuera del v-for para evitar conflicto de refs -->
                        <div v-if="grupo === 'empresa'">
                            <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">
                                Logo de la empresa
                            </label>
                            <input
                                type="file"
                                accept="image/*"
                                class="hidden"
                                id="input-logo-empresa"
                                @change="subirLogoEmpresa"
                            />
                            <button
                                type="button"
                                @click="abrirSelectorLogo"
                                class="w-full border-2 border-dashed border-tinta-200 rounded-xl py-3 text-sm text-tinta-400 hover:border-[var(--marca)] hover:text-[var(--marca)] transition-colors"
                            >
                                Seleccionar imagen del logo
                            </button>
                            <div v-if="configForm['empresa_logo_url']" class="mt-2 flex items-center gap-2">
                                <img :src="configForm['empresa_logo_url']"
                                    class="h-12 object-contain border rounded p-1 bg-white"
                                    @error="e => e.target.style.display='none'" />
                                <span class="text-xs text-green-600">✓ Logo cargado</span>
                            </div>
                        </div>
                        <div v-if="grupo === 'seguridad'" class="mt-4 rounded-xl border border-blue-100 overflow-hidden">
                            <div class="bg-blue-50 px-4 py-3 border-b border-blue-100">
                                <div class="flex items-center gap-2 mb-1">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                                    </svg>
                                    <span class="text-sm font-semibold text-blue-800">Cómo configurar Google reCAPTCHA v3</span>
                                </div>
                            </div>
                            <div class="bg-white px-4 py-3 space-y-2">
                                <div class="flex items-start gap-2">
                                    <span class="w-5 h-5 rounded-full bg-[var(--marca)] text-white text-xs flex items-center justify-center flex-shrink-0 mt-0.5 font-semibold">1</span>
                                    <p class="text-xs text-tinta-500">Ve a <a href="https://www.google.com/recaptcha/admin" target="_blank" class="text-[var(--marca)] underline font-semibold">google.com/recaptcha/admin</a> e inicia sesión con tu cuenta Google.</p>
                                </div>
                                <div class="flex items-start gap-2">
                                    <span class="w-5 h-5 rounded-full bg-[var(--marca)] text-white text-xs flex items-center justify-center flex-shrink-0 mt-0.5 font-semibold">2</span>
                                    <p class="text-xs text-tinta-500">Haz click en <strong>"+"</strong> para crear un nuevo sitio. Escribe un nombre (ej: <em>Mi empresa</em>), selecciona <strong>reCAPTCHA v3</strong>.</p>
                                </div>
                                <div class="flex items-start gap-2">
                                    <span class="w-5 h-5 rounded-full bg-[var(--marca)] text-white text-xs flex items-center justify-center flex-shrink-0 mt-0.5 font-semibold">3</span>
                                    <p class="text-xs text-tinta-500">En <strong>Dominios</strong> agrega: <code class="bg-tinta-100 px-1 rounded text-xs">tu-dominio.com</code> y también <code class="bg-tinta-100 px-1 rounded text-xs">localhost</code> para pruebas locales.</p>
                                </div>
                                <div class="flex items-start gap-2">
                                    <span class="w-5 h-5 rounded-full bg-[var(--marca)] text-white text-xs flex items-center justify-center flex-shrink-0 mt-0.5 font-semibold">4</span>
                                    <p class="text-xs text-tinta-500">Copia la <strong>Clave del sitio (Site Key)</strong> y pégala en el campo <em>reCAPTCHA Site Key</em> de arriba.</p>
                                </div>
                                <div class="flex items-start gap-2">
                                    <span class="w-5 h-5 rounded-full bg-[var(--marca)] text-white text-xs flex items-center justify-center flex-shrink-0 mt-0.5 font-semibold">5</span>
                                    <p class="text-xs text-tinta-500">Copia la <strong>Clave secreta (Secret Key)</strong> y pégala en el campo <em>reCAPTCHA Secret Key</em> de arriba.</p>
                                </div>
                                <div class="flex items-start gap-2">
                                    <span class="w-5 h-5 rounded-full bg-[var(--marca)] text-white text-xs flex items-center justify-center flex-shrink-0 mt-0.5 font-semibold">6</span>
                                    <p class="text-xs text-tinta-500">Guarda la configuración y activa reCAPTCHA en cada formulario desde <strong>CRM → Formularios → Editar → Seguridad</strong>.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <button
                    @click="saveConfig"
                    class="w-full py-3 rounded-2xl text-white text-sm font-semibold"
                    style="background:var(--marca);"
                >
                    Guardar configuración
                </button>
            </div>

            <!-- ── NOTIFICACIONES ────────────────────────────────────────────── -->
            <div v-show="tab === 'notificaciones'" class="space-y-4">
                <p class="text-sm text-tinta-400">
                    Prende o apaga cada aviso. La columna <strong>Campanita</strong> es el
                    aviso dentro de la app; <strong>Email</strong> lo manda además por correo
                    (requiere el SMTP configurado en la pestaña Email).
                </p>

                <div v-for="(items, grupo) in notifLocal" :key="grupo"
                    class="bg-white rounded-2xl border border-linea overflow-hidden">
                    <div class="px-5 py-3 border-b border-linea flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-tinta-700">{{ grupo }}</h2>
                        <div class="flex items-center gap-6 text-[11px] font-semibold text-tinta-300 uppercase tracking-wide">
                            <span>Campanita</span>
                            <span>Email</span>
                        </div>
                    </div>
                    <div class="divide-y divide-gray-50">
                        <div v-for="n in items" :key="n.tipo"
                            class="flex items-center justify-between px-5 py-3">
                            <span class="text-sm text-tinta-900 flex-1">{{ n.label }}</span>
                            <div class="flex items-center gap-8">
                                <button type="button" @click="n.activa = !n.activa"
                                    class="relative w-11 h-6 rounded-full transition-colors flex-shrink-0"
                                    :style="n.activa ? 'background:var(--marca)' : 'background:#D1D5DB'">
                                    <span class="absolute top-0.5 left-0.5 w-5 h-5 rounded-full bg-white shadow-sm transition-transform"
                                        :class="n.activa ? 'translate-x-5' : 'translate-x-0'" />
                                </button>
                                <button type="button" @click="n.email = !n.email"
                                    class="relative w-11 h-6 rounded-full transition-colors flex-shrink-0"
                                    :style="n.email ? 'background:var(--marca)' : 'background:#D1D5DB'">
                                    <span class="absolute top-0.5 left-0.5 w-5 h-5 rounded-full bg-white shadow-sm transition-transform"
                                        :class="n.email ? 'translate-x-5' : 'translate-x-0'" />
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <button @click="guardarNotificaciones" :disabled="guardandoNotif"
                    class="w-full py-3 rounded-2xl text-white text-sm font-semibold disabled:opacity-60"
                    style="background:var(--marca);">
                    {{ guardandoNotif ? 'Guardando...' : 'Guardar notificaciones' }}
                </button>
            </div>

            <!-- ── EMAIL / SMTP ──────────────────────────────────────────────── -->
            <div v-show="tab === 'email'" class="space-y-4">

                <div class="bg-white rounded-2xl border border-linea overflow-hidden">
                    <div class="px-5 py-3 border-b border-linea">
                        <h2 class="text-sm font-semibold text-tinta-700">Correo electrónico (SMTP)</h2>
                        <p class="text-xs text-tinta-300 mt-0.5">Credenciales para envío de emails desde el sistema</p>
                    </div>
                    <div class="p-5 space-y-4">
                        <!-- Servidor y Puerto -->
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Servidor SMTP</label>
                                <input
                                    v-model="configForm['smtp_host']"
                                    type="text"
                                    placeholder="smtp.hostinger.com"
                                    class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                                />
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Puerto</label>
                                <input
                                    v-model="configForm['smtp_port']"
                                    type="number"
                                    placeholder="465"
                                    class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                                />
                            </div>
                        </div>

                        <!-- Cifrado -->
                        <div>
                            <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Cifrado</label>
                            <select
                                v-model="configForm['smtp_encryption']"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white"
                            >
                                <option value="ssl">SSL</option>
                                <option value="tls">TLS</option>
                                <option value="">Ninguno</option>
                            </select>
                        </div>

                        <!-- Usuario y Contraseña -->
                        <div>
                            <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Usuario (email)</label>
                            <input
                                v-model="configForm['smtp_username']"
                                type="email"
                                placeholder="noreply@empresa.com"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Contraseña</label>
                            <div class="relative">
                                <input
                                    v-model="configForm['smtp_password']"
                                    :type="mostrarPassword ? 'text' : 'password'"
                                    placeholder="••••••••"
                                    class="w-full rounded-xl border border-tinta-200 px-3 py-2 pr-10 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                                />
                                <button
                                    type="button"
                                    @click="mostrarPassword = !mostrarPassword"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-tinta-300 hover:text-tinta-500"
                                >
                                    <svg v-if="!mostrarPassword" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                    </svg>
                                    <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <!-- Nombre y Email remitente -->
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Nombre remitente</label>
                                <input
                                    v-model="configForm['smtp_from_name']"
                                    type="text"
                                    placeholder="Mi empresa"
                                    class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                                />
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Email remitente</label>
                                <input
                                    v-model="configForm['smtp_from_email']"
                                    type="email"
                                    placeholder="noreply@empresa.com"
                                    class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Botón guardar -->
                <button
                    @click="saveConfig"
                    class="w-full py-3 rounded-2xl text-white text-sm font-semibold"
                    style="background:var(--marca);"
                >
                    Guardar configuración SMTP
                </button>

                <!-- Sección probar -->
                <div class="bg-white rounded-2xl border border-linea p-5">
                    <h3 class="text-sm font-semibold text-tinta-700 mb-1">Probar conexión SMTP</h3>
                    <p class="text-xs text-tinta-300 mb-3">Guarda primero la configuración y luego envía un email de prueba.</p>
                    <div class="flex gap-2">
                        <input
                            v-model="emailPrueba"
                            type="email"
                            placeholder="destino@ejemplo.com"
                            class="flex-1 rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                        />
                        <button
                            @click="probarSmtp"
                            :disabled="smtpCargando || !emailPrueba"
                            class="px-4 py-2 rounded-xl text-white text-sm font-semibold disabled:opacity-50 shrink-0"
                            style="background:var(--marca);"
                        >
                            {{ smtpCargando ? 'Enviando…' : 'Enviar prueba' }}
                        </button>
                    </div>
                    <div v-if="smtpResultado" class="mt-3 rounded-xl px-4 py-3 text-sm"
                        :class="smtpResultado.ok ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200'"
                    >
                        {{ smtpResultado.ok ? '✓' : '✗' }} {{ smtpResultado.mensaje }}
                    </div>
                </div>
            </div>

            <!-- ── TIPOS DE COLABORADOR ────────────────────────────────────── -->
            <div v-show="tab === 'tipos'" class="space-y-4">
                <!-- Lista -->
                <div class="bg-white rounded-2xl border border-linea overflow-hidden">
                    <div class="px-5 py-3 border-b border-linea">
                        <h2 class="text-sm font-semibold text-tinta-700">Tipos de colaborador</h2>
                        <p class="text-xs text-tinta-300 mt-0.5">Arrastra para reordenar</p>
                    </div>
                    <div v-if="!tipos.length" class="py-8 text-center text-sm text-tinta-300">Sin tipos configurados.</div>
                    <div class="divide-y divide-gray-50">
                        <div
                            v-for="(t, idx) in tipos"
                            :key="t.id"
                            class="flex items-center px-4 py-3 gap-3 transition-colors cursor-default"
                            :class="dragOverTipoIdx === idx && dragTipoIdx !== idx ? 'bg-blue-50' : ''"
                            :draggable="true"
                            @dragstart="onDragStartTipo(idx)"
                            @dragover="onDragOverTipo($event, idx)"
                            @drop="onDropTipo(idx)"
                            @dragend="onDragEndTipo"
                        >
                            <!-- Handle -->
                            <svg class="w-4 h-4 text-tinta-200 cursor-grab shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 8h16M4 16h16" />
                            </svg>
                            <!-- Color -->
                            <div class="w-5 h-5 rounded-full shrink-0 border border-linea" :style="{ background: t.color }" />
                            <!-- Nombre -->
                            <p class="flex-1 text-sm font-medium text-tinta-900 min-w-0 truncate">{{ t.nombre }}</p>
                            <!-- Badge activo -->
                            <span
                                class="text-xs px-2 py-0.5 rounded-full shrink-0"
                                :class="t.activo ? 'bg-green-100 text-green-700' : 'bg-tinta-100 text-tinta-400'"
                            >
                                {{ t.activo ? 'Activo' : 'Inactivo' }}
                            </span>
                            <button @click="editarTipo(t)" class="text-xs text-blue-600 hover:underline shrink-0">Editar</button>
                            <button @click="destroyTipo(t.id)" class="text-xs text-red-500 hover:underline shrink-0">Eliminar</button>
                        </div>
                    </div>
                </div>

                <!-- Formulario -->
                <div class="bg-white rounded-2xl border border-linea p-5">
                    <h3 class="text-sm font-semibold text-tinta-700 mb-4">
                        {{ editandoTipo ? 'Editar tipo' : 'Nuevo tipo de colaborador' }}
                    </h3>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Nombre *</label>
                            <input
                                v-model="tipoForm.nombre"
                                type="text"
                                placeholder="Ej: Supervisor"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Color</label>
                            <div class="flex items-center gap-3">
                                <input
                                    v-model="tipoForm.color"
                                    type="color"
                                    class="w-10 h-10 rounded-xl border border-tinta-200 cursor-pointer p-0.5"
                                />
                                <span class="text-sm text-tinta-400 font-mono">{{ tipoForm.color }}</span>
                            </div>
                        </div>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input v-model="tipoForm.activo" type="checkbox" class="rounded" />
                            <span class="text-sm text-tinta-700">Activo</span>
                        </label>
                        <div class="flex gap-3">
                            <button
                                v-if="editandoTipo"
                                @click="cancelarTipo"
                                class="flex-1 py-2.5 rounded-xl border border-linea text-sm text-tinta-500"
                            >
                                Cancelar
                            </button>
                            <button
                                @click="storeTipo"
                                class="flex-1 py-2.5 rounded-xl text-white text-sm font-semibold"
                                style="background:var(--marca);"
                            >
                                {{ editandoTipo ? 'Actualizar' : 'Crear tipo' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── ESTACIONES DE TRABAJO ───────────────────────────────────── -->
            <div v-show="tab === 'estaciones'" class="space-y-4">
                <!-- Lista -->
                <div class="bg-white rounded-2xl border border-linea overflow-hidden">
                    <div class="px-5 py-3 border-b border-linea">
                        <h2 class="text-sm font-semibold text-tinta-700">Estaciones de trabajo</h2>
                        <p class="text-xs text-tinta-300 mt-0.5">Arrastra para reordenar</p>
                    </div>
                    <div v-if="!estaciones.length" class="py-8 text-center text-sm text-tinta-300">Sin estaciones configuradas.</div>
                    <div class="divide-y divide-gray-50">
                        <div
                            v-for="(e, idx) in estaciones"
                            :key="e.id"
                            class="px-4 py-3 transition-colors cursor-default"
                            :class="dragOverEstacionIdx === idx && dragEstacionIdx !== idx ? 'bg-blue-50' : ''"
                            :draggable="true"
                            @dragstart="onDragStartEstacion(idx)"
                            @dragover="onDragOverEstacion($event, idx)"
                            @drop="onDropEstacion(idx)"
                            @dragend="onDragEndEstacion"
                        >
                            <!-- Fila principal -->
                            <div class="flex items-center gap-3">
                                <svg class="w-4 h-4 text-tinta-200 cursor-grab shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 8h16M4 16h16" />
                                </svg>
                                <div class="w-5 h-5 rounded-full shrink-0 border border-linea" :style="{ background: e.color }" />
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-tinta-900 truncate">{{ e.nombre }}</p>
                                    <p v-if="e.descripcion" class="text-xs text-tinta-300 truncate">{{ e.descripcion }}</p>
                                </div>
                                <span class="text-xs text-tinta-400 shrink-0">Cap: {{ e.capacidad_simultanea }}</span>
                                <span
                                    class="text-xs px-2 py-0.5 rounded-full shrink-0"
                                    :class="e.activa ? 'bg-green-100 text-green-700' : 'bg-tinta-100 text-tinta-400'"
                                >
                                    {{ e.activa ? 'Activa' : 'Inactiva' }}
                                </span>
                                <button @click="editarEstacion(e)" class="text-xs text-blue-600 hover:underline shrink-0">Editar</button>
                                <button @click="destroyEstacion(e.id)" class="text-xs text-red-500 hover:underline shrink-0">Eliminar</button>
                            </div>

                            <!-- Equipos asignados -->
                            <div class="mt-2 ml-7">
                                <div v-if="e.equipos?.length" class="flex flex-wrap gap-1">
                                    <span
                                        v-for="eq in e.equipos"
                                        :key="eq.id"
                                        class="text-xs px-2 py-0.5 rounded-full"
                                        :class="eq.estado === 'en_mantenimiento'
                                            ? 'bg-red-100 text-red-700'
                                            : eq.estado === 'fuera_servicio'
                                            ? 'bg-tinta-100 text-tinta-400'
                                            : 'bg-green-100 text-green-700'"
                                    >
                                        {{ eq.nombre }}<span v-if="eq.estado === 'en_mantenimiento'"> ⚠</span>
                                    </span>
                                </div>
                                <p v-else class="text-xs text-tinta-300 italic">Sin equipos asignados</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Formulario -->
                <div class="bg-white rounded-2xl border border-linea p-5">
                    <h3 class="text-sm font-semibold text-tinta-700 mb-4">
                        {{ editandoEstacion ? 'Editar estación' : 'Nueva estación de trabajo' }}
                    </h3>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Nombre *</label>
                            <input
                                v-model="estacionForm.nombre"
                                type="text"
                                placeholder="Ej: Taller de panelería"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Descripción</label>
                            <textarea
                                v-model="estacionForm.descripcion"
                                rows="2"
                                placeholder="Descripción opcional..."
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none"
                            ></textarea>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Color</label>
                            <div class="flex items-center gap-3">
                                <input
                                    v-model="estacionForm.color"
                                    type="color"
                                    class="w-10 h-10 rounded-xl border border-tinta-200 cursor-pointer p-0.5"
                                />
                                <span class="text-sm text-tinta-400 font-mono">{{ estacionForm.color }}</span>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Capacidad simultánea *</label>
                            <input
                                v-model.number="estacionForm.capacidad_simultanea"
                                type="number"
                                min="1"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                        </div>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input v-model="estacionForm.activa" type="checkbox" class="rounded" />
                            <span class="text-sm text-tinta-700">Activa</span>
                        </label>
                        <div class="flex gap-3">
                            <button
                                v-if="editandoEstacion"
                                @click="cancelarEstacion"
                                class="flex-1 py-2.5 rounded-xl border border-linea text-sm text-tinta-500"
                            >
                                Cancelar
                            </button>
                            <button
                                @click="storeEstacion"
                                class="flex-1 py-2.5 rounded-xl text-white text-sm font-semibold"
                                style="background:var(--marca);"
                            >
                                {{ editandoEstacion ? 'Actualizar' : 'Crear estación' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── PUNTOS E HITOS ─────────────────────────────────────────── -->
            <div v-show="tab === 'puntos'" class="space-y-4">

                <!-- Config de puntos -->
                <div class="bg-white rounded-2xl border border-linea overflow-hidden">
                    <div class="px-5 py-3 border-b border-linea">
                        <h2 class="text-sm font-semibold text-tinta-700">Configuración de puntos</h2>
                    </div>
                    <div class="p-5 space-y-4">
                        <div v-for="c in configPuntos" :key="c.clave">
                            <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">
                                {{ c.etiqueta ?? c.clave }}
                            </label>
                            <input
                                type="number"
                                v-model="configForm[c.clave]"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                            />
                        </div>
                    </div>
                    <div class="px-5 pb-5">
                        <button @click="saveConfig"
                            class="w-full py-3 rounded-2xl text-white text-sm font-semibold"
                            style="background:var(--marca);">
                            Guardar configuración de puntos
                        </button>
                    </div>
                </div>

                <!-- Niveles CRUD -->
                <div class="bg-white rounded-2xl border border-linea overflow-hidden">
                    <div class="px-5 py-3 border-b border-linea">
                        <h2 class="text-sm font-semibold text-tinta-700">Niveles de colaborador</h2>
                        <p class="text-xs text-tinta-300 mt-0.5">Define los niveles que se asignan según los puntos acumulados.</p>
                    </div>
                    <div v-if="!niveles.length" class="py-8 text-center text-sm text-tinta-300">Sin niveles configurados.</div>
                    <div class="divide-y divide-gray-50">
                        <div v-for="n in niveles" :key="n.id" class="px-4 py-3">
                            <div v-if="editandoNivel === n.id" class="space-y-3">
                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1">Nombre</label>
                                        <input v-model="nivelForm.nombre" type="text"
                                            class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2" />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1">Icono</label>
                                        <input v-model="nivelForm.icono" type="text" maxlength="4"
                                            class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2" />
                                    </div>
                                </div>
                                <div class="grid grid-cols-3 gap-2">
                                    <div>
                                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1">Color</label>
                                        <input v-model="nivelForm.color" type="color"
                                            class="w-full h-9 rounded-xl border border-tinta-200 px-1 py-1 cursor-pointer" />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1">Pts min</label>
                                        <input v-model.number="nivelForm.puntos_minimos" type="number" min="0"
                                            class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2" />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1">Pts max</label>
                                        <input v-model.number="nivelForm.puntos_maximos" type="number" min="0"
                                            placeholder="∞"
                                            class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2" />
                                    </div>
                                </div>
                                <div class="flex gap-2">
                                    <button @click="storeNivel"
                                        class="flex-1 py-2 rounded-xl text-white text-xs font-semibold"
                                        style="background:var(--marca);">Guardar</button>
                                    <button @click="cancelarNivel"
                                        class="px-4 py-2 rounded-xl border border-tinta-200 text-xs text-tinta-500">Cancelar</button>
                                </div>
                            </div>
                            <div v-else class="flex items-center gap-3">
                                <span class="text-2xl leading-none">{{ n.icono }}</span>
                                <div class="w-4 h-4 rounded-full shrink-0 border border-linea" :style="{ background: n.color }" />
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-tinta-900">{{ n.nombre }}</p>
                                    <p class="text-xs text-tinta-300">
                                        {{ n.puntos_minimos }} – {{ n.puntos_maximos ?? '∞' }} pts
                                    </p>
                                </div>
                                <button @click="editarNivel(n)"
                                    class="px-3 py-1 rounded-xl border border-tinta-200 text-xs text-tinta-500 hover:bg-tinta-50">
                                    Editar
                                </button>
                                <button @click="destroyNivel(n.id)"
                                    class="px-3 py-1 rounded-xl border border-red-200 text-xs text-red-500 hover:bg-red-50">
                                    Eliminar
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Nuevo nivel -->
                <div v-if="!editandoNivel" class="bg-white rounded-2xl border border-linea p-5">
                    <h3 class="text-sm font-semibold text-tinta-700 mb-4">Nuevo nivel</h3>
                    <div class="space-y-3">
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1">Nombre *</label>
                                <input v-model="nivelForm.nombre" type="text"
                                    class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2" />
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1">Icono (emoji)</label>
                                <input v-model="nivelForm.icono" type="text" maxlength="4"
                                    class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2" />
                            </div>
                        </div>
                        <div class="grid grid-cols-3 gap-2">
                            <div>
                                <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1">Color</label>
                                <input v-model="nivelForm.color" type="color"
                                    class="w-full h-9 rounded-xl border border-tinta-200 px-1 py-1 cursor-pointer" />
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1">Pts min *</label>
                                <input v-model.number="nivelForm.puntos_minimos" type="number" min="0"
                                    class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2" />
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1">Pts max</label>
                                <input v-model.number="nivelForm.puntos_maximos" type="number" min="0"
                                    placeholder="∞"
                                    class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2" />
                            </div>
                        </div>
                        <button @click="storeNivel"
                            class="w-full py-2.5 rounded-xl text-white text-sm font-semibold"
                            style="background:var(--marca);">
                            Crear nivel
                        </button>
                    </div>
                </div>
            </div>

            <!-- ── PIPELINE CRM ───────────────────────────────────────────── -->
            <div v-show="tab === 'crm'" class="space-y-4">

                <!-- Lista etapas -->
                <div class="bg-white rounded-2xl border border-linea overflow-hidden">
                    <div class="px-5 py-3 border-b border-linea">
                        <h2 class="text-sm font-semibold text-tinta-700">Etapas del Pipeline</h2>
                        <p class="text-xs text-tinta-300 mt-0.5">Arrastra para reordenar</p>
                    </div>
                    <div v-if="!crmEtapas.length" class="py-8 text-center text-sm text-tinta-300">Sin etapas configuradas.</div>
                    <div class="divide-y divide-gray-50">
                        <div
                            v-for="(etapa, idx) in crmEtapas"
                            :key="etapa.id"
                            class="flex items-center px-4 py-3 gap-3 transition-colors cursor-default"
                            :class="crmDragOverIdx === idx && crmDragIdx !== idx ? 'bg-blue-50' : ''"
                            draggable="true"
                            @dragstart="crmOnDragStart(idx)"
                            @dragover="crmOnDragOver($event, idx)"
                            @drop="crmOnDrop(idx)"
                            @dragend="crmOnDragEnd"
                        >
                            <svg class="w-4 h-4 text-tinta-200 cursor-grab shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 8h16M4 16h16"/>
                            </svg>
                            <div class="w-4 h-4 rounded-full shrink-0 border border-linea" :style="{ background: etapa.color }"/>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-tinta-900 truncate">{{ etapa.nombre }}</p>
                                <p class="text-xs text-tinta-300">{{ crmAccionLabel[etapa.accion_automatica] }}</p>
                            </div>
                            <span v-if="etapa.es_ganado" class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full shrink-0">Ganado</span>
                            <span v-if="etapa.es_perdido" class="text-xs bg-red-100 text-red-700 px-2 py-0.5 rounded-full shrink-0">Perdido</span>
                            <span class="text-xs px-2 py-0.5 rounded-full shrink-0"
                                :class="etapa.activa ? 'bg-green-100 text-green-700' : 'bg-tinta-100 text-tinta-400'">
                                {{ etapa.activa ? 'Activa' : 'Inactiva' }}
                            </span>
                            <button @click="crmEditarEtapa(etapa)" class="text-xs text-blue-600 hover:underline shrink-0">Editar</button>
                            <button @click="crmDestroyEtapa(etapa.id)" class="text-xs text-red-500 hover:underline shrink-0">Eliminar</button>
                        </div>
                    </div>
                </div>

                <!-- Formulario etapa -->
                <div class="bg-white rounded-2xl border border-linea p-5">
                    <h3 class="text-sm font-semibold text-tinta-700 mb-4">
                        {{ crmEditandoEtapa ? 'Editar etapa' : 'Nueva etapa' }}
                    </h3>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Nombre *</label>
                            <input v-model="crmEtapaForm.nombre" type="text" placeholder="Ej: Propuesta enviada"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"/>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Color</label>
                                <div class="flex items-center gap-2">
                                    <input v-model="crmEtapaForm.color" type="color"
                                        class="w-10 h-10 rounded-xl border border-tinta-200 cursor-pointer p-0.5"/>
                                    <span class="text-sm text-tinta-400 font-mono">{{ crmEtapaForm.color }}</span>
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Acción automática</label>
                                <select v-model="crmEtapaForm.accion_automatica"
                                    class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white">
                                    <option value="ninguna">Ninguna</option>
                                    <option value="cotizacion">Crear cotización</option>
                                    <option value="op">Crear OP</option>
                                </select>
                            </div>
                        </div>
                        <div class="flex gap-4">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input v-model="crmEtapaForm.es_ganado" type="checkbox" class="rounded"/>
                                <span class="text-sm text-tinta-700">Es etapa "Ganado"</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input v-model="crmEtapaForm.es_perdido" type="checkbox" class="rounded"/>
                                <span class="text-sm text-tinta-700">Es etapa "Perdido"</span>
                            </label>
                        </div>
                        <label v-if="crmEditandoEtapa" class="flex items-center gap-2 cursor-pointer">
                            <input v-model="crmEtapaForm.activa" type="checkbox" class="rounded"/>
                            <span class="text-sm text-tinta-700">Activa</span>
                        </label>
                        <div class="flex gap-3">
                            <button v-if="crmEditandoEtapa" @click="crmCancelarEtapa"
                                class="flex-1 py-2.5 rounded-xl border border-linea text-sm text-tinta-500">
                                Cancelar
                            </button>
                            <button @click="crmStoreEtapa"
                                class="flex-1 py-2.5 rounded-xl text-white text-sm font-semibold"
                                style="background:var(--marca);">
                                {{ crmEditandoEtapa ? 'Actualizar' : 'Crear etapa' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── SISTEMA ────────────────────────────────────────────────── -->
            <div v-show="tab === 'sistema'" class="space-y-6">

                <!-- ORGANIZACIÓN -->
                <div>
                    <p class="text-xs font-semibold text-tinta-300 uppercase tracking-[0.12em] mb-3">Organización</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-6">
                        <a href="/configuracion/sedes" @click.prevent="router.visit('/configuracion/sedes')"
                            class="flex items-center gap-3 bg-white rounded-xl border border-linea p-4 hover:border-blue-300 hover:shadow-sm transition-all">
                            <div class="w-10 h-10 rounded-xl bg-blue-50 flex items-center justify-center shrink-0">
                                <svg class="w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-semibold text-tinta-900">Sedes</p>
                                <p class="text-xs text-tinta-400 mt-0.5">Sedes de ventas y fábricas de la empresa</p>
                            </div>
                            <svg class="w-4 h-4 text-tinta-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                            </svg>
                        </a>
                        <a href="/configuracion/perfil-marca" @click.prevent="router.visit('/configuracion/perfil-marca')"
                            class="flex items-center gap-3 bg-white rounded-xl border border-linea p-4 hover:border-blue-300 hover:shadow-sm transition-all">
                            <div class="w-10 h-10 rounded-xl bg-blue-50 flex items-center justify-center shrink-0">
                                <svg class="w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-semibold text-tinta-900">Perfil de marca y asistente</p>
                                <p class="text-xs text-tinta-400 mt-0.5">Identidad, tono y voz, y el nombre del asistente</p>
                            </div>
                            <svg class="w-4 h-4 text-tinta-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                            </svg>
                        </a>
                        <a href="/configuracion/integraciones/wordpress" @click.prevent="router.visit('/configuracion/integraciones/wordpress')"
                            class="flex items-center gap-3 bg-white rounded-xl border border-linea p-4 hover:border-blue-300 hover:shadow-sm transition-all">
                            <div class="w-10 h-10 rounded-xl bg-blue-50 flex items-center justify-center shrink-0">
                                <svg class="w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 010 5.656l-4 4a4 4 0 01-5.656-5.656l1.5-1.5M10.172 13.828a4 4 0 010-5.656l4-4a4 4 0 015.656 5.656l-1.5 1.5" />
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-semibold text-tinta-900">Integraciones — WordPress</p>
                                <p class="text-xs text-tinta-400 mt-0.5">Token para conectar el plugin Briela Connect</p>
                            </div>
                            <svg class="w-4 h-4 text-tinta-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                            </svg>
                        </a>
                        <a href="/configuracion/roles" @click.prevent="router.visit('/configuracion/roles')"
                            class="flex items-center gap-3 bg-white rounded-xl border border-linea p-4 hover:border-blue-300 hover:shadow-sm transition-all">
                            <div class="w-10 h-10 rounded-xl bg-blue-50 flex items-center justify-center shrink-0">
                                <svg class="w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-semibold text-tinta-900">Roles y permisos</p>
                                <p class="text-xs text-tinta-400 mt-0.5">Crear roles y definir qué puede hacer cada uno</p>
                            </div>
                            <svg class="w-4 h-4 text-tinta-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                            </svg>
                        </a>
                        <a href="/configuracion/numeracion" @click.prevent="router.visit('/configuracion/numeracion')"
                            class="flex items-center gap-3 bg-white rounded-xl border border-linea p-4 hover:border-blue-300 hover:shadow-sm transition-all">
                            <div class="w-10 h-10 rounded-xl bg-blue-50 flex items-center justify-center shrink-0">
                                <svg class="w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 20l4-16m2 16l4-16M6 9h14M4 15h14" />
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-semibold text-tinta-900">Numeración</p>
                                <p class="text-xs text-tinta-400 mt-0.5">Prefijos y consecutivos de cada documento, por sede</p>
                            </div>
                            <svg class="w-4 h-4 text-tinta-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                            </svg>
                        </a>
                        <a href="/configuracion/marca" @click.prevent="router.visit('/configuracion/marca')"
                            class="flex items-center gap-3 bg-white rounded-xl border border-linea p-4 hover:border-blue-300 hover:shadow-sm transition-all">
                            <div class="w-10 h-10 rounded-xl bg-blue-50 flex items-center justify-center shrink-0">
                                <svg class="w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828L11 19.5M7 17h.01" />
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-semibold text-tinta-900">Marca</p>
                                <p class="text-xs text-tinta-400 mt-0.5">Color, favicon y título de la pestaña del navegador</p>
                            </div>
                            <svg class="w-4 h-4 text-tinta-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                            </svg>
                        </a>
                        <a href="/configuracion/identificacion" @click.prevent="router.visit('/configuracion/identificacion')"
                            class="flex items-center gap-3 bg-white rounded-xl border border-linea p-4 hover:border-blue-300 hover:shadow-sm transition-all">
                            <div class="w-10 h-10 rounded-xl bg-blue-50 flex items-center justify-center shrink-0">
                                <svg class="w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0h4m-7 6h3m-3 3h6" />
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-semibold text-tinta-900">Identificación de clientes</p>
                                <p class="text-xs text-tinta-400 mt-0.5">Dígito de verificación del NIT, duplicados y consulta al RUES</p>
                            </div>
                            <svg class="w-4 h-4 text-tinta-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                            </svg>
                        </a>
                    </div>
                </div>

                <!-- INVENTARIO Y COTIZACIÓN -->
                <div>
                    <p class="text-xs font-semibold text-tinta-300 uppercase tracking-[0.12em] mb-3">Inventario y Cotización</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <a href="/configuracion/bodegas" @click.prevent="router.visit('/configuracion/bodegas')"
                            class="flex items-center gap-3 bg-white rounded-xl border border-linea p-4 hover:border-blue-300 hover:shadow-sm transition-all">
                            <div class="w-10 h-10 rounded-xl bg-blue-50 flex items-center justify-center shrink-0">
                                <svg class="w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 7a1 1 0 011-1h5a1 1 0 011 1v3H3V7zm0 3h7v7a1 1 0 01-1 1H4a1 1 0 01-1-1v-7zm11-3a1 1 0 011-1h5a1 1 0 011 1v3h-7V7zm0 3h7v7a1 1 0 01-1 1h-5a1 1 0 01-1-1v-7z" />
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-semibold text-tinta-900">Bodegas</p>
                                <p class="text-xs text-tinta-400 mt-0.5">Crear y gestionar bodegas de almacenamiento</p>
                            </div>
                            <svg class="w-4 h-4 text-tinta-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                            </svg>
                        </a>
                        <a href="/cotizadores/plantillas" @click.prevent="router.visit('/cotizadores/plantillas')"
                            class="flex items-center gap-3 bg-white rounded-xl border border-linea p-4 hover:border-blue-300 hover:shadow-sm transition-all">
                            <div class="w-10 h-10 rounded-xl bg-blue-50 flex items-center justify-center shrink-0">
                                <svg class="w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-semibold text-tinta-900">Plantillas de Ensamble</p>
                                <p class="text-xs text-tinta-400 mt-0.5">Constructor de plantillas con fórmulas y componentes</p>
                            </div>
                            <svg class="w-4 h-4 text-tinta-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                            </svg>
                        </a>
                    </div>
                </div>

                <!-- Producción: "Templates de Trabajo" queda oculto del menú desde la
                     fusión con Plantillas de Ensamble (los pasos se cargan ahora en
                     Cotizadores > Plantillas, pestaña "Producción"). La ruta
                     /produccion/templates sigue viva para no romper historial. -->

                <!-- RRHH -->
                <div>
                    <p class="text-xs font-semibold text-tinta-300 uppercase tracking-[0.12em] mb-3">RRHH</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <a href="/rrhh/configuracion" @click.prevent="router.visit('/rrhh/configuracion')"
                            class="flex items-center gap-3 bg-white rounded-xl border border-linea p-4 hover:border-blue-300 hover:shadow-sm transition-all">
                            <div class="w-10 h-10 rounded-xl bg-blue-50 flex items-center justify-center shrink-0">
                                <svg class="w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-semibold text-tinta-900">Configuración RRHH</p>
                                <p class="text-xs text-tinta-400 mt-0.5">Turnos, tarifas y configuración de nómina</p>
                            </div>
                            <svg class="w-4 h-4 text-tinta-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                            </svg>
                        </a>
                    </div>
                </div>

                <!-- ADMINISTRACIÓN -->
                <div>
                    <p class="text-xs font-semibold text-tinta-300 uppercase tracking-[0.12em] mb-3">Administración</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <a href="/usuarios" @click.prevent="router.visit('/usuarios')"
                            class="flex items-center gap-3 bg-white rounded-xl border border-linea p-4 hover:border-blue-300 hover:shadow-sm transition-all">
                            <div class="w-10 h-10 rounded-xl bg-blue-50 flex items-center justify-center shrink-0">
                                <svg class="w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-semibold text-tinta-900">Usuarios</p>
                                <p class="text-xs text-tinta-400 mt-0.5">Gestión de usuarios y roles del sistema</p>
                            </div>
                            <svg class="w-4 h-4 text-tinta-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                            </svg>
                        </a>
                        <a href="/configuracion/whatsapp-numeros" @click.prevent="router.visit('/configuracion/whatsapp-numeros')"
                            class="flex items-center gap-3 bg-white rounded-xl border border-linea p-4 hover:border-blue-300 hover:shadow-sm transition-all">
                            <div class="w-10 h-10 rounded-xl bg-blue-50 flex items-center justify-center shrink-0">
                                <svg class="w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-6l-4 4v-4z" />
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-semibold text-tinta-900">Números de WhatsApp</p>
                                <p class="text-xs text-tinta-400 mt-0.5">Gestión de números y modo Coexistencia (WhatsApp Cloud API)</p>
                            </div>
                            <svg class="w-4 h-4 text-tinta-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                            </svg>
                        </a>
                        <a href="/administracion/backup" @click.prevent="router.visit('/administracion/backup')"
                            class="flex items-center gap-3 bg-white rounded-xl border border-linea p-4 hover:border-blue-300 hover:shadow-sm transition-all">
                            <div class="w-10 h-10 rounded-xl bg-blue-50 flex items-center justify-center shrink-0">
                                <svg class="w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 7C4 5.343 7.582 4 12 4s8 1.343 8 3v2c0 1.657-3.582 3-8 3S4 10.657 4 9V7z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 9v4c0 1.657 3.582 3 8 3s8-1.343 8-3V9" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 13v4c0 1.657 3.582 3 8 3s8-1.343 8-3v-4" />
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-semibold text-tinta-900">Backup Base de Datos</p>
                                <p class="text-xs text-tinta-400 mt-0.5">Respaldos y restauración de la base de datos</p>
                            </div>
                            <svg class="w-4 h-4 text-tinta-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                            </svg>
                        </a>
                    </div>
                </div>

                <!-- PANTALLA DE PLANTA -->
                <div>
                    <p class="text-xs font-semibold text-tinta-300 uppercase tracking-[0.12em] mb-3">Pantalla de Planta</p>
                    <div class="bg-white rounded-xl border border-linea p-4">
                        <p class="text-sm font-semibold text-tinta-700 mb-1">📺 Pantalla de Planta</p>
                        <p class="text-xs text-tinta-400 mb-3">
                            URL pública para mostrar en TV o monitor de la planta. Se actualiza automáticamente cada 30 segundos.
                        </p>
                        <div class="flex gap-2">
                            <input
                                readonly
                                :value="urlPantallaFull"
                                class="flex-1 rounded-lg border border-linea px-3 py-2 text-xs text-tinta-500 bg-tinta-50"
                            />
                            <button
                                @click="copiarUrlPantalla"
                                class="px-3 py-2 rounded-lg text-xs font-medium text-white shrink-0"
                                style="background:var(--marca)"
                            >
                                Copiar
                            </button>
                            <a
                                :href="urlPantalla"
                                target="_blank"
                                class="px-3 py-2 rounded-lg text-xs font-medium border border-tinta-200 text-tinta-500 hover:bg-tinta-50 shrink-0"
                            >
                                Ver
                            </a>
                        </div>

                        <div class="mt-3 pt-3 border-t border-linea">
                            <p class="text-xs text-tinta-400 mb-2">
                                Cualquiera con esta URL ve las órdenes en curso, sin contraseña.
                                Si se filtró, genera una nueva.
                            </p>
                            <button
                                @click="regenerarTokenPantalla"
                                :disabled="regenerandoPantalla"
                                class="px-3 py-2 rounded-lg text-xs font-medium border border-amber-300 text-amber-700 hover:bg-amber-50 disabled:opacity-50"
                            >
                                {{ regenerandoPantalla ? 'Generando…' : 'Generar URL nueva' }}
                            </button>
                            <p v-if="tokenPantallaNuevo" class="text-xs text-green-600 mt-2 font-medium">
                                Listo. La URL de arriba ya es la nueva — cópiala y cárgala en las pantallas de la planta.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- CLIENTES -->
                <div>
                    <p class="text-xs font-semibold text-tinta-300 uppercase tracking-[0.12em] mb-3">Clientes</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <a href="/administracion/segmentacion" @click.prevent="router.visit('/administracion/segmentacion')"
                            class="flex items-center gap-3 bg-white rounded-xl border border-linea p-4 hover:border-blue-300 hover:shadow-sm transition-all">
                            <div class="w-10 h-10 rounded-xl bg-blue-50 flex items-center justify-center shrink-0">
                                <svg class="w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01" />
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-semibold text-tinta-900">Segmentación</p>
                                <p class="text-xs text-tinta-400 mt-0.5">Tipos de contacto, industrias y procesos de seguimiento</p>
                            </div>
                            <svg class="w-4 h-4 text-tinta-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                            </svg>
                        </a>
                    </div>
                </div>

            </div>

        </div>
    </AppLayout>
</template>
