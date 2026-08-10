<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import { router } from '@inertiajs/vue3'
import { ref } from 'vue'

defineProps({
    columnas: { type: Array, default: () => [] },
})

const csrf = () => {
    const c = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))
    return c ? decodeURIComponent(c.split('=')[1]) : ''
}

// Explicación de cada columna — el orden importa, debe coincidir con la plantilla.
const descripciones = {
    nombre:                        { obligatoria: true,  texto: 'Nombre del producto o servicio.' },
    tipo:                          { obligatoria: false, texto: '"producto" o "servicio". Si se deja vacío, es "producto".' },
    referencia:                    { obligatoria: false, texto: 'Código único. Si se deja vacío se genera solo. Si coincide con uno que ya existe, ese producto se actualiza en vez de crear uno nuevo.' },
    categoria:                     { obligatoria: false, texto: 'Nombre de la categoría. Si no existe, se crea sola.' },
    proveedor:                     { obligatoria: false, texto: 'Nombre del proveedor. Si no existe, se crea solo.' },
    unidad_medida:                 { obligatoria: false, texto: 'Ej: unidad, m2, kg. Por defecto "unidad".' },
    descripcion_corta:             { obligatoria: false, texto: 'Texto corto para catálogo/cotizaciones.' },
    descripcion_larga:             { obligatoria: false, texto: 'Descripción más detallada.' },
    es_vendible:                   { obligatoria: false, texto: '"Si" o "No" — si aparece en cotizaciones. Por defecto Si.' },
    es_insumo:                     { obligatoria: false, texto: '"Si" o "No" — si se usa como insumo interno. Por defecto No.' },
    inventariable:                 { obligatoria: false, texto: '"Si" o "No" — si maneja stock. Por defecto Si para productos.' },
    activo:                        { obligatoria: false, texto: '"Si" o "No". Por defecto Si.' },
    precio_costo:                  { obligatoria: false, texto: 'Precio de costo. Solo números, sin puntos de miles.' },
    margen_mayorista:              { obligatoria: false, texto: '% de margen mayorista. Por defecto 25.' },
    margen_distribuidor:           { obligatoria: false, texto: '% de margen distribuidor. Por defecto 30.' },
    margen_cliente_final:          { obligatoria: false, texto: '% de margen cliente final. Por defecto 35.' },
    precio_mayorista:              { obligatoria: false, texto: 'Precio de venta mayorista (si lo dejas vacío y no hay margen calculado, queda en 0).' },
    precio_distribuidor:           { obligatoria: false, texto: 'Precio de venta distribuidor.' },
    precio_cliente_final:          { obligatoria: false, texto: 'Precio de venta cliente final.' },
    comision_pct_minima:           { obligatoria: false, texto: '% comisión mínima vendedor.' },
    comision_pct_maxima:           { obligatoria: false, texto: '% comisión máxima vendedor.' },
    comision_min_distribuidor:     { obligatoria: false, texto: '% comisión mínima canal distribuidor.' },
    comision_max_distribuidor:     { obligatoria: false, texto: '% comisión máxima canal distribuidor.' },
    comision_min_cliente_final:    { obligatoria: false, texto: '% comisión mínima canal cliente final.' },
    comision_max_cliente_final:    { obligatoria: false, texto: '% comisión máxima canal cliente final.' },
    utilidad_minima_empresa_pct:   { obligatoria: false, texto: '% de utilidad mínima que exige la empresa. Por defecto 15.' },
    descuento_max_cliente_final:   { obligatoria: false, texto: '% descuento máximo permitido a cliente final.' },
    descuento_max_distribuidor:    { obligatoria: false, texto: '% descuento máximo permitido a distribuidor.' },
    descuento_max_mayorista:       { obligatoria: false, texto: '% descuento máximo permitido a mayorista.' },
    stock_minimo:                  { obligatoria: false, texto: 'Umbral para alertas de stock bajo.' },
    stock_maximo:                  { obligatoria: false, texto: 'Umbral máximo de stock.' },
    stock_inicial:                 { obligatoria: false, texto: 'Cantidad inicial a cargar. Solo aplica al CREAR el producto — si reimportas el mismo archivo no se vuelve a sumar.' },
    bodega:                        { obligatoria: false, texto: 'Nombre de la bodega para el stock inicial. Si se deja vacío usa la bodega principal.' },
    es_padre:                      { obligatoria: false, texto: '"Si" si esta fila es un producto "padre" que agrupa variantes (ej: una puerta que viene en varios colores). Un padre no lleva precio ni stock propio.' },
    producto_padre:                { obligatoria: false, texto: 'Referencia del producto padre — solo se llena en las filas que son variantes de ese padre. El padre debe existir o venir antes en el mismo archivo.' },
    atributo_variante:             { obligatoria: false, texto: 'Solo en la fila del padre: nombre del atributo que varía, ej: "Color".' },
    valor_variante:                { obligatoria: false, texto: 'Solo en filas de variante: el valor específico, ej: "Blanco".' },
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
            <div class="bg-white rounded-2xl border border-linea shadow-sm p-5 mb-4">
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
            <div class="bg-white rounded-2xl border border-linea shadow-sm p-5 mb-4">
                <h2 class="text-sm font-semibold text-tinta-900 mb-2">2. Sube tu archivo</h2>
                <p class="text-sm text-tinta-400 mb-3">
                    Si una referencia del archivo ya existe en el sistema, ese producto se <strong>actualiza</strong> (las columnas que dejes vacías no se tocan). Si no existe, se crea nuevo.
                </p>
                <label class="flex items-center gap-3 border-2 border-dashed border-linea rounded-xl px-4 py-3 cursor-pointer hover:border-blue-300">
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
                <p v-if="error" class="text-xs text-red-500 mt-2">{{ error }}</p>
            </div>

            <!-- Resultado -->
            <div v-if="resultado" class="bg-white rounded-2xl border border-linea shadow-sm p-5 mb-4">
                <h2 class="text-sm font-semibold text-tinta-900 mb-3">Resultado</h2>
                <div class="grid grid-cols-3 gap-3 mb-4">
                    <div class="bg-green-50 rounded-xl p-3 text-center">
                        <p class="text-xl font-semibold text-green-700">{{ resultado.creados }}</p>
                        <p class="text-xs text-green-600 mt-0.5">Creados</p>
                    </div>
                    <div class="bg-blue-50 rounded-xl p-3 text-center">
                        <p class="text-xl font-semibold text-blue-700">{{ resultado.actualizados }}</p>
                        <p class="text-xs text-blue-600 mt-0.5">Actualizados</p>
                    </div>
                    <div class="bg-red-50 rounded-xl p-3 text-center">
                        <p class="text-xl font-semibold text-red-700">{{ resultado.errores.length }}</p>
                        <p class="text-xs text-red-600 mt-0.5">Con error</p>
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
                    <div v-for="e in resultado.errores" :key="e.fila" class="bg-red-50 rounded-lg px-3 py-2 text-xs text-red-700">
                        Fila {{ e.fila }}: {{ e.motivo }}
                    </div>
                </div>

                <button v-if="resultado.creados + resultado.actualizados > 0" @click="router.visit('/productos')"
                    class="mt-4 w-full py-2 rounded-xl text-sm font-medium text-tinta-500 border border-linea hover:bg-tinta-50">
                    Ver productos
                </button>
            </div>

            <!-- Guía de columnas -->
            <div class="bg-white rounded-2xl border border-linea shadow-sm p-5">
                <h2 class="text-sm font-semibold text-tinta-900 mb-3">Guía de columnas</h2>
                <div class="divide-y divide-gray-50">
                    <div v-for="col in columnas" :key="col" class="py-2 flex items-start gap-3">
                        <span class="shrink-0 font-mono text-xs px-2 py-1 rounded bg-tinta-100 text-tinta-700 w-48 truncate">{{ col }}</span>
                        <div class="flex-1 min-w-0">
                            <span v-if="descripciones[col]?.obligatoria" class="text-[10px] font-semibold text-red-500 uppercase mr-1">Obligatoria</span>
                            <span class="text-xs text-tinta-400">{{ descripciones[col]?.texto ?? '' }}</span>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </AppLayout>
</template>
