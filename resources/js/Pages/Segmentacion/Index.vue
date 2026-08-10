<script setup>
import { ref, computed } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { colorMarca } from '@/marca'

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
const mensajeEsError = ref(false)

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
    formNuevo.value[tipo] = { etiqueta: '', color: colorMarca() }
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
    editando.value = { id: op.id, etiqueta: op.etiqueta, color: op.color ?? colorMarca() }
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
        mensajeEsError.value = false
        mensaje.value = 'Eliminada.'
        setTimeout(() => mensaje.value = '', 2000)
        await cargar()
        return
    }

    // El servidor bloquea las opciones que definen el precio: hay que decir por qué.
    const cuerpo = await r.json().catch(() => ({}))
    mensajeEsError.value = true
    mensaje.value = cuerpo.message ?? 'No se pudo eliminar la opción.'
    setTimeout(() => mensaje.value = '', 6000)
}

function toggleAbierto(key) {
    abiertos.value[key] = !abiertos.value[key]
}
</script>

<template>
    <AppLayout title="Segmentación de Clientes">
        <div class="max-w-3xl mx-auto px-4 py-4">

            <div class="flex items-center gap-3 mb-4">
                <a href="/clientes" class="text-tinta-300 hover:text-tinta-500">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M15 19l-7-7 7-7"/>
                    </svg>
                </a>
                <h1 class="text-xl font-semibold text-tinta-900">Listas de segmentación</h1>
            </div>

            <!-- Toast -->
            <div v-if="mensaje" class="mb-3 px-4 py-2 rounded-xl text-sm font-medium text-white"
                :style="`background:${mensajeEsError ? '#B91C1C' : 'var(--marca)'};`">
                {{ mensaje }}
            </div>

            <div v-if="cargando" class="py-12 text-center text-tinta-300 text-sm">Cargando opciones...</div>

            <div v-else class="space-y-3">
                <div v-for="tipo in TIPOS" :key="tipo.key" class="bg-superficie rounded-xl border border-linea overflow-hidden">

                    <!-- Cabecera acordeón -->
                    <button type="button" @click="toggleAbierto(tipo.key)"
                        class="w-full flex items-center justify-between px-4 py-3 text-left hover:bg-tinta-50 transition-colors">
                        <span class="text-sm font-semibold text-tinta-900">{{ tipo.label }}</span>
                        <div class="flex items-center gap-3">
                            <span class="text-xs text-tinta-300">{{ (opciones[tipo.key] ?? []).length }} opciones</span>
                            <svg class="w-4 h-4 text-tinta-300 transition-transform"
                                :class="abiertos[tipo.key] ? 'rotate-180' : ''"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </div>
                    </button>

                    <!-- Contenido acordeón -->
                    <div v-if="abiertos[tipo.key]" class="border-t border-linea p-4">

                        <!-- Lista de opciones -->
                        <div class="space-y-1.5 mb-3">
                            <div v-for="op in (opciones[tipo.key] ?? [])" :key="op.id">
                                <!-- Modo edición inline -->
                                <div v-if="editando.id === op.id"
                                    class="flex items-center gap-2 p-2 rounded-lg border-2" style="border-color:var(--marca); background:var(--pastel-azul);">
                                    <input v-model="editando.etiqueta" type="text"
                                        class="flex-1 text-sm border border-tinta-200 rounded-lg px-2 py-1 focus:outline-none focus:border-[var(--marca)]"
                                        @keyup.enter="guardarEdicion" @keyup.escape="cancelarEdicion" />
                                    <div class="flex items-center gap-1.5 shrink-0">
                                        <div class="w-5 h-5 rounded-full border border-tinta-200 cursor-pointer"
                                            :style="`background:${editando.color ?? 'var(--marca)'};`"
                                            :title="editando.color"/>
                                        <input v-model="editando.color" type="color"
                                            class="w-7 h-7 rounded cursor-pointer border-0 p-0" />
                                    </div>
                                    <button type="button" @click="guardarEdicion"
                                        class="text-xs px-2 py-1 rounded-lg text-white" style="background:var(--marca);">✓</button>
                                    <button type="button" @click="cancelarEdicion"
                                        class="text-xs px-2 py-1 rounded-lg border border-tinta-200 text-tinta-400">✕</button>
                                </div>

                                <!-- Modo visualización -->
                                <div v-else class="flex items-center gap-2 px-2 py-1.5 rounded-lg hover:bg-tinta-50 group">
                                    <div class="w-3 h-3 rounded-full shrink-0"
                                        :style="`background:${op.color ?? '#9CA3AF'};`"/>
                                    <span class="text-sm text-tinta-700 flex-1">{{ op.etiqueta }}</span>
                                    <span v-if="op.atada_a_precios"
                                        class="text-[10px] px-1.5 py-0.5 rounded-full bg-amber-50 text-amber-700 border border-amber-200 shrink-0"
                                        title="El cotizador usa esta opción para decidir el precio y la comisión. No se puede eliminar.">
                                        define precio
                                    </span>
                                    <span class="text-xs text-tinta-300 font-mono hidden sm:block">{{ op.valor }}</span>
                                    <div class="flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity shrink-0">
                                        <button type="button" @click="iniciarEdicion(op)"
                                            class="text-xs px-2 py-1 rounded text-blue-600 hover:bg-blue-50">Editar</button>
                                        <button v-if="!op.atada_a_precios" type="button" @click="eliminar(op.id)"
                                            class="text-xs px-2 py-1 rounded text-red-500 hover:bg-red-50">Eliminar</button>
                                    </div>
                                </div>
                            </div>

                            <p v-if="!(opciones[tipo.key] ?? []).length" class="text-sm text-tinta-300 text-center py-3">
                                Sin opciones configuradas.
                            </p>
                        </div>

                        <!-- Formulario nueva opción -->
                        <div v-if="formNuevo[tipo.key]"
                            class="flex items-center gap-2 p-2 rounded-lg border-2 border-dashed" style="border-color:var(--marca); background:var(--pastel-azul);">
                            <input v-model="formNuevo[tipo.key].etiqueta" type="text" placeholder="Etiqueta..."
                                class="flex-1 text-sm border border-tinta-200 rounded-lg px-2 py-1 focus:outline-none focus:border-[var(--marca)]"
                                @keyup.enter="guardarNuevo(tipo.key)" @keyup.escape="cancelarNuevo(tipo.key)"
                                autofocus />
                            <input v-model="formNuevo[tipo.key].color" type="color"
                                class="w-7 h-7 rounded cursor-pointer border-0 p-0 shrink-0" />
                            <button type="button" @click="guardarNuevo(tipo.key)"
                                class="text-xs px-3 py-1.5 rounded-lg text-white shrink-0" style="background:var(--marca);">Agregar</button>
                            <button type="button" @click="cancelarNuevo(tipo.key)"
                                class="text-xs px-2 py-1 rounded-lg border border-tinta-200 text-tinta-400 shrink-0">✕</button>
                        </div>

                        <button v-else type="button" @click="iniciarNuevo(tipo.key)"
                            class="w-full mt-1 text-sm py-2 rounded-lg border border-dashed border-tinta-200 text-tinta-300 hover:border-blue-400 hover:text-blue-600 transition-colors">
                            + Agregar opción
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
