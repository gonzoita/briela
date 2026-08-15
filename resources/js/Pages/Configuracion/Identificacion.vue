<script setup>
import { ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    rues:            { type: Object, default: () => ({}) },
    url_por_defecto: { type: String, default: '' },
})

const form = useForm({
    activo:  props.rues.activo ?? true,
    url:     props.rues.url ?? '',
    token:   props.rues.token ?? '',
    timeout: props.rues.timeout ?? 6,
})

function guardar() {
    form.post('/configuracion/identificacion', { preserveScroll: true })
}

function restaurarUrl() {
    form.url = props.url_por_defecto
}

// ─── Probar la conexión con un NIT real ──────────────────────────────────────
// Vacío a propósito: quien prueba escribe un NIT que conozca.
const nitPrueba = ref('')
const probando  = ref(false)
const prueba    = ref(null)

async function probar() {
    probando.value = true
    prueba.value   = null

    try {
        const resp = await fetch('/configuracion/identificacion/probar', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(
                    document.cookie.split('; ').find(c => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? ''
                ),
            },
            body: JSON.stringify({ nit: nitPrueba.value }),
        })
        prueba.value = await resp.json()
    } catch (e) {
        prueba.value = { ok: false, mensaje: 'No se pudo completar la prueba.' }
    } finally {
        probando.value = false
    }
}

function ic(extra = '') {
    return `w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm focus:ring-4 focus:ring-[var(--marca-suave)] focus:outline-none ${extra}`
}
</script>

<template>
    <AppLayout title="Identificación de clientes">
        <div class="max-w-2xl mx-auto space-y-4 pb-8">

            <!-- Volver -->
            <a href="/configuracion" @click.prevent="router.visit('/configuracion')"
                class="inline-flex items-center gap-1.5 text-sm text-tinta-400 hover:text-tinta-700">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                </svg>
                Configuración
            </a>

            <!-- Lo que siempre funciona -->
            <div class="bg-superficie rounded-xl border border-linea p-4">
                <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-3">
                    Siempre activo
                </p>
                <div class="space-y-3 text-sm">
                    <div class="flex gap-3">
                        <span class="mt-0.5 shrink-0 w-5 h-5 rounded-full bg-pastel-verde-2 flex items-center justify-center">
                            <svg class="w-3 h-3 text-aviso-verde" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                        </span>
                        <div>
                            <p class="font-medium text-tinta-900">Dígito de verificación del NIT</p>
                            <p class="text-xs text-tinta-400">
                                Se calcula solo y avisa si está mal escrito. Es matemática, no depende
                                de internet.
                            </p>
                        </div>
                    </div>
                    <div class="flex gap-3">
                        <span class="mt-0.5 shrink-0 w-5 h-5 rounded-full bg-pastel-verde-2 flex items-center justify-center">
                            <svg class="w-3 h-3 text-aviso-verde" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                        </span>
                        <div>
                            <p class="font-medium text-tinta-900">Aviso de clientes duplicados</p>
                            <p class="text-xs text-tinta-400">
                                Busca el número en todas las sedes antes de dejarte crear el cliente.
                            </p>
                        </div>
                    </div>
                </div>
                <p class="mt-3 text-xs text-tinta-300 border-t border-linea pt-3">
                    Estas dos no se pueden apagar porque no dependen de nada externo y no tienen
                    forma de fallar.
                </p>
            </div>

            <!-- RUES -->
            <form @submit.prevent="guardar" class="bg-superficie rounded-xl border border-linea p-4 space-y-4">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">
                            Consulta al registro mercantil
                        </p>
                        <p class="text-xs text-tinta-400 mt-1">
                            Trae la razón social, la cámara de comercio y el estado de la matrícula
                            a partir del NIT. Usa los datos abiertos que publica Confecámaras en
                            datos.gov.co: son oficiales, gratuitos y no piden credenciales.
                        </p>
                    </div>
                    <button type="button" @click="form.activo = !form.activo"
                        :class="['relative shrink-0 w-11 h-6 rounded-full transition-colors',
                                 form.activo ? 'bg-blue-600' : 'bg-gray-300']">
                        <span :class="['absolute top-0.5 w-5 h-5 rounded-full bg-superficie transition-all',
                                       form.activo ? 'left-[22px]' : 'left-0.5']"></span>
                    </button>
                </div>

                <div v-if="form.activo" class="space-y-3 pt-1">
                    <div>
                        <label class="block text-xs font-medium text-tinta-700 mb-1">Dirección del servicio</label>
                        <input v-model="form.url" type="text" :class="ic('font-mono text-xs')"/>
                        <div class="flex items-center justify-between mt-1">
                            <p class="text-xs text-tinta-300">
                                Cámbiala solo si el RUES deja de responder.
                            </p>
                            <button type="button" @click="restaurarUrl"
                                class="text-xs text-aviso-azul font-medium hover:underline shrink-0 ml-2">
                                Restaurar
                            </button>
                        </div>
                        <p v-if="form.errors.url" class="text-aviso-rojo text-xs mt-1">{{ form.errors.url }}</p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-tinta-700 mb-1">
                            Token de datos.gov.co (opcional)
                        </label>
                        <input v-model="form.token" type="text" :class="ic('font-mono text-xs')"
                            placeholder="Déjalo vacío: solo hace falta si consultas muchísimo"/>
                        <p class="text-xs text-tinta-300 mt-1">
                            Sin token también funciona. Solo sube el límite de consultas por hora,
                            y se saca gratis en evergreen.data.socrata.com.
                        </p>
                        <p v-if="form.errors.token" class="text-aviso-rojo text-xs mt-1">{{ form.errors.token }}</p>
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-tinta-700 mb-1">
                            Tiempo máximo de espera (segundos)
                        </label>
                        <input v-model.number="form.timeout" type="number" min="2" max="30" :class="ic('w-24')"/>
                        <p class="text-xs text-tinta-300 mt-1">
                            Pasado este tiempo se deja de esperar y el formulario sigue normal.
                        </p>
                        <p v-if="form.errors.timeout" class="text-aviso-rojo text-xs mt-1">{{ form.errors.timeout }}</p>
                    </div>
                </div>

                <p v-else class="text-xs text-tinta-400 bg-tinta-50 border border-linea rounded-lg px-3 py-2">
                    Apagado. El dígito de verificación y el aviso de duplicados siguen funcionando.
                </p>

                <button type="submit" :disabled="form.processing"
                    class="w-full sm:w-auto rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50">
                    {{ form.processing ? 'Guardando...' : 'Guardar' }}
                </button>
            </form>

            <!-- Probar -->
            <div v-if="form.activo" class="bg-superficie rounded-xl border border-linea p-4 space-y-3">
                <div>
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Probar</p>
                    <p class="text-xs text-tinta-400 mt-1">
                        Consulta un NIT de verdad, sin usar la caché, para ver si el servicio responde.
                    </p>
                </div>

                <div class="flex gap-2">
                    <input v-model="nitPrueba" type="text" :class="ic('flex-1')" placeholder="NIT sin DV"/>
                    <button type="button" @click="probar" :disabled="probando"
                        class="shrink-0 rounded-lg border border-tinta-200 px-4 py-2 text-sm font-semibold text-tinta-700 hover:bg-tinta-50 disabled:opacity-50">
                        {{ probando ? 'Probando...' : 'Probar' }}
                    </button>
                </div>

                <div v-if="prueba" class="rounded-lg border px-3 py-2.5 text-xs"
                    :class="prueba.ok
                        ? 'border-borde-aviso-verde bg-pastel-verde text-aviso-verde'
                        : 'border-borde-aviso-ambar bg-pastel-ambar text-aviso-ambar'">
                    <p class="font-semibold">{{ prueba.mensaje }}</p>
                    <p v-if="prueba.dv" class="mt-1">
                        Dígito de verificación calculado: <strong>{{ prueba.dv }}</strong>
                    </p>
                    <template v-if="prueba.rues">
                        <p class="mt-1 font-medium">{{ prueba.rues.razon_social }}</p>
                        <p v-if="prueba.rues.camara_comercio">
                            Cámara de Comercio de {{ prueba.rues.camara_comercio }}
                        </p>
                        <p v-if="prueba.rues.estado_matricula">
                            Matrícula {{ prueba.rues.estado_matricula }}
                        </p>
                    </template>
                    <p v-if="prueba.milisegundos" class="mt-1 opacity-70">
                        Respondió en {{ prueba.milisegundos }} ms
                    </p>
                </div>

                <p class="text-xs text-tinta-300">
                    Escribe un NIT que ya conozcas. Si la prueba con uno válido falla, el problema
                    es del servicio y no del número.
                </p>
            </div>

            <!-- Lo que el RUES nunca va a traer -->
            <div class="rounded-xl border border-linea bg-tinta-50 p-4">
                <p class="text-xs font-semibold text-tinta-500 mb-2">Ten en cuenta</p>
                <ul class="text-xs text-tinta-400 space-y-1.5 list-disc pl-4">
                    <li>El registro <strong>no publica correo, teléfono ni dirección</strong>. Eso siempre se escribe a mano.</li>
                    <li>Tampoco publica la ciudad, sino la <strong>cámara de comercio</strong>, cuya jurisdicción cubre varios municipios. Por eso no llenamos la ciudad automáticamente.</li>
                    <li>Las <strong>cédulas de personas naturales</strong> no se consultan: están protegidas por la Ley 1581 de Habeas Data y no hay fuente pública.</li>
                    <li>Los datos se actualizan <strong>cada mes</strong>. Una empresa creada hace pocos días puede no aparecer todavía.</li>
                    <li>Los resultados se guardan 30 días para no repetir consultas.</li>
                </ul>
            </div>
        </div>
    </AppLayout>
</template>
