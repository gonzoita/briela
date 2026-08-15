<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useUnsavedChanges } from '@/composables/useUnsavedChanges'

const props = defineProps({
    mantenimiento: Object,
    equipos:       Array,
})

const form = ref({
    equipo_id:        props.mantenimiento.equipo_id,
    tipo:             props.mantenimiento.tipo,
    estado:           props.mantenimiento.estado,
    fecha_programada: props.mantenimiento.fecha_programada,
    fecha_inicio:     props.mantenimiento.fecha_inicio ?? '',
    fecha_fin:        props.mantenimiento.fecha_fin ?? '',
    ejecutor_tipo:    props.mantenimiento.ejecutor_tipo ?? 'interno',
    ejecutor_nombre:  props.mantenimiento.ejecutor_nombre ?? '',
    descripcion:      props.mantenimiento.descripcion ?? '',
    hallazgos:        props.mantenimiento.hallazgos ?? '',
    acciones:         props.mantenimiento.acciones ?? '',
    costo_mano_obra:  parseFloat(props.mantenimiento.costo_mano_obra) || 0,
    tiempo_horas:     props.mantenimiento.tiempo_horas ?? '',
    repuestos:        (props.mantenimiento.repuestos ?? []).map(r => ({ ...r })),
})

const errors = ref({})

const { hasChanges, setOriginal, checkChanges } = useUnsavedChanges()
onMounted(() => setOriginal(form.value))
watch(form, checkChanges, { deep: true })

function agregarRepuesto() {
    form.value.repuestos.push({ nombre: '', referencia: '', unidad: 'und', cantidad: 1, precio_unitario: 0 })
}
function quitarRepuesto(idx) { form.value.repuestos.splice(idx, 1) }

const costoRepuestos = computed(() =>
    form.value.repuestos.reduce((s, r) => s + (parseFloat(r.cantidad || 0) * parseFloat(r.precio_unitario || 0)), 0)
)
const costoTotal = computed(() => parseFloat(form.value.costo_mano_obra || 0) + costoRepuestos.value)
const fmt = (n) => Number(n).toLocaleString('es-CO')

function submit() {
    setOriginal(form.value)
    router.put(`/mantenimiento/mantenimientos/${props.mantenimiento.id}`, form.value, {
        onError: (e) => { errors.value = e; hasChanges.value = true },
    })
}
</script>

<template>
    <AppLayout title="Editar mantenimiento">
        <div class="max-w-2xl mx-auto">

            <div class="flex items-center gap-3 mb-5">
                <a :href="`/mantenimiento/mantenimientos/${mantenimiento.id}`"
                    @click.prevent="router.visit(`/mantenimiento/mantenimientos/${mantenimiento.id}`)"
                    class="text-tinta-300 hover:text-tinta-700">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M15 19l-7-7 7-7"/>
                    </svg>
                </a>
                <h1 class="text-xl font-semibold text-tinta-900">Editar Mantenimiento</h1>
            </div>

            <div v-if="hasChanges" class="mb-3 inline-flex items-center gap-1.5 text-xs font-medium text-aviso-ambar bg-pastel-ambar border border-borde-aviso-ambar rounded-xl px-3 py-1.5">
                <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                Cambios sin guardar
            </div>

            <div class="bg-superficie rounded-2xl border border-linea p-5 mb-4 space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Equipo *</label>
                    <select v-model="form.equipo_id"
                        class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2">
                        <option v-for="e in equipos" :key="e.id" :value="e.id">
                            {{ e.nombre }} {{ e.ubicacion ? `(${e.ubicacion})` : '' }}
                        </option>
                    </select>
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
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Tiempo (h)</label>
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
                    <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Acciones</label>
                    <textarea v-model="form.acciones" rows="2"
                        class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 resize-none"></textarea>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Mano de obra ($)</label>
                    <input v-model.number="form.costo_mano_obra" type="number" step="0.01" min="0"
                        class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2" />
                </div>
            </div>

            <!-- Repuestos -->
            <div class="bg-superficie rounded-2xl border border-linea overflow-hidden mb-4">
                <div class="px-5 py-3 border-b border-linea flex items-center justify-between">
                    <div>
                        <h2 class="text-sm font-semibold text-tinta-700">Repuestos</h2>
                        <p class="text-xs text-tinta-300 mt-0.5">Subtotal: ${{ fmt(costoRepuestos) }}</p>
                    </div>
                    <button @click="agregarRepuesto"
                        class="px-3 py-1.5 rounded-xl border border-tinta-200 text-xs font-medium hover:bg-tinta-50">
                        + Repuesto
                    </button>
                </div>
                <div v-if="!form.repuestos.length" class="py-5 text-center text-xs text-tinta-300">Sin repuestos.</div>
                <div class="divide-y divide-separador">
                    <div v-for="(r, idx) in form.repuestos" :key="idx" class="p-4 space-y-2">
                        <div class="flex justify-between items-center">
                            <span class="text-xs font-semibold text-tinta-300">Ítem {{ idx + 1 }}</span>
                            <button @click="quitarRepuesto(idx)" class="w-6 h-6 rounded-lg flex items-center justify-center text-red-400 hover:bg-pastel-rojo">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>
                        <input v-model="r.nombre" placeholder="Nombre *" type="text"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2" />
                        <div class="grid grid-cols-3 gap-2">
                            <input v-model.number="r.cantidad" type="number" step="0.01" min="0" placeholder="Cant."
                                class="rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2" />
                            <input v-model="r.unidad" placeholder="Und" type="text"
                                class="rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2" />
                            <input v-model.number="r.precio_unitario" type="number" step="0.01" min="0" placeholder="$/u"
                                class="rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2" />
                        </div>
                    </div>
                </div>
            </div>

            <!-- Total -->
            <div class="bg-pastel-azul rounded-2xl border border-borde-aviso-azul p-4 mb-4">
                <div class="flex justify-between font-semibold">
                    <span>Total estimado</span>
                    <span style="color:var(--marca);">${{ fmt(costoTotal) }}</span>
                </div>
            </div>

            <div class="flex gap-3">
                <button type="button" @click="router.visit(`/mantenimiento/mantenimientos/${mantenimiento.id}`)"
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
