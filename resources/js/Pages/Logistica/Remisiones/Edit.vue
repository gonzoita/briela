<script setup>
import { reactive, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    remision: Object,
    clientes: Array,
})

const form = reactive({
    fecha_remision:        props.remision.fecha_remision ?? '',
    notas:                 props.remision.notas ?? '',
    transportista:         props.remision.transportista ?? '',
    celular_transportista: props.remision.celular_transportista ?? '',
    placa:                 props.remision.placa ?? '',
    costo_flete:           props.remision.costo_flete ?? '',
    cliente_id:            props.remision.cliente?.id ?? null,
})

const errors    = ref({})
const guardando = ref(false)

function enviar() {
    guardando.value = true
    router.put(`/logistica/remisiones/${props.remision.id}`, form, {
        onError: (e) => { errors.value = e; guardando.value = false },
        onSuccess: () => { guardando.value = false },
    })
}
</script>

<template>
    <AppLayout :title="`Editar ${remision.numero}`">
        <div class="max-w-xl mx-auto">

            <div class="flex items-center gap-3 mb-6">
                <a :href="`/logistica/remisiones/${remision.id}`" class="text-tinta-300 hover:text-tinta-700">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M15 19l-7-7 7-7"/>
                    </svg>
                </a>
                <h1 class="text-xl font-semibold text-tinta-900">Editar {{ remision.numero }}</h1>
            </div>

            <div class="bg-white rounded-2xl border border-linea p-5 space-y-4">

                <div>
                    <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">
                        Cliente
                    </label>
                    <select v-model="form.cliente_id"
                        class="w-full border border-linea rounded-xl px-4 py-2.5 text-sm focus:outline-none">
                        <option :value="null">Sin cliente</option>
                        <option v-for="c in clientes" :key="c.id" :value="c.id">{{ c.nombre }}</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">
                        Fecha de remisión
                    </label>
                    <input v-model="form.fecha_remision" type="date"
                        class="w-full border border-linea rounded-xl px-4 py-2.5 text-sm focus:outline-none"/>
                    <p v-if="errors.fecha_remision" class="text-xs text-red-500 mt-1">{{ errors.fecha_remision }}</p>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">
                        Transportista
                    </label>
                    <input v-model="form.transportista" type="text" placeholder="Nombre del transportista"
                        class="w-full border border-linea rounded-xl px-4 py-2.5 text-sm focus:outline-none"/>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">
                            Celular
                        </label>
                        <input v-model="form.celular_transportista" type="text"
                            class="w-full border border-linea rounded-xl px-4 py-2.5 text-sm focus:outline-none"/>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">
                            Placa
                        </label>
                        <input v-model="form.placa" type="text"
                            class="w-full border border-linea rounded-xl px-4 py-2.5 text-sm focus:outline-none"/>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">
                        Costo flete
                    </label>
                    <input v-model.number="form.costo_flete" type="number" step="1000" min="0" placeholder="0"
                        class="w-full border border-linea rounded-xl px-4 py-2.5 text-sm focus:outline-none"/>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">
                        Notas internas
                    </label>
                    <textarea v-model="form.notas" rows="3"
                        class="w-full border border-linea rounded-xl px-4 py-2.5 text-sm focus:outline-none resize-none"/>
                </div>

                <button @click="enviar" type="button" :disabled="guardando"
                    class="w-full py-3 rounded-xl text-sm font-semibold text-white"
                    :style="guardando ? 'background:var(--marca);opacity:0.6;' : 'background:var(--marca);'">
                    {{ guardando ? 'Guardando…' : 'Guardar cambios' }}
                </button>
            </div>

        </div>
    </AppLayout>
</template>
