<script setup>
import { ref, watch, onMounted } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useUnsavedChanges } from '@/composables/useUnsavedChanges'

const props = defineProps({
    equipo:     Object,
    estaciones: { type: Array, default: () => [] },
})

const form = ref({
    nombre:              props.equipo.nombre,
    codigo:              props.equipo.codigo ?? '',
    tipo:                props.equipo.tipo ?? '',
    marca:               props.equipo.marca ?? '',
    modelo:              props.equipo.modelo ?? '',
    serial:              props.equipo.serial ?? '',
    fecha_instalacion:   props.equipo.fecha_instalacion ?? '',
    frecuencia_dias:     props.equipo.frecuencia_dias ?? 90,
    ubicacion:           props.equipo.ubicacion ?? '',
    estacion_trabajo_id: props.equipo.estacion_trabajo_id ?? null,
    responsable:         props.equipo.responsable ?? '',
    observaciones:       props.equipo.observaciones ?? '',
    estado:              props.equipo.estado ?? 'activo',
    componentes:         (props.equipo.componentes ?? []).map(c => ({ ...c })),
})

const errors = ref({})

const { hasChanges, setOriginal, checkChanges } = useUnsavedChanges()
onMounted(() => setOriginal(form.value))
watch(form, checkChanges, { deep: true })

function agregarComponente() {
    form.value.componentes.push({ nombre: '', referencia: '', unidad: 'und', cantidad: 1, descripcion: '' })
}

function quitarComponente(idx) {
    form.value.componentes.splice(idx, 1)
}

function submit() {
    setOriginal(form.value)
    router.put(`/mantenimiento/equipos/${props.equipo.id}`, form.value, {
        onError: (e) => { errors.value = e; hasChanges.value = true },
    })
}

const TIPOS = ['compresor', 'evaporador', 'condensador', 'puerta', 'panel', 'motor', 'bomba', 'otro']
</script>

<template>
    <AppLayout :title="`Editar: ${equipo.nombre}`">
        <div class="max-w-2xl mx-auto">

            <div class="flex items-center gap-3 mb-5">
                <a :href="`/mantenimiento/equipos/${equipo.id}`"
                    @click.prevent="router.visit(`/mantenimiento/equipos/${equipo.id}`)"
                    class="text-tinta-300 hover:text-tinta-700">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M15 19l-7-7 7-7"/>
                    </svg>
                </a>
                <h1 class="text-xl font-semibold text-tinta-900">Editar Equipo</h1>
            </div>

            <div v-if="hasChanges" class="mb-3 inline-flex items-center gap-1.5 text-xs font-medium text-amber-700 bg-amber-50 border border-amber-200 rounded-xl px-3 py-1.5">
                <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                Cambios sin guardar
            </div>

            <div class="bg-superficie rounded-2xl border border-linea p-5 mb-4 space-y-4">
                <div class="grid grid-cols-2 gap-3">
                    <div class="col-span-2">
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Nombre *</label>
                        <input v-model="form.nombre" type="text"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2" />
                        <p v-if="errors.nombre" class="text-xs text-red-500 mt-1">{{ errors.nombre }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Código</label>
                        <input v-model="form.codigo" type="text"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Tipo</label>
                        <select v-model="form.tipo"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2">
                            <option value="">Seleccionar...</option>
                            <option v-for="t in TIPOS" :key="t" :value="t">{{ t }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Marca</label>
                        <input v-model="form.marca" type="text"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Modelo</label>
                        <input v-model="form.modelo" type="text"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Serial</label>
                        <input v-model="form.serial" type="text"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Fecha instalación</label>
                        <input v-model="form.fecha_instalacion" type="date"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Frec. (días)</label>
                        <input v-model.number="form.frecuencia_dias" type="number" min="1"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Ubicación</label>
                        <input v-model="form.ubicacion" type="text"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2" />
                    </div>
                    <div class="col-span-2">
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Estación de Trabajo</label>
                        <select v-model="form.estacion_trabajo_id"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2">
                            <option :value="null">Sin estación asignada</option>
                            <option v-for="est in props.estaciones" :key="est.id" :value="est.id">
                                {{ est.nombre }}
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Responsable</label>
                        <input v-model="form.responsable" type="text"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2" />
                    </div>
                    <div class="col-span-2">
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Estado</label>
                        <select v-model="form.estado"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2">
                            <option value="activo">Activo</option>
                            <option value="en_mantenimiento">En mantenimiento</option>
                            <option value="fuera_servicio">Fuera de servicio</option>
                        </select>
                    </div>
                    <div class="col-span-2">
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Observaciones</label>
                        <textarea v-model="form.observaciones" rows="2"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 resize-none"></textarea>
                    </div>
                </div>
            </div>

            <!-- Componentes -->
            <div class="bg-superficie rounded-2xl border border-linea overflow-hidden mb-4">
                <div class="px-5 py-3 border-b border-linea flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-tinta-700">Componentes</h2>
                    <button @click="agregarComponente"
                        class="px-3 py-1.5 rounded-xl border border-tinta-200 text-xs font-medium text-tinta-700 hover:bg-tinta-50">
                        + Componente
                    </button>
                </div>
                <div v-if="!form.componentes.length" class="py-6 text-center text-xs text-tinta-300">Sin componentes.</div>
                <div class="divide-y divide-gray-50">
                    <div v-for="(c, idx) in form.componentes" :key="idx" class="p-4 space-y-2">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-semibold text-tinta-300">Componente {{ idx + 1 }}</span>
                            <button @click="quitarComponente(idx)"
                                class="w-6 h-6 rounded-lg flex items-center justify-center text-red-400 hover:bg-red-50">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <input v-model="c.nombre" placeholder="Nombre *" type="text"
                                class="col-span-2 rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2" />
                            <input v-model="c.referencia" placeholder="Referencia" type="text"
                                class="rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2" />
                            <div class="flex gap-2">
                                <input v-model.number="c.cantidad" type="number" min="0" placeholder="Cant."
                                    class="flex-1 rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2" />
                                <input v-model="c.unidad" placeholder="Und" type="text"
                                    class="w-20 rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2" />
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex gap-3">
                <button type="button" @click="router.visit(`/mantenimiento/equipos/${equipo.id}`)"
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
