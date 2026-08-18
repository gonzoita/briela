<script setup>
/**
 * Los pagos de comisiones. A un vendedor se le paga el corte, no cotización por cotización.
 */
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { formatCOP } from '@/formato'

defineProps({ liquidaciones: { type: Object, default: () => ({ data: [] }) } })
</script>

<template>
    <AppLayout title="Liquidaciones">
        <div class="max-w-6xl mx-auto">
            <div class="flex items-center justify-between gap-3 mb-5 flex-wrap">
                <div>
                    <h1 class="text-xl font-semibold text-tinta-900">Liquidaciones</h1>
                    <p class="text-sm text-tinta-400 mt-0.5">Varias comisiones pagadas de una sola vez.</p>
                </div>
                <button type="button" @click="router.visit('/comisiones/liquidaciones/nueva')"
                    class="px-4 py-2 rounded-xl text-white text-sm font-semibold" style="background:var(--marca);">
                    + Nueva liquidación
                </button>
            </div>

            <div class="bg-superficie rounded-2xl shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-tinta-50 text-xs text-tinta-400 uppercase tracking-wide">
                            <tr>
                                <th class="text-left px-4 py-3">Número</th>
                                <th class="text-left px-4 py-3">Vendedor</th>
                                <th class="text-left px-4 py-3">Fecha</th>
                                <th class="text-right px-4 py-3">Comisiones</th>
                                <th class="text-right px-4 py-3">Total</th>
                                <th class="text-left px-4 py-3">Estado</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-separador">
                            <tr v-for="l in liquidaciones.data" :key="l.id"
                                @click="router.visit('/comisiones/liquidaciones/' + l.id)"
                                class="hover:bg-realce transition-colors cursor-pointer">
                                <td class="px-4 py-3 font-mono text-xs text-[var(--marca)]">{{ l.numero }}</td>
                                <td class="px-4 py-3 text-tinta-700">{{ l.vendedor }}</td>
                                <td class="px-4 py-3 text-tinta-500">{{ l.fecha ?? '—' }}</td>
                                <td class="px-4 py-3 text-right text-tinta-500">{{ l.comisiones }}</td>
                                <td class="px-4 py-3 text-right font-semibold text-tinta-800">${{ formatCOP(l.total) }}</td>
                                <td class="px-4 py-3">
                                    <span :class="['text-xs px-2 py-0.5 rounded-full',
                                        l.estado === 'pagada' ? 'bg-pastel-verde text-aviso-verde' : 'bg-pastel-ambar text-aviso-ambar']">
                                        {{ l.estado }}
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="! liquidaciones.data.length">
                                <td colspan="6" class="px-4 py-10 text-center text-sm text-tinta-300">
                                    Todavía no hay liquidaciones.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
