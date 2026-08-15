<script setup>
import { computed, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    sedes:      Array,
    secuencias: Array,
    catalogo:   Object,
})

const filas = ref(props.secuencias.map(s => ({ ...s })))

watch(() => props.secuencias, (vals) => {
    filas.value = vals.map(s => ({ ...s }))
}, { deep: true })

const sedeSeleccionada = ref(props.sedes[0]?.id ?? null)

const filasDeSede = computed(() =>
    filas.value.filter(f => f.sede_id === sedeSeleccionada.value)
)

// Vista previa en vivo, sin esperar a guardar.
function ejemplo(f) {
    const anio = f.incluir_anio ? `${new Date().getFullYear()}-` : ''
    const num  = String(f.siguiente_numero ?? 1).padStart(Number(f.padding) || 1, '0')
    return `${f.prefijo ?? ''}${anio}${num}`
}

function guardar(f) {
    router.put(`/configuracion/numeracion/${f.id}`, {
        prefijo:          f.prefijo ?? '',
        incluir_anio:     f.incluir_anio,
        siguiente_numero: f.siguiente_numero,
        padding:          f.padding,
    }, { preserveScroll: true })
}
</script>

<template>
    <AppLayout title="Numeración de documentos">
        <div class="max-w-3xl mx-auto">

            <div class="flex items-center gap-3 mb-5">
                <button @click="router.visit('/configuracion')"
                    class="p-2 rounded-xl hover:bg-tinta-100 transition-colors text-tinta-400" title="Volver">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                    </svg>
                </button>
                <h1 class="text-xl font-semibold text-tinta-900">Numeración</h1>
            </div>

            <div class="bg-pastel-ambar border border-borde-aviso-ambar rounded-xl p-3 mb-4">
                <p class="text-xs text-aviso-ambar">
                    Cuidado al cambiar el consecutivo: si lo bajas por debajo de un número ya usado,
                    el sistema intentará repetir códigos existentes.
                </p>
            </div>

            <!-- Selector de sede -->
            <div class="bg-superficie rounded-xl border border-linea p-3 mb-4">
                <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Sede</label>
                <select v-model="sedeSeleccionada"
                    class="w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm bg-superficie">
                    <option v-for="s in sedes" :key="s.id" :value="s.id">
                        {{ s.nombre }} ({{ s.codigo }}){{ s.activa ? '' : ' — inactiva' }}
                    </option>
                </select>
            </div>

            <!-- Documentos -->
            <div class="space-y-3">
                <div v-for="f in filasDeSede" :key="f.id" class="bg-superficie rounded-2xl border border-linea p-4">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-semibold text-tinta-900">{{ f.tipo_label }}</h3>
                        <span class="text-xs font-mono px-2 py-1 rounded-lg bg-tinta-100 text-tinta-700">
                            {{ ejemplo(f) }}
                        </span>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                        <div class="col-span-2 sm:col-span-1">
                            <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Prefijo</label>
                            <input v-model="f.prefijo" type="text" maxlength="30" placeholder="OP-"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Siguiente</label>
                            <input v-model.number="f.siguiente_numero" type="number" min="1"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Ceros</label>
                            <input v-model.number="f.padding" type="number" min="1" max="10"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]" />
                        </div>
                    </div>

                    <div class="flex items-center justify-between mt-3">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input v-model="f.incluir_anio" type="checkbox" class="rounded" />
                            <span class="text-sm text-tinta-700">Incluir el año</span>
                        </label>
                        <button @click="guardar(f)"
                            class="px-4 py-2 rounded-xl text-white text-sm font-semibold" style="background:var(--marca);">
                            Guardar
                        </button>
                    </div>
                </div>
            </div>

            <div v-if="!filasDeSede.length" class="py-10 text-center text-sm text-tinta-300">
                Esta sede no tiene numeración configurada todavía.
            </div>

        </div>
    </AppLayout>
</template>
