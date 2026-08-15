<script setup>
import { ref, computed } from 'vue'
import { colorMarca } from '@/marca'

const props = defineProps({
    bloques:   { type: Array,  default: () => [] },
    variables: { type: Array,  default: () => [] },
    seccion:   { type: String, default: 'body' },
})

const emit = defineEmits(['update:bloques'])

// ─── Tipos de bloque ──────────────────────────────────────────────────────────
const TIPOS = {
    texto:       { label: 'Texto',       icono: 'T',  color: 'bg-pastel-azul-2 text-aviso-azul' },
    titulo:      { label: 'Título',      icono: 'H',  color: 'bg-[var(--marca-suave)] text-[var(--marca)]' },
    imagen:      { label: 'Imagen',      icono: '🖼',  color: 'bg-pastel-verde-2 text-aviso-verde' },
    separador:   { label: 'Separador',   icono: '─',  color: 'bg-tinta-100 text-tinta-500' },
    spacer:      { label: 'Espacio',     icono: '□',  color: 'bg-tinta-50 text-tinta-300' },
    variable:    { label: 'Variable',    icono: '⚡',  color: 'bg-pastel-ambar-2 text-aviso-ambar' },
    columnas:    { label: 'Columnas',    icono: '▦',  color: 'bg-pastel-azul-2 text-aviso-azul' },
    tabla:       { label: 'Tabla',       icono: '⊞',  color: 'bg-pastel-violeta-2 text-aviso-violeta' },
    totales:     { label: 'Totales',     icono: '$',  color: 'bg-pastel-verde-2 text-aviso-verde' },
    qr:          { label: 'QR',          icono: '▣',  color: 'bg-pastel-naranja-2 text-aviso-naranja' },
    firma:       { label: 'Firma',       icono: '✍',  color: 'bg-pink-100 text-pink-700' },
    sello:       { label: 'Sello',       icono: '●',  color: 'bg-pastel-rojo-2 text-aviso-rojo' },
    lista_items: { label: 'Lista items', icono: '☰',  color: 'bg-pastel-violeta-2 text-aviso-violeta' },
}

// ─── Estado ───────────────────────────────────────────────────────────────────
const bloqueSeleccionadoId = ref(null)
const mostrarMenuAgregar   = ref(false)
const dragFromIdx          = ref(null)
const dropVarAbierto       = ref(false)
const tablaFileInput       = ref(null)
const dragColIdx           = ref(null)
const dragOverColIdx       = ref(null)

// ─── Computed ─────────────────────────────────────────────────────────────────
const bloqueSeleccionado = computed(() =>
    props.bloques.find(b => b.id === bloqueSeleccionadoId.value) ?? null
)

const variablesPlanas = computed(() =>
    (props.variables ?? []).filter(v => !v.var.includes('...')).map(v => v.var)
)

// ─── Utilidades ───────────────────────────────────────────────────────────────
function nuevoId() {
    return 'b' + Date.now().toString(36) + Math.random().toString(36).slice(2, 5)
}

function tipoLabel(bloque) {
    const contenido = bloque.props?.contenido ?? bloque.props?.variable ?? bloque.props?.texto ?? ''
    if (contenido) return contenido.length > 28 ? contenido.slice(0, 28) + '…' : contenido
    return TIPOS[bloque.tipo]?.label ?? bloque.tipo
}

function propsPorDefecto(tipo) {
    const mapa = {
        texto:       { contenido: 'Escribe tu texto aquí', color: '', font_size: 10, alineacion: 'left', negrita: false, italica: false, margin_top: 0, margin_bottom: 0 },
        titulo:      { contenido: 'Título', nivel: 2, color: colorMarca(), alineacion: 'left' },
        imagen:      { src: '', ancho: 120, alineacion: 'left' },
        separador:   { color: '#dddddd', grosor: 1 },
        spacer:      { alto: 20 },
        variable:    { variable: '', color: '', font_size: 10, negrita: false },
        columnas:    { columnas: 2, contenido_columnas: [{ bloques: [] }, { bloques: [] }] },
        tabla:       { columnas: [
            { etiqueta: '#', variable: 'index' },
            { etiqueta: 'Descripción', variable: 'descripcion' },
            { etiqueta: 'Cant.', variable: 'cantidad' },
            { etiqueta: 'Precio', variable: 'precio_unitario|moneda' },
            { etiqueta: 'Total', variable: 'total_linea|moneda' },
        ]},
        totales:     { filas: [
            { etiqueta: 'Subtotal', variable: 'cotizacion.subtotal|moneda', destacado: false },
            { etiqueta: 'TOTAL',    variable: 'cotizacion.total|moneda',    destacado: true },
        ]},
        qr:          { tamanio: 80, alineacion: 'center', etiqueta: '' },
        firma:       { nombre: '', cargo: '', espacio_superior: 40 },
        sello:       { texto: 'APROBADO', alineacion: 'center' },
        lista_items: {},
    }
    return mapa[tipo] ?? {}
}

// ─── Operaciones sobre la lista de bloques ────────────────────────────────────
function agregarBloque(tipo) {
    const nuevo = { id: nuevoId(), tipo, props: propsPorDefecto(tipo) }
    emit('update:bloques', [...props.bloques, nuevo])
    bloqueSeleccionadoId.value = nuevo.id
    mostrarMenuAgregar.value = false
}

function eliminarBloque(id) {
    if (bloqueSeleccionadoId.value === id) bloqueSeleccionadoId.value = null
    emit('update:bloques', props.bloques.filter(b => b.id !== id))
}

function moverArriba(idx) {
    if (idx === 0) return
    const lista = [...props.bloques]
    ;[lista[idx - 1], lista[idx]] = [lista[idx], lista[idx - 1]]
    emit('update:bloques', lista)
}

function moverAbajo(idx) {
    if (idx >= props.bloques.length - 1) return
    const lista = [...props.bloques]
    ;[lista[idx], lista[idx + 1]] = [lista[idx + 1], lista[idx]]
    emit('update:bloques', lista)
}

// ─── Drag & drop ─────────────────────────────────────────────────────────────
function dragStart(e, idx) {
    dragFromIdx.value = idx
    e.dataTransfer.effectAllowed = 'move'
}

function dragOver(e) {
    e.preventDefault()
    e.dataTransfer.dropEffect = 'move'
}

function dragDrop(e, toIdx) {
    e.preventDefault()
    const from = dragFromIdx.value
    if (from === null || from === toIdx) { dragFromIdx.value = null; return }
    const lista = [...props.bloques]
    const [removed] = lista.splice(from, 1)
    lista.splice(toIdx, 0, removed)
    emit('update:bloques', lista)
    dragFromIdx.value = null
}

// ─── Actualización de props de un bloque ─────────────────────────────────────
function actualizarProp(bloqueId, prop, val) {
    emit('update:bloques', props.bloques.map(b =>
        b.id === bloqueId ? { ...b, props: { ...b.props, [prop]: val } } : b
    ))
}

function actualizarColumna(bloque, colIdx, nuevosBloques) {
    const nuevasCols = bloque.props.contenido_columnas.map((col, i) =>
        i === colIdx ? { ...col, bloques: nuevosBloques } : col
    )
    actualizarProp(bloque.id, 'contenido_columnas', nuevasCols)
}

function cambiarNumColumnas(bloque, n) {
    const cols = [...(bloque.props.contenido_columnas ?? [])]
    while (cols.length < n) cols.push({ bloques: [] })
    const nuevasCols = cols.slice(0, n)
    emit('update:bloques', props.bloques.map(b =>
        b.id === bloque.id
            ? { ...b, props: { ...b.props, columnas: n, contenido_columnas: nuevasCols } }
            : b
    ))
}

// Tabla
function agregarColTabla(bloque) {
    actualizarProp(bloque.id, 'columnas', [...(bloque.props.columnas ?? []), { etiqueta: 'Nueva', variable: '' }])
}

function eliminarColTabla(bloque, idx) {
    actualizarProp(bloque.id, 'columnas', bloque.props.columnas.filter((_, i) => i !== idx))
}

function editarColTabla(bloque, idx, field, val) {
    actualizarProp(bloque.id, 'columnas', bloque.props.columnas.map((c, i) =>
        i === idx ? { ...c, [field]: val } : c
    ))
}

// Totales
function agregarFilaTotales(bloque) {
    actualizarProp(bloque.id, 'filas', [...(bloque.props.filas ?? []), { etiqueta: 'Fila', variable: '', destacado: false }])
}

function eliminarFilaTotales(bloque, idx) {
    actualizarProp(bloque.id, 'filas', bloque.props.filas.filter((_, i) => i !== idx))
}

function editarFilaTotales(bloque, idx, field, val) {
    actualizarProp(bloque.id, 'filas', bloque.props.filas.map((f, i) =>
        i === idx ? { ...f, [field]: val } : f
    ))
}

// Imagen
function subirImagen(e, bloqueId) {
    const file = e.target.files?.[0]
    if (!file) return
    const reader = new FileReader()
    reader.onload = ev => actualizarProp(bloqueId, 'src', ev.target.result)
    reader.readAsDataURL(file)
}

// ─── Drag & drop columnas de tabla ───────────────────────────────────────────
function onColDragStart(e, idx) {
    dragColIdx.value = idx
    e.dataTransfer.effectAllowed = 'move'
    e.dataTransfer.setData('text/plain', String(idx))
}

function onColDragOver(e, idx) {
    e.preventDefault()
    dragOverColIdx.value = idx
}

function onColDrop(e, idx) {
    e.preventDefault()
    const fromIdx = dragColIdx.value
    if (fromIdx === null || fromIdx === idx) {
        dragColIdx.value = null
        dragOverColIdx.value = null
        return
    }
    const cols = [...bloqueSeleccionado.value.props.columnas]
    const [item] = cols.splice(fromIdx, 1)
    cols.splice(idx, 0, item)
    actualizarProp(bloqueSeleccionado.value.id, 'columnas', cols)
    dragColIdx.value = null
    dragOverColIdx.value = null
}

function onColDragEnd() {
    dragColIdx.value = null
    dragOverColIdx.value = null
}
</script>

<template>
    <div class="flex flex-col gap-2">

        <!-- ─── Toolbar: Agregar bloque ──────────────────────────────────── -->
        <div class="flex items-center gap-2 relative">
            <button
                @click="mostrarMenuAgregar = !mostrarMenuAgregar"
                class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-white rounded-lg"
                style="background-color: var(--marca);"
            >
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                </svg>
                Agregar bloque
            </button>

            <!-- Dropdown tipos -->
            <div
                v-if="mostrarMenuAgregar"
                class="absolute top-full left-0 mt-1 z-30 bg-superficie border border-linea rounded-xl shadow-xl p-3 grid grid-cols-4 gap-1.5"
                style="min-width: 280px;"
                @click.stop
            >
                <button
                    v-for="(info, tipo) in TIPOS"
                    :key="tipo"
                    @click="agregarBloque(tipo)"
                    class="flex flex-col items-center gap-1 p-2 rounded-lg hover:bg-tinta-50 transition-colors text-center"
                >
                    <span class="text-base leading-none">{{ info.icono }}</span>
                    <span class="text-xs text-tinta-500 leading-tight">{{ info.label }}</span>
                </button>
            </div>

            <span class="text-xs text-tinta-300">
                {{ props.bloques.length }} bloque{{ props.bloques.length !== 1 ? 's' : '' }}
            </span>
        </div>

        <!-- ─── Layout dos paneles ────────────────────────────────────────── -->
        <div class="flex flex-col lg:flex-row gap-3" style="min-height: 380px;">

            <!-- Panel izquierdo: Lista de bloques -->
            <div class="lg:w-60 shrink-0 bg-tinta-50 rounded-xl border border-linea flex flex-col overflow-hidden">
                <div v-if="!props.bloques.length" class="flex-1 flex items-center justify-center p-6 text-center">
                    <div>
                        <p class="text-xs text-tinta-300 mb-1">Sin bloques</p>
                        <p class="text-xs text-tinta-200">Haz clic en "Agregar bloque"</p>
                    </div>
                </div>

                <div
                    v-for="(bloque, idx) in props.bloques"
                    :key="bloque.id"
                    draggable="true"
                    @dragstart="dragStart($event, idx)"
                    @dragover="dragOver($event)"
                    @drop="dragDrop($event, idx)"
                    @click="bloqueSeleccionadoId = bloque.id; mostrarMenuAgregar = false"
                    class="group flex items-center gap-2 px-2.5 py-2 cursor-pointer border-b border-linea transition-colors select-none"
                    :class="[
                        bloqueSeleccionadoId === bloque.id
                            ? 'bg-pastel-azul border-l-2 border-l-[var(--marca)]'
                            : 'hover:bg-superficie',
                        dragFromIdx === idx ? 'opacity-30' : ''
                    ]"
                >
                    <!-- Handle drag -->
                    <span class="text-tinta-200 text-xs cursor-grab">⋮⋮</span>
                    <!-- Icono tipo -->
                    <span
                        class="w-5 h-5 rounded flex items-center justify-center text-xs shrink-0 font-semibold"
                        :class="TIPOS[bloque.tipo]?.color ?? 'bg-tinta-100 text-tinta-400'"
                    >{{ TIPOS[bloque.tipo]?.icono ?? '?' }}</span>
                    <!-- Label -->
                    <span class="flex-1 text-xs text-tinta-700 truncate min-w-0">
                        {{ tipoLabel(bloque) }}
                    </span>
                    <!-- Controles -->
                    <div class="flex items-center gap-0.5 shrink-0 opacity-0 group-hover:opacity-100 transition-opacity">
                        <button
                            @click.stop="moverArriba(idx)"
                            :disabled="idx === 0"
                            class="p-0.5 rounded hover:bg-tinta-200 text-tinta-300 disabled:opacity-20 text-xs"
                        >↑</button>
                        <button
                            @click.stop="moverAbajo(idx)"
                            :disabled="idx === props.bloques.length - 1"
                            class="p-0.5 rounded hover:bg-tinta-200 text-tinta-300 disabled:opacity-20 text-xs"
                        >↓</button>
                        <button
                            @click.stop="eliminarBloque(bloque.id)"
                            class="p-0.5 rounded hover:bg-pastel-rojo-2 text-red-400 text-xs"
                        >×</button>
                    </div>
                </div>
            </div>

            <!-- Panel derecho: Propiedades -->
            <div class="flex-1 min-w-0 bg-superficie rounded-xl border border-linea overflow-hidden flex flex-col">

                <!-- Sin selección -->
                <div v-if="!bloqueSeleccionado" class="flex-1 flex items-center justify-center p-8 text-center">
                    <div>
                        <svg class="w-10 h-10 text-gray-200 mx-auto mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 15l-2 5L9 9l11 4-5 2zm0 0l5 5M7.188 2.239l.777 2.897M5.136 7.965l-2.898-.777M13.95 4.05l-2.122 2.122m-5.657 5.656l-2.12 2.122"/>
                        </svg>
                        <p class="text-sm text-tinta-300">Selecciona un bloque<br/>para editar sus propiedades</p>
                    </div>
                </div>

                <!-- Propiedades del bloque seleccionado -->
                <div v-else class="flex-1 overflow-y-auto p-4">

                    <!-- Cabecera tipo -->
                    <div class="flex items-center gap-2 mb-4 pb-3 border-b border-linea">
                        <span class="w-7 h-7 rounded-lg flex items-center justify-center text-sm font-semibold" :class="TIPOS[bloqueSeleccionado.tipo]?.color">
                            {{ TIPOS[bloqueSeleccionado.tipo]?.icono }}
                        </span>
                        <span class="text-sm font-semibold text-tinta-900">{{ TIPOS[bloqueSeleccionado.tipo]?.label }}</span>
                    </div>

                    <!-- ══ TEXTO ══════════════════════════════════════════════ -->
                    <template v-if="bloqueSeleccionado.tipo === 'texto'">
                        <div class="space-y-3">
                            <div>
                                <label class="prop-label">Contenido</label>
                                <div class="relative">
                                    <textarea
                                        :value="bloqueSeleccionado.props.contenido"
                                        @input="actualizarProp(bloqueSeleccionado.id, 'contenido', $event.target.value)"
                                        rows="3"
                                        class="prop-textarea"
                                        placeholder="Texto del párrafo..."
                                    />
                                    <!-- Insert variable -->
                                    <div class="relative mt-1">
                                        <button @click="dropVarAbierto = dropVarAbierto === 'texto' ? false : 'texto'"
                                            class="text-xs text-[var(--marca)] hover:underline">
                                            + Insertar variable
                                        </button>
                                        <div v-if="dropVarAbierto === 'texto'" class="var-dropdown">
                                            <div v-for="v in variablesPlanas" :key="v"
                                                @click="actualizarProp(bloqueSeleccionado.id, 'contenido', (bloqueSeleccionado.props.contenido ?? '') + v); dropVarAbierto = false"
                                                class="var-item">
                                                <code class="text-xs text-[var(--marca)]">{{ v }}</code>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="prop-label">Tamaño fuente (px)</label>
                                    <input type="number" min="7" max="24"
                                        :value="bloqueSeleccionado.props.font_size"
                                        @input="actualizarProp(bloqueSeleccionado.id, 'font_size', +$event.target.value)"
                                        class="prop-input" />
                                </div>
                                <div>
                                    <label class="prop-label">Color</label>
                                    <input type="color"
                                        :value="bloqueSeleccionado.props.color || '#1a1a1a'"
                                        @input="actualizarProp(bloqueSeleccionado.id, 'color', $event.target.value)"
                                        class="prop-color" />
                                </div>
                            </div>
                            <div>
                                <label class="prop-label">Alineación</label>
                                <div class="flex gap-1">
                                    <button v-for="a in ['left','center','right','justify']" :key="a"
                                        @click="actualizarProp(bloqueSeleccionado.id, 'alineacion', a)"
                                        class="flex-1 py-1.5 text-xs rounded-lg border transition-colors"
                                        :class="bloqueSeleccionado.props.alineacion === a ? 'bg-[var(--marca)] text-white border-[var(--marca)]' : 'border-linea text-tinta-500 hover:bg-tinta-50'">
                                        {{ a === 'left' ? '⬅' : a === 'center' ? '↔' : a === 'right' ? '➡' : '⟺' }}
                                    </button>
                                </div>
                            </div>
                            <div class="flex gap-3">
                                <button @click="actualizarProp(bloqueSeleccionado.id, 'negrita', !bloqueSeleccionado.props.negrita)"
                                    class="flex-1 py-1.5 text-xs rounded-lg border transition-colors font-semibold"
                                    :class="bloqueSeleccionado.props.negrita ? 'bg-[var(--marca)] text-white border-[var(--marca)]' : 'border-linea text-tinta-500'">
                                    N
                                </button>
                                <button @click="actualizarProp(bloqueSeleccionado.id, 'italica', !bloqueSeleccionado.props.italica)"
                                    class="flex-1 py-1.5 text-xs rounded-lg border transition-colors italic"
                                    :class="bloqueSeleccionado.props.italica ? 'bg-[var(--marca)] text-white border-[var(--marca)]' : 'border-linea text-tinta-500'">
                                    I
                                </button>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="prop-label">Margen superior (px)</label>
                                    <input type="number" min="0" max="80"
                                        :value="bloqueSeleccionado.props.margin_top"
                                        @input="actualizarProp(bloqueSeleccionado.id, 'margin_top', +$event.target.value)"
                                        class="prop-input" />
                                </div>
                                <div>
                                    <label class="prop-label">Margen inferior (px)</label>
                                    <input type="number" min="0" max="80"
                                        :value="bloqueSeleccionado.props.margin_bottom"
                                        @input="actualizarProp(bloqueSeleccionado.id, 'margin_bottom', +$event.target.value)"
                                        class="prop-input" />
                                </div>
                            </div>
                        </div>
                    </template>

                    <!-- ══ TÍTULO ═════════════════════════════════════════════ -->
                    <template v-else-if="bloqueSeleccionado.tipo === 'titulo'">
                        <div class="space-y-3">
                            <div>
                                <label class="prop-label">Contenido</label>
                                <input type="text"
                                    :value="bloqueSeleccionado.props.contenido"
                                    @input="actualizarProp(bloqueSeleccionado.id, 'contenido', $event.target.value)"
                                    class="prop-input"
                                    placeholder="Título del documento..." />
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="prop-label">Nivel (H1–H4)</label>
                                    <select :value="bloqueSeleccionado.props.nivel"
                                        @change="actualizarProp(bloqueSeleccionado.id, 'nivel', +$event.target.value)"
                                        class="prop-select">
                                        <option value="1">H1 — Muy grande</option>
                                        <option value="2">H2 — Grande</option>
                                        <option value="3">H3 — Mediano</option>
                                        <option value="4">H4 — Pequeño</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="prop-label">Color</label>
                                    <input type="color"
                                        :value="bloqueSeleccionado.props.color || colorMarca()"
                                        @input="actualizarProp(bloqueSeleccionado.id, 'color', $event.target.value)"
                                        class="prop-color" />
                                </div>
                            </div>
                            <div>
                                <label class="prop-label">Alineación</label>
                                <div class="flex gap-1">
                                    <button v-for="a in ['left','center','right']" :key="a"
                                        @click="actualizarProp(bloqueSeleccionado.id, 'alineacion', a)"
                                        class="flex-1 py-1.5 text-xs rounded-lg border transition-colors"
                                        :class="bloqueSeleccionado.props.alineacion === a ? 'bg-[var(--marca)] text-white border-[var(--marca)]' : 'border-linea text-tinta-500 hover:bg-tinta-50'">
                                        {{ a === 'left' ? '⬅' : a === 'center' ? '↔' : '➡' }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </template>

                    <!-- ══ IMAGEN ═════════════════════════════════════════════ -->
                    <template v-else-if="bloqueSeleccionado.tipo === 'imagen'">
                        <div class="space-y-3">
                            <div>
                                <label class="prop-label">URL de la imagen</label>
                                <input type="text"
                                    :value="bloqueSeleccionado.props.src"
                                    @input="actualizarProp(bloqueSeleccionado.id, 'src', $event.target.value)"
                                    class="prop-input"
                                    placeholder="https://... o {{!variable}}" />
                            </div>
                            <div>
                                <label class="prop-label">O subir imagen local (base64)</label>
                                <input type="file" accept="image/*" class="hidden" ref="tablaFileInput"
                                    @change="subirImagen($event, bloqueSeleccionado.id)" />
                                <button @click="$refs.tablaFileInput.click()"
                                    class="px-3 py-1.5 text-xs rounded-lg border border-dashed border-tinta-200 text-tinta-500 hover:bg-tinta-50 w-full transition-colors">
                                    Seleccionar archivo
                                </button>
                            </div>
                            <div v-if="bloqueSeleccionado.props.src" class="rounded-lg border border-linea p-2 text-center bg-tinta-50">
                                <img :src="bloqueSeleccionado.props.src" class="h-16 object-contain mx-auto" @error="() => {}" />
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="prop-label">Ancho (px)</label>
                                    <input type="number" min="20" max="600"
                                        :value="bloqueSeleccionado.props.ancho"
                                        @input="actualizarProp(bloqueSeleccionado.id, 'ancho', +$event.target.value)"
                                        class="prop-input" />
                                </div>
                                <div>
                                    <label class="prop-label">Alineación</label>
                                    <select :value="bloqueSeleccionado.props.alineacion"
                                        @change="actualizarProp(bloqueSeleccionado.id, 'alineacion', $event.target.value)"
                                        class="prop-select">
                                        <option value="left">Izquierda</option>
                                        <option value="center">Centro</option>
                                        <option value="right">Derecha</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </template>

                    <!-- ══ SEPARADOR ══════════════════════════════════════════ -->
                    <template v-else-if="bloqueSeleccionado.tipo === 'separador'">
                        <div class="space-y-3">
                            <div>
                                <label class="prop-label">Color de línea</label>
                                <input type="color"
                                    :value="bloqueSeleccionado.props.color || '#dddddd'"
                                    @input="actualizarProp(bloqueSeleccionado.id, 'color', $event.target.value)"
                                    class="prop-color" />
                            </div>
                            <div>
                                <label class="prop-label">Grosor (px)</label>
                                <input type="number" min="1" max="10"
                                    :value="bloqueSeleccionado.props.grosor"
                                    @input="actualizarProp(bloqueSeleccionado.id, 'grosor', +$event.target.value)"
                                    class="prop-input" />
                            </div>
                        </div>
                    </template>

                    <!-- ══ SPACER ═════════════════════════════════════════════ -->
                    <template v-else-if="bloqueSeleccionado.tipo === 'spacer'">
                        <div>
                            <label class="prop-label">Alto del espacio (px)</label>
                            <input type="number" min="5" max="200"
                                :value="bloqueSeleccionado.props.alto"
                                @input="actualizarProp(bloqueSeleccionado.id, 'alto', +$event.target.value)"
                                class="prop-input" />
                        </div>
                    </template>

                    <!-- ══ VARIABLE ════════════════════════════════════════════ -->
                    <template v-else-if="bloqueSeleccionado.tipo === 'variable'">
                        <div class="space-y-3">
                            <div>
                                <label class="prop-label">Variable</label>
                                <select :value="bloqueSeleccionado.props.variable"
                                    @change="actualizarProp(bloqueSeleccionado.id, 'variable', $event.target.value)"
                                    class="prop-select">
                                    <option value="">— Selecciona —</option>
                                    <option v-for="v in variablesPlanas" :key="v" :value="v.replace(/[{}]/g, '')">{{ v }}</option>
                                </select>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="prop-label">Tamaño (px)</label>
                                    <input type="number" min="7" max="24"
                                        :value="bloqueSeleccionado.props.font_size"
                                        @input="actualizarProp(bloqueSeleccionado.id, 'font_size', +$event.target.value)"
                                        class="prop-input" />
                                </div>
                                <div>
                                    <label class="prop-label">Color</label>
                                    <input type="color"
                                        :value="bloqueSeleccionado.props.color || '#1a1a1a'"
                                        @input="actualizarProp(bloqueSeleccionado.id, 'color', $event.target.value)"
                                        class="prop-color" />
                                </div>
                            </div>
                            <button @click="actualizarProp(bloqueSeleccionado.id, 'negrita', !bloqueSeleccionado.props.negrita)"
                                class="w-full py-1.5 text-xs rounded-lg border transition-colors font-semibold"
                                :class="bloqueSeleccionado.props.negrita ? 'bg-[var(--marca)] text-white border-[var(--marca)]' : 'border-linea text-tinta-500'">
                                Negrita
                            </button>
                        </div>
                    </template>

                    <!-- ══ COLUMNAS ════════════════════════════════════════════ -->
                    <template v-else-if="bloqueSeleccionado.tipo === 'columnas'">
                        <div class="space-y-3">
                            <div>
                                <label class="prop-label">Número de columnas</label>
                                <div class="flex gap-2">
                                    <button v-for="n in [2, 3]" :key="n"
                                        @click="cambiarNumColumnas(bloqueSeleccionado, n)"
                                        class="flex-1 py-2 text-xs rounded-lg border font-medium transition-colors"
                                        :class="bloqueSeleccionado.props.columnas === n ? 'bg-[var(--marca)] text-white border-[var(--marca)]' : 'border-linea text-tinta-500 hover:bg-tinta-50'">
                                        {{ n }} columnas
                                    </button>
                                </div>
                            </div>
                            <div v-for="(col, colIdx) in bloqueSeleccionado.props.contenido_columnas" :key="colIdx">
                                <label class="prop-label">Columna {{ colIdx + 1 }}</label>
                                <div class="border border-linea rounded-xl p-2 bg-tinta-50">
                                    <EditorBloques
                                        :bloques="col.bloques"
                                        :variables="variables"
                                        seccion="columna"
                                        @update:bloques="actualizarColumna(bloqueSeleccionado, colIdx, $event)"
                                    />
                                </div>
                            </div>
                        </div>
                    </template>

                    <!-- ══ TABLA ══════════════════════════════════════════════ -->
                    <template v-else-if="bloqueSeleccionado.tipo === 'tabla'">
                        <div class="space-y-3">
                            <label class="prop-label">Columnas de la tabla (arrastra para reordenar)</label>
                            <div
                                v-for="(col, idx) in bloqueSeleccionado.props.columnas"
                                :key="idx"
                                :draggable="true"
                                @dragstart="onColDragStart($event, idx)"
                                @dragover.prevent="onColDragOver($event, idx)"
                                @drop.prevent="onColDrop($event, idx)"
                                @dragend="onColDragEnd"
                                class="flex items-center gap-2 p-2 mb-1 bg-superficie border rounded-lg transition-all"
                                :class="[
                                    dragColIdx === idx ? 'opacity-30' : '',
                                    dragOverColIdx === idx && dragColIdx !== idx
                                        ? 'border-[var(--marca)] border-2'
                                        : 'border-linea'
                                ]"
                            >
                                <span class="cursor-grab text-tinta-200 select-none shrink-0" style="touch-action:none;">⠿⠿</span>
                                <span class="text-xs text-tinta-300 w-4 shrink-0">{{ idx + 1 }}</span>
                                <input type="text"
                                    :value="col.etiqueta"
                                    @input="editarColTabla(bloqueSeleccionado, idx, 'etiqueta', $event.target.value)"
                                    placeholder="Etiqueta"
                                    class="flex-1 border border-linea rounded px-2 py-1 text-xs" />
                                <select :value="col.variable"
                                    @change="editarColTabla(bloqueSeleccionado, idx, 'variable', $event.target.value)"
                                    class="flex-1 border border-linea rounded px-2 py-1 text-xs">
                                    <option value="">— Variable —</option>
                                    <option v-for="v in variablesPlanas" :key="v" :value="v.replace(/[{}]/g, '')">{{ v }}</option>
                                    <option value="index">#</option>
                                    <option value="descripcion">descripcion</option>
                                    <option value="cantidad">cantidad</option>
                                    <option value="precio_unitario|moneda">precio_unitario</option>
                                    <option value="total_linea|moneda">total_linea</option>
                                </select>
                                <input
                                    type="number"
                                    :value="col.ancho || ''"
                                    @input="editarColTabla(bloqueSeleccionado, idx, 'ancho', $event.target.value ? +$event.target.value : null)"
                                    placeholder="px"
                                    min="20" max="500"
                                    class="w-14 border border-linea rounded px-1 py-1 text-xs shrink-0"
                                    title="Ancho en px (vacío = auto)" />
                                <button @click="eliminarColTabla(bloqueSeleccionado, idx)"
                                    class="text-red-400 hover:text-aviso-rojo shrink-0">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none"
                                        viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                    </svg>
                                </button>
                            </div>
                            <button @click="agregarColTabla(bloqueSeleccionado)"
                                class="w-full py-1.5 text-xs rounded-lg border border-dashed border-tinta-200 text-tinta-400 hover:bg-tinta-50 transition-colors">
                                + Agregar columna
                            </button>
                        </div>
                    </template>

                    <!-- ══ TOTALES ════════════════════════════════════════════ -->
                    <template v-else-if="bloqueSeleccionado.tipo === 'totales'">
                        <div class="space-y-3">
                            <label class="prop-label">Filas de totales</label>
                            <div
                                v-for="(fila, idx) in bloqueSeleccionado.props.filas"
                                :key="idx"
                                class="flex items-center gap-2 bg-tinta-50 rounded-lg p-2"
                            >
                                <input type="text"
                                    :value="fila.etiqueta"
                                    @input="editarFilaTotales(bloqueSeleccionado, idx, 'etiqueta', $event.target.value)"
                                    placeholder="Etiqueta"
                                    class="prop-input flex-1" />
                                <select :value="fila.variable"
                                    @change="editarFilaTotales(bloqueSeleccionado, idx, 'variable', $event.target.value)"
                                    class="prop-select flex-1">
                                    <option value="">— Variable —</option>
                                    <option v-for="v in variablesPlanas" :key="v" :value="v.replace(/[{}]/g, '')">{{ v }}</option>
                                </select>
                                <button
                                    @click="editarFilaTotales(bloqueSeleccionado, idx, 'destacado', !fila.destacado)"
                                    class="shrink-0 w-6 h-6 rounded flex items-center justify-center text-xs border transition-colors"
                                    :class="fila.destacado ? 'bg-[var(--marca)] text-white border-[var(--marca)]' : 'border-linea text-tinta-300'"
                                    title="Destacado">★</button>
                                <button @click="eliminarFilaTotales(bloqueSeleccionado, idx)"
                                    class="text-red-400 hover:text-aviso-rojo text-sm shrink-0">×</button>
                            </div>
                            <button @click="agregarFilaTotales(bloqueSeleccionado)"
                                class="w-full py-1.5 text-xs rounded-lg border border-dashed border-tinta-200 text-tinta-400 hover:bg-tinta-50 transition-colors">
                                + Agregar fila
                            </button>
                        </div>
                    </template>

                    <!-- ══ QR ═════════════════════════════════════════════════ -->
                    <template v-else-if="bloqueSeleccionado.tipo === 'qr'">
                        <div class="space-y-3">
                            <div>
                                <label class="prop-label">Tamaño (px)</label>
                                <input type="number" min="40" max="200"
                                    :value="bloqueSeleccionado.props.tamanio"
                                    @input="actualizarProp(bloqueSeleccionado.id, 'tamanio', +$event.target.value)"
                                    class="prop-input" />
                            </div>
                            <div>
                                <label class="prop-label">Alineación</label>
                                <select :value="bloqueSeleccionado.props.alineacion"
                                    @change="actualizarProp(bloqueSeleccionado.id, 'alineacion', $event.target.value)"
                                    class="prop-select">
                                    <option value="left">Izquierda</option>
                                    <option value="center">Centro</option>
                                    <option value="right">Derecha</option>
                                </select>
                            </div>
                            <div>
                                <label class="prop-label">Etiqueta debajo del QR</label>
                                <input type="text"
                                    :value="bloqueSeleccionado.props.etiqueta"
                                    @input="actualizarProp(bloqueSeleccionado.id, 'etiqueta', $event.target.value)"
                                    class="prop-input"
                                    placeholder="Ej: Escanear para seguimiento" />
                            </div>
                            <p class="text-xs text-tinta-300">El QR usa la variable <code class="bg-tinta-100 px-1 rounded">&#123;&#123;!op.qr_imagen&#125;&#125;</code> automáticamente.</p>
                        </div>
                    </template>

                    <!-- ══ FIRMA ══════════════════════════════════════════════ -->
                    <template v-else-if="bloqueSeleccionado.tipo === 'firma'">
                        <div class="space-y-3">
                            <div>
                                <label class="prop-label">Nombre del firmante</label>
                                <input type="text"
                                    :value="bloqueSeleccionado.props.nombre"
                                    @input="actualizarProp(bloqueSeleccionado.id, 'nombre', $event.target.value)"
                                    class="prop-input"
                                    placeholder="Ej: Gerente General" />
                            </div>
                            <div>
                                <label class="prop-label">Cargo</label>
                                <input type="text"
                                    :value="bloqueSeleccionado.props.cargo"
                                    @input="actualizarProp(bloqueSeleccionado.id, 'cargo', $event.target.value)"
                                    class="prop-input"
                                    placeholder="Ej: Representante Legal" />
                            </div>
                            <div>
                                <label class="prop-label">Espacio sobre la línea (px)</label>
                                <input type="number" min="10" max="150"
                                    :value="bloqueSeleccionado.props.espacio_superior"
                                    @input="actualizarProp(bloqueSeleccionado.id, 'espacio_superior', +$event.target.value)"
                                    class="prop-input" />
                            </div>
                        </div>
                    </template>

                    <!-- ══ SELLO ══════════════════════════════════════════════ -->
                    <template v-else-if="bloqueSeleccionado.tipo === 'sello'">
                        <div class="space-y-3">
                            <div>
                                <label class="prop-label">Texto del sello</label>
                                <input type="text"
                                    :value="bloqueSeleccionado.props.texto"
                                    @input="actualizarProp(bloqueSeleccionado.id, 'texto', $event.target.value)"
                                    class="prop-input"
                                    placeholder="Ej: APROBADO" />
                            </div>
                            <div>
                                <label class="prop-label">Alineación</label>
                                <select :value="bloqueSeleccionado.props.alineacion"
                                    @change="actualizarProp(bloqueSeleccionado.id, 'alineacion', $event.target.value)"
                                    class="prop-select">
                                    <option value="left">Izquierda</option>
                                    <option value="center">Centro</option>
                                    <option value="right">Derecha</option>
                                </select>
                            </div>
                        </div>
                    </template>

                    <!-- ══ LISTA ITEMS ════════════════════════════════════════ -->
                    <template v-else-if="bloqueSeleccionado.tipo === 'lista_items'">
                        <div class="bg-pastel-azul rounded-xl p-3">
                            <p class="text-xs text-aviso-azul leading-relaxed">
                                Este bloque genera automáticamente una lista con todos los items usando
                                <code class="bg-pastel-azul-2 px-1 rounded">&#123;&#123;#items&#125;&#125;…&#123;&#123;/items&#125;&#125;</code>.
                                No requiere configuración adicional.
                            </p>
                        </div>
                    </template>

                </div>
            </div>
        </div>
    </div>

    <!-- Cerrar dropdowns al hacer click fuera -->
    <div v-if="mostrarMenuAgregar || dropVarAbierto" class="fixed inset-0 z-20" @click="mostrarMenuAgregar = false; dropVarAbierto = false" />
</template>

<style scoped>
.prop-label {
    @apply text-xs font-semibold text-tinta-400 block mb-1 uppercase tracking-wide;
}
.prop-input {
    @apply w-full text-xs border border-linea rounded-lg px-2.5 py-1.5 focus:outline-none focus:ring-2 focus:ring-[var(--marca)] bg-superficie;
}
.prop-textarea {
    @apply w-full text-xs border border-linea rounded-lg px-2.5 py-1.5 focus:outline-none focus:ring-2 focus:ring-[var(--marca)] bg-superficie resize-none;
}
.prop-select {
    @apply w-full text-xs border border-linea rounded-lg px-2.5 py-1.5 focus:outline-none focus:ring-2 focus:ring-[var(--marca)] bg-superficie;
}
.prop-color {
    @apply w-full h-8 rounded-lg border border-linea cursor-pointer p-0.5 bg-superficie;
}
.var-dropdown {
    @apply absolute top-5 left-0 z-30 bg-superficie border border-linea rounded-xl shadow-lg py-1 max-h-48 overflow-y-auto;
    min-width: 220px;
}
.var-item {
    @apply px-3 py-1.5 cursor-pointer hover:bg-realce transition-colors;
}
</style>
