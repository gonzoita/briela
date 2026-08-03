<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import { router } from '@inertiajs/vue3'
import { ref } from 'vue'

const props = defineProps({
    columnas: { type: Array, default: () => [] },
    sedes:    { type: Array, default: () => [] },
})

const csrf = () => {
    const c = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))
    return c ? decodeURIComponent(c.split('=')[1]) : ''
}

// Explicación de cada columna. El orden debe coincidir con la plantilla.
const descripciones = {
    tipo:                  { obligatoria: false, texto: '"empresa" o "persona". Si se deja vacío, es "empresa".' },
    tipo_identificacion:   { obligatoria: false, texto: 'NIT o RUT para empresas; CC, CE o PA para personas. Si se deja vacío se elige según el tipo.' },
    numero_identificacion: { obligatoria: false, texto: 'NIT o cédula. Puedes escribirlo con puntos y guiones; se limpia solo. Si ya existe en el sistema, ese cliente se ACTUALIZA en vez de crear uno duplicado. El dígito de verificación se calcula solo, no lo pongas en columna aparte.' },
    nombre:                { obligatoria: true,  texto: 'Razón social de la empresa, o el nombre si es persona natural.' },
    apellido:              { obligatoria: false, texto: 'Solo para personas naturales.' },
    email:                 { obligatoria: false, texto: 'Correo de la empresa o de la persona.' },
    telefono:              { obligatoria: false, texto: 'Teléfono fijo.' },
    celular:               { obligatoria: false, texto: 'Celular.' },
    ciudad:                { obligatoria: false, texto: 'Ciudad del cliente.' },
    direccion:             { obligatoria: false, texto: 'Dirección.' },
    sede:                  { obligatoria: false, texto: 'Nombre exacto de la sede a la que pertenece el cliente. Si se deja vacío, queda en la sede activa. Si escribes una que no existe, la fila falla.' },
    activo:                { obligatoria: false, texto: '"Si" o "No". Por defecto Si.' },
    requiere_anticipo:     { obligatoria: false, texto: '"Si" o "No". Por defecto No.' },
    industrias:            { obligatoria: false, texto: 'Separadas por coma. Ej: "Alimentos,Retail".' },
    fuentes_contacto:      { obligatoria: false, texto: 'Cómo llegó el cliente, separadas por coma. Ej: "Referido,Página web".' },
    notas:                 { obligatoria: false, texto: 'Comentario libre.' },
    contacto_nombre:       { obligatoria: false, texto: 'Persona de contacto en la empresa. Sin esto, la empresa queda cargada pero no se le puede cotizar hasta agregarle un contacto.' },
    contacto_apellido:     { obligatoria: false, texto: 'Apellido del contacto.' },
    contacto_cargo:        { obligatoria: false, texto: 'Cargo del contacto. Ej: "Jefe de compras".' },
    contacto_email:        { obligatoria: false, texto: 'Correo del contacto.' },
    contacto_telefono:     { obligatoria: false, texto: 'Teléfono del contacto.' },
    contacto_celular:      { obligatoria: false, texto: 'Celular del contacto.' },
}

const archivo       = ref(null)
const nombreArchivo = ref('')
const importando    = ref(false)
const resultado     = ref(null)
const error         = ref('')

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

        const res = await fetch('/clientes/importar', {
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
    <AppLayout title="Importar clientes">
        <div class="max-w-3xl mx-auto">

            <div class="flex items-center gap-3 mb-4">
                <button class="text-gray-400 hover:text-gray-700" @click="router.visit('/clientes')">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                    </svg>
                </button>
                <h1 class="text-xl font-bold text-gray-900">Importar clientes desde CSV</h1>
            </div>

            <!-- Paso 1: plantilla -->
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 mb-4">
                <h2 class="text-sm font-bold text-gray-800 mb-2">1. Descarga la plantilla</h2>
                <p class="text-sm text-gray-500 mb-3">
                    Trae los encabezados correctos y dos filas de ejemplo: una empresa con
                    contacto y una persona natural. Solo la columna <strong>nombre</strong> es
                    obligatoria.
                </p>
                <a href="/clientes/importar/plantilla"
                    class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold text-white"
                    style="background:var(--marca);">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                    </svg>
                    Descargar plantilla CSV
                </a>
                <p v-if="sedes.length" class="text-xs text-gray-400 mt-3">
                    Sedes disponibles para la columna <span class="font-mono">sede</span>:
                    <span class="font-medium text-gray-600">{{ sedes.join(', ') }}</span>
                </p>
            </div>

            <!-- Paso 2: subir -->
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 mb-4">
                <h2 class="text-sm font-bold text-gray-800 mb-2">2. Sube tu archivo</h2>
                <p class="text-sm text-gray-500 mb-3">
                    Si el número de identificación ya existe, ese cliente se
                    <strong>actualiza</strong> (las columnas que dejes vacías no se tocan). Si no
                    existe, se crea nuevo. Puedes reimportar el mismo archivo sin miedo a
                    duplicar.
                </p>
                <label class="flex items-center gap-3 border-2 border-dashed border-gray-200 rounded-xl px-4 py-3 cursor-pointer hover:border-blue-300">
                    <svg class="w-5 h-5 text-gray-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                    </svg>
                    <span class="text-sm text-gray-600 truncate">{{ nombreArchivo || 'Elegir archivo .csv' }}</span>
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
            <div v-if="resultado" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5 mb-4">
                <h2 class="text-sm font-bold text-gray-800 mb-3">Resultado</h2>
                <div class="grid grid-cols-3 gap-3 mb-4">
                    <div class="bg-green-50 rounded-xl p-3 text-center">
                        <p class="text-xl font-bold text-green-700">{{ resultado.creados }}</p>
                        <p class="text-xs text-green-600 mt-0.5">Creados</p>
                    </div>
                    <div class="bg-blue-50 rounded-xl p-3 text-center">
                        <p class="text-xl font-bold text-blue-700">{{ resultado.actualizados }}</p>
                        <p class="text-xs text-blue-600 mt-0.5">Actualizados</p>
                    </div>
                    <div class="bg-red-50 rounded-xl p-3 text-center">
                        <p class="text-xl font-bold text-red-700">{{ resultado.errores.length }}</p>
                        <p class="text-xs text-red-600 mt-0.5">Con error</p>
                    </div>
                </div>

                <div v-if="resultado.errores.length" class="space-y-1.5 mb-3">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Filas con error</p>
                    <div v-for="e in resultado.errores" :key="e.fila"
                        class="bg-red-50 rounded-lg px-3 py-2 text-xs text-red-700">
                        Fila {{ e.fila }}: {{ e.motivo }}
                    </div>
                </div>

                <div v-if="resultado.sin_contacto?.length" class="space-y-1.5">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Empresas sin contacto</p>
                    <p class="text-xs text-gray-500">
                        Se cargaron bien, pero no se les puede hacer una cotización hasta que les
                        agregues una persona de contacto.
                    </p>
                    <div v-for="s in resultado.sin_contacto" :key="s.fila"
                        class="bg-amber-50 rounded-lg px-3 py-2 text-xs text-amber-800">
                        Fila {{ s.fila }}: {{ s.cliente }}
                    </div>
                </div>

                <button v-if="resultado.creados + resultado.actualizados > 0" @click="router.visit('/clientes')"
                    class="mt-4 w-full py-2 rounded-xl text-sm font-medium text-gray-600 border border-gray-200 hover:bg-gray-50">
                    Ver clientes
                </button>
            </div>

            <!-- Guía de columnas -->
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                <h2 class="text-sm font-bold text-gray-800 mb-3">Guía de columnas</h2>
                <div class="divide-y divide-gray-50">
                    <div v-for="col in columnas" :key="col" class="py-2 flex items-start gap-3">
                        <span class="shrink-0 font-mono text-xs px-2 py-1 rounded bg-gray-100 text-gray-700 w-48 truncate">{{ col }}</span>
                        <div class="flex-1 min-w-0">
                            <span v-if="descripciones[col]?.obligatoria" class="text-[10px] font-bold text-red-500 uppercase mr-1">Obligatoria</span>
                            <span class="text-xs text-gray-500">{{ descripciones[col]?.texto ?? '' }}</span>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </AppLayout>
</template>
