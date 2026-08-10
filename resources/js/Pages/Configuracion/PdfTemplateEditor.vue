<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import { ref, reactive, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { useClipboard } from '@/composables/useClipboard'
import { colorMarca } from '@/marca'

const { copyText } = useClipboard()

const props = defineProps({
    modulo:    String,
    label:     String,
    secciones: Array,
    variables: Array,
    template:  Object,
})

const tabActivo   = ref('general')
const guardando   = ref(false)
const guardado    = ref(false)
const sinGuardar  = ref(false)
const previewKey  = ref(0)
const copiadoVar  = ref(null)
const logoPreview = ref(props.template?.logo_url ?? null)
const subiendoLogo = ref(false)

const form = reactive({
    nombre:               props.template?.nombre               ?? 'Plantilla por defecto',
    color_primario:       props.template?.color_primario       ?? colorMarca(),
    color_secundario:     props.template?.color_secundario     ?? '#F8FAFC',
    color_texto:          props.template?.color_texto          ?? '#1A1A1A',
    fuente:               props.template?.fuente               ?? 'Arial',
    tamanio_fuente:       props.template?.tamanio_fuente       ?? 10,
    mostrar_logo:         props.template?.mostrar_logo         ?? true,
    logo_posicion:        props.template?.logo_posicion        ?? 'izquierda',
    logo_ancho:           props.template?.logo_ancho           ?? 120,
    mostrar_encabezado:   props.template?.mostrar_encabezado   ?? true,
    encabezado_titulo:    props.template?.encabezado_titulo    ?? '',
    encabezado_subtitulo: props.template?.encabezado_subtitulo ?? '',
    encabezado_html:      props.template?.encabezado_html      ?? '',
    mostrar_pie:          props.template?.mostrar_pie          ?? true,
    pie_html:             props.template?.pie_html             ?? '',
    pie_texto:            props.template?.pie_texto            ?? '',
    secciones_visibles:   props.template?.secciones_visibles   ?? [...(props.secciones ?? [])],
})

const usarEncabezadoHtml = ref(!!(props.template?.encabezado_html))
const usarPieHtml        = ref(!!(props.template?.pie_html))

const tabs = [
    { key: 'general',    label: 'General' },
    { key: 'logo',       label: 'Logo y encabezado' },
    { key: 'pie',        label: 'Pie de página' },
    { key: 'secciones',  label: 'Secciones' },
    { key: 'variables',  label: 'Variables' },
]

const previewUrl = computed(() => `/configuracion/pdf-templates/${props.modulo}/preview`)

function marcarCambio() {
    sinGuardar.value = true
    guardado.value   = false
}

async function guardar() {
    guardando.value = true
    try {
        const payload = { ...form }
        if (!usarEncabezadoHtml.value) payload.encabezado_html = null
        if (!usarPieHtml.value)        payload.pie_html        = null

        const csrfToken = document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1]
        const res = await fetch(`/configuracion/pdf-templates/${props.modulo}`, {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '',
            },
            body: JSON.stringify(payload),
        })
        if (!res.ok) throw new Error('Error al guardar')
        guardado.value   = true
        sinGuardar.value = false
    } catch (e) {
        alert('Error al guardar la plantilla')
    } finally {
        guardando.value = false
    }
}

function actualizarPreview() {
    previewKey.value++
}

async function subirLogo(event) {
    const file = event.target.files?.[0]
    if (!file) return
    subiendoLogo.value = true
    try {
        const fd = new FormData()
        fd.append('logo', file)
        const csrfToken = document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1]
        const res = await fetch(`/configuracion/pdf-templates/${props.modulo}/logo`, {
            method: 'POST',
            headers: { 'X-XSRF-TOKEN': csrfToken ? decodeURIComponent(csrfToken) : '' },
            body: fd,
        })
        const data = await res.json()
        logoPreview.value = data.logo_url
        marcarCambio()
    } catch {
        alert('Error al subir el logo')
    } finally {
        subiendoLogo.value = false
    }
}

function toggleSeccion(seccion) {
    const idx = form.secciones_visibles.indexOf(seccion)
    if (idx >= 0) {
        form.secciones_visibles.splice(idx, 1)
    } else {
        form.secciones_visibles.push(seccion)
    }
    marcarCambio()
}

function seccionActiva(seccion) {
    return form.secciones_visibles.includes(seccion)
}

async function copiarVariable(variable) {
    if (await copyText(variable)) {
        copiadoVar.value = variable
        setTimeout(() => { copiadoVar.value = null }, 1500)
    } else {
        copiadoVar.value = null
    }
}
</script>

<template>
    <AppLayout :title="`Editar Plantilla — ${label}`">
        <div class="max-w-7xl mx-auto">
            <!-- Breadcrumb -->
            <div class="flex items-center gap-2 text-sm text-tinta-400 mb-4">
                <button @click="router.visit('/configuracion/pdf-templates')" class="hover:text-tinta-700 transition-colors">
                    Plantillas PDF
                </button>
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                </svg>
                <span class="text-tinta-900 font-medium">{{ label }}</span>
            </div>

            <!-- Layout dos paneles -->
            <div class="flex flex-col lg:flex-row gap-5" style="min-height: 70vh;">

                <!-- ── Panel izquierdo (controles) ──────────────────────────── -->
                <div class="w-full lg:w-[420px] shrink-0 flex flex-col gap-0">
                    <div class="bg-superficie rounded-2xl shadow-sm border border-linea flex flex-col" style="min-height:600px;">

                        <!-- Nombre del template -->
                        <div class="px-5 pt-5 pb-3 border-b border-linea">
                            <label class="block text-xs font-medium text-tinta-500 mb-1">Nombre de la plantilla</label>
                            <input
                                v-model="form.nombre"
                                @input="marcarCambio"
                                type="text"
                                class="w-full text-sm border border-linea rounded-xl px-3 py-2 focus:outline-none focus:ring-2"
                                style="--tw-ring-color: var(--marca);"
                                placeholder="Ej: Plantilla corporativa azul"
                            />
                        </div>

                        <!-- Tabs -->
                        <div class="flex border-b border-linea overflow-x-auto">
                            <button
                                v-for="tab in tabs"
                                :key="tab.key"
                                @click="tabActivo = tab.key"
                                class="px-4 py-3 text-xs font-medium whitespace-nowrap border-b-2 transition-colors shrink-0"
                                :class="tabActivo === tab.key
                                    ? 'border-[var(--marca)] text-[var(--marca)]'
                                    : 'border-transparent text-tinta-400 hover:text-tinta-700'"
                            >
                                {{ tab.label }}
                            </button>
                        </div>

                        <!-- Contenido tabs -->
                        <div class="flex-1 overflow-y-auto px-5 py-5 space-y-5">

                            <!-- TAB: General -->
                            <template v-if="tabActivo === 'general'">
                                <!-- Color primario -->
                                <div>
                                    <label class="block text-xs font-medium text-tinta-500 mb-1.5">Color primario</label>
                                    <div class="flex items-center gap-2">
                                        <input
                                            v-model="form.color_primario"
                                            @input="marcarCambio"
                                            type="color"
                                            class="w-10 h-10 rounded-lg border border-linea cursor-pointer p-0.5"
                                        />
                                        <input
                                            v-model="form.color_primario"
                                            @input="marcarCambio"
                                            type="text"
                                            maxlength="7"
                                            class="flex-1 text-sm border border-linea rounded-xl px-3 py-2 font-mono focus:outline-none focus:ring-2"
                                            style="--tw-ring-color: var(--marca);"
                                            placeholder="#2563EB"
                                        />
                                    </div>
                                </div>
                                <!-- Color secundario -->
                                <div>
                                    <label class="block text-xs font-medium text-tinta-500 mb-1.5">Color secundario (fondos, badges)</label>
                                    <div class="flex items-center gap-2">
                                        <input
                                            v-model="form.color_secundario"
                                            @input="marcarCambio"
                                            type="color"
                                            class="w-10 h-10 rounded-lg border border-linea cursor-pointer p-0.5"
                                        />
                                        <input
                                            v-model="form.color_secundario"
                                            @input="marcarCambio"
                                            type="text"
                                            maxlength="7"
                                            class="flex-1 text-sm border border-linea rounded-xl px-3 py-2 font-mono focus:outline-none focus:ring-2"
                                            style="--tw-ring-color: var(--marca);"
                                            placeholder="#F8FAFC"
                                        />
                                    </div>
                                </div>
                                <!-- Color texto -->
                                <div>
                                    <label class="block text-xs font-medium text-tinta-500 mb-1.5">Color de texto</label>
                                    <div class="flex items-center gap-2">
                                        <input
                                            v-model="form.color_texto"
                                            @input="marcarCambio"
                                            type="color"
                                            class="w-10 h-10 rounded-lg border border-linea cursor-pointer p-0.5"
                                        />
                                        <input
                                            v-model="form.color_texto"
                                            @input="marcarCambio"
                                            type="text"
                                            maxlength="7"
                                            class="flex-1 text-sm border border-linea rounded-xl px-3 py-2 font-mono focus:outline-none focus:ring-2"
                                            style="--tw-ring-color: var(--marca);"
                                            placeholder="#1A1A1A"
                                        />
                                    </div>
                                </div>
                                <!-- Fuente -->
                                <div>
                                    <label class="block text-xs font-medium text-tinta-500 mb-1.5">Fuente</label>
                                    <select
                                        v-model="form.fuente"
                                        @change="marcarCambio"
                                        class="w-full text-sm border border-linea rounded-xl px-3 py-2 focus:outline-none"
                                    >
                                        <option value="Arial">Arial</option>
                                        <option value="Helvetica">Helvetica</option>
                                        <option value="Times New Roman">Times New Roman</option>
                                        <option value="Courier">Courier</option>
                                        <option value="DejaVu Sans">DejaVu Sans</option>
                                    </select>
                                </div>
                                <!-- Tamaño fuente -->
                                <div>
                                    <label class="block text-xs font-medium text-tinta-500 mb-1.5">
                                        Tamaño de fuente: <span class="font-semibold text-tinta-900">{{ form.tamanio_fuente }}px</span>
                                    </label>
                                    <input
                                        v-model.number="form.tamanio_fuente"
                                        @input="marcarCambio"
                                        type="range"
                                        min="8"
                                        max="16"
                                        step="1"
                                        class="w-full"
                                    />
                                    <div class="flex justify-between text-xs text-tinta-300 mt-1">
                                        <span>8px (pequeño)</span>
                                        <span>16px (grande)</span>
                                    </div>
                                </div>

                                <!-- Muestra de paleta -->
                                <div class="rounded-xl overflow-hidden border border-linea">
                                    <div class="px-4 py-3 text-white text-sm font-semibold" :style="{ backgroundColor: form.color_primario }">
                                        Encabezado de ejemplo
                                    </div>
                                    <div class="px-4 py-3 text-sm" :style="{ backgroundColor: form.color_secundario, color: form.color_texto }">
                                        Texto del documento en {{ form.fuente }}, {{ form.tamanio_fuente }}px
                                    </div>
                                </div>
                            </template>

                            <!-- TAB: Logo y encabezado -->
                            <template v-if="tabActivo === 'logo'">
                                <!-- Mostrar logo -->
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="text-sm font-medium text-tinta-700">Mostrar logo</p>
                                        <p class="text-xs text-tinta-300">Incluir el logo de la empresa en el encabezado</p>
                                    </div>
                                    <button
                                        @click="form.mostrar_logo = !form.mostrar_logo; marcarCambio()"
                                        class="relative w-11 h-6 rounded-full transition-colors shrink-0"
                                        :style="{ backgroundColor: form.mostrar_logo ? 'var(--marca)' : '#D1D5DB' }"
                                    >
                                        <span
                                            class="absolute top-0.5 w-5 h-5 bg-superficie rounded-full shadow transition-transform"
                                            :class="form.mostrar_logo ? 'translate-x-5' : 'translate-x-0.5'"
                                        />
                                    </button>
                                </div>

                                <!-- Upload logo -->
                                <div v-if="form.mostrar_logo">
                                    <label class="block text-xs font-medium text-tinta-500 mb-1.5">Logo de la empresa</label>
                                    <div v-if="logoPreview" class="mb-3 p-3 border border-linea rounded-xl flex items-center justify-between">
                                        <img :src="logoPreview" alt="Logo" class="h-10 w-auto object-contain" />
                                        <button
                                            @click="logoPreview = null; marcarCambio()"
                                            class="text-xs text-red-500 hover:text-red-700"
                                        >Quitar</button>
                                    </div>
                                    <label class="flex items-center justify-center gap-2 w-full py-3 border-2 border-dashed border-linea rounded-xl cursor-pointer hover:bg-tinta-50 transition-colors">
                                        <svg class="w-5 h-5 text-tinta-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                                        </svg>
                                        <span class="text-sm text-tinta-400">{{ subiendoLogo ? 'Subiendo...' : 'Subir logo (PNG, JPG, SVG)' }}</span>
                                        <input type="file" accept="image/png,image/jpg,image/jpeg,image/svg+xml" class="hidden" @change="subirLogo" :disabled="subiendoLogo" />
                                    </label>
                                </div>

                                <!-- Posición logo -->
                                <div v-if="form.mostrar_logo">
                                    <label class="block text-xs font-medium text-tinta-500 mb-1.5">Posición del logo</label>
                                    <div class="flex gap-2">
                                        <button
                                            v-for="pos in ['izquierda', 'centro', 'derecha']"
                                            :key="pos"
                                            @click="form.logo_posicion = pos; marcarCambio()"
                                            class="flex-1 py-2 text-xs rounded-xl border font-medium transition-colors capitalize"
                                            :class="form.logo_posicion === pos
                                                ? 'border-[var(--marca)] bg-[var(--marca)] text-white'
                                                : 'border-linea text-tinta-500 hover:bg-tinta-50'"
                                        >
                                            {{ pos }}
                                        </button>
                                    </div>
                                </div>

                                <!-- Ancho logo -->
                                <div v-if="form.mostrar_logo">
                                    <label class="block text-xs font-medium text-tinta-500 mb-1.5">
                                        Ancho del logo: <span class="font-semibold text-tinta-900">{{ form.logo_ancho }}px</span>
                                    </label>
                                    <input
                                        v-model.number="form.logo_ancho"
                                        @input="marcarCambio"
                                        type="range"
                                        min="40"
                                        max="300"
                                        step="10"
                                        class="w-full"
                                    />
                                </div>

                                <hr class="border-linea" />

                                <!-- Mostrar encabezado -->
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="text-sm font-medium text-tinta-700">Mostrar encabezado</p>
                                        <p class="text-xs text-tinta-300">Franja de color con datos de la empresa</p>
                                    </div>
                                    <button
                                        @click="form.mostrar_encabezado = !form.mostrar_encabezado; marcarCambio()"
                                        class="relative w-11 h-6 rounded-full transition-colors shrink-0"
                                        :style="{ backgroundColor: form.mostrar_encabezado ? 'var(--marca)' : '#D1D5DB' }"
                                    >
                                        <span
                                            class="absolute top-0.5 w-5 h-5 bg-superficie rounded-full shadow transition-transform"
                                            :class="form.mostrar_encabezado ? 'translate-x-5' : 'translate-x-0.5'"
                                        />
                                    </button>
                                </div>

                                <template v-if="form.mostrar_encabezado">
                                    <div>
                                        <label class="block text-xs font-medium text-tinta-500 mb-1.5">Título del encabezado</label>
                                        <input
                                            v-model="form.encabezado_titulo"
                                            @input="marcarCambio"
                                            type="text"
                                            class="w-full text-sm border border-linea rounded-xl px-3 py-2 focus:outline-none"
                                            placeholder="Ej: Mi Empresa SAS"
                                        />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-tinta-500 mb-1.5">Subtítulo del encabezado</label>
                                        <textarea
                                            v-model="form.encabezado_subtitulo"
                                            @input="marcarCambio"
                                            rows="2"
                                            class="w-full text-sm border border-linea rounded-xl px-3 py-2 focus:outline-none resize-none"
                                            placeholder="Ej: Cuartos Fríos y Puertas Refrigeradas"
                                        />
                                    </div>

                                    <!-- HTML personalizado encabezado -->
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <p class="text-sm font-medium text-tinta-700">HTML personalizado</p>
                                            <p class="text-xs text-tinta-300">Reemplaza el encabezado con HTML libre</p>
                                        </div>
                                        <button
                                            @click="usarEncabezadoHtml = !usarEncabezadoHtml; marcarCambio()"
                                            class="relative w-11 h-6 rounded-full transition-colors shrink-0"
                                            :style="{ backgroundColor: usarEncabezadoHtml ? 'var(--marca)' : '#D1D5DB' }"
                                        >
                                            <span
                                                class="absolute top-0.5 w-5 h-5 bg-superficie rounded-full shadow transition-transform"
                                                :class="usarEncabezadoHtml ? 'translate-x-5' : 'translate-x-0.5'"
                                            />
                                        </button>
                                    </div>
                                    <div v-if="usarEncabezadoHtml">
                                        <textarea
                                            v-model="form.encabezado_html"
                                            @input="marcarCambio"
                                            rows="5"
                                            class="w-full text-xs border border-linea rounded-xl px-3 py-2 focus:outline-none font-mono resize-none bg-tinta-50"
                                            placeholder="<div style='...'> HTML del encabezado </div>"
                                        />
                                    </div>
                                </template>
                            </template>

                            <!-- TAB: Pie de página -->
                            <template v-if="tabActivo === 'pie'">
                                <!-- Mostrar pie -->
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="text-sm font-medium text-tinta-700">Mostrar pie de página</p>
                                        <p class="text-xs text-tinta-300">Banda inferior con datos de contacto</p>
                                    </div>
                                    <button
                                        @click="form.mostrar_pie = !form.mostrar_pie; marcarCambio()"
                                        class="relative w-11 h-6 rounded-full transition-colors shrink-0"
                                        :style="{ backgroundColor: form.mostrar_pie ? 'var(--marca)' : '#D1D5DB' }"
                                    >
                                        <span
                                            class="absolute top-0.5 w-5 h-5 bg-superficie rounded-full shadow transition-transform"
                                            :class="form.mostrar_pie ? 'translate-x-5' : 'translate-x-0.5'"
                                        />
                                    </button>
                                </div>

                                <template v-if="form.mostrar_pie">
                                    <div>
                                        <label class="block text-xs font-medium text-tinta-500 mb-1.5">Texto del pie</label>
                                        <input
                                            v-model="form.pie_texto"
                                            @input="marcarCambio"
                                            type="text"
                                            class="w-full text-sm border border-linea rounded-xl px-3 py-2 focus:outline-none"
                                            placeholder="Ej: Mi Empresa SAS — Calle 0 # 0-0, Bogotá"
                                        />
                                        <p class="text-xs text-tinta-300 mt-1">Se muestra a la izquierda del pie.</p>
                                    </div>

                                    <!-- HTML personalizado pie -->
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <p class="text-sm font-medium text-tinta-700">Pie HTML personalizado</p>
                                            <p class="text-xs text-tinta-300">Reemplaza el pie con HTML libre</p>
                                        </div>
                                        <button
                                            @click="usarPieHtml = !usarPieHtml; marcarCambio()"
                                            class="relative w-11 h-6 rounded-full transition-colors shrink-0"
                                            :style="{ backgroundColor: usarPieHtml ? 'var(--marca)' : '#D1D5DB' }"
                                        >
                                            <span
                                                class="absolute top-0.5 w-5 h-5 bg-superficie rounded-full shadow transition-transform"
                                                :class="usarPieHtml ? 'translate-x-5' : 'translate-x-0.5'"
                                            />
                                        </button>
                                    </div>
                                    <div v-if="usarPieHtml">
                                        <textarea
                                            v-model="form.pie_html"
                                            @input="marcarCambio"
                                            rows="5"
                                            class="w-full text-xs border border-linea rounded-xl px-3 py-2 focus:outline-none font-mono resize-none bg-tinta-50"
                                            placeholder="<div style='...'> HTML del pie </div>"
                                        />
                                    </div>
                                </template>
                            </template>

                            <!-- TAB: Secciones -->
                            <template v-if="tabActivo === 'secciones'">
                                <p class="text-xs text-tinta-400">
                                    Las secciones desactivadas no aparecerán en el PDF generado.
                                </p>
                                <div class="space-y-2">
                                    <div
                                        v-for="sec in secciones"
                                        :key="sec"
                                        class="flex items-center justify-between py-2.5 px-3 rounded-xl border transition-colors cursor-pointer"
                                        :class="seccionActiva(sec)
                                            ? 'border-[var(--marca)] bg-blue-50'
                                            : 'border-linea bg-tinta-50'"
                                        @click="toggleSeccion(sec)"
                                    >
                                        <span class="text-sm font-medium capitalize" :class="seccionActiva(sec) ? 'text-[var(--marca)]' : 'text-tinta-400'">
                                            {{ sec.replace(/_/g, ' ') }}
                                        </span>
                                        <div
                                            class="w-5 h-5 rounded flex items-center justify-center shrink-0"
                                            :style="{ backgroundColor: seccionActiva(sec) ? 'var(--marca)' : 'transparent', border: seccionActiva(sec) ? 'none' : '1.5px solid #D1D5DB' }"
                                        >
                                            <svg v-if="seccionActiva(sec)" class="w-3 h-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                            </svg>
                                        </div>
                                    </div>
                                </div>
                            </template>

                            <!-- TAB: Variables -->
                            <template v-if="tabActivo === 'variables'">
                                <p class="text-xs text-tinta-400">
                                    Copia estas variables y pégalas en los campos de HTML personalizado del encabezado o pie de página.
                                </p>
                                <div class="space-y-2">
                                    <div
                                        v-for="variable in variables"
                                        :key="variable"
                                        class="flex items-center justify-between py-2 px-3 rounded-xl bg-tinta-50 border border-linea"
                                    >
                                        <code class="text-xs text-tinta-700 font-mono">{{ variable }}</code>
                                        <button
                                            @click="copiarVariable(variable)"
                                            class="text-xs px-2 py-1 rounded-lg transition-colors"
                                            :class="copiadoVar === variable
                                                ? 'bg-green-100 text-green-700'
                                                : 'bg-tinta-200 text-tinta-500 hover:bg-gray-300'"
                                        >
                                            {{ copiadoVar === variable ? 'Copiado' : 'Copiar' }}
                                        </button>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <!-- Botón guardar (fijo en el fondo) -->
                        <div class="px-5 py-4 border-t border-linea shrink-0">
                            <div class="flex items-center justify-between mb-3">
                                <span class="text-xs" :class="guardado ? 'text-green-600' : sinGuardar ? 'text-amber-600' : 'text-tinta-300'">
                                    {{ guardado ? '✓ Guardado' : sinGuardar ? '● Cambios sin guardar' : 'Sin cambios' }}
                                </span>
                                <a
                                    :href="`/configuracion/pdf-templates/${modulo}/preview`"
                                    target="_blank"
                                    class="text-xs text-[var(--marca)] hover:underline"
                                >
                                    Abrir PDF
                                </a>
                            </div>
                            <button
                                @click="guardar"
                                :disabled="guardando"
                                class="w-full py-3 px-5 rounded-xl text-sm font-semibold text-white transition-opacity"
                                :style="{ backgroundColor: 'var(--marca)', opacity: guardando ? 0.7 : 1 }"
                            >
                                {{ guardando ? 'Guardando...' : 'Guardar plantilla' }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ── Panel derecho (preview) ──────────────────────────────── -->
                <div class="flex-1 flex flex-col gap-3">
                    <!-- Barra de acciones del preview -->
                    <div class="flex items-center justify-between bg-superficie rounded-2xl shadow-sm border border-linea px-4 py-3">
                        <span class="text-sm font-medium text-tinta-700">Vista previa del PDF</span>
                        <div class="flex items-center gap-2">
                            <button
                                @click="actualizarPreview"
                                class="flex items-center gap-1.5 px-3 py-1.5 text-xs text-tinta-500 border border-linea rounded-xl hover:bg-tinta-50 transition-colors"
                            >
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                </svg>
                                Actualizar
                            </button>
                            <a
                                :href="previewUrl"
                                target="_blank"
                                class="flex items-center gap-1.5 px-3 py-1.5 text-xs text-white rounded-xl transition-colors"
                                style="background-color: var(--marca);"
                            >
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                                </svg>
                                Descargar
                            </a>
                        </div>
                    </div>

                    <!-- iframe -->
                    <div class="flex-1 bg-superficie rounded-2xl shadow-sm border border-linea overflow-hidden" style="min-height: 500px;">
                        <iframe
                            :key="previewKey"
                            :src="previewUrl"
                            class="w-full h-full border-0"
                            style="min-height: 550px;"
                            title="Vista previa PDF"
                        />
                    </div>

                    <p class="text-xs text-tinta-300 text-center">
                        Haz clic en "Actualizar" después de guardar para refrescar la vista previa.
                    </p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
