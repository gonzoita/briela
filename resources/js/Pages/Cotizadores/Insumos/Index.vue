<script setup>
import { ref, computed } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    agrupados: Array,
    filters:   Object,
    esAdmin:   Boolean,
})

const buscar     = ref(props.filters?.buscar ?? '')
const expandidos = ref(props.agrupados.map((_, i) => i === 0))

function toggleCategoria(idx) {
    expandidos.value[idx] = !expandidos.value[idx]
}

const formatCOP = (v) =>
    new Intl.NumberFormat('es-CO', { maximumFractionDigits: 0 }).format(v ?? 0)

// ─── Edición inline de precio ─────────────────────────────────────────────────
const editandoId = ref(null)
const editandoPrecio = ref('')

function iniciarEdicion(item) {
    if (!props.esAdmin) return
    editandoId.value = item.id
    editandoPrecio.value = String(item.precio_costo)
}

const formPrecio = useForm({ precio_costo: '' })

function guardarPrecio(item) {
    const valor = parseFloat(editandoPrecio.value.replace(/\./g, '').replace(',', '.'))
    if (isNaN(valor) || valor < 0) return
    formPrecio.precio_costo = valor
    formPrecio.put(`/cotizadores/insumos/${item.id}`, {
        preserveScroll: true,
        onSuccess: () => { editandoId.value = null },
    })
}

function cancelarEdicion() {
    editandoId.value = null
}

// ─── Modal agregar insumo ─────────────────────────────────────────────────────
const modalAgregar = ref(false)
const formNuevo = useForm({
    categoria:    '',
    nombre:       '',
    unidad:       'UN',
    precio_costo: '',
})

function agregarInsumo() {
    formNuevo.post('/cotizadores/insumos', {
        preserveScroll: true,
        onSuccess: () => { modalAgregar.value = false; formNuevo.reset() },
    })
}

function desactivar(item) {
    if (!confirm(`¿Desactivar el insumo "${item.nombre}"?`)) return
    router.delete(`/cotizadores/insumos/${item.id}`, { preserveScroll: true })
}

function aplicarBusqueda() {
    router.get('/cotizadores/insumos', { buscar: buscar.value }, { preserveState: true, replace: true })
}

const ic = (extra = '') =>
    `w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm focus:ring-4 focus:ring-[var(--marca-suave)] focus:outline-none ${extra}`
</script>

<template>
    <AppLayout title="Insumos y Precios">
        <div class="max-w-5xl mx-auto">

            <!-- Cabecera -->
            <div class="flex flex-col sm:flex-row sm:items-center gap-3 mb-5">
                <div class="flex-1">
                    <h1 class="text-xl font-semibold text-tinta-900">Insumos y Precios</h1>
                    <p class="text-sm text-tinta-400 mt-0.5">Tabla de precios base usada por el calculador de puertas</p>
                </div>
                <div class="flex gap-2">
                    <!-- Buscador -->
                    <div class="relative flex-1 sm:flex-none">
                        <input
                            v-model="buscar"
                            @keyup.enter="aplicarBusqueda"
                            type="text" placeholder="Buscar insumo..."
                            class="w-full sm:w-52 rounded-xl border border-tinta-200 pl-9 pr-3 py-2 text-sm focus:ring-2 focus:outline-none"
                            style="focus:border-color:var(--marca);"
                        />
                        <svg class="absolute left-3 top-2.5 w-4 h-4 text-tinta-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                    </div>
                    <button v-if="esAdmin" @click="modalAgregar = true"
                        class="shrink-0 px-4 py-2 rounded-xl text-sm font-medium text-white"
                        style="background:var(--marca)">
                        + Insumo
                    </button>
                </div>
            </div>

            <!-- Acordeones por categoría -->
            <div class="space-y-3">
                <div v-for="(grupo, idx) in agrupados" :key="grupo.categoria"
                     class="bg-superficie rounded-xl border border-linea overflow-hidden">

                    <!-- Header categoría -->
                    <button
                        @click="toggleCategoria(idx)"
                        class="w-full flex items-center justify-between px-4 py-3 hover:bg-tinta-50 transition-colors"
                    >
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-semibold text-tinta-900">{{ grupo.categoria }}</span>
                            <span class="text-xs px-2 py-0.5 rounded-full font-medium"
                                  style="background:var(--pastel-azul); color:var(--texto-azul);">
                                {{ grupo.items.length }} insumos
                            </span>
                        </div>
                        <svg
                            class="w-4 h-4 text-tinta-300 transition-transform"
                            :class="expandidos[idx] ? 'rotate-180' : ''"
                            fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>

                    <!-- Tabla de insumos -->
                    <div v-show="expandidos[idx]">
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr style="background:var(--superficie-2); border-top: 1px solid #E5E7EB;">
                                        <th class="text-left text-xs font-semibold text-tinta-400 uppercase px-4 py-2">Nombre</th>
                                        <th class="text-center text-xs font-semibold text-tinta-400 uppercase px-3 py-2 w-16">Unidad</th>
                                        <th class="text-right text-xs font-semibold text-tinta-400 uppercase px-4 py-2 w-36">Precio Costo</th>
                                        <th v-if="esAdmin" class="text-center text-xs font-semibold text-tinta-400 uppercase px-3 py-2 w-24">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-50">
                                    <tr v-for="item in grupo.items" :key="item.id"
                                        :class="['transition-colors', item.activo ? 'hover:bg-tinta-50' : 'opacity-50']">
                                        <td class="px-4 py-2.5 font-medium text-tinta-900">
                                            {{ item.nombre }}
                                            <span v-if="!item.activo" class="ml-2 text-xs text-red-400">(inactivo)</span>
                                        </td>
                                        <td class="px-3 py-2.5 text-center">
                                            <span class="text-xs font-mono text-tinta-400 bg-tinta-100 px-1.5 py-0.5 rounded">{{ item.unidad }}</span>
                                        </td>
                                        <td class="px-4 py-2.5 text-right">
                                            <!-- Celda editable -->
                                            <div v-if="editandoId === item.id" class="flex items-center gap-2 justify-end">
                                                <span class="text-tinta-300 text-xs">$</span>
                                                <input
                                                    v-model="editandoPrecio"
                                                    @keyup.enter="guardarPrecio(item)"
                                                    @keyup.escape="cancelarEdicion"
                                                    type="text" class="w-24 text-right rounded border border-blue-400 px-2 py-0.5 text-sm focus:outline-none"
                                                    autofocus
                                                />
                                                <button @click="guardarPrecio(item)"
                                                    class="w-6 h-6 rounded flex items-center justify-center text-green-600 hover:bg-green-50">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.6">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                                    </svg>
                                                </button>
                                                <button @click="cancelarEdicion"
                                                    class="w-6 h-6 rounded flex items-center justify-center text-tinta-300 hover:bg-tinta-100">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.6">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                                    </svg>
                                                </button>
                                            </div>
                                            <div v-else class="flex items-center gap-2 justify-end">
                                                <span class="font-semibold text-tinta-900">
                                                    ${{ formatCOP(item.precio_costo) }}
                                                </span>
                                                <button v-if="esAdmin" @click="iniciarEdicion(item)"
                                                    class="w-5 h-5 rounded flex items-center justify-center text-tinta-200 hover:text-blue-500"
                                                    title="Editar precio">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="1.6">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                                    </svg>
                                                </button>
                                            </div>
                                        </td>
                                        <td v-if="esAdmin" class="px-3 py-2.5 text-center">
                                            <button v-if="item.activo" @click="desactivar(item)"
                                                class="text-xs text-red-400 hover:text-red-600 hover:bg-red-50 px-2 py-1 rounded">
                                                Desactivar
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- Modal agregar insumo -->
        <Teleport to="body">
            <div v-if="modalAgregar" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-4" style="background:rgba(0,0,0,0.5);">
                <div class="bg-superficie rounded-2xl shadow-xl w-full max-w-md p-5">
                    <h3 class="text-base font-semibold text-tinta-900 mb-4">Agregar insumo</h3>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-xs font-medium text-tinta-700 mb-1">Categoría *</label>
                            <input v-model="formNuevo.categoria" type="text" :class="ic()" placeholder="Ej: Empaques y Sellos"/>
                            <p v-if="formNuevo.errors.categoria" class="text-red-500 text-xs mt-1">{{ formNuevo.errors.categoria }}</p>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-tinta-700 mb-1">Nombre *</label>
                            <input v-model="formNuevo.nombre" type="text" :class="ic()" placeholder="Nombre del insumo"/>
                            <p v-if="formNuevo.errors.nombre" class="text-red-500 text-xs mt-1">{{ formNuevo.errors.nombre }}</p>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-tinta-700 mb-1">Unidad *</label>
                                <select v-model="formNuevo.unidad" :class="ic()">
                                    <option v-for="u in ['UN','ML','M2','KG','GL']" :key="u" :value="u">{{ u }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-tinta-700 mb-1">Precio costo *</label>
                                <input v-model="formNuevo.precio_costo" type="number" :class="ic()" min="0"/>
                                <p v-if="formNuevo.errors.precio_costo" class="text-red-500 text-xs mt-1">{{ formNuevo.errors.precio_costo }}</p>
                            </div>
                        </div>
                    </div>
                    <div class="flex gap-3 mt-5">
                        <button @click="modalAgregar = false; formNuevo.reset()"
                            class="flex-1 py-2.5 rounded-xl border border-linea text-sm text-tinta-500">
                            Cancelar
                        </button>
                        <button @click="agregarInsumo" :disabled="formNuevo.processing"
                            class="flex-1 py-2.5 rounded-xl text-white text-sm font-medium disabled:opacity-60"
                            style="background:var(--marca)">
                            Agregar
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
