<script setup>
import { useEditor, EditorContent } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import Underline from '@tiptap/extension-underline'
import TextAlign from '@tiptap/extension-text-align'
import Link from '@tiptap/extension-link'
import { ref, watch, onBeforeUnmount } from 'vue'

const props = defineProps({
    modelValue:  { type: String, default: '' },
    placeholder: { type: String, default: 'Escribe aquí...' },
    minHeight:   { type: String, default: '120px' },
    maxLength:   { type: Number, default: null },
})
const emit = defineEmits(['update:modelValue'])

const charCount = ref(0)

const editor = useEditor({
    content: props.modelValue || '',
    extensions: [
        StarterKit,
        Underline,
        TextAlign.configure({ types: ['heading', 'paragraph'] }),
        Link.configure({ openOnClick: false }),
    ],
    editorProps: {
        attributes: {
            class: 'prose prose-sm max-w-none focus:outline-none',
        },
    },
    onCreate: ({ editor }) => {
        charCount.value = editor.getText().length
    },
    onUpdate: ({ editor }) => {
        charCount.value = editor.getText().length
        emit('update:modelValue', editor.getHTML())
    },
})

watch(() => props.modelValue, (val) => {
    if (editor.value && editor.value.getHTML() !== val) {
        editor.value.commands.setContent(val || '', false)
        charCount.value = editor.value.getText().length
    }
})

onBeforeUnmount(() => {
    editor.value?.destroy()
})

function toggleLink() {
    if (!editor.value) return
    if (editor.value.isActive('link')) {
        editor.value.chain().focus().unsetLink().run()
        return
    }
    const prev = editor.value.getAttributes('link').href
    const url = prompt('URL del enlace:', prev ?? 'https://')
    if (url) {
        editor.value.chain().focus().setLink({ href: url }).run()
    }
}
</script>

<template>
    <div class="border border-tinta-200 rounded-xl overflow-hidden focus-within:ring-2 focus-within:ring-blue-400 focus-within:border-blue-400 transition-colors bg-white">
        <!-- Toolbar -->
        <div class="flex flex-wrap gap-0.5 p-1.5 border-b border-linea bg-tinta-50">

            <!-- Bold -->
            <button type="button"
                @mousedown.prevent="editor?.chain().focus().toggleBold().run()"
                :class="['p-1.5 rounded hover:bg-tinta-200 transition-colors', editor?.isActive('bold') ? 'bg-[var(--marca)] text-white' : 'text-tinta-500']"
                title="Negrita">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M15.6 11.79c.97-.67 1.65-1.77 1.65-2.79 0-2.26-1.75-4-4-4H7v14h7.04c2.09 0 3.71-1.7 3.71-3.79 0-1.52-.86-2.82-2.15-3.42zM10 6.5h3c.83 0 1.5.67 1.5 1.5s-.67 1.5-1.5 1.5h-3v-3zm3.5 9H10v-3h3.5c.83 0 1.5.67 1.5 1.5s-.67 1.5-1.5 1.5z"/>
                </svg>
            </button>

            <!-- Italic -->
            <button type="button"
                @mousedown.prevent="editor?.chain().focus().toggleItalic().run()"
                :class="['p-1.5 rounded hover:bg-tinta-200 transition-colors', editor?.isActive('italic') ? 'bg-[var(--marca)] text-white' : 'text-tinta-500']"
                title="Cursiva">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M10 4v3h2.21l-3.42 8H6v3h8v-3h-2.21l3.42-8H18V4h-8z"/>
                </svg>
            </button>

            <!-- Underline -->
            <button type="button"
                @mousedown.prevent="editor?.chain().focus().toggleUnderline().run()"
                :class="['p-1.5 rounded hover:bg-tinta-200 transition-colors', editor?.isActive('underline') ? 'bg-[var(--marca)] text-white' : 'text-tinta-500']"
                title="Subrayado">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 17c3.31 0 6-2.69 6-6V3h-2.5v8c0 1.93-1.57 3.5-3.5 3.5S8.5 12.93 8.5 11V3H6v8c0 3.31 2.69 6 6 6zm-7 2v2h14v-2H5z"/>
                </svg>
            </button>

            <div class="w-px bg-gray-300 mx-0.5 my-1"/>

            <!-- Bullet list -->
            <button type="button"
                @mousedown.prevent="editor?.chain().focus().toggleBulletList().run()"
                :class="['p-1.5 rounded hover:bg-tinta-200 transition-colors', editor?.isActive('bulletList') ? 'bg-[var(--marca)] text-white' : 'text-tinta-500']"
                title="Lista con viñetas">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M4 10.5c-.83 0-1.5.67-1.5 1.5s.67 1.5 1.5 1.5 1.5-.67 1.5-1.5-.67-1.5-1.5-1.5zm0-6c-.83 0-1.5.67-1.5 1.5S3.17 7.5 4 7.5 5.5 6.83 5.5 6 4.83 4.5 4 4.5zm0 12c-.83 0-1.5.68-1.5 1.5s.68 1.5 1.5 1.5 1.5-.68 1.5-1.5-.67-1.5-1.5-1.5zM7 19h14v-2H7v2zm0-6h14v-2H7v2zm0-8v2h14V5H7z"/>
                </svg>
            </button>

            <!-- Ordered list -->
            <button type="button"
                @mousedown.prevent="editor?.chain().focus().toggleOrderedList().run()"
                :class="['p-1.5 rounded hover:bg-tinta-200 transition-colors', editor?.isActive('orderedList') ? 'bg-[var(--marca)] text-white' : 'text-tinta-500']"
                title="Lista numerada">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M2 17h2v.5H3v1h1v.5H2v1h3v-4H2v1zm1-9h1V4H2v1h1v3zm-1 3h1.8L2 13.1v.9h3v-1H3.2L5 10.9V10H2v1zm5-8v2h14V3H7zm0 18h14v-2H7v2zm0-8h14v-2H7v2z"/>
                </svg>
            </button>

            <div class="w-px bg-gray-300 mx-0.5 my-1"/>

            <!-- Align left -->
            <button type="button"
                @mousedown.prevent="editor?.chain().focus().setTextAlign('left').run()"
                :class="['p-1.5 rounded hover:bg-tinta-200 transition-colors', editor?.isActive({ textAlign: 'left' }) ? 'bg-[var(--marca)] text-white' : 'text-tinta-500']"
                title="Alinear izquierda">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M15 15H3v2h12v-2zm0-8H3v2h12V7zM3 13h18v-2H3v2zm0 8h18v-2H3v2zM3 3v2h18V3H3z"/>
                </svg>
            </button>

            <!-- Align center -->
            <button type="button"
                @mousedown.prevent="editor?.chain().focus().setTextAlign('center').run()"
                :class="['p-1.5 rounded hover:bg-tinta-200 transition-colors', editor?.isActive({ textAlign: 'center' }) ? 'bg-[var(--marca)] text-white' : 'text-tinta-500']"
                title="Centrar">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M7 15v2h10v-2H7zm-4 6h18v-2H3v2zm0-8h18v-2H3v2zm4-6v2h10V7H7zM3 3v2h18V3H3z"/>
                </svg>
            </button>

            <!-- Align right -->
            <button type="button"
                @mousedown.prevent="editor?.chain().focus().setTextAlign('right').run()"
                :class="['p-1.5 rounded hover:bg-tinta-200 transition-colors', editor?.isActive({ textAlign: 'right' }) ? 'bg-[var(--marca)] text-white' : 'text-tinta-500']"
                title="Alinear derecha">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M3 21h18v-2H3v2zm6-4h12v-2H9v2zm-6-4h18v-2H3v2zm6-4h12V7H9v2zM3 3v2h18V3H3z"/>
                </svg>
            </button>

            <div class="w-px bg-gray-300 mx-0.5 my-1"/>

            <!-- Link -->
            <button type="button"
                @mousedown.prevent="toggleLink"
                :class="['p-1.5 rounded hover:bg-tinta-200 transition-colors', editor?.isActive('link') ? 'bg-[var(--marca)] text-white' : 'text-tinta-500']"
                title="Enlace">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M3.9 12c0-1.71 1.39-3.1 3.1-3.1h4V7H7c-2.76 0-5 2.24-5 5s2.24 5 5 5h4v-1.9H7c-1.71 0-3.1-1.39-3.1-3.1zM8 13h8v-2H8v2zm9-6h-4v1.9h4c1.71 0 3.1 1.39 3.1 3.1s-1.39 3.1-3.1 3.1h-4V17h4c2.76 0 5-2.24 5-5s-2.24-5-5-5z"/>
                </svg>
            </button>

            <!-- Clear format -->
            <button type="button"
                @mousedown.prevent="editor?.chain().focus().clearNodes().unsetAllMarks().run()"
                class="p-1.5 rounded hover:bg-tinta-200 transition-colors text-tinta-400"
                title="Limpiar formato">
                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M3.27 5L2 6.27l6.97 6.97L6.5 19h3l1.57-3.66L16.73 21 18 19.73 3.27 5zM6 5v.18L8.82 8h2.4l-.72 1.68 2.1 2.1L14.21 8H20V5H6z"/>
                </svg>
            </button>
        </div>

        <!-- Content area -->
        <EditorContent
            :editor="editor"
            :style="`min-height:${minHeight};`"
            class="px-3 py-2 text-sm text-tinta-900 [&_.ProseMirror]:outline-none [&_.ProseMirror]:min-h-[inherit]"
        />
        <!-- Counter (only when maxLength is set) -->
        <div v-if="maxLength" class="px-3 py-1 border-t border-linea flex justify-end bg-white">
            <span class="text-xs tabular-nums"
                :class="charCount > maxLength ? 'text-red-500 font-semibold' : charCount > maxLength * 0.9 ? 'text-amber-500 font-semibold' : 'text-tinta-300'">
                {{ charCount.toLocaleString('es-CO') }}/{{ maxLength.toLocaleString('es-CO') }}
            </span>
        </div>
    </div>
</template>

<style>
.ProseMirror ul { list-style-type: disc; padding-left: 1.5rem; margin-bottom: 0.5rem; }
.ProseMirror ol { list-style-type: decimal; padding-left: 1.5rem; margin-bottom: 0.5rem; }
.ProseMirror li p { margin-bottom: 0; }
.ProseMirror strong { font-weight: 700; }
.ProseMirror em { font-style: italic; }
.ProseMirror u { text-decoration: underline; }
.ProseMirror p { margin-bottom: 0.5rem; }
.ProseMirror p:last-child { margin-bottom: 0; }
.ProseMirror:focus { outline: none; }
.ProseMirror a { color: var(--marca); text-decoration: underline; }
</style>
