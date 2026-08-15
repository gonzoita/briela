<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useClipboard } from '@/composables/useClipboard'
import { colorMarca } from '@/marca'

const { copyText } = useClipboard()

const props = defineProps({
    modulo:      String,
    moduloLabel: String,
    variables:   Array,
})

const emit = defineEmits(['cerrar'])

// ─── Wizard ───────────────────────────────────────────────────────────────────
const paso = ref(1)

// ─── Paso 1: Diseño ───────────────────────────────────────────────────────────
const estilos = [
    { value: 'corporativo',   label: 'Corporativo formal',  desc: 'Encabezado azul, tabla estructurada' },
    { value: 'minimalista',   label: 'Minimalista',          desc: 'Blanco y negro, tipografía limpia' },
    { value: 'moderno',       label: 'Moderno',              desc: 'Colores suaves, bordes redondeados' },
    { value: 'clasico',       label: 'Clásico',              desc: 'Estilo factura tradicional' },
    { value: 'personalizado', label: 'Personalizado',        desc: 'Describo yo' },
]

const opcionesTabla = [
    { key: 'mostrarImagenes',  label: '¿Mostrar imágenes en la tabla?' },
    { key: 'mostrarVariables', label: '¿Mostrar variables de item (Ancho, Alto, etc.)?' },
    { key: 'mostrarDescuento', label: '¿Mostrar descuento por línea?' },
    { key: 'mostrarImpuesto',  label: '¿Mostrar impuesto por línea?' },
    { key: 'mostrarComision',  label: '¿Mostrar comisión?' },
    { key: 'filasAlternas',    label: '¿Tabla con filas alternas?' },
    { key: 'encabezadoColor',  label: '¿Encabezados de tabla en color primario?' },
]

const diseno = reactive({
    estilo:           'corporativo',
    colorPrimario:    colorMarca(),
    colorSecundario:  '#F8FAFC',
    orientacion:      'portrait',
    papel:            'a4',
    ancho_mm:         210,
    alto_mm:          297,
    logo:             true,
    logoPosicion:     'izquierda',
    logo_url:         '',
    logo_url_error:   false,
    logo_ancho:       120,
    descripcionLibre: '',
    mostrarImagenes:  false,
    mostrarVariables: false,
    mostrarDescuento: false,
    mostrarImpuesto:  false,
    mostrarComision:  false,
    filasAlternas:    true,
    encabezadoColor:  true,
})

// ─── Variables agrupadas ──────────────────────────────────────────────────────
const variablesAgrupadas = computed(() => {
    const grupos = {}
    const orden  = []
    for (const v of (props.variables ?? [])) {
        if (v.var.includes('...')) continue
        const g = v.grupo ?? 'General'
        if (!grupos[g]) { grupos[g] = []; orden.push(g) }
        grupos[g].push(v)
    }
    return orden.map(g => ({ grupo: g, vars: grupos[g] }))
})

const grupoTabla = computed(() =>
    variablesAgrupadas.value.find(g => g.grupo === 'Tabla items')?.vars ?? []
)

const gruposRegulares = computed(() =>
    variablesAgrupadas.value.filter(g => g.grupo !== 'Tabla items')
)

// ─── Selección de variables ───────────────────────────────────────────────────
const seleccionRegulares = ref([])
const seleccionTabla     = ref([])

onMounted(() => {
    const defaultGrupos = new Set(['Empresa', 'Cliente', 'Vendedor', 'Totales', 'Fechas'])
    for (const { grupo, vars } of gruposRegulares.value) {
        if (defaultGrupos.has(grupo)) {
            vars.forEach(v => seleccionRegulares.value.push(v.var))
        }
    }
    // Seleccionar campos clave del módulo (numero, fecha, estado, nombre)
    const camposModulo = gruposRegulares.value.find(g => g.grupo === 'Campos del módulo')?.vars ?? []
    const claves = ['numero', 'fecha', 'estado', 'nombre', 'numero_op', 'codigo']
    camposModulo.forEach(v => {
        const campo = v.var.replace(/[{}]/g, '').split('.').pop()
        if (claves.some(c => campo.includes(c))) seleccionRegulares.value.push(v.var)
    })

    const defaultTabla = new Set([
        '{{index}}', '{{descripcion}}', '{{descripcion_corta}}',
        '{{cantidad}}', '{{precio_unitario|moneda}}',
        '{{subtotal_linea|moneda}}', '{{total_linea|moneda}}',
    ])
    grupoTabla.value.forEach(v => {
        if (defaultTabla.has(v.var)) seleccionTabla.value.push(v.var)
    })
})

function isRegular(varStr) { return seleccionRegulares.value.includes(varStr) }
function isTabla(varStr)   { return seleccionTabla.value.includes(varStr) }

function toggleRegular(varStr) {
    const idx = seleccionRegulares.value.indexOf(varStr)
    if (idx === -1) seleccionRegulares.value.push(varStr)
    else seleccionRegulares.value.splice(idx, 1)
}

function toggleTablaVar(varStr) {
    const idx = seleccionTabla.value.indexOf(varStr)
    if (idx === -1) seleccionTabla.value.push(varStr)
    else seleccionTabla.value.splice(idx, 1)
}

function grupoTodo(grupo) {
    const vars = gruposRegulares.value.find(g => g.grupo === grupo)?.vars ?? []
    return vars.length > 0 && vars.every(v => isRegular(v.var))
}

function toggleGrupo(grupo) {
    const vars = gruposRegulares.value.find(g => g.grupo === grupo)?.vars ?? []
    if (grupoTodo(grupo)) {
        seleccionRegulares.value = seleccionRegulares.value.filter(s => !vars.some(v => v.var === s))
    } else {
        vars.forEach(v => { if (!isRegular(v.var)) seleccionRegulares.value.push(v.var) })
    }
}

function tablasTodo() {
    return grupoTabla.value.length > 0 && grupoTabla.value.every(v => isTabla(v.var))
}

function toggleTodasTabla() {
    if (tablasTodo()) seleccionTabla.value = []
    else seleccionTabla.value = grupoTabla.value.map(v => v.var)
}

// ─── Sección a generar ───────────────────────────────────────────────────────
const seccionGeneracion = ref('body')

const opcionesSecciones = [
    { value: 'header', label: 'Solo Encabezado', desc: 'Logo, datos empresa, número de documento' },
    { value: 'body',   label: 'Solo Cuerpo',     desc: 'Tabla de items, totales, contenido principal' },
    { value: 'footer', label: 'Solo Pie',         desc: 'Firmas, condiciones, contacto' },
    { value: 'todas',  label: 'Las 3 secciones',  desc: 'Genera prompts separados para header, body y footer' },
]

// ─── Generar prompt ───────────────────────────────────────────────────────────
const promptGenerado  = ref('')
const promptsMultiple = ref({ header: '', body: '', footer: '' })
const tabPrompt       = ref('body')
const copiado         = ref(false)

const anchoPapel = computed(() => {
    if (diseno.papel === 'personalizado') {
        return Math.round((diseno.ancho_mm ?? 210) * 2.8346) + 'px'
    }
    const mapa = {
        portrait: {
            a4: '794px', a5: '559px', a3: '1122px',
            letter: '816px', legal: '816px', 'half-letter': '530px',
            'etiqueta-10x13': '378px', 'etiqueta-10x15': '378px',
            'ticket-80': '302px', tarjeta: '322px',
        },
        landscape: {
            a4: '1122px', a5: '794px', a3: '1587px',
            letter: '1056px', legal: '1056px', 'half-letter': '816px',
            'etiqueta-10x13': '491px', 'etiqueta-10x15': '567px',
            'ticket-80': '756px', tarjeta: '208px',
        },
    }
    return mapa[diseno.orientacion]?.[diseno.papel] ?? '794px'
})

const estiloLabel = computed(() =>
    estilos.find(e => e.value === diseno.estilo)?.label ?? diseno.estilo
)

function construirPromptParaSeccion(seccion) {
    const todasLasVars = props.variables ?? []
    function varConDesc(varStr) {
        const found = todasLasVars.find(x => x.var === varStr)
        return `  ${varStr}${found?.desc ? ' → ' + found.desc : ''}`
    }
    const listaVars      = seleccionRegulares.value.map(varConDesc).join('\n') || '  (ninguna seleccionada)'
    const tablaVarsTexto = seleccionTabla.value.map(varConDesc).join('\n') || '  (ninguna)'
    const tablaVars = seleccionTabla.value

    let bloqueTabla = ''
    if (tablaVars.length > 0) {
        const filas = tablaVars.map(v => {
            const campo   = v.replace(/\|moneda|\|fecha|\|upper|\|lower/g, '').replace(/[{}]/g, '').trim()
            const formato = v.includes('|moneda') ? '|moneda' : v.includes('|fecha') ? '|fecha' : ''
            return `      <td>{{${campo}${formato}}}</td>`
        }).join('\n')
        bloqueTabla = `{{#items}}\n    <tr>\n${filas}\n    </tr>\n    {{/items}}`
    }

    const extras = [
        diseno.filasAlternas    ? '- Filas alternas: una blanca, una #f8f9fa' : '',
        diseno.encabezadoColor  ? `- Encabezado de tabla en color ${diseno.colorPrimario} con texto blanco` : '',
        diseno.mostrarImagenes  ? '- Incluir columna de imagen: <td><img src="{{!imagen_base64}}" style="width:60px;height:60px;object-fit:cover;"/></td>' : '',
        diseno.mostrarVariables ? '- Incluir columna de especificaciones: {{variables_texto}}' : '',
        diseno.mostrarDescuento ? '- Incluir columna de descuento por línea: {{descuento_pct}}% / {{descuento_valor|moneda}}' : '',
        diseno.mostrarImpuesto  ? '- Incluir columna de impuesto por línea' : '',
        diseno.mostrarComision  ? '- Incluir columna de comisión: {{comision_pct}}% / {{comision_valor|moneda}}' : '',
    ].filter(Boolean).join('\n')

    const moduloUp = (props.moduloLabel ?? props.modulo ?? '').toUpperCase()

    const instruccionSeccion = {
        header: `Solo el ENCABEZADO del documento (logo + datos empresa + número/fecha).
No incluyas tabla de items ni totales. Solo la sección superior.`,
        body:   `Solo el CUERPO del documento (tabla de items + totales + información del cliente).
No incluyas encabezado ni pie de página.`,
        footer: `Solo el PIE DE PÁGINA del documento (firmas, condiciones, datos de contacto).
No incluyas encabezado ni tabla de items.`,
        todas:  `Genera el documento COMPLETO con las 3 secciones.`,
    }

    const estructuraSeccion = {
        header: '1. Logo (si aplica) + nombre empresa + NIT/ciudad\n2. Número y fecha del documento',
        body:   '1. Datos del cliente/destinatario\n2. Tabla de items con las columnas seleccionadas\n3. Cuadro de totales destacado',
        footer: '1. Condiciones comerciales o notas\n2. Espacio para firmas (si aplica)\n3. Datos de contacto empresa',
        todas:  '1. ENCABEZADO: Logo + datos empresa + número y fecha\n2. DATOS: Información del cliente\n3. CUERPO: Tabla de items\n4. TOTALES: Resumen financiero\n5. PIE: Condiciones, firmas, contacto',
    }

    return `Eres un experto en diseño de documentos PDF empresariales para Colombia.
Genera HTML para el sistema.

════════════════════════════════════════
MÓDULO: ${moduloUp} | SECCIÓN: ${seccion.toUpperCase()}
════════════════════════════════════════
${instruccionSeccion[seccion] ?? instruccionSeccion.body}

════════════════════════════════════════
REGLAS TÉCNICAS OBLIGATORIAS (DomPDF)
════════════════════════════════════════
- Solo CSS 2.1 — NO flexbox, NO grid, NO CSS3
- Para columnas: display:table / display:table-cell SIEMPRE
- Fuentes permitidas: Arial, Helvetica, Times New Roman, Courier
- Medidas en px o pt
- Ancho máximo del body: ${anchoPapel.value} (${diseno.papel.toUpperCase()} ${diseno.orientacion})
- NO usar: @media, transform, animation, SVG inline, float complejo
- Imágenes: solo con src en base64 o URL absoluta
- Devolver SOLO el fragmento HTML solicitado, sin explicaciones

════════════════════════════════════════
SISTEMA DE VARIABLES
════════════════════════════════════════
Las variables se escriben: {{prefijo.campo}}
Formato moneda: {{campo|moneda}} → $1.000.000
Formato fecha: {{campo|fecha}} → 25/06/2026
Condicional: {{#if campo}}contenido{{/if}}
Tabla dinámica: {{#items}}fila{{/items}}

════════════════════════════════════════
VARIABLES DISPONIBLES — ÚSALAS EXACTAMENTE ASÍ
════════════════════════════════════════
SINTAXIS:
- Variable simple: {{prefijo.campo}}
- Con formato moneda: {{campo|moneda}} → $1.000.000
- Con formato fecha: {{campo|fecha}} → 25/06/2026
- Sin escape HTML (imágenes/QR): {{!campo}}
- Bloque de tabla: {{#items}}...fila...{{/items}}
- Condicional: {{#if campo}}contenido{{/if}}

VARIABLES GENERALES:
${listaVars}

VARIABLES DE TABLA (van dentro de {{#items}}...{{/items}}):
${tablaVarsTexto}

════════════════════════════════════════
BLOQUE DE TABLA DE ITEMS
════════════════════════════════════════
<table>
  <thead><tr><!-- encabezados --></tr></thead>
  <tbody>
    ${bloqueTabla}
  </tbody>
</table>

Columnas seleccionadas: ${tablaVars.join(', ')}
${extras ? '\n' + extras : ''}

ERRORES COMUNES — EVITAR:
- NO uses {{cliente_nombre}} → usa {{cliente.nombre}}
- NO uses {{total}} → usa {{cotizacion.total|moneda}}
- NO inventes variables fuera de la lista de arriba
- El motor reemplaza EXACTAMENTE el texto entre {{ }} incluyendo el punto

════════════════════════════════════════
DISEÑO Y ESTILO
════════════════════════════════════════
Estilo: ${estiloLabel.value}
Color primario: ${diseno.colorPrimario}
Color secundario: ${diseno.colorSecundario}
${diseno.logo
    ? diseno.logo_url
        ? `Logo: <img src="${diseno.logo_url}" style="width:${diseno.logo_ancho ?? 120}px;display:block;"/> — Posición: ${diseno.logoPosicion}`
        : `Logo: <img src="{{empresa.logo_url}}" style="width:${diseno.logo_ancho ?? 120}px;"/> — Posición: ${diseno.logoPosicion}`
    : 'Sin logo'
}

Descripción del usuario:
"${diseno.descripcionLibre || 'Diseño profesional para empresa manufacturera colombiana'}"

════════════════════════════════════════
ESTRUCTURA ESPERADA
════════════════════════════════════════
${estructuraSeccion[seccion] ?? estructuraSeccion.body}

Para imágenes/QR usar {{!campo}} (evita escape HTML):
<img src="{{!imagen_base64}}" style="width:60px;"/>
<img src="{{!op.qr_imagen}}" style="width:80px;"/>

Devuelve SOLO el HTML del fragmento solicitado.`
}

function generarPrompt() {
    if (seccionGeneracion.value === 'todas') {
        promptsMultiple.value = {
            header: construirPromptParaSeccion('header'),
            body:   construirPromptParaSeccion('body'),
            footer: construirPromptParaSeccion('footer'),
        }
        tabPrompt.value      = 'body'
        promptGenerado.value = ''
    } else {
        promptGenerado.value      = construirPromptParaSeccion(seccionGeneracion.value)
        promptsMultiple.value     = { header: '', body: '', footer: '' }
    }
    paso.value = 3
}

async function copiarPrompt(texto) {
    const str = texto ?? promptGenerado.value
    if (!str) return
    if (await copyText(str)) {
        copiado.value = true
        setTimeout(() => { copiado.value = false }, 2000)
    }
}
</script>

<template>
    <!-- Backdrop -->
    <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/50 p-0 sm:p-4" @click.self="emit('cerrar')">

        <!-- Modal -->
        <div class="bg-superficie w-full sm:max-w-2xl sm:rounded-2xl shadow-2xl flex flex-col max-h-[95dvh] sm:max-h-[90vh] rounded-t-2xl">

            <!-- Header -->
            <div class="flex items-center justify-between px-5 py-4 border-b border-linea shrink-0">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-lg bg-pastel-violeta-2 flex items-center justify-center shrink-0">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-aviso-violeta" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.347.347a3.75 3.75 0 01-5.303 0l-.347-.347z"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-tinta-900">Asistente de Prompt IA</p>
                        <p class="text-xs text-tinta-300">Paso {{ paso }} de 3</p>
                    </div>
                </div>
                <button @click="emit('cerrar')" class="p-1.5 rounded-lg hover:bg-tinta-100 transition-colors text-tinta-300 hover:text-tinta-500">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <!-- Barra de progreso -->
            <div class="px-5 pt-3 pb-1 shrink-0">
                <div class="flex gap-1.5">
                    <div v-for="i in 3" :key="i"
                        class="flex-1 h-1 rounded-full transition-all duration-300"
                        :class="i <= paso ? 'bg-purple-600' : 'bg-tinta-200'"
                    />
                </div>
            </div>

            <!-- Cuerpo scrollable -->
            <div class="flex-1 overflow-y-auto px-5 py-4">

                <!-- ══ PASO 1: Diseño ════════════════════════════════════════════ -->
                <div v-if="paso === 1">
                    <h3 class="text-base font-semibold text-tinta-900 mb-4">¿Cómo quieres que se vea tu plantilla?</h3>

                    <!-- Estilo visual -->
                    <div class="mb-4">
                        <label class="text-xs font-semibold text-tinta-400 mb-1.5 block uppercase tracking-wide">Estilo visual</label>
                        <select v-model="diseno.estilo" class="w-full text-sm border border-linea rounded-xl px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-purple-500 bg-superficie">
                            <option v-for="e in estilos" :key="e.value" :value="e.value">{{ e.label }} — {{ e.desc }}</option>
                        </select>
                    </div>

                    <!-- Colores -->
                    <div class="grid grid-cols-2 gap-3 mb-4">
                        <div>
                            <label class="text-xs font-semibold text-tinta-400 mb-1.5 block uppercase tracking-wide">Color primario</label>
                            <div class="flex items-center gap-2 border border-linea rounded-xl px-3 py-2 bg-superficie">
                                <input type="color" v-model="diseno.colorPrimario" class="w-7 h-7 rounded-lg cursor-pointer border-0 p-0 bg-transparent" />
                                <span class="text-xs font-mono text-tinta-700">{{ diseno.colorPrimario }}</span>
                            </div>
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-tinta-400 mb-1.5 block uppercase tracking-wide">Color secundario</label>
                            <div class="flex items-center gap-2 border border-linea rounded-xl px-3 py-2 bg-superficie">
                                <input type="color" v-model="diseno.colorSecundario" class="w-7 h-7 rounded-lg cursor-pointer border-0 p-0 bg-transparent" />
                                <span class="text-xs font-mono text-tinta-700">{{ diseno.colorSecundario }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Orientación + Papel -->
                    <div class="grid grid-cols-2 gap-3 mb-4">
                        <div>
                            <label class="text-xs font-semibold text-tinta-400 mb-1.5 block uppercase tracking-wide">Orientación</label>
                            <div class="flex gap-1.5">
                                <button
                                    v-for="o in [{ value: 'portrait', label: 'Vertical' }, { value: 'landscape', label: 'Horizontal' }]"
                                    :key="o.value"
                                    @click="diseno.orientacion = o.value"
                                    class="flex-1 py-2 text-xs font-medium rounded-xl border transition-colors"
                                    :class="diseno.orientacion === o.value
                                        ? 'bg-purple-600 text-white border-purple-600'
                                        : 'border-linea text-tinta-500 hover:bg-tinta-50'"
                                >{{ o.label }}</button>
                            </div>
                        </div>
                        <div>
                            <label class="text-xs font-semibold text-tinta-400 mb-1.5 block uppercase tracking-wide">Tamaño papel</label>
                            <select v-model="diseno.papel" class="w-full text-sm border border-linea rounded-xl px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-purple-500 bg-superficie">
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
                        </div>
                    </div>
                    <!-- Dimensiones personalizadas -->
                    <div v-if="diseno.papel === 'personalizado'" class="flex gap-3 mb-4">
                        <div class="flex-1">
                            <label class="text-xs text-tinta-400 block mb-1">Ancho (mm)</label>
                            <input v-model="diseno.ancho_mm" type="number" min="50" max="500"
                                class="w-full border border-linea rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500"/>
                        </div>
                        <div class="flex-1">
                            <label class="text-xs text-tinta-400 block mb-1">Alto (mm)</label>
                            <input v-model="diseno.alto_mm" type="number" min="50" max="700"
                                class="w-full border border-linea rounded-lg px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500"/>
                        </div>
                    </div>

                    <!-- Logo -->
                    <div class="mb-4">
                        <div class="flex items-center justify-between mb-2">
                            <label class="text-xs font-semibold text-tinta-400 uppercase tracking-wide">¿Incluir logo de la empresa?</label>
                            <button
                                @click="diseno.logo = !diseno.logo"
                                class="relative w-9 h-5 rounded-full transition-colors shrink-0"
                                :style="{ backgroundColor: diseno.logo ? '#9333ea' : '#D1D5DB' }"
                            >
                                <span class="absolute top-0.5 w-4 h-4 bg-superficie rounded-full shadow transition-transform"
                                    :class="diseno.logo ? 'translate-x-4' : 'translate-x-0.5'" />
                            </button>
                        </div>
                        <div v-if="diseno.logo" class="mt-2 space-y-3">
                            <!-- Posición -->
                            <div class="flex gap-1.5">
                                <button
                                    v-for="pos in ['izquierda', 'centro', 'derecha']"
                                    :key="pos"
                                    @click="diseno.logoPosicion = pos"
                                    class="flex-1 py-1.5 text-xs font-medium rounded-lg border transition-colors capitalize"
                                    :class="diseno.logoPosicion === pos
                                        ? 'bg-pastel-violeta-2 text-aviso-violeta border-borde-aviso-violeta'
                                        : 'border-linea text-tinta-500 hover:bg-tinta-50'"
                                >{{ pos }}</button>
                            </div>
                            <!-- URL del logo -->
                            <div>
                                <label class="text-xs text-tinta-400 block mb-1">URL del logo <span class="text-tinta-300">(opcional)</span></label>
                                <input
                                    v-model="diseno.logo_url"
                                    placeholder="https://tudominio.com/logo.png"
                                    class="w-full border border-linea rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500"
                                />
                                <p class="text-xs text-tinta-300 mt-1">En SGI: <code class="bg-tinta-100 px-1 rounded">/storage/logos/logo.png</code></p>
                            </div>
                            <!-- Vista previa del logo -->
                            <div v-if="diseno.logo_url" class="flex items-center gap-2 p-2 bg-tinta-50 rounded-lg">
                                <img
                                    :src="diseno.logo_url"
                                    class="h-8 object-contain"
                                    @error="diseno.logo_url_error = true"
                                    @load="diseno.logo_url_error = false"
                                />
                                <span v-if="!diseno.logo_url_error" class="text-xs text-aviso-verde">✓ Logo válido</span>
                                <span v-else class="text-xs text-aviso-rojo">✗ No se puede cargar</span>
                            </div>
                            <!-- Ancho del logo -->
                            <div class="flex items-center gap-2">
                                <label class="text-xs text-tinta-400 whitespace-nowrap">Ancho logo (px)</label>
                                <input v-model="diseno.logo_ancho" type="number" min="40" max="300"
                                    class="w-20 border border-linea rounded-lg px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500"/>
                            </div>
                        </div>
                    </div>

                    <!-- Sección a generar -->
                    <div class="mb-4">
                        <label class="text-xs font-semibold text-tinta-400 mb-1.5 block uppercase tracking-wide">¿Para qué sección generar el prompt?</label>
                        <div class="grid grid-cols-2 gap-1.5">
                            <button
                                v-for="opt in opcionesSecciones"
                                :key="opt.value"
                                @click="seccionGeneracion = opt.value"
                                class="p-2.5 rounded-xl border text-left transition-colors"
                                :class="seccionGeneracion === opt.value
                                    ? 'bg-pastel-violeta border-borde-aviso-violeta text-aviso-violeta'
                                    : 'border-linea text-tinta-500 hover:bg-tinta-50'"
                            >
                                <p class="text-xs font-semibold">{{ opt.label }}</p>
                                <p class="text-xs text-tinta-300 mt-0.5 leading-tight">{{ opt.desc }}</p>
                            </button>
                        </div>
                    </div>

                    <!-- Descripción libre -->
                    <div>
                        <label class="text-xs font-semibold text-tinta-400 mb-1.5 block uppercase tracking-wide">Describe el diseño con tus palabras <span class="normal-case text-tinta-300 font-normal">(opcional)</span></label>
                        <textarea
                            v-model="diseno.descripcionLibre"
                            rows="3"
                            class="w-full text-sm border border-linea rounded-xl px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-purple-500 resize-none"
                            placeholder="Ej: Quiero una cotización elegante con el logo arriba a la izquierda, número grande a la derecha, tabla de items con filas alternas en gris claro..."
                        />
                    </div>
                </div>

                <!-- ══ PASO 2: Contenido ════════════════════════════════════════ -->
                <div v-else-if="paso === 2">
                    <h3 class="text-base font-semibold text-tinta-900 mb-1">¿Qué información debe incluir?</h3>
                    <p class="text-xs text-tinta-300 mb-4">Selecciona las variables que quieres en tu documento</p>

                    <!-- Grupos regulares -->
                    <div v-for="grupo in gruposRegulares" :key="grupo.grupo" class="mb-5">
                        <div class="flex items-center justify-between mb-1.5">
                            <span class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">{{ grupo.grupo }}</span>
                            <button @click="toggleGrupo(grupo.grupo)" class="text-xs text-aviso-violeta hover:text-aviso-violeta font-medium transition-colors">
                                {{ grupoTodo(grupo.grupo) ? 'Quitar todos' : 'Seleccionar todos' }}
                            </button>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-0.5">
                            <label
                                v-for="v in grupo.vars"
                                :key="v.var"
                                class="flex items-start gap-2 p-2 rounded-lg cursor-pointer hover:bg-tinta-50 transition-colors"
                            >
                                <input
                                    type="checkbox"
                                    :checked="isRegular(v.var)"
                                    @change="toggleRegular(v.var)"
                                    class="mt-0.5 w-3.5 h-3.5 rounded shrink-0 accent-purple-600"
                                />
                                <div class="min-w-0">
                                    <code class="text-xs font-mono text-[var(--marca)] break-all leading-tight">{{ v.var }}</code>
                                    <p class="text-xs text-tinta-300 leading-tight mt-0.5">{{ v.desc }}</p>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Columnas de tabla -->
                    <div v-if="grupoTabla.length > 0" class="mb-5">
                        <div class="flex items-center justify-between mb-1.5">
                            <span class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Columnas de la tabla</span>
                            <button @click="toggleTodasTabla" class="text-xs text-aviso-violeta hover:text-aviso-violeta font-medium transition-colors">
                                {{ tablasTodo() ? 'Quitar todas' : 'Seleccionar todas' }}
                            </button>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-0.5">
                            <label
                                v-for="v in grupoTabla"
                                :key="v.var"
                                class="flex items-start gap-2 p-2 rounded-lg cursor-pointer hover:bg-tinta-50 transition-colors"
                            >
                                <input
                                    type="checkbox"
                                    :checked="isTabla(v.var)"
                                    @change="toggleTablaVar(v.var)"
                                    class="mt-0.5 w-3.5 h-3.5 rounded shrink-0 accent-purple-600"
                                />
                                <div class="min-w-0">
                                    <code class="text-xs font-mono text-[var(--marca)] break-all leading-tight">{{ v.var }}</code>
                                    <p class="text-xs text-tinta-300 leading-tight mt-0.5">{{ v.desc }}</p>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Opciones de tabla -->
                    <div class="bg-tinta-50 rounded-xl p-4 space-y-3">
                        <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Opciones de tabla</p>
                        <div
                            v-for="opt in opcionesTabla"
                            :key="opt.key"
                            class="flex items-center justify-between gap-3"
                        >
                            <span class="text-xs text-tinta-700">{{ opt.label }}</span>
                            <button
                                @click="diseno[opt.key] = !diseno[opt.key]"
                                class="relative w-9 h-5 rounded-full transition-colors shrink-0"
                                :style="{ backgroundColor: diseno[opt.key] ? '#9333ea' : '#D1D5DB' }"
                            >
                                <span class="absolute top-0.5 w-4 h-4 bg-superficie rounded-full shadow transition-transform"
                                    :class="diseno[opt.key] ? 'translate-x-4' : 'translate-x-0.5'" />
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ══ PASO 3: Prompt generado ══════════════════════════════════ -->
                <div v-else-if="paso === 3">
                    <h3 class="text-base font-semibold text-tinta-900 mb-1">Tu prompt está listo</h3>
                    <p class="text-xs text-tinta-300 mb-3">Copia el prompt y pégalo en Gemini o Claude para generar el HTML</p>

                    <!-- Múltiples secciones: tabs -->
                    <template v-if="seccionGeneracion === 'todas'">
                        <div class="flex gap-1 mb-3 bg-tinta-100 rounded-lg p-0.5">
                            <button v-for="s in [{ key:'header', label:'⬆ Encabezado' }, { key:'body', label:'📄 Cuerpo' }, { key:'footer', label:'⬇ Pie' }]"
                                :key="s.key"
                                @click="tabPrompt = s.key"
                                class="flex-1 py-1.5 text-xs font-medium rounded-lg transition-all"
                                :class="tabPrompt === s.key ? 'bg-superficie text-aviso-violeta shadow-sm' : 'text-tinta-400 hover:text-tinta-700'">
                                {{ s.label }}
                            </button>
                        </div>
                        <textarea
                            :value="promptsMultiple[tabPrompt]"
                            readonly
                            rows="14"
                            class="w-full text-xs border border-linea rounded-xl p-3 bg-tinta-50 resize-none focus:outline-none focus:ring-2 focus:ring-purple-500 leading-relaxed"
                            style="font-family: 'Courier New', Courier, monospace;"
                        />
                        <div class="flex flex-wrap gap-2 mt-3">
                            <button @click="copiarPrompt(promptsMultiple[tabPrompt])"
                                class="flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-xl transition-all duration-200"
                                :class="copiado ? 'bg-pastel-verde-2 text-aviso-verde border border-borde-aviso-verde' : 'bg-gray-900 text-white hover:bg-gray-700'">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path v-if="!copiado" stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                                    <path v-else stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                </svg>
                                {{ copiado ? '✓ Copiado' : `📋 Copiar prompt (${tabPrompt})` }}
                            </button>
                        </div>
                    </template>

                    <!-- Sección única -->
                    <template v-else>
                        <textarea
                            :value="promptGenerado"
                            readonly
                            rows="16"
                            class="w-full text-xs border border-linea rounded-xl p-3 bg-tinta-50 resize-none focus:outline-none focus:ring-2 focus:ring-purple-500 leading-relaxed"
                            style="font-family: 'Courier New', Courier, monospace;"
                        />
                        <div class="flex flex-wrap gap-2 mt-3">
                            <button @click="copiarPrompt()"
                                class="flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-xl transition-all duration-200"
                                :class="copiado ? 'bg-pastel-verde-2 text-aviso-verde border border-borde-aviso-verde' : 'bg-gray-900 text-white hover:bg-gray-700'">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path v-if="!copiado" stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                                    <path v-else stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                </svg>
                                {{ copiado ? '✓ Copiado' : '📋 Copiar prompt' }}
                            </button>
                            <a href="https://gemini.google.com" target="_blank" rel="noopener noreferrer"
                                class="flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-xl bg-blue-600 text-white hover:bg-blue-700 transition-colors">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                                </svg>
                                ↗ Gemini
                            </a>
                            <a href="https://claude.ai" target="_blank" rel="noopener noreferrer"
                                class="flex items-center gap-1.5 px-4 py-2 text-sm font-semibold rounded-xl bg-orange-500 text-white hover:bg-orange-600 transition-colors">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                                </svg>
                                ↗ Claude
                            </a>
                        </div>
                    </template>

                    <!-- Instrucción -->
                    <div class="mt-4 bg-pastel-violeta border border-borde-aviso-violeta rounded-xl p-3">
                        <p class="text-xs text-aviso-violeta font-medium leading-relaxed">
                            <span class="font-semibold">1.</span> Copia el prompt →
                            <span class="font-semibold">2.</span> Abre Gemini o Claude →
                            <span class="font-semibold">3.</span> Pega y genera →
                            <span class="font-semibold">4.</span> Copia el HTML →
                            <span class="font-semibold">5.</span> Pégalo en el editor (modo Código)
                        </p>
                    </div>
                </div>

            </div>

            <!-- Footer: navegación -->
            <div class="px-5 py-4 border-t border-linea flex items-center justify-between shrink-0">
                <button
                    v-if="paso > 1"
                    @click="paso--"
                    class="flex items-center gap-1.5 px-4 py-2 text-sm font-medium text-tinta-500 border border-linea rounded-xl hover:bg-tinta-50 transition-colors"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                    </svg>
                    Volver
                </button>
                <div v-else />

                <!-- Siguiente (paso 1) -->
                <button
                    v-if="paso === 1"
                    @click="paso = 2"
                    class="flex items-center gap-1.5 px-5 py-2 text-sm font-semibold text-white rounded-xl transition-colors"
                    style="background-color: #9333ea;"
                >
                    Siguiente
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                    </svg>
                </button>

                <!-- Generar (paso 2) -->
                <button
                    v-else-if="paso === 2"
                    @click="generarPrompt"
                    class="flex items-center gap-1.5 px-5 py-2 text-sm font-semibold text-white rounded-xl transition-colors"
                    style="background-color: #9333ea;"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                    Generar prompt
                </button>

                <!-- Listo (paso 3) -->
                <button
                    v-else
                    @click="emit('cerrar')"
                    class="flex items-center gap-1.5 px-5 py-2 text-sm font-semibold text-white rounded-xl transition-colors"
                    style="background-color: #9333ea;"
                >
                    Listo
                </button>
            </div>

        </div>
    </div>
</template>
