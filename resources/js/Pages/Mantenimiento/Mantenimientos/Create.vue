<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useUnsavedChanges } from '@/composables/useUnsavedChanges'

const props = defineProps({
    equipos: Array,
})

// Pre-selección por query param
const urlParams  = new URLSearchParams(window.location.search)
const equipoInit = urlParams.get('equipo_id') ?? ''

const form = ref({
    equipo_id:        equipoInit,
    tipo:             'preventivo',
    estado:           'programado',
    fecha_programada: new Date().toISOString().split('T')[0],
    fecha_inicio:     '',
    fecha_fin:        '',
    ejecutor_tipo:    'interno',
    ejecutor_nombre:  '',
    descripcion:      '',
    hallazgos:        '',
    acciones:         '',
    costo_mano_obra:  0,
    tiempo_horas:     '',
    repuestos:        [],
})

const errors = ref({})

const { hasChanges, setOriginal, checkChanges } = useUnsavedChanges()
onMounted(() => setOriginal(form.value))
watch(form, checkChanges, { deep: true })

function agregarRepuesto() {
    form.value.repuestos.push({ nombre: '', referencia: '', unidad: 'und', cantidad: 1, precio_unitario: 0 })
}

function quitarRepuesto(idx) {
    form.value.repuestos.splice(idx, 1)
}

const costoRepuestos = computed(() =>
    form.value.repuestos.reduce((s, r) => s + (parseFloat(r.cantidad || 0) * parseFloat(r.precio_unitario || 0)), 0)
)

const costoTotal = computed(() =>
    parseFloat(form.value.costo_mano_obra || 0) + costoRepuestos.value
)

const fmt = (n) => Number(n).toLocaleString('es-CO')

function submit() {
    setOriginal(form.value)
    router.post('/mantenimiento/mantenimientos', form.value, {
        onError: (e) => { errors.value = e; hasChanges.value = true },
    })
}
</script>

<template>
    <AppLayout title="Nuevo mantenimiento">
        <div class="max-w-2xl mx-auto">

            <div class="flex items-center gap-3 mb-5">
                <a href="/mantenimiento/mantenimientos"
                    @click.prevent="router.visit('/mantenimiento/mantenimientos')"
                    class="text-tinta-300 hover:text-tinta-700">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M15 19l-7-7 7-7"/>
                    </svg>
                </a>
                <h1 class="text-xl font-semibold text-tinta-900">Registrar Mantenimiento</h1>
            </div>

            <div v-if="hasChanges" class="mb-3 inline-flex items-center gap-1.5 text-xs font-medium text-amber-700 bg-amber-50 border border-amber-200 rounded-xl px-3 py-1.5">
                <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                Cambios sin guardar
            </div>

            <!-- Datos básicos -->
            <div class="bg-white rounded-2xl border border-linea p-5 mb-4 space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Equipo *</label>
                    <select v-model="form.equipo_id"
                        class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2">
                        <option value="">Seleccionar equipo...</option>
                        <option v-for="e in equipos" :key="e.id" :value="e.id">
                            {{ e.nombre }} {{ e.ubicacion ? `(${e.ubicacion})` : '' }}
                        </option>
                    </select>
                    <p v-if="errors.equipo_id" class="text-xs text-red-500 mt-1">{{ errors.equipo_id }}</p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Tipo *</label>
                        <select v-model="form.tipo"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2">
                            <option value="preventivo">Preventivo</option>
                            <option value="correctivo">Correctivo</option>
                            <option value="predictivo">Predictivo</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Estado</label>
                        <select v-model="form.estado"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2">
                            <option value="programado">Programado</option>
                            <option value="en_proceso">En proceso</option>
                            <option value="completado">Completado</option>
                            <option value="cancelado">Cancelado</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Fecha programada *</label>
                        <input v-model="form.fecha_programada" type="date"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Tiempo (horas)</label>
                        <input v-model.number="form.tiempo_horas" type="number" min="0"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Fecha inicio</label>
                        <input v-model="form.fecha_inicio" type="date"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Fecha fin</label>
                        <input v-model="form.fecha_fin" type="date"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2" />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Ejecutor</label>
                        <select v-model="form.ejecutor_tipo"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2">
                            <option value="interno">Interno</option>
                            <option value="externo">Externo</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Nombre ejecutor</label>
                        <input v-model="form.ejecutor_nombre" type="text"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2" />
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Descripción</label>
                    <textarea v-model="form.descripcion" rows="2"
                        class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 resize-none"></textarea>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Hallazgos</label>
                    <textarea v-model="form.hallazgos" rows="2"
                        class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 resize-none"></textarea>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Acciones realizadas</label>
                    <textarea v-model="form.acciones" rows="2"
                        class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 resize-none"></textarea>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Mano de obra ($)</label>
                    <input v-model.number="form.costo_mano_obra" type="number" min="0"
                        class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2" />
                </div>
            </div>

            <!-- Repuestos -->
            <div class="bg-white rounded-2xl border border-linea overflow-hidden mb-4">
                <div class="px-5 py-3 border-b border-linea flex items-center justify-between">
                    <div>
                        <h2 class="text-sm font-semibold text-tinta-700">Repuestos / materiales</h2>
                        <p class="text-xs text-tinta-300 mt-0.5">Subtotal: ${{ fmt(costoRepuestos) }}</p>
                    </div>
                    <button @click="agregarRepuesto"
                        class="px-3 py-1.5 rounded-xl border border-tinta-200 text-xs font-medium text-tinta-700 hover:bg-tinta-50">
                        + Repuesto
                    </button>
                </div>
                <div v-if="!form.repuestos.length" class="py-6 text-center text-xs text-tinta-300">Sin repuestos.</div>
                <div class="divide-y divide-gray-50">
                    <div v-for="(r, idx) in form.repuestos" :key="idx" class="p-4 space-y-2">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-semibold text-tinta-300">Ítem {{ idx + 1 }}</span>
                            <button @click="quitarRepuesto(idx)"
                                class="w-6 h-6 rounded-lg flex items-center justify-center text-red-400 hover:bg-red-50">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>
                        <input v-model="r.nombre" placeholder="Nombre *" type="text"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2" />
                        <div class="grid grid-cols-3 gap-2">
                            <input v-model.number="r.cantidad" type="number" min="0" placeholder="Cant."
                                class="rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2" />
                            <input v-model="r.unidad" placeholder="Und" type="text"
                                class="rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2" />
                            <input v-model.number="r.precio_unitario" type="number" min="0" placeholder="$/u"
                                class="rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2" />
                        </div>
                        <p class="text-xs text-right text-tinta-400 font-medium">
                            Subtotal: ${{ fmt(r.cantidad * r.precio_unitario) }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- Resumen costo -->
            <div class="bg-blue-50 rounded-2xl border border-blue-200 p-4 mb-4">
                <div class="flex justify-between text-sm">
                    <span class="text-tinta-500">Mano de obra</span>
                    <span class="font-medium">${{ fmt(form.costo_mano_obra) }}</span>
                </div>
                <div class="flex justify-between text-sm mt-1">
                    <span class="text-tinta-500">Repuestos</span>
                    <span class="font-medium">${{ fmt(costoRepuestos) }}</span>
                </div>
                <div class="flex justify-between text-base font-semibold mt-2 pt-2 border-t border-blue-200">
                    <span>Total</span>
                    <span style="color:var(--marca);">${{ fmt(costoTotal) }}</span>
                </div>
            </div>

            <div class="flex gap-3">
                <button type="button" @click="router.visit('/mantenimiento/mantenimientos')"
                    class="flex-1 py-3 rounded-xl border border-linea text-sm text-tinta-500 font-medium">
                    Cancelar
                </button>
                <button type="button" @click="submit"
                    class="flex-1 py-3 rounded-xl text-white text-sm font-semibold"
                    style="background:var(--marca);">
                    Registrar
                </button>
            </div>
        </div>
    </AppLayout>
</template>
