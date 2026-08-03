<script setup>
import PortalLayout from '@/Layouts/PortalLayout.vue'
import { router } from '@inertiajs/vue3'
import { computed } from 'vue'

const props = defineProps({
    pendientesObligatorios: { type: Array, default: () => [] },
    enProgreso:             { type: Array, default: () => [] },
    completados:            { type: Array, default: () => [] },
    catalogo:               { type: Array, default: () => [] },
    rutaBase:               { type: String, default: '/mi-capacitacion' },
    esColaborador:          { type: Boolean, default: false },
    nombreEstudiante:       { type: String, default: '' },
})

function irACurso(cursoId) {
    router.visit(`${props.rutaBase}/${cursoId}`)
}

function diasRestantes(fecha) {
    if (!fecha) return null
    const dias = Math.ceil((new Date(fecha) - new Date()) / 86400000)
    return dias
}

const inicial = (t) => (t ?? '?').charAt(0).toUpperCase()
</script>

<template>
    <PortalLayout title="Mi Capacitación" :es-colaborador="esColaborador">
        <div class="space-y-8">

            <div>
                <h1 class="text-xl font-bold text-gray-900">Hola{{ nombreEstudiante ? ', ' + nombreEstudiante : '' }} 👋</h1>
                <p class="text-sm text-gray-500 mt-0.5">Continúa tu formación en Interfrigo</p>
            </div>

            <!-- Pendientes obligatorios -->
            <section v-if="pendientesObligatorios.length > 0">
                <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-3">Pendientes</h2>
                <div class="space-y-3">
                    <div v-for="insc in pendientesObligatorios" :key="insc.id"
                        class="bg-white rounded-2xl shadow-sm p-4 flex items-center gap-3 cursor-pointer active:scale-[0.99] transition-transform"
                        @click="irACurso(insc.curso.id)">
                        <div class="w-11 h-11 rounded-xl flex items-center justify-center text-white font-bold shrink-0 overflow-hidden" style="background:#B91C1C;">
                            <img v-if="insc.curso.imagen_portada" :src="insc.curso.imagen_portada" class="w-full h-full object-cover" />
                            <span v-else>{{ inicial(insc.curso.titulo) }}</span>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold text-gray-900 truncate">{{ insc.curso.titulo }}</p>
                            <p class="text-xs text-gray-400 mt-0.5">
                                {{ insc.estado === 'en_progreso' ? `En progreso · ${insc.porcentaje}%` : 'Sin iniciar' }}
                            </p>
                        </div>
                        <span v-if="insc.fecha_limite" class="inline-block px-2 py-0.5 rounded-full text-xs font-semibold shrink-0"
                            :style="diasRestantes(insc.fecha_limite) <= 3 ? 'background:#FEE2E2;color:#B91C1C;' : 'background:#FEF3C7;color:#92400E;'">
                            {{ diasRestantes(insc.fecha_limite) >= 0 ? `${diasRestantes(insc.fecha_limite)}d restantes` : 'Vencido' }}
                        </span>
                    </div>
                </div>
            </section>

            <!-- En progreso -->
            <section v-if="enProgreso.length > 0">
                <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-3">En progreso</h2>
                <div class="space-y-3">
                    <div v-for="insc in enProgreso" :key="insc.id"
                        class="bg-white rounded-2xl shadow-sm p-4 cursor-pointer active:scale-[0.99] transition-transform"
                        @click="irACurso(insc.curso.id)">
                        <div class="flex items-center gap-3 mb-2">
                            <div class="w-11 h-11 rounded-xl flex items-center justify-center text-white font-bold shrink-0 overflow-hidden" style="background:var(--marca);">
                                <img v-if="insc.curso.imagen_portada" :src="insc.curso.imagen_portada" class="w-full h-full object-cover" />
                                <span v-else>{{ inicial(insc.curso.titulo) }}</span>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-semibold text-gray-900 truncate">{{ insc.curso.titulo }}</p>
                                <p class="text-xs text-gray-400">{{ insc.porcentaje }}% completado</p>
                            </div>
                        </div>
                        <div class="h-2 rounded-full bg-gray-100 overflow-hidden">
                            <div class="h-full rounded-full transition-all" :style="`width:${insc.porcentaje}%;background:var(--marca);`"></div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Catálogo disponible -->
            <section v-if="catalogo.length > 0">
                <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-3">Catálogo disponible</h2>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                    <div v-for="curso in catalogo" :key="curso.id"
                        class="bg-white rounded-2xl shadow-sm overflow-hidden">
                        <div class="aspect-video overflow-hidden" style="background:#F1F5F9;">
                            <img v-if="curso.imagen_portada" :src="curso.imagen_portada" :alt="curso.titulo" class="w-full h-full object-cover"/>
                            <div v-else class="w-full h-full flex items-center justify-center text-white text-2xl font-bold" style="background:var(--marca);">
                                {{ inicial(curso.titulo) }}
                            </div>
                        </div>
                        <div class="p-3">
                            <p class="text-sm font-semibold text-gray-900 line-clamp-2 mb-1">{{ curso.titulo }}</p>
                            <p v-if="curso.categoria" class="text-xs text-gray-400 mb-3">{{ curso.categoria }}</p>
                            <button @click="irACurso(curso.id)"
                                class="w-full py-2 rounded-xl text-xs font-semibold text-white" style="background:var(--marca);">
                                Inscribirme
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Completados -->
            <section v-if="completados.length > 0">
                <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-3">Completados</h2>
                <div class="space-y-2">
                    <div v-for="insc in completados" :key="insc.id"
                        class="bg-white rounded-2xl shadow-sm p-4 flex items-center gap-3">
                        <div class="w-9 h-9 rounded-full flex items-center justify-center shrink-0 cursor-pointer" style="background:#D1FAE5;" @click="irACurso(insc.curso.id)">
                            <svg class="w-5 h-5 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                            </svg>
                        </div>
                        <p class="text-sm font-semibold text-gray-900 flex-1 truncate cursor-pointer" @click="irACurso(insc.curso.id)">{{ insc.curso.titulo }}</p>
                        <a v-if="insc.tieneCertificado" :href="`${rutaBase}/${insc.curso.id}/certificado`" @click.stop
                            class="text-xs font-semibold px-3 py-1.5 rounded-lg text-white shrink-0" style="background:var(--marca);">
                            Certificado
                        </a>
                    </div>
                </div>
            </section>

            <!-- Vacío total -->
            <div v-if="!pendientesObligatorios.length && !enProgreso.length && !catalogo.length && !completados.length"
                class="bg-white rounded-2xl shadow-sm py-16 text-center text-gray-400">
                <p class="text-sm">No hay cursos disponibles por ahora.</p>
            </div>

        </div>
    </PortalLayout>
</template>
