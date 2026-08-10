<script setup>
import { ref, watch, onMounted } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useUnsavedChanges } from '@/composables/useUnsavedChanges'

const props = defineProps({
    operario:          Object,
    usuarios_operario: Array,
    tipos_colaborador: Array,
})

function formatFecha(fecha) {
    if (!fecha) return ''
    return String(fecha).substring(0, 10)
}

const form = ref({
    nombre:               props.operario.nombre,
    documento:            props.operario.documento,
    telefono:             props.operario.telefono ?? '',
    especialidad:         props.operario.especialidad ?? '',
    cargo:                props.operario.cargo ?? '',
    estado:               props.operario.estado,
    user_id:              props.operario.user_id ?? '',
    tipo_colaborador_id:  props.operario.tipo_colaborador_id ?? '',
    fecha_nacimiento:     formatFecha(props.operario.fecha_nacimiento),
    fecha_ingreso:        formatFecha(props.operario.fecha_ingreso),
    direccion:            props.operario.direccion ?? '',
    ciudad:               props.operario.ciudad ?? '',
    email:                props.operario.email ?? '',
    numero_eps:           props.operario.numero_eps ?? '',
    nombre_eps:           props.operario.nombre_eps ?? '',
    numero_pension:       props.operario.numero_pension ?? '',
    nombre_pension:       props.operario.nombre_pension ?? '',
    numero_cuenta_bancaria: props.operario.numero_cuenta_bancaria ?? '',
    banco:                props.operario.banco ?? '',
    tipo_cuenta:          props.operario.tipo_cuenta ?? '',
    crear_usuario:        false,
    usuario_email:        '',
    usuario_password:     '',
    usuario_name:         '',
})
const errors = ref({})
const seccion = ref('personal')
const subiendo = ref({})

const { hasChanges, setOriginal, checkChanges } = useUnsavedChanges()
onMounted(() => setOriginal(form.value))
watch(form, checkChanges, { deep: true })

function submit() {
    setOriginal(form.value)
    router.put(`/rrhh/operarios/${props.operario.id}`, form.value, {
        onError: (e) => { errors.value = e; hasChanges.value = true },
    })
}

const SECCIONES = [
    { key: 'personal',   label: 'Personal'    },
    { key: 'seguridad',  label: 'Seg. Social' },
    { key: 'bancario',   label: 'Bancario'    },
    { key: 'documentos', label: 'Documentos'  },
    { key: 'acceso',     label: 'Acceso'      },
]

const ARCHIVOS = [
    { tipo: 'hoja_vida',              campo: 'archivo_hoja_vida',              label: 'Hoja de vida'              },
    { tipo: 'cedula',                 campo: 'archivo_cedula',                 label: 'Cédula'                    },
    { tipo: 'eps',                    campo: 'archivo_eps',                    label: 'Documento EPS'             },
    { tipo: 'pension',                campo: 'archivo_pension',                label: 'Documento pensión'         },
    { tipo: 'antecedentes',           campo: 'archivo_antecedentes',           label: 'Antecedentes disciplinarios'},
    { tipo: 'certificacion_bancaria', campo: 'archivo_certificacion_bancaria', label: 'Certificación bancaria'    },
]

const archivos = ref({ ...props.operario })

async function subirArchivo(event, tipo) {
    const file = event.target.files[0]
    if (!file) return

    subiendo.value[tipo] = true
    const fd = new FormData()
    fd.append('archivo', file)
    fd.append('tipo', tipo)

    const token = document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1]

    try {
        const res = await fetch(`/rrhh/operarios/${props.operario.id}/subir-archivo`, {
            method: 'POST',
            headers: { 'X-XSRF-TOKEN': decodeURIComponent(token ?? '') },
            body: fd,
        })
        const data = await res.json()
        if (data.ok) {
            archivos.value[`archivo_${tipo}`] = data.path
        } else {
            console.error('Error:', data.error)
            alert('Error al subir archivo: ' + data.error)
        }
    } finally {
        subiendo.value[tipo] = false
        event.target.value = ''
    }
}
</script>

<template>
    <AppLayout title="Editar Colaborador">
        <div class="max-w-lg mx-auto">

            <div class="flex items-center gap-3 mb-5">
                <a :href="`/rrhh/operarios/${operario.id}`"
                    @click.prevent="router.visit(`/rrhh/operarios/${operario.id}`)"
                    class="text-tinta-300 hover:text-tinta-700">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M15 19l-7-7 7-7"/>
                    </svg>
                </a>
                <h1 class="text-xl font-semibold text-tinta-900">Editar Colaborador</h1>
            </div>

            <div v-if="hasChanges" class="mb-3 inline-flex items-center gap-1.5 text-xs font-medium text-amber-700 bg-amber-50 border border-amber-200 rounded-xl px-3 py-1.5">
                <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                Cambios sin guardar
            </div>

            <!-- Tabs de sección -->
            <div class="flex gap-1 bg-tinta-100 p-1 rounded-2xl mb-4 overflow-x-auto">
                <button v-for="s in SECCIONES" :key="s.key"
                    type="button"
                    @click="seccion = s.key"
                    class="flex-1 min-w-[64px] py-2 px-2 rounded-xl text-xs font-semibold transition-colors whitespace-nowrap"
                    :class="seccion === s.key ? 'bg-white text-tinta-900 shadow-sm' : 'text-tinta-400 hover:text-tinta-700'">
                    {{ s.label }}
                </button>
            </div>

            <!-- ─── SECCIÓN PERSONAL ─── -->
            <div v-show="seccion === 'personal'" class="bg-white rounded-2xl border border-linea p-5 space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Nombre *</label>
                    <input v-model="form.nombre" type="text"
                        class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2" />
                    <p v-if="errors.nombre" class="text-xs text-red-500 mt-1">{{ errors.nombre }}</p>
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
                    <p v-if="errors.documento" class="text-xs text-red-500 mt-1">{{ errors.documento }}</p>
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
                    <p v-if="errors.email" class="text-xs text-red-500 mt-1">{{ errors.email }}</p>
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
            <div v-show="seccion === 'seguridad'" class="bg-white rounded-2xl border border-linea p-5 space-y-4">
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
                        <!-- Archivo EPS -->
                        <div class="flex items-center gap-3 p-3 rounded-lg border border-linea">
                            <div class="flex-1">
                                <p class="text-sm font-medium text-tinta-700">Documento EPS</p>
                                <p v-if="archivos.archivo_eps" class="text-xs text-green-600 mt-0.5">
                                    Cargado
                                    <a :href="`/storage/${archivos.archivo_eps}`" target="_blank" class="underline ml-1">Ver</a>
                                </p>
                                <p v-else class="text-xs text-tinta-300 mt-0.5">Sin archivo</p>
                            </div>
                            <label class="cursor-pointer px-3 py-1.5 rounded-lg text-xs font-medium text-white" style="background:var(--marca)">
                                {{ archivos.archivo_eps ? 'Reemplazar' : 'Cargar' }}
                                <input type="file" class="hidden" :disabled="subiendo.eps" @change="subirArchivo($event, 'eps')">
                            </label>
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
                        <!-- Archivo pensión -->
                        <div class="flex items-center gap-3 p-3 rounded-lg border border-linea">
                            <div class="flex-1">
                                <p class="text-sm font-medium text-tinta-700">Documento pensión</p>
                                <p v-if="archivos.archivo_pension" class="text-xs text-green-600 mt-0.5">
                                    Cargado
                                    <a :href="`/storage/${archivos.archivo_pension}`" target="_blank" class="underline ml-1">Ver</a>
                                </p>
                                <p v-else class="text-xs text-tinta-300 mt-0.5">Sin archivo</p>
                            </div>
                            <label class="cursor-pointer px-3 py-1.5 rounded-lg text-xs font-medium text-white" style="background:var(--marca)">
                                {{ archivos.archivo_pension ? 'Reemplazar' : 'Cargar' }}
                                <input type="file" class="hidden" :disabled="subiendo.pension" @change="subirArchivo($event, 'pension')">
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ─── SECCIÓN BANCARIA ─── -->
            <div v-show="seccion === 'bancario'" class="bg-white rounded-2xl border border-linea p-5 space-y-4">
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

                <!-- Certificación bancaria -->
                <div class="flex items-center gap-3 p-3 rounded-lg border border-linea">
                    <div class="flex-1">
                        <p class="text-sm font-medium text-tinta-700">Certificación bancaria</p>
                        <p v-if="archivos.archivo_certificacion_bancaria" class="text-xs text-green-600 mt-0.5">
                            Cargado
                            <a :href="`/storage/${archivos.archivo_certificacion_bancaria}`" target="_blank" class="underline ml-1">Ver</a>
                        </p>
                        <p v-else class="text-xs text-tinta-300 mt-0.5">Sin archivo</p>
                    </div>
                    <label class="cursor-pointer px-3 py-1.5 rounded-lg text-xs font-medium text-white" style="background:var(--marca)">
                        {{ archivos.archivo_certificacion_bancaria ? 'Reemplazar' : 'Cargar' }}
                        <input type="file" class="hidden" :disabled="subiendo.certificacion_bancaria" @change="subirArchivo($event, 'certificacion_bancaria')">
                    </label>
                </div>
            </div>

            <!-- ─── SECCIÓN DOCUMENTOS ─── -->
            <div v-show="seccion === 'documentos'" class="bg-white rounded-2xl border border-linea p-5 space-y-3">
                <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-2">Documentos del colaborador</p>

                <div v-for="doc in ARCHIVOS" :key="doc.tipo"
                    class="flex items-center gap-3 p-3 rounded-lg border border-linea">
                    <div class="flex-1">
                        <p class="text-sm font-medium text-tinta-700">{{ doc.label }}</p>
                        <p v-if="archivos[doc.campo]" class="text-xs text-green-600 mt-0.5">
                            Cargado
                            <a :href="`/storage/${archivos[doc.campo]}`" target="_blank" class="underline ml-1">Ver</a>
                        </p>
                        <p v-else class="text-xs text-tinta-300 mt-0.5">Sin archivo</p>
                    </div>
                    <label class="cursor-pointer px-3 py-1.5 rounded-lg text-xs font-medium text-white" style="background:var(--marca)">
                        <span v-if="subiendo[doc.tipo]">Subiendo…</span>
                        <span v-else>{{ archivos[doc.campo] ? 'Reemplazar' : 'Cargar' }}</span>
                        <input type="file" class="hidden" :disabled="subiendo[doc.tipo]" @change="subirArchivo($event, doc.tipo)">
                    </label>
                </div>

                <!-- Otros documentos -->
                <div class="border-t border-linea pt-3">
                    <p class="text-xs font-medium text-tinta-400 mb-2">Otros documentos</p>
                    <div v-if="archivos.archivo_otros?.length" class="space-y-2 mb-2">
                        <div v-for="(doc, i) in archivos.archivo_otros" :key="i"
                            class="flex items-center gap-2 text-xs text-tinta-500">
                            <span class="truncate flex-1">{{ doc.nombre }}</span>
                            <a :href="`/storage/${doc.path}`" target="_blank"
                                class="underline text-blue-600 shrink-0">Ver</a>
                        </div>
                    </div>
                    <label class="cursor-pointer inline-flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-medium text-white" style="background:var(--marca)">
                        <span v-if="subiendo.otros">Subiendo…</span>
                        <span v-else>+ Agregar documento</span>
                        <input type="file" class="hidden" :disabled="subiendo.otros" @change="subirArchivo($event, 'otros')">
                    </label>
                </div>
            </div>

            <!-- ─── SECCIÓN ACCESO ─── -->
            <div v-show="seccion === 'acceso'" class="bg-white rounded-2xl border border-linea p-5 space-y-4">
                <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Acceso al sistema</p>

                <div class="flex gap-2">
                    <button type="button"
                        @click="form.crear_usuario = false; form.usuario_email = ''; form.usuario_password = ''; form.usuario_name = ''"
                        class="flex-1 py-2 rounded-xl text-xs font-medium border transition-colors"
                        :class="!form.crear_usuario ? 'bg-blue-50 border-blue-400 text-blue-700' : 'border-linea text-tinta-400 hover:bg-tinta-50'">
                        Vincular usuario existente
                    </button>
                    <button type="button"
                        @click="form.crear_usuario = true; form.user_id = ''; form.usuario_name = form.nombre"
                        class="flex-1 py-2 rounded-xl text-xs font-medium border transition-colors"
                        :class="form.crear_usuario ? 'bg-green-50 border-green-400 text-green-700' : 'border-linea text-tinta-400 hover:bg-tinta-50'">
                        Crear usuario nuevo
                    </button>
                </div>

                <div v-if="!form.crear_usuario">
                    <div v-if="operario.user_id && form.user_id"
                        class="bg-green-50 border border-green-200 rounded-xl px-4 py-3 flex items-center justify-between">
                        <div>
                            <p class="text-xs font-semibold text-green-700">Usuario vinculado</p>
                            <p class="text-sm text-green-800">{{ operario.usuario_nombre ?? 'Usuario activo' }}</p>
                        </div>
                        <button type="button" @click="form.user_id = ''"
                            class="text-xs text-red-500 hover:text-red-700 underline">
                            Desvincular
                        </button>
                    </div>
                    <div v-else>
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
                </div>

                <div v-if="form.crear_usuario" class="space-y-3">
                    <div>
                        <label class="block text-xs font-medium text-tinta-400 mb-1.5">Nombre de usuario *</label>
                        <input v-model="form.usuario_name" type="text" placeholder="Nombre completo"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2" />
                        <p v-if="errors.usuario_name" class="text-xs text-red-500 mt-1">{{ errors.usuario_name }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-tinta-400 mb-1.5">Correo electrónico *</label>
                        <input v-model="form.usuario_email" type="email" placeholder="colaborador@empresa.com"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2" />
                        <p v-if="errors.usuario_email" class="text-xs text-red-500 mt-1">{{ errors.usuario_email }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-tinta-400 mb-1.5">Contraseña *</label>
                        <input v-model="form.usuario_password" type="password" placeholder="Mínimo 8 caracteres"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2" />
                        <p v-if="errors.usuario_password" class="text-xs text-red-500 mt-1">{{ errors.usuario_password }}</p>
                    </div>
                    <div class="bg-blue-50 rounded-xl px-3 py-2 text-xs text-blue-700">
                        Se creará un usuario con rol <strong>operario</strong> que podrá acceder
                        a Mi Panel y ver sus trabajos asignados.
                    </div>
                </div>
            </div>

            <div class="flex gap-3 mt-5">
                <button type="button" @click="router.visit(`/rrhh/operarios/${operario.id}`)"
                    class="flex-1 py-3 rounded-xl border border-linea text-sm text-tinta-500 font-medium">
                    Cancelar
                </button>
                <button type="button" @click="submit"
                    class="flex-1 py-3 rounded-xl text-white text-sm font-semibold"
                    style="background:var(--marca);">
                    Guardar cambios
                </button>
            </div>

        </div>
    </AppLayout>
</template>
