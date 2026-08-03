<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    turnos:  Array,
    tarifas: Array,
    config:  Object,
})

const tab = ref('turnos')

// ─── Turnos
const turnoForm = ref({ id: null, nombre: '', hora_inicio: '', hora_fin: '', activo: true })
const editandoTurno = ref(false)
function editarTurno(t) {
    turnoForm.value = { id: t.id, nombre: t.nombre, hora_inicio: t.hora_inicio, hora_fin: t.hora_fin, activo: t.activo }
    editandoTurno.value = true
}
function cancelarTurno() {
    turnoForm.value = { id: null, nombre: '', hora_inicio: '', hora_fin: '', activo: true }
    editandoTurno.value = false
}
function storeTurno() {
    router.post('/rrhh/configuracion/turnos', turnoForm.value, {
        preserveScroll: true,
        onSuccess: () => cancelarTurno(),
    })
}

// ─── Tarifas
const tarifas = [
    { tipo: 'diurna',    label: 'Diurna'    },
    { tipo: 'nocturna',  label: 'Nocturna'  },
    { tipo: 'dominical', label: 'Dominical' },
]
const tarifaForm = ref({ tipo: 'diurna', valor_hora: 0, activo: true })
function storeTarifa() {
    router.post('/rrhh/configuracion/tarifas', tarifaForm.value, { preserveScroll: true })
}

const tarifaValores = {}
for (const t of props.tarifas) tarifaValores[t.tipo] = t.valor_hora

// ─── Config global
const configForm = ref({
    tarifa_hora_base:      props.config.tarifa_hora_base,
    penalizacion_ausencia: props.config.penalizacion_ausencia,
})
function saveConfig() {
    router.post('/rrhh/configuracion/save', configForm.value, { preserveScroll: true })
}
</script>

<template>
    <AppLayout title="Config. RRHH">
        <div class="max-w-3xl mx-auto">

            <h1 class="text-xl font-bold text-gray-900 mb-5">Configuración RRHH</h1>

            <!-- Tabs -->
            <div class="flex gap-1 bg-gray-100 p-1 rounded-2xl mb-5">
                <button @click="tab = 'turnos'"
                    class="flex-1 py-2 rounded-xl text-sm font-semibold transition-colors"
                    :class="tab === 'turnos' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500'">
                    Turnos
                </button>
                <button @click="tab = 'tarifas'"
                    class="flex-1 py-2 rounded-xl text-sm font-semibold transition-colors"
                    :class="tab === 'tarifas' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500'">
                    Tarifas y Config.
                </button>
            </div>

            <!-- ─── TURNOS ─── -->
            <div v-show="tab === 'turnos'" class="space-y-4">
                <!-- Lista turnos -->
                <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-100">
                        <h2 class="text-sm font-semibold text-gray-700">Turnos configurados</h2>
                    </div>
                    <div v-if="!turnos.length" class="py-8 text-center text-sm text-gray-400">Sin turnos.</div>
                    <div v-else class="divide-y divide-gray-50">
                        <div v-for="t in turnos" :key="t.id" class="flex items-center px-5 py-3 gap-3">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-800">{{ t.nombre }}</p>
                                <p class="text-xs text-gray-400">{{ t.hora_inicio }} — {{ t.hora_fin }}</p>
                            </div>
                            <span class="text-xs px-2 py-0.5 rounded-full"
                                :class="t.activo ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'">
                                {{ t.activo ? 'Activo' : 'Inactivo' }}
                            </span>
                            <button @click="editarTurno(t)"
                                class="text-xs text-blue-600 hover:underline shrink-0">
                                Editar
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Formulario turno -->
                <div class="bg-white rounded-2xl border border-gray-200 p-5">
                    <h3 class="text-sm font-semibold text-gray-700 mb-4">
                        {{ editandoTurno ? 'Editar turno' : 'Nuevo turno' }}
                    </h3>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1.5">Nombre *</label>
                            <input v-model="turnoForm.nombre" type="text"
                                class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2" />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1.5">Hora inicio</label>
                                <input v-model="turnoForm.hora_inicio" type="time"
                                    class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2" />
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1.5">Hora fin</label>
                                <input v-model="turnoForm.hora_fin" type="time"
                                    class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2" />
                            </div>
                        </div>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input v-model="turnoForm.activo" type="checkbox" class="rounded" />
                            <span class="text-sm text-gray-700">Activo</span>
                        </label>
                        <div class="flex gap-3">
                            <button v-if="editandoTurno" @click="cancelarTurno"
                                class="flex-1 py-2.5 rounded-xl border border-gray-200 text-sm text-gray-600">
                                Cancelar
                            </button>
                            <button @click="storeTurno"
                                class="flex-1 py-2.5 rounded-xl text-white text-sm font-semibold"
                                style="background:var(--marca);">
                                {{ editandoTurno ? 'Actualizar' : 'Crear turno' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ─── TARIFAS ─── -->
            <div v-show="tab === 'tarifas'" class="space-y-4">
                <!-- Tarifas actuales -->
                <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
                    <div class="px-5 py-3 border-b border-gray-100">
                        <h2 class="text-sm font-semibold text-gray-700">Tarifas por tipo de hora extra</h2>
                    </div>
                    <div class="divide-y divide-gray-50">
                        <div v-for="tarifa in props.tarifas" :key="tarifa.id"
                            class="flex items-center px-5 py-3 gap-3">
                            <div class="flex-1">
                                <p class="text-sm font-medium text-gray-800 capitalize">{{ tarifa.tipo }}</p>
                            </div>
                            <span class="text-sm font-bold text-gray-700">
                                ${{ Number(tarifa.valor_hora).toLocaleString('es-CO') }}/h
                            </span>
                            <span class="text-xs px-2 py-0.5 rounded-full"
                                :class="tarifa.activo ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'">
                                {{ tarifa.activo ? 'Activa' : 'Inactiva' }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Actualizar tarifa -->
                <div class="bg-white rounded-2xl border border-gray-200 p-5">
                    <h3 class="text-sm font-semibold text-gray-700 mb-4">Actualizar tarifa</h3>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1.5">Tipo *</label>
                            <select v-model="tarifaForm.tipo"
                                class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2">
                                <option v-for="t in tarifas" :key="t.tipo" :value="t.tipo">{{ t.label }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1.5">Valor por hora ($) *</label>
                            <input v-model.number="tarifaForm.valor_hora" type="number" min="0"
                                class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2" />
                        </div>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input v-model="tarifaForm.activo" type="checkbox" class="rounded" />
                            <span class="text-sm text-gray-700">Activa</span>
                        </label>
                        <button @click="storeTarifa"
                            class="w-full py-2.5 rounded-xl text-white text-sm font-semibold"
                            style="background:var(--marca);">
                            Guardar tarifa
                        </button>
                    </div>
                </div>

                <!-- Config global -->
                <div class="bg-white rounded-2xl border border-gray-200 p-5">
                    <h3 class="text-sm font-semibold text-gray-700 mb-4">Configuración general</h3>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1.5">Tarifa hora base ($/h)</label>
                            <input v-model.number="configForm.tarifa_hora_base" type="number" min="0"
                                class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1.5">Penalización por ausencia ($)</label>
                            <input v-model.number="configForm.penalizacion_ausencia" type="number" min="0"
                                class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2" />
                        </div>
                        <button @click="saveConfig"
                            class="w-full py-2.5 rounded-xl text-white text-sm font-semibold"
                            style="background:var(--marca);">
                            Guardar configuración
                        </button>
                    </div>
                </div>
            </div>

        </div>
    </AppLayout>
</template>
