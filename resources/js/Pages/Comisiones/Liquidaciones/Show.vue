<script setup>
/**
 * El documento del pago: qué comisiones entraron y por cuánto.
 *
 * En borrador se puede deshacer y sus comisiones vuelven a quedar disponibles. Una pagada no
 * se borra: es el registro de una plata que ya salió, y borrarlo dejaría comisiones liquidadas
 * sin nada que las explique.
 */
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { formatCOP } from '@/formato'

const props = defineProps({ liquidacion: { type: Object, required: true } })

function pagar() {
    router.patch('/comisiones/liquidaciones/' + props.liquidacion.id + '/pagar', {}, { preserveScroll: true })
}

function deshacer() {
    router.delete('/comisiones/liquidaciones/' + props.liquidacion.id)
}
</script>

<template>
    <AppLayout :title="liquidacion.numero">
        <div class="max-w-4xl mx-auto">
            <button type="button" @click="router.visit('/comisiones/liquidaciones')"
                class="text-xs text-tinta-400 hover:text-tinta-700 mb-3">← Liquidaciones</button>

            <div class="bg-superficie rounded-2xl shadow-sm p-5 mb-4">
                <div class="flex items-start justify-between gap-3 flex-wrap">
                    <div>
                        <h1 class="text-xl font-semibold text-tinta-900">{{ liquidacion.numero }}</h1>
                        <p class="text-sm text-tinta-500 mt-0.5">{{ liquidacion.vendedor }}</p>
                        <p class="text-xs text-tinta-300 mt-1">
                            {{ liquidacion.fecha }}
                            <span v-if="liquidacion.creada_por"> · armada por {{ liquidacion.creada_por }}</span>
                            <span v-if="liquidacion.pagada_at"> · pagada el {{ liquidacion.pagada_at }}</span>
                        </p>
                    </div>
                    <div class="text-right">
                        <p class="text-2xl font-semibold" style="color:var(--marca);">${{ formatCOP(liquidacion.total) }}</p>
                        <span :class="['text-xs px-2 py-0.5 rounded-full',
                            liquidacion.estado === 'pagada' ? 'bg-pastel-verde text-aviso-verde' : 'bg-pastel-ambar text-aviso-ambar']">
                            {{ liquidacion.estado }}
                        </span>
                    </div>
                </div>

                <p v-if="liquidacion.notas" class="text-sm text-tinta-500 mt-3 pt-3 border-t border-linea">
                    {{ liquidacion.notas }}
                </p>
            </div>

            <div class="bg-superficie rounded-2xl shadow-sm overflow-hidden mb-4">
                <div class="px-5 py-3 border-b border-linea">
                    <h3 class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">
                        Comisiones incluidas ({{ liquidacion.comisiones.length }})
                    </h3>
                </div>
                <div class="divide-y divide-separador">
                    <div v-for="c in liquidacion.comisiones" :key="c.id"
                        class="flex items-center gap-3 px-5 py-3">
                        <span class="min-w-0 flex-1">
                            <span class="font-mono text-xs text-tinta-700">{{ c.cotizacion }}</span>
                            <span class="text-sm text-tinta-500 ml-2">{{ c.cliente }}</span>
                            <span class="text-xs text-tinta-300 ml-2">{{ c.periodo }}</span>
                        </span>
                        <span class="text-sm text-tinta-800 shrink-0">${{ formatCOP(c.total) }}</span>
                    </div>
                </div>
            </div>

            <div v-if="liquidacion.estado !== 'pagada'" class="flex gap-3">
                <button type="button" @click="deshacer"
                    class="flex-1 py-3 rounded-xl border border-borde-aviso-rojo text-sm font-medium text-aviso-rojo hover:bg-pastel-rojo transition-colors">
                    Deshacer
                </button>
                <button type="button" @click="pagar"
                    class="flex-1 py-3 rounded-xl text-sm font-semibold text-white bg-emerald-600 hover:bg-emerald-700 transition-colors">
                    Marcar pagada
                </button>
            </div>

            <p v-else class="text-xs text-tinta-300 text-center">
                Pagada. Sus comisiones quedaron liquidadas y el documento ya no se modifica.
            </p>
        </div>
    </AppLayout>
</template>
