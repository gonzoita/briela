<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import { router, useForm } from '@inertiajs/vue3'
import { ref } from 'vue'
import { useClipboard } from '@/composables/useClipboard'

const props = defineProps({
    invitaciones: { type: Array, default: () => [] },
})

// ─── Estado de la invitación ──────────────────────────────────────────────────
function estadoInvitacion(inv) {
    if (inv.usado_at) return { label: 'Usada', bg: '#F3F4F6', color: '#6B7280' }
    if (new Date(inv.expira_at) < new Date()) return { label: 'Expirada', bg: '#FEE2E2', color: '#B91C1C' }
    return { label: 'Vigente', bg: '#D1FAE5', color: '#065F46' }
}

const tipoLabel = (t) => ({ contratista: 'Contratista', cliente: 'Cliente' }[t] ?? t)

function urlInvitacion(inv) {
    return `${window.location.origin}/capacitacion/invitacion/${inv.token}`
}

const { copyText } = useClipboard()
const copiadoId = ref(null)
const copiaFallidaId = ref(null)

async function copiarLink(inv) {
    const ok = await copyText(urlInvitacion(inv))
    if (ok) {
        copiaFallidaId.value = null
        copiadoId.value = inv.id
        setTimeout(() => { copiadoId.value = null }, 2000)
    } else {
        copiaFallidaId.value = inv.id
    }
}

function cancelar(inv) {
    if (!confirm(`¿Cancelar la invitación para ${inv.email}?`)) return
    router.delete(`/capacitacion/invitaciones/${inv.id}`, { preserveScroll: true })
}

// ─── Modal nueva invitación ───────────────────────────────────────────────────
const modalAbierto = ref(false)
const form = useForm({
    email: '',
    tipo: 'contratista',
    cliente_id: null,
    nombre_sugerido: '',
})

function abrirModal() {
    form.reset()
    modalAbierto.value = true
}

function cerrarModal() {
    modalAbierto.value = false
    clienteQuery.value = ''
    clienteResultados.value = []
    clienteSeleccionado.value = null
}

function guardar() {
    form.post('/capacitacion/invitaciones', {
        preserveScroll: true,
        onSuccess: () => cerrarModal(),
    })
}

// ─── Buscador de cliente ──────────────────────────────────────────────────────
const clienteQuery = ref('')
const clienteResultados = ref([])
const clienteSeleccionado = ref(null)
let timerCliente = null

function buscarCliente(q) {
    clienteQuery.value = q
    clearTimeout(timerCliente)
    if (q.length < 2) { clienteResultados.value = []; return }
    timerCliente = setTimeout(async () => {
        const r = await fetch(`/api/cotizaciones/clientes?q=${encodeURIComponent(q)}`)
        clienteResultados.value = await r.json()
    }, 300)
}

function seleccionarCliente(c) {
    clienteSeleccionado.value = c
    clienteQuery.value = c.nombre + (c.apellido ? ' ' + c.apellido : '')
    form.cliente_id = c.id
    clienteResultados.value = []
}

function limpiarCliente() {
    clienteSeleccionado.value = null
    form.cliente_id = null
    clienteQuery.value = ''
}
</script>

<template>
    <AppLayout title="Invitaciones">
        <div class="max-w-4xl mx-auto">

            <!-- Cabecera -->
            <div class="flex items-center justify-between mb-5 flex-wrap gap-3">
                <div>
                    <h1 class="text-xl font-semibold text-tinta-900">Invitaciones de capacitación</h1>
                    <p class="text-sm text-tinta-400 mt-0.5">Invita clientes y contratistas al portal de cursos</p>
                </div>
                <button @click="abrirModal"
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold text-white"
                    style="background:var(--marca);">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                    </svg>
                    Nueva invitación
                </button>
            </div>

            <!-- Vacío -->
            <div v-if="invitaciones.length === 0" class="bg-superficie rounded-2xl shadow-sm py-16 text-center text-tinta-300">
                <svg class="w-12 h-12 mx-auto mb-3 text-tinta-200" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                </svg>
                <p class="text-sm">Sin invitaciones. <a href="#" @click.prevent="abrirModal" class="text-blue-600 hover:underline">Crea la primera</a>.</p>
            </div>

            <!-- Lista -->
            <div v-else class="bg-superficie rounded-2xl shadow-sm overflow-hidden divide-y divide-linea">
                <div v-for="inv in invitaciones" :key="inv.id" class="p-4 space-y-2.5">
                    <div class="flex items-center gap-3 flex-wrap">
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold text-tinta-900 truncate">{{ inv.email }}</p>
                            <p class="text-xs text-tinta-300 mt-0.5">
                                {{ tipoLabel(inv.tipo) }}<span v-if="inv.cliente"> · {{ inv.cliente.nombre }} {{ inv.cliente.apellido }}</span>
                                <span v-if="inv.nombre_sugerido"> · {{ inv.nombre_sugerido }}</span>
                            </p>
                        </div>
                        <span class="inline-block px-2 py-0.5 rounded-full text-xs font-semibold shrink-0"
                            :style="`background:${estadoInvitacion(inv).bg};color:${estadoInvitacion(inv).color};`">
                            {{ estadoInvitacion(inv).label }}
                        </span>
                        <div class="flex items-center gap-1.5 shrink-0">
                            <button v-if="!inv.usado_at" @click="copiarLink(inv)"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold border border-linea hover:bg-tinta-50 transition-colors">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                                </svg>
                                {{ copiadoId === inv.id ? '¡Copiado!' : 'Copiar link' }}
                            </button>
                            <button v-if="!inv.usado_at" @click="cancelar(inv)"
                                class="w-8 h-8 rounded-lg flex items-center justify-center text-tinta-300 hover:text-red-600 hover:bg-red-50 transition-colors">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <!-- Link completo, siempre visible y seleccionable -->
                    <input :value="urlInvitacion(inv)" readonly type="text"
                        @focus="$event.target.select()" @click="$event.target.select()"
                        class="w-full border border-linea rounded-lg px-2.5 py-1.5 text-xs text-tinta-400 bg-tinta-50 focus:outline-none focus:border-[var(--marca)] truncate" />
                    <p v-if="copiaFallidaId === inv.id" class="text-xs" style="color:#B91C1C;">
                        No se pudo copiar automáticamente. Selecciona el texto de arriba y usa Ctrl+C.
                    </p>
                </div>
            </div>

        </div>

        <!-- Modal nueva invitación -->
        <div v-if="modalAbierto" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
            <div class="absolute inset-0 bg-black/40" @click="cerrarModal"></div>
            <div class="relative bg-superficie w-full sm:max-w-md sm:rounded-2xl rounded-t-2xl shadow-xl max-h-[90vh] overflow-y-auto">
                <div class="px-5 py-4 border-b border-linea flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-tinta-900">Nueva invitación</h3>
                    <button @click="cerrarModal" class="w-7 h-7 rounded-lg flex items-center justify-center text-tinta-300 hover:bg-tinta-100">✕</button>
                </div>
                <div class="p-5 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-tinta-700 mb-1">Correo electrónico</label>
                        <input v-model="form.email" type="email" placeholder="correo@ejemplo.com"
                            class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]" />
                        <p v-if="form.errors.email" class="text-xs text-red-500 mt-1">{{ form.errors.email }}</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-tinta-700 mb-1">Tipo</label>
                        <select v-model="form.tipo" class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]">
                            <option value="contratista">Contratista</option>
                            <option value="cliente">Cliente</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-tinta-700 mb-1">Cliente asociado (opcional)</label>
                        <div class="relative">
                            <input :value="clienteQuery" @input="buscarCliente($event.target.value)"
                                type="text" placeholder="Buscar por nombre o identificación..."
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:ring-2 focus:outline-none"/>
                            <div v-if="clienteResultados.length > 0"
                                class="absolute top-full left-0 right-0 mt-1 bg-superficie rounded-xl shadow-xl border border-linea z-20 overflow-hidden">
                                <button v-for="c in clienteResultados" :key="c.id" type="button"
                                    @click="seleccionarCliente(c)"
                                    class="w-full flex items-center gap-3 px-4 py-2.5 hover:bg-tinta-50 text-left transition-colors border-b border-gray-50 last:border-0">
                                    <div>
                                        <p class="text-sm font-medium text-tinta-900">{{ c.nombre }}{{ c.apellido ? ' ' + c.apellido : '' }}</p>
                                        <p class="text-xs text-tinta-300">{{ c.tipo_identificacion }}: {{ c.numero_identificacion }} · {{ c.ciudad }}</p>
                                    </div>
                                </button>
                            </div>
                        </div>
                        <div v-if="clienteSeleccionado"
                            class="mt-2 flex items-center gap-3 px-3 py-2 rounded-xl"
                            style="background:var(--pastel-azul); border:1px solid #BFDBFE;">
                            <p class="text-sm font-medium flex-1" style="color:#1D4ED8;">
                                {{ clienteSeleccionado.nombre }}{{ clienteSeleccionado.apellido ? ' ' + clienteSeleccionado.apellido : '' }}
                            </p>
                            <button type="button" @click="limpiarCliente" class="text-blue-300 hover:text-blue-500">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-tinta-700 mb-1">Nombre sugerido (opcional)</label>
                        <input v-model="form.nombre_sugerido" type="text" maxlength="150"
                            class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]" />
                    </div>
                </div>
                <div class="px-5 py-4 border-t border-linea flex gap-2">
                    <button @click="cerrarModal" class="flex-1 py-2.5 rounded-xl text-sm font-semibold text-tinta-500 border border-linea hover:bg-tinta-50">Cancelar</button>
                    <button @click="guardar" :disabled="form.processing"
                        class="flex-1 py-2.5 rounded-xl text-sm font-semibold text-white disabled:opacity-50" style="background:var(--marca);">
                        {{ form.processing ? 'Creando...' : 'Crear invitación' }}
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
