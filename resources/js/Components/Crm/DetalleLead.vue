<script setup>
import { ref, watch, onMounted } from 'vue'
import { router } from '@inertiajs/vue3'

const props = defineProps({
    leadId:   { type: Number, required: true },
    usuarios: { type: Array,  default: () => [] },
    clientes: { type: Array,  default: () => [] },
    etapas:   { type: Array,  default: () => [] },
})

const emit = defineEmits(['cerrar', 'actualizado'])

const lead     = ref(null)
const cargando = ref(true)

// ── Tareas ────────────────────────────────────────────────────────────────────
const mostrarFormTarea = ref(false)
const guardandoTarea   = ref(false)
const formTarea = ref({ titulo: '', tipo: 'seguimiento', fecha_vencimiento: '', responsable_id: null })

// ── Notas ─────────────────────────────────────────────────────────────────────
const nuevaNota    = ref('')
const guardandoNota = ref(false)

// ── Cierre ────────────────────────────────────────────────────────────────────
const modalCierre     = ref(false)
const tipoCierre      = ref('')
const motivoCierre    = ref('')
const guardandoCierre = ref(false)

function getCookie(name) {
    const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'))
    return match ? decodeURIComponent(match[2]) : ''
}

async function cargarLead() {
    cargando.value = true
    const res = await fetch(`/crm/leads/${props.leadId}`, { credentials: 'same-origin' })
    const data = await res.json()
    lead.value = data.lead
    cargando.value = false
}

onMounted(cargarLead)

// ── Tareas ─────────────────────────────────────────────────────────────────────
function esTareaVencida(tarea) {
    return !tarea.completada && tarea.fecha_vencimiento && new Date(tarea.fecha_vencimiento) < new Date(new Date().toDateString())
}

async function toggleTarea(tarea) {
    const res = await fetch(`/crm/tareas/${tarea.id}/completar`, {
        method: 'PUT',
        headers: { 'X-XSRF-TOKEN': getCookie('XSRF-TOKEN') },
        credentials: 'same-origin',
    })
    const data = await res.json()
    const idx = lead.value.tareas.findIndex(t => t.id === tarea.id)
    if (idx !== -1) lead.value.tareas[idx] = data.tarea
}

async function guardarTarea() {
    if (!formTarea.value.titulo.trim()) return
    guardandoTarea.value = true
    const res = await fetch(`/crm/leads/${props.leadId}/tareas`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': getCookie('XSRF-TOKEN') },
        credentials: 'same-origin',
        body: JSON.stringify(formTarea.value),
    })
    const data = await res.json()
    lead.value.tareas.push(data.tarea)
    formTarea.value = { titulo: '', tipo: 'seguimiento', fecha_vencimiento: '', responsable_id: null }
    mostrarFormTarea.value = false
    guardandoTarea.value   = false
}

async function eliminarTarea(id) {
    if (!confirm('¿Eliminar esta tarea?')) return
    await fetch(`/crm/tareas/${id}`, {
        method: 'DELETE',
        headers: { 'X-XSRF-TOKEN': getCookie('XSRF-TOKEN') },
        credentials: 'same-origin',
    })
    lead.value.tareas = lead.value.tareas.filter(t => t.id !== id)
}

// ── Notas ──────────────────────────────────────────────────────────────────────
async function guardarNota() {
    if (!nuevaNota.value.trim()) return
    guardandoNota.value = true
    const res = await fetch(`/crm/leads/${props.leadId}/notas`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': getCookie('XSRF-TOKEN') },
        credentials: 'same-origin',
        body: JSON.stringify({ contenido: nuevaNota.value }),
    })
    const data = await res.json()
    lead.value.notas.unshift(data.nota)
    nuevaNota.value     = ''
    guardandoNota.value = false
}

async function eliminarNota(id) {
    if (!confirm('¿Eliminar esta nota?')) return
    await fetch(`/crm/notas/${id}`, {
        method: 'DELETE',
        headers: { 'X-XSRF-TOKEN': getCookie('XSRF-TOKEN') },
        credentials: 'same-origin',
    })
    lead.value.notas = lead.value.notas.filter(n => n.id !== id)
}

// ── Cierre (ganado/perdido) ────────────────────────────────────────────────────
function abrirCierre(tipo) {
    tipoCierre.value   = tipo
    motivoCierre.value = ''
    modalCierre.value  = true
}

async function confirmarCierre() {
    guardandoCierre.value = true

    const etapaDestino = props.etapas.find(e =>
        tipoCierre.value === 'ganado' ? e.es_ganado : e.es_perdido
    )

    if (!etapaDestino) {
        alert(`No hay etapa configurada como "${tipoCierre.value}". Ve a Configuración > Pipeline CRM y marca una etapa como ${tipoCierre.value}.`)
        guardandoCierre.value = false
        return
    }

    await fetch(`/crm/leads/${props.leadId}/mover`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': getCookie('XSRF-TOKEN') },
        credentials: 'same-origin',
        body: JSON.stringify({
            etapa_id:       etapaDestino.id,
            orden_en_etapa: 0,
            motivo_cierre:  motivoCierre.value,
        }),
    })

    guardandoCierre.value = false
    modalCierre.value     = false
    emit('cerrar')
    emit('actualizado')
}

// ── Convertir / vincular cliente ──────────────────────────────────────────────
const mostrarFormConvertir = ref(false)
const convirtiendoCliente  = ref(false)
const errorConvertir       = ref('')
const formConvertir = ref({ tipo: 'empresa', nombre: '', email: '', celular: '', ciudad: '' })
const clienteExistenteId   = ref('')

watch(mostrarFormConvertir, (val) => {
    if (val && lead.value) {
        formConvertir.value.nombre  = lead.value.empresa_contacto ?? lead.value.nombre_contacto ?? ''
        formConvertir.value.email   = lead.value.email_contacto   ?? ''
        formConvertir.value.celular = lead.value.telefono_contacto ?? ''
        formConvertir.value.tipo    = lead.value.empresa_contacto ? 'empresa' : 'persona'
    }
})

async function convertirACliente() {
    errorConvertir.value = ''
    if (!formConvertir.value.nombre) { errorConvertir.value = 'El nombre es requerido.'; return }
    convirtiendoCliente.value = true
    try {
        const res = await fetch(`/crm/leads/${props.leadId}/convertir`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-XSRF-TOKEN': getCookie('XSRF-TOKEN') },
            credentials: 'same-origin',
            body: JSON.stringify(formConvertir.value),
        })
        if (!res.ok) {
            const err = await res.json()
            errorConvertir.value = err.message ?? 'Error al convertir.'
            return
        }
        const data = await res.json()
        lead.value.cliente = data.cliente
        if (data.lead?.etapa) lead.value.etapa = data.lead.etapa
        mostrarFormConvertir.value = false
        emit('actualizado')

        if (data.cotizacion_id) {
            if (confirm('Cliente creado. Se generó automáticamente una cotización en borrador. ¿Abrirla ahora?')) {
                router.visit(`/cotizaciones/${data.cotizacion_id}`)
            }
        }
    } catch {
        errorConvertir.value = 'Error de conexión.'
    } finally {
        convirtiendoCliente.value = false
    }
}

async function vincularCliente() {
    if (!clienteExistenteId.value) return
    await fetch(`/crm/leads/${props.leadId}`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-XSRF-TOKEN': getCookie('XSRF-TOKEN') },
        credentials: 'same-origin',
        body: JSON.stringify({ cliente_id: clienteExistenteId.value }),
    })
    await cargarLead()
    emit('actualizado')
}

const tipoTareaLabel = { llamada: 'Llamada', email: 'Email', reunion: 'Reunión', seguimiento: 'Seguimiento', otro: 'Otro' }
const tipoTareaColor = { llamada: 'blue', email: 'purple', reunion: 'green', seguimiento: 'yellow', otro: 'gray' }

function formatFecha(fecha) {
    if (!fecha) return ''
    return new Date(fecha + 'T00:00:00').toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric' })
}
</script>

<template>
    <teleport to="body">
        <!-- Overlay -->
        <div class="fixed inset-0 z-[55]" style="background: rgba(0,0,0,0.4);" @click="emit('cerrar')" />

        <!-- Panel slide-over -->
        <div class="fixed top-0 right-0 bottom-0 z-[56] flex flex-col bg-white shadow-2xl w-full max-w-lg overflow-hidden">

            <!-- Loader -->
            <div v-if="cargando" class="flex-1 flex items-center justify-center">
                <div class="w-8 h-8 border-4 border-gray-200 rounded-full animate-spin" style="border-top-color: var(--marca);"></div>
            </div>

            <template v-else-if="lead">
                <!-- ── A) Header ──────────────────────────────────────────── -->
                <div class="flex items-start justify-between gap-3 px-5 py-4 border-b border-gray-100 shrink-0">
                    <div class="min-w-0">
                        <h2 class="text-base font-bold text-gray-900 leading-snug">{{ lead.titulo }}</h2>
                        <div class="flex items-center gap-2 mt-1">
                            <span
                                class="inline-block w-2 h-2 rounded-full shrink-0"
                                :style="{ background: lead.etapa?.color }"
                            ></span>
                            <span class="text-xs text-gray-500">{{ lead.etapa?.nombre }}</span>
                        </div>
                    </div>
                    <button @click="emit('cerrar')"
                        class="w-8 h-8 flex items-center justify-center rounded-xl hover:bg-gray-100 transition-colors shrink-0 mt-0.5">
                        <svg class="w-5 h-5 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <!-- ── Contenido scrollable ───────────────────────────────── -->
                <div class="flex-1 overflow-y-auto">

                    <!-- ── B) Info contacto ──────────────────────────────── -->
                    <div class="px-5 py-4 border-b border-gray-100">
                        <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-3">Contacto</h3>
                        <div class="space-y-2">
                            <div v-if="lead.nombre_contacto" class="flex items-center gap-2 text-sm text-gray-700">
                                <svg class="w-4 h-4 text-gray-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                </svg>
                                {{ lead.nombre_contacto }}
                            </div>
                            <div v-if="lead.empresa_contacto" class="flex items-center gap-2 text-sm text-gray-700">
                                <svg class="w-4 h-4 text-gray-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-2 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                                </svg>
                                {{ lead.empresa_contacto }}
                            </div>
                            <a v-if="lead.email_contacto" :href="`mailto:${lead.email_contacto}`"
                                class="flex items-center gap-2 text-sm text-blue-600 hover:underline">
                                <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                                </svg>
                                {{ lead.email_contacto }}
                            </a>
                            <a v-if="lead.telefono_contacto" :href="`tel:${lead.telefono_contacto}`"
                                class="flex items-center gap-2 text-sm text-blue-600 hover:underline">
                                <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                                </svg>
                                {{ lead.telefono_contacto }}
                            </a>
                            <div v-if="lead.fuente" class="flex items-center gap-2 text-sm text-gray-700">
                                <svg class="w-4 h-4 text-gray-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                                </svg>
                                {{ lead.fuente }}
                            </div>
                            <div v-if="lead.responsable" class="flex items-center gap-2 text-sm text-gray-700">
                                <svg class="w-4 h-4 text-gray-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                </svg>
                                Responsable: <span class="font-medium">{{ lead.responsable.name }}</span>
                            </div>
                            <div v-if="lead.cliente" class="flex items-center gap-2 text-sm text-gray-700">
                                <svg class="w-4 h-4 text-green-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                Cliente: <a :href="`/clientes/${lead.cliente.id}`" class="text-blue-600 font-medium hover:underline">{{ lead.cliente.nombre }}</a>
                            </div>

                            <!-- Convertir / vincular cliente -->
                            <div v-if="!lead.cliente" class="mt-3 space-y-2">

                                <!-- Botón abrir form nuevo cliente -->
                                <button
                                    v-if="!mostrarFormConvertir"
                                    @click="mostrarFormConvertir = true"
                                    class="flex items-center gap-1.5 text-sm font-medium border rounded-lg px-3 py-1.5 transition-colors hover:bg-blue-50"
                                    style="color: var(--marca); border-color: var(--marca);"
                                >
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                    </svg>
                                    Convertir a cliente
                                </button>

                                <!-- Form nuevo cliente -->
                                <div v-if="mostrarFormConvertir" class="bg-blue-50 rounded-xl p-3 space-y-2">
                                    <p class="text-xs font-semibold text-gray-700">Nuevo cliente</p>
                                    <div class="grid grid-cols-2 gap-2">
                                        <div>
                                            <label class="text-xs text-gray-500 block mb-1">Tipo</label>
                                            <select v-model="formConvertir.tipo"
                                                class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm bg-white">
                                                <option value="empresa">Empresa</option>
                                                <option value="persona">Persona</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="text-xs text-gray-500 block mb-1">Nombre *</label>
                                            <input v-model="formConvertir.nombre" placeholder="Nombre o razón social"
                                                class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm"/>
                                        </div>
                                    </div>
                                    <div class="grid grid-cols-2 gap-2">
                                        <div>
                                            <label class="text-xs text-gray-500 block mb-1">Email</label>
                                            <input v-model="formConvertir.email" type="email"
                                                class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm"/>
                                        </div>
                                        <div>
                                            <label class="text-xs text-gray-500 block mb-1">Celular</label>
                                            <input v-model="formConvertir.celular"
                                                class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm"/>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="text-xs text-gray-500 block mb-1">Ciudad</label>
                                        <input v-model="formConvertir.ciudad"
                                            class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm"/>
                                    </div>
                                    <p v-if="errorConvertir" class="text-xs text-red-600 bg-red-50 rounded px-2 py-1">
                                        {{ errorConvertir }}
                                    </p>
                                    <div class="flex gap-2 pt-1">
                                        <button @click="convertirACliente" :disabled="convirtiendoCliente"
                                            class="text-white text-sm px-4 py-1.5 rounded-lg font-medium disabled:opacity-50"
                                            style="background-color: var(--marca);">
                                            {{ convirtiendoCliente ? 'Guardando...' : 'Crear cliente' }}
                                        </button>
                                        <button @click="mostrarFormConvertir = false"
                                            class="text-sm text-gray-500 px-3 py-1.5">
                                            Cancelar
                                        </button>
                                    </div>
                                </div>

                                <!-- Vincular cliente existente -->
                                <div v-if="!mostrarFormConvertir && clientes.length">
                                    <p class="text-xs text-gray-400 mb-1">O vincular a cliente existente:</p>
                                    <div class="flex gap-2">
                                        <select v-model="clienteExistenteId"
                                            class="flex-1 border border-gray-300 rounded-lg px-2 py-1.5 text-sm bg-white">
                                            <option value="">Seleccionar cliente...</option>
                                            <option v-for="c in clientes" :key="c.id" :value="c.id">{{ c.nombre }}</option>
                                        </select>
                                        <button @click="vincularCliente" :disabled="!clienteExistenteId"
                                            class="text-white text-sm px-3 py-1.5 rounded-lg disabled:opacity-40"
                                            style="background-color: #374151;">
                                            Vincular
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div v-if="lead.descripcion" class="mt-3 text-sm text-gray-600 bg-gray-50 rounded-xl px-3 py-2.5">
                            {{ lead.descripcion }}
                        </div>
                    </div>

                    <!-- ── C) Tareas ─────────────────────────────────────── -->
                    <div class="px-5 py-4 border-b border-gray-100">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider">Tareas</h3>
                            <button @click="mostrarFormTarea = !mostrarFormTarea"
                                class="flex items-center gap-1 text-xs font-semibold px-2.5 py-1.5 rounded-lg transition-colors"
                                style="color: var(--marca); background: #EFF6FF;">
                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                                </svg>
                                Nueva tarea
                            </button>
                        </div>

                        <!-- Form nueva tarea -->
                        <div v-if="mostrarFormTarea" class="bg-blue-50 rounded-xl p-3 mb-3 space-y-2">
                            <input v-model="formTarea.titulo" type="text" placeholder="Título de la tarea"
                                class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white"/>
                            <div class="grid grid-cols-2 gap-2">
                                <select v-model="formTarea.tipo"
                                    class="border border-gray-200 rounded-lg px-2 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <option value="llamada">Llamada</option>
                                    <option value="email">Email</option>
                                    <option value="reunion">Reunión</option>
                                    <option value="seguimiento">Seguimiento</option>
                                    <option value="otro">Otro</option>
                                </select>
                                <input v-model="formTarea.fecha_vencimiento" type="date"
                                    class="border border-gray-200 rounded-lg px-2 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500"/>
                            </div>
                            <select v-model="formTarea.responsable_id"
                                class="w-full border border-gray-200 rounded-lg px-2 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option :value="null">Sin responsable</option>
                                <option v-for="u in usuarios" :key="u.id" :value="u.id">{{ u.name }}</option>
                            </select>
                            <div class="flex gap-2">
                                <button @click="mostrarFormTarea = false"
                                    class="flex-1 py-2 rounded-lg text-xs font-semibold text-gray-500 border border-gray-200 hover:bg-gray-50">
                                    Cancelar
                                </button>
                                <button @click="guardarTarea" :disabled="guardandoTarea"
                                    class="flex-1 py-2 rounded-lg text-xs font-semibold text-white disabled:opacity-60"
                                    style="background-color: var(--marca);">
                                    Guardar
                                </button>
                            </div>
                        </div>

                        <!-- Lista de tareas -->
                        <div v-if="lead.tareas.length" class="space-y-2">
                            <div v-for="tarea in lead.tareas" :key="tarea.id"
                                class="flex items-start gap-2 p-2.5 rounded-xl"
                                :class="esTareaVencida(tarea) ? 'bg-red-50 border border-red-100' : 'bg-gray-50'">
                                <button @click="toggleTarea(tarea)"
                                    class="mt-0.5 w-4 h-4 rounded border-2 shrink-0 flex items-center justify-center transition-colors"
                                    :class="tarea.completada ? 'border-green-500 bg-green-500' : 'border-gray-300 hover:border-gray-500'">
                                    <svg v-if="tarea.completada" class="w-3 h-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                    </svg>
                                </button>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-gray-800 leading-snug"
                                        :class="{ 'line-through text-gray-400': tarea.completada }">
                                        {{ tarea.titulo }}
                                    </p>
                                    <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                                        <span class="text-xs px-1.5 py-0.5 rounded-md font-medium"
                                            :class="`bg-${tipoTareaColor[tarea.tipo]}-100 text-${tipoTareaColor[tarea.tipo]}-700`">
                                            {{ tipoTareaLabel[tarea.tipo] }}
                                        </span>
                                        <span v-if="tarea.fecha_vencimiento"
                                            class="text-xs"
                                            :class="esTareaVencida(tarea) ? 'text-red-600 font-semibold' : 'text-gray-400'">
                                            {{ formatFecha(tarea.fecha_vencimiento) }}
                                        </span>
                                        <span v-if="tarea.responsable" class="text-xs text-gray-400">
                                            {{ tarea.responsable.name }}
                                        </span>
                                    </div>
                                </div>
                                <button @click="eliminarTarea(tarea.id)"
                                    class="text-gray-300 hover:text-red-400 transition-colors shrink-0 mt-0.5">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                        <p v-else class="text-sm text-gray-400 text-center py-3">Sin tareas</p>
                    </div>

                    <!-- ── D) Actividad ───────────────────────────────── -->
                    <div class="px-5 py-4 border-b border-gray-100">
                        <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-3">Actividad</h3>
                        <div v-if="lead.actividades?.length" class="space-y-2">
                            <div v-for="act in lead.actividades" :key="act.id" class="flex items-start gap-2">
                                <span class="w-6 h-6 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5"
                                    :class="{
                                        'bg-blue-100':   act.tipo === 'etapa',
                                        'bg-green-100':  act.tipo === 'tarea' || act.tipo === 'conversion',
                                        'bg-yellow-100': act.tipo === 'nota',
                                        'bg-gray-100':   act.tipo === 'creacion',
                                        'bg-red-100':    act.tipo === 'cierre',
                                    }">
                                    <svg v-if="act.tipo === 'etapa'" xmlns="http://www.w3.org/2000/svg" class="w-3 h-3 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                                    </svg>
                                    <svg v-else-if="act.tipo === 'tarea'" xmlns="http://www.w3.org/2000/svg" class="w-3 h-3 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                    </svg>
                                    <svg v-else-if="act.tipo === 'nota'" xmlns="http://www.w3.org/2000/svg" class="w-3 h-3 text-yellow-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                    </svg>
                                    <svg v-else xmlns="http://www.w3.org/2000/svg" class="w-3 h-3 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                </span>
                                <div class="flex-1 min-w-0">
                                    <p class="text-xs text-gray-700">{{ act.descripcion }}</p>
                                    <p class="text-xs text-gray-400 mt-0.5">
                                        {{ act.user?.name ?? 'Sistema' }} ·
                                        {{ new Date(act.created_at.replace(' ', 'T')).toLocaleDateString('es-CO', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) }}
                                    </p>
                                </div>
                            </div>
                        </div>
                        <p v-else class="text-xs text-gray-400 text-center py-2">Sin actividad registrada</p>
                    </div>

                    <!-- ── F) Notas ─────────────────────────────────────── -->
                    <div class="px-5 py-4 border-b border-gray-100">
                        <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-3">Notas</h3>

                        <!-- Nueva nota -->
                        <div class="flex gap-2 mb-3">
                            <textarea v-model="nuevaNota" rows="2"
                                class="flex-1 border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none"
                                placeholder="Agregar nota…"></textarea>
                            <button @click="guardarNota" :disabled="guardandoNota || !nuevaNota.trim()"
                                class="px-3 py-2 rounded-xl text-white text-sm font-semibold disabled:opacity-40 transition-opacity self-end"
                                style="background-color: var(--marca);">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                                </svg>
                            </button>
                        </div>

                        <!-- Lista de notas -->
                        <div class="space-y-2">
                            <div v-for="nota in lead.notas" :key="nota.id"
                                class="bg-gray-50 rounded-xl p-3">
                                <div class="flex items-start justify-between gap-2">
                                    <p class="text-sm text-gray-700 leading-relaxed flex-1">{{ nota.contenido }}</p>
                                    <button @click="eliminarNota(nota.id)"
                                        class="text-gray-300 hover:text-red-400 transition-colors shrink-0">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                        </svg>
                                    </button>
                                </div>
                                <p class="text-xs text-gray-400 mt-1">
                                    {{ nota.user?.name }} · {{ new Date(nota.created_at.replace(' ', 'T')).toLocaleDateString('es-CO') }}
                                </p>
                            </div>
                        </div>
                        <p v-if="!lead.notas.length" class="text-sm text-gray-400 text-center py-2">Sin notas</p>
                    </div>
                </div>

                <!-- ── E) Footer acciones ───────────────────────────────── -->
                <div class="px-5 py-4 border-t border-gray-100 shrink-0 flex gap-3">
                    <button @click="abrirCierre('perdido')"
                        class="flex-1 py-2.5 rounded-xl text-sm font-semibold text-red-600 border border-red-200 hover:bg-red-50 transition-colors">
                        Marcar perdido
                    </button>
                    <button @click="abrirCierre('ganado')"
                        class="flex-1 py-2.5 rounded-xl text-sm font-semibold text-white transition-opacity hover:opacity-90"
                        style="background-color: #10B981;">
                        Marcar ganado
                    </button>
                </div>
            </template>
        </div>

        <!-- Modal confirmación cierre -->
        <div v-if="modalCierre"
            class="fixed inset-0 z-[60] flex items-center justify-center p-4"
            style="background: rgba(0,0,0,0.55);">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100">
                    <h3 class="font-bold text-gray-900">
                        {{ tipoCierre === 'ganado' ? '¡Marcar como ganado!' : 'Marcar como perdido' }}
                    </h3>
                </div>
                <div class="px-5 py-4">
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Motivo / observaciones</label>
                    <textarea v-model="motivoCierre" rows="3"
                        class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 resize-none"
                        placeholder="Opcional…"></textarea>
                </div>
                <div class="px-5 py-4 border-t border-gray-100 flex gap-3">
                    <button @click="modalCierre = false"
                        class="flex-1 py-2.5 rounded-xl text-sm font-semibold text-gray-600 border border-gray-200">
                        Cancelar
                    </button>
                    <button @click="confirmarCierre" :disabled="guardandoCierre"
                        class="flex-1 py-2.5 rounded-xl text-sm font-semibold text-white disabled:opacity-60"
                        :style="{ background: tipoCierre === 'ganado' ? '#10B981' : '#EF4444' }">
                        Confirmar
                    </button>
                </div>
            </div>
        </div>
    </teleport>
</template>
