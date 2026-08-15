<script setup>
import { router, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import InterruptorWeb from '@/Components/InterruptorWeb.vue'

const props = defineProps({
    ensamble: { type: Object, required: true },
    // Los canales configurados con el precio efectivo de este ensamble en cada uno.
    canales:  { type: Array,  default: () => [] },
    // Cuántas unidades alcanzan a armarse hoy, y qué material se agota primero.
    disponibilidad: { type: Object, default: null },
    web:      { type: Object, default: null },
})

// Publicar en la web es una decisión sobre el ensamble: pide el mismo permiso que editarlo.
const page = usePage()
const puedeEditar = computed(() => (page.props.auth?.permisosLista ?? []).includes('ensambles.editar'))

const formatCOP = (v) => new Intl.NumberFormat('es-CO', { maximumFractionDigits: 0 }).format(v ?? 0)

// Las imágenes nuevas se guardan como ruta relativa, pero las viejas quedaron
// con la URL completa de Google Drive. Se respeta la que ya venga absoluta.
const urlImagen = (v) => (!v ? null : (v.startsWith('http') ? v : `/storage/${v}`))

const imagenUrl = urlImagen(props.ensamble.imagen_principal)

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
                        <button @click="router.visit('/ensambles')" class="text-tinta-300 hover:text-tinta-500">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                            </svg>
                        </button>
                        <span class="text-xs text-tinta-300 font-medium">Ensambles</span>
                    </div>
                    <h1 class="text-xl font-semibold text-tinta-900">{{ ensamble.nombre }}</h1>
                    <p class="text-sm text-tinta-400">
                        <span v-if="ensamble.referencia" class="font-mono">{{ ensamble.referencia }}</span>
                        <span v-if="ensamble.referencia && ensamble.plantilla_nombre"> · </span>
                        {{ ensamble.plantilla_nombre }}
                        <span v-if="ensamble.unidad_medida"> · por {{ ensamble.unidad_medida }}</span>
                    </p>
                    <!-- Categoría -->
                    <span v-if="ensamble.categoria_nombre"
                        class="inline-block mt-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold text-white"
                        :style="`background:${ensamble.categoria_color ?? '#64748B'};`">
                        {{ ensamble.categoria_nombre }}
                    </span>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a :href="`/catalogo/ensambles/${ensamble.id}`" target="_blank"
                        class="px-3 py-2 rounded-xl border border-linea text-xs text-tinta-500 hover:bg-tinta-50">
                        Catálogo
                    </a>
                    <a :href="`/catalogo/ensambles/${ensamble.id}?precio=0`" target="_blank"
                        class="px-3 py-2 rounded-xl border border-dashed border-linea text-xs text-tinta-300 hover:bg-tinta-50">
                        Sin precio
                    </a>
                    <a :href="`/catalogo/ensambles/${ensamble.id}/pdf`"
                        class="px-3 py-2 rounded-xl border border-linea text-xs text-tinta-500 hover:bg-tinta-50">
                        PDF
                    </a>
                    <a :href="`/catalogo/ensambles/${ensamble.id}/pdf?precio=0`"
                        class="px-3 py-2 rounded-xl border border-dashed border-linea text-xs text-tinta-300 hover:bg-tinta-50">
                        PDF s/precio
                    </a>
                    <button @click="recalcular"
                        class="px-3 py-2 rounded-xl border border-linea text-sm text-tinta-500 hover:bg-tinta-50 flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                        Recalcular
                    </button>
                    <!-- Duplicar, igual que en productos: la forma real de crear el segundo
                         ensamble parecido es copiar el primero y cambiar dos cosas. -->
                    <button @click="router.visit(`/ensambles/${ensamble.id}/duplicar`)"
                        class="px-3 py-2 rounded-xl border border-linea text-sm text-tinta-500 hover:bg-tinta-50 flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                        </svg>
                        Duplicar
                    </button>
                    <button @click="router.visit(`/ensambles/${ensamble.id}/editar`)"
                        class="px-3 py-2 rounded-xl text-sm text-white font-medium flex items-center gap-2"
                        style="background:var(--marca);">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
                        </svg>
                        Editar
                    </button>
                </div>
            </div>

            <!-- Publicar en el sitio web. En WordPress un ensamble es un producto más;
                 lo único distinto es que su precio va como «desde», porque el final
                 depende de las medidas. -->
            <div v-if="puedeEditar" class="mb-4">
                <InterruptorWeb
                    tipo="ensamble"
                    :id="ensamble.id"
                    :publicado="!!ensamble.publicado_web"
                    :publicado-at="ensamble.publicado_web_at"
                    :sin-precio="!!web?.sin_precio"
                />
            </div>

            <!-- Descripciones -->
            <div v-if="ensamble.descripcion_corta || ensamble.descripcion_larga"
                class="bg-superficie rounded-2xl shadow-sm p-5 mb-4">
                <h2 class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-3">Descripción</h2>
                <p v-if="ensamble.descripcion_corta" class="text-sm font-medium text-tinta-700 mb-2">
                    {{ ensamble.descripcion_corta }}
                </p>
                <div v-if="ensamble.descripcion_larga" class="tiptap-content text-sm text-tinta-500 leading-relaxed" v-html="ensamble.descripcion_larga"></div>
            </div>

            <!-- Resumen técnico para cotizaciones. Se muestra aunque falte: es lo que se
                 imprime en la cotización y lo que lee la IA para recomendar. -->
            <div class="rounded-2xl shadow-sm p-5 mb-4"
                :class="ensamble.descripcion_cotizacion ? 'bg-superficie' : ''"
                :style="ensamble.descripcion_cotizacion ? '' : 'background:var(--pastel-ambar);'">
                <div class="flex items-center justify-between gap-2 mb-2">
                    <h2 class="text-xs font-semibold uppercase tracking-[0.12em]"
                        :style="ensamble.descripcion_cotizacion ? 'color:var(--tinta-400);' : 'color:var(--texto-ambar);'">
                        Resumen técnico · cotizaciones y OP
                    </h2>
                    <button v-if="puedeEditar" type="button" @click="router.visit(`/ensambles/${ensamble.id}/editar`)"
                        class="text-xs font-semibold shrink-0" style="color:var(--marca);">
                        {{ ensamble.descripcion_cotizacion ? 'Editar' : 'Generarlo' }}
                    </button>
                </div>
                <p v-if="ensamble.descripcion_cotizacion" class="text-sm text-tinta-700 leading-relaxed">
                    {{ ensamble.descripcion_cotizacion }}
                </p>
                <p v-else class="text-xs leading-relaxed" style="color:var(--texto-ambar);">
                    Sin cargar. En la cotización va a salir la descripción comercial en su lugar, y el
                    asistente no tiene con qué recomendar este ensamble. Se genera con «Ficha técnica
                    con IA» al editarlo.
                </p>
            </div>

            <!-- ¿Se puede armar hoy?
                 Un ensamble no vive en un estante: cada uno se arma cuando se vende. Así que
                 la pregunta «¿lo tengo en almacén?» no tiene respuesta literal, y la que sí
                 sirve es cuántos alcanzan a armarse con lo que hay — limitado por el material
                 que primero se agota. -->
            <div v-if="disponibilidad && disponibilidad.unidades !== null"
                class="bg-superficie rounded-2xl shadow-sm p-5 mb-4">
                <h2 class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-3">Se puede armar</h2>

                <div class="flex items-baseline gap-2 flex-wrap">
                    <span class="text-3xl font-semibold"
                        :class="disponibilidad.unidades > 0 ? 'text-aviso-verde' : 'text-aviso-rojo'">
                        {{ disponibilidad.unidades }}
                    </span>
                    <span class="text-sm text-tinta-400">
                        {{ disponibilidad.unidades === 1 ? 'unidad' : 'unidades' }} con el inventario de esta sede
                    </span>
                </div>

                <p v-if="disponibilidad.cuello" class="text-xs text-tinta-400 mt-2">
                    Lo primero que se agota: <span class="font-medium text-tinta-600">{{ disponibilidad.cuello }}</span>
                </p>

                <!-- Qué falta y cuánto: es lo que se lleva a una solicitud de compra. -->
                <div v-if="disponibilidad.faltantes.length" class="mt-3 border-t border-linea pt-3">
                    <p class="text-xs font-semibold text-aviso-ambar mb-2">Falta material para armar una sola unidad</p>
                    <div class="space-y-1">
                        <div v-for="(f, i) in disponibilidad.faltantes" :key="i"
                            class="flex items-center justify-between gap-2 text-xs">
                            <span class="text-tinta-600 truncate">{{ f.nombre }}</span>
                            <span class="text-tinta-400 shrink-0">
                                hay {{ f.hay }} · pide {{ f.necesita }} {{ f.unidad }} ·
                                <span class="text-aviso-rojo font-semibold">falta {{ f.falta }}</span>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Variables configuradas. Un ensamble directo no tiene: su receta se escribió
                 a mano, no salió de medidas, y la tarjeta salía vacía. -->
            <div v-if="variablesEntries.length" class="bg-superficie rounded-2xl shadow-sm p-5 mb-4">
                <h2 class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-3">Variables configuradas</h2>
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                    <div v-for="[k, v] in variablesEntries" :key="k"
                        class="p-3 rounded-xl" style="background:var(--superficie-2);">
                        <p class="text-xs text-tinta-300 font-mono">{{ k }}</p>
                        <p class="text-sm font-semibold text-tinta-900 mt-0.5">{{ v === true ? 'Sí' : v === false ? 'No' : v }}</p>
                    </div>
                </div>
            </div>

            <!-- Desglose de componentes -->
            <div class="bg-superficie rounded-2xl shadow-sm p-5 mb-4">
                <h2 class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-3">{{ variablesEntries.length ? 'Desglose de componentes' : 'Lista de materiales' }}</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs text-tinta-300 border-b border-linea">
                                <th class="pb-2 font-medium">Componente</th>
                                <th class="pb-2 font-medium text-right">Cant.</th>
                                <th class="pb-2 font-medium text-right">Unidad</th>
                                <th class="pb-2 font-medium text-right">P. Unit.</th>
                                <th class="pb-2 font-medium text-right">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-separador">
                            <tr v-for="(c, i) in ensamble.componentes_resultado" :key="i" class="hover:bg-tinta-50">
                                <td class="py-2 font-medium text-tinta-700">{{ c.nombre }}</td>
                                <td class="py-2 text-right font-mono text-tinta-500">{{ c.cantidad }}</td>
                                <td class="py-2 text-right text-tinta-400">{{ c.unidad }}</td>
                                <td class="py-2 text-right text-tinta-400">${{ formatCOP(c.precio_unit) }}</td>
                                <td class="py-2 text-right font-semibold text-tinta-900">${{ formatCOP(c.subtotal) }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-linea">
                                <td colspan="4" class="pt-3 text-xs font-semibold text-tinta-500 uppercase">Precio costo</td>
                                <td class="pt-3 text-right font-semibold text-tinta-900">${{ formatCOP(ensamble.precio_costo) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Tabla de precios.
                 Sale de los canales que la empresa configuró en Segmentación, con SUS
                 nombres. Antes eran cuatro cajas escritas aquí —costo, mayorista,
                 distribuidor, cliente final— leyendo las columnas viejas: en una instalación
                 con canales propios mostraba nombres que no existen y dejaba por fuera
                 cualquier canal que la empresa hubiera creado. -->
            <div class="bg-superficie rounded-2xl shadow-sm p-5 mb-4">
                <div class="flex items-center justify-between gap-2 mb-3">
                    <h2 class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Precios por canal</h2>
                    <a v-if="puedeEditar" :href="`/ensambles/${ensamble.id}/editar`"
                        class="text-xs font-semibold" style="color:var(--marca);">Editar</a>
                </div>

                <div class="border border-linea rounded-xl overflow-hidden">
                    <div class="divide-y divide-separador">
                        <div class="flex items-center justify-between px-3 py-2.5">
                            <span class="text-xs text-tinta-400">Costo</span>
                            <span class="text-sm font-semibold text-tinta-900">${{ formatCOP(ensamble.precio_costo) }}</span>
                        </div>

                        <div v-for="c in (canales ?? [])" :key="c.segmentacion_opcion_id"
                            class="flex items-center justify-between px-3 py-2.5"
                            :style="c.es_precio_publico ? 'background:var(--pastel-azul);' : ''">
                            <span class="text-xs flex items-center gap-1.5 min-w-0">
                                <span class="truncate"
                                    :style="c.es_precio_publico ? 'color:var(--marca); font-weight:600;' : 'color:var(--tinta-400);'">
                                    {{ c.etiqueta }}
                                </span>
                                <span v-if="c.es_canal_base"
                                    class="text-[10px] px-1.5 py-0.5 rounded-full shrink-0"
                                    style="background:var(--tinta-100); color:var(--tinta-500);">base</span>
                            </span>
                            <span class="text-sm font-semibold shrink-0"
                                :class="c.precio > 0 ? '' : 'text-aviso-ambar'"
                                :style="c.es_precio_publico && c.precio > 0 ? 'color:var(--marca);' : ''">
                                {{ c.precio > 0 ? '$' + formatCOP(c.precio) : 'sin precio' }}
                            </span>
                        </div>

                        <div v-if="!(canales ?? []).length" class="px-3 py-2.5">
                            <p class="text-xs text-tinta-400">
                                No hay canales de precio configurados. Se marcan con «define precio»
                                en Configuración → Listas de segmentación.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Eliminar -->
            <div class="flex justify-end">
                <button @click="eliminar"
                    class="px-4 py-2 rounded-xl text-sm text-aviso-rojo hover:bg-pastel-rojo border border-borde-aviso-rojo transition-colors">
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
