<script setup>
import { ref, watch, onMounted } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useUnsavedChanges } from '@/composables/useUnsavedChanges'

const props = defineProps({
    usuarios_operario: Array,
    tipos_colaborador: Array,
})

const form = ref({
    nombre:               '',
    documento:            '',
    telefono:             '',
    especialidad:         '',
    cargo:                '',
    estado:               'activo',
    user_id:              '',
    tipo_colaborador_id:  '',
    fecha_nacimiento:     '',
    fecha_ingreso:        '',
    direccion:            '',
    ciudad:               '',
    email:                '',
    numero_eps:           '',
    nombre_eps:           '',
    numero_pension:       '',
    nombre_pension:       '',
    numero_cuenta_bancaria: '',
    banco:                '',
    tipo_cuenta:          '',
    crear_usuario:        false,
    usuario_email:        '',
    usuario_password:     '',
    usuario_name:         '',
})
const errors = ref({})
const seccion = ref('personal')

const { hasChanges, setOriginal, checkChanges } = useUnsavedChanges()
onMounted(() => setOriginal(form.value))
watch(form, checkChanges, { deep: true })

function onNombreChange() {
    if (form.value.crear_usuario && !form.value.usuario_name) {
        form.value.usuario_name = form.value.nombre
    }
}

function submit() {
    setOriginal(form.value)
    router.post('/rrhh/operarios', form.value, {
        onError: (e) => { errors.value = e; hasChanges.value = true },
    })
}

const SECCIONES = [
    { key: 'personal',  label: 'Personal'   },
    { key: 'seguridad', label: 'Seg. Social' },
    { key: 'bancario',  label: 'Bancario'   },
    { key: 'acceso',    label: 'Acceso'     },
]
</script>

<template>
    <AppLayout title="Nuevo Colaborador">
        <div class="max-w-lg mx-auto">

            <div class="flex items-center gap-3 mb-5">
                <a href="/rrhh/operarios" @click.prevent="router.visit('/rrhh/operarios')"
                    class="text-tinta-300 hover:text-tinta-700">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M15 19l-7-7 7-7"/>
                    </svg>
                </a>
                <h1 class="text-xl font-semibold text-tinta-900">Nuevo Colaborador</h1>
            </div>

            <div v-if="hasChanges" class="mb-3 inline-flex items-center gap-1.5 text-xs font-medium text-aviso-ambar bg-pastel-ambar border border-borde-aviso-ambar rounded-xl px-3 py-1.5">
                <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                Cambios sin guardar
            </div>

            <!-- Tabs de sección -->
            <div class="flex gap-1 bg-tinta-100 p-1 rounded-2xl mb-4 overflow-x-auto">
                <button v-for="s in SECCIONES" :key="s.key"
                    type="button"
                    @click="seccion = s.key"
                    class="flex-1 min-w-[72px] py-2 px-2 rounded-xl text-xs font-semibold transition-colors whitespace-nowrap"
                    :class="seccion === s.key ? 'bg-superficie text-tinta-900 shadow-sm' : 'text-tinta-400 hover:text-tinta-700'">
                    {{ s.label }}
                </button>
            </div>

            <!-- ─── SECCIÓN PERSONAL ─── -->
            <div v-show="seccion === 'personal'" class="bg-superficie rounded-2xl border border-linea p-5 space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Nombre *</label>
                    <input v-model="form.nombre" @input="onNombreChange" type="text"
                        class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2" />
                    <p v-if="errors.nombre" class="text-xs text-aviso-rojo mt-1">{{ errors.nombre }}</p>
                </div>

                <div v-if="tipos_colaborador?.length">
                    <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Tipo de colaborador</label>
                    <select v-model="form.tipo_colaborador_id"
                        class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2">
                        <option value="">Sin tipo</option>
                        <option v-for="t in tipos_colaborador" :key="t.id" :value="t.id">{{ t.nombre }}</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Cargo</label>
                    <input v-model="form.cargo" type="text"
                        class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2" />
                </div>

                <div>
                    <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Documento *</label>
                    <input v-model="form.documento" type="text"
                        class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2" />
                    <p v-if="errors.documento" class="text-xs text-aviso-rojo mt-1">{{ errors.documento }}</p>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Teléfono</label>
                    <input v-model="form.telefono" type="text"
                        class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2" />
                </div>

                <div>
                    <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Email</label>
                    <input v-model="form.email" type="email"
                        class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2" />
                    <p v-if="errors.email" class="text-xs text-aviso-rojo mt-1">{{ errors.email }}</p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Fecha nacimiento</label>
                        <input v-model="form.fecha_nacimiento" type="date"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Fecha ingreso</label>
                        <input v-model="form.fecha_ingreso" type="date"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2" />
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Especialidad</label>
                    <input v-model="form.especialidad" type="text"
                        class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2" />
                </div>

                <div>
                    <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Ciudad</label>
                    <input v-model="form.ciudad" type="text"
                        class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2" />
                </div>

                <div>
                    <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Dirección</label>
                    <input v-model="form.direccion" type="text"
                        class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2" />
                </div>

                <div>
                    <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Estado *</label>
                    <select v-model="form.estado"
                        class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2">
                        <option value="activo">Activo</option>
                        <option value="inactivo">Inactivo</option>
                    </select>
                </div>
            </div>

            <!-- ─── SECCIÓN SEGURIDAD SOCIAL ─── -->
            <div v-show="seccion === 'seguridad'" class="bg-superficie rounded-2xl border border-linea p-5 space-y-4">
                <p class="text-xs text-tinta-300">Los documentos se pueden cargar después de crear el colaborador.</p>

                <div class="border-b border-linea pb-4">
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-3">EPS</p>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-xs font-medium text-tinta-400 mb-1.5">Nombre EPS</label>
                            <input v-model="form.nombre_eps" type="text"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-tinta-400 mb-1.5">Número / Código</label>
                            <input v-model="form.numero_eps" type="text"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2" />
                        </div>
                    </div>
                </div>

                <div>
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-3">Pensión</p>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-xs font-medium text-tinta-400 mb-1.5">Nombre fondo de pensión</label>
                            <input v-model="form.nombre_pension" type="text"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-tinta-400 mb-1.5">Número / Código</label>
                            <input v-model="form.numero_pension" type="text"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2" />
                        </div>
                    </div>
                </div>
            </div>

            <!-- ─── SECCIÓN BANCARIA ─── -->
            <div v-show="seccion === 'bancario'" class="bg-superficie rounded-2xl border border-linea p-5 space-y-4">
                <p class="text-xs text-tinta-300">Los documentos se pueden cargar después de crear el colaborador.</p>

                <div>
                    <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Banco</label>
                    <input v-model="form.banco" type="text"
                        class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2" />
                </div>

                <div>
                    <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Tipo de cuenta</label>
                    <select v-model="form.tipo_cuenta"
                        class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2">
                        <option value="">Sin especificar</option>
                        <option value="ahorros">Ahorros</option>
                        <option value="corriente">Corriente</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Número de cuenta</label>
                    <input v-model="form.numero_cuenta_bancaria" type="text"
                        class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2" />
                </div>
            </div>

            <!-- ─── SECCIÓN ACCESO ─── -->
            <div v-show="seccion === 'acceso'" class="bg-superficie rounded-2xl border border-linea p-5 space-y-4">
                <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Acceso al sistema</p>

                <div class="flex gap-2">
                    <button type="button"
                        @click="form.crear_usuario = false; form.usuario_email = ''; form.usuario_password = ''; form.usuario_name = ''"
                        class="flex-1 py-2 rounded-xl text-xs font-medium border transition-colors"
                        :class="!form.crear_usuario ? 'bg-pastel-azul border-blue-400 text-aviso-azul' : 'border-linea text-tinta-400 hover:bg-tinta-50'">
                        Vincular usuario existente
                    </button>
                    <button type="button"
                        @click="form.crear_usuario = true; form.user_id = ''; form.usuario_name = form.nombre"
                        class="flex-1 py-2 rounded-xl text-xs font-medium border transition-colors"
                        :class="form.crear_usuario ? 'bg-pastel-verde border-green-400 text-aviso-verde' : 'border-linea text-tinta-400 hover:bg-tinta-50'">
                        Crear usuario nuevo
                    </button>
                </div>

                <div v-if="!form.crear_usuario">
                    <label class="block text-xs font-medium text-tinta-400 mb-1.5">
                        Usuario existente <span class="text-tinta-300 font-normal">(opcional)</span>
                    </label>
                    <select v-model="form.user_id"
                        class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2">
                        <option value="">Sin acceso al sistema</option>
                        <option v-for="u in usuarios_operario" :key="u.id" :value="u.id">{{ u.name }}</option>
                    </select>
                    <p class="text-xs text-tinta-300 mt-1">Solo usuarios con rol "operario" aparecen aquí.</p>
                </div>

                <div v-if="form.crear_usuario" class="space-y-3">
                    <div>
                        <label class="block text-xs font-medium text-tinta-400 mb-1.5">Nombre de usuario *</label>
                        <input v-model="form.usuario_name" type="text" placeholder="Nombre completo"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2" />
                        <p v-if="errors.usuario_name" class="text-xs text-aviso-rojo mt-1">{{ errors.usuario_name }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-tinta-400 mb-1.5">Correo electrónico *</label>
                        <input v-model="form.usuario_email" type="email" placeholder="colaborador@empresa.com"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2" />
                        <p v-if="errors.usuario_email" class="text-xs text-aviso-rojo mt-1">{{ errors.usuario_email }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-tinta-400 mb-1.5">Contraseña *</label>
                        <input v-model="form.usuario_password" type="password" placeholder="Mínimo 8 caracteres"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2" />
                        <p v-if="errors.usuario_password" class="text-xs text-aviso-rojo mt-1">{{ errors.usuario_password }}</p>
                    </div>
                    <div class="bg-pastel-azul rounded-xl px-3 py-2 text-xs text-aviso-azul">
                        Se creará un usuario con rol <strong>operario</strong> que podrá acceder
                        a Mi Panel y ver sus trabajos asignados.
                    </div>
                </div>
            </div>

            <div class="flex gap-3 mt-5">
                <button type="button" @click="router.visit('/rrhh/operarios')"
                    class="flex-1 py-3 rounded-xl border border-linea text-sm text-tinta-500 font-medium">
                    Cancelar
                </button>
                <button type="button" @click="submit"
                    class="flex-1 py-3 rounded-xl text-white text-sm font-semibold"
                    style="background:var(--marca);">
                    Crear Colaborador
                </button>
            </div>

        </div>
    </AppLayout>
</template>
