<script setup>
import { computed, onMounted, watch } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useForm } from '@inertiajs/vue3'
import { useUnsavedChanges } from '@/composables/useUnsavedChanges'

const props = defineProps({
    sedes:   { type: Array, default: () => [] },
    roles:   { type: Array, default: () => [] },
    bodegas: { type: Array, default: () => [] },
})

const form = useForm({
    name:                  '',
    email:                 '',
    telefono:              '',
    password:              '',
    password_confirmation: '',
    rol_id:                props.roles[0]?.id ?? null,
    sede_id:               props.sedes[0]?.id ?? null,
    sedes:                 [],
    bodegas:               [],
    activo:                true,
})

// Si el rol da acceso a todas las sedes, no tiene sentido elegirlas a mano.
const rolElegido = computed(() => props.roles.find(r => r.id === form.rol_id))
const todasLasSedes = computed(() => !!rolElegido.value?.todas_las_sedes)

function alternar(lista, id) {
    const i = form[lista].indexOf(id)
    if (i === -1) form[lista].push(id)
    else form[lista].splice(i, 1)
}

const { hasChanges, setOriginal, checkChanges, markClean } = useUnsavedChanges()
onMounted(() => setOriginal(form.data()))
watch(() => form.data(), checkChanges, { deep: true })

const submit = () => {
    markClean()
    form.post('/usuarios')
}
</script>

<template>
    <AppLayout title="Nuevo usuario">
        <div class="max-w-xl">
            <div class="bg-white rounded-xl shadow-sm p-6">
                <h2 class="font-semibold text-tinta-900 mb-4">Crear nuevo usuario</h2>

                <div v-if="hasChanges" class="mb-4 inline-flex items-center gap-1.5 text-xs font-medium text-amber-700 bg-amber-50 border border-amber-200 rounded-xl px-3 py-1.5">
                    <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                    Cambios sin guardar
                </div>

                <form @submit.prevent="submit" class="space-y-5">

                    <!-- Nombre -->
                    <div>
                        <label class="block text-sm font-medium text-tinta-700 mb-1">Nombre completo</label>
                        <input
                            v-model="form.name"
                            type="text"
                            required
                            class="w-full px-3 py-2.5 border rounded-lg text-sm focus:outline-none focus:ring-2"
                            :class="form.errors.name ? 'border-red-400 focus:ring-red-200' : 'border-tinta-200 focus:ring-blue-200'"
                        />
                        <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
                    </div>

                    <!-- Email -->
                    <div>
                        <label class="block text-sm font-medium text-tinta-700 mb-1">Correo electrónico</label>
                        <input
                            v-model="form.email"
                            type="email"
                            required
                            class="w-full px-3 py-2.5 border rounded-lg text-sm focus:outline-none focus:ring-2"
                            :class="form.errors.email ? 'border-red-400 focus:ring-red-200' : 'border-tinta-200 focus:ring-blue-200'"
                        />
                        <p v-if="form.errors.email" class="mt-1 text-xs text-red-600">{{ form.errors.email }}</p>
                    </div>

                    <!-- Teléfono -->
                    <div>
                        <label class="block text-sm font-medium text-tinta-700 mb-1">Teléfono</label>
                        <input
                            v-model="form.telefono"
                            type="tel"
                            placeholder="Ej: 3001234567"
                            class="w-full px-3 py-2.5 border rounded-lg text-sm focus:outline-none focus:ring-2"
                            :class="form.errors.telefono ? 'border-red-400 focus:ring-red-200' : 'border-tinta-200 focus:ring-blue-200'"
                        />
                        <p v-if="form.errors.telefono" class="mt-1 text-xs text-red-600">{{ form.errors.telefono }}</p>
                    </div>

                    <!-- Password -->
                    <div>
                        <label class="block text-sm font-medium text-tinta-700 mb-1">Contraseña</label>
                        <input
                            v-model="form.password"
                            type="password"
                            required
                            class="w-full px-3 py-2.5 border rounded-lg text-sm focus:outline-none focus:ring-2"
                            :class="form.errors.password ? 'border-red-400 focus:ring-red-200' : 'border-tinta-200 focus:ring-blue-200'"
                        />
                        <p v-if="form.errors.password" class="mt-1 text-xs text-red-600">{{ form.errors.password }}</p>
                    </div>

                    <!-- Confirmación password -->
                    <div>
                        <label class="block text-sm font-medium text-tinta-700 mb-1">Confirmar contraseña</label>
                        <input
                            v-model="form.password_confirmation"
                            type="password"
                            required
                            class="w-full px-3 py-2.5 border rounded-lg text-sm focus:outline-none focus:ring-2 border-tinta-200 focus:ring-blue-200"
                        />
                    </div>

                    <!-- Rol -->
                    <div>
                        <label class="block text-sm font-medium text-tinta-700 mb-1">Rol</label>
                        <select
                            v-model="form.rol_id"
                            class="w-full px-3 py-2.5 border rounded-lg text-sm focus:outline-none focus:ring-2"
                            :class="form.errors.rol_id ? 'border-red-400 focus:ring-red-200' : 'border-tinta-200 focus:ring-blue-200'"
                        >
                            <option v-for="r in roles" :key="r.id" :value="r.id">{{ r.nombre }}</option>
                        </select>
                        <p v-if="form.errors.rol_id" class="mt-1 text-xs text-red-600">{{ form.errors.rol_id }}</p>
                        <p class="mt-1 text-xs text-tinta-300">
                            Los roles se crean en Configuración → Roles y permisos.
                        </p>
                    </div>

                    <!-- Sede principal -->
                    <div>
                        <label class="block text-sm font-medium text-tinta-700 mb-1">Sede principal</label>
                        <select
                            v-model="form.sede_id"
                            class="w-full px-3 py-2.5 border rounded-lg text-sm focus:outline-none focus:ring-2"
                            :class="form.errors.sede_id ? 'border-red-400 focus:ring-red-200' : 'border-tinta-200 focus:ring-blue-200'"
                        >
                            <option v-for="s in sedes" :key="s.id" :value="s.id">{{ s.nombre }} ({{ s.codigo }})</option>
                        </select>
                        <p v-if="form.errors.sede_id" class="mt-1 text-xs text-red-600">{{ form.errors.sede_id }}</p>
                    </div>

                    <!-- Sedes adicionales -->
                    <div v-if="!todasLasSedes">
                        <label class="block text-sm font-medium text-tinta-700 mb-1">Sedes a las que accede</label>
                        <div class="rounded-lg border border-linea p-3 space-y-1.5">
                            <label v-for="s in sedes" :key="s.id" class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" class="rounded"
                                    :checked="form.sedes.includes(s.id)" @change="alternar('sedes', s.id)" />
                                <span class="text-sm text-tinta-700">{{ s.nombre }}</span>
                            </label>
                        </div>
                        <p class="mt-1 text-xs text-tinta-300">Si no marcas ninguna, queda con su sede principal.</p>
                    </div>
                    <div v-else class="rounded-lg bg-blue-50 border border-blue-200 p-3">
                        <p class="text-xs text-blue-800">Este rol tiene acceso a todas las sedes.</p>
                    </div>

                    <!-- Almacenes -->
                    <div v-if="bodegas.length">
                        <label class="block text-sm font-medium text-tinta-700 mb-1">Almacenes permitidos</label>
                        <div class="rounded-lg border border-linea p-3 space-y-1.5 max-h-48 overflow-y-auto">
                            <label v-for="b in bodegas" :key="b.id" class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" class="rounded"
                                    :checked="form.bodegas.includes(b.id)" @change="alternar('bodegas', b.id)" />
                                <span class="text-sm text-tinta-700">
                                    {{ b.nombre }}
                                    <span v-if="b.sede" class="text-tinta-300">· {{ b.sede.nombre }}</span>
                                </span>
                            </label>
                        </div>
                        <p class="mt-1 text-xs text-tinta-300">Sin marcar ninguno, accede a los de sus sedes.</p>
                    </div>

                    <!-- Activo -->
                    <div class="flex items-center gap-3">
                        <button
                            type="button"
                            @click="form.activo = !form.activo"
                            class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors"
                            :style="form.activo ? 'background-color: var(--marca);' : 'background-color: #D1D5DB;'"
                        >
                            <span
                                class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"
                                :class="form.activo ? 'translate-x-6' : 'translate-x-1'"
                            />
                        </button>
                        <label class="text-sm text-tinta-700">{{ form.activo ? 'Usuario activo' : 'Usuario inactivo' }}</label>
                    </div>

                    <!-- Botones -->
                    <div class="flex items-center gap-3 pt-2">
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="px-5 py-2.5 rounded-lg text-white text-sm font-medium transition-opacity hover:opacity-90 disabled:opacity-60"
                            style="background-color: var(--marca);"
                        >
                            Guardar usuario
                        </button>
                        <a
                            href="/usuarios"
                            class="px-5 py-2.5 rounded-lg text-sm font-medium border border-tinta-200 text-tinta-700 hover:bg-tinta-50 transition-colors"
                        >
                            Cancelar
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
