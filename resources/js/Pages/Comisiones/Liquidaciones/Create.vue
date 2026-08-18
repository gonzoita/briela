<script setup>
/**
 * Armar una liquidación: un vendedor y lo que tiene sin pagar.
 *
 * Solo salen las comisiones que de verdad se pueden pagar —sin liquidar, fuera de otra
 * liquidación y con valor—, y el servidor lo vuelve a comprobar al guardar: entre que la
 * pantalla cargó y alguien pulsó el botón, otra persona pudo haber pagado una.
 */
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { formatCOP } from '@/formato'

const props = defineProps({
    vendedores:  { type: Array, default: () => [] },
    vendedor_id: { type: [Number, String], default: null },
    pendientes:  { type: Array, default: () => [] },
})

const vendedor = ref(props.vendedor_id ?? '')
const elegidas = ref(props.pendientes.map(p => p.id))
const fecha    = ref(new Date().toISOString().slice(0, 10))
const notas    = ref('')

const total = computed(() =>
    props.pendientes.filter(p => elegidas.value.includes(p.id)).reduce((s, p) => s + p.total, 0)
)

function cambiarVendedor(id) {
    router.get('/comisiones/liquidaciones/nueva', { user_id: id || undefined }, { preserveState: false })
}

function alternar(id) {
    elegidas.value = elegidas.value.includes(id)
        ? elegidas.value.filter(x => x !== id)
        : [...elegidas.value, id]
}

function guardar() {
    router.post('/comisiones/liquidaciones', {
        user_id: vendedor.value, comisiones: elegidas.value, fecha: fecha.value, notas: notas.value,
    })
}
</script>

<template>
    <AppLayout title="Nueva liquidación">
        <div class="max-w-4xl mx-auto">
            <h1 class="text-xl font-semibold text-tinta-900 mb-1">Nueva liquidación</h1>
            <p class="text-sm text-tinta-400 mb-5">Elige el vendedor y las comisiones que entran en este pago.</p>

            <div class="bg-superficie rounded-2xl shadow-sm p-5 mb-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-tinta-400 mb-1">Vendedor</label>
                        <select :value="vendedor" @change="cambiarVendedor($event.target.value)"
                            class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]">
                            <option value="">Elige un vendedor…</option>
                            <option v-for="v in vendedores" :key="v.id" :value="v.id">{{ v.name }}</option>
                        </select>
                        <p v-if="! vendedores.length" class="text-xs text-tinta-300 mt-1">
                            Nadie tiene comisiones sin pagar en este momento.
                        </p>
                    </div>
                    <div>
                        <label class="block text-xs text-tinta-400 mb-1">Fecha del pago</label>
                        <input v-model="fecha" type="date"
                            class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]" />
                    </div>
                </div>
            </div>

            <div v-if="vendedor" class="bg-superficie rounded-2xl shadow-sm overflow-hidden mb-4">
                <div class="px-5 py-3 border-b border-linea flex items-center justify-between gap-2">
                    <h3 class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Sin pagar</h3>
                    <span class="text-sm font-semibold" style="color:var(--marca);">${{ formatCOP(total) }}</span>
                </div>
                <div class="divide-y divide-separador">
                    <label v-for="p in pendientes" :key="p.id"
                        class="flex items-center gap-3 px-5 py-3 hover:bg-realce transition-colors cursor-pointer">
                        <input type="checkbox" :checked="elegidas.includes(p.id)" @change="alternar(p.id)"
                            class="accent-emerald-600 shrink-0" />
                        <span class="min-w-0 flex-1">
                            <span class="font-mono text-xs text-tinta-700">{{ p.cotizacion }}</span>
                            <span class="text-sm text-tinta-500 ml-2">{{ p.cliente }}</span>
                            <span class="text-xs text-tinta-300 ml-2">{{ p.periodo }} · {{ p.estado }}</span>
                        </span>
                        <span class="text-sm text-tinta-800 shrink-0">${{ formatCOP(p.total) }}</span>
                    </label>
                    <p v-if="! pendientes.length" class="px-5 py-8 text-center text-sm text-tinta-300">
                        Este vendedor no tiene comisiones sin pagar.
                    </p>
                </div>
            </div>

            <div v-if="vendedor && pendientes.length" class="bg-superficie rounded-2xl shadow-sm p-5 mb-4">
                <label class="block text-xs text-tinta-400 mb-1">Notas (opcional)</label>
                <textarea v-model="notas" rows="2" maxlength="2000"
                    class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]"></textarea>
            </div>

            <div class="flex gap-3">
                <button type="button" @click="router.visit('/comisiones/liquidaciones')"
                    class="flex-1 py-3 rounded-xl border border-linea text-sm font-medium text-tinta-500 hover:bg-realce transition-colors">
                    Cancelar
                </button>
                <button type="button" @click="guardar" :disabled="! vendedor || ! elegidas.length"
                    class="flex-1 py-3 rounded-xl text-sm font-semibold text-white disabled:opacity-50"
                    style="background:var(--marca);">
                    Crear liquidación por ${{ formatCOP(total) }}
                </button>
            </div>
        </div>
    </AppLayout>
</template>
