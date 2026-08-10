<script setup>
import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    sedes: Array,
})

const sedes = ref(props.sedes.map(s => ({ ...s })))

watch(() => props.sedes, (vals) => {
    sedes.value = vals.map(s => ({ ...s }))
}, { deep: true })

const formVacio = {
    nombre: '', codigo: '', tiene_ventas: true, tiene_produccion: false,
    es_principal: false, nit: '', direccion: '', ciudad: '', telefono: '',
    email: '', activa: true,
}

const form = ref({ ...formVacio })
const editando = ref(null)

function editar(s) {
    editando.value = s.id
    form.value = {
        nombre: s.nombre, codigo: s.codigo,
        tiene_ventas: s.tiene_ventas, tiene_produccion: s.tiene_produccion,
        es_principal: s.es_principal, nit: s.nit ?? '', direccion: s.direccion ?? '',
        ciudad: s.ciudad ?? '', telefono: s.telefono ?? '', email: s.email ?? '',
        activa: s.activa,
    }
    window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' })
}

function cancelar() {
    editando.value = null
    form.value = { ...formVacio }
}

function guardar() {
    const payload = { ...form.value, codigo: form.value.codigo.toUpperCase() }
    if (editando.value) {
        router.put(`/configuracion/sedes/${editando.value}`, payload, {
            preserveScroll: true,
            onSuccess: () => cancelar(),
        })
    } else {
        router.post('/configuracion/sedes', payload, {
            preserveScroll: true,
            onSuccess: () => cancelar(),
        })
    }
}

function eliminar(s) {
    if (s.es_principal) return
    if (!confirm(`¿Eliminar la sede "${s.nombre}"? Si tiene bodegas o usuarios, solo se desactivará.`)) return
    router.delete(`/configuracion/sedes/${s.id}`, { preserveScroll: true })
}
</script>

<template>
    <AppLayout title="Sedes">
        <div class="max-w-3xl mx-auto">

            <div class="flex items-center gap-3 mb-5">
                <button @click="router.visit('/configuracion')"
                    class="p-2 rounded-xl hover:bg-tinta-100 transition-colors text-tinta-400" title="Volver">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                    </svg>
                </button>
                <h1 class="text-xl font-semibold text-tinta-900">Sedes</h1>
            </div>

            <!-- Lista -->
            <div class="bg-white rounded-2xl border border-linea overflow-hidden mb-4">
                <div class="px-5 py-3 border-b border-linea">
                    <h2 class="text-sm font-semibold text-tinta-700">Sedes registradas</h2>
                    <p class="text-xs text-tinta-300 mt-0.5">
                        Una sede puede ser solo de ventas, solo fábrica, o las dos cosas.
                    </p>
                </div>

                <div v-if="!sedes.length" class="py-10 text-center text-sm text-tinta-300">
                    Sin sedes configuradas.
                </div>

                <div class="divide-y divide-gray-50">
                    <div v-for="s in sedes" :key="s.id" class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl flex items-center justify-center shrink-0"
                                :class="s.activa ? 'bg-blue-50' : 'bg-tinta-100'">
                                <svg class="w-4 h-4" :class="s.activa ? 'text-[var(--marca)]' : 'text-tinta-300'"
                                    fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                </svg>
                            </div>

                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <p class="text-sm font-medium text-tinta-900 truncate">{{ s.nombre }}</p>
                                    <span class="text-xs font-mono px-1.5 py-0.5 rounded bg-tinta-100 text-tinta-500">{{ s.codigo }}</span>
                                </div>
                                <p class="text-xs text-tinta-300">
                                    {{ s.tipo_label }}
                                    <span v-if="s.ciudad"> · {{ s.ciudad }}</span>
                                </p>
                                <p class="text-xs text-tinta-300 mt-0.5">
                                    {{ s.bodegas_count }} bodega(s) · {{ s.usuarios_count }} usuario(s)
                                </p>
                            </div>

                            <span v-if="s.es_principal"
                                class="text-xs px-2 py-0.5 rounded-full bg-[var(--marca)] text-white font-semibold shrink-0">
                                Principal
                            </span>
                            <span class="text-xs px-2 py-0.5 rounded-full shrink-0"
                                :class="s.activa ? 'bg-green-100 text-green-700' : 'bg-tinta-100 text-tinta-400'">
                                {{ s.activa ? 'Activa' : 'Inactiva' }}
                            </span>
                        </div>

                        <div class="flex items-center gap-3 mt-2 pl-12">
                            <button @click="editar(s)" class="text-xs text-blue-600 hover:underline">Editar</button>
                            <button v-if="!s.es_principal" @click="eliminar(s)"
                                class="text-xs text-red-500 hover:underline">Eliminar</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Formulario -->
            <div class="bg-white rounded-2xl border border-linea p-5">
                <h3 class="text-sm font-semibold text-tinta-700 mb-4">
                    {{ editando ? 'Editar sede' : 'Nueva sede' }}
                </h3>

                <div class="space-y-3">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Nombre *</label>
                            <input v-model="form.nombre" type="text" placeholder="Ej: Cali"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Código *</label>
                            <input v-model="form.codigo" type="text" maxlength="10" placeholder="CAL"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm uppercase focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]" />
                            <p class="text-xs text-tinta-300 mt-1">Se usa en los códigos de documentos.</p>
                        </div>
                    </div>

                    <!-- Qué hace la sede -->
                    <div class="rounded-xl bg-tinta-50 p-3">
                        <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-2">¿Qué se hace en esta sede?</p>
                        <label class="flex items-center gap-2 cursor-pointer py-1">
                            <input v-model="form.tiene_ventas" type="checkbox" class="rounded" />
                            <span class="text-sm text-tinta-700">Ventas (clientes, cotizaciones)</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer py-1">
                            <input v-model="form.tiene_produccion" type="checkbox" class="rounded" />
                            <span class="text-sm text-tinta-700">Fábrica (órdenes de producción, trabajos)</span>
                        </label>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Ciudad</label>
                            <input v-model="form.ciudad" type="text"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">NIT</label>
                            <input v-model="form.nit" type="text"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]" />
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Dirección</label>
                        <input v-model="form.direccion" type="text"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]" />
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Teléfono</label>
                            <input v-model="form.telefono" type="text"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Correo</label>
                            <input v-model="form.email" type="email"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]" />
                        </div>
                    </div>

                    <div class="flex flex-col gap-2">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input v-model="form.es_principal" type="checkbox" class="rounded" />
                            <span class="text-sm text-tinta-700">Sede principal (reemplaza a la actual)</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input v-model="form.activa" type="checkbox" class="rounded" />
                            <span class="text-sm text-tinta-700">Activa</span>
                        </label>
                    </div>

                    <div class="flex gap-3 pt-1">
                        <button v-if="editando" @click="cancelar"
                            class="flex-1 py-2.5 rounded-xl border border-linea text-sm text-tinta-500">
                            Cancelar
                        </button>
                        <button @click="guardar" class="flex-1 py-2.5 rounded-xl text-white text-sm font-semibold"
                            style="background:var(--marca);">
                            {{ editando ? 'Actualizar' : 'Crear sede' }}
                        </button>
                    </div>
                </div>
            </div>

        </div>
    </AppLayout>
</template>
