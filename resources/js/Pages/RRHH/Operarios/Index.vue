<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    operarios:         Array,
    metricas:          Object,
    filters:           Object,
    tipos_colaborador: Array,
    niveles:           Array,
})

const filters = ref({
    buscar:              props.filters?.buscar              ?? '',
    tipo_colaborador_id: props.filters?.tipo_colaborador_id ?? '',
    estado:              props.filters?.estado              ?? '',
    fecha_ingreso_desde: props.filters?.fecha_ingreso_desde ?? '',
    fecha_ingreso_hasta: props.filters?.fecha_ingreso_hasta ?? '',
    nivel:               props.filters?.nivel               ?? '',
    puntos_min:          props.filters?.puntos_min          ?? '',
})

function buscar() {
    router.get(
        '/rrhh/operarios',
        Object.fromEntries(Object.entries(filters.value).filter(([, v]) => v !== '' && v !== null)),
        { preserveState: true, replace: true }
    )
}

// ─── Cálculo masivo de bonos del mes ─────────────────────────────────────────
const modalBonos    = ref(false)
const hoy           = new Date()
const bonoMes       = ref(hoy.getMonth() + 1)
const bonoAnio      = ref(hoy.getFullYear())
const calculandoBonos = ref(false)
const meses = [
    'Enero','Febrero','Marzo','Abril','Mayo','Junio',
    'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre',
]

function calcularBonosTodos() {
    calculandoBonos.value = true
    router.post('/rrhh/operarios/calcular-bonos-todos',
        { mes: bonoMes.value, anio: bonoAnio.value },
        {
            preserveScroll: true,
            onSuccess: () => { modalBonos.value = false },
            onFinish:  () => { calculandoBonos.value = false },
        }
    )
}

let searchTimer = null
function onBuscarTexto() {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(buscar, 350)
}

function limpiarFiltros() {
    filters.value = {
        buscar: '', tipo_colaborador_id: '', estado: '',
        fecha_ingreso_desde: '', fecha_ingreso_hasta: '',
        nivel: '', puntos_min: '',
    }
    buscar()
}

function nivelColor(nivel) {
    const colores = {
        'Bronce':   '#CD7F32',
        'Plata':    '#C0C0C0',
        'Oro':      '#FFD700',
        'Diamante': '#00BFFF',
    }
    return colores[nivel] ?? '#6B7280'
}

function formatFecha(fecha) {
    if (!fecha) return ''
    return new Date(fecha).toLocaleDateString('es-CO', { year: 'numeric', month: 'short' })
}

const MESES = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic']
const mesActual = MESES[new Date().getMonth()]
</script>

<template>
    <AppLayout title="Colaboradores">
        <div class="max-w-5xl mx-auto">

            <!-- Header -->
            <div class="flex items-center justify-between mb-5 gap-2 flex-wrap">
                <h1 class="text-xl font-bold text-gray-900">Colaboradores</h1>
                <div class="flex items-center gap-2">
                    <button @click="modalBonos = true"
                        class="px-4 py-2 rounded-xl text-sm font-semibold border border-gray-300 text-gray-700 hover:bg-gray-50">
                        Calcular bonos del mes
                    </button>
                    <a href="/rrhh/operarios/crear" @click.prevent="router.visit('/rrhh/operarios/crear')"
                        class="px-4 py-2 rounded-xl text-white text-sm font-semibold"
                        style="background:var(--marca);">
                        + Nuevo
                    </a>
                </div>
            </div>

            <!-- Modal calcular bonos del mes (todos) -->
            <Teleport to="body">
                <div v-if="modalBonos" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-4"
                    style="background:rgba(0,0,0,0.5);">
                    <div class="bg-white rounded-2xl w-full max-w-sm p-5 space-y-4">
                        <h3 class="text-base font-bold text-gray-900">Calcular bonos del mes</h3>
                        <p class="text-xs text-gray-500">
                            Calcula el bono de <strong>todos los colaboradores activos</strong> para el mes elegido,
                            de una sola vez.
                        </p>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="text-xs text-gray-500 block mb-1">Mes</label>
                                <select v-model.number="bonoMes"
                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                                    <option v-for="(m, i) in meses" :key="i" :value="i + 1">{{ m }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-xs text-gray-500 block mb-1">Año</label>
                                <input v-model.number="bonoAnio" type="number" min="2020"
                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" />
                            </div>
                        </div>
                        <div class="flex gap-2 pt-1">
                            <button @click="modalBonos = false"
                                class="flex-1 py-2.5 rounded-xl border border-gray-200 text-sm text-gray-600">Cancelar</button>
                            <button @click="calcularBonosTodos" :disabled="calculandoBonos"
                                class="flex-1 py-2.5 rounded-xl text-white text-sm font-semibold disabled:opacity-60"
                                style="background:var(--marca);">
                                {{ calculandoBonos ? 'Calculando...' : 'Calcular todos' }}
                            </button>
                        </div>
                    </div>
                </div>
            </Teleport>

            <!-- Métricas -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
                <div class="bg-white rounded-2xl border border-gray-200 p-4 text-center">
                    <p class="text-2xl font-bold text-gray-900">{{ metricas.total_activos }}</p>
                    <p class="text-xs text-gray-500 mt-1">Activos</p>
                </div>
                <div class="bg-white rounded-2xl border border-gray-200 p-4 text-center">
                    <p class="text-2xl font-bold text-blue-600">{{ metricas.bonos_calculados_mes }}</p>
                    <p class="text-xs text-gray-500 mt-1">Bonos {{ mesActual }}</p>
                </div>
                <div class="bg-white rounded-2xl border border-gray-200 p-4 text-center">
                    <p class="text-2xl font-bold text-red-500">{{ metricas.disciplinas_pendientes }}</p>
                    <p class="text-xs text-gray-500 mt-1">Disciplinas pend.</p>
                </div>
                <div class="bg-white rounded-2xl border border-gray-200 p-4 text-center">
                    <p class="text-2xl font-bold text-amber-600">{{ metricas.horas_extras_mes }}</p>
                    <p class="text-xs text-gray-500 mt-1">Horas extras {{ mesActual }}</p>
                </div>
            </div>

            <!-- Filtros -->
            <div class="bg-white rounded-xl border border-gray-200 p-4 mb-4 space-y-3">
                <!-- Fila 1: búsqueda + tipo + estado + nivel -->
                <div class="flex flex-wrap gap-2">
                    <input v-model="filters.buscar" type="text"
                        placeholder="Buscar por nombre, documento, especialidad..."
                        @keyup.enter="buscar"
                        @input="onBuscarTexto"
                        class="flex-1 min-w-48 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-200" />

                    <select v-model="filters.tipo_colaborador_id" @change="buscar"
                        class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none">
                        <option value="">Todos los tipos</option>
                        <option v-for="t in tipos_colaborador" :key="t.id" :value="t.id">
                            {{ t.nombre }}
                        </option>
                    </select>

                    <select v-model="filters.estado" @change="buscar"
                        class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none">
                        <option value="">Todos los estados</option>
                        <option value="activo">Activo</option>
                        <option value="inactivo">Inactivo</option>
                    </select>

                    <select v-model="filters.nivel" @change="buscar"
                        class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none">
                        <option value="">Todos los niveles</option>
                        <option v-for="n in niveles" :key="n.nombre" :value="n.nombre">
                            {{ n.nombre }}
                        </option>
                    </select>
                </div>

                <!-- Fila 2: fechas + puntos mínimos + limpiar -->
                <div class="flex flex-wrap gap-2 items-center">
                    <input v-model="filters.fecha_ingreso_desde" type="date"
                        @change="buscar"
                        class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none" />

                    <input v-model="filters.fecha_ingreso_hasta" type="date"
                        @change="buscar"
                        class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none" />

                    <input v-model="filters.puntos_min" type="number"
                        @change="buscar"
                        placeholder="Puntos mínimos"
                        class="w-36 rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none" />

                    <button @click="limpiarFiltros"
                        class="px-3 py-2 rounded-lg text-sm text-gray-500 hover:bg-gray-100 transition-colors">
                        Limpiar
                    </button>
                </div>
            </div>

            <!-- Lista vacía -->
            <div v-if="!operarios.length"
                class="py-12 text-center bg-white rounded-2xl border border-gray-200">
                <p class="text-sm text-gray-400">No hay colaboradores que coincidan con los filtros.</p>
            </div>

            <!-- Cards -->
            <div v-else class="space-y-2">
                <a v-for="op in operarios" :key="op.id"
                    :href="`/rrhh/operarios/${op.id}`"
                    @click.prevent="router.visit(`/rrhh/operarios/${op.id}`)"
                    class="flex items-start gap-3 bg-white rounded-xl border border-gray-200 px-4 py-3 hover:border-blue-300 hover:shadow-sm transition-all cursor-pointer">

                    <!-- Avatar con inicial -->
                    <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold text-white flex-shrink-0"
                        :style="{ background: op.tipo_color ?? 'var(--marca)' }">
                        {{ op.nombre?.[0]?.toUpperCase() ?? '?' }}
                    </div>

                    <div class="flex-1 min-w-0">
                        <!-- Nombre + badges -->
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="font-semibold text-gray-900 text-sm">{{ op.nombre }}</span>
                            <span v-if="op.tipo_colaborador"
                                class="text-xs px-2 py-0.5 rounded-full font-medium text-white"
                                :style="{ background: op.tipo_color ?? '#6B7280' }">
                                {{ op.tipo_colaborador }}
                            </span>
                            <span v-if="op.tiene_usuario"
                                class="text-xs px-1.5 py-0.5 rounded bg-green-100 text-green-700">
                                Acceso
                            </span>
                            <span v-if="op.disciplinas_pendientes > 0"
                                class="text-xs px-2 py-0.5 rounded-full font-semibold bg-red-100 text-red-700">
                                {{ op.disciplinas_pendientes }} disciplina(s)
                            </span>
                        </div>

                        <!-- Cargo + documento -->
                        <p class="text-xs text-gray-500 mt-0.5">
                            {{ op.cargo ?? op.especialidad ?? '—' }} · {{ op.documento }}
                        </p>

                        <!-- Nivel + puntos + fecha + estado + bono -->
                        <div class="flex items-center gap-2 mt-1.5 flex-wrap">
                            <span class="text-xs font-medium px-2 py-0.5 rounded-full"
                                :style="{ background: nivelColor(op.nivel) + '22', color: nivelColor(op.nivel) }">
                                ★ {{ op.nivel }} · {{ op.puntos_totales }} pts
                            </span>
                            <span v-if="op.fecha_ingreso" class="text-xs text-gray-400">
                                Desde {{ formatFecha(op.fecha_ingreso) }}
                            </span>
                            <span :class="op.estado === 'activo'
                                    ? 'bg-green-100 text-green-700'
                                    : 'bg-gray-100 text-gray-500'"
                                class="text-xs px-2 py-0.5 rounded-full">
                                {{ op.estado }}
                            </span>
                            <span v-if="op.bono_mes > 0"
                                class="text-xs font-semibold text-green-700 hidden sm:inline">
                                ${{ Number(op.bono_mes).toLocaleString('es-CO') }} bono {{ mesActual }}
                            </span>
                        </div>
                    </div>

                    <svg class="w-4 h-4 text-gray-400 flex-shrink-0 mt-1"
                        fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </a>
            </div>

        </div>
    </AppLayout>
</template>
