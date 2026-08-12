<script setup>
import { reactive, computed, ref, onMounted, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ChipsSegmentacion from '@/Components/ChipsSegmentacion.vue'
import AvisoIdentificacion from '@/Components/AvisoIdentificacion.vue'
import { useUnsavedChanges } from '@/composables/useUnsavedChanges'
import { useConsultaIdentificacion } from '@/composables/useConsultaIdentificacion'

const props = defineProps({
    segmentacion_opciones: { type: Object, default: () => ({}) },
    sedes:                 { type: Array,  default: () => [] },
})

const form = useForm({
    sede_id: props.sedes[0]?.id ?? null,
    tipo: 'empresa',
    tipo_identificacion: 'NIT',
    numero_identificacion: '',
    digito_verificacion: '',
    datos_rues: null,
    nombre: '',
    apellido: '',
    email: '',
    telefono: '',
    celular: '',
    ciudad:              '',
    direccion:           '',
    notas:               '',
    tipos_contacto:      [],
    industrias:          [],
    intereses:           '',
    proceso_seguimiento: [],
    fuentes_contacto:    [],
    contactos:           [],
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
    consultar(form.numero_identificacion, form.tipo_identificacion).then((r) => {
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
    form.post('/clientes')
}
</script>

<template>
    <AppLayout title="Nuevo Cliente">
        <div class="max-w-3xl mx-auto px-4 py-4">

            <!-- Badge cambios sin guardar -->
            <div v-if="hasChanges"
                class="mb-3 inline-flex items-center gap-2 px-3 py-1.5 rounded-xl text-xs font-semibold text-orange-700"
                style="background:var(--pastel-ambar); border:1px solid #F59E0B;">
                ● Cambios sin guardar
            </div>

            <!-- Cabecera -->
            <div class="flex items-center gap-3 mb-4">
                <a href="/clientes" class="text-tinta-400 hover:text-tinta-700">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M15 19l-7-7 7-7"/>
                    </svg>
                </a>
                <h1 class="text-xl font-semibold text-tinta-900">Nuevo cliente</h1>
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
                            <p v-if="form.errors.numero_identificacion" class="text-red-500 text-xs mt-1">{{ form.errors.numero_identificacion }}</p>
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
                            <input v-model="form.nombre" type="text" :class="ic()" :placeholder="esPersona ? 'Nombre' : 'Razón social'" required/>
                            <p v-if="form.errors.nombre" class="text-red-500 text-xs mt-1">{{ form.errors.nombre }}</p>
                        </div>
                        <div v-if="esPersona">
                            <label class="block text-xs font-medium text-tinta-700 mb-1">Apellido</label>
                            <input v-model="form.apellido" type="text" :class="ic()" placeholder="Apellido"/>
                        </div>
                    </div>
                </div>

                <!-- Contacto directo (persona) -->
                <div v-if="esPersona" class="bg-superficie rounded-xl border border-linea p-4">
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-3">Contacto</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-tinta-700 mb-1">Email</label>
                            <input v-model="form.email" type="email" :class="ic()" placeholder="correo@email.com"/>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-tinta-700 mb-1">Celular</label>
                            <input v-model="form.celular" type="text" :class="ic()" placeholder="300 000 0000"/>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-tinta-700 mb-1">Teléfono fijo</label>
                            <input v-model="form.telefono" type="text" :class="ic()" placeholder="(601) 000-0000"/>
                        </div>
                    </div>
                    <p class="text-xs text-tinta-300 mt-3 italic">El contacto principal será el mismo titular.</p>
                </div>

                <!-- Contacto empresa -->
                <div v-if="!esPersona" class="bg-superficie rounded-xl border border-linea p-4">
                    <div class="flex items-center justify-between mb-3">
                        <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Contactos</p>
                        <button type="button" @click="abrirNuevoContacto"
                            class="text-xs font-medium px-3 py-1.5 rounded-lg text-white"
                            style="background:var(--marca)">
                            + Agregar contacto
                        </button>
                    </div>

                    <p v-if="form.errors.contactos" class="text-red-500 text-xs mb-3 bg-red-50 px-3 py-2 rounded-lg">
                        {{ form.errors.contactos }}
                    </p>

                    <!-- Lista de contactos agregados -->
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
                        Sin contactos. Debe agregar al menos uno para empresas.
                    </p>

                    <!-- Formulario inline nuevo contacto -->
                    <div v-if="nuevoContactoAbierto" class="mt-4 p-4 rounded-xl border-2 border-dashed" style="border-color:var(--marca); background:var(--pastel-azul);">
                        <p class="text-xs font-semibold mb-3" style="color:var(--marca);">Nuevo contacto</p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-tinta-700 mb-1">Nombre *</label>
                                <input v-model="nuevoContacto.nombre" type="text" :class="ic()" placeholder="Nombre"/>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-tinta-700 mb-1">Apellido</label>
                                <input v-model="nuevoContacto.apellido" type="text" :class="ic()" placeholder="Apellido"/>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-tinta-700 mb-1">Cargo</label>
                                <input v-model="nuevoContacto.cargo" type="text" :class="ic()" placeholder="Gerente, Compras..."/>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-tinta-700 mb-1">Email</label>
                                <input v-model="nuevoContacto.email" type="email" :class="ic()" placeholder="correo@empresa.com"/>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-tinta-700 mb-1">Teléfono</label>
                                <input v-model="nuevoContacto.telefono" type="text" :class="ic()" placeholder="(601) 000-0000"/>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-tinta-700 mb-1">Celular</label>
                                <input v-model="nuevoContacto.celular" type="text" :class="ic()" placeholder="300 000 0000"/>
                            </div>
                            <div class="sm:col-span-2">
                                <label class="block text-xs font-medium text-tinta-700 mb-1">Notas</label>
                                <textarea v-model="nuevoContacto.notas" :class="ic()" rows="2" placeholder="Observaciones..."></textarea>
                            </div>
                            <div class="sm:col-span-2 flex items-center gap-2">
                                <input v-model="nuevoContacto.es_principal" type="checkbox" id="es_principal_nuevo" class="rounded"/>
                                <label for="es_principal_nuevo" class="text-xs text-tinta-700">Marcar como contacto principal</label>
                            </div>
                        </div>
                        <div class="flex gap-2 mt-3">
                            <button type="button" @click="nuevoContactoAbierto = false"
                                class="flex-1 py-2 rounded-lg border border-tinta-200 text-sm text-tinta-500">
                                Cancelar
                            </button>
                            <button type="button" @click="agregarContacto"
                                class="flex-1 py-2 rounded-lg text-sm font-medium text-white"
                                style="background:var(--marca)">
                                Agregar
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Contacto empresa (continuación — email, tel) -->
                <div v-if="!esPersona" class="bg-superficie rounded-xl border border-linea p-4">
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-3">Datos de contacto de la empresa</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-tinta-700 mb-1">Email empresa</label>
                            <input v-model="form.email" type="email" :class="ic()" placeholder="info@empresa.com"/>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-tinta-700 mb-1">Celular empresa</label>
                            <input v-model="form.celular" type="text" :class="ic()" placeholder="300 000 0000"/>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-tinta-700 mb-1">Teléfono empresa</label>
                            <input v-model="form.telefono" type="text" :class="ic()" placeholder="(601) 000-0000"/>
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
                        <p class="text-xs text-tinta-300 mt-1">Sede a la que pertenece este cliente.</p>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-tinta-700 mb-1">Ciudad</label>
                            <input v-model="form.ciudad" type="text" :class="ic()" placeholder="Bogotá"/>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-tinta-700 mb-1">Dirección</label>
                            <input v-model="form.direccion" type="text" :class="ic()" placeholder="Calle 00 # 00-00"/>
                        </div>
                    </div>
                </div>

                <!-- Notas -->
                <div class="bg-superficie rounded-xl border border-linea p-4">
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-3">Notas</p>
                    <textarea v-model="form.notas" :class="ic()" rows="3" placeholder="Observaciones internas..."></textarea>
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

                <!-- Acciones -->
                <div class="flex gap-3 pb-4">
                    <a href="/clientes" class="flex-1 text-center px-4 py-2.5 rounded-lg border border-tinta-200 text-sm font-medium text-tinta-700 hover:bg-tinta-50">
                        Cancelar
                    </a>
                    <button type="submit" :disabled="form.processing"
                        class="flex-1 px-4 py-2.5 rounded-lg text-sm font-medium text-white disabled:opacity-60"
                        style="background:var(--marca)">
                        {{ form.processing ? 'Guardando...' : 'Crear cliente' }}
                    </button>
                </div>

            </form>
        </div>
    </AppLayout>
</template>
