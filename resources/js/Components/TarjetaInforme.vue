<script setup>
/**
 * La tarjeta del informe que la guía generó en el chat: las cifras clave, un resumen, unas filas
 * de muestra y las descargas.
 *
 * Las cifras las calculó el servidor (MotorInformes), no la IA: la IA solo escribió el análisis
 * que va encima, con los totales. El archivo completo se descarga de /informes/{id}, con los
 * permisos de quien mira.
 */
import { computed } from 'vue'
import { formatoValor, esNumerico } from '@/informes'

const props = defineProps({
    informe: { type: Object, required: true },
})

const metricas = computed(() => props.informe.metricas ?? [])
const resumen  = computed(() => props.informe.resumen)
const columnas = computed(() => props.informe.columnas ?? [])
const muestra  = computed(() => props.informe.muestra ?? [])
</script>

<template>
    <div class="mt-3 rounded-xl border border-linea bg-superficie overflow-hidden whitespace-normal">
        <div class="px-3 py-2.5 border-b border-linea">
            <p class="text-sm font-semibold text-tinta-900 leading-tight">{{ informe.nombre }}</p>
            <p class="text-[11px] text-tinta-400 mt-0.5">
                {{ informe.fuente }} · {{ informe.periodo }} · {{ formatoValor(informe.total_filas, 'entero') }} filas
            </p>
        </div>

        <!-- Cifras clave -->
        <div v-if="metricas.length" class="grid grid-cols-2 gap-px bg-separador">
            <div v-for="m in metricas" :key="m.clave" class="bg-superficie px-3 py-2">
                <p class="text-[10px] text-tinta-400 leading-tight">{{ m.etiqueta }}</p>
                <p class="text-sm font-semibold text-tinta-900 mt-0.5 truncate" :title="formatoValor(m.valor, m.tipo)">{{ formatoValor(m.valor, m.tipo) }}</p>
            </div>
        </div>

        <!-- El primer resumen -->
        <div v-if="resumen && resumen.filas.length" class="px-3 pt-3">
            <p class="text-[10px] font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1">{{ resumen.titulo }}</p>
            <div class="overflow-x-auto">
                <table class="w-full text-[11px]">
                    <thead>
                        <tr class="text-tinta-400">
                            <th v-for="c in resumen.columnas" :key="c.clave"
                                class="py-1 pr-2 font-medium" :class="esNumerico(c.tipo) ? 'text-right' : 'text-left'">{{ c.etiqueta }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-separador">
                        <tr v-for="(f, i) in resumen.filas" :key="i" class="text-tinta-700">
                            <td v-for="c in resumen.columnas" :key="c.clave"
                                class="py-1 pr-2 whitespace-nowrap" :class="esNumerico(c.tipo) ? 'text-right' : 'text-left'">{{ formatoValor(f[c.clave], c.tipo) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Unas filas del detalle, para ver qué trae el archivo -->
        <div v-if="muestra.length" class="px-3 pt-3">
            <p class="text-[10px] font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1">Primeras filas del detalle</p>
            <div class="overflow-x-auto">
                <table class="w-full text-[11px]">
                    <thead>
                        <tr class="text-tinta-400">
                            <th v-for="c in columnas" :key="c.clave"
                                class="py-1 pr-2 font-medium whitespace-nowrap" :class="esNumerico(c.tipo) ? 'text-right' : 'text-left'">{{ c.etiqueta }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-separador">
                        <tr v-for="(f, i) in muestra" :key="i" class="text-tinta-700">
                            <td v-for="c in columnas" :key="c.clave"
                                class="py-1 pr-2 whitespace-nowrap max-w-[10rem] truncate" :class="esNumerico(c.tipo) ? 'text-right' : 'text-left'">{{ formatoValor(f[c.clave], c.tipo) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <p v-else class="px-3 pt-3 text-xs text-tinta-400">No hay datos en ese periodo.</p>

        <!-- Descargas -->
        <div class="flex flex-wrap gap-2 p-3">
            <a v-if="informe.xlsx" :href="informe.urls.xlsx" download
                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-white"
                style="background:#107C41;">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0-4-4m4 4 4-4M4 20h16"/></svg>
                Excel
            </a>
            <a :href="informe.urls.csv" download
                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-linea text-xs font-medium text-tinta-700 hover:bg-realce">
                CSV
            </a>
            <a :href="informe.urls.ver"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-linea text-xs font-medium text-tinta-700 hover:bg-realce">
                Abrir en Informes
            </a>
        </div>
    </div>
</template>
