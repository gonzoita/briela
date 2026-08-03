<script setup>
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    ensamble: { type: Object, required: true },
})

const formatCOP = (v) => new Intl.NumberFormat('es-CO', { maximumFractionDigits: 0 }).format(v ?? 0)

const imagenUrl = props.ensamble.imagen_principal
    ? `/storage/${props.ensamble.imagen_principal}`
    : null

function recalcular() {
    if (!confirm('¿Recalcular los precios con los precios actuales de insumos?')) return
    router.post(`/ensambles/${props.ensamble.id}/recalcular`)
}

function eliminar() {
    if (!confirm('¿Eliminar este ensamble?')) return
    router.delete(`/ensambles/${props.ensamble.id}`)
}

const variablesEntries = Object.entries(props.ensamble.variables ?? {})
</script>

<template>
    <AppLayout :title="ensamble.nombre">
        <div class="max-w-3xl mx-auto">

            <!-- Imagen principal -->
            <div v-if="imagenUrl" class="rounded-2xl overflow-hidden mb-5 shadow-sm" style="max-height:320px;">
                <img :src="imagenUrl" :alt="ensamble.nombre" class="w-full h-full object-cover" style="max-height:320px;" />
            </div>

            <!-- Cabecera -->
            <div class="flex items-start justify-between mb-5 gap-3 flex-wrap">
                <div>
                    <div class="flex items-center gap-2 mb-1">
                        <button @click="router.visit('/ensambles')" class="text-gray-400 hover:text-gray-600">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                            </svg>
                        </button>
                        <span class="text-xs text-gray-400 font-medium">Ensambles</span>
                    </div>
                    <h1 class="text-xl font-bold text-gray-900">{{ ensamble.nombre }}</h1>
                    <p class="text-sm text-gray-500">{{ ensamble.plantilla_nombre }}</p>
                    <!-- Categoría -->
                    <span v-if="ensamble.categoria_nombre"
                        class="inline-block mt-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold text-white"
                        :style="`background:${ensamble.categoria_color ?? '#64748B'};`">
                        {{ ensamble.categoria_nombre }}
                    </span>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a :href="`/catalogo/ensambles/${ensamble.id}`" target="_blank"
                        class="px-3 py-2 rounded-xl border border-gray-200 text-xs text-gray-600 hover:bg-gray-50">
                        Catálogo
                    </a>
                    <a :href="`/catalogo/ensambles/${ensamble.id}?precio=0`" target="_blank"
                        class="px-3 py-2 rounded-xl border border-dashed border-gray-200 text-xs text-gray-400 hover:bg-gray-50">
                        Sin precio
                    </a>
                    <a :href="`/catalogo/ensambles/${ensamble.id}/pdf`"
                        class="px-3 py-2 rounded-xl border border-gray-200 text-xs text-gray-600 hover:bg-gray-50">
                        PDF
                    </a>
                    <a :href="`/catalogo/ensambles/${ensamble.id}/pdf?precio=0`"
                        class="px-3 py-2 rounded-xl border border-dashed border-gray-200 text-xs text-gray-400 hover:bg-gray-50">
                        PDF s/precio
                    </a>
                    <button @click="recalcular"
                        class="px-3 py-2 rounded-xl border border-gray-200 text-sm text-gray-600 hover:bg-gray-50 flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                        Recalcular
                    </button>
                    <button @click="router.visit(`/ensambles/${ensamble.id}/editar`)"
                        class="px-3 py-2 rounded-xl text-sm text-white font-medium flex items-center gap-2"
                        style="background:var(--marca);">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
                        </svg>
                        Editar
                    </button>
                </div>
            </div>

            <!-- Descripciones -->
            <div v-if="ensamble.descripcion_corta || ensamble.descripcion_larga"
                class="bg-white rounded-2xl shadow-sm p-5 mb-4">
                <h2 class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3">Descripción</h2>
                <p v-if="ensamble.descripcion_corta" class="text-sm font-medium text-gray-700 mb-2">
                    {{ ensamble.descripcion_corta }}
                </p>
                <div v-if="ensamble.descripcion_larga" class="tiptap-content text-sm text-gray-600 leading-relaxed" v-html="ensamble.descripcion_larga"></div>
            </div>

            <!-- Variables configuradas -->
            <div class="bg-white rounded-2xl shadow-sm p-5 mb-4">
                <h2 class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3">Variables configuradas</h2>
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                    <div v-for="[k, v] in variablesEntries" :key="k"
                        class="p-3 rounded-xl" style="background:#F8F9FA;">
                        <p class="text-xs text-gray-400 font-mono">{{ k }}</p>
                        <p class="text-sm font-semibold text-gray-800 mt-0.5">{{ v === true ? 'Sí' : v === false ? 'No' : v }}</p>
                    </div>
                </div>
            </div>

            <!-- Desglose de componentes -->
            <div class="bg-white rounded-2xl shadow-sm p-5 mb-4">
                <h2 class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3">Desglose de componentes</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs text-gray-400 border-b border-gray-100">
                                <th class="pb-2 font-medium">Componente</th>
                                <th class="pb-2 font-medium text-right">Cant.</th>
                                <th class="pb-2 font-medium text-right">Unidad</th>
                                <th class="pb-2 font-medium text-right">P. Unit.</th>
                                <th class="pb-2 font-medium text-right">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <tr v-for="(c, i) in ensamble.componentes_resultado" :key="i" class="hover:bg-gray-50">
                                <td class="py-2 font-medium text-gray-700">{{ c.nombre }}</td>
                                <td class="py-2 text-right font-mono text-gray-600">{{ c.cantidad }}</td>
                                <td class="py-2 text-right text-gray-500">{{ c.unidad }}</td>
                                <td class="py-2 text-right text-gray-500">${{ formatCOP(c.precio_unit) }}</td>
                                <td class="py-2 text-right font-semibold text-gray-800">${{ formatCOP(c.subtotal) }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-gray-200">
                                <td colspan="4" class="pt-3 text-xs font-bold text-gray-600 uppercase">Precio costo</td>
                                <td class="pt-3 text-right font-bold text-gray-900">${{ formatCOP(ensamble.precio_costo) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Tabla de precios -->
            <div class="bg-white rounded-2xl shadow-sm p-5 mb-4">
                <h2 class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3">Precios por canal</h2>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <div class="p-4 rounded-xl text-center" style="background:#F8F9FA;">
                        <p class="text-xs text-gray-400 mb-1">Costo</p>
                        <p class="text-lg font-bold text-gray-700">${{ formatCOP(ensamble.precio_costo) }}</p>
                    </div>
                    <div class="p-4 rounded-xl text-center" style="background:#F0F9FF;">
                        <p class="text-xs text-gray-400 mb-1">Mayorista</p>
                        <p class="text-lg font-bold text-gray-800">${{ formatCOP(ensamble.precio_mayorista) }}</p>
                    </div>
                    <div class="p-4 rounded-xl text-center" style="background:#EFF6FF; border:2px solid #93C5FD;">
                        <p class="text-xs font-medium mb-1" style="color:#1D4ED8;">Distribuidor</p>
                        <p class="text-lg font-bold" style="color:#1D4ED8;">${{ formatCOP(ensamble.precio_distribuidor) }}</p>
                    </div>
                    <div class="p-4 rounded-xl text-center" style="background:#F0FDF4;">
                        <p class="text-xs text-gray-400 mb-1">Cliente final</p>
                        <p class="text-lg font-bold text-gray-800">${{ formatCOP(ensamble.precio_cliente_final) }}</p>
                    </div>
                </div>

            </div>

            <!-- Eliminar -->
            <div class="flex justify-end">
                <button @click="eliminar"
                    class="px-4 py-2 rounded-xl text-sm text-red-600 hover:bg-red-50 border border-red-200 transition-colors">
                    Eliminar ensamble
                </button>
            </div>

        </div>
    </AppLayout>
</template>

<style>
.tiptap-content p { margin-bottom: 0.5rem; }
.tiptap-content p:last-child { margin-bottom: 0; }
.tiptap-content ul { list-style-type: disc; padding-left: 1.5rem; margin-bottom: 0.5rem; }
.tiptap-content ol { list-style-type: decimal; padding-left: 1.5rem; margin-bottom: 0.5rem; }
.tiptap-content li p { margin-bottom: 0; }
.tiptap-content strong { font-weight: 700; }
.tiptap-content em { font-style: italic; }
.tiptap-content u { text-decoration: underline; }
.tiptap-content a { color: var(--marca); text-decoration: underline; }
.tiptap-content h1 { font-size: 1.125rem; font-weight: 700; margin-bottom: 0.5rem; }
.tiptap-content h2 { font-size: 1rem; font-weight: 700; margin-bottom: 0.5rem; }
.tiptap-content h3 { font-size: 0.9rem; font-weight: 600; margin-bottom: 0.4rem; }
</style>
