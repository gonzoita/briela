<script setup>
/**
 * Crea un cliente sin salir de la cotización.
 *
 * Salirse a la pantalla de clientes con una cotización a medio armar es perder el
 * trabajo: los ítems, los precios calculados y las medidas del ensamble se quedan en la
 * pantalla que se abandonó.
 *
 * **La segmentación no es opcional aquí.** Sin tipo de contacto, la cotización no muestra
 * precios —es a propósito, para que nadie venda al precio de otro canal por error—, así
 * que un cliente creado sin ella deja al vendedor mirando una pantalla vacía sin entender
 * por qué. El modal lo pide y lo explica.
 *
 * Valida en el servidor con las mismas reglas que la pantalla completa: una versión
 * recortada es por donde se cuelan los clientes a medio llenar.
 */
import { ref, computed } from 'vue'
import ChipsSegmentacion from '@/Components/ChipsSegmentacion.vue'

const props = defineProps({
    // Las listas de segmentación, tal como las manda el servidor para la pantalla de
    // clientes: { tipo_contacto: [...], industria: [...], ... }
    segmentacion: { type: Object, default: () => ({}) },
    // Con qué nombre abrir el formulario: lo que el vendedor ya escribió en el buscador.
    nombreInicial: { type: String, default: '' },
})

const emit = defineEmits(['creado', 'cerrar'])

const guardando = ref(false)
const errores   = ref({})
const errorGeneral = ref('')

const form = ref({
    tipo: 'empresa',
    tipo_identificacion: 'NIT',
    numero_identificacion: '',
    nombre: props.nombreInicial ?? '',
    apellido: '',
    email: '',
    telefono: '',
    celular: '',
    ciudad: '',
    direccion: '',
    tipos_contacto: [],
    industrias: [],
    notas: '',
    // Una empresa sin contacto es un teléfono que nadie contesta: el servidor lo exige y
    // aquí se pide, en vez de dejar que el modal falle al guardar.
    contactos: [{ nombre: '', apellido: '', cargo: '', email: '', celular: '', es_principal: true }],
})

const esPersona = computed(() => form.value.tipo === 'persona')

/** Los tipos de contacto que definen precio, para poder advertir si no eligió ninguno. */
const canales = computed(() =>
    (props.segmentacion?.tipo_contacto ?? []).filter(o => o.define_precio)
)

const sinCanal = computed(() => {
    const elegidos = form.value.tipos_contacto ?? []

    return ! canales.value.some(c => elegidos.includes(c.valor))
})

const csrf = () => {
    const c = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))
    return c ? decodeURIComponent(c.split('=')[1]) : ''
}

async function guardar() {
    guardando.value = true
    errores.value = {}
    errorGeneral.value = ''

    try {
        const res = await fetch('/api/clientes', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': csrf(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify(form.value),
        })

        const data = await res.json().catch(() => null)

        if (res.status === 422) {
            errores.value = data?.errors ?? {}
            errorGeneral.value = data?.message ?? 'Revisa los campos marcados.'
            return
        }

        if (! res.ok || ! data?.ok) throw new Error(data?.message || `No se pudo crear (${res.status}).`)

        emit('creado', data.cliente)
    } catch (e) {
        errorGeneral.value = e.message
    } finally {
        guardando.value = false
    }
}

const claseCampo = (campo) => [
    'w-full rounded-xl border px-3 py-2 text-sm bg-superficie focus:outline-none',
    errores.value[campo] ? 'border-red-400' : 'border-linea focus:border-[var(--marca)]',
]
</script>

<template>
    <Teleport to="body">
        <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
            <div class="absolute inset-0 bg-black/40" @click="emit('cerrar')" />

            <div class="relative w-full sm:max-w-2xl bg-superficie rounded-t-3xl sm:rounded-2xl shadow-2xl flex flex-col max-h-[92vh]">
                <div class="flex items-center justify-between px-5 py-4 border-b border-linea shrink-0">
                    <div>
                        <h3 class="text-base font-semibold text-tinta-900">Cliente nuevo</h3>
                        <p class="text-xs text-tinta-400">Se crea y queda seleccionado en esta cotización.</p>
                    </div>
                    <button type="button" @click="emit('cerrar')"
                        class="w-8 h-8 flex items-center justify-center rounded-full text-tinta-300 hover:bg-tinta-100 text-lg">✕</button>
                </div>

                <div class="p-5 overflow-y-auto space-y-4">
                    <p v-if="errorGeneral" class="text-xs px-3 py-2 rounded-xl" style="background:#FEF2F2;color:#B91C1C;">
                        {{ errorGeneral }}
                    </p>

                    <!-- Persona o empresa -->
                    <div class="flex gap-2">
                        <button v-for="t in [{ v: 'empresa', l: 'Empresa' }, { v: 'persona', l: 'Persona' }]" :key="t.v"
                            type="button" @click="form.tipo = t.v"
                            class="flex-1 py-2 rounded-xl text-sm font-medium border transition-colors"
                            :class="form.tipo === t.v
                                ? 'text-white border-transparent'
                                : 'bg-superficie text-tinta-500 border-linea hover:bg-tinta-50'"
                            :style="form.tipo === t.v ? 'background:var(--marca);' : ''">
                            {{ t.l }}
                        </button>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <label class="block text-xs font-medium text-tinta-700 mb-1">
                                {{ esPersona ? 'Nombre' : 'Razón social' }} *
                            </label>
                            <input v-model="form.nombre" :class="claseCampo('nombre')" />
                            <p v-if="errores.nombre" class="mt-1 text-xs text-red-600">{{ errores.nombre[0] }}</p>
                        </div>
                        <div v-if="esPersona">
                            <label class="block text-xs font-medium text-tinta-700 mb-1">Apellido</label>
                            <input v-model="form.apellido" :class="claseCampo('apellido')" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-tinta-700 mb-1">Tipo de identificación</label>
                            <select v-model="form.tipo_identificacion" :class="claseCampo('tipo_identificacion')">
                                <option value="NIT">NIT</option>
                                <option value="CC">Cédula</option>
                                <option value="CE">Cédula de extranjería</option>
                                <option value="PA">Pasaporte</option>
                                <option value="RUT">RUT</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-tinta-700 mb-1">Número</label>
                            <input v-model="form.numero_identificacion" :class="claseCampo('numero_identificacion')" />
                            <p v-if="errores.numero_identificacion" class="mt-1 text-xs text-red-600">
                                {{ errores.numero_identificacion[0] }}
                            </p>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-tinta-700 mb-1">Correo</label>
                            <input v-model="form.email" type="email" :class="claseCampo('email')" />
                            <p v-if="errores.email" class="mt-1 text-xs text-red-600">{{ errores.email[0] }}</p>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-tinta-700 mb-1">Celular</label>
                            <input v-model="form.celular" :class="claseCampo('celular')" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-tinta-700 mb-1">Teléfono</label>
                            <input v-model="form.telefono" :class="claseCampo('telefono')" />
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-tinta-700 mb-1">Ciudad</label>
                            <input v-model="form.ciudad" :class="claseCampo('ciudad')" />
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-medium text-tinta-700 mb-1">Dirección</label>
                            <input v-model="form.direccion" :class="claseCampo('direccion')" />
                        </div>
                    </div>

                    <!-- Segmentación: de aquí salen los precios -->
                    <div class="rounded-xl p-3" style="background:var(--superficie-2);">
                        <ChipsSegmentacion
                            label="Tipo de cliente"
                            :opciones="segmentacion.tipo_contacto ?? []"
                            v-model="form.tipos_contacto"
                        />
                        <p v-if="sinCanal" class="mt-2 text-xs px-2 py-1.5 rounded-lg"
                            style="background:var(--pastel-ambar);color:var(--texto-ambar);">
                            Elige un tipo que defina precio. Sin eso, la cotización no va a mostrar
                            precios para este cliente — es a propósito, para que nadie venda al precio
                            de otro canal por error.
                        </p>

                        <div v-if="(segmentacion.industria ?? []).length" class="mt-3">
                            <ChipsSegmentacion
                                label="Industria"
                                :opciones="segmentacion.industria ?? []"
                                v-model="form.industrias"
                            />
                        </div>
                    </div>

                    <!-- Contacto: obligatorio para empresas. En una persona, el sistema lo
                         crea solo con sus propios datos. -->
                    <div v-if="!esPersona" class="rounded-xl border border-linea p-3">
                        <div class="flex items-baseline justify-between mb-2">
                            <p class="text-xs font-semibold text-tinta-700">Contacto principal *</p>
                            <span class="text-xs text-tinta-300">Con quién se habla en esa empresa</span>
                        </div>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <input v-model="form.contactos[0].nombre" placeholder="Nombre *"
                                :class="claseCampo('contactos')" />
                            <input v-model="form.contactos[0].cargo" placeholder="Cargo"
                                :class="claseCampo('contactos.0.cargo')" />
                            <input v-model="form.contactos[0].email" type="email" placeholder="Correo"
                                :class="claseCampo('contactos.0.email')" />
                            <input v-model="form.contactos[0].celular" placeholder="Celular"
                                :class="claseCampo('contactos.0.celular')" />
                        </div>
                        <p v-if="errores.contactos" class="mt-1 text-xs text-red-600">{{ errores.contactos[0] }}</p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-tinta-700 mb-1">Notas</label>
                        <textarea v-model="form.notas" rows="2" :class="claseCampo('notas')" />
                    </div>
                </div>

                <div class="flex gap-2 px-5 py-4 border-t border-linea shrink-0">
                    <button type="button" @click="emit('cerrar')"
                        class="px-4 py-2.5 rounded-xl border border-linea text-sm text-tinta-500">
                        Cancelar
                    </button>
                    <button type="button" @click="guardar" :disabled="guardando"
                        class="flex-1 py-2.5 rounded-xl text-white text-sm font-semibold disabled:opacity-50"
                        style="background:var(--marca);">
                        {{ guardando ? 'Creando…' : 'Crear y usar en esta cotización' }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
