<script setup>
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import OrdenarLista from '@/Components/OrdenarLista.vue'
import { useOrden } from '@/composables/useOrden'
import BuscadorModulo from '@/Components/BuscadorModulo.vue'

const props = defineProps({
    proveedores: Object,
    filters:     Object,
    // El orden vigente, que decide el servidor: { campo, dir }.
    orden: { type: Object, default: () => ({}) },
})

// Ordenar mantiene los filtros: reordenar no es empezar de cero.
const { ordenarPor } = useOrden('/compras/proveedores', props.orden, props.filters)

const camposOrden = [
    { campo: 'nombre', etiqueta: 'Nombre' },
    { campo: 'ciudad', etiqueta: 'Ciudad' },
    { campo: 'created_at', etiqueta: 'Más reciente', texto: false },
]

const buscar = ref(props.filters?.buscar ?? '')
const tipo   = ref(props.filters?.tipo   ?? '')
const activo = ref(props.filters?.activo ?? '')

const modalAbierto  = ref(false)
const editando      = ref(null)
const guardando     = ref(false)

const form = ref({
    nombre: '', nit: '', contacto: '', telefono: '',
    email: '', ciudad: '', direccion: '',
    tipo: 'mixto', activo: true, notas: '',
})

function aplicarFiltros() {
    router.get('/compras/proveedores', {
        buscar: buscar.value || undefined,
        tipo:   tipo.value   || undefined,
        activo: activo.value || undefined,
    }, { preserveState: true, replace: true })
}

function abrirCrear() {
    editando.value = null
    form.value = { nombre: '', nit: '', contacto: '', telefono: '', email: '', ciudad: '', direccion: '', tipo: 'mixto', activo: true, notas: '' }
    modalAbierto.value = true
}

function abrirEditar(p) {
    editando.value = p
    form.value = { ...p }
    modalAbierto.value = true
}

function cerrarModal() {
    modalAbierto.value = false
    editando.value = null
}

function guardar() {
    guardando.value = true
    if (editando.value) {
        router.put(`/compras/proveedores/${editando.value.id}`, form.value, {
            onSuccess: () => { cerrarModal(); guardando.value = false },
            onError:   () => { guardando.value = false },
        })
    } else {
        router.post('/compras/proveedores', form.value, {
            onSuccess: () => { cerrarModal(); guardando.value = false },
            onError:   () => { guardando.value = false },
        })
    }
}

function desactivar(p) {
    if (!confirm(`¿Desactivar a ${p.nombre}?`)) return
    router.delete(`/compras/proveedores/${p.id}`)
}

function tipoLabel(t) {
    return { materia_prima: 'Mat. Prima', insumos: 'Insumos', mixto: 'Mixto' }[t] ?? t
}

function tipoColor(t) {
    return {
        materia_prima: 'bg-pastel-naranja-2 text-aviso-naranja',
        insumos:       'bg-pastel-azul-2 text-aviso-azul',
        mixto:         'bg-pastel-violeta-2 text-aviso-violeta',
    }[t] ?? 'bg-tinta-100 text-tinta-700'
}
</script>

<template>
    <AppLayout title="Proveedores">
        <div class="max-w-5xl mx-auto px-4 py-4">

            <!-- Cabecera -->
            <div class="flex items-center justify-between mb-4">
                <h1 class="text-xl font-semibold text-tinta-900">Proveedores</h1>
                <button @click="abrirCrear"
                    class="inline-flex items-center gap-1 px-3 py-2 rounded-lg text-sm font-medium text-white"
                    style="background:var(--marca)">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M12 4v16m8-8H4"/>
                    </svg>
                    Nuevo
                </button>
            </div>

            <!-- Ordenar. Vale para las listas que son tabla y para las que son tarjetas, y
                 en celular es el único camino: ahí no hay encabezados donde hacer clic. -->
            <div class="mb-3">
                <OrdenarLista :campos="camposOrden" :orden="orden" @ordenar="ordenarPor" />
            </div>

            <!-- Filtros -->
            <div class="bg-superficie rounded-xl border border-linea p-3 mb-4 space-y-2">
                <div class="flex flex-col sm:flex-row gap-2">
                    <BuscadorModulo
                        v-model="buscar"
                        tipos="proveedor"
                        placeholder="Buscar por nombre, NIT, email..."
                        @filtrar="aplicarFiltros"
                        class="flex-1"
                    />
                    <select v-model="tipo" class="rounded-lg border border-tinta-200 px-3 py-2 text-sm" @change="aplicarFiltros">
                        <option value="">Todos los tipos</option>
                        <option value="materia_prima">Mat. Prima</option>
                        <option value="insumos">Insumos</option>
                        <option value="mixto">Mixto</option>
                    </select>
                    <select v-model="activo" class="rounded-lg border border-tinta-200 px-3 py-2 text-sm" @change="aplicarFiltros">
                        <option value="">Todos</option>
                        <option value="true">Activos</option>
                        <option value="false">Inactivos</option>
                    </select>
                    <button @click="aplicarFiltros" class="px-4 py-2 rounded-lg bg-tinta-100 text-tinta-700 text-sm font-medium">
                        Buscar
                    </button>
                </div>
            </div>

            <!-- Lista mobile -->
            <div class="space-y-2 sm:hidden">
                <div v-for="p in proveedores.data" :key="p.id"
                    class="bg-superficie rounded-xl border border-linea p-4">
                    <div class="flex items-start justify-between">
                        <div>
                            <p class="font-semibold text-tinta-900">{{ p.nombre }}</p>
                            <p class="text-sm text-tinta-400">{{ p.nit ?? '—' }}</p>
                            <p class="text-sm text-tinta-400">{{ p.telefono ?? '—' }} · {{ p.email ?? '—' }}</p>
                        </div>
                        <div class="flex flex-col items-end gap-1">
                            <span :class="['text-xs px-2 py-0.5 rounded-full font-medium', tipoColor(p.tipo)]">{{ tipoLabel(p.tipo) }}</span>
                            <span v-if="!p.activo" class="text-xs px-2 py-0.5 rounded-full bg-pastel-rojo-2 text-aviso-rojo">Inactivo</span>
                        </div>
                    </div>
                    <div class="flex gap-2 mt-3">
                        <button @click="abrirEditar(p)" class="text-sm text-aviso-azul font-medium">Editar</button>
                        <button v-if="p.activo" @click="desactivar(p)" class="text-sm text-aviso-rojo font-medium">Desactivar</button>
                    </div>
                </div>
                <div v-if="!proveedores.data?.length" class="text-center py-8 text-tinta-300">
                    No hay proveedores
                </div>
            </div>

            <!-- Tabla desktop -->
            <div class="hidden sm:block bg-superficie rounded-xl border border-linea overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-tinta-50 border-b border-linea">
                        <tr>
                            <th class="text-left px-4 py-3 font-semibold text-tinta-500">Nombre</th>
                            <th class="text-left px-4 py-3 font-semibold text-tinta-500">NIT</th>
                            <th class="text-left px-4 py-3 font-semibold text-tinta-500">Tipo</th>
                            <th class="text-left px-4 py-3 font-semibold text-tinta-500">Teléfono</th>
                            <th class="text-left px-4 py-3 font-semibold text-tinta-500">Email</th>
                            <th class="text-left px-4 py-3 font-semibold text-tinta-500">Estado</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-linea">
                        <tr v-for="p in proveedores.data" :key="p.id" class="hover:bg-tinta-50 transition-colors">
                            <td class="px-4 py-3 font-medium text-tinta-900">{{ p.nombre }}</td>
                            <td class="px-4 py-3 text-tinta-400">{{ p.nit ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <span :class="['text-xs px-2 py-0.5 rounded-full font-medium', tipoColor(p.tipo)]">{{ tipoLabel(p.tipo) }}</span>
                            </td>
                            <td class="px-4 py-3 text-tinta-400">{{ p.telefono ?? '—' }}</td>
                            <td class="px-4 py-3 text-tinta-400">{{ p.email ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <span v-if="p.activo" class="text-xs px-2 py-0.5 rounded-full bg-pastel-verde-2 text-aviso-verde">Activo</span>
                                <span v-else class="text-xs px-2 py-0.5 rounded-full bg-pastel-rojo-2 text-aviso-rojo">Inactivo</span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3 justify-end">
                                    <button @click="abrirEditar(p)" class="text-aviso-azul hover:text-aviso-azul text-sm font-medium">Editar</button>
                                    <button v-if="p.activo" @click="desactivar(p)" class="text-aviso-rojo hover:text-aviso-rojo text-sm font-medium">Desactivar</button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!proveedores.data?.length">
                            <td colspan="7" class="px-4 py-8 text-center text-tinta-300">No hay proveedores</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Paginación -->
            <div v-if="proveedores.last_page > 1" class="flex justify-center gap-2 mt-4">
                <template v-for="link in proveedores.links" :key="link.label">
                    <button v-if="link.url"
                        @click="router.visit(link.url, { preserveState: true })"
                        :class="['px-3 py-1.5 rounded-lg text-sm', link.active ? 'text-white font-semibold' : 'bg-superficie border border-linea text-tinta-700']"
                        :style="link.active ? 'background:var(--marca)' : ''"
                        v-html="link.label" />
                    <span v-else class="px-3 py-1.5 rounded-lg text-sm text-tinta-200 border border-linea" v-html="link.label" />
                </template>
            </div>
        </div>

        <!-- Modal crear/editar -->
        <Teleport to="body">
            <div v-if="modalAbierto" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
                <div class="absolute inset-0 bg-black/40" @click="cerrarModal" />
                <div class="relative bg-superficie w-full sm:max-w-lg rounded-t-2xl sm:rounded-2xl p-5 max-h-[90vh] overflow-y-auto">
                    <h2 class="text-lg font-semibold text-tinta-900 mb-4">
                        {{ editando ? 'Editar proveedor' : 'Nuevo proveedor' }}
                    </h2>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-sm font-medium text-tinta-700 mb-1">Nombre *</label>
                            <input v-model="form.nombre" type="text" class="w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm focus:ring-4 focus:ring-[var(--marca-suave)] focus:outline-none" />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-medium text-tinta-700 mb-1">NIT</label>
                                <input v-model="form.nit" type="text" class="w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm focus:ring-4 focus:ring-[var(--marca-suave)] focus:outline-none" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-tinta-700 mb-1">Tipo *</label>
                                <select v-model="form.tipo" class="w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm focus:ring-4 focus:ring-[var(--marca-suave)] focus:outline-none">
                                    <option value="materia_prima">Materia Prima</option>
                                    <option value="insumos">Insumos</option>
                                    <option value="mixto">Mixto</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-tinta-700 mb-1">Contacto</label>
                            <input v-model="form.contacto" type="text" class="w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm focus:ring-4 focus:ring-[var(--marca-suave)] focus:outline-none" />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-medium text-tinta-700 mb-1">Teléfono</label>
                                <input v-model="form.telefono" type="text" class="w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm focus:ring-4 focus:ring-[var(--marca-suave)] focus:outline-none" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-tinta-700 mb-1">Email</label>
                                <input v-model="form.email" type="email" class="w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm focus:ring-4 focus:ring-[var(--marca-suave)] focus:outline-none" />
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-medium text-tinta-700 mb-1">Ciudad</label>
                                <input v-model="form.ciudad" type="text" class="w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm focus:ring-4 focus:ring-[var(--marca-suave)] focus:outline-none" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-tinta-700 mb-1">Dirección</label>
                                <input v-model="form.direccion" type="text" class="w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm focus:ring-4 focus:ring-[var(--marca-suave)] focus:outline-none" />
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-tinta-700 mb-1">Notas</label>
                            <textarea v-model="form.notas" rows="2" class="w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm focus:ring-4 focus:ring-[var(--marca-suave)] focus:outline-none" />
                        </div>
                        <div v-if="editando" class="flex items-center gap-2">
                            <input id="activo" v-model="form.activo" type="checkbox" class="rounded" />
                            <label for="activo" class="text-sm text-tinta-700">Activo</label>
                        </div>
                    </div>
                    <div class="flex gap-3 mt-5">
                        <button @click="cerrarModal" class="flex-1 py-2.5 rounded-xl border border-tinta-200 text-sm font-medium text-tinta-700">
                            Cancelar
                        </button>
                        <button @click="guardar" :disabled="guardando || !form.nombre"
                            class="flex-1 py-2.5 rounded-xl text-sm font-semibold text-white disabled:opacity-50"
                            style="background:var(--marca)">
                            {{ guardando ? 'Guardando...' : (editando ? 'Actualizar' : 'Crear') }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
