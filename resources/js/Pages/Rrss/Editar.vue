<script setup>
import { computed, ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    publicacion: Object,
    cuentas:     Array,
})

const form = useForm({
    contenido: props.publicacion.contenido,
    fecha_programada: props.publicacion.fecha_programada,
    cuentas: props.publicacion.cuentas.map(c => c.cuenta_id),
    imagen: null,
    accion: props.publicacion.estado === 'borrador' ? 'borrador' : 'programar',
    _method: 'PUT',
})

const redLabel = {
    instagram: 'Instagram', facebook: 'Facebook', linkedin: 'LinkedIn', google_business: 'Google Business Profile',
}
const redIcono = { instagram: '📷', facebook: '📘', linkedin: '💼', google_business: '📍' }

const cuentasPorRed = computed(() => {
    const grupos = {}
    for (const c of props.cuentas) {
        grupos[c.red] = grupos[c.red] || []
        grupos[c.red].push(c)
    }
    return grupos
})

function toggleCuenta(id) {
    const i = form.cuentas.indexOf(id)
    if (i === -1) form.cuentas.push(id)
    else form.cuentas.splice(i, 1)
}

const previewImagen = ref(props.publicacion.imagen_url || null)
function onImagen(e) {
    const file = e.target.files[0]
    form.imagen = file || null
    previewImagen.value = file ? URL.createObjectURL(file) : previewImagen.value
}

// ── Imagen generada con IA ────────────────────────────────────────────────────
// Queda guardada en Multimedia además de reemplazar la de esta publicación.
const iaAbierto     = ref(false)
const iaDescripcion = ref('')
const iaEstilo      = ref('fotografico')
const iaMejorar     = ref(true)
const iaCargando    = ref(false)
const iaError       = ref('')

function getCookie(name) {
    const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'))
    return match ? decodeURIComponent(match[2]) : ''
}

async function generarImagen() {
    if (!iaDescripcion.value.trim()) return

    iaCargando.value = true
    iaError.value    = ''

    try {
        const resp = await fetch('/api/ia/imagen', {
            method:  'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': getCookie('XSRF-TOKEN'),
                'Accept':       'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                descripcion: iaDescripcion.value,
                estilo: iaEstilo.value,
                mejorar: iaMejorar.value,
            }),
        })

        const data = await resp.json()

        if (!resp.ok) {
            iaError.value = data.error ?? 'No se pudo generar la imagen.'
            return
        }

        const blob = await (await fetch(data.url)).blob()
        form.imagen = new File([blob], data.nombre, { type: blob.type })
        previewImagen.value = data.url
        iaAbierto.value = false
    } catch (e) {
        iaError.value = 'No se pudo conectar con la IA.'
    } finally {
        iaCargando.value = false
    }
}

const seleccionoInstagram = computed(() =>
    form.cuentas.some(id => props.cuentas.find(c => c.id === id)?.red === 'instagram')
)

function enviar(accion) {
    form.accion = accion
    form.post(`/rrss/${props.publicacion.id}`, { forceFormData: true })
}
</script>

<template>
    <AppLayout title="Editar publicación">
        <div class="max-w-2xl mx-auto px-4 py-4">

            <div class="flex items-center gap-3 mb-5">
                <button @click="router.visit('/rrss')" class="p-2 rounded-xl hover:bg-tinta-100 text-tinta-400">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                    </svg>
                </button>
                <h1 class="text-xl font-semibold text-tinta-900">Editar publicación</h1>
            </div>

            <div class="bg-white rounded-2xl border border-linea p-5 space-y-4">

                <div>
                    <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Texto *</label>
                    <textarea v-model="form.contenido" rows="5" maxlength="3000"
                        class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                    <p class="text-xs text-tinta-300 mt-1 text-right">{{ form.contenido.length }}/3000</p>
                    <p v-if="form.errors.contenido" class="text-xs text-red-500 mt-1">{{ form.errors.contenido }}</p>
                </div>

                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Imagen</label>
                        <button type="button" @click="iaAbierto = !iaAbierto"
                            class="text-xs font-semibold text-[var(--marca)] hover:underline">
                            {{ iaAbierto ? 'Cerrar' : 'Generar con IA' }}
                        </button>
                    </div>

                    <div v-if="iaAbierto" class="rounded-xl border border-linea bg-tinta-50 p-3 mb-2 space-y-2">
                        <textarea v-model="iaDescripcion" rows="2" maxlength="1000"
                            placeholder="Describe la imagen que quieres"
                            class="w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
                        <select v-model="iaEstilo"
                            class="w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm bg-white">
                            <option value="fotografico">Fotográfico</option>
                            <option value="ilustracion">Ilustración</option>
                            <option value="minimalista">Minimalista</option>
                            <option value="3d">Render 3D</option>
                        </select>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input v-model="iaMejorar" type="checkbox" class="rounded" />
                            <span class="text-xs text-tinta-500">Mejorar mi descripción con IA</span>
                        </label>
                        <button type="button" @click="generarImagen" :disabled="iaCargando || !iaDescripcion.trim()"
                            class="w-full py-2 rounded-lg text-white text-sm font-semibold disabled:opacity-50"
                            style="background:var(--marca);">
                            {{ iaCargando ? 'Generando…' : 'Generar imagen' }}
                        </button>
                        <p v-if="iaError" class="text-xs text-red-600">{{ iaError }}</p>
                        <p class="text-xs text-tinta-300">
                            La imagen se guarda también en Multimedia para reutilizarla después.
                        </p>
                        <p class="text-xs text-tinta-300">
                            Recomendado: <strong>1080 × 1080 px</strong> (cuadrada) — es el formato
                            que aceptan bien todas las redes. Para Instagram también sirve
                            1080 × 1350 px (vertical), que ocupa más espacio en el feed.
                        </p>
                    </div>

                    <input type="file" accept="image/*" @change="onImagen"
                        class="w-full text-sm text-tinta-500 file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:bg-tinta-100 file:text-sm" />
                    <img v-if="previewImagen" :src="previewImagen" class="mt-2 w-28 h-28 rounded-lg object-cover" />
                    <p v-if="seleccionoInstagram && !previewImagen" class="text-xs text-amber-600 mt-1">
                        Instagram exige al menos una imagen para publicar.
                    </p>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Fecha y hora *</label>
                    <input v-model="form.fecha_programada" type="datetime-local"
                        class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
                    <p v-if="form.errors.fecha_programada" class="text-xs text-red-500 mt-1">{{ form.errors.fecha_programada }}</p>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Publicar en *</label>
                    <div v-for="(lista, red) in cuentasPorRed" :key="red" class="mb-2">
                        <p class="text-xs font-semibold text-tinta-500 mb-1">{{ redIcono[red] }} {{ redLabel[red] ?? red }}</p>
                        <label v-for="c in lista" :key="c.id"
                            class="flex items-center gap-2 py-1.5 px-2 rounded-lg hover:bg-tinta-50 cursor-pointer">
                            <input type="checkbox" :checked="form.cuentas.includes(c.id)" @change="toggleCuenta(c.id)" class="rounded" />
                            <span class="text-sm text-tinta-700">{{ c.nombre_cuenta }}</span>
                        </label>
                    </div>
                    <p v-if="form.errors.cuentas" class="text-xs text-red-500 mt-1">{{ form.errors.cuentas }}</p>
                </div>

                <div class="flex gap-3 pt-2">
                    <button @click="enviar('borrador')" :disabled="form.processing"
                        class="flex-1 py-2.5 rounded-xl border border-linea text-sm text-tinta-500 font-medium">
                        Guardar borrador
                    </button>
                    <button @click="enviar('programar')" :disabled="form.processing"
                        class="flex-1 py-2.5 rounded-xl text-white text-sm font-semibold" style="background:var(--marca);">
                        Guardar y programar
                    </button>
                </div>
            </div>

        </div>
    </AppLayout>
</template>
