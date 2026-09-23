<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import { router } from '@inertiajs/vue3'
import { ref, computed } from 'vue'

const props = defineProps({
    // Cada columna con su explicación y su grupo. Salen del servidor: las de precio dependen
    // de los canales que la empresa tenga en Segmentación, así que no se pueden escribir aquí.
    columnas: { type: Array, default: () => [] },
})

// Agrupadas en el orden en que vienen, que es el de la plantilla.
const grupos = computed(() => {
    const mapa = new Map()
    for (const col of props.columnas) {
        if (! mapa.has(col.grupo)) mapa.set(col.grupo, [])
        mapa.get(col.grupo).push(col)
    }
    return [...mapa.entries()].map(([nombre, columnas]) => ({ nombre, columnas }))
})

const csrf = () => {
    const c = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))
    return c ? decodeURIComponent(c.split('=')[1]) : ''
}

const archivo    = ref(null)
const nombreArchivo = ref('')
const importando = ref(false)
const resultado   = ref(null)
const error       = ref('')

function onFileChange(e) {
    archivo.value = e.target.files?.[0] ?? null
    nombreArchivo.value = archivo.value?.name ?? ''
}

async function importar() {
    if (!archivo.value) { error.value = 'Selecciona un archivo CSV primero.'; return }
    importando.value = true
    error.value      = ''
    resultado.value  = null
    try {
        const fd = new FormData()
        fd.append('archivo', archivo.value)
        const res = await fetch('/productos/importar', {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
            body: fd,
        })
        const data = await res.json().catch(() => null)
        if (!res.ok) {
            error.value = data?.message || `Error del servidor (${res.status})`
            return
        }
        resultado.value = data
    } catch (e) {
        error.value = e.message || 'Error al importar el archivo.'
    } finally {
        importando.value = false
    }
}
</script>

<template>
    <AppLayout title="Importar productos">
        <div class="max-w-3xl mx-auto">

            <div class="flex items-center gap-3 mb-4">
                <button class="text-tinta-300 hover:text-tinta-700" @click="router.visit('/productos')">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M15 19l-7-7 7-7"/>
                    </svg>
                </button>
                <h1 class="text-xl font-semibold text-tinta-900">Importar productos desde CSV</h1>
            </div>

            <!-- Paso 1: plantilla -->
            <div class="bg-superficie rounded-2xl border border-linea shadow-sm p-5 mb-4">
                <h2 class="text-sm font-semibold text-tinta-900 mb-2">1. Descarga la plantilla</h2>
                <p class="text-sm text-tinta-400 mb-3">
                    Trae los encabezados correctos y filas de ejemplo (producto simple, servicio, y un producto con variantes). Solo la columna <strong>nombre</strong> es obligatoria — el resto se puede dejar vacío.
                </p>
                <a href="/productos/importar/plantilla"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold text-white"
                    style="background:var(--marca);">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                    </svg>
                    Descargar plantilla CSV
                </a>
            </div>

            <!-- Paso 2: subir -->
            <div class="bg-superficie rounded-2xl border border-linea shadow-sm p-5 mb-4">
                <h2 class="text-sm font-semibold text-tinta-900 mb-2">2. Sube tu archivo</h2>
                <p class="text-sm text-tinta-400 mb-3">
                    Si una referencia del archivo ya existe en el sistema, ese producto se <strong>actualiza</strong> (las columnas que dejes vacías no se tocan). Si no existe, se crea nuevo.
                </p>
                <label class="flex items-center gap-3 border-2 border-dashed border-linea rounded-xl px-4 py-3 cursor-pointer hover:border-borde-aviso-azul">
                    <svg class="w-5 h-5 text-tinta-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                    </svg>
                    <span class="text-sm text-tinta-500 truncate">{{ nombreArchivo || 'Elegir archivo .csv' }}</span>
                    <input type="file" accept=".csv,text/csv" class="hidden" @change="onFileChange" />
                </label>
                <button @click="importar" :disabled="importando || !archivo"
                    class="mt-3 w-full py-2.5 rounded-xl text-sm font-semibold text-white disabled:opacity-60"
                    style="background:var(--marca);">
                    {{ importando ? 'Importando...' : 'Importar' }}
                </button>
                <p v-if="error" class="text-xs text-aviso-rojo mt-2">{{ error }}</p>
            </div>

            <!-- Resultado -->
            <div v-if="resultado" class="bg-superficie rounded-2xl border border-linea shadow-sm p-5 mb-4">
                <h2 class="text-sm font-semibold text-tinta-900 mb-3">Resultado</h2>
                <div class="grid grid-cols-3 gap-3 mb-4">
                    <div class="bg-pastel-verde rounded-xl p-3 text-center">
                        <p class="text-xl font-semibold text-aviso-verde">{{ resultado.creados }}</p>
                        <p class="text-xs text-aviso-verde mt-0.5">Creados</p>
                    </div>
                    <div class="bg-pastel-azul rounded-xl p-3 text-center">
                        <p class="text-xl font-semibold text-aviso-azul">{{ resultado.actualizados }}</p>
                        <p class="text-xs text-aviso-azul mt-0.5">Actualizados</p>
                    </div>
                    <div class="bg-pastel-rojo rounded-xl p-3 text-center">
                        <p class="text-xl font-semibold text-aviso-rojo">{{ resultado.errores.length }}</p>
                        <p class="text-xs text-aviso-rojo mt-0.5">Con error</p>
                    </div>
                </div>

                <div v-if="resultado.categorias_creadas.length" class="text-xs text-tinta-400 mb-2">
                    Categorías nuevas: <span class="font-medium text-tinta-700">{{ resultado.categorias_creadas.join(', ') }}</span>
                </div>
                <div v-if="resultado.proveedores_creados.length" class="text-xs text-tinta-400 mb-2">
                    Proveedores nuevos: <span class="font-medium text-tinta-700">{{ resultado.proveedores_creados.join(', ') }}</span>
                </div>

                <div v-if="resultado.errores.length" class="mt-3 space-y-1.5">
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Filas con error</p>
                    <div v-for="e in resultado.errores" :key="e.fila" class="bg-pastel-rojo rounded-lg px-3 py-2 text-xs text-aviso-rojo">
                        Fila {{ e.fila }}: {{ e.motivo }}
                    </div>
                </div>

                <button v-if="resultado.creados + resultado.actualizados > 0" @click="router.visit('/productos')"
                    class="mt-4 w-full py-2 rounded-xl text-sm font-medium text-tinta-500 border border-linea hover:bg-tinta-50">
                    Ver productos
                </button>
            </div>

            <!-- Guía de columnas -->
            <div class="bg-superficie rounded-2xl border border-linea shadow-sm p-5">
                <h2 class="text-sm font-semibold text-tinta-900 mb-1">Guía de columnas</h2>
                <p class="text-xs text-tinta-400 mb-3">
                    Las columnas de precio salen de los canales configurados en
                    <a href="/administracion/segmentacion" class="underline hover:text-tinta-700">Segmentación</a>:
                    si creas un canal nuevo, vuelve a descargar la plantilla y ya trae sus columnas.
                    Un archivo hecho con la plantilla anterior sigue sirviendo.
                </p>
                <div v-for="g in grupos" :key="g.nombre" class="mb-4 last:mb-0">
                    <p class="text-[11px] font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1">{{ g.nombre }}</p>
                    <div class="divide-y divide-separador">
                        <div v-for="col in g.columnas" :key="col.columna" class="py-2 flex flex-col sm:flex-row sm:items-start gap-1 sm:gap-3">
                            <span class="shrink-0 font-mono text-xs px-2 py-1 rounded bg-tinta-100 text-tinta-700 sm:w-56 truncate self-start">{{ col.columna }}</span>
                            <div class="flex-1 min-w-0">
                                <span v-if="col.obligatoria" class="text-[10px] font-semibold text-aviso-rojo uppercase mr-1">Obligatoria</span>
                                <span class="text-xs text-tinta-400">{{ col.texto }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </AppLayout>
</template>
