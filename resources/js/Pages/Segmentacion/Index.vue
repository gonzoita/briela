<script setup>
import { ref, computed } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'

const TIPOS = [
    { key: 'tipo_contacto',       label: 'Tipos de contacto' },
    { key: 'industria',           label: 'Industrias' },
    { key: 'proceso_seguimiento', label: 'Proceso de seguimiento' },
    { key: 'fuente_contacto',     label: 'Fuentes de contacto' },
]

const opciones = ref({})
const cargando = ref(true)
const abiertos = ref({})
const mensaje  = ref('')

const csrf = () => {
    const c = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))
    return c ? decodeURIComponent(c.split('=')[1]) : ''
}
const headers = () => ({
    'Content-Type': 'application/json',
    Accept: 'application/json',
    'X-XSRF-TOKEN': csrf(),
})

async function cargar() {
    cargando.value = true
    try {
        const r = await fetch('/api/segmentacion-opciones', { headers: headers() })
        opciones.value = await r.json()
    } finally { cargando.value = false }
}
cargar()

// ─── Formulario inline ────────────────────────────────────────────────────────
const formNuevo = ref({})
const editando  = ref({}) // { id, etiqueta, color }

function iniciarNuevo(tipo) {
    formNuevo.value[tipo] = { etiqueta: '', color: '#0A4283' }
}

function cancelarNuevo(tipo) {
    delete formNuevo.value[tipo]
}

async function guardarNuevo(tipo) {
    const f = formNuevo.value[tipo]
    if (!f?.etiqueta?.trim()) return
    const r = await fetch('/api/segmentacion-opciones', {
        method: 'POST',
        headers: headers(),
        body: JSON.stringify({ tipo, etiqueta: f.etiqueta, color: f.color }),
    })
    if (r.ok) {
        mensaje.value = 'Opción agregada.'
        setTimeout(() => mensaje.value = '', 2000)
        cancelarNuevo(tipo)
        await cargar()
    }
}

function iniciarEdicion(op) {
    editando.value = { id: op.id, etiqueta: op.etiqueta, color: op.color ?? '#0A4283' }
}

function cancelarEdicion() {
    editando.value = {}
}

async function guardarEdicion() {
    const { id, etiqueta, color } = editando.value
    if (!etiqueta?.trim()) return
    const r = await fetch(`/api/segmentacion-opciones/${id}`, {
        method: 'PUT',
        headers: headers(),
        body: JSON.stringify({ etiqueta, color }),
    })
    if (r.ok) {
        mensaje.value = 'Guardado.'
        setTimeout(() => mensaje.value = '', 2000)
        cancelarEdicion()
        await cargar()
    }
}

async function eliminar(id) {
    if (!confirm('¿Eliminar esta opción?')) return
    const r = await fetch(`/api/segmentacion-opciones/${id}`, {
        method: 'DELETE',
        headers: headers(),
    })
    if (r.ok) {
        mensaje.value = 'Eliminada.'
        setTimeout(() => mensaje.value = '', 2000)
        await cargar()
    }
}

function toggleAbierto(key) {
    abiertos.value[key] = !abiertos.value[key]
}
</script>

<template>
    <AppLayout title="Segmentación de Clientes">
        <div class="max-w-3xl mx-auto px-4 py-4">

            <div class="flex items-center gap-3 mb-4">
                <a href="/clientes" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                    </svg>
                </a>
                <h1 class="text-xl font-bold text-gray-900">Listas de segmentación</h1>
            </div>

            <!-- Toast -->
            <div v-if="mensaje" class="mb-3 px-4 py-2 rounded-xl text-sm font-medium text-white"
                style="background:var(--marca);">
                {{ mensaje }}
            </div>

            <div v-if="cargando" class="py-12 text-center text-gray-400 text-sm">Cargando opciones...</div>

            <div v-else class="space-y-3">
                <div v-for="tipo in TIPOS" :key="tipo.key" class="bg-white rounded-xl border border-gray-200 overflow-hidden">

                    <!-- Cabecera acordeón -->
                    <button type="button" @click="toggleAbierto(tipo.key)"
                        class="w-full flex items-center justify-between px-4 py-3 text-left hover:bg-gray-50 transition-colors">
                        <span class="text-sm font-semibold text-gray-800">{{ tipo.label }}</span>
                        <div class="flex items-center gap-3">
                            <span class="text-xs text-gray-400">{{ (opciones[tipo.key] ?? []).length }} opciones</span>
                            <svg class="w-4 h-4 text-gray-400 transition-transform"
                                :class="abiertos[tipo.key] ? 'rotate-180' : ''"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </div>
                    </button>

                    <!-- Contenido acordeón -->
                    <div v-if="abiertos[tipo.key]" class="border-t border-gray-100 p-4">

                        <!-- Lista de opciones -->
                        <div class="space-y-1.5 mb-3">
                            <div v-for="op in (opciones[tipo.key] ?? [])" :key="op.id">
                                <!-- Modo edición inline -->
                                <div v-if="editando.id === op.id"
                                    class="flex items-center gap-2 p-2 rounded-lg border-2" style="border-color:var(--marca); background:#F0F7FF;">
                                    <input v-model="editando.etiqueta" type="text"
                                        class="flex-1 text-sm border border-gray-300 rounded-lg px-2 py-1 focus:outline-none focus:border-blue-400"
                                        @keyup.enter="guardarEdicion" @keyup.escape="cancelarEdicion" />
                                    <div class="flex items-center gap-1.5 shrink-0">
                                        <div class="w-5 h-5 rounded-full border border-gray-300 cursor-pointer"
                                            :style="`background:${editando.color ?? 'var(--marca)'};`"
                                            :title="editando.color"/>
                                        <input v-model="editando.color" type="color"
                                            class="w-7 h-7 rounded cursor-pointer border-0 p-0" />
                                    </div>
                                    <button type="button" @click="guardarEdicion"
                                        class="text-xs px-2 py-1 rounded-lg text-white" style="background:var(--marca);">✓</button>
                                    <button type="button" @click="cancelarEdicion"
                                        class="text-xs px-2 py-1 rounded-lg border border-gray-300 text-gray-500">✕</button>
                                </div>

                                <!-- Modo visualización -->
                                <div v-else class="flex items-center gap-2 px-2 py-1.5 rounded-lg hover:bg-gray-50 group">
                                    <div class="w-3 h-3 rounded-full shrink-0"
                                        :style="`background:${op.color ?? '#9CA3AF'};`"/>
                                    <span class="text-sm text-gray-700 flex-1">{{ op.etiqueta }}</span>
                                    <span class="text-xs text-gray-400 font-mono hidden sm:block">{{ op.valor }}</span>
                                    <div class="flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity shrink-0">
                                        <button type="button" @click="iniciarEdicion(op)"
                                            class="text-xs px-2 py-1 rounded text-blue-600 hover:bg-blue-50">Editar</button>
                                        <button type="button" @click="eliminar(op.id)"
                                            class="text-xs px-2 py-1 rounded text-red-500 hover:bg-red-50">Eliminar</button>
                                    </div>
                                </div>
                            </div>

                            <p v-if="!(opciones[tipo.key] ?? []).length" class="text-sm text-gray-400 text-center py-3">
                                Sin opciones configuradas.
                            </p>
                        </div>

                        <!-- Formulario nueva opción -->
                        <div v-if="formNuevo[tipo.key]"
                            class="flex items-center gap-2 p-2 rounded-lg border-2 border-dashed" style="border-color:var(--marca); background:#F0F7FF;">
                            <input v-model="formNuevo[tipo.key].etiqueta" type="text" placeholder="Etiqueta..."
                                class="flex-1 text-sm border border-gray-300 rounded-lg px-2 py-1 focus:outline-none focus:border-blue-400"
                                @keyup.enter="guardarNuevo(tipo.key)" @keyup.escape="cancelarNuevo(tipo.key)"
                                autofocus />
                            <input v-model="formNuevo[tipo.key].color" type="color"
                                class="w-7 h-7 rounded cursor-pointer border-0 p-0 shrink-0" />
                            <button type="button" @click="guardarNuevo(tipo.key)"
                                class="text-xs px-3 py-1.5 rounded-lg text-white shrink-0" style="background:var(--marca);">Agregar</button>
                            <button type="button" @click="cancelarNuevo(tipo.key)"
                                class="text-xs px-2 py-1 rounded-lg border border-gray-300 text-gray-500 shrink-0">✕</button>
                        </div>

                        <button v-else type="button" @click="iniciarNuevo(tipo.key)"
                            class="w-full mt-1 text-sm py-2 rounded-lg border border-dashed border-gray-300 text-gray-400 hover:border-blue-400 hover:text-blue-600 transition-colors">
                            + Agregar opción
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
