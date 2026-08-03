<script setup>
import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    numeros: Array,
    usuarios: Array,
})

const numeros = ref(props.numeros.map(n => ({ ...n })))

watch(() => props.numeros, (vals) => {
    numeros.value = vals.map(n => ({ ...n }))
}, { deep: true })

const formVacio = { nombre: '', numero_telefono: '', phone_number_id: '', rol: 'asesor', usuario_id: '', activo: true }
const form = ref({ ...formVacio })
const editando = ref(null)

const rolLabel = { central: 'Central', asesor: 'Asesor' }

function editar(n) {
    editando.value = n.id
    form.value = {
        nombre: n.nombre,
        numero_telefono: n.numero_telefono,
        phone_number_id: n.phone_number_id,
        rol: n.rol,
        usuario_id: n.usuario_id ?? '',
        activo: n.activo,
    }
}

function cancelar() {
    editando.value = null
    form.value = { ...formVacio }
}

function guardar() {
    const payload = { ...form.value, usuario_id: form.value.usuario_id || null }
    if (editando.value) {
        router.put(`/configuracion/whatsapp-numeros/${editando.value}`, payload, {
            preserveScroll: true,
            onSuccess: () => cancelar(),
        })
    } else {
        router.post('/configuracion/whatsapp-numeros', payload, {
            preserveScroll: true,
            onSuccess: () => cancelar(),
        })
    }
}

function eliminar(id) {
    if (!confirm('¿Eliminar este número? Si tiene conversaciones asociadas, solo se desactivará.')) return
    router.delete(`/configuracion/whatsapp-numeros/${id}`, { preserveScroll: true })
}
</script>

<template>
    <AppLayout title="Números de WhatsApp">
        <div class="max-w-3xl mx-auto">

            <div class="flex items-center gap-3 mb-5">
                <button
                    @click="router.visit('/configuracion')"
                    class="p-2 rounded-xl hover:bg-gray-100 transition-colors text-gray-500"
                    title="Volver"
                >
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                    </svg>
                </button>
                <h1 class="text-xl font-bold text-gray-900">Números de WhatsApp</h1>
            </div>

            <!-- Lista -->
            <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden mb-4">
                <div class="px-5 py-3 border-b border-gray-100">
                    <h2 class="text-sm font-semibold text-gray-700">Números registrados</h2>
                    <p class="text-xs text-gray-400 mt-0.5">Cada asesor conserva su app y su historial (modo Coexistencia).</p>
                </div>

                <div v-if="!numeros.length" class="py-10 text-center text-sm text-gray-400">
                    Sin números configurados.
                </div>

                <div class="divide-y divide-gray-50">
                    <div
                        v-for="n in numeros"
                        :key="n.id"
                        class="flex items-center gap-3 px-4 py-3"
                    >
                        <!-- Icono WhatsApp -->
                        <div class="w-9 h-9 rounded-xl flex items-center justify-center shrink-0"
                            :class="n.activo ? 'bg-blue-50' : 'bg-gray-100'"
                        >
                            <svg class="w-4 h-4" :class="n.activo ? 'text-[var(--marca)]' : 'text-gray-400'"
                                fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-6l-4 4v-4z" />
                            </svg>
                        </div>

                        <!-- Info -->
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-800 truncate">{{ n.nombre }}</p>
                            <p class="text-xs text-gray-400 truncate">
                                {{ n.numero_telefono }}
                                <span v-if="n.usuario"> · {{ n.usuario.name }}</span>
                            </p>
                        </div>

                        <!-- Badge rol -->
                        <span
                            class="text-xs px-2 py-0.5 rounded-full shrink-0"
                            :class="n.rol === 'central' ? 'bg-[var(--marca)] text-white font-semibold' : 'bg-gray-100 text-gray-600'"
                        >
                            {{ rolLabel[n.rol] ?? n.rol }}
                        </span>

                        <!-- Badge activo -->
                        <span
                            class="text-xs px-2 py-0.5 rounded-full shrink-0"
                            :class="n.activo ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'"
                        >
                            {{ n.activo ? 'Activo' : 'Inactivo' }}
                        </span>

                        <button @click="editar(n)" class="text-xs text-blue-600 hover:underline shrink-0">Editar</button>
                        <button @click="eliminar(n.id)" class="text-xs text-red-500 hover:underline shrink-0">Eliminar</button>
                    </div>
                </div>
            </div>

            <!-- Formulario crear / editar -->
            <div class="bg-white rounded-2xl border border-gray-200 p-5">
                <h3 class="text-sm font-semibold text-gray-700 mb-4">
                    {{ editando ? 'Editar número' : 'Nuevo número' }}
                </h3>

                <div class="space-y-3">
                    <!-- Nombre -->
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1.5">
                            Nombre *
                        </label>
                        <input
                            v-model="form.nombre"
                            type="text"
                            placeholder="Ej: Renier Dominguez"
                            class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                        />
                    </div>

                    <!-- Número de teléfono -->
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1.5">
                            Número de teléfono *
                        </label>
                        <input
                            v-model="form.numero_telefono"
                            type="text"
                            placeholder="+573001234567"
                            class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                        />
                    </div>

                    <!-- Phone Number ID -->
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1.5">
                            Phone Number ID (Meta) *
                        </label>
                        <input
                            v-model="form.phone_number_id"
                            type="text"
                            placeholder="ID asignado por Meta"
                            class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                        />
                    </div>

                    <!-- Rol -->
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1.5">
                            Rol *
                        </label>
                        <select
                            v-model="form.rol"
                            class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white"
                        >
                            <option value="asesor">Asesor</option>
                            <option value="central">Central</option>
                        </select>
                    </div>

                    <!-- Usuario asociado -->
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1.5">
                            Usuario asociado
                        </label>
                        <select
                            v-model="form.usuario_id"
                            class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white"
                        >
                            <option value="">Sin asignar</option>
                            <option v-for="u in usuarios" :key="u.id" :value="u.id">{{ u.name }}</option>
                        </select>
                    </div>

                    <!-- Activo -->
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input v-model="form.activo" type="checkbox" class="rounded" />
                        <span class="text-sm text-gray-700">Activo</span>
                    </label>

                    <!-- Botones -->
                    <div class="flex gap-3 pt-1">
                        <button
                            v-if="editando"
                            @click="cancelar"
                            class="flex-1 py-2.5 rounded-xl border border-gray-200 text-sm text-gray-600"
                        >
                            Cancelar
                        </button>
                        <button
                            @click="guardar"
                            class="flex-1 py-2.5 rounded-xl text-white text-sm font-semibold"
                            style="background:var(--marca);"
                        >
                            {{ editando ? 'Actualizar' : 'Crear número' }}
                        </button>
                    </div>
                </div>
            </div>

        </div>
    </AppLayout>
</template>
