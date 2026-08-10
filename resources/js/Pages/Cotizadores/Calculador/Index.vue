<script setup>
import { ref, reactive } from 'vue'
import { useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useCalculadorPuertas, TIPOS_PUERTA, redondearCincoMil } from '@/composables/useCalculadorPuertas.js'

const props = defineProps({
    insumos:         Array,
    configuraciones: Array,
})

const params = reactive({
    tipo_puerta:    'BATIENTE_SIMPLE',
    tipo_sello:     'SUELO',
    temperatura:    'MEDIA',
    ancho_vano:     1.00,
    alto_vano:      2.20,
    tipo_corredera: 'SE12',
    sin_llave:      false,
    palanca:        false,
    visor:          false,
})

const {
    esDoble, esCorredera, esVaiven, esInstEmerg, esBatiente,
    columnaActiva, errores, desglose, subtotalComun, versionesLamina,
} = useCalculadorPuertas(params, props.insumos)

const tiposPuerta = TIPOS_PUERTA

const formatCOP = (v) =>
    new Intl.NumberFormat('es-CO', { maximumFractionDigits: 0 }).format(Math.round(v))

const modalGuardar = ref(false)
const nombreConfig  = ref('')
const versionSeleccionada = ref(0)

const formGuardar = useForm({
    nombre:            '',
    tipo_puerta:       '',
    tipo_sello:        '',
    temperatura:       '',
    ancho_vano:        0,
    alto_vano:         0,
    tipo_corredera:    null,
    sin_llave:         false,
    palanca:           false,
    visor:             false,
    desglose:          [],
    precios_resultado: [],
})

function guardarConfig() {
    formGuardar.nombre            = nombreConfig.value
    formGuardar.tipo_puerta       = params.tipo_puerta
    formGuardar.tipo_sello        = params.tipo_sello
    formGuardar.temperatura       = params.temperatura
    formGuardar.ancho_vano        = params.ancho_vano
    formGuardar.alto_vano         = params.alto_vano
    formGuardar.tipo_corredera    = esCorredera.value ? params.tipo_corredera : null
    formGuardar.sin_llave         = params.sin_llave
    formGuardar.palanca           = params.palanca
    formGuardar.visor             = params.visor
    formGuardar.desglose          = desglose.value
    formGuardar.precios_resultado = versionesLamina.value
    formGuardar.post('/cotizadores/calculador', {
        onSuccess: () => { modalGuardar.value = false; nombreConfig.value = '' },
    })
}
</script>

<template>
    <AppLayout title="Calculador de Puertas">
        <div class="max-w-7xl mx-auto">

            <div class="lg:grid lg:grid-cols-2 lg:gap-6">

                <!-- Columna izquierda: Parametros -->
                <div class="space-y-4 mb-6 lg:mb-0">

                    <div class="bg-superficie rounded-2xl border border-linea p-5">
                        <h2 class="text-sm font-semibold text-tinta-700 mb-4">Parámetros de la puerta</h2>

                        <!-- Tipo de puerta -->
                        <div class="mb-4">
                            <label class="block text-xs font-medium text-tinta-500 mb-1.5">Tipo de puerta</label>
                            <select v-model="params.tipo_puerta"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:ring-2 focus:outline-none">
                                <option v-for="t in tiposPuerta" :key="t.value" :value="t.value">{{ t.label }}</option>
                            </select>
                        </div>

                        <!-- Dimensiones -->
                        <div class="grid grid-cols-2 gap-3 mb-4">
                            <div>
                                <label class="block text-xs font-medium text-tinta-500 mb-1.5">Ancho vano (m)</label>
                                <input v-model.number="params.ancho_vano" type="number" step="0.01" min="0.3" max="3"
                                    class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:ring-2 focus:outline-none"/>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-tinta-500 mb-1.5">Alto vano (m)</label>
                                <input v-model.number="params.alto_vano" type="number" step="0.01" min="0.5" max="3"
                                    class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:ring-2 focus:outline-none"/>
                            </div>
                        </div>

                        <!-- Tipo de sello (no aplica Vaiven/Inst/Emerg) -->
                        <div v-if="!esVaiven && !esInstEmerg" class="mb-4">
                            <label class="block text-xs font-medium text-tinta-500 mb-1.5">Tipo de sello</label>
                            <div class="grid grid-cols-2 gap-2">
                                <button type="button" @click="params.tipo_sello = 'SUELO'"
                                    :class="['rounded-xl border-2 px-3 py-2 text-sm font-medium transition-colors',
                                        params.tipo_sello === 'SUELO' ? 'border-blue-600 bg-blue-50 text-blue-700' : 'border-linea text-tinta-500']">
                                    Sello al Suelo
                                </button>
                                <button type="button" @click="params.tipo_sello = 'TOPE'"
                                    :class="['rounded-xl border-2 px-3 py-2 text-sm font-medium transition-colors',
                                        params.tipo_sello === 'TOPE' ? 'border-blue-600 bg-blue-50 text-blue-700' : 'border-linea text-tinta-500']">
                                    Sello a Tope
                                </button>
                            </div>
                        </div>

                        <!-- Tipo corredera -->
                        <div v-if="esCorredera" class="mb-4">
                            <label class="block text-xs font-medium text-tinta-500 mb-1.5">Sistema corredizo</label>
                            <div class="grid grid-cols-3 gap-2">
                                <button v-for="s in ['SE12','SM20','480']" :key="s" type="button"
                                    @click="params.tipo_corredera = s"
                                    :class="['rounded-xl border-2 px-3 py-2 text-sm font-medium transition-colors',
                                        params.tipo_corredera === s ? 'border-blue-600 bg-blue-50 text-blue-700' : 'border-linea text-tinta-500']">
                                    {{ s }}
                                </button>
                            </div>
                        </div>

                        <!-- Temperatura -->
                        <div class="mb-4">
                            <label class="block text-xs font-medium text-tinta-500 mb-1.5">Temperatura</label>
                            <div class="grid grid-cols-2 gap-2">
                                <button type="button" @click="params.temperatura = 'MEDIA'"
                                    :class="['rounded-xl border-2 px-3 py-2 text-sm font-medium transition-colors',
                                        params.temperatura === 'MEDIA' ? 'border-blue-600 bg-blue-50 text-blue-700' : 'border-linea text-tinta-500']">
                                    Media
                                </button>
                                <button type="button"
                                    @click="params.tipo_puerta !== 'BATIENTE_DOBLE' ? params.temperatura = 'BAJA' : null"
                                    :class="['rounded-xl border-2 px-3 py-2 text-sm font-medium transition-colors',
                                        params.temperatura === 'BAJA' ? 'border-blue-600 bg-blue-50 text-blue-700' : 'border-linea text-tinta-500',
                                        params.tipo_puerta === 'BATIENTE_DOBLE' ? 'opacity-40 cursor-not-allowed' : '']">
                                    Baja
                                </button>
                            </div>
                        </div>

                        <!-- Opcionales -->
                        <div class="space-y-2">
                            <label class="block text-xs font-medium text-tinta-500 mb-1">Opcionales</label>
                            <label v-if="!esCorredera && !esInstEmerg" class="flex items-center gap-3 cursor-pointer">
                                <input v-model="params.sin_llave" type="checkbox" class="rounded w-4 h-4"/>
                                <span class="text-sm text-tinta-700">Sin llave (sin cerradura)</span>
                            </label>
                            <label class="flex items-center gap-3 cursor-pointer">
                                <input v-model="params.palanca" type="checkbox" class="rounded w-4 h-4"/>
                                <span class="text-sm text-tinta-700">Palanca de mano 8100 (+$440.000)</span>
                            </label>
                            <label v-if="!esInstEmerg && params.temperatura === 'MEDIA'" class="flex items-center gap-3 cursor-pointer">
                                <input v-model="params.visor" type="checkbox" class="rounded w-4 h-4"/>
                                <span class="text-sm text-tinta-700">Visor 80mm (+$105.000)</span>
                            </label>
                            <p v-if="esInstEmerg" class="text-xs text-tinta-300 italic">Incluye Visor 40mm de serie.</p>
                        </div>
                    </div>

                    <!-- Errores de validacion -->
                    <div v-if="errores.length > 0" class="bg-red-50 border border-red-200 rounded-xl p-4">
                        <p v-for="e in errores" :key="e" class="text-sm text-red-600">&#9888; {{ e }}</p>
                    </div>

                    <!-- Boton guardar -->
                    <button v-if="errores.length === 0" @click="modalGuardar = true"
                        class="w-full py-3 rounded-xl text-sm font-medium text-white"
                        style="background:var(--marca)">
                        Guardar configuración
                    </button>
                </div>

                <!-- Columna derecha: Resultado -->
                <div class="space-y-4">

                    <!-- Desglose de componentes -->
                    <div class="bg-superficie rounded-2xl border border-linea overflow-hidden">
                        <div class="px-5 py-3 border-b border-linea flex items-center justify-between">
                            <h2 class="text-sm font-semibold text-tinta-700">Desglose de componentes</h2>
                            <span class="text-xs text-tinta-300">Columna activa: <strong>{{ columnaActiva }}</strong></span>
                        </div>
                        <div v-if="errores.length > 0" class="px-5 py-8 text-center text-tinta-300 text-sm">
                            Corrige los errores para ver el desglose.
                        </div>
                        <div v-else-if="desglose.length === 0" class="px-5 py-8 text-center text-tinta-300 text-sm">
                            Sin componentes para este tipo.
                        </div>
                        <div v-else class="overflow-x-auto">
                            <table class="w-full text-xs">
                                <thead>
                                    <tr class="bg-tinta-50 border-b border-linea">
                                        <th class="text-left px-4 py-2 font-semibold text-tinta-400 uppercase">Insumo</th>
                                        <th class="text-center px-2 py-2 font-semibold text-tinta-400 uppercase w-10">Ud.</th>
                                        <th class="text-right px-3 py-2 font-semibold text-tinta-400 uppercase w-16">Cant.</th>
                                        <th class="text-right px-4 py-2 font-semibold text-tinta-400 uppercase w-24">Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-50">
                                    <tr v-for="linea in desglose" :key="linea.id">
                                        <td class="px-4 py-2 text-tinta-700">{{ linea.nombre }}</td>
                                        <td class="px-2 py-2 text-center text-tinta-300 font-mono">{{ linea.unidad }}</td>
                                        <td class="px-3 py-2 text-right text-tinta-500">{{ linea.cantidad }}</td>
                                        <td class="px-4 py-2 text-right font-semibold text-tinta-900">${{ formatCOP(linea.subtotal) }}</td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr class="border-t-2 border-linea">
                                        <td colspan="3" class="px-4 py-3 text-xs font-semibold text-tinta-400 text-right">Subtotal común (sin lámina):</td>
                                        <td class="px-4 py-3 text-right font-semibold text-sm" style="color:var(--marca)">${{ formatCOP(subtotalComun) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <!-- Tabla de precios por version de lamina -->
                    <div v-if="errores.length === 0" class="bg-superficie rounded-2xl border border-linea overflow-hidden">
                        <div class="px-5 py-3 border-b border-linea">
                            <h2 class="text-sm font-semibold text-tinta-700">Precios por tipo de lámina</h2>
                            <p class="text-xs text-tinta-300 mt-0.5">Márgenes: Mayorista 30% · Distribuidor 32.5% · Cliente 35%</p>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-xs">
                                <thead>
                                    <tr class="bg-tinta-50 border-b border-linea">
                                        <th class="text-left px-4 py-2 font-semibold text-tinta-400 uppercase">Lámina</th>
                                        <th class="text-right px-3 py-2 font-semibold text-tinta-400 uppercase">Costo</th>
                                        <th class="text-right px-3 py-2 font-semibold text-tinta-400 uppercase">Mayorista</th>
                                        <th class="text-right px-3 py-2 font-semibold text-tinta-400 uppercase">Distribuidor</th>
                                        <th class="text-right px-4 py-2 font-semibold text-tinta-400 uppercase">Cliente</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-50">
                                    <tr v-for="(v, idx) in versionesLamina" :key="idx"
                                        :class="['cursor-pointer transition-colors', versionSeleccionada === idx ? '' : 'hover:bg-tinta-50']"
                                        :style="versionSeleccionada === idx ? 'background:#EFF6FF;' : ''"
                                        @click="versionSeleccionada = idx">
                                        <td class="px-4 py-2.5 font-medium text-tinta-700">{{ v.label }}</td>
                                        <td class="px-3 py-2.5 text-right text-tinta-400">${{ formatCOP(v.costo) }}</td>
                                        <td class="px-3 py-2.5 text-right text-tinta-500">${{ formatCOP(v.mayorista) }}</td>
                                        <td class="px-3 py-2.5 text-right text-tinta-500">${{ formatCOP(v.distribuidor) }}</td>
                                        <td class="px-4 py-2.5 text-right font-semibold" style="color:var(--marca)">${{ formatCOP(v.clienteFinal) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- Modal guardar -->
        <Teleport to="body">
            <div v-if="modalGuardar" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-4" style="background:rgba(0,0,0,0.5);">
                <div class="bg-superficie rounded-2xl shadow-xl w-full max-w-sm p-5">
                    <h3 class="text-base font-semibold text-tinta-900 mb-4">Guardar configuración</h3>
                    <div>
                        <label class="block text-xs font-medium text-tinta-700 mb-1">Nombre de la configuración *</label>
                        <input v-model="nombreConfig" type="text"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:ring-2 focus:outline-none"
                            placeholder="Ej: Puerta fría almacén mediano"/>
                    </div>
                    <div class="flex gap-3 mt-5">
                        <button @click="modalGuardar = false"
                            class="flex-1 py-2.5 rounded-xl border border-linea text-sm text-tinta-500">Cancelar</button>
                        <button @click="guardarConfig" :disabled="!nombreConfig || formGuardar.processing"
                            class="flex-1 py-2.5 rounded-xl text-white text-sm font-medium disabled:opacity-60"
                            style="background:var(--marca)">
                            Guardar
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
