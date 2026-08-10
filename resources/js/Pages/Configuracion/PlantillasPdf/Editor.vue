<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import ModalAsistentePrompt from '@/Components/PdfPlantillas/ModalAsistentePrompt.vue'
import EditorBloques from '@/Components/PdfPlantillas/EditorBloques.vue'
import { ref, reactive, computed, nextTick, onUnmounted } from 'vue'
import { router } from '@inertiajs/vue3'
import { useClipboard } from '@/composables/useClipboard'
import { colorMarca } from '@/marca'

const { copyText } = useClipboard()

const props = defineProps({
    plantilla:  Object,
    modulo:     String,
    modulos:    Object,
    variables:  Array,
    html_base:  String,
})

// ─── Estado ───────────────────────────────────────────────────────────────────
const guardando        = ref(false)
const guardado         = ref(false)
const sinGuardar       = ref(false)
const cargandoPreview  = ref(false)
const previewObjectUrl = ref(null)
const tabActivo        = ref('editor')
const mostrarVars      = ref(false)
const mostrarAsistente = ref(false)
const dropdownVars     = ref(false)
const copiadoVar       = ref(null)
const textareaRef      = ref(null)
const modoEditor       = ref(props.plantilla?.modo_editor ?? 'visual')
const seccionActiva    = ref('body')

// ─── Bloques por defecto para plantilla nueva ─────────────────────────────────
function nuevoId() {
    return 'b' + Date.now().toString(36) + Math.random().toString(36).slice(2, 5)
}

function bloquesPorDefecto() {
    return [
        {
            id: nuevoId(), tipo: 'columnas',
            props: {
                columnas: 2,
                contenido_columnas: [
                    { bloques: [{ id: nuevoId(), tipo: 'variable', props: { variable: 'empresa.nombre', font_size: 12, negrita: true, color: colorMarca() } }] },
                    { bloques: [{ id: nuevoId(), tipo: 'variable', props: { variable: 'cotizacion.numero', font_size: 12, negrita: true, color: '' } }] },
                ],
            },
        },
        { id: nuevoId(), tipo: 'separador', props: { color: '#dddddd', grosor: 1 } },
        {
            id: nuevoId(), tipo: 'tabla',
            props: { columnas: [
                { etiqueta: '#', variable: 'index' },
                { etiqueta: 'Descripción', variable: 'descripcion' },
                { etiqueta: 'Cant.', variable: 'cantidad' },
                { etiqueta: 'Precio', variable: 'precio_unitario|moneda' },
                { etiqueta: 'Total', variable: 'total_linea|moneda' },
            ]},
        },
        {
            id: nuevoId(), tipo: 'totales',
            props: { filas: [
                { etiqueta: 'Subtotal', variable: 'cotizacion.subtotal|moneda', destacado: false },
                { etiqueta: 'TOTAL',    variable: 'cotizacion.total|moneda',    destacado: true },
            ]},
        },
    ]
}

const form = reactive({
    nombre:      props.plantilla?.nombre      ?? 'Nueva plantilla',
    descripcion: props.plantilla?.descripcion ?? '',
    html:        props.plantilla?.html        ?? props.html_base ?? '',
    papel:       props.plantilla?.papel       ?? 'a4',
    orientacion: props.plantilla?.orientacion ?? 'portrait',
    es_default:  props.plantilla?.es_default  ?? false,
    activa:      props.plantilla?.activa      ?? true,
    ancho_mm:    props.plantilla?.ancho_mm    ?? 210,
    alto_mm:     props.plantilla?.alto_mm     ?? 297,
    bloques: {
        header: props.plantilla?.bloques_header ?? [],
        body:   props.plantilla?.bloques_body   ?? bloquesPorDefecto(),
        footer: props.plantilla?.bloques_footer ?? [],
    },
})

const moduloLabel = computed(() => props.modulos[props.modulo] ?? props.modulo)

const variablesAgrupadas = computed(() => {
    const grupos = {}
    const orden  = []
    for (const v of props.variables) {
        const g = v.grupo ?? 'General'
        if (!grupos[g]) { grupos[g] = []; orden.push(g) }
        grupos[g].push(v)
    }
    return orden.map(g => ({ grupo: g, vars: grupos[g] }))
})

onUnmounted(() => {
    if (previewObjectUrl.value) URL.revokeObjectURL(previewObjectUrl.value)
})

// ─── Utils ────────────────────────────────────────────────────────────────────
function getCsrf() {
    const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/)
    return m ? decodeURIComponent(m[1]) : ''
}

function marcarCambio() {
    sinGuardar.value = true
    guardado.value   = false
}

// ─── Guardar ──────────────────────────────────────────────────────────────────
async function guardar() {
    if (!form.nombre.trim()) { alert('El nombre es obligatorio'); return }
    if (modoEditor.value === 'codigo' && !form.html.trim()) {
        alert('El HTML no puede estar vacío en modo código')
        return
    }

    guardando.value = true
    try {
        const url    = props.plantilla
            ? `/configuracion/plantillas-pdf/${props.plantilla.id}`
            : '/configuracion/plantillas-pdf'
        const method = props.plantilla ? 'PUT' : 'POST'

        const payload = {
            modulo:         props.modulo,
            nombre:         form.nombre,
            descripcion:    form.descripcion,
            html:           form.html,
            papel:          form.papel,
            orientacion:    form.orientacion,
            es_default:     form.es_default,
            activa:         form.activa,
            ancho_mm:       form.papel === 'personalizado' ? form.ancho_mm : null,
            alto_mm:        form.papel === 'personalizado' ? form.alto_mm : null,
            modo_editor:    modoEditor.value,
            bloques_header: form.bloques.header,
            bloques_body:   form.bloques.body,
            bloques_footer: form.bloques.footer,
        }

        const res = await fetch(url, {
            method,
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': getCsrf(),
            },
            credentials: 'same-origin',
            body: JSON.stringify(payload),
        })

        if (!res.ok) {
            const err = await res.json().catch(() => ({}))
            throw new Error(Object.values(err.errors ?? {})[0]?.[0] ?? 'Error al guardar')
        }

        const data = await res.json()
        guardado.value   = true
        sinGuardar.value = false

        if (!props.plantilla && data.plantilla?.id) {
            router.visit(`/configuracion/plantillas-pdf/${data.plantilla.id}/editar`, { replace: true })
        }
    } catch (e) {
        alert(e.message)
    } finally {
        guardando.value = false
    }
}

// ─── Preview ──────────────────────────────────────────────────────────────────
async function actualizarPreview() {
    if (modoEditor.value === 'codigo' && !form.html.trim()) return
    cargandoPreview.value = true
    try {
        const body = modoEditor.value === 'visual'
            ? {
                modo_editor:    'visual',
                bloques_header: form.bloques.header,
                bloques_body:   form.bloques.body,
                bloques_footer: form.bloques.footer,
                modulo:         props.modulo,
                papel:          form.papel,
                orientacion:    form.orientacion,
                ancho_mm:       form.papel === 'personalizado' ? form.ancho_mm : null,
                alto_mm:        form.papel === 'personalizado' ? form.alto_mm  : null,
            }
            : {
                modo_editor: 'codigo',
                html:        form.html,
                modulo:      props.modulo,
                papel:       form.papel,
                orientacion: form.orientacion,
                ancho_mm:    form.papel === 'personalizado' ? form.ancho_mm : null,
                alto_mm:     form.papel === 'personalizado' ? form.alto_mm  : null,
            }

        const res = await fetch('/configuracion/plantillas-pdf/preview', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': getCsrf(),
            },
            credentials: 'same-origin',
            body: JSON.stringify(body),
        })
        if (!res.ok) throw new Error('Error al generar preview')
        const blob = await res.blob()
        if (previewObjectUrl.value) URL.revokeObjectURL(previewObjectUrl.value)
        previewObjectUrl.value = URL.createObjectURL(blob)
        tabActivo.value = 'preview'
    } catch {
        alert('Error al generar la vista previa')
    } finally {
        cargandoPreview.value = false
    }
}

// ─── Insertar texto en el textarea (modo código) ──────────────────────────────
function insertarTexto(texto) {
    const el = textareaRef.value
    if (!el) {
        form.html += texto
        marcarCambio()
        return
    }
    const inicio = el.selectionStart
    const fin    = el.selectionEnd
    form.html = form.html.substring(0, inicio) + texto + form.html.substring(fin)
    marcarCambio()
    nextTick(() => {
        el.selectionStart = el.selectionEnd = inicio + texto.length
        el.focus()
    })
}

function insertarVariable(v) {
    if (v.includes('...')) {
        const partes = v.split('...')
        insertarTexto(partes[0] + '\n  <!-- fila -->\n' + partes[1])
    } else {
        insertarTexto(v)
    }
    dropdownVars.value = false
}

function insertarTabla() {
    insertarTexto(`{{#items}}\n<tr>\n  <td>{{index}}</td>\n  <td>{{descripcion}}</td>\n  <td>{{cantidad}}</td>\n</tr>\n{{/items}}`)
}

function insertarCondicional() {
    insertarTexto(`{{#if variable}}\n  <!-- contenido si variable es verdadera -->\n{{/if}}`)
}

async function copiarVariable(v) {
    if (await copyText(v)) {
        copiadoVar.value = v
        setTimeout(() => { copiadoVar.value = null }, 1500)
    } else {
        copiadoVar.value = null
    }
}

// ─── Tabs sección visual ──────────────────────────────────────────────────────
const seccionesTabs = [
    { key: 'header', label: '⬆ Encabezado' },
    { key: 'body',   label: '📄 Cuerpo' },
    { key: 'footer', label: '⬇ Pie de página' },
]
</script>

<template>
    <AppLayout :title="`Plantilla PDF — ${moduloLabel}`">
        <div class="max-w-7xl mx-auto">

            <!-- Breadcrumb -->
            <div class="flex items-center gap-2 text-sm text-tinta-400 mb-4">
                <button @click="router.visit('/configuracion/plantillas-pdf')" class="hover:text-tinta-700 transition-colors">
                    Plantillas PDF
                </button>
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                </svg>
                <span class="text-tinta-900 font-medium">{{ moduloLabel }}</span>
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                </svg>
                <span class="text-tinta-500 truncate max-w-xs">{{ form.nombre }}</span>
            </div>

            <!-- Barra de configuración -->
            <div class="bg-superficie rounded-2xl border border-linea shadow-sm px-4 py-3 mb-4">
                <div class="flex flex-col sm:flex-row gap-3 items-start sm:items-center">
                    <!-- Nombre -->
                    <input
                        v-model="form.nombre"
                        @input="marcarCambio"
                        type="text"
                        placeholder="Nombre de la plantilla"
                        class="flex-1 min-w-0 text-sm font-semibold border border-linea rounded-xl px-3 py-2 focus:outline-none focus:ring-2 focus:ring-[var(--marca)]"
                    />

                    <!-- Papel + Orientación -->
                    <div class="flex flex-wrap gap-2 shrink-0">
                        <select v-model="form.papel" @change="marcarCambio"
                            class="text-xs border border-linea rounded-xl px-2 py-2 focus:outline-none focus:ring-2 focus:ring-[var(--marca)]">
                            <optgroup label="Estándar">
                                <option value="a4">A4 (210×297mm)</option>
                                <option value="a5">A5 (148×210mm)</option>
                                <option value="a3">A3 (297×420mm)</option>
                                <option value="letter">Letter (216×279mm)</option>
                                <option value="legal">Legal (216×356mm)</option>
                                <option value="half-letter">Half Letter (140×216mm)</option>
                            </optgroup>
                            <optgroup label="Etiquetas y tickets">
                                <option value="etiqueta-10x13">Etiqueta 10×13cm</option>
                                <option value="etiqueta-10x15">Etiqueta 10×15cm</option>
                                <option value="ticket-80">Ticket 80mm</option>
                                <option value="tarjeta">Tarjeta de visita (85×55mm)</option>
                            </optgroup>
                            <optgroup label="Personalizado">
                                <option value="personalizado">Personalizado...</option>
                            </optgroup>
                        </select>
                        <select v-model="form.orientacion" @change="marcarCambio"
                            class="text-xs border border-linea rounded-xl px-2 py-2 focus:outline-none focus:ring-2 focus:ring-[var(--marca)]">
                            <option value="portrait">Vertical</option>
                            <option value="landscape">Horizontal</option>
                        </select>
                        <!-- Dimensiones personalizadas -->
                        <template v-if="form.papel === 'personalizado'">
                            <div class="flex items-center gap-1">
                                <span class="text-xs text-tinta-300">Ancho</span>
                                <input v-model="form.ancho_mm" @input="marcarCambio"
                                    type="number" min="50" max="500"
                                    class="w-16 text-xs border border-linea rounded-lg px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-[var(--marca)]"/>
                                <span class="text-xs text-tinta-300">mm</span>
                            </div>
                            <div class="flex items-center gap-1">
                                <span class="text-xs text-tinta-300">Alto</span>
                                <input v-model="form.alto_mm" @input="marcarCambio"
                                    type="number" min="50" max="700"
                                    class="w-16 text-xs border border-linea rounded-lg px-2 py-1.5 focus:outline-none focus:ring-2 focus:ring-[var(--marca)]"/>
                                <span class="text-xs text-tinta-300">mm</span>
                            </div>
                        </template>
                    </div>

                    <!-- Toggle default -->
                    <div class="flex items-center gap-2 shrink-0">
                        <button
                            @click="form.es_default = !form.es_default; marcarCambio()"
                            class="relative w-9 h-5 rounded-full transition-colors shrink-0"
                            :style="{ backgroundColor: form.es_default ? 'var(--marca)' : '#D1D5DB' }"
                        >
                            <span class="absolute top-0.5 w-4 h-4 bg-superficie rounded-full shadow transition-transform"
                                :class="form.es_default ? 'translate-x-4' : 'translate-x-0.5'" />
                        </button>
                        <span class="text-xs text-tinta-500 whitespace-nowrap">Por defecto</span>
                    </div>

                    <!-- Estado + Guardar -->
                    <div class="flex items-center gap-2 shrink-0">
                        <span class="text-xs hidden sm:inline" :class="guardado ? 'text-green-600' : sinGuardar ? 'text-amber-500' : 'text-tinta-300'">
                            {{ guardado ? '✓ Guardado' : sinGuardar ? '● Sin guardar' : '' }}
                        </span>
                        <button
                            @click="guardar"
                            :disabled="guardando"
                            class="px-4 py-2 rounded-xl text-sm font-semibold text-white transition-opacity disabled:opacity-60"
                            style="background-color: var(--marca);"
                        >
                            {{ guardando ? 'Guardando...' : 'Guardar' }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- Tabs mobile (editor / preview) -->
            <div class="flex lg:hidden mb-3 bg-superficie rounded-xl border border-linea p-1 shadow-sm">
                <button
                    v-for="t in [{ key: 'editor', label: 'Editor' }, { key: 'preview', label: 'Vista previa' }]"
                    :key="t.key"
                    @click="tabActivo = t.key"
                    class="flex-1 py-2 text-xs font-medium rounded-lg transition-colors"
                    :class="tabActivo === t.key ? 'bg-[var(--marca)] text-white' : 'text-tinta-400 hover:text-tinta-700'"
                >{{ t.label }}</button>
            </div>

            <!-- Layout dos columnas -->
            <div class="flex flex-col lg:flex-row gap-4" style="min-height: 65vh;">

                <!-- ── Panel Editor ─────────────────────────────────────────── -->
                <div
                    class="flex-1 flex flex-col gap-3 min-w-0"
                    :class="tabActivo === 'preview' ? 'hidden lg:flex' : 'flex'"
                >
                    <!-- Toggle modo editor -->
                    <div class="bg-superficie rounded-xl border border-linea shadow-sm px-3 py-2 flex flex-wrap items-center gap-2">

                        <!-- Modo Visual / Código -->
                        <div class="flex items-center gap-1 bg-tinta-100 rounded-lg p-0.5">
                            <button
                                @click="modoEditor = 'visual'; marcarCambio()"
                                class="px-3 py-1.5 text-xs rounded-lg font-medium transition-all"
                                :class="modoEditor === 'visual' ? 'bg-superficie text-[var(--marca)] shadow-sm' : 'text-tinta-400 hover:text-tinta-700'"
                            >
                                🎨 Visual
                            </button>
                            <button
                                @click="modoEditor = 'codigo'; marcarCambio()"
                                class="px-3 py-1.5 text-xs rounded-lg font-medium transition-all"
                                :class="modoEditor === 'codigo' ? 'bg-superficie text-[var(--marca)] shadow-sm' : 'text-tinta-400 hover:text-tinta-700'"
                            >
                                &lt;/&gt; Código
                            </button>
                        </div>

                        <!-- Herramientas modo código -->
                        <template v-if="modoEditor === 'codigo'">
                            <!-- Insertar variable -->
                            <div class="relative">
                                <button
                                    @click="dropdownVars = !dropdownVars"
                                    class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg border border-linea text-tinta-700 hover:bg-tinta-50 transition-colors"
                                >
                                    <svg class="w-3.5 h-3.5 text-[var(--marca)]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/>
                                    </svg>
                                    Insertar variable
                                </button>
                                <div v-if="dropdownVars"
                                    class="absolute top-full left-0 mt-1 z-20 bg-superficie border border-linea rounded-xl shadow-lg py-1 w-80 max-h-80 overflow-y-auto"
                                    @click.stop>
                                    <template v-for="grupo in variablesAgrupadas" :key="grupo.grupo">
                                        <div class="px-3 pt-2 pb-0.5 sticky top-0 bg-superficie border-b border-gray-50">
                                            <span class="text-xs font-semibold text-tinta-300 uppercase tracking-[0.12em]">{{ grupo.grupo }}</span>
                                        </div>
                                        <div v-for="v in grupo.vars" :key="v.var"
                                            @click="insertarVariable(v.var)"
                                            class="px-3 py-2 cursor-pointer hover:bg-blue-50 transition-colors">
                                            <code class="text-xs font-mono text-[var(--marca)]">{{ v.var }}</code>
                                            <p class="text-xs text-tinta-300 mt-0.5">{{ v.desc }}</p>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <button @click="insertarTabla"
                                class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg border border-linea text-tinta-700 hover:bg-tinta-50 transition-colors">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M3 14h18M10 3v18M14 3v18M3 6a3 3 0 013-3h12a3 3 0 013 3v12a3 3 0 01-3 3H6a3 3 0 01-3-3V6z"/>
                                </svg>
                                Tabla
                            </button>

                            <button @click="insertarCondicional"
                                class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg border border-linea text-tinta-700 hover:bg-tinta-50 transition-colors">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 9l4-4 4 4m0 6l-4 4-4-4"/>
                                </svg>
                                Condicional
                            </button>
                        </template>

                        <!-- Asistente IA (siempre visible) -->
                        <button
                            @click="mostrarAsistente = true"
                            class="flex items-center gap-1.5 text-xs px-3 py-1.5 bg-purple-600 text-white rounded-lg font-medium hover:bg-purple-700 transition-colors"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.347.347a3.75 3.75 0 01-5.303 0l-.347-.347z"/>
                            </svg>
                            Generar con IA
                        </button>

                        <!-- Variables sidebar toggle (solo modo código) -->
                        <template v-if="modoEditor === 'codigo'">
                            <div class="flex-1" />
                            <button
                                @click="mostrarVars = !mostrarVars"
                                class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg border transition-colors"
                                :class="mostrarVars ? 'bg-blue-50 border-[var(--marca)] text-[var(--marca)]' : 'border-linea text-tinta-700 hover:bg-tinta-50'"
                            >
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h8m-8 6h16"/>
                                </svg>
                                Variables
                            </button>
                        </template>
                    </div>

                    <!-- Tabs sección (solo modo visual) -->
                    <div v-if="modoEditor === 'visual'"
                        class="flex bg-superficie rounded-xl border border-linea shadow-sm overflow-hidden">
                        <button
                            v-for="sec in seccionesTabs"
                            :key="sec.key"
                            @click="seccionActiva = sec.key"
                            class="flex-1 px-3 py-2.5 text-xs font-medium transition-colors border-b-2"
                            :class="seccionActiva === sec.key
                                ? 'border-[var(--marca)] text-[var(--marca)] bg-blue-50'
                                : 'border-transparent text-tinta-400 hover:text-tinta-700 hover:bg-tinta-50'"
                        >
                            {{ sec.label }}
                        </button>
                    </div>

                    <!-- Área de edición -->
                    <div class="flex gap-3 flex-1 min-h-0">

                        <!-- Editor visual -->
                        <div v-if="modoEditor === 'visual'" class="flex-1 min-w-0">
                            <div class="bg-superficie rounded-xl border border-linea shadow-sm p-4">
                                <EditorBloques
                                    :bloques="form.bloques[seccionActiva]"
                                    :variables="props.variables"
                                    :seccion="seccionActiva"
                                    @update:bloques="(val) => { form.bloques[seccionActiva] = val; marcarCambio() }"
                                />
                            </div>
                        </div>

                        <!-- Editor código -->
                        <template v-else>
                            <div class="flex-1 min-w-0 flex flex-col">
                                <textarea
                                    ref="textareaRef"
                                    v-model="form.html"
                                    @input="marcarCambio"
                                    @click="dropdownVars = false"
                                    class="flex-1 w-full rounded-xl border border-linea shadow-sm p-3 text-xs leading-relaxed resize-none focus:outline-none focus:ring-2 focus:ring-[var(--marca)] bg-tinta-50"
                                    style="font-family: 'Courier New', Courier, monospace; min-height: 480px;"
                                    spellcheck="false"
                                    placeholder="Escribe o pega tu HTML aquí..."
                                />
                            </div>

                            <!-- Panel de variables -->
                            <div v-if="mostrarVars"
                                class="w-52 shrink-0 bg-superficie rounded-xl border border-linea shadow-sm flex flex-col overflow-hidden">
                                <div class="px-3 py-2 border-b border-linea">
                                    <p class="text-xs font-semibold text-tinta-700">Variables disponibles</p>
                                </div>
                                <div class="flex-1 overflow-y-auto">
                                    <template v-for="grupo in variablesAgrupadas" :key="grupo.grupo">
                                        <div class="px-3 pt-3 pb-1 sticky top-0 bg-superficie z-10 border-b border-gray-50">
                                            <span class="text-xs font-semibold text-tinta-300 uppercase tracking-[0.12em]">{{ grupo.grupo }}</span>
                                        </div>
                                        <div class="p-2 space-y-0.5">
                                            <div v-for="v in grupo.vars" :key="v.var"
                                                class="rounded-lg p-2 hover:bg-tinta-50 transition-colors group">
                                                <div class="flex items-start justify-between gap-1">
                                                    <code
                                                        class="text-xs font-mono text-[var(--marca)] cursor-pointer break-all leading-tight"
                                                        @click="insertarVariable(v.var)">{{ v.var }}</code>
                                                    <button
                                                        @click="copiarVariable(v.var)"
                                                        class="shrink-0 text-xs px-1.5 py-0.5 rounded-lg transition-colors opacity-0 group-hover:opacity-100"
                                                        :class="copiadoVar === v.var ? 'bg-green-100 text-green-700' : 'bg-tinta-100 text-tinta-400'">
                                                        {{ copiadoVar === v.var ? '✓' : 'Copiar' }}
                                                    </button>
                                                </div>
                                                <p class="text-xs text-tinta-300 mt-0.5 leading-tight">{{ v.desc }}</p>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- ── Panel Preview ─────────────────────────────────────────── -->
                <div
                    class="lg:w-[420px] xl:w-[500px] shrink-0 flex flex-col gap-3"
                    :class="tabActivo === 'editor' ? 'hidden lg:flex' : 'flex'"
                >
                    <div class="bg-superficie rounded-xl border border-linea shadow-sm px-3 py-2 flex items-center justify-between">
                        <span class="text-xs font-medium text-tinta-700">Vista previa</span>
                        <div class="flex items-center gap-2">
                            <button
                                @click="actualizarPreview"
                                :disabled="cargandoPreview"
                                class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg text-white transition-opacity disabled:opacity-60"
                                style="background-color: var(--marca);"
                            >
                                <svg class="w-3.5 h-3.5" :class="cargandoPreview ? 'animate-spin' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                </svg>
                                {{ cargandoPreview ? 'Generando...' : 'Actualizar' }}
                            </button>
                            <a v-if="previewObjectUrl" :href="previewObjectUrl" download="preview.pdf"
                                class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium rounded-lg border border-linea text-tinta-700 hover:bg-tinta-50 transition-colors">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                                </svg>
                                Descargar
                            </a>
                        </div>
                    </div>

                    <div class="flex-1 bg-superficie rounded-xl border border-linea shadow-sm overflow-hidden flex flex-col" style="min-height: 480px;">
                        <iframe v-if="previewObjectUrl" :src="previewObjectUrl"
                            class="w-full flex-1 border-0" title="Vista previa PDF" />
                        <div v-else class="flex-1 flex flex-col items-center justify-center text-center p-8">
                            <svg class="w-12 h-12 text-gray-200 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                            </svg>
                            <p class="text-sm text-tinta-300 mb-1">Sin vista previa</p>
                            <p class="text-xs text-tinta-200">Haz clic en "Actualizar" para generar el PDF</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cerrar dropdown vars al hacer click fuera -->
        <div v-if="dropdownVars" class="fixed inset-0 z-10" @click="dropdownVars = false" />

        <!-- Modal asistente IA -->
        <ModalAsistentePrompt
            v-if="mostrarAsistente"
            :modulo="props.modulo"
            :modulo-label="moduloLabel"
            :variables="props.variables"
            @cerrar="mostrarAsistente = false"
        />
    </AppLayout>
</template>
