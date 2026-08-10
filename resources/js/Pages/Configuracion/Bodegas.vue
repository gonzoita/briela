<script setup>
import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    bodegas: Array,
    sedes:   { type: Array, default: () => [] },
})

const bodegas = ref(props.bodegas.map(b => ({ ...b })))

watch(() => props.bodegas, (vals) => {
    bodegas.value = vals.map(b => ({ ...b }))
}, { deep: true })

const sedePorDefecto = props.sedes[0]?.id ?? null
const form = ref({ sede_id: sedePorDefecto, nombre: '', tipo: 'general', es_principal: false, activa: true })
const editando = ref(null)

const tipoLabel = {
    general:    'General',
    produccion: 'Producción',
    exhibicion: 'Exhibición',
    otra:       'Otra',
}

function editar(b) {
    editando.value = b.id
    form.value = {
        sede_id: b.sede_id ?? sedePorDefecto,
        nombre: b.nombre, tipo: b.tipo, es_principal: b.es_principal, activa: b.activa,
    }
}

function cancelar() {
    editando.value = null
    form.value = { sede_id: sedePorDefecto, nombre: '', tipo: 'general', es_principal: false, activa: true }
}

function guardar() {
    if (editando.value) {
        router.put(`/configuracion/bodegas/${editando.value}`, form.value, {
            preserveScroll: true,
            onSuccess: () => cancelar(),
        })
    } else {
        router.post('/configuracion/bodegas', form.value, {
            preserveScroll: true,
            onSuccess: () => cancelar(),
        })
    }
}

function eliminar(id) {
    if (!confirm('¿Eliminar esta bodega? Si tiene stock o movimientos, solo se desactivará.')) return
    router.delete(`/configuracion/bodegas/${id}`, { preserveScroll: true })
}
</script>

<template>
    <AppLayout title="Bodegas">
        <div class="max-w-3xl mx-auto">

            <div class="flex items-center gap-3 mb-5">
                <button
                    @click="router.visit('/configuracion')"
                    class="p-2 rounded-xl hover:bg-tinta-100 transition-colors text-tinta-400"
                    title="Volver"
                >
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                    </svg>
                </button>
                <h1 class="text-xl font-semibold text-tinta-900">Bodegas</h1>
            </div>

            <!-- Lista -->
            <div class="bg-white rounded-2xl border border-linea overflow-hidden mb-4">
                <div class="px-5 py-3 border-b border-linea">
                    <h2 class="text-sm font-semibold text-tinta-700">Bodegas registradas</h2>
                    <p class="text-xs text-tinta-300 mt-0.5">Cada sede puede tener su propia bodega Principal.</p>
                </div>

                <div v-if="!bodegas.length" class="py-10 text-center text-sm text-tinta-300">
                    Sin bodegas configuradas.
                </div>

                <div class="divide-y divide-gray-50">
                    <div
                        v-for="b in bodegas"
                        :key="b.id"
                        class="flex items-center gap-3 px-4 py-3"
                    >
                        <!-- Icono bodega -->
                        <div class="w-9 h-9 rounded-xl flex items-center justify-center shrink-0"
                            :class="b.activa ? 'bg-blue-50' : 'bg-tinta-100'"
                        >
                            <svg class="w-4 h-4" :class="b.activa ? 'text-[var(--marca)]' : 'text-tinta-300'"
                                fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M3 7a1 1 0 011-1h5a1 1 0 011 1v3H3V7zm0 3h7v7a1 1 0 01-1 1H4a1 1 0 01-1-1v-7zm11-3a1 1 0 011-1h5a1 1 0 011 1v3h-7V7zm0 3h7v7a1 1 0 01-1 1h-5a1 1 0 01-1-1v-7z" />
                            </svg>
                        </div>

                        <!-- Info -->
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-tinta-900 truncate">{{ b.nombre }}</p>
                            <p class="text-xs text-tinta-300">
                                <span v-if="b.sede" class="font-medium text-tinta-400">{{ b.sede.nombre }}</span>
                                <span v-if="b.sede"> · </span>{{ tipoLabel[b.tipo] ?? b.tipo }}
                            </p>
                        </div>

                        <!-- Badge Principal -->
                        <span
                            v-if="b.es_principal"
                            class="text-xs px-2 py-0.5 rounded-full bg-[var(--marca)] text-white font-semibold shrink-0"
                        >
                            Principal
                        </span>

                        <!-- Badge activa -->
                        <span
                            class="text-xs px-2 py-0.5 rounded-full shrink-0"
                            :class="b.activa ? 'bg-green-100 text-green-700' : 'bg-tinta-100 text-tinta-400'"
                        >
                            {{ b.activa ? 'Activa' : 'Inactiva' }}
                        </span>

                        <button @click="editar(b)" class="text-xs text-blue-600 hover:underline shrink-0">Editar</button>
                        <button @click="eliminar(b.id)" class="text-xs text-red-500 hover:underline shrink-0">Eliminar</button>
                    </div>
                </div>
            </div>

            <!-- Formulario crear / editar -->
            <div class="bg-white rounded-2xl border border-linea p-5">
                <h3 class="text-sm font-semibold text-tinta-700 mb-4">
                    {{ editando ? 'Editar bodega' : 'Nueva bodega' }}
                </h3>

                <div class="space-y-3">
                    <!-- Sede -->
                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">
                            Sede *
                        </label>
                        <select
                            v-model="form.sede_id"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)] bg-white"
                        >
                            <option v-for="s in sedes" :key="s.id" :value="s.id">{{ s.nombre }} ({{ s.codigo }})</option>
                        </select>
                    </div>

                    <!-- Nombre -->
                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">
                            Nombre *
                        </label>
                        <input
                            v-model="form.nombre"
                            type="text"
                            placeholder="Ej: Almacén Norte"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]"
                        />
                    </div>

                    <!-- Tipo -->
                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">
                            Tipo *
                        </label>
                        <select
                            v-model="form.tipo"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)] bg-white"
                        >
                            <option value="general">General</option>
                            <option value="produccion">Producción</option>
                            <option value="exhibicion">Exhibición</option>
                            <option value="otra">Otra</option>
                        </select>
                    </div>

                    <!-- Checkboxes -->
                    <div class="flex flex-col gap-2">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input v-model="form.es_principal" type="checkbox" class="rounded" />
                            <span class="text-sm text-tinta-700">Bodega principal de esta sede</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input v-model="form.activa" type="checkbox" class="rounded" />
                            <span class="text-sm text-tinta-700">Activa</span>
                        </label>
                    </div>

                    <!-- Botones -->
                    <div class="flex gap-3 pt-1">
                        <button
                            v-if="editando"
                            @click="cancelar"
                            class="flex-1 py-2.5 rounded-xl border border-linea text-sm text-tinta-500"
                        >
                            Cancelar
                        </button>
                        <button
                            @click="guardar"
                            class="flex-1 py-2.5 rounded-xl text-white text-sm font-semibold"
                            style="background:var(--marca);"
                        >
                            {{ editando ? 'Actualizar' : 'Crear bodega' }}
                        </button>
                    </div>
                </div>
            </div>

        </div>
    </AppLayout>
</template>
