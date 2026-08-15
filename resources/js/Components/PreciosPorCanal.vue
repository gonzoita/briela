<script setup>
/**
 * Las dos tarjetas de precios de un producto: la lista por canal y las comisiones.
 *
 * Existe como componente porque estaba escrita dentro de la pantalla de crear, y la de
 * editar tenía otra cosa —tres cajas fijas que escribían solo las columnas antiguas—. Esa
 * diferencia fue la causa de que un producto con cuatro canales quedara con datos a medias
 * al guardarlo desde editar, y de que el cuarto canal no tuviera forma de llenarse.
 *
 * Muta las filas en sitio: el padre pasa su propio arreglo y lo manda tal cual al guardar.
 */
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { usePreciosPorCanal } from '@/composables/usePreciosPorCanal'

const props = defineProps({
    // Las filas por canal. Se mutan aquí: es el mismo arreglo del formulario del padre.
    canales:     { type: Array,  required: true },
    precioCosto: { type: [Number, String], default: 0 },
    // En un producto el costo se escribe; en un ensamble sale de la suma de sus
    // componentes y escribirlo a mano solo lo desincronizaría de la receta.
    costoEditable: { type: Boolean, default: true },
})

const emit = defineEmits(['update:precioCosto'])

const canalesRef = computed(() => props.canales)
const costoRef   = computed(() => Number(props.precioCosto) || 0)

const {
    canalBase, canalesConComision, excedenteDe, minimoExigido,
    errorEscalera, descuentoMaxDe, sugerirComisiones,
} = usePreciosPorCanal(canalesRef, costoRef)

const formatCOP = (v) =>
    new Intl.NumberFormat('es-CO', { maximumFractionDigits: 0 }).format(Math.round(v ?? 0))
</script>

<template>
    <!-- Lista de precios -->
    <div class="bg-superficie rounded-2xl shadow-sm overflow-hidden">
        <div class="px-5 py-3 border-b border-linea">
            <h3 class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Lista de precios</h3>
        </div>
        <div class="p-5 space-y-4">
            <div>
                <label class="block text-xs font-medium text-tinta-500 mb-1">Precio Costo</label>
                <div class="relative">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs text-tinta-300">$</span>
                    <input v-if="costoEditable"
                        :value="precioCosto" @input="emit('update:precioCosto', Number($event.target.value) || 0)"
                        type="number" min="0" step="0.01"
                        class="w-full border rounded-xl pl-7 pr-3 py-2 text-sm focus:outline-none border-linea bg-superficie focus:border-[var(--marca)]" />
                    <input v-else :value="formatCOP(precioCosto)" readonly
                        class="w-full border border-linea rounded-xl pl-7 pr-3 py-2 text-sm bg-tinta-50 font-semibold text-tinta-700" />
                </div>
                <p v-if="! costoEditable" class="text-xs text-tinta-300 mt-1">
                    Sale de la suma de los componentes.
                </p>
            </div>

            <!-- Una fila por canal configurado en Segmentación. El nombre va como
                 encabezado y los campos se llaman «Margen %» y «Precio»: componer
                 «Margen {nombre} %» daba «Margen Precio Público %», porque el nombre lo
                 pone la empresa y la pantalla no puede asumir cómo empieza. -->
            <div v-for="canal in canales" :key="canal.segmentacion_opcion_id">
                <div class="flex items-center gap-1.5 mb-1.5 flex-wrap">
                    <span class="w-2 h-2 rounded-full shrink-0" :style="`background:${canal.color};`"/>
                    <span class="text-xs font-semibold text-tinta-600">{{ canal.etiqueta }}</span>
                    <span v-if="canal.es_canal_base" class="text-[10px] px-1.5 py-0.5 rounded-full bg-pastel-azul text-aviso-azul">canal base</span>
                    <span v-if="canal.es_precio_publico" class="text-[10px] px-1.5 py-0.5 rounded-full bg-pastel-verde text-aviso-verde">precio público</span>
                </div>
                <div class="grid grid-cols-2 gap-3 items-end">
                    <div>
                        <label class="block text-xs font-medium text-tinta-400 mb-1">Margen %</label>
                        <input v-model.number="canal.margen_pct" type="number" min="1" max="99" step="0.01"
                            class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-tinta-400 mb-1">Precio</label>
                        <div class="relative">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-xs text-tinta-300">$</span>
                            <input :value="formatCOP(canal.precio)" readonly
                                class="w-full border border-linea rounded-xl pl-7 pr-3 py-2 text-sm bg-tinta-50 font-semibold text-tinta-700" />
                        </div>
                    </div>
                </div>
            </div>

            <p v-if="canales.length && !Number(precioCosto)" class="text-xs text-aviso-ambar">
                Escribe el precio de costo y los precios de cada canal se calculan solos.
            </p>

            <div v-if="!canales.length" class="rounded-xl bg-pastel-ambar border border-borde-aviso-ambar px-3 py-2.5">
                <p class="text-xs text-aviso-ambar leading-relaxed">
                    No hay canales de precio configurados. Ve a
                    <button type="button" @click="router.visit('/administracion/segmentacion')"
                        class="font-semibold underline underline-offset-2">Segmentación</button>
                    y márcale «define precio» a los tipos de contacto que deban tener lista de precios.
                </p>
            </div>
        </div>
    </div>

    <!-- Comisión del vendedor por canal -->
    <div v-if="canales.length" class="bg-superficie rounded-2xl shadow-sm overflow-hidden">
        <div class="px-5 py-3 border-b border-linea flex items-center justify-between gap-2">
            <h3 class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Comisión vendedor por canal</h3>
            <button type="button" @click="sugerirComisiones"
                class="text-xs text-[var(--marca)] border border-[var(--marca)] rounded-lg px-3 py-1.5 hover:bg-realce transition-colors shrink-0">
                Sugerir comisiones
            </button>
        </div>
        <div class="p-5 space-y-4">
            <!-- El canal base va primero y sin campos: es el piso de utilidad de la empresa,
                 no una venta con margen para repartir. -->
            <div v-if="canalBase" class="bg-pastel-azul border border-borde-aviso-azul rounded-lg p-3">
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="text-xs font-semibold text-aviso-azul uppercase">{{ canalBase.etiqueta }}</span>
                    <span class="bg-pastel-azul-2 text-aviso-azul text-xs px-2 py-0.5 rounded-full">Sin comisión · Precio fijo</span>
                </div>
                <p class="text-xs text-blue-400 mt-1">
                    Su margen ({{ canalBase.margen_pct }}%) es la utilidad mínima garantizada de la empresa.
                    No hay comisión en este canal, y la de los demás se calcula sobre lo que se venda por
                    encima de {{ formatCOP(canalBase.precio) }}.
                </p>
            </div>

            <div v-for="(canal, i) in canalesConComision" :key="canal.segmentacion_opcion_id"
                class="border rounded-lg p-3 space-y-3"
                :style="`border-color:${canal.color}40; background:${canal.color}0a;`">
                <div class="flex items-center justify-between flex-wrap gap-1">
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-semibold uppercase" :style="`color:${canal.color};`">{{ canal.etiqueta }}</span>
                        <span v-if="canal.es_precio_publico" class="bg-pastel-verde-2 text-aviso-verde text-xs px-2 py-0.5 rounded-full">★ Mayor incentivo</span>
                    </div>
                    <span class="text-xs text-tinta-400">
                        Base: {{ formatCOP(canal.precio) }} · Excedente: {{ formatCOP(excedenteDe(canal)) }} ·
                        Desc. máx: {{ descuentoMaxDe(canal) }}%
                    </span>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs text-tinta-400 mb-1 block">
                            Comisión mínima (%)
                            <span v-if="minimoExigido(i) > 0" class="text-aviso-naranja ml-1">
                                ← mín = máx {{ canalesConComision[i - 1].etiqueta }} ({{ minimoExigido(i) }}%)
                            </span>
                        </label>
                        <div class="flex items-center gap-2">
                            <input type="number" step="0.01" :min="minimoExigido(i)" v-model.number="canal.comision_min_pct"
                                :class="['w-24 border rounded-lg px-2 py-1.5 text-sm focus:ring-2 focus:outline-none',
                                    errorEscalera(i) ? 'border-red-400 focus:ring-red-300' : 'border-tinta-200 focus:ring-[var(--marca-suave)]']" />
                            <span class="text-xs text-tinta-300">= {{ formatCOP(excedenteDe(canal) * canal.comision_min_pct / 100) }}</span>
                        </div>
                    </div>
                    <div>
                        <label class="text-xs text-tinta-400 mb-1 block">Comisión máxima (%)</label>
                        <div class="flex items-center gap-2">
                            <input type="number" step="0.01" :min="canal.comision_min_pct" v-model.number="canal.comision_max_pct"
                                class="w-24 border border-tinta-200 rounded-lg px-2 py-1.5 text-sm focus:ring-2 focus:ring-[var(--marca-suave)] focus:outline-none" />
                            <span class="text-xs text-tinta-300">= {{ formatCOP(excedenteDe(canal) * canal.comision_max_pct / 100) }}</span>
                        </div>
                    </div>
                </div>

                <p v-if="errorEscalera(i)" class="text-xs text-aviso-rojo">
                    La comisión mínima debe ser mayor o igual a la máxima de
                    {{ canalesConComision[i - 1].etiqueta }} ({{ minimoExigido(i) }}%): mientras más lejos del
                    canal base, más incentivo para el vendedor.
                </p>
                <p v-else-if="canal.comision_min_pct > 0 && canal.comision_max_pct > 0"
                    class="text-xs" :style="`color:${canal.color};`">
                    El vendedor gana entre {{ formatCOP(excedenteDe(canal) * canal.comision_min_pct / 100) }}
                    y {{ formatCOP(excedenteDe(canal) * canal.comision_max_pct / 100) }}
                </p>
            </div>
        </div>
    </div>
</template>
