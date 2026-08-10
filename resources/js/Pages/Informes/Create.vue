<script setup>
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    metadatos: Object,
})

// ─── Stepper ────────────────────────────────────────────────────────────────
const paso = ref(1)
const pasos = ['Fuente', 'Campos', 'Filtros', 'Visualización']

// ─── Estado del formulario ───────────────────────────────────────────────────
const fuente       = ref('')
const camposSelec  = ref([])
const filtrosValor = ref({})
const nombre       = ref('')
const descripcion  = ref('')
const tipoGrafica  = ref('ninguna')
const publico      = ref(false)
const enviando     = ref(false)

// ─── Fuentes disponibles ─────────────────────────────────────────────────────
const fuentes = [
    {
        key: 'ops',
        label: 'Órdenes de Producción',
        desc: 'Analiza el estado y avance de las OPs',
        color: 'border-blue-200 bg-blue-50',
        colorActivo: 'border-blue-500 bg-blue-50',
        icon: 'clipboard',
    },
    {
        key: 'colaboradores',
        label: 'Colaboradores',
        desc: 'Puntos, niveles y tipos de colaboradores',
        color: 'border-green-200 bg-green-50',
        colorActivo: 'border-green-500 bg-green-50',
        icon: 'workers',
    },
    {
        key: 'cotizaciones',
        label: 'Cotizaciones',
        desc: 'Cotizaciones por estado, vendedor y total',
        color: 'border-orange-200 bg-orange-50',
        colorActivo: 'border-orange-500 bg-orange-50',
        icon: 'cotizacion',
    },
    {
        key: 'pasos',
        label: 'Pasos de Trabajo',
        desc: 'Pasos programados por estación y colaborador',
        color: 'border-purple-200 bg-purple-50',
        colorActivo: 'border-purple-500 bg-purple-50',
        icon: 'trabajos',
    },
]

const camposDisponibles = computed(() =>
    fuente.value ? (props.metadatos[fuente.value]?.campos ?? []) : []
)

const filtrosDisponibles = computed(() =>
    fuente.value ? (props.metadatos[fuente.value]?.filtros ?? []) : []
)

// ─── Navegación del stepper ──────────────────────────────────────────────────
function seleccionarFuente(key) {
    fuente.value      = key
    camposSelec.value = []
    filtrosValor.value = {}
}

function siguientePaso() {
    if (paso.value === 1 && !fuente.value) return
    if (paso.value === 2 && !camposSelec.value.length) return
    paso.value++
}

function anteriorPaso() {
    if (paso.value > 1) paso.value--
}

function toggleCampo(key) {
    const idx = camposSelec.value.indexOf(key)
    if (idx === -1) camposSelec.value.push(key)
    else camposSelec.value.splice(idx, 1)
}

// ─── Envío ───────────────────────────────────────────────────────────────────
function guardar() {
    if (!nombre.value.trim()) return
    enviando.value = true

    const filtrosLimpios = {}
    for (const [k, v] of Object.entries(filtrosValor.value)) {
        if (v !== '' && v !== null && v !== undefined) filtrosLimpios[k] = v
    }

    router.post('/informes', {
        nombre:       nombre.value,
        descripcion:  descripcion.value,
        fuente:       fuente.value,
        campos:       camposSelec.value,
        filtros:      filtrosLimpios,
        tipo_grafica: tipoGrafica.value || 'ninguna',
        publico:      publico.value,
    }, {
        onFinish: () => { enviando.value = false },
    })
}
</script>

<template>
    <AppLayout title="Nuevo Informe">
        <div class="max-w-2xl mx-auto">

            <!-- Stepper visual -->
            <div class="flex items-center gap-0 mb-8">
                <template v-for="(label, i) in pasos" :key="i">
                    <div class="flex flex-col items-center">
                        <div
                            class="w-8 h-8 rounded-full flex items-center justify-center text-sm font-semibold transition-colors"
                            :class="i + 1 === paso
                                ? 'text-white'
                                : i + 1 < paso
                                    ? 'bg-green-500 text-white'
                                    : 'bg-tinta-200 text-tinta-300'"
                            :style="i + 1 === paso ? 'background-color:var(--marca)' : ''"
                        >
                            <svg v-if="i + 1 < paso" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                            </svg>
                            <span v-else>{{ i + 1 }}</span>
                        </div>
                        <span class="text-xs mt-1 font-medium hidden sm:block"
                            :class="i + 1 === paso ? 'text-tinta-900' : 'text-tinta-300'">
                            {{ label }}
                        </span>
                    </div>
                    <div v-if="i < pasos.length - 1"
                        class="flex-1 h-0.5 mx-1 mb-4"
                        :class="i + 1 < paso ? 'bg-green-400' : 'bg-tinta-200'"
                    />
                </template>
            </div>

            <!-- ─── PASO 1: Fuente ─────────────────────────────────────────── -->
            <div v-if="paso === 1">
                <h2 class="text-lg font-semibold text-tinta-900 mb-1">Selecciona la fuente de datos</h2>
                <p class="text-sm text-tinta-400 mb-5">¿Sobre qué datos quieres generar el informe?</p>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <button
                        v-for="f in fuentes"
                        :key="f.key"
                        @click="seleccionarFuente(f.key)"
                        class="flex items-start gap-4 p-4 rounded-2xl border-2 text-left transition-all"
                        :class="fuente === f.key ? f.colorActivo + ' border-2' : 'border-linea bg-superficie hover:border-tinta-200'"
                    >
                        <!-- Ícono -->
                        <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0"
                            :style="fuente === f.key ? 'background-color:var(--marca)' : 'background:#F3F4F6'">
                            <!-- clipboard -->
                            <svg v-if="f.icon === 'clipboard'" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"
                                :style="fuente === f.key ? 'color:white' : 'color:#6B7280'">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
                            </svg>
                            <!-- workers -->
                            <svg v-if="f.icon === 'workers'" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"
                                :style="fuente === f.key ? 'color:white' : 'color:#6B7280'">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                            </svg>
                            <!-- cotizacion -->
                            <svg v-if="f.icon === 'cotizacion'" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"
                                :style="fuente === f.key ? 'color:white' : 'color:#6B7280'">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                            <!-- trabajos -->
                            <svg v-if="f.icon === 'trabajos'" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"
                                :style="fuente === f.key ? 'color:white' : 'color:#6B7280'">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4"/>
                            </svg>
                        </div>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-tinta-900">{{ f.label }}</p>
                            <p class="text-xs text-tinta-400 mt-0.5">{{ f.desc }}</p>
                        </div>
                    </button>
                </div>

                <div class="flex justify-end mt-6">
                    <button
                        @click="siguientePaso"
                        :disabled="!fuente"
                        class="px-6 py-2.5 rounded-xl text-white text-sm font-semibold disabled:opacity-40 transition-opacity"
                        style="background-color:var(--marca);"
                    >
                        Siguiente
                    </button>
                </div>
            </div>

            <!-- ─── PASO 2: Campos ─────────────────────────────────────────── -->
            <div v-if="paso === 2">
                <h2 class="text-lg font-semibold text-tinta-900 mb-1">Selecciona los campos</h2>
                <p class="text-sm text-tinta-400 mb-5">Elige al menos un campo para mostrar en el informe.</p>

                <div class="space-y-2">
                    <label
                        v-for="campo in camposDisponibles"
                        :key="campo.key"
                        class="flex items-center gap-3 p-3.5 rounded-xl border cursor-pointer transition-colors"
                        :class="camposSelec.includes(campo.key)
                            ? 'border-blue-300 bg-blue-50'
                            : 'border-linea bg-superficie hover:border-tinta-200'"
                    >
                        <input
                            type="checkbox"
                            :value="campo.key"
                            :checked="camposSelec.includes(campo.key)"
                            @change="toggleCampo(campo.key)"
                            class="w-4 h-4 rounded"
                            :style="camposSelec.includes(campo.key) ? 'accent-color:var(--marca)' : ''"
                        />
                        <span class="text-sm font-medium text-tinta-900">{{ campo.label }}</span>
                    </label>
                </div>

                <p v-if="!camposSelec.length" class="text-xs text-red-500 mt-2">Selecciona al menos un campo.</p>

                <div class="flex items-center justify-between mt-6">
                    <button @click="anteriorPaso" class="px-5 py-2.5 rounded-xl text-sm font-medium text-tinta-500 hover:bg-tinta-100">
                        Atrás
                    </button>
                    <button
                        @click="siguientePaso"
                        :disabled="!camposSelec.length"
                        class="px-6 py-2.5 rounded-xl text-white text-sm font-semibold disabled:opacity-40"
                        style="background-color:var(--marca);"
                    >
                        Siguiente
                    </button>
                </div>
            </div>

            <!-- ─── PASO 3: Filtros ────────────────────────────────────────── -->
            <div v-if="paso === 3">
                <h2 class="text-lg font-semibold text-tinta-900 mb-1">Configura los filtros</h2>
                <p class="text-sm text-tinta-400 mb-5">Todos los filtros son opcionales.</p>

                <div v-if="filtrosDisponibles.length" class="space-y-4">
                    <div v-for="filtro in filtrosDisponibles" :key="filtro.key">
                        <label class="block text-xs font-semibold text-tinta-500 mb-1.5 uppercase tracking-wide">
                            {{ filtro.label }}
                        </label>

                        <!-- Select -->
                        <select
                            v-if="filtro.tipo === 'select'"
                            v-model="filtrosValor[filtro.key]"
                            class="w-full rounded-xl border border-linea bg-superficie px-3 py-2.5 text-sm text-tinta-900 focus:outline-none focus:border-[var(--marca)]"
                        >
                            <option value="">— Todos —</option>
                            <template v-if="Array.isArray(filtro.opciones)">
                                <option v-for="op in filtro.opciones" :key="op" :value="op">{{ op }}</option>
                            </template>
                            <template v-else>
                                <option v-for="(label, val) in filtro.opciones" :key="val" :value="val">{{ label }}</option>
                            </template>
                        </select>

                        <!-- Date -->
                        <input
                            v-else-if="filtro.tipo === 'date'"
                            type="date"
                            v-model="filtrosValor[filtro.key]"
                            class="w-full rounded-xl border border-linea bg-superficie px-3 py-2.5 text-sm text-tinta-900 focus:outline-none focus:border-[var(--marca)]"
                        />

                        <!-- Text -->
                        <input
                            v-else
                            type="text"
                            v-model="filtrosValor[filtro.key]"
                            :placeholder="'Filtrar por ' + filtro.label.toLowerCase()"
                            class="w-full rounded-xl border border-linea bg-superficie px-3 py-2.5 text-sm text-tinta-900 focus:outline-none focus:border-[var(--marca)]"
                        />
                    </div>
                </div>

                <p v-else class="text-sm text-tinta-300 py-4">No hay filtros disponibles para esta fuente.</p>

                <div class="flex items-center justify-between mt-6">
                    <button @click="anteriorPaso" class="px-5 py-2.5 rounded-xl text-sm font-medium text-tinta-500 hover:bg-tinta-100">
                        Atrás
                    </button>
                    <button
                        @click="siguientePaso"
                        class="px-6 py-2.5 rounded-xl text-white text-sm font-semibold"
                        style="background-color:var(--marca);"
                    >
                        Siguiente
                    </button>
                </div>
            </div>

            <!-- ─── PASO 4: Visualización ──────────────────────────────────── -->
            <div v-if="paso === 4">
                <h2 class="text-lg font-semibold text-tinta-900 mb-1">Visualización y nombre</h2>
                <p class="text-sm text-tinta-400 mb-5">Define cómo se mostrará el informe.</p>

                <div class="space-y-4">
                    <!-- Nombre -->
                    <div>
                        <label class="block text-xs font-semibold text-tinta-500 mb-1.5 uppercase tracking-wide">
                            Nombre del informe *
                        </label>
                        <input
                            v-model="nombre"
                            type="text"
                            placeholder="Ej: OPs en producción — Junio 2026"
                            class="w-full rounded-xl border border-linea px-3 py-2.5 text-sm text-tinta-900 focus:outline-none focus:border-[var(--marca)]"
                            :class="!nombre && 'border-red-300'"
                        />
                    </div>

                    <!-- Descripción -->
                    <div>
                        <label class="block text-xs font-semibold text-tinta-500 mb-1.5 uppercase tracking-wide">
                            Descripción (opcional)
                        </label>
                        <textarea
                            v-model="descripcion"
                            rows="2"
                            placeholder="Breve descripción del propósito del informe"
                            class="w-full rounded-xl border border-linea px-3 py-2.5 text-sm text-tinta-900 focus:outline-none focus:border-[var(--marca)] resize-none"
                        />
                    </div>

                    <!-- Tipo gráfica -->
                    <div>
                        <label class="block text-xs font-semibold text-tinta-500 mb-2 uppercase tracking-wide">
                            Tipo de gráfica
                        </label>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                            <button
                                v-for="op in [
                                    { key: 'ninguna', label: 'Sin gráfica' },
                                    { key: 'barras',  label: 'Barras' },
                                    { key: 'lineas',  label: 'Líneas' },
                                    { key: 'torta',   label: 'Torta' },
                                ]"
                                :key="op.key"
                                @click="tipoGrafica = op.key"
                                class="py-2.5 px-3 rounded-xl border text-xs font-semibold transition-colors text-center"
                                :class="tipoGrafica === op.key
                                    ? 'text-white border-transparent'
                                    : 'text-tinta-500 border-linea hover:border-tinta-200'"
                                :style="tipoGrafica === op.key ? 'background-color:var(--marca)' : ''"
                            >
                                {{ op.label }}
                            </button>
                        </div>
                    </div>

                    <!-- Toggle público -->
                    <div class="flex items-center justify-between py-3 px-4 rounded-xl bg-tinta-50 border border-linea">
                        <div>
                            <p class="text-sm font-semibold text-tinta-900">Visible para todos</p>
                            <p class="text-xs text-tinta-300">Cualquier usuario podrá ver este informe</p>
                        </div>
                        <button
                            @click="publico = !publico"
                            class="relative w-11 h-6 rounded-full transition-colors"
                            :style="publico ? 'background-color:var(--marca)' : 'background-color:#D1D5DB'"
                        >
                            <span
                                class="absolute top-0.5 left-0.5 w-5 h-5 rounded-full bg-superficie shadow-sm transition-transform"
                                :class="publico ? 'translate-x-5' : 'translate-x-0'"
                            />
                        </button>
                    </div>
                </div>

                <div class="flex items-center justify-between mt-6">
                    <button @click="anteriorPaso" class="px-5 py-2.5 rounded-xl text-sm font-medium text-tinta-500 hover:bg-tinta-100">
                        Atrás
                    </button>
                    <button
                        @click="guardar"
                        :disabled="!nombre.trim() || enviando"
                        class="flex items-center gap-2 px-6 py-2.5 rounded-xl text-white text-sm font-semibold disabled:opacity-40"
                        style="background-color:var(--marca);"
                    >
                        <svg v-if="enviando" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                        </svg>
                        {{ enviando ? 'Guardando...' : 'Guardar Informe' }}
                    </button>
                </div>
            </div>

        </div>
    </AppLayout>
</template>
