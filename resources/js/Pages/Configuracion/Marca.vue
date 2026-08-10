<script setup>
import { ref, computed, watch } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { colorMarca } from '@/marca'

const props = defineProps({
    marca:             { type: Object, default: () => ({}) },
    color_por_defecto: { type: String, default: colorMarca() },
    fuentes:           { type: Array,  default: () => [] },
})

const form = useForm({
    color:  props.marca.color ?? props.color_por_defecto,
    titulo: props.marca.titulo ?? '{empresa}',
    fuente: props.marca.fuente ?? 'sistema',
})

// La pila real de la tipografía elegida, para que la vista previa se dibuje con
// la fuente de verdad y no con una aproximación.
const pilaElegida = computed(
    () => props.fuentes.find(f => f.clave === form.fuente)?.pila ?? 'inherit'
)

// La paleta que se está viendo en la vista previa. Arranca con la guardada y
// se recalcula en el servidor cada vez que se mueve el selector, para que la
// previa use exactamente la misma fórmula que la aplicación real.
const paleta = ref({ ...(props.marca.paleta ?? {}) })

let temporizador = null
watch(() => form.color, (color) => {
    if (!/^#[0-9A-Fa-f]{6}$/.test(color)) return
    // Sin esta espera, arrastrar el selector dispara una petición por pixel.
    clearTimeout(temporizador)
    temporizador = setTimeout(() => pedirPaleta(color), 200)
})

async function pedirPaleta(color) {
    try {
        const resp = await fetch('/configuracion/marca/previsualizar', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-XSRF-TOKEN': tokenCsrf(),
            },
            body: JSON.stringify({ color }),
        })
        if (resp.ok) paleta.value = await resp.json()
    } catch (e) {
        // Si falla, la previa se queda como está. No es crítico.
    }
}

function tokenCsrf() {
    return decodeURIComponent(
        document.cookie.split('; ').find(c => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? ''
    )
}

// Estilo de la vista previa: usa la paleta calculada, no las variables
// globales, para poder mostrar el color nuevo sin haberlo aplicado todavía.
const estiloPrevia = computed(() => ({
    '--p':        paleta.value['marca']        ?? form.color,
    '--p-oscuro': paleta.value['marca-oscuro'] ?? form.color,
    '--p-suave':  paleta.value['marca-suave']  ?? '#EFF6FF',
    '--p-medio':  paleta.value['marca-medio']  ?? '#DBEAFE',
    '--p-texto':  paleta.value['marca-texto']  ?? '#FFFFFF',
}))

// El título tal como saldría en la pestaña.
const tituloEjemplo = computed(() =>
    (form.titulo || '')
        .replace('{pagina}', 'Clientes')
        .replace('{empresa}', props.marca.empresa ?? 'Mi Empresa')
        .replace(/^[\s\-–—|·]+|[\s\-–—|·]+$/g, '')
        .trim() || '(vacío)'
)

function guardar() {
    form.post('/configuracion/marca', { preserveScroll: true })
}

function restaurarColor() {
    form.color = props.color_por_defecto
}

// ─── Imágenes (logo y favicon) ──────────────────────────────────────────────
// Ambas se guardan en el servidor, en storage/app/public/marca.
const faviconUrl = ref(props.marca.favicon_url ?? '')
const logoUrl    = ref(props.marca.logo_url ?? '')

const subiendo = ref({ favicon: false, logo: false })
const errores  = ref({ favicon: '', logo: '' })

const urls = { favicon: faviconUrl, logo: logoUrl }

function abrirSelector(cual) {
    document.getElementById(`input-${cual}`)?.click()
}

async function subirImagen(e, cual) {
    const archivo = e.target.files?.[0]
    if (!archivo) return

    subiendo.value[cual] = true
    errores.value[cual]  = ''

    try {
        const datos = new FormData()
        datos.append(cual, archivo)

        const resp = await fetch(`/configuracion/marca/${cual}`, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-XSRF-TOKEN': tokenCsrf() },
            body: datos,
        })

        const json = await resp.json().catch(() => ({}))

        if (!resp.ok) {
            errores.value[cual] = json?.errors?.[cual]?.[0]
                ?? json?.message
                ?? 'No se pudo subir el archivo.'
            return
        }

        urls[cual].value = json.url
    } catch (err) {
        errores.value[cual] = 'No se pudo subir el archivo.'
    } finally {
        subiendo.value[cual] = false
        e.target.value = ''
    }
}

function quitarImagen(cual) {
    router.delete(`/configuracion/marca/${cual}`, {
        preserveScroll: true,
        onSuccess: () => { urls[cual].value = '' },
    })
}

function ic(extra = '') {
    return `w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm focus:ring-4 focus:ring-[var(--marca-suave)] focus:outline-none ${extra}`
}
</script>

<template>
    <AppLayout title="Marca">
        <div class="max-w-2xl mx-auto space-y-4 pb-8">

            <a href="/configuracion" @click.prevent="router.visit('/configuracion')"
                class="inline-flex items-center gap-1.5 text-sm text-tinta-400 hover:text-tinta-700">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                </svg>
                Configuración
            </a>

            <!-- ── Color ──────────────────────────────────────────────────── -->
            <div class="bg-white rounded-xl border border-linea p-4 space-y-4">
                <div>
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Color de la marca</p>
                    <p class="text-xs text-tinta-400 mt-1">
                        Eliges uno solo. El tono de hover, los fondos suaves y el color del texto
                        se calculan a partir de él, para que la combinación siempre funcione.
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    <input v-model="form.color" type="color"
                        class="h-11 w-14 shrink-0 cursor-pointer rounded-lg border border-tinta-200 bg-white p-1"/>
                    <input v-model="form.color" type="text" :class="ic('font-mono uppercase')" maxlength="7"/>
                    <button type="button" @click="restaurarColor"
                        class="shrink-0 text-xs text-blue-600 font-medium hover:underline">
                        Restaurar
                    </button>
                </div>
                <p v-if="form.errors.color" class="text-red-500 text-xs">{{ form.errors.color }}</p>

                <!-- Paleta derivada -->
                <div class="grid grid-cols-5 gap-2">
                    <div v-for="(valor, nombre) in paleta" :key="nombre" class="text-center">
                        <div class="h-10 rounded-lg border border-linea" :style="{ backgroundColor: valor }"></div>
                        <p class="text-[10px] text-tinta-300 mt-1 truncate">{{ nombre.replace('marca-', '').replace('marca', 'base') }}</p>
                    </div>
                </div>
            </div>

            <!-- ── Vista previa ───────────────────────────────────────────── -->
            <div class="bg-white rounded-xl border border-linea p-4 space-y-3">
                <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Así se vería</p>

                <div :style="estiloPrevia" class="rounded-xl border border-linea overflow-hidden">
                    <!-- Encabezado -->
                    <div class="flex items-center justify-between px-4 py-3"
                        style="background: var(--p); color: var(--p-texto);">
                        <span class="text-sm font-semibold">{{ marca.empresa }}</span>
                        <span class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-semibold"
                            style="background: rgba(255,255,255,.25);">A</span>
                    </div>

                    <div class="p-4 space-y-3 bg-tinta-50">
                        <!-- Tarjeta tipo dashboard -->
                        <div class="bg-white rounded-xl p-3 flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0"
                                style="background: var(--p-suave);">
                                <svg class="w-5 h-5" style="color: var(--p)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                                </svg>
                            </div>
                            <div>
                                <p class="text-xl font-semibold text-tinta-900 leading-none">12</p>
                                <p class="text-xs text-tinta-400 mt-1">En producción</p>
                            </div>
                        </div>

                        <!-- Botones -->
                        <div class="flex gap-2">
                            <span class="rounded-lg px-4 py-2 text-sm font-semibold"
                                style="background: var(--p); color: var(--p-texto);">Guardar</span>
                            <span class="rounded-lg px-4 py-2 text-sm font-semibold border-2 bg-white"
                                style="border-color: var(--p); color: var(--p);">Cancelar</span>
                        </div>

                        <!-- Enlace y badge -->
                        <div class="flex items-center gap-3">
                            <span class="text-sm font-medium" style="color: var(--p)">Ver todas →</span>
                            <span class="text-xs font-medium rounded-full px-2.5 py-0.5"
                                style="background: var(--p-medio); color: var(--p-oscuro);">OP-045</span>
                        </div>
                    </div>
                </div>

                <p class="text-xs text-tinta-300">
                    El color se aplica a toda la plataforma: encabezado, menú, botones, enlaces,
                    etiquetas y la barra de carga.
                </p>
            </div>

            <!-- ── Título de la pestaña ───────────────────────────────────── -->
            <form @submit.prevent="guardar" class="bg-white rounded-xl border border-linea p-4 space-y-3">
                <div>
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Título de la pestaña</p>
                    <p class="text-xs text-tinta-400 mt-1">
                        Lo que se lee en la pestaña del navegador y en la vista previa de los
                        enlaces que compartes por WhatsApp.
                    </p>
                </div>

                <input v-model="form.titulo" type="text" :class="ic()"/>
                <p v-if="form.errors.titulo" class="text-red-500 text-xs">{{ form.errors.titulo }}</p>

                <div class="text-xs text-tinta-400 space-y-1">
                    <p>
                        <code class="bg-tinta-100 rounded px-1 py-0.5">{pagina}</code> se reemplaza por
                        la pantalla en la que estés.
                        <code class="bg-tinta-100 rounded px-1 py-0.5">{empresa}</code>, por
                        "{{ marca.empresa }}".
                    </p>
                </div>

                <!-- Cómo se vería la pestaña -->
                <div class="rounded-lg border border-linea bg-tinta-50 p-3">
                    <div class="inline-flex items-center gap-2 rounded-t-lg bg-white px-3 py-2 shadow-sm max-w-full">
                        <img v-if="faviconUrl" :src="faviconUrl" class="w-4 h-4 rounded shrink-0" alt=""/>
                        <span v-else class="w-4 h-4 rounded shrink-0" :style="{ backgroundColor: form.color }"></span>
                        <span class="text-xs text-tinta-700 truncate">{{ tituloEjemplo }}</span>
                        <span class="text-tinta-200 text-xs">✕</span>
                    </div>
                </div>

                <!-- ── Tipografía ─────────────────────────────────────────── -->
                <div class="pt-4 border-t border-linea">
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Tipografía</p>
                    <p class="text-xs text-tinta-400 mt-1">
                        La familia que se usa en toda la interfaz y en los documentos. Ninguna
                        descarga archivos: son las que ya trae cada dispositivo, así que el
                        sistema no depende de servidores ajenos para verse bien.
                    </p>

                    <div class="mt-3 space-y-2">
                        <label
                            v-for="f in fuentes"
                            :key="f.clave"
                            class="flex items-start gap-3 rounded-lg border p-3 cursor-pointer transition-colors"
                            :class="form.fuente === f.clave
                                ? 'border-[var(--marca)] bg-[var(--marca-suave)]'
                                : 'border-linea hover:border-tinta-200'"
                        >
                            <input type="radio" v-model="form.fuente" :value="f.clave" class="mt-1 shrink-0"/>
                            <span class="min-w-0">
                                <span class="block text-sm font-semibold text-tinta-900">{{ f.nombre }}</span>
                                <span class="block text-xs text-tinta-400 mt-0.5">{{ f.nota }}</span>
                                <!-- Se dibuja con la pila real, así se ve tal como quedaría -->
                                <span class="block mt-2 text-tinta-900" :style="{ fontFamily: f.pila }">
                                    Cotización 1042 · Cuarto frío 3×3 · $12.480.000
                                </span>
                            </span>
                        </label>
                    </div>

                    <p v-if="form.errors.fuente" class="text-red-500 text-xs mt-2">{{ form.errors.fuente }}</p>
                </div>

                <div class="flex items-center gap-3 pt-1">
                    <button type="submit" :disabled="form.processing"
                        class="rounded-lg px-4 py-2 text-sm font-semibold text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-50">
                        {{ form.processing ? 'Guardando...' : 'Guardar color y título' }}
                    </button>
                    <Transition enter-active-class="transition" enter-from-class="opacity-0"
                        leave-active-class="transition" leave-to-class="opacity-0">
                        <p v-if="form.recentlySuccessful" class="text-xs text-green-600 font-medium">
                            Guardado. Recarga para verlo aplicado.
                        </p>
                    </Transition>
                </div>
            </form>

            <!-- ── Favicon ────────────────────────────────────────────────── -->
            <div class="bg-white rounded-xl border border-linea p-4 space-y-3">
                <div>
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Favicon</p>
                    <p class="text-xs text-tinta-400 mt-1">
                        El ícono de la pestaña. Un PNG cuadrado de 512×512 se ve bien en todas
                        partes, incluido el ícono de la app instalada en el celular.
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    <div class="w-14 h-14 rounded-xl border border-linea bg-tinta-50 flex items-center justify-center shrink-0 overflow-hidden p-1">
                        <img v-if="faviconUrl" :src="faviconUrl" class="max-w-full max-h-full object-contain" alt="Favicon"/>
                        <span v-else class="text-[10px] text-tinta-300 text-center px-1">Por defecto</span>
                    </div>

                    <div class="flex-1 flex flex-wrap gap-2">
                        <button type="button" @click="abrirSelector('favicon')" :disabled="subiendo.favicon"
                            class="rounded-lg border border-tinta-200 px-4 py-2 text-sm font-semibold text-tinta-700 hover:bg-tinta-50 disabled:opacity-50">
                            {{ subiendo.favicon ? 'Subiendo...' : 'Subir imagen' }}
                        </button>
                        <button v-if="faviconUrl" type="button" @click="quitarImagen('favicon')"
                            class="rounded-lg px-4 py-2 text-sm font-semibold text-red-600 hover:bg-red-50">
                            Quitar
                        </button>
                    </div>

                    <input id="input-favicon" type="file" class="hidden"
                        accept="image/png,image/x-icon,image/svg+xml,image/jpeg"
                        @change="e => subirImagen(e, 'favicon')"/>
                </div>

                <p v-if="errores.favicon" class="text-red-500 text-xs">{{ errores.favicon }}</p>

                <p class="text-xs text-tinta-300">
                    El archivo se guarda con un nombre distinto en cada subida, así que el
                    navegador lo vuelve a bajar y no se queda con el anterior en caché.
                </p>
            </div>

            <!-- ── Logo ───────────────────────────────────────────────────── -->
            <div class="bg-white rounded-xl border border-linea p-4 space-y-3">
                <div>
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Logo</p>
                    <p class="text-xs text-tinta-400 mt-1">
                        Sale en el encabezado, en el menú lateral y en la vista previa de los
                        enlaces que compartes. Un PNG con fondo transparente es lo que mejor
                        queda. Máximo 2 MB.
                        Recomendado: <strong>400 × 120 px</strong> (horizontal). Se muestra a
                        unos 36 px de alto sin recortarse, y ese tamaño lo mantiene nítido en
                        pantallas de alta resolución.
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    <div class="w-28 h-14 rounded-xl border border-linea bg-tinta-50 flex items-center justify-center shrink-0 overflow-hidden p-1">
                        <img v-if="logoUrl" :src="logoUrl" class="max-w-full max-h-full object-contain" alt="Logo"/>
                        <span v-else class="text-[10px] text-tinta-300 text-center px-1">Por defecto</span>
                    </div>

                    <div class="flex-1 flex flex-wrap gap-2">
                        <button type="button" @click="abrirSelector('logo')" :disabled="subiendo.logo"
                            class="rounded-lg border border-tinta-200 px-4 py-2 text-sm font-semibold text-tinta-700 hover:bg-tinta-50 disabled:opacity-50">
                            {{ subiendo.logo ? 'Subiendo...' : 'Subir logo' }}
                        </button>
                        <button v-if="logoUrl" type="button" @click="quitarImagen('logo')"
                            class="rounded-lg px-4 py-2 text-sm font-semibold text-red-600 hover:bg-red-50">
                            Quitar
                        </button>
                    </div>

                    <input id="input-logo" type="file" class="hidden"
                        accept="image/png,image/svg+xml,image/jpeg,image/webp"
                        @change="e => subirImagen(e, 'logo')"/>
                </div>

                <p v-if="errores.logo" class="text-red-500 text-xs">{{ errores.logo }}</p>
            </div>
        </div>
    </AppLayout>
</template>
