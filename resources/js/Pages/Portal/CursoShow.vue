<script setup>
import PortalLayout from '@/Layouts/PortalLayout.vue'
import { router } from '@inertiajs/vue3'
import { ref, reactive, computed } from 'vue'

const props = defineProps({
    curso:             Object,
    inscripcion:       Object,
    progreso:          Object,
    porcentaje:        Number,
    tieneEvaluacion:   { type: Boolean, default: false },
    tieneCertificado:  { type: Boolean, default: false },
    rutaBase:          String,
    esColaborador:     Boolean,
})

const porcentajeActual = ref(props.porcentaje)
const completadas = reactive({ ...props.progreso })

// Solo módulos desbloqueados aportan lecciones navegables/reproducibles.
const leccionesDisponibles = computed(() => props.curso.modulos.filter(m => !m.bloqueado).flatMap(m => m.lecciones))

const leccionActual = ref(
    leccionesDisponibles.value.find(l => !completadas[l.id]) ?? leccionesDisponibles.value[0] ?? null
)

function seleccionarLeccion(leccion, modulo) {
    if (modulo.bloqueado) return
    leccionActual.value = leccion
}

function driveId(url) {
    const m = url.match(/\/d\/([a-zA-Z0-9_-]+)/) || url.match(/[?&]id=([a-zA-Z0-9_-]+)/)
    return m ? m[1] : null
}

function embedUrl(leccion) {
    if (!leccion) return ''
    if (leccion.tipo === 'video_drive' || leccion.tipo === 'pdf') {
        const id = driveId(leccion.contenido)
        return id ? `https://drive.google.com/file/d/${id}/preview` : leccion.contenido
    }
    if (leccion.tipo === 'video_externo') {
        const url = leccion.contenido
        let m = url.match(/(?:youtu\.be\/|youtube\.com\/watch\?v=|youtube\.com\/embed\/)([a-zA-Z0-9_-]+)/)
        if (m) return `https://www.youtube.com/embed/${m[1]}`
        m = url.match(/loom\.com\/share\/([a-zA-Z0-9]+)/)
        if (m) return `https://www.loom.com/embed/${m[1]}`
        return url
    }
    return ''
}

const csrf    = () => { const c = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN=')); return c ? decodeURIComponent(c.split('=')[1]) : '' }
const jsonHdr = () => ({ 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' })

const marcando = ref(false)

async function marcarCompletada() {
    if (!leccionActual.value || completadas[leccionActual.value.id] || marcando.value) return
    marcando.value = true
    try {
        const res = await fetch(`${props.rutaBase}/${props.curso.id}/lecciones/${leccionActual.value.id}`, {
            method: 'POST', headers: jsonHdr(),
        })
        if (!res.ok) throw new Error()
        const data = await res.json()
        completadas[leccionActual.value.id] = true
        porcentajeActual.value = data.porcentaje
    } catch {}
    finally { marcando.value = false }
}

function siguienteLeccion() {
    const idx = leccionesDisponibles.value.findIndex(l => l.id === leccionActual.value?.id)
    if (idx !== -1 && idx < leccionesDisponibles.value.length - 1) {
        leccionActual.value = leccionesDisponibles.value[idx + 1]
    }
}

function irAEvaluacionModulo(modulo) {
    router.visit(`${props.rutaBase}/${props.curso.id}/modulos/${modulo.id}/evaluacion`)
}

const tipoLabel = (v) => ({ video_drive: 'Video', video_externo: 'Video', texto: 'Lectura', pdf: 'PDF' }[v] ?? v)
</script>

<template>
    <PortalLayout :title="curso.titulo" :es-colaborador="esColaborador">
        <div class="space-y-4">

            <!-- Barra de progreso del curso -->
            <div class="bg-superficie rounded-2xl shadow-sm p-4">
                <div class="flex items-center justify-between mb-2">
                    <p class="text-sm font-semibold text-tinta-900 truncate">{{ curso.titulo }}</p>
                    <span class="text-xs font-semibold shrink-0" style="color:var(--marca);">{{ porcentajeActual }}%</span>
                </div>
                <div class="h-2 rounded-full bg-tinta-100 overflow-hidden">
                    <div class="h-full rounded-full transition-all" :style="`width:${porcentajeActual}%;background:var(--marca);`"></div>
                </div>

                <a v-if="tieneCertificado" :href="`${rutaBase}/${curso.id}/certificado`"
                    class="mt-3 flex items-center justify-center gap-2 w-full py-2.5 rounded-xl text-sm font-semibold text-white" style="background:var(--marca);">
                    Descargar certificado
                </a>
                <button v-else-if="tieneEvaluacion && porcentajeActual >= 100" @click="router.visit(`${rutaBase}/${curso.id}/evaluacion`)"
                    class="mt-3 w-full py-2.5 rounded-xl text-sm font-semibold text-white" style="background:var(--marca);">
                    Realizar evaluación
                </button>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">

                <!-- Sidebar módulos/lecciones -->
                <div class="md:col-span-1 order-2 md:order-1 space-y-3">
                    <div v-for="modulo in curso.modulos" :key="modulo.id" class="bg-superficie rounded-2xl shadow-sm overflow-hidden" :class="{ 'opacity-60': modulo.bloqueado }">
                        <div class="flex items-center gap-2 px-4 py-2.5 border-b border-separador">
                            <svg v-if="modulo.bloqueado" class="w-3.5 h-3.5 text-tinta-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                            </svg>
                            <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] flex-1 truncate">{{ modulo.nombre }}</p>
                        </div>

                        <p v-if="modulo.bloqueado" class="px-4 py-2.5 text-xs text-tinta-300">
                            Aprueba la evaluación del módulo anterior para desbloquear.
                        </p>

                        <template v-else>
                            <ul class="divide-y divide-separador">
                                <li v-for="leccion in modulo.lecciones" :key="leccion.id">
                                    <button @click="seleccionarLeccion(leccion, modulo)"
                                        class="w-full flex items-center gap-2.5 px-4 py-2.5 text-left transition-colors"
                                        :class="leccionActual?.id === leccion.id ? 'bg-pastel-azul' : 'hover:bg-tinta-50'">
                                        <div class="w-5 h-5 rounded-full flex items-center justify-center shrink-0"
                                            :style="completadas[leccion.id] ? 'background:var(--pastel-verde);' : 'background:var(--superficie-2);'">
                                            <svg v-if="completadas[leccion.id]" class="w-3 h-3 text-aviso-verde" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                            </svg>
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <p class="text-xs font-medium truncate" :class="leccionActual?.id === leccion.id ? 'text-aviso-azul' : 'text-tinta-700'">{{ leccion.nombre }}</p>
                                            <p class="text-[11px] text-tinta-300">{{ tipoLabel(leccion.tipo) }}<span v-if="leccion.duracion_minutos"> · {{ leccion.duracion_minutos }} min</span></p>
                                        </div>
                                    </button>
                                </li>
                            </ul>

                            <button v-if="modulo.tiene_evaluacion && modulo.completado && !modulo.evaluacion_aprobada"
                                @click="irAEvaluacionModulo(modulo)"
                                class="w-full py-2.5 text-xs font-semibold text-center text-white transition-colors" style="background:var(--marca);">
                                Tomar evaluación del módulo
                            </button>
                        </template>
                    </div>
                </div>

                <!-- Panel principal / reproductor -->
                <div class="md:col-span-2 order-1 md:order-2">
                    <div v-if="leccionActual" class="bg-superficie rounded-2xl shadow-sm overflow-hidden">

                        <div v-if="leccionActual.tipo === 'video_drive' || leccionActual.tipo === 'video_externo'" class="aspect-video bg-black">
                            <iframe :src="embedUrl(leccionActual)" class="w-full h-full" frameborder="0" allow="autoplay; fullscreen" allowfullscreen></iframe>
                        </div>

                        <div v-else-if="leccionActual.tipo === 'pdf'" class="aspect-[3/4] md:aspect-video bg-tinta-100">
                            <iframe :src="embedUrl(leccionActual)" class="w-full h-full" frameborder="0"></iframe>
                        </div>

                        <div v-else-if="leccionActual.tipo === 'texto'" class="p-5 prose prose-sm max-w-none" v-html="leccionActual.contenido"></div>

                        <div class="p-4 border-t border-linea">
                            <p class="text-sm font-semibold text-tinta-900 mb-3">{{ leccionActual.nombre }}</p>
                            <div class="flex gap-2">
                                <button v-if="!completadas[leccionActual.id]" @click="marcarCompletada" :disabled="marcando"
                                    class="flex-1 py-2.5 rounded-xl text-sm font-semibold text-white disabled:opacity-50" style="background:var(--marca);">
                                    {{ marcando ? 'Guardando...' : 'Marcar como completada' }}
                                </button>
                                <button v-else class="flex-1 py-2.5 rounded-xl text-sm font-semibold text-aviso-verde bg-pastel-verde cursor-default">
                                    ✓ Completada
                                </button>
                                <button v-if="leccionesDisponibles.findIndex(l => l.id === leccionActual.id) < leccionesDisponibles.length - 1"
                                    @click="siguienteLeccion"
                                    class="px-4 py-2.5 rounded-xl text-sm font-semibold text-tinta-500 border border-linea hover:bg-tinta-50">
                                    Siguiente
                                </button>
                            </div>
                        </div>
                    </div>

                    <div v-else class="bg-superficie rounded-2xl shadow-sm py-16 text-center text-tinta-300">
                        <p class="text-sm">Este curso aún no tiene lecciones.</p>
                    </div>
                </div>

            </div>

        </div>
    </PortalLayout>
</template>
