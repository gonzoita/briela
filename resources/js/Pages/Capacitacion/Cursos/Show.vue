<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import EditorTexto from '@/Components/EditorTexto.vue'
import { router } from '@inertiajs/vue3'
import { ref, reactive, computed } from 'vue'

const props = defineProps({
    curso: Object,
})

const curso = reactive(JSON.parse(JSON.stringify(props.curso)))

function inicializarEvalCfg(modulo) {
    return {
        id: modulo.evaluacion?.id ?? null,
        nombre: modulo.evaluacion?.nombre ?? `Evaluación de ${modulo.nombre}`,
        nota_minima_aprobacion: modulo.evaluacion?.nota_minima_aprobacion ?? 70,
        intentos_permitidos: modulo.evaluacion?.intentos_permitidos ?? '',
        requiere_revision_manual: modulo.evaluacion?.requiere_revision_manual ?? false,
        preguntas: modulo.evaluacion?.preguntas ?? [],
    }
}

curso.modulos.forEach(m => {
    m.evalCfg = inicializarEvalCfg(m)
    m.guardandoEvaluacion = false
    m.errorEvaluacion = ''
})

const csrf    = () => { const c = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN=')); return c ? decodeURIComponent(c.split('=')[1]) : '' }
const jsonHdr = () => ({ 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' })
const formHdr = () => ({ Accept: 'application/json', 'X-XSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' })

const publicoLabel = (v) => ({ colaborador: 'Colaboradores', contratista: 'Contratistas', cliente: 'Clientes', todos: 'Todos' }[v] ?? v)
const tipoLabel    = (v) => ({ video_drive: 'Video (Drive)', video_externo: 'Video externo', texto: 'Texto', pdf: 'PDF' }[v] ?? v)

// ── Módulos: expandir/colapsar ────────────────────────────────────────────────
const expandidos = ref(new Set(curso.modulos.map(m => m.id)))
const toggleExpandido = (id) => {
    const set = new Set(expandidos.value)
    set.has(id) ? set.delete(id) : set.add(id)
    expandidos.value = set
}

// ── CRUD Módulos ──────────────────────────────────────────────────────────────
const nuevoModuloNombre = ref('')
const creandoModulo     = ref(false)
const errorModulo       = ref('')

async function crearModulo() {
    if (!nuevoModuloNombre.value.trim()) return
    creandoModulo.value = true
    errorModulo.value   = ''
    try {
        const res = await fetch(`/capacitacion/cursos/${curso.id}/modulos`, {
            method: 'POST', headers: jsonHdr(),
            body: JSON.stringify({ nombre: nuevoModuloNombre.value.trim() }),
        })
        if (!res.ok) throw new Error()
        const data = await res.json()
        const nuevoModulo = { ...data, lecciones: [], guardandoEvaluacion: false, errorEvaluacion: '' }
        nuevoModulo.evalCfg = inicializarEvalCfg(nuevoModulo)
        curso.modulos.push(nuevoModulo)
        expandidos.value.add(data.id)
        nuevoModuloNombre.value = ''
    } catch { errorModulo.value = 'Error al crear el módulo.' }
    finally { creandoModulo.value = false }
}

async function eliminarModulo(modulo) {
    if (!confirm(`¿Eliminar el módulo "${modulo.nombre}" y todas sus lecciones?`)) return
    try {
        await fetch(`/capacitacion/cursos/${curso.id}/modulos/${modulo.id}`, { method: 'DELETE', headers: jsonHdr() })
        curso.modulos = curso.modulos.filter(m => m.id !== modulo.id)
    } catch {}
}

async function moverModulo(modulo, direccion) {
    const idx = curso.modulos.findIndex(m => m.id === modulo.id)
    const nuevoIdx = idx + direccion
    if (nuevoIdx < 0 || nuevoIdx >= curso.modulos.length) return
    const arr = [...curso.modulos]
    ;[arr[idx], arr[nuevoIdx]] = [arr[nuevoIdx], arr[idx]]
    curso.modulos = arr
    try {
        await fetch(`/capacitacion/cursos/${curso.id}/modulos/reordenar`, {
            method: 'POST', headers: jsonHdr(),
            body: JSON.stringify({ ids: arr.map(m => m.id) }),
        })
    } catch {}
}

// ── CRUD Lecciones ────────────────────────────────────────────────────────────
const modal = reactive({
    abierto:  false,
    modulo:   null,
    editando: null,
    guardando: false,
    error:    '',
    form: {
        nombre:            '',
        tipo:              'texto',
        contenido_url:     '',
        contenido_texto:   '',
        archivo_pdf:       null,
        archivo_pdf_nombre: '',
        duracion_minutos:  '',
    },
})

function abrirNuevaLeccion(modulo) {
    modal.abierto  = true
    modal.modulo   = modulo
    modal.editando = null
    modal.error    = ''
    modal.form = { nombre: '', tipo: 'texto', contenido_url: '', contenido_texto: '', archivo_pdf: null, archivo_pdf_nombre: '', duracion_minutos: '' }
}

function abrirEditarLeccion(modulo, leccion) {
    modal.abierto  = true
    modal.modulo   = modulo
    modal.editando = leccion.id
    modal.error    = ''
    modal.form = {
        nombre:            leccion.nombre,
        tipo:              leccion.tipo,
        contenido_url:     ['video_drive', 'video_externo'].includes(leccion.tipo) ? leccion.contenido : '',
        contenido_texto:   leccion.tipo === 'texto' ? leccion.contenido : '',
        archivo_pdf:       null,
        archivo_pdf_nombre: leccion.tipo === 'pdf' ? leccion.contenido : '',
        duracion_minutos:  leccion.duracion_minutos ?? '',
    }
}

function cerrarModal() {
    modal.abierto = false
}

function onArchivoPdf(e) {
    const file = e.target.files?.[0]
    modal.form.archivo_pdf = file ?? null
    modal.form.archivo_pdf_nombre = file?.name ?? modal.form.archivo_pdf_nombre
}

async function guardarLeccion() {
    if (!modal.form.nombre.trim()) { modal.error = 'El nombre es requerido.'; return }
    modal.guardando = true
    modal.error     = ''

    const fd = new FormData()
    fd.append('nombre', modal.form.nombre.trim())
    fd.append('tipo', modal.form.tipo)
    if (modal.form.duracion_minutos) fd.append('duracion_minutos', modal.form.duracion_minutos)
    if (['video_drive', 'video_externo'].includes(modal.form.tipo)) fd.append('contenido_url', modal.form.contenido_url)
    if (modal.form.tipo === 'texto') fd.append('contenido_texto', modal.form.contenido_texto)
    if (modal.form.tipo === 'pdf' && modal.form.archivo_pdf) fd.append('archivo_pdf', modal.form.archivo_pdf)

    const editando = modal.editando
    const modulo    = modal.modulo
    const url = editando
        ? `/capacitacion/modulos/${modulo.id}/lecciones/${editando}`
        : `/capacitacion/modulos/${modulo.id}/lecciones`
    if (editando) fd.append('_method', 'PUT')

    try {
        const res = await fetch(url, { method: 'POST', headers: formHdr(), body: fd })
        if (!res.ok) throw new Error()
        const data = await res.json()
        const moduloLocal = curso.modulos.find(m => m.id === modulo.id)
        if (editando) {
            const i = moduloLocal.lecciones.findIndex(l => l.id === editando)
            if (i !== -1) moduloLocal.lecciones[i] = data
        } else {
            moduloLocal.lecciones.push(data)
        }
        modal.abierto = false
    } catch { modal.error = 'Error al guardar la lección.' }
    finally { modal.guardando = false }
}

async function eliminarLeccion(modulo, leccion) {
    if (!confirm(`¿Eliminar la lección "${leccion.nombre}"?`)) return
    try {
        await fetch(`/capacitacion/modulos/${modulo.id}/lecciones/${leccion.id}`, { method: 'DELETE', headers: jsonHdr() })
        modulo.lecciones = modulo.lecciones.filter(l => l.id !== leccion.id)
    } catch {}
}

async function moverLeccion(modulo, leccion, direccion) {
    const idx = modulo.lecciones.findIndex(l => l.id === leccion.id)
    const nuevoIdx = idx + direccion
    if (nuevoIdx < 0 || nuevoIdx >= modulo.lecciones.length) return
    const arr = [...modulo.lecciones]
    ;[arr[idx], arr[nuevoIdx]] = [arr[nuevoIdx], arr[idx]]
    modulo.lecciones = arr
    try {
        await fetch(`/capacitacion/modulos/${modulo.id}/lecciones/reordenar`, {
            method: 'POST', headers: jsonHdr(),
            body: JSON.stringify({ ids: arr.map(l => l.id) }),
        })
    } catch {}
}

// ── Evaluación ────────────────────────────────────────────────────────────────
const evaluacion = reactive({
    id: curso.evaluacion?.id ?? null,
    nombre: curso.evaluacion?.nombre ?? 'Evaluación final',
    nota_minima_aprobacion: curso.evaluacion?.nota_minima_aprobacion ?? 70,
    intentos_permitidos: curso.evaluacion?.intentos_permitidos ?? '',
    requiere_revision_manual: curso.evaluacion?.requiere_revision_manual ?? false,
    preguntas: curso.evaluacion?.preguntas ?? [],
})
const guardandoEvaluacion = ref(false)
const errorEvaluacion     = ref('')

async function guardarEvaluacion() {
    guardandoEvaluacion.value = true
    errorEvaluacion.value     = ''
    try {
        const res = await fetch(`/capacitacion/cursos/${curso.id}/evaluacion`, {
            method: 'POST', headers: jsonHdr(),
            body: JSON.stringify({
                nombre: evaluacion.nombre,
                nota_minima_aprobacion: Number(evaluacion.nota_minima_aprobacion),
                intentos_permitidos: evaluacion.intentos_permitidos ? Number(evaluacion.intentos_permitidos) : null,
                requiere_revision_manual: !!evaluacion.requiere_revision_manual,
            }),
        })
        if (!res.ok) throw new Error()
        const data = await res.json()
        evaluacion.id = data.id
    } catch { errorEvaluacion.value = 'Error al guardar la evaluación.' }
    finally { guardandoEvaluacion.value = false }
}

// ── Evaluación de módulo (candado de avance, reutiliza el mismo formulario) ──
async function guardarEvaluacionModulo(modulo) {
    modulo.guardandoEvaluacion = true
    modulo.errorEvaluacion     = ''
    try {
        const res = await fetch(`/capacitacion/cursos/${curso.id}/modulos/${modulo.id}/evaluacion`, {
            method: 'POST', headers: jsonHdr(),
            body: JSON.stringify({
                nombre: modulo.evalCfg.nombre,
                nota_minima_aprobacion: Number(modulo.evalCfg.nota_minima_aprobacion),
                intentos_permitidos: modulo.evalCfg.intentos_permitidos ? Number(modulo.evalCfg.intentos_permitidos) : null,
                requiere_revision_manual: !!modulo.evalCfg.requiere_revision_manual,
            }),
        })
        if (!res.ok) throw new Error()
        const data = await res.json()
        modulo.evalCfg.id = data.id
    } catch { modulo.errorEvaluacion = 'Error al guardar la evaluación.' }
    finally { modulo.guardandoEvaluacion = false }
}

const modalPregunta = reactive({
    abierto:   false,
    target:    null,
    editando:  null,
    guardando: false,
    error:     '',
    form: { enunciado: '', tipo: 'opcion_multiple', opciones: [{ texto: '', es_correcta: false }, { texto: '', es_correcta: false }] },
})

function abrirNuevaPregunta(target = evaluacion) {
    modalPregunta.abierto  = true
    modalPregunta.target   = target
    modalPregunta.editando = null
    modalPregunta.error    = ''
    modalPregunta.form = { enunciado: '', tipo: 'opcion_multiple', opciones: [{ texto: '', es_correcta: false }, { texto: '', es_correcta: false }] }
}

function abrirEditarPregunta(pregunta, target = evaluacion) {
    modalPregunta.abierto  = true
    modalPregunta.target   = target
    modalPregunta.editando = pregunta.id
    modalPregunta.error    = ''
    modalPregunta.form = {
        enunciado: pregunta.enunciado,
        tipo:      pregunta.tipo,
        opciones:  pregunta.tipo === 'opcion_multiple'
            ? pregunta.opciones.map(o => ({ texto: o.texto, es_correcta: o.es_correcta }))
            : [{ texto: '', es_correcta: false }, { texto: '', es_correcta: false }],
    }
}

function cerrarModalPregunta() { modalPregunta.abierto = false }

function agregarOpcion() {
    modalPregunta.form.opciones.push({ texto: '', es_correcta: false })
}

function quitarOpcion(idx) {
    if (modalPregunta.form.opciones.length <= 2) return
    modalPregunta.form.opciones.splice(idx, 1)
}

function marcarCorrecta(idx) {
    modalPregunta.form.opciones.forEach((o, i) => { o.es_correcta = i === idx })
}

async function guardarPregunta() {
    if (!modalPregunta.form.enunciado.trim()) { modalPregunta.error = 'El enunciado es requerido.'; return }

    if (modalPregunta.form.tipo === 'opcion_multiple') {
        const validas = modalPregunta.form.opciones.filter(o => o.texto.trim())
        if (validas.length < 2) { modalPregunta.error = 'Agrega al menos 2 opciones.'; return }
        if (!validas.some(o => o.es_correcta)) { modalPregunta.error = 'Marca la opción correcta.'; return }
    }

    modalPregunta.guardando = true
    modalPregunta.error     = ''

    const editando = modalPregunta.editando
    const target   = modalPregunta.target
    const url = editando
        ? `/capacitacion/evaluaciones/${target.id}/preguntas/${editando}`
        : `/capacitacion/evaluaciones/${target.id}/preguntas`

    const body = {
        enunciado: modalPregunta.form.enunciado.trim(),
        tipo:      modalPregunta.form.tipo,
        opciones:  modalPregunta.form.tipo === 'opcion_multiple' ? modalPregunta.form.opciones.filter(o => o.texto.trim()) : undefined,
    }

    try {
        const res = await fetch(url, {
            method: editando ? 'PUT' : 'POST', headers: jsonHdr(),
            body: JSON.stringify(body),
        })
        if (!res.ok) throw new Error()
        const data = await res.json()
        if (editando) {
            const i = target.preguntas.findIndex(p => p.id === editando)
            if (i !== -1) target.preguntas[i] = data
        } else {
            target.preguntas.push(data)
        }
        modalPregunta.abierto = false
    } catch { modalPregunta.error = 'Error al guardar la pregunta.' }
    finally { modalPregunta.guardando = false }
}

async function eliminarPregunta(pregunta, target = evaluacion) {
    if (!confirm('¿Eliminar esta pregunta?')) return
    try {
        await fetch(`/capacitacion/evaluaciones/${target.id}/preguntas/${pregunta.id}`, { method: 'DELETE', headers: jsonHdr() })
        target.preguntas = target.preguntas.filter(p => p.id !== pregunta.id)
    } catch {}
}
</script>

<template>
    <AppLayout title="Curso">
        <div class="max-w-2xl mx-auto">

            <div class="flex items-center gap-3 mb-5">
                <button @click="router.visit('/capacitacion/cursos')" class="w-9 h-9 rounded-xl flex items-center justify-center text-tinta-400 hover:bg-tinta-100 transition-colors shrink-0">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                    </svg>
                </button>
                <h1 class="text-xl font-semibold text-tinta-900 truncate flex-1">{{ curso.titulo }}</h1>
                <button @click="router.visit(`/capacitacion/cursos/${curso.id}/editar`)"
                    class="w-9 h-9 rounded-xl flex items-center justify-center text-tinta-300 hover:text-indigo-600 hover:bg-indigo-50 transition-colors shrink-0">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
                    </svg>
                </button>
            </div>

            <!-- Info curso -->
            <div class="bg-white rounded-2xl shadow-sm overflow-hidden mb-4">
                <div class="aspect-video overflow-hidden" style="background:#F1F5F9;" v-if="curso.imagen_portada">
                    <img :src="curso.imagen_portada" :alt="curso.titulo" class="w-full h-full object-cover"/>
                </div>
                <div class="p-4">
                    <p v-if="curso.descripcion" class="text-sm text-tinta-500 mb-3">{{ curso.descripcion }}</p>
                    <div class="flex items-center gap-1.5 flex-wrap">
                        <span v-if="curso.categoria" class="inline-block px-2 py-0.5 rounded-full text-xs font-semibold text-white" style="background:#64748B;">{{ curso.categoria }}</span>
                        <span class="inline-block px-2 py-0.5 rounded-full text-xs font-semibold text-white" style="background:var(--marca);">{{ publicoLabel(curso.publico_objetivo) }}</span>
                        <span v-if="curso.obligatorio" class="inline-block px-2 py-0.5 rounded-full text-xs font-semibold text-white" style="background:#B91C1C;">Obligatorio</span>
                        <span class="inline-block px-2 py-0.5 rounded-full text-xs font-semibold" :style="curso.activo ? 'background:#D1FAE5;color:#065F46;' : 'background:#F3F4F6;color:#6B7280;'">{{ curso.activo ? 'Activo' : 'Inactivo' }}</span>
                    </div>
                </div>
            </div>

            <!-- Módulos -->
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-sm font-semibold text-tinta-400 uppercase tracking-[0.12em]">Módulos</h2>
            </div>

            <div class="space-y-3 mb-4">
                <div v-for="(modulo, mIdx) in curso.modulos" :key="modulo.id" class="bg-white rounded-2xl shadow-sm overflow-hidden">
                    <div class="flex items-center gap-2 px-4 py-3 cursor-pointer" @click="toggleExpandido(modulo.id)">
                        <svg class="w-4 h-4 text-tinta-300 shrink-0 transition-transform" :class="{ 'rotate-90': expandidos.has(modulo.id) }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                        </svg>
                        <p class="text-sm font-semibold text-tinta-900 flex-1 truncate">{{ modulo.nombre }}</p>
                        <span class="text-xs text-tinta-300 shrink-0">{{ modulo.lecciones.length }} lección{{ modulo.lecciones.length === 1 ? '' : 'es' }}</span>
                        <div class="flex items-center gap-0.5 shrink-0" @click.stop>
                            <button @click="moverModulo(modulo, -1)" :disabled="mIdx === 0" class="w-6 h-6 rounded-md flex items-center justify-center text-tinta-300 hover:bg-tinta-100 disabled:opacity-30">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7"/></svg>
                            </button>
                            <button @click="moverModulo(modulo, 1)" :disabled="mIdx === curso.modulos.length - 1" class="w-6 h-6 rounded-md flex items-center justify-center text-tinta-300 hover:bg-tinta-100 disabled:opacity-30">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                            </button>
                            <button @click="eliminarModulo(modulo)" class="w-6 h-6 rounded-md flex items-center justify-center text-tinta-300 hover:text-red-600 hover:bg-red-50">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            </button>
                        </div>
                    </div>

                    <div v-if="expandidos.has(modulo.id)" class="border-t border-linea">
                        <ul class="divide-y divide-gray-50">
                            <li v-for="(leccion, lIdx) in modulo.lecciones" :key="leccion.id" class="flex items-center gap-2 px-4 py-2.5 hover:bg-tinta-50/60">
                                <span class="text-xs text-tinta-300 w-4 shrink-0">{{ lIdx + 1 }}</span>
                                <div class="flex-1 min-w-0 cursor-pointer" @click="abrirEditarLeccion(modulo, leccion)">
                                    <p class="text-sm text-tinta-900 truncate">{{ leccion.nombre }}</p>
                                    <p class="text-xs text-tinta-300">{{ tipoLabel(leccion.tipo) }}<span v-if="leccion.duracion_minutos"> · {{ leccion.duracion_minutos }} min</span></p>
                                </div>
                                <div class="flex items-center gap-0.5 shrink-0">
                                    <button @click="moverLeccion(modulo, leccion, -1)" :disabled="lIdx === 0" class="w-6 h-6 rounded-md flex items-center justify-center text-tinta-300 hover:bg-tinta-100 disabled:opacity-30">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7"/></svg>
                                    </button>
                                    <button @click="moverLeccion(modulo, leccion, 1)" :disabled="lIdx === modulo.lecciones.length - 1" class="w-6 h-6 rounded-md flex items-center justify-center text-tinta-300 hover:bg-tinta-100 disabled:opacity-30">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                                    </button>
                                    <button @click="eliminarLeccion(modulo, leccion)" class="w-6 h-6 rounded-md flex items-center justify-center text-tinta-300 hover:text-red-600 hover:bg-red-50">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </div>
                            </li>
                        </ul>
                        <button @click="abrirNuevaLeccion(modulo)" class="w-full py-2.5 text-xs font-semibold text-center hover:bg-blue-50/60 transition-colors" style="color:var(--marca);">
                            + Lección
                        </button>

                        <!-- Evaluación de módulo (candado de avance, opcional) -->
                        <div class="border-t border-linea p-4 space-y-3" style="background:#FAFAFA;">
                            <div class="flex items-center justify-between">
                                <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Evaluación del módulo (opcional)</p>
                                <button v-if="modulo.evalCfg.id" @click="router.visit('/capacitacion/revision-evaluaciones')" class="text-xs font-semibold" style="color:var(--marca);">
                                    Cola de revisión
                                </button>
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div class="col-span-2">
                                    <label class="block text-xs font-medium text-tinta-700 mb-1">Nombre</label>
                                    <input v-model="modulo.evalCfg.nombre" type="text"
                                        class="w-full border border-linea rounded-xl px-3 py-2 text-sm bg-white focus:outline-none focus:border-blue-400" />
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-tinta-700 mb-1">Nota mínima (%)</label>
                                    <input v-model="modulo.evalCfg.nota_minima_aprobacion" type="number" min="0" max="100"
                                        class="w-full border border-linea rounded-xl px-3 py-2 text-sm bg-white focus:outline-none focus:border-blue-400" />
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-tinta-700 mb-1">Intentos permitidos</label>
                                    <input v-model="modulo.evalCfg.intentos_permitidos" type="number" min="1" placeholder="Ilimitados"
                                        class="w-full border border-linea rounded-xl px-3 py-2 text-sm bg-white focus:outline-none focus:border-blue-400" />
                                </div>
                            </div>
                            <label class="flex items-center gap-2 text-xs text-tinta-700">
                                <input v-model="modulo.evalCfg.requiere_revision_manual" type="checkbox" class="rounded" />
                                Requiere revisión manual (tiene preguntas abiertas)
                            </label>
                            <p v-if="modulo.errorEvaluacion" class="text-xs text-red-500">{{ modulo.errorEvaluacion }}</p>
                            <button @click="guardarEvaluacionModulo(modulo)" :disabled="modulo.guardandoEvaluacion"
                                class="w-full py-2.5 rounded-xl text-sm font-semibold text-white disabled:opacity-50" style="background:var(--marca);">
                                {{ modulo.guardandoEvaluacion ? 'Guardando...' : (modulo.evalCfg.id ? 'Actualizar configuración' : 'Crear evaluación de módulo') }}
                            </button>

                            <div v-if="modulo.evalCfg.id" class="space-y-2">
                                <div v-for="(pregunta, idx) in modulo.evalCfg.preguntas" :key="pregunta.id" class="bg-white rounded-xl border border-linea p-3">
                                    <div class="flex items-start gap-2">
                                        <span class="text-xs text-tinta-300 w-4 shrink-0 mt-0.5">{{ idx + 1 }}</span>
                                        <div class="flex-1 min-w-0 cursor-pointer" @click="abrirEditarPregunta(pregunta, modulo.evalCfg)">
                                            <p class="text-sm text-tinta-900">{{ pregunta.enunciado }}</p>
                                            <p class="text-xs text-tinta-300 mt-1">
                                                {{ pregunta.tipo === 'opcion_multiple' ? 'Opción múltiple' : 'Respuesta abierta (revisión manual)' }}
                                            </p>
                                            <ul v-if="pregunta.tipo === 'opcion_multiple'" class="mt-2 space-y-0.5">
                                                <li v-for="o in pregunta.opciones" :key="o.id" class="text-xs" :class="o.es_correcta ? 'text-green-700 font-semibold' : 'text-tinta-400'">
                                                    {{ o.es_correcta ? '✓' : '·' }} {{ o.texto }}
                                                </li>
                                            </ul>
                                        </div>
                                        <button @click.stop="eliminarPregunta(pregunta, modulo.evalCfg)" class="w-6 h-6 rounded-md flex items-center justify-center text-tinta-300 hover:text-red-600 hover:bg-red-50 shrink-0">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                        </button>
                                    </div>
                                </div>

                                <button @click="abrirNuevaPregunta(modulo.evalCfg)" class="w-full py-2 rounded-xl text-xs font-semibold text-center bg-white border border-linea hover:bg-blue-50/60 transition-colors" style="color:var(--marca);">
                                    + Pregunta
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Nuevo módulo -->
            <div class="bg-white rounded-2xl shadow-sm p-4">
                <div class="flex gap-2">
                    <input v-model="nuevoModuloNombre" @keyup.enter="crearModulo" type="text" placeholder="Nombre del nuevo módulo..."
                        class="flex-1 border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-blue-400" />
                    <button @click="crearModulo" :disabled="creandoModulo"
                        class="px-4 py-2 rounded-xl text-sm font-semibold text-white shrink-0 disabled:opacity-50" style="background:var(--marca);">
                        + Módulo
                    </button>
                </div>
                <p v-if="errorModulo" class="text-xs text-red-500 mt-2">{{ errorModulo }}</p>
            </div>

            <!-- Evaluación -->
            <div class="flex items-center justify-between mb-3 mt-6">
                <h2 class="text-sm font-semibold text-tinta-400 uppercase tracking-[0.12em]">Evaluación</h2>
                <button v-if="evaluacion.id" @click="router.visit('/capacitacion/revision-evaluaciones')" class="text-xs font-semibold" style="color:var(--marca);">
                    Cola de revisión
                </button>
            </div>

            <div class="bg-white rounded-2xl shadow-sm p-4 mb-4 space-y-3">
                <div class="grid grid-cols-2 gap-3">
                    <div class="col-span-2">
                        <label class="block text-xs font-medium text-tinta-700 mb-1">Nombre</label>
                        <input v-model="evaluacion.nombre" type="text"
                            class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-blue-400" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-tinta-700 mb-1">Nota mínima (%)</label>
                        <input v-model="evaluacion.nota_minima_aprobacion" type="number" min="0" max="100"
                            class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-blue-400" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-tinta-700 mb-1">Intentos permitidos</label>
                        <input v-model="evaluacion.intentos_permitidos" type="number" min="1" placeholder="Ilimitados"
                            class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-blue-400" />
                    </div>
                </div>
                <label class="flex items-center gap-2 text-xs text-tinta-700">
                    <input v-model="evaluacion.requiere_revision_manual" type="checkbox" class="rounded" />
                    Requiere revisión manual (tiene preguntas abiertas)
                </label>
                <p v-if="errorEvaluacion" class="text-xs text-red-500">{{ errorEvaluacion }}</p>
                <button @click="guardarEvaluacion" :disabled="guardandoEvaluacion"
                    class="w-full py-2.5 rounded-xl text-sm font-semibold text-white disabled:opacity-50" style="background:var(--marca);">
                    {{ guardandoEvaluacion ? 'Guardando...' : (evaluacion.id ? 'Actualizar configuración' : 'Crear evaluación') }}
                </button>
            </div>

            <div v-if="evaluacion.id" class="space-y-2 mb-4">
                <div v-for="(pregunta, idx) in evaluacion.preguntas" :key="pregunta.id" class="bg-white rounded-2xl shadow-sm p-4">
                    <div class="flex items-start gap-2">
                        <span class="text-xs text-tinta-300 w-4 shrink-0 mt-0.5">{{ idx + 1 }}</span>
                        <div class="flex-1 min-w-0 cursor-pointer" @click="abrirEditarPregunta(pregunta)">
                            <p class="text-sm text-tinta-900">{{ pregunta.enunciado }}</p>
                            <p class="text-xs text-tinta-300 mt-1">
                                {{ pregunta.tipo === 'opcion_multiple' ? 'Opción múltiple' : 'Respuesta abierta (revisión manual)' }}
                            </p>
                            <ul v-if="pregunta.tipo === 'opcion_multiple'" class="mt-2 space-y-0.5">
                                <li v-for="o in pregunta.opciones" :key="o.id" class="text-xs" :class="o.es_correcta ? 'text-green-700 font-semibold' : 'text-tinta-400'">
                                    {{ o.es_correcta ? '✓' : '·' }} {{ o.texto }}
                                </li>
                            </ul>
                        </div>
                        <button @click.stop="eliminarPregunta(pregunta)" class="w-6 h-6 rounded-md flex items-center justify-center text-tinta-300 hover:text-red-600 hover:bg-red-50 shrink-0">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        </button>
                    </div>
                </div>

                <button @click="abrirNuevaPregunta" class="w-full py-2.5 rounded-xl text-xs font-semibold text-center bg-white shadow-sm hover:bg-blue-50/60 transition-colors" style="color:var(--marca);">
                    + Pregunta
                </button>
            </div>
            <p v-else class="text-xs text-tinta-300 mb-4">Guarda la configuración de la evaluación para poder agregar preguntas.</p>

        </div>

        <!-- Modal lección -->
        <div v-if="modal.abierto" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
            <div class="absolute inset-0 bg-black/40" @click="cerrarModal"></div>
            <div class="relative bg-white w-full sm:max-w-md sm:rounded-2xl rounded-t-2xl shadow-xl max-h-[90vh] overflow-y-auto">
                <div class="px-5 py-4 border-b border-linea flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-tinta-900">{{ modal.editando ? 'Editar lección' : 'Nueva lección' }}</h3>
                    <button @click="cerrarModal" class="w-7 h-7 rounded-lg flex items-center justify-center text-tinta-300 hover:bg-tinta-100">✕</button>
                </div>
                <div class="p-5 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-tinta-700 mb-1">Nombre</label>
                        <input v-model="modal.form.nombre" type="text" maxlength="150"
                            class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-blue-400" />
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-tinta-700 mb-1">Tipo de contenido</label>
                        <select v-model="modal.form.tipo" class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-blue-400">
                            <option value="video_drive">Video (Google Drive)</option>
                            <option value="video_externo">Video externo (Loom/YouTube)</option>
                            <option value="texto">Texto</option>
                            <option value="pdf">PDF</option>
                        </select>
                    </div>

                    <div v-if="modal.form.tipo === 'video_drive'">
                        <label class="block text-sm font-medium text-tinta-700 mb-1">URL de Google Drive</label>
                        <input v-model="modal.form.contenido_url" type="url" placeholder="https://drive.google.com/..."
                            class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-blue-400" />
                    </div>

                    <div v-else-if="modal.form.tipo === 'video_externo'">
                        <label class="block text-sm font-medium text-tinta-700 mb-1">URL del video (Loom/YouTube)</label>
                        <input v-model="modal.form.contenido_url" type="url" placeholder="https://youtube.com/..."
                            class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-blue-400" />
                    </div>

                    <div v-else-if="modal.form.tipo === 'texto'">
                        <label class="block text-sm font-medium text-tinta-700 mb-1">Contenido</label>
                        <EditorTexto v-model="modal.form.contenido_texto" placeholder="Contenido de la lección..." :maxLength="10000" />
                    </div>

                    <div v-else-if="modal.form.tipo === 'pdf'">
                        <label class="block text-sm font-medium text-tinta-700 mb-1">Archivo PDF</label>
                        <label class="cursor-pointer block">
                            <input type="file" accept="application/pdf" class="hidden" @change="onArchivoPdf" />
                            <span class="inline-block px-3 py-2 rounded-xl border border-linea text-xs text-tinta-500 hover:bg-tinta-50">
                                {{ modal.form.archivo_pdf ? modal.form.archivo_pdf.name : (modal.form.archivo_pdf_nombre ? 'Cambiar PDF' : 'Subir PDF') }}
                            </span>
                        </label>
                        <p v-if="modal.form.archivo_pdf_nombre && !modal.form.archivo_pdf" class="text-xs text-tinta-300 mt-1">PDF actual ya cargado.</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-tinta-700 mb-1">Duración (minutos, opcional)</label>
                        <input v-model="modal.form.duracion_minutos" type="number" min="0"
                            class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-blue-400" />
                    </div>

                    <p v-if="modal.error" class="text-xs text-red-500">{{ modal.error }}</p>
                </div>
                <div class="px-5 py-4 border-t border-linea flex gap-2">
                    <button @click="cerrarModal" class="flex-1 py-2.5 rounded-xl text-sm font-semibold text-tinta-500 border border-linea hover:bg-tinta-50">Cancelar</button>
                    <button @click="guardarLeccion" :disabled="modal.guardando"
                        class="flex-1 py-2.5 rounded-xl text-sm font-semibold text-white disabled:opacity-50" style="background:var(--marca);">
                        {{ modal.guardando ? 'Guardando...' : 'Guardar' }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Modal pregunta -->
        <div v-if="modalPregunta.abierto" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
            <div class="absolute inset-0 bg-black/40" @click="cerrarModalPregunta"></div>
            <div class="relative bg-white w-full sm:max-w-md sm:rounded-2xl rounded-t-2xl shadow-xl max-h-[90vh] overflow-y-auto">
                <div class="px-5 py-4 border-b border-linea flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-tinta-900">{{ modalPregunta.editando ? 'Editar pregunta' : 'Nueva pregunta' }}</h3>
                    <button @click="cerrarModalPregunta" class="w-7 h-7 rounded-lg flex items-center justify-center text-tinta-300 hover:bg-tinta-100">✕</button>
                </div>
                <div class="p-5 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-tinta-700 mb-1">Enunciado</label>
                        <textarea v-model="modalPregunta.form.enunciado" rows="2"
                            class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-blue-400"></textarea>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-tinta-700 mb-1">Tipo</label>
                        <select v-model="modalPregunta.form.tipo" class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-blue-400">
                            <option value="opcion_multiple">Opción múltiple</option>
                            <option value="abierta">Respuesta abierta (revisión manual)</option>
                        </select>
                    </div>

                    <div v-if="modalPregunta.form.tipo === 'opcion_multiple'" class="space-y-2">
                        <label class="block text-sm font-medium text-tinta-700">Opciones (marca la correcta)</label>
                        <div v-for="(o, idx) in modalPregunta.form.opciones" :key="idx" class="flex items-center gap-2">
                            <input type="radio" name="opcion-correcta" :checked="o.es_correcta" @change="marcarCorrecta(idx)" />
                            <input v-model="o.texto" type="text" placeholder="Texto de la opción"
                                class="flex-1 border border-linea rounded-xl px-3 py-1.5 text-sm focus:outline-none focus:border-blue-400" />
                            <button v-if="modalPregunta.form.opciones.length > 2" @click="quitarOpcion(idx)" class="text-tinta-300 hover:text-red-600 text-xs shrink-0">✕</button>
                        </div>
                        <button @click="agregarOpcion" class="text-xs font-semibold" style="color:var(--marca);">+ Opción</button>
                    </div>

                    <p v-if="modalPregunta.error" class="text-xs text-red-500">{{ modalPregunta.error }}</p>
                </div>
                <div class="px-5 py-4 border-t border-linea flex gap-2">
                    <button @click="cerrarModalPregunta" class="flex-1 py-2.5 rounded-xl text-sm font-semibold text-tinta-500 border border-linea hover:bg-tinta-50">Cancelar</button>
                    <button @click="guardarPregunta" :disabled="modalPregunta.guardando"
                        class="flex-1 py-2.5 rounded-xl text-sm font-semibold text-white disabled:opacity-50" style="background:var(--marca);">
                        {{ modalPregunta.guardando ? 'Guardando...' : 'Guardar' }}
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
