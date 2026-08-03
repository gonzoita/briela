<script setup>
// Editor de texto enriquecido liviano (negrita, cursiva, viñetas, lista
// numerada) sin librerías externas — usa contenteditable + execCommand.
// Guarda/lee HTML plano en el v-model. Pensado para descripciones cortas de
// pasos de producción, no para contenido largo.
//
// execCommand solo actúa sobre la selección de texto activa en ese momento.
// Al hacer click en un botón de la barra, el navegador puede mover el foco
// (y con eso perder la selección) antes de que el comando se ejecute. Por
// eso guardamos manualmente el rango seleccionado (saveSelection) cada vez
// que el usuario selecciona texto o mueve el cursor, y lo restauramos justo
// antes de aplicar el comando (restoreSelection) en vez de confiar en que
// el navegador la mantenga sola.
import { ref, watch, onMounted } from 'vue'

const props = defineProps({
    modelValue:  { type: String, default: '' },
    placeholder: { type: String, default: 'Escribe aquí…' },
})
const emit = defineEmits(['update:modelValue'])

const el = ref(null)
let savedRange = null

onMounted(() => {
    if (el.value) el.value.innerHTML = props.modelValue || ''
})

watch(() => props.modelValue, (val) => {
    if (el.value && el.value.innerHTML !== (val || '') && document.activeElement !== el.value) {
        el.value.innerHTML = val || ''
    }
})

function onInput() {
    emit('update:modelValue', el.value.innerHTML)
}

function saveSelection() {
    const sel = window.getSelection()
    if (sel && sel.rangeCount > 0 && el.value && el.value.contains(sel.anchorNode)) {
        savedRange = sel.getRangeAt(0).cloneRange()
    }
}

function restoreSelection() {
    const sel = window.getSelection()
    sel.removeAllRanges()
    if (savedRange) {
        sel.addRange(savedRange)
    } else {
        // Sin selección previa: ubica el cursor al final del texto.
        const range = document.createRange()
        range.selectNodeContents(el.value)
        range.collapse(false)
        sel.addRange(range)
    }
}

function cmd(command) {
    el.value.focus()
    restoreSelection()
    document.execCommand(command)
    saveSelection()
    onInput()
}

// document.execCommand('insertUnorderedList'/'insertOrderedList') es la parte
// más frágil/inconsistente de execCommand entre navegadores — a veces
// simplemente no hace nada, aunque bold/italic sí funcionen con el mismo
// mecanismo de selección. Por eso las listas se arman a mano manipulando el
// DOM directamente, sin depender de ese comando.
function lineaDe(nodo) {
    while (nodo && nodo.parentNode !== el.value) nodo = nodo.parentNode
    return nodo
}

function toggleLista(ordenada) {
    el.value.focus()
    restoreSelection()

    const sel = window.getSelection()
    if (!sel || !sel.rangeCount) return
    const range = sel.getRangeAt(0)
    const tag = ordenada ? 'ol' : 'ul'

    let inicio = lineaDe(range.startContainer)
    let fin    = lineaDe(range.endContainer)
    if (!inicio) inicio = fin = el.value.firstChild

    if (!inicio) {
        // Editor vacío: crear la lista con un ítem para empezar a escribir.
        const lista = document.createElement(tag)
        lista.appendChild(document.createElement('li'))
        el.value.appendChild(lista)
        saveSelection()
        onInput()
        return
    }
    if (!fin) fin = inicio

    // Selección dentro de una sola lista del mismo tipo → se desarma (toggle).
    if (inicio === fin && inicio.nodeName?.toLowerCase() === tag) {
        const frag = document.createDocumentFragment()
        Array.from(inicio.children).forEach(li => {
            const div = document.createElement('div')
            div.innerHTML = li.innerHTML || '<br>'
            frag.appendChild(div)
        })
        inicio.replaceWith(frag)
        saveSelection()
        onInput()
        return
    }

    // Recolectar las líneas (nodos hijos directos del editor) entre inicio y fin.
    const lineas = []
    let cursor = inicio
    while (cursor) {
        if (cursor.nodeName !== 'BR') lineas.push(cursor)
        if (cursor === fin) break
        cursor = cursor.nextSibling
    }
    if (!lineas.length) return

    const lista = document.createElement(tag)
    lineas.forEach(nodo => {
        const li = document.createElement('li')
        li.innerHTML = nodo.nodeType === Node.TEXT_NODE
            ? (nodo.textContent || '')
            : (nodo.innerHTML || nodo.textContent || '')
        lista.appendChild(li)
    })
    lineas[0].replaceWith(lista)
    lineas.slice(1).forEach(n => n.remove())

    saveSelection()
    onInput()
}
</script>

<template>
    <div class="border border-gray-200 rounded-lg overflow-hidden bg-white">
        <div class="flex items-center gap-1 border-b border-gray-100 bg-gray-50 px-2 py-1">
            <button type="button" @mousedown.prevent="cmd('bold')"
                class="w-7 h-7 rounded text-sm font-bold text-gray-600 hover:bg-gray-200">B</button>
            <button type="button" @mousedown.prevent="cmd('italic')"
                class="w-7 h-7 rounded text-sm italic text-gray-600 hover:bg-gray-200">I</button>
            <span class="w-px h-4 bg-gray-200 mx-1"></span>
            <button type="button" @mousedown.prevent="toggleLista(false)"
                class="w-7 h-7 rounded text-sm text-gray-600 hover:bg-gray-200">•</button>
            <button type="button" @mousedown.prevent="toggleLista(true)"
                class="w-7 h-7 rounded text-xs text-gray-600 hover:bg-gray-200">1.</button>
        </div>
        <div ref="el" contenteditable="true" @input="onInput" @mouseup="saveSelection" @keyup="saveSelection"
            class="rte-content w-full min-h-[70px] px-3 py-2 text-sm focus:outline-none"
            :data-placeholder="placeholder"></div>
    </div>
</template>

<style scoped>
/* execCommand inserta <ul>/<ol>/<b>/<i> directo en el DOM, sin pasar por el
   compilador de Vue — esos nodos nunca reciben el atributo data-v-xxx que
   usa el scoping normal, así que un selector "scoped" común (.rte-content ul)
   nunca los matchea y Tailwind preflight (que resetea list-style a none) se
   queda ganando. :deep() saca el atributo scoped de esa parte del selector. */
.rte-content:empty:before { content: attr(data-placeholder); color: #9CA3AF; }
.rte-content :deep(ul) { list-style: disc; padding-left: 1.25rem; }
.rte-content :deep(ol) { list-style: decimal; padding-left: 1.25rem; }
</style>
