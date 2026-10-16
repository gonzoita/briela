<script setup>
/**
 * Las líneas de una cotización, agrupadas en secciones que se arrastran.
 *
 * Solo dibuja y mueve: no sabe de precios ni de comisiones. Cada fila es una **tabla compacta** (una
 * línea por ítem) que se expande al tocarla; lo que va en las celdas y en el detalle lo pone la
 * pantalla por los slots `fila` y `detalle`. Así la pantalla conserva todas sus reglas y esta pieza
 * se queda con lo que es común: los grupos, el arrastre y qué fila está abierta.
 *
 * - Una sección es una zona: se le suelta un ítem encima (o entre sus filas) y queda dentro. Las
 *   secciones también se reordenan arrastrándolas por su asa.
 * - HTML5 no arrastra con el dedo, así que en el celular las mismas acciones salen del detalle de
 *   cada ítem (subir, bajar, cambiar de sección). El asa solo existe en pantallas medianas.
 * - Nunca muta lo que recibe: avisa con eventos y la pantalla decide (reglas de orden, nombres
 *   repetidos, confirmaciones).
 */
import { ref, reactive, computed, nextTick, toRaw } from 'vue'

const props = defineProps({
    items:     { type: Array, required: true },
    // Los nombres de las secciones, en su orden; incluye las que todavía no tienen ítems.
    secciones: { type: Array, default: () => [] },
    // 'tabla': las filas arrancan plegadas. 'detallado': arrancan abiertas.
    modo:      { type: String, default: 'tabla' },
    // El `grid-template-columns` de las celdas de la fila (sin el asa ni la flecha), en pantallas medianas.
    columnas:  { type: String, required: true },
})

const emit = defineEmits(['mover', 'mover-seccion', 'agregar', 'renombrar-seccion', 'eliminar-seccion'])

// ─── Identidad de cada fila ──────────────────────────────────────────────────
// Un índice como `key` hace que, al reordenar, los campos de una fila queden en otra. Se le da a
// cada ítem un número estable SIN escribirle nada: lo que se guarda en la cotización no cambia.
const uids = new WeakMap()
let siguiente = 1
const uid = (item) => {
    const crudo = toRaw(item)
    if (! uids.has(crudo)) uids.set(crudo, siguiente++)
    return uids.get(crudo)
}

// ─── Qué está abierto ────────────────────────────────────────────────────────
// Se guarda lo que el usuario cambió contra el modo: en «detallado» están abiertas todas menos las
// que plegó, y en «tabla» están plegadas todas menos las que abrió.
const alternadas = reactive(new Set())
const plegadas   = reactive(new Set())

const abierta = (item) =>
    !! item.problema || ((props.modo === 'detallado') !== alternadas.has(uid(item)))

function alternar(item) {
    const k = uid(item)
    alternadas.has(k) ? alternadas.delete(k) : alternadas.add(k)
}

function expandirTodo()  { alternadas.clear() }
defineExpose({ reiniciar: () => alternadas.clear(), expandirTodo })

// Tocar la fila la abre, pero no si el toque era para escribir o para pulsar algo de adentro.
function clicFila(e, item) {
    if (e.target.closest('input, select, textarea, button, a, label, [data-no-alternar]')) return
    alternar(item)
}

// ─── Los grupos ──────────────────────────────────────────────────────────────
// Lo que no tiene sección va al final como «Sin sección»: es lo que el PDF imprime como «Otros».
const grupos = computed(() => {
    const por = new Map(props.secciones.map(n => [n, []]))
    const sin = []

    props.items.forEach((item, idx) => {
        const s = (item.seccion ?? '').trim()
        ;(por.get(s) ?? sin).push({ item, idx })
    })

    return [
        ...props.secciones.map(nombre => ({ nombre, filas: por.get(nombre), sin: false })),
        { nombre: '', filas: sin, sin: true },
    ]
})

const haySecciones = computed(() => props.secciones.length > 0)

function alternarPliegue(nombre) {
    plegadas.has(nombre) ? plegadas.delete(nombre) : plegadas.add(nombre)
}

// ─── Arrastrar y soltar ──────────────────────────────────────────────────────
const arrastrando = ref(null)  // { tipo: 'item', item } | { tipo: 'seccion', nombre }
const sobre       = ref(null)  // { tipo: 'fila', clave, despues } | { tipo: 'grupo', nombre }

function limpiar() { arrastrando.value = null; sobre.value = null }

function empezar(e, datos, elemento) {
    arrastrando.value = datos
    e.dataTransfer.effectAllowed = 'move'
    // Firefox no empieza a arrastrar sin algún dato.
    e.dataTransfer.setData('text/plain', datos.tipo)
    if (elemento) { try { e.dataTransfer.setDragImage(elemento, 16, 16) } catch (err) { /* sin imagen propia */ } }
}

const empezarItem    = (e, item) => empezar(e, { tipo: 'item', item }, e.currentTarget.closest('[data-fila]'))
const empezarSeccion = (e, nombre) => empezar(e, { tipo: 'seccion', nombre }, e.currentTarget.closest('[data-grupo]'))

function sobreFila(e, item) {
    if (arrastrando.value?.tipo !== 'item' || arrastrando.value.item === item) return
    e.preventDefault()
    const r = e.currentTarget.getBoundingClientRect()
    sobre.value = { tipo: 'fila', clave: uid(item), despues: e.clientY > r.top + r.height / 2 }
}

function soltarFila(e, item, grupo) {
    if (arrastrando.value?.tipo !== 'item') return
    e.preventDefault()
    e.stopPropagation()
    const r = e.currentTarget.getBoundingClientRect()
    const despues = e.clientY > r.top + r.height / 2
    emit('mover', { item: arrastrando.value.item, seccion: grupo.nombre, referencia: item, despues })
    limpiar()
}

function sobreGrupo(e, grupo) {
    if (! arrastrando.value) return
    if (arrastrando.value.tipo === 'seccion' && grupo.sin) return
    e.preventDefault()
    sobre.value = { tipo: 'grupo', nombre: grupo.nombre }
}

function soltarGrupo(e, grupo) {
    if (! arrastrando.value) return
    e.preventDefault()

    if (arrastrando.value.tipo === 'item') {
        emit('mover', { item: arrastrando.value.item, seccion: grupo.nombre, referencia: null, despues: true })
    } else if (arrastrando.value.nombre !== grupo.nombre) {
        emit('mover-seccion', arrastrando.value.nombre, grupo.nombre)
    }

    limpiar()
}

const marcaFila = (item) => {
    const s = sobre.value
    if (s?.tipo !== 'fila' || s.clave !== uid(item)) return ''
    return s.despues ? 'shadow-[inset_0_-2px_0_0_var(--marca)]' : 'shadow-[inset_0_2px_0_0_var(--marca)]'
}
const marcaGrupo = (g) => sobre.value?.tipo === 'grupo' && sobre.value.nombre === g.nombre
    ? 'bg-[var(--marca-suave)]' : ''

// ─── Renombrar ───────────────────────────────────────────────────────────────
function renombrar(e, nombre) {
    emit('renombrar-seccion', nombre, e.target.value)
    // Si la pantalla lo rechazó (vacío o repetido) la sección conserva su nombre: se devuelve el texto.
    nextTick(() => { if (e.target.isConnected) e.target.value = nombre })
}

const estiloFila = computed(() => ({ '--cols': `1.25rem ${props.columnas} 2rem` }))
const baseFila   = 'grid-cols-[minmax(0,1fr)_2rem] md:[grid-template-columns:var(--cols)] items-center gap-x-2 gap-y-0.5 px-2 py-1.5'
</script>

<template>
    <div class="rounded-xl border border-linea overflow-hidden bg-superficie">
        <!-- Encabezado de la tabla (solo en pantallas medianas: en el celular cada fila se explica sola) -->
        <div :class="[baseFila, 'hidden md:grid bg-realce text-[11px] font-medium uppercase tracking-wide text-tinta-400 border-b border-linea']" :style="estiloFila">
            <span/>
            <slot name="cabecera"/>
            <span/>
        </div>

        <div v-for="g in grupos" :key="g.sin ? '__sin' : g.nombre" data-grupo
            v-show="g.filas.length || (haySecciones && (! g.sin || arrastrando?.tipo === 'item'))">

            <!-- Cabecera de sección: también es la zona donde se suelta para dejar al final -->
            <div v-if="haySecciones"
                :class="['flex items-center gap-1.5 px-2 py-1.5 border-b border-linea bg-realce', marcaGrupo(g)]"
                @dragover="sobreGrupo($event, g)" @dragleave="sobre = null" @drop="soltarGrupo($event, g)">
                <span v-if="! g.sin" draggable="true" title="Arrastrar la sección"
                    class="hidden md:flex cursor-grab text-tinta-300 hover:text-tinta-500 select-none"
                    @dragstart="empezarSeccion($event, g.nombre)" @dragend="limpiar">
                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><circle cx="9" cy="6" r="1.5"/><circle cx="15" cy="6" r="1.5"/><circle cx="9" cy="12" r="1.5"/><circle cx="15" cy="12" r="1.5"/><circle cx="9" cy="18" r="1.5"/><circle cx="15" cy="18" r="1.5"/></svg>
                </span>
                <button type="button" @click="alternarPliegue(g.nombre)" :aria-expanded="! plegadas.has(g.nombre)"
                    class="w-6 h-6 flex items-center justify-center rounded text-tinta-400 hover:bg-superficie" :title="plegadas.has(g.nombre) ? 'Desplegar' : 'Plegar'">
                    <svg :class="['w-4 h-4 transition-transform', plegadas.has(g.nombre) ? '-rotate-90' : '']" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                </button>

                <input v-if="! g.sin" :key="g.nombre" :value="g.nombre" type="text" maxlength="80" aria-label="Nombre de la sección"
                    class="min-w-[5rem] flex-1 bg-transparent border border-transparent hover:border-linea focus:border-linea focus:bg-superficie rounded-md px-1.5 py-0.5 text-sm font-semibold text-tinta-800 focus:outline-none"
                    @change="renombrar($event, g.nombre)" @keydown.enter.prevent="$event.target.blur()">
                <span v-else class="min-w-0 flex-1 truncate text-sm font-semibold text-tinta-500">
                    Sin sección <span class="font-normal text-[11px] text-tinta-300">(se imprime como «Otros»)</span>
                </span>

                <span class="hidden sm:inline text-[11px] text-tinta-400 shrink-0">{{ g.filas.length }} {{ g.filas.length === 1 ? 'ítem' : 'ítems' }}</span>
                <span class="text-sm font-semibold shrink-0 text-right sm:min-w-[6rem]" style="color:var(--marca)">
                    <slot name="total-seccion" :nombre="g.nombre" :filas="g.filas"/>
                </span>
                <button type="button" @click="emit('agregar', g.nombre)" title="Agregar un ítem a esta sección"
                    class="h-6 px-2 rounded-md text-xs font-medium text-tinta-500 hover:bg-superficie hover:text-[var(--marca)] shrink-0">+ Ítem</button>
                <button v-if="! g.sin" type="button" @click="emit('eliminar-seccion', g.nombre)" title="Quitar la sección (sus ítems pasan a «Sin sección»)"
                    class="w-6 h-6 flex items-center justify-center rounded-md text-tinta-300 hover:text-aviso-rojo hover:bg-pastel-rojo shrink-0">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                </button>
            </div>

            <template v-if="! haySecciones || ! plegadas.has(g.nombre)">
                <!-- Sección vacía: una zona donde soltar -->
                <div v-if="haySecciones && ! g.filas.length && ! g.sin"
                    :class="['px-3 py-3 text-center text-xs text-tinta-300 border-b border-separador', marcaGrupo(g)]"
                    @dragover="sobreGrupo($event, g)" @dragleave="sobre = null" @drop="soltarGrupo($event, g)">
                    Arrastra ítems aquí o usa «+ Ítem».
                </div>

                <div v-for="f in g.filas" :key="uid(f.item)" data-fila class="border-b border-separador last:border-b-0">
                    <div :class="[baseFila, 'grid cursor-pointer transition-colors hover:bg-realce', abierta(f.item) ? 'bg-realce' : '', marcaFila(f.item)]" :style="estiloFila"
                        @click="clicFila($event, f.item)"
                        @dragover="sobreFila($event, f.item)" @dragleave="sobre = null" @drop="soltarFila($event, f.item, g)">
                        <span draggable="true" title="Arrastrar para mover" data-no-alternar
                            class="hidden md:flex cursor-grab text-tinta-300 hover:text-tinta-500 select-none"
                            @dragstart="empezarItem($event, f.item)" @dragend="limpiar">
                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><circle cx="9" cy="6" r="1.5"/><circle cx="15" cy="6" r="1.5"/><circle cx="9" cy="12" r="1.5"/><circle cx="15" cy="12" r="1.5"/><circle cx="9" cy="18" r="1.5"/><circle cx="15" cy="18" r="1.5"/></svg>
                        </span>

                        <!-- En el celular las celdas se apilan; desde md se vuelven columnas de la tabla -->
                        <div class="min-w-0 md:contents">
                            <slot name="fila" :item="f.item" :idx="f.idx" :abierta="abierta(f.item)"/>
                        </div>

                        <button type="button" @click.stop="alternar(f.item)" :aria-expanded="abierta(f.item)" :title="abierta(f.item) ? 'Plegar' : 'Ver detalle'"
                            class="w-7 h-7 flex items-center justify-center rounded-md text-tinta-400 hover:bg-superficie">
                            <svg :class="['w-4 h-4 transition-transform', abierta(f.item) ? 'rotate-180' : '']" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                        </button>
                    </div>

                    <div v-if="abierta(f.item)" class="px-3 pt-2 pb-3 border-t border-separador">
                        <slot name="detalle" :item="f.item" :idx="f.idx"/>
                    </div>
                </div>
            </template>
        </div>

        <p v-if="! items.length && ! haySecciones" class="px-3 py-6 text-center text-sm text-tinta-300">Sin ítems. Agrega al menos uno.</p>
    </div>
</template>
