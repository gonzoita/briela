<script setup>
import { computed, reactive, ref, onMounted, watch } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ChipsSegmentacion from '@/Components/ChipsSegmentacion.vue'
import AvisoIdentificacion from '@/Components/AvisoIdentificacion.vue'
import { useUnsavedChanges } from '@/composables/useUnsavedChanges'
import { useConsultaIdentificacion } from '@/composables/useConsultaIdentificacion'

const props = defineProps({
    cliente:               Object,
    contactos:             { type: Array,  default: () => [] },
    archivos:              { type: Array,  default: () => [] },
    segmentacion_opciones: { type: Object, default: () => ({}) },
    sedes:                 { type: Array,  default: () => [] },
})

const form = useForm({
    sede_id: props.cliente.sede_id ?? props.sedes[0]?.id ?? null,
    tipo: props.cliente.tipo,
    tipo_identificacion: props.cliente.tipo_identificacion,
    numero_identificacion: props.cliente.numero_identificacion ?? '',
    digito_verificacion:   props.cliente.digito_verificacion ?? '',
    datos_rues:            props.cliente.datos_rues ?? null,
    nombre: props.cliente.nombre,
    apellido: props.cliente.apellido ?? '',
    email: props.cliente.email ?? '',
    telefono: props.cliente.telefono ?? '',
    celular: props.cliente.celular ?? '',
    ciudad:              props.cliente.ciudad ?? '',
    direccion:           props.cliente.direccion ?? '',
    notas:               props.cliente.notas ?? '',
    tipos_contacto:      props.cliente.tipos_contacto ?? [],
    industrias:          props.cliente.industrias ?? [],
    intereses:           props.cliente.intereses ?? '',
    proceso_seguimiento: props.cliente.proceso_seguimiento ?? [],
    fuentes_contacto:    props.cliente.fuentes_contacto ?? [],
    contactos:           props.contactos.map(c => ({ ...c })),
})

const { hasChanges, setOriginal, checkChanges, markClean } = useUnsavedChanges()
onMounted(() => setOriginal(form.data()))
watch(() => form.data(), checkChanges, { deep: true })

const esPersona = computed(() => form.tipo === 'persona')

const tiposId = computed(() =>
    form.tipo === 'persona'
        ? ['CC', 'CE', 'PA']
        : ['NIT', 'RUT']
)

function setTipo(t) {
    form.tipo = t
    form.tipo_identificacion = t === 'persona' ? 'CC' : 'NIT'
    limpiar()
}

// ─── Consulta de identificación (DV, duplicados y RUES) ──────────────────────
const { consultando, resultado, error: errorConsulta, consultar, limpiar } = useConsultaIdentificacion()

function revisarIdentificacion() {
    // Al editar, ignoramos al propio cliente: si no, se avisaría a sí mismo
    // como duplicado.
    consultar(form.numero_identificacion, form.tipo_identificacion, props.cliente.id).then((r) => {
        if (!r) return
        // El DV es automático: si el usuario lo escribió pegado, lo separamos
        // y dejamos el campo solo con el número base.
        if (r.base) form.numero_identificacion = r.base
        form.digito_verificacion = r.dv ?? ''
    })
}

function usarDatosRues(rues) {
    form.nombre     = rues.razon_social
    form.datos_rues = rues
    // La ciudad no se llena: el registro publica la cámara de comercio, cuya
    // jurisdicción cubre varios municipios. Adivinarla sería peor que dejarla
    // en blanco.
}

function ic(extra = '') {
    return `w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm focus:ring-4 focus:ring-[var(--marca-suave)] focus:outline-none ${extra}`
}

// ─── Contactos ────────────────────────────────────────────────────────────────
const contactoEnBlanco = () => ({
    nombre: '', apellido: '', cargo: '', email: '',
    telefono: '', celular: '', notas: '', es_principal: false,
})
const nuevoContactoAbierto = ref(false)
const nuevoContacto = reactive(contactoEnBlanco())

function abrirNuevoContacto() {
    Object.assign(nuevoContacto, contactoEnBlanco())
    nuevoContactoAbierto.value = true
}

function agregarContacto() {
    if (!nuevoContacto.nombre.trim()) return
    const esElPrimero = form.contactos.length === 0
    form.contactos.push({
        ...nuevoContacto,
        es_principal: esElPrimero ? true : nuevoContacto.es_principal,
    })
    nuevoContactoAbierto.value = false
}

function setPrincipal(idx) {
    form.contactos.forEach((c, i) => { c.es_principal = i === idx })
}

function eliminarContacto(idx) {
    form.contactos.splice(idx, 1)
    if (form.contactos.length > 0 && !form.contactos.some(c => c.es_principal)) {
        form.contactos[0].es_principal = true
    }
}

function submit() {
    markClean()
    form.put(`/clientes/${props.cliente.id}`)
}

function eliminar() {
    if (confirm('¿Eliminar este cliente?')) {
        router.delete(`/clientes/${props.cliente.id}`)
    }
}

// ─── Documentos ───────────────────────────────────────────────────────────────
const archivoInput = ref(null)
const subiendoArchivo = ref(false)

function subirArchivo(event) {
    const file = event.target.files?.[0]
    if (!file) return
    subiendoArchivo.value = true
    const data = new FormData()
    data.append('archivo', file)
    data.append('categoria', 'documento')
    router.post(`/clientes/${props.cliente.id}/archivos`, data, {
        forceFormData: true,
        onFinish: () => {
            subiendoArchivo.value = false
            if (archivoInput.value) archivoInput.value.value = ''
        },
    })
}

function eliminarArchivo(id) {
    if (!confirm('¿Eliminar este documento?')) return
    router.delete(`/clientes/archivos/${id}`)
}
</script>

<template>
    <AppLayout :title="`Editar — ${cliente.nombre}`">
        <div class="max-w-3xl mx-auto px-4 py-4">

            <div v-if="hasChanges"
                class="mb-3 inline-flex items-center gap-2 px-3 py-1.5 rounded-xl text-xs font-semibold text-orange-700"
                style="background:var(--pastel-ambar); border:1px solid #F59E0B;">
                ● Cambios sin guardar
            </div>

            <!-- Cabecera -->
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-3">
                    <a :href="`/clientes/${cliente.id}`" class="text-tinta-400 hover:text-tinta-700">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M15 19l-7-7 7-7"/>
                        </svg>
                    </a>
                    <h1 class="text-xl font-semibold text-tinta-900">Editar cliente</h1>
                </div>
                <button @click="eliminar" class="px-3 py-1.5 rounded-lg text-xs font-medium text-red-600 border border-red-200 hover:bg-red-50">
                    Eliminar
                </button>
            </div>

            <form @submit.prevent="submit" class="space-y-4">

                <!-- Tipo de cliente -->
                <div class="bg-superficie rounded-xl border border-linea p-4">
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-3">Tipo de cliente</p>
                    <div class="grid grid-cols-2 gap-2">
                        <button type="button" @click="setTipo('empresa')"
                            :class="['rounded-lg border-2 px-4 py-2.5 text-sm font-medium transition-colors',
                                form.tipo === 'empresa' ? 'border-blue-600 bg-blue-50 text-blue-700' : 'border-linea text-tinta-500 hover:border-tinta-200']">
                            Empresa
                        </button>
                        <button type="button" @click="setTipo('persona')"
                            :class="['rounded-lg border-2 px-4 py-2.5 text-sm font-medium transition-colors',
                                form.tipo === 'persona' ? 'border-blue-600 bg-blue-50 text-blue-700' : 'border-linea text-tinta-500 hover:border-tinta-200']">
                            Persona natural
                        </button>
                    </div>
                </div>

                <!-- Identificación -->
                <div class="bg-superficie rounded-xl border border-linea p-4">
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-3">Identificación</p>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-tinta-700 mb-1">Tipo ID</label>
                            <select v-model="form.tipo_identificacion" :class="ic()">
                                <option v-for="t in tiposId" :key="t" :value="t">{{ t }}</option>
                            </select>
                            <p v-if="form.errors.tipo_identificacion" class="text-red-500 text-xs mt-1">{{ form.errors.tipo_identificacion }}</p>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-tinta-700 mb-1">Número</label>
                            <div class="relative">
                                <input v-model="form.numero_identificacion" type="text" :class="ic('pr-12')"
                                    placeholder="Ej: 900123456"
                                    @blur="revisarIdentificacion"
                                    @keyup.enter.prevent="revisarIdentificacion"/>
                                <!-- El DV no se escribe: se calcula y se muestra aquí -->
                                <span v-if="form.digito_verificacion"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-sm font-semibold text-tinta-300 pointer-events-none">
                                    &ndash;{{ form.digito_verificacion }}
                                </span>
                            </div>
                            <p class="text-xs text-tinta-300 mt-1">El dígito de verificación se calcula solo.</p>
                        </div>
                    </div>

                    <AvisoIdentificacion
                        :consultando="consultando"
                        :resultado="resultado"
                        :error="errorConsulta"
                        @usar-rues="usarDatosRues"/>
                </div>

                <!-- Nombre -->
                <div class="bg-superficie rounded-xl border border-linea p-4">
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-3">Datos personales / empresa</p>
                    <div :class="esPersona ? 'grid grid-cols-1 sm:grid-cols-2 gap-3' : ''">
                        <div>
                            <label class="block text-xs font-medium text-tinta-700 mb-1">
                                {{ esPersona ? 'Nombre *' : 'Razón social *' }}
                            </label>
                            <input v-model="form.nombre" type="text" :class="ic()" required/>
                            <p v-if="form.errors.nombre" class="text-red-500 text-xs mt-1">{{ form.errors.nombre }}</p>
                        </div>
                        <div v-if="esPersona">
                            <label class="block text-xs font-medium text-tinta-700 mb-1">Apellido</label>
                            <input v-model="form.apellido" type="text" :class="ic()"/>
                        </div>
                    </div>
                </div>

                <!-- Contacto persona -->
                <div v-if="esPersona" class="bg-superficie rounded-xl border border-linea p-4">
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-3">Contacto</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-tinta-700 mb-1">Email</label>
                            <input v-model="form.email" type="email" :class="ic()" placeholder="correo@email.com"/>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-tinta-700 mb-1">Celular</label>
                            <input v-model="form.celular" type="text" :class="ic()"/>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-tinta-700 mb-1">Teléfono fijo</label>
                            <input v-model="form.telefono" type="text" :class="ic()"/>
                        </div>
                    </div>
                    <p class="text-xs text-tinta-300 mt-3 italic">El contacto principal es el mismo titular.</p>
                </div>

                <!-- Contactos empresa -->
                <div v-if="!esPersona" class="bg-superficie rounded-xl border border-linea p-4">
                    <div class="flex items-center justify-between mb-3">
                        <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Contactos</p>
                        <button type="button" @click="abrirNuevoContacto"
                            class="text-xs font-medium px-3 py-1.5 rounded-lg text-white" style="background:var(--marca)">
                            + Agregar contacto
                        </button>
                    </div>

                    <p v-if="form.errors.contactos" class="text-red-500 text-xs mb-3 bg-red-50 px-3 py-2 rounded-lg">
                        {{ form.errors.contactos }}
                    </p>

                    <div v-if="form.contactos.length > 0" class="space-y-2 mb-4">
                        <div v-for="(c, idx) in form.contactos" :key="idx"
                             class="flex items-center gap-3 px-3 py-2.5 rounded-lg border"
                             :style="c.es_principal ? 'border-color:var(--marca); background:var(--pastel-azul);' : 'border-color:var(--borde);'">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <p class="text-sm font-medium text-tinta-900">{{ c.nombre }} {{ c.apellido }}</p>
                                    <span v-if="c.es_principal" class="text-xs px-1.5 py-0.5 rounded-full font-medium" style="background:var(--pastel-azul-2);color:var(--texto-azul);">Principal</span>
                                </div>
                                <p v-if="c.cargo" class="text-xs text-tinta-400">{{ c.cargo }}</p>
                                <p v-if="c.email || c.celular" class="text-xs text-tinta-300">{{ [c.email, c.celular].filter(Boolean).join(' · ') }}</p>
                            </div>
                            <div class="flex items-center gap-1 shrink-0">
                                <button v-if="!c.es_principal" type="button" @click="setPrincipal(idx)"
                                    class="text-xs px-2 py-1 rounded text-tinta-400 hover:text-blue-600 hover:bg-blue-50"
                                    title="Marcar como principal">★</button>
                                <button type="button" @click="eliminarContacto(idx)"
                                    class="text-xs px-2 py-1 rounded text-tinta-300 hover:text-red-600 hover:bg-red-50">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M6 18L18 6M6 6l12 12"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                    <p v-else class="text-sm text-tinta-300 text-center py-4 border border-dashed border-linea rounded-lg">
                        Sin contactos.
                    </p>

                    <!-- Formulario inline -->
                    <div v-if="nuevoContactoAbierto" class="mt-4 p-4 rounded-xl border-2 border-dashed" style="border-color:var(--marca); background:var(--pastel-azul);">
                        <p class="text-xs font-semibold mb-3" style="color:var(--marca);">Nuevo contacto</p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-tinta-700 mb-1">Nombre *</label>
                                <input v-model="nuevoContacto.nombre" type="text" :class="ic()"/>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-tinta-700 mb-1">Apellido</label>
                                <input v-model="nuevoContacto.apellido" type="text" :class="ic()"/>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-tinta-700 mb-1">Cargo</label>
                                <input v-model="nuevoContacto.cargo" type="text" :class="ic()"/>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-tinta-700 mb-1">Email</label>
                                <input v-model="nuevoContacto.email" type="email" :class="ic()"/>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-tinta-700 mb-1">Teléfono</label>
                                <input v-model="nuevoContacto.telefono" type="text" :class="ic()"/>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-tinta-700 mb-1">Celular</label>
                                <input v-model="nuevoContacto.celular" type="text" :class="ic()"/>
                            </div>
                            <div class="sm:col-span-2 flex items-center gap-2">
                                <input v-model="nuevoContacto.es_principal" type="checkbox" id="es_ppal_edit" class="rounded"/>
                                <label for="es_ppal_edit" class="text-xs text-tinta-700">Marcar como contacto principal</label>
                            </div>
                        </div>
                        <div class="flex gap-2 mt-3">
                            <button type="button" @click="nuevoContactoAbierto = false"
                                class="flex-1 py-2 rounded-lg border border-tinta-200 text-sm text-tinta-500">Cancelar</button>
                            <button type="button" @click="agregarContacto"
                                class="flex-1 py-2 rounded-lg text-sm font-medium text-white" style="background:var(--marca)">
                                Agregar
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Contacto empresa (datos empresa) -->
                <div v-if="!esPersona" class="bg-superficie rounded-xl border border-linea p-4">
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-3">Datos de contacto de la empresa</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-tinta-700 mb-1">Email empresa</label>
                            <input v-model="form.email" type="email" :class="ic()"/>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-tinta-700 mb-1">Celular empresa</label>
                            <input v-model="form.celular" type="text" :class="ic()"/>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-tinta-700 mb-1">Teléfono empresa</label>
                            <input v-model="form.telefono" type="text" :class="ic()"/>
                        </div>
                    </div>
                </div>

                <!-- Ubicación -->
                <div class="bg-superficie rounded-xl border border-linea p-4">
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-3">Ubicación</p>
                    <div v-if="sedes.length > 1" class="mb-3">
                        <label class="block text-xs font-medium text-tinta-700 mb-1">Sede</label>
                        <select v-model="form.sede_id" :class="ic()">
                            <option v-for="s in sedes" :key="s.id" :value="s.id">{{ s.nombre }}</option>
                        </select>
                        <p class="text-xs text-tinta-300 mt-1">
                            Cambiar la sede mueve el cliente: dejará de verse desde la sede actual.
                        </p>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-tinta-700 mb-1">Ciudad</label>
                            <input v-model="form.ciudad" type="text" :class="ic()"/>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-tinta-700 mb-1">Dirección</label>
                            <input v-model="form.direccion" type="text" :class="ic()"/>
                        </div>
                    </div>
                </div>

                <!-- Notas -->
                <div class="bg-superficie rounded-xl border border-linea p-4">
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-3">Notas</p>
                    <textarea v-model="form.notas" :class="ic()" rows="3"></textarea>
                </div>

                <!-- Segmentación -->
                <div class="bg-superficie rounded-xl border border-linea p-4 space-y-4">
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Segmentación</p>
                    <ChipsSegmentacion
                        label="Tipo de contacto"
                        :opciones="segmentacion_opciones.tipo_contacto ?? []"
                        v-model="form.tipos_contacto"
                    />
                    <ChipsSegmentacion
                        label="Industria"
                        :opciones="segmentacion_opciones.industria ?? []"
                        v-model="form.industrias"
                    />
                    <ChipsSegmentacion
                        label="Proceso de seguimiento"
                        :opciones="segmentacion_opciones.proceso_seguimiento ?? []"
                        v-model="form.proceso_seguimiento"
                    />
                    <ChipsSegmentacion
                        label="Fuente de contacto"
                        :opciones="segmentacion_opciones.fuente_contacto ?? []"
                        v-model="form.fuentes_contacto"
                    />
                    <div>
                        <label class="block text-xs font-medium text-tinta-700 mb-1">Intereses y observaciones</label>
                        <textarea v-model="form.intereses" :class="ic()" rows="3"
                            placeholder="Describe los intereses, necesidades o notas relevantes del cliente..."></textarea>
                    </div>
                </div>

                <!-- Documentos -->
                <div class="bg-superficie rounded-xl border border-linea p-4">
                    <div class="flex items-center justify-between mb-3">
                        <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Documentos</p>
                        <label class="flex items-center gap-1.5 text-xs font-medium px-3 py-1.5 rounded-lg text-white cursor-pointer"
                               style="background:var(--marca);"
                               :class="subiendoArchivo ? 'opacity-60 pointer-events-none' : ''">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                            </svg>
                            {{ subiendoArchivo ? 'Subiendo...' : 'Agregar' }}
                            <input ref="archivoInput" type="file" class="hidden" @change="subirArchivo" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png"/>
                        </label>
                    </div>
                    <div v-if="archivos.length > 0" class="space-y-2">
                        <div v-for="a in archivos" :key="a.id"
                             class="flex items-center gap-3 p-2.5 rounded-lg border border-linea">
                            <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0" style="background:var(--pastel-azul);">
                                <svg class="w-4 h-4" style="color:var(--marca);" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                                </svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <a :href="a.url" target="_blank" class="text-sm font-medium text-tinta-900 truncate hover:text-blue-600 block">{{ a.nombre_original }}</a>
                                <p class="text-xs text-tinta-300">{{ a.extension?.toUpperCase() }} · {{ a.tamano_formateado }}</p>
                            </div>
                            <button type="button" @click="eliminarArchivo(a.id)"
                                class="p-1.5 rounded-lg text-tinta-300 hover:text-red-500 hover:bg-red-50 shrink-0">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                    <p v-else class="text-sm text-tinta-300 text-center py-6 border border-dashed border-linea rounded-lg">
                        Sin documentos adjuntos.
                    </p>
                </div>

                <!-- Acciones -->
                <div class="flex gap-3 pb-4">
                    <a :href="`/clientes/${cliente.id}`" class="flex-1 text-center px-4 py-2.5 rounded-lg border border-tinta-200 text-sm font-medium text-tinta-700 hover:bg-tinta-50">
                        Cancelar
                    </a>
                    <button type="submit" :disabled="form.processing"
                        class="flex-1 px-4 py-2.5 rounded-lg text-sm font-medium text-white disabled:opacity-60"
                        style="background:var(--marca)">
                        {{ form.processing ? 'Guardando...' : 'Guardar cambios' }}
                    </button>
                </div>

            </form>
        </div>
    </AppLayout>
</template>
