<script setup>
import { ref, computed } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useClipboard } from '@/composables/useClipboard'

const { copyText } = useClipboard()

const props = defineProps({
    formularios: Array,
    etapas:      Array,
    usuarios:    Array,
    appUrl:      { type: String, default: '' },
})

// ─── Estado ─────────────────────────────────────────────────────────────────
const lista      = ref(props.formularios.map(f => ({ ...f })))
const modalOpen  = ref(false)
const editando   = ref(null)
const guardando  = ref(false)
const eliminando = ref(null)
const copiado    = ref(null)

const CAMPOS_DEFAULT = [
    { nombre: 'nombre',   etiqueta: 'Nombre completo',           tipo: 'text',     requerido: true  },
    { nombre: 'email',    etiqueta: 'Correo electrónico',         tipo: 'email',    requerido: true  },
    { nombre: 'telefono', etiqueta: 'Teléfono / WhatsApp',        tipo: 'tel',      requerido: false },
    { nombre: 'empresa',  etiqueta: 'Empresa',                    tipo: 'text',     requerido: false },
    { nombre: 'mensaje',  etiqueta: '¿En qué podemos ayudarte?',  tipo: 'textarea', requerido: false },
]

function formularioVacio() {
    return {
        nombre:                  '',
        etapa_id:                null,
        responsable_id:          null,
        asignacion_tipo:         'fijo',
        responsables_ids:        [],
        responsables_pesos:      {},
        fuente:                  '',
        titulo_formulario:       'Contáctanos',
        descripcion_formulario:  '',
        texto_boton:             'Enviar',
        mensaje_exito:           '¡Gracias! Nos pondremos en contacto pronto.',
        gracias_tipo:            'mensaje',
        gracias_url:             '',
        email_notificacion:      '',
        captcha_activo:          false,
        activo:                  true,
        campos: CAMPOS_DEFAULT.map(c => ({ ...c })),
    }
}

const form = ref(formularioVacio())

// ─── Modal ───────────────────────────────────────────────────────────────────
function abrirNuevo() {
    editando.value  = null
    form.value      = formularioVacio()
    modalOpen.value = true
}

function abrirEditar(f) {
    editando.value = f.id
    form.value = {
        nombre:                  f.nombre,
        etapa_id:                f.etapa_id,
        responsable_id:          f.responsable_id,
        asignacion_tipo:         f.asignacion_tipo  ?? 'fijo',
        responsables_ids:        f.responsables_ids  ? [...f.responsables_ids] : [],
        responsables_pesos:      f.responsables_pesos ? { ...f.responsables_pesos } : {},
        fuente:                  f.fuente            ?? '',
        titulo_formulario:       f.titulo_formulario,
        descripcion_formulario:  f.descripcion_formulario ?? '',
        texto_boton:             f.texto_boton,
        mensaje_exito:           f.mensaje_exito,
        gracias_tipo:            f.gracias_tipo      ?? 'mensaje',
        gracias_url:             f.gracias_url       ?? '',
        email_notificacion:      f.email_notificacion ?? '',
        captcha_activo:          f.captcha_activo    ?? false,
        activo:                  f.activo,
        campos:                  f.campos.map(c => ({ ...c })),
    }
    modalOpen.value  = true
}

function cerrarModal() {
    modalOpen.value = false
    editando.value  = null
}

// ─── CRUD ────────────────────────────────────────────────────────────────────
function getCsrf() {
    const m = document.cookie.split(';').find(c => c.trim().startsWith('XSRF-TOKEN='))
    return m ? decodeURIComponent(m.split('=')[1]) : ''
}

async function guardar() {
    guardando.value = true
    try {
        const url    = editando.value ? `/crm/formularios/${editando.value}` : '/crm/formularios'
        const method = editando.value ? 'PUT' : 'POST'
        const res = await fetch(url, {
            method,
            headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': getCsrf() },
            credentials: 'same-origin',
            body: JSON.stringify(form.value),
        })
        const data = await res.json()
        if (!res.ok) {
            alert(data.message ?? 'Error al guardar.')
            return
        }
        if (editando.value) {
            const idx = lista.value.findIndex(f => f.id === editando.value)
            if (idx !== -1) lista.value[idx] = data.formulario
        } else {
            lista.value.unshift(data.formulario)
        }
        cerrarModal()
    } finally {
        guardando.value = false
    }
}

async function eliminar(f) {
    if (!confirm(`¿Eliminar el formulario "${f.nombre}"?`)) return
    eliminando.value = f.id
    try {
        await fetch(`/crm/formularios/${f.id}`, {
            method: 'DELETE',
            headers: { 'X-XSRF-TOKEN': getCsrf() },
            credentials: 'same-origin',
        })
        lista.value = lista.value.filter(x => x.id !== f.id)
    } finally {
        eliminando.value = null
    }
}

// ─── Campos del constructor ──────────────────────────────────────────────────
function agregarCampo() {
    form.value.campos.push({ nombre: '', etiqueta: '', tipo: 'text', requerido: false })
}

function eliminarCampo(idx) {
    form.value.campos.splice(idx, 1)
}

function moverCampo(idx, dir) {
    const arr    = form.value.campos
    const destino = idx + dir
    if (destino < 0 || destino >= arr.length) return
    ;[arr[idx], arr[destino]] = [arr[destino], arr[idx]]
}

// ─── Asignación round robin / ponderado ─────────────────────────────────────
function toggleUsuario(id) {
    const idx = form.value.responsables_ids.indexOf(id)
    if (idx === -1) {
        form.value.responsables_ids.push(id)
        if (!form.value.responsables_pesos[id]) {
            form.value.responsables_pesos[id] = 1
        }
    } else {
        form.value.responsables_ids.splice(idx, 1)
        delete form.value.responsables_pesos[id]
    }
}

function setPeso(id, valor) {
    form.value.responsables_pesos[id] = Math.max(1, parseInt(valor) || 1)
}

// ─── Snippets ────────────────────────────────────────────────────────────────
function baseUrl() {
    return props.appUrl || window.location.origin
}

function snippetIframe(formulario) {
    const url = `${baseUrl()}/f/${formulario.slug}`
    const id  = `sgi-form-${formulario.slug}`
    return `<iframe
  id="${id}"
  src="${url}"
  width="100%"
  height="500"
  frameborder="0"
  scrolling="no"
  style="border:none;display:block;overflow:hidden;transition:height 0.3s ease;"
  loading="lazy">
</iframe>
<script>
window.addEventListener('message', function(e) {
  if (e.data && e.data.tipo === 'sgi-form-altura') {
    var iframe = document.getElementById('${id}');
    if (iframe) iframe.style.height = (e.data.altura + 20) + 'px';
  }
});
<\/script>`
}

async function copiarSnippet(formulario, tipo = 'iframe') {
    const texto = snippetIframe(formulario)
    const key   = `${formulario?.id ?? 'modal'}-${tipo}`
    await copyText(texto)
    copiado.value = key
    setTimeout(() => { copiado.value = null }, 2000)
}

const formularioEditando = computed(() => lista.value.find(f => f.id === editando.value) ?? { slug: '' })
</script>

<template>
    <AppLayout title="Formularios CRM">
        <div class="max-w-4xl mx-auto">

            <!-- Cabecera -->
            <div class="flex items-center justify-between mb-5">
                <div>
                    <h1 class="text-xl font-semibold text-tinta-900">Formularios CRM</h1>
                    <p class="text-sm text-tinta-400 mt-0.5">Crea formularios embebibles que generan leads en el pipeline</p>
                </div>
                <button
                    @click="abrirNuevo"
                    class="flex items-center gap-2 px-4 py-2 rounded-xl text-white text-sm font-semibold"
                    style="background:var(--marca)"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                    </svg>
                    Nuevo formulario
                </button>
            </div>

            <!-- Lista vacía -->
            <div v-if="!lista.length" class="bg-white rounded-2xl border border-linea py-16 text-center">
                <svg class="w-12 h-12 text-tinta-200 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                <p class="text-tinta-400 text-sm">Sin formularios. Crea el primero.</p>
            </div>

            <!-- Tabla de formularios -->
            <div v-else class="bg-white rounded-2xl border border-linea overflow-hidden">
                <div class="divide-y divide-gray-50">
                    <div v-for="f in lista" :key="f.id" class="px-5 py-4">
                        <!-- Fila principal -->
                        <div class="flex items-start gap-4">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <p class="text-sm font-semibold text-tinta-900">{{ f.nombre }}</p>
                                    <span class="text-xs px-2 py-0.5 rounded-full"
                                        :class="f.activo ? 'bg-green-100 text-green-700' : 'bg-tinta-100 text-tinta-400'">
                                        {{ f.activo ? 'Activo' : 'Inactivo' }}
                                    </span>
                                    <span v-if="f.fuente" class="text-xs px-2 py-0.5 rounded-full bg-blue-50 text-blue-600">{{ f.fuente }}</span>
                                    <span v-if="f.captcha_activo" class="text-xs px-2 py-0.5 rounded-full bg-purple-50 text-purple-600">reCAPTCHA</span>
                                </div>
                                <p class="text-xs text-tinta-300 mt-0.5">
                                    Etapa: {{ f.etapa?.nombre ?? 'Primera etapa' }}
                                    <span v-if="f.asignacion_tipo && f.asignacion_tipo !== 'fijo'"> · {{ f.asignacion_tipo === 'round_robin' ? 'Round Robin' : 'Ponderado' }}</span>
                                    <span v-else-if="f.responsable"> · {{ f.responsable.name }}</span>
                                    · {{ f.campos?.length ?? 0 }} campos
                                </p>
                                <p class="text-xs text-tinta-300 mt-0.5 font-mono">/f/{{ f.slug }}</p>
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                <a :href="`/f/${f.slug}`" target="_blank"
                                    class="px-3 py-1.5 rounded-xl border border-linea text-xs text-tinta-500 hover:bg-tinta-50">Ver</a>
                                <button @click="abrirEditar(f)"
                                    class="px-3 py-1.5 rounded-xl border border-linea text-xs text-blue-600 hover:bg-blue-50">Editar</button>
                                <button @click="eliminar(f)" :disabled="eliminando === f.id"
                                    class="px-3 py-1.5 rounded-xl border border-linea text-xs text-red-500 hover:bg-red-50">Eliminar</button>
                            </div>
                        </div>

                        <!-- Snippet inline -->
                        <div class="mt-3 flex gap-2">
                            <div class="flex-1 bg-tinta-50 rounded-xl border border-linea p-2 flex items-center gap-2 min-w-0">
                                <p class="text-xs text-tinta-400 font-mono flex-1 truncate">{{ snippetIframe(f).split('\n')[0] }}…</p>
                                <button @click="copiarSnippet(f)"
                                    class="shrink-0 text-xs px-2 py-1 rounded-lg border border-tinta-200 text-tinta-500 hover:bg-white">
                                    {{ copiado === `${f.id}-iframe` ? '✓ Copiado' : 'Copiar snippet' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ─── MODAL Constructor ──────────────────────────────────────────── -->
        <Teleport to="body">
            <div v-if="modalOpen"
                class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4"
                @click.self="cerrarModal">
                <div class="absolute inset-0 bg-black/40" @click="cerrarModal"></div>
                <div class="relative bg-white w-full sm:max-w-2xl max-h-[95dvh] overflow-y-auto rounded-t-3xl sm:rounded-2xl shadow-2xl">

                    <!-- Header -->
                    <div class="sticky top-0 bg-white z-10 flex items-center justify-between px-5 py-4 border-b border-linea">
                        <h2 class="text-base font-semibold text-tinta-900">
                            {{ editando ? 'Editar formulario' : 'Nuevo formulario' }}
                        </h2>
                        <button @click="cerrarModal" class="w-8 h-8 rounded-full hover:bg-tinta-100 flex items-center justify-center">
                            <svg class="w-5 h-5 text-tinta-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    <div class="p-5 space-y-6">

                        <!-- ── CONFIG GENERAL ───────────────────────────────── -->
                        <section>
                            <p class="text-xs font-semibold text-tinta-300 uppercase tracking-[0.12em] mb-3">Configuración general</p>
                            <div class="space-y-3">
                                <div>
                                    <label class="block text-xs font-semibold text-tinta-500 mb-1">Nombre interno *</label>
                                    <input v-model="form.nombre" type="text" placeholder="Ej: Formulario landing refrigeración"
                                        class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400"/>
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs font-semibold text-tinta-500 mb-1">Etapa destino</label>
                                        <select v-model="form.etapa_id"
                                            class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none bg-white">
                                            <option :value="null">Primera etapa</option>
                                            <option v-for="e in etapas" :key="e.id" :value="e.id">{{ e.nombre }}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-tinta-500 mb-1">Fuente</label>
                                        <select v-model="form.fuente"
                                            class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none bg-white">
                                            <option value="">Sin especificar</option>
                                            <option>Web</option>
                                            <option>Instagram</option>
                                            <option>Facebook</option>
                                            <option>WhatsApp</option>
                                            <option>Referido</option>
                                            <option>Otro</option>
                                        </select>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-tinta-500 mb-1">Email notificación</label>
                                    <input v-model="form.email_notificacion" type="email" placeholder="notificaciones@empresa.com"
                                        class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400"/>
                                </div>
                                <div v-if="editando">
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input v-model="form.activo" type="checkbox" class="rounded"/>
                                        <span class="text-sm text-tinta-700">Formulario activo</span>
                                    </label>
                                </div>
                            </div>
                        </section>

                        <!-- ── ASIGNACIÓN DE RESPONSABLE ────────────────────── -->
                        <section>
                            <p class="text-xs font-semibold text-tinta-300 uppercase tracking-[0.12em] mb-3">Asignación de responsable</p>
                            <div class="space-y-3">
                                <!-- Tipo de asignación -->
                                <div class="flex gap-2">
                                    <label v-for="opt in [['fijo','Fijo'],['round_robin','Round Robin'],['ponderado','Ponderado']]"
                                        :key="opt[0]"
                                        class="flex-1 flex items-center gap-1.5 border rounded-xl px-3 py-2 cursor-pointer text-sm transition-colors"
                                        :class="form.asignacion_tipo === opt[0]
                                            ? 'border-blue-500 bg-blue-50 text-blue-700 font-semibold'
                                            : 'border-linea text-tinta-500'">
                                        <input type="radio" v-model="form.asignacion_tipo" :value="opt[0]" class="sr-only"/>
                                        {{ opt[1] }}
                                    </label>
                                </div>

                                <!-- Fijo: un responsable -->
                                <div v-if="form.asignacion_tipo === 'fijo'">
                                    <label class="block text-xs font-semibold text-tinta-500 mb-1">Responsable</label>
                                    <select v-model="form.responsable_id"
                                        class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none bg-white">
                                        <option :value="null">Sin asignar</option>
                                        <option v-for="u in usuarios" :key="u.id" :value="u.id">{{ u.name }}</option>
                                    </select>
                                </div>

                                <!-- Round Robin: selección múltiple -->
                                <div v-else-if="form.asignacion_tipo === 'round_robin'">
                                    <label class="block text-xs font-semibold text-tinta-500 mb-2">Usuarios en rotación</label>
                                    <div class="space-y-1.5 max-h-40 overflow-y-auto">
                                        <label v-for="u in usuarios" :key="u.id"
                                            class="flex items-center gap-2 px-3 py-2 rounded-xl border cursor-pointer transition-colors"
                                            :class="form.responsables_ids.includes(u.id)
                                                ? 'border-blue-400 bg-blue-50'
                                                : 'border-linea hover:bg-tinta-50'">
                                            <input type="checkbox"
                                                :checked="form.responsables_ids.includes(u.id)"
                                                @change="toggleUsuario(u.id)"
                                                class="rounded"/>
                                            <span class="text-sm text-tinta-700">{{ u.name }}</span>
                                        </label>
                                    </div>
                                    <p class="text-xs text-tinta-300 mt-1.5">Cada lead se asigna al siguiente usuario en turno.</p>
                                </div>

                                <!-- Ponderado: selección + pesos -->
                                <div v-else-if="form.asignacion_tipo === 'ponderado'">
                                    <label class="block text-xs font-semibold text-tinta-500 mb-2">Usuarios y pesos</label>
                                    <div class="space-y-1.5 max-h-44 overflow-y-auto">
                                        <div v-for="u in usuarios" :key="u.id"
                                            class="flex items-center gap-2 px-3 py-2 rounded-xl border transition-colors"
                                            :class="form.responsables_ids.includes(u.id)
                                                ? 'border-blue-400 bg-blue-50'
                                                : 'border-linea'">
                                            <input type="checkbox"
                                                :checked="form.responsables_ids.includes(u.id)"
                                                @change="toggleUsuario(u.id)"
                                                class="rounded cursor-pointer"/>
                                            <span class="text-sm text-tinta-700 flex-1">{{ u.name }}</span>
                                            <div v-if="form.responsables_ids.includes(u.id)" class="flex items-center gap-1">
                                                <span class="text-xs text-tinta-400">Peso:</span>
                                                <input
                                                    type="number" min="1" max="99"
                                                    :value="form.responsables_pesos[u.id] ?? 1"
                                                    @change="setPeso(u.id, $event.target.value)"
                                                    class="w-14 rounded-lg border border-tinta-200 px-2 py-1 text-xs text-center focus:outline-none"/>
                                            </div>
                                        </div>
                                    </div>
                                    <p class="text-xs text-tinta-300 mt-1.5">Mayor peso = más leads asignados proporcionalmente.</p>
                                </div>
                            </div>
                        </section>

                        <!-- ── APARIENCIA ────────────────────────────────────── -->
                        <section>
                            <p class="text-xs font-semibold text-tinta-300 uppercase tracking-[0.12em] mb-3">Apariencia</p>
                            <div class="space-y-3">
                                <div>
                                    <label class="block text-xs font-semibold text-tinta-500 mb-1">Título del formulario</label>
                                    <input v-model="form.titulo_formulario" type="text"
                                        class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400"/>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-tinta-500 mb-1">Descripción / subtítulo</label>
                                    <textarea v-model="form.descripcion_formulario" rows="2"
                                        class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400 resize-none"></textarea>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-tinta-500 mb-1">Texto del botón</label>
                                    <input v-model="form.texto_boton" type="text"
                                        class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400"/>
                                </div>
                            </div>
                        </section>

                        <!-- ── PÁGINA DE GRACIAS ─────────────────────────────── -->
                        <section>
                            <p class="text-xs font-semibold text-tinta-300 uppercase tracking-[0.12em] mb-3">Página de gracias</p>
                            <div class="space-y-3">
                                <div class="flex gap-2">
                                    <label v-for="opt in [['mensaje','Mostrar mensaje'],['redirect','Redirigir']]"
                                        :key="opt[0]"
                                        class="flex-1 flex items-center gap-1.5 border rounded-xl px-3 py-2 cursor-pointer text-sm transition-colors"
                                        :class="form.gracias_tipo === opt[0]
                                            ? 'border-blue-500 bg-blue-50 text-blue-700 font-semibold'
                                            : 'border-linea text-tinta-500'">
                                        <input type="radio" v-model="form.gracias_tipo" :value="opt[0]" class="sr-only"/>
                                        {{ opt[1] }}
                                    </label>
                                </div>
                                <div v-if="form.gracias_tipo === 'mensaje'">
                                    <label class="block text-xs font-semibold text-tinta-500 mb-1">Mensaje de confirmación</label>
                                    <input v-model="form.mensaje_exito" type="text" placeholder="¡Gracias! Nos pondremos en contacto pronto."
                                        class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400"/>
                                </div>
                                <div v-else>
                                    <label class="block text-xs font-semibold text-tinta-500 mb-1">URL de destino</label>
                                    <input v-model="form.gracias_url" type="url" placeholder="https://tudominio.com/gracias"
                                        class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400"/>
                                </div>
                            </div>
                        </section>

                        <!-- ── CAMPOS ────────────────────────────────────────── -->
                        <section>
                            <div class="flex items-center justify-between mb-3">
                                <p class="text-xs font-semibold text-tinta-300 uppercase tracking-[0.12em]">Campos del formulario</p>
                                <button @click="agregarCampo"
                                    class="flex items-center gap-1 text-xs px-3 py-1.5 rounded-xl border border-linea text-blue-600 hover:bg-blue-50">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                                    </svg>
                                    Agregar campo
                                </button>
                            </div>

                            <div class="space-y-2">
                                <div v-for="(campo, idx) in form.campos" :key="idx"
                                    class="bg-tinta-50 rounded-xl border border-linea p-3">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="text-xs text-tinta-300 font-mono w-4 text-center shrink-0">{{ idx + 1 }}</span>
                                        <input v-model="campo.etiqueta" type="text" placeholder="Etiqueta visible"
                                            class="flex-1 min-w-0 rounded-lg border border-tinta-200 px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400"/>
                                        <input v-model="campo.nombre" type="text" placeholder="nombre_campo"
                                            class="w-28 rounded-lg border border-tinta-200 px-2 py-1.5 text-xs font-mono focus:outline-none focus:ring-2 focus:ring-blue-400"/>
                                        <select v-model="campo.tipo"
                                            class="w-24 rounded-lg border border-tinta-200 px-2 py-1.5 text-xs focus:outline-none bg-white">
                                            <option value="text">Texto</option>
                                            <option value="email">Email</option>
                                            <option value="tel">Teléfono</option>
                                            <option value="textarea">Área texto</option>
                                            <option value="number">Número</option>
                                            <option value="select">Selector</option>
                                        </select>
                                        <label class="flex items-center gap-1 shrink-0">
                                            <input v-model="campo.requerido" type="checkbox" class="rounded"/>
                                            <span class="text-xs text-tinta-500">Req.</span>
                                        </label>
                                        <div class="flex gap-0.5 shrink-0">
                                            <button @click="moverCampo(idx, -1)" :disabled="idx === 0"
                                                class="p-1 rounded hover:bg-tinta-200 disabled:opacity-30">
                                                <svg class="w-3.5 h-3.5 text-tinta-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7"/>
                                                </svg>
                                            </button>
                                            <button @click="moverCampo(idx, 1)" :disabled="idx === form.campos.length - 1"
                                                class="p-1 rounded hover:bg-tinta-200 disabled:opacity-30">
                                                <svg class="w-3.5 h-3.5 text-tinta-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                                                </svg>
                                            </button>
                                            <button @click="eliminarCampo(idx)" :disabled="form.campos.length <= 1"
                                                class="p-1 rounded hover:bg-red-100 text-red-400 disabled:opacity-30">
                                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <!-- ── SEGURIDAD ─────────────────────────────────────── -->
                        <section>
                            <p class="text-xs font-semibold text-tinta-300 uppercase tracking-[0.12em] mb-3">Seguridad</p>
                            <label class="flex items-start gap-3 cursor-pointer">
                                <div class="relative shrink-0 mt-0.5">
                                    <input v-model="form.captcha_activo" type="checkbox" class="sr-only peer"/>
                                    <div class="w-10 h-6 bg-tinta-200 rounded-full peer-checked:bg-blue-600 transition-colors"></div>
                                    <div class="absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform peer-checked:translate-x-4"></div>
                                </div>
                                <div>
                                    <p class="text-sm font-medium text-tinta-700">Google reCAPTCHA v3</p>
                                    <p class="text-xs text-tinta-300 mt-0.5">Protege el formulario de bots. Requiere configurar las claves en Configuración → Seguridad.</p>
                                </div>
                            </label>
                        </section>

                        <!-- ── SNIPPET (solo en edición) ─────────────────────── -->
                        <section v-if="editando">
                            <p class="text-xs font-semibold text-tinta-300 uppercase tracking-[0.12em] mb-3">Snippet para embeber</p>
                            <div class="relative">
                                <pre class="bg-gray-900 text-green-300 text-xs p-4 rounded-xl overflow-x-auto whitespace-pre-wrap break-all">{{ snippetIframe(formularioEditando) }}</pre>
                                <button
                                    @click="copiarSnippet(formularioEditando)"
                                    class="absolute top-2 right-2 text-xs px-2 py-1 rounded-lg bg-gray-700 text-gray-200 hover:bg-gray-600">
                                    {{ copiado === `${formularioEditando?.id}-iframe` ? '✓ Copiado' : 'Copiar' }}
                                </button>
                            </div>
                        </section>

                    </div>

                    <!-- Footer modal -->
                    <div class="sticky bottom-0 bg-white border-t border-linea px-5 py-4 flex gap-3">
                        <button @click="cerrarModal"
                            class="flex-1 py-3 rounded-xl border border-linea text-sm text-tinta-500 font-medium">
                            Cancelar
                        </button>
                        <button @click="guardar"
                            :disabled="guardando || !form.nombre || !form.campos.length"
                            class="flex-1 py-3 rounded-xl text-white text-sm font-semibold disabled:opacity-50"
                            style="background:var(--marca)">
                            {{ guardando ? 'Guardando…' : (editando ? 'Actualizar' : 'Crear formulario') }}
                        </button>
                    </div>

                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
