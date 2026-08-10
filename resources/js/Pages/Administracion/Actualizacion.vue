<script setup>
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

/**
 * Actualizar el sistema.
 *
 * Los pasos se llaman uno tras otro desde aquí, y las tandas de extracción y copia se
 * repiten hasta terminar. Se hace en el navegador y no en el servidor porque en un
 * hosting compartido el límite de ejecución son treinta segundos: cada tanda cabe
 * sobrada, y si una falla el proceso se detiene con un mensaje concreto en vez de
 * morir con una pantalla en blanco a mitad de camino.
 */
const props = defineProps({
    licencia:     { type: Object, default: () => ({}) },
    comprobacion: { type: Object, default: () => ({}) },
    en_curso:     { type: Boolean, default: false },
    progreso:     { type: Object, default: () => ({}) },
})

const serial     = ref('')
const guardando  = ref(false)
const trabajando = ref(false)
const terminado  = ref(false)
const error      = ref('')

// Lo que se le muestra mientras trabaja.
const paso   = ref('')
const avance = ref(0)   // 0 a 100
const detalle= ref('')

const nueva = computed(() => props.licencia?.actualizacion ?? null)
const puede = computed(() => props.comprobacion?.puede !== false)

function csrf() {
    return decodeURIComponent(
        document.cookie.split('; ').find(c => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? ''
    )
}

async function pedir(ruta, datos = {}) {
    const resp = await fetch(`/administracion/actualizacion/${ruta}`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-XSRF-TOKEN': csrf(),
        },
        body: JSON.stringify(datos),
    })

    return await resp.json().catch(() => ({ ok: false, mensaje: 'El servidor respondió algo que no se entiende.' }))
}

async function guardarSerial() {
    guardando.value = true
    error.value = ''

    const r = await pedir('serial', { serial: serial.value })

    guardando.value = false

    if (!r.ok) {
        error.value = r.mensaje ?? 'No se pudo verificar el serial.'
        return
    }

    router.reload()
}

async function comprobarAhora() {
    trabajando.value = true
    paso.value = 'Consultando al servidor…'
    error.value = ''

    await pedir('comprobar')

    trabajando.value = false
    paso.value = ''
    router.reload()
}

/** El proceso completo, paso a paso. Si algo falla, se detiene aquí mismo. */
async function actualizar() {
    if (!confirm(
        'La actualización va a reemplazar los archivos del sistema.\n\n'
        + 'Antes se respalda la base de datos. Mientras dure, es mejor que nadie más '
        + 'esté trabajando.\n\n¿Continuamos?'
    )) return

    trabajando.value = true
    terminado.value = false
    error.value = ''

    try {
        // 1 · Descargar
        paso.value = 'Descargando la versión nueva…'
        detalle.value = 'Son unos 60 MB. Puede tardar.'
        avance.value = 5

        const descarga = await pedir('descargar')
        if (!descarga.ok) throw new Error(descarga.mensaje)

        // 2 · Respaldar: sin esto no se sigue
        paso.value = 'Respaldando la base de datos…'
        detalle.value = 'Es la vuelta atrás si algo sale mal.'
        avance.value = 15

        const respaldo = await pedir('respaldar')
        if (!respaldo.ok) throw new Error(respaldo.mensaje)
        detalle.value = 'Respaldo guardado: ' + respaldo.respaldo

        // 3 · Extraer por tandas
        paso.value = 'Preparando los archivos…'
        avance.value = 20

        let listo = false
        while (!listo) {
            const r = await pedir('extraer')
            if (!r.ok) throw new Error(r.mensaje)

            listo = r.listo
            avance.value = 20 + Math.round((r.extraidos / r.total) * 40)
            detalle.value = `${r.extraidos.toLocaleString()} de ${r.total.toLocaleString()} archivos`
        }

        // 4 · Copiar por tandas
        paso.value = 'Aplicando la actualización…'
        avance.value = 60
        listo = false

        while (!listo) {
            const r = await pedir('copiar')
            if (!r.ok) throw new Error(r.mensaje)

            listo = r.listo
            avance.value = 60 + Math.round((r.copiados / r.total) * 30)
            detalle.value = `${r.copiados.toLocaleString()} de ${r.total.toLocaleString()} archivos`
        }

        // 5 · Migrar y cerrar
        paso.value = 'Actualizando la base de datos…'
        detalle.value = 'Último paso.'
        avance.value = 92

        const fin = await pedir('finalizar')
        if (!fin.ok) throw new Error(fin.mensaje)

        avance.value = 100
        paso.value = 'Listo'
        terminado.value = true
        detalle.value = 'Ahora en la versión ' + fin.version
    } catch (e) {
        error.value = e.message ?? 'Algo falló durante la actualización.'
    } finally {
        trabajando.value = false
    }
}

async function cancelarProceso() {
    await pedir('cancelar')
    router.reload()
}
</script>

<template>
    <AppLayout title="Actualizar el sistema">
        <div class="max-w-2xl mx-auto space-y-4 pb-8">

            <a href="/configuracion" @click.prevent="router.visit('/configuracion')"
                class="inline-flex items-center gap-1.5 text-sm text-tinta-400 hover:text-tinta-700">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                </svg>
                Configuración
            </a>

            <!-- ── Versión y licencia ─────────────────────────────────────── -->
            <div class="bg-superficie rounded-xl border border-linea p-4">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Versión instalada</p>
                        <p class="text-2xl font-semibold text-tinta-900 mt-1">{{ licencia.version ?? 'sin determinar' }}</p>
                        <p v-if="licencia.vence_el" class="text-xs text-tinta-400 mt-1.5">
                            Suscripción {{ licencia.al_dia ? 'al día' : 'vencida' }} · vence el {{ licencia.vence_el }}
                        </p>
                    </div>

                    <button
                        type="button"
                        @click="comprobarAhora"
                        :disabled="trabajando"
                        class="shrink-0 rounded-lg border border-tinta-200 px-3.5 py-2 text-sm font-semibold text-tinta-700 hover:bg-tinta-50 disabled:opacity-50"
                    >Comprobar ahora</button>
                </div>
            </div>

            <!-- ── Sin serial ─────────────────────────────────────────────── -->
            <div v-if="licencia.estado === 'sin_serial'" class="bg-superficie rounded-xl border border-linea p-4 space-y-3">
                <div>
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Serial</p>
                    <p class="text-xs text-tinta-400 mt-1">
                        Es el código que entrega Briela con la suscripción. Sin él, esta
                        instalación no puede recibir actualizaciones ni usar el asistente.
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <input
                        v-model="serial"
                        type="text"
                        placeholder="BRL-XXXX-XXXX-XXXX"
                        class="flex-1 min-w-[220px] rounded-lg border border-tinta-200 px-3 py-2 text-sm font-mono uppercase bg-superficie focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]"
                    />
                    <button
                        type="button"
                        @click="guardarSerial"
                        :disabled="guardando || !serial"
                        class="rounded-lg px-4 py-2 text-sm font-semibold disabled:opacity-50"
                        :style="{ background: 'var(--marca)', color: 'var(--marca-texto)' }"
                    >{{ guardando ? 'Verificando…' : 'Guardar' }}</button>
                </div>
            </div>

            <!-- ── Requisitos que no se cumplen ───────────────────────────── -->
            <div v-if="!puede" class="rounded-xl border border-red-200 bg-red-50 p-4">
                <p class="text-sm font-semibold text-red-700">El servidor no está listo para actualizar</p>
                <ul class="mt-2 space-y-1">
                    <li v-for="p in comprobacion.problemas" :key="p" class="text-sm text-red-700">· {{ p }}</li>
                </ul>
            </div>

            <div v-else-if="comprobacion.avisos?.length" class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                <ul class="space-y-1">
                    <li v-for="a in comprobacion.avisos" :key="a" class="text-sm text-amber-800">· {{ a }}</li>
                </ul>
            </div>

            <!-- ── Un proceso a medias de antes ───────────────────────────── -->
            <div v-if="en_curso && !trabajando" class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                <p class="text-sm font-semibold text-amber-800">Hay una actualización a medio aplicar</p>
                <p class="text-sm text-amber-800 mt-1">
                    Se quedó en «{{ progreso.fase }}» de la versión {{ progreso.version }}. Puedes
                    volver a intentarlo o descartarla para empezar de nuevo.
                </p>
                <button type="button" @click="cancelarProceso"
                    class="mt-2 text-sm font-semibold text-amber-900 underline underline-offset-2">
                    Descartar y empezar de nuevo
                </button>
            </div>

            <!-- ── La versión nueva ───────────────────────────────────────── -->
            <div v-if="nueva" class="bg-superficie rounded-xl border-2 p-4 space-y-3"
                 :style="{ borderColor: 'var(--marca)' }">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.12em]" :style="{ color: 'var(--marca)' }">
                            Versión disponible
                        </p>
                        <p class="text-2xl font-semibold text-tinta-900 mt-1">{{ nueva.version }}</p>
                    </div>
                    <span v-if="nueva.obligatoria"
                        class="shrink-0 text-xs font-semibold px-2 py-1 rounded bg-red-50 text-red-700">
                        Obligatoria
                    </span>
                </div>

                <p v-if="nueva.notas" class="text-sm text-tinta-500 whitespace-pre-line leading-relaxed">{{ nueva.notas }}</p>

                <div class="rounded-lg bg-tinta-50 p-3 text-xs text-tinta-500 leading-relaxed">
                    Antes de reemplazar nada se respalda la base de datos. No se tocan la
                    configuración, los archivos subidos ni las imágenes. Mientras dure, es
                    mejor que nadie más esté trabajando en el sistema.
                </div>

                <button
                    type="button"
                    @click="actualizar"
                    :disabled="trabajando || !puede"
                    class="w-full py-3 rounded-lg font-semibold transition-all active:scale-[.99] disabled:opacity-50"
                    :style="{ background: 'var(--marca)', color: 'var(--marca-texto)' }"
                >{{ trabajando ? 'Actualizando…' : 'Actualizar ahora' }}</button>
            </div>

            <div v-else-if="licencia.estado !== 'sin_serial' && !trabajando && !terminado"
                 class="bg-superficie rounded-xl border border-linea p-4">
                <p class="text-sm text-tinta-500">
                    El sistema está al día. Cuando haya una versión nueva, aparecerá aquí.
                </p>
            </div>

            <!-- ── Avance ─────────────────────────────────────────────────── -->
            <div v-if="trabajando || terminado" class="bg-superficie rounded-xl border border-linea p-4 space-y-3">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-sm font-semibold text-tinta-900">{{ paso }}</p>
                    <span class="text-sm font-semibold tabular-nums" :style="{ color: 'var(--marca)' }">{{ avance }} %</span>
                </div>

                <div class="h-2 rounded-full bg-tinta-100 overflow-hidden">
                    <div class="h-full rounded-full transition-all duration-300"
                         :style="{ width: avance + '%', background: 'var(--marca)' }"></div>
                </div>

                <p v-if="detalle" class="text-xs text-tinta-400">{{ detalle }}</p>

                <p v-if="terminado" class="text-sm text-emerald-700 font-medium">
                    Actualización terminada. Recarga la página para ver la versión nueva.
                </p>
            </div>

            <!-- ── Error ──────────────────────────────────────────────────── -->
            <div v-if="error" class="rounded-xl border border-red-200 bg-red-50 p-4">
                <p class="text-sm font-semibold text-red-700">La actualización se detuvo</p>
                <p class="text-sm text-red-700 mt-1">{{ error }}</p>
                <p class="text-xs text-red-600 mt-2">
                    La base de datos quedó respaldada antes de empezar. Si el sistema no
                    funciona bien, escríbenos con este mensaje.
                </p>
            </div>
        </div>
    </AppLayout>
</template>
