<script setup>
/**
 * La ficha de verificación de una unidad.
 *
 * Es la pantalla donde de verdad se juzga: las medidas con las que se cotizó, la receta con la
 * que se armó, quién hizo cada paso y las fotos que dejó, y al lado la lista de puntos con su
 * observación y su evidencia. El tablero sirve para marcar rápido lo que ya se miró; esto es
 * para lo que hay que mirar despacio.
 */
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import RevisionCalidad from '@/Components/RevisionCalidad.vue'
import { formatCantidad } from '@/formato'

const props = defineProps({
    ficha: { type: Object, required: true },
    op:    { type: Object, default: () => ({}) },
    item:  { type: Object, default: () => ({}) },
    pasos: { type: Array,  default: () => [] },
})

const claseUrgencia = computed(() => ({
    vencida: 'bg-pastel-rojo-2 text-aviso-rojo',
    hoy:     'bg-pastel-ambar-2 text-aviso-ambar',
    alta:    'bg-pastel-ambar-2 text-aviso-ambar',
}[props.ficha.urgencia?.clave] ?? 'bg-tinta-100 text-tinta-400'))

const formatDuracion = (min) => {
    if (min === null || min === undefined) return '—'
    if (min < 60) return `${min} min`
    const h = Math.floor(min / 60), m = min % 60

    return m ? `${h}h ${m}min` : `${h}h`
}

const pasosCompletados = computed(() => props.pasos.filter(p => p.completado).length)
</script>

<template>
    <AppLayout :title="`Calidad · ${ficha.op_numero}`">

        <!-- ── Encabezado ───────────────────────────────────────────────────── -->
        <div class="flex items-start gap-3 flex-wrap mb-4">
            <button type="button" @click="router.visit('/calidad')"
                class="px-3 py-2 rounded-xl border border-linea text-sm text-tinta-500 hover:bg-realce transition-colors">
                ‹ Tablero
            </button>

            <div class="min-w-0">
                <div class="flex items-baseline gap-2 flex-wrap">
                    <h2 class="text-2xl font-black tabular-nums tracking-tight text-tinta-900">{{ ficha.op_numero }}</h2>
                    <span v-if="ficha.total_unidades > 1" class="text-xl font-black text-tinta-300 tabular-nums">
                        −{{ ficha.numero_unidad }}
                    </span>
                    <span class="text-base font-bold uppercase tracking-wide text-tinta-700">{{ ficha.titulo }}</span>
                </div>
                <p class="text-sm text-tinta-400 mt-0.5">
                    {{ op.cliente }}
                    <template v-if="ficha.total_unidades > 1">
                        · unidad {{ ficha.numero_unidad }} de {{ ficha.total_unidades }}
                    </template>
                </p>
            </div>

            <span v-if="ficha.urgencia?.etiqueta"
                class="text-[10px] font-bold uppercase tracking-[0.08em] px-2 py-1 rounded-full"
                :class="claseUrgencia">
                {{ ficha.urgencia.etiqueta }}
            </span>

            <div class="ml-auto flex items-center gap-2">
                <button type="button" @click="router.visit(`/produccion/ops/${ficha.op_id}`)"
                    class="px-3 py-2 rounded-xl border border-linea text-sm text-tinta-500 hover:bg-realce transition-colors">
                    Ver la orden
                </button>
                <button type="button" @click="router.visit(`/trabajos/${ficha.id}`)"
                    class="px-3 py-2 rounded-xl border border-linea text-sm text-tinta-500 hover:bg-realce transition-colors">
                    Hoja de producción
                </button>
            </div>
        </div>

        <div v-if="op.calidad_aprobada_at"
            class="bg-pastel-verde border border-borde-aviso-verde rounded-2xl px-4 py-3 mb-4">
            <p class="text-sm text-aviso-verde font-medium">
                Calidad aprobada el {{ op.calidad_aprobada_at }} — la orden ya se puede remisionar.
            </p>
        </div>

        <div v-if="op.motivo_rechazo"
            class="bg-pastel-rojo border border-borde-aviso-rojo rounded-2xl px-4 py-3 mb-4">
            <p class="text-xs font-semibold text-aviso-rojo uppercase tracking-[0.08em]">En reproceso</p>
            <p class="text-sm text-aviso-rojo mt-1">{{ op.motivo_rechazo }}</p>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

            <!-- ── Columna izquierda: la revisión ──────────────────────────── -->
            <div class="lg:col-span-2 space-y-4">
                <RevisionCalidad :checks="ficha.checks" base="/calidad" />

                <!-- Lo que pasó en planta. Sin esto, la revisión es a ciegas: la foto del
                     operario es la mitad de la evidencia. -->
                <div class="bg-superficie rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-linea flex items-center justify-between gap-2">
                        <h3 class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Cómo se fabricó</h3>
                        <span class="text-xs text-tinta-300">{{ pasosCompletados }}/{{ pasos.length }} pasos</span>
                    </div>

                    <div class="divide-y divide-separador">
                        <div v-for="paso in pasos" :key="paso.id" class="px-5 py-3">
                            <div class="flex items-start gap-3 flex-wrap">
                                <span class="w-5 h-5 rounded-full flex items-center justify-center shrink-0 mt-0.5"
                                    :class="paso.completado ? 'bg-green-500' : 'bg-tinta-200'">
                                    <svg v-if="paso.completado" class="w-3 h-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                    </svg>
                                </span>

                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium text-tinta-800">
                                        {{ paso.nombre }}
                                        <span v-if="paso.es_paso_final"
                                            class="text-[10px] px-1.5 py-0.5 rounded-full bg-pastel-violeta-2 text-aviso-violeta ml-1">entrega</span>
                                    </p>
                                    <p v-if="paso.descripcion" class="text-xs text-tinta-400 mt-0.5">{{ paso.descripcion }}</p>
                                    <p class="text-xs text-tinta-300 mt-1">
                                        <template v-if="paso.operarios.length">{{ paso.operarios.join(' · ') }}</template>
                                        <template v-else-if="paso.operario">{{ paso.operario }}</template>
                                        <template v-else>Sin operario registrado</template>
                                        <template v-if="paso.completado_at"> · {{ paso.completado_at }}</template>
                                        <template v-if="paso.duracion !== null"> · {{ formatDuracion(paso.duracion) }}</template>
                                    </p>

                                    <div v-if="paso.fotos.length" class="flex flex-wrap gap-2 mt-2">
                                        <a v-for="(foto, i) in paso.fotos" :key="i" :href="foto" target="_blank" rel="noopener">
                                            <img :src="foto" alt="Foto del paso"
                                                class="w-16 h-16 rounded-lg object-cover border border-linea" />
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <p v-if="! pasos.length" class="px-5 py-6 text-xs text-tinta-300 italic">
                            Esta unidad no tiene pasos registrados.
                        </p>
                    </div>
                </div>
            </div>

            <!-- ── Columna derecha: el proyecto ────────────────────────────── -->
            <div class="space-y-4">

                <!-- Las medidas de ESTA unidad: es contra esto que se mide -->
                <div class="bg-superficie rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-linea">
                        <h3 class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Medidas de esta unidad</h3>
                    </div>
                    <div v-if="ficha.variables.length" class="divide-y divide-separador">
                        <div v-for="v in ficha.variables" :key="v.clave"
                            class="px-5 py-2.5 flex items-center justify-between gap-3">
                            <span class="text-xs text-tinta-400 truncate">{{ v.etiqueta }}</span>
                            <span class="text-sm font-semibold text-tinta-900 tabular-nums shrink-0">{{ v.valor }}</span>
                        </div>
                    </div>
                    <p v-else class="px-5 py-4 text-xs text-tinta-300 italic">
                        Este ensamble no se fabrica por medidas.
                    </p>
                </div>

                <!-- La orden -->
                <div class="bg-superficie rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-linea">
                        <h3 class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">La orden</h3>
                    </div>
                    <div class="divide-y divide-separador text-sm">
                        <div class="px-5 py-2.5 flex justify-between gap-3">
                            <span class="text-xs text-tinta-400">Cliente</span>
                            <span class="text-tinta-800 text-right">{{ op.cliente ?? '—' }}</span>
                        </div>
                        <div class="px-5 py-2.5 flex justify-between gap-3">
                            <span class="text-xs text-tinta-400">Responsable</span>
                            <span class="text-tinta-800 text-right">{{ op.responsable ?? '—' }}</span>
                        </div>
                        <div class="px-5 py-2.5 flex justify-between gap-3">
                            <span class="text-xs text-tinta-400">Creada</span>
                            <span class="text-tinta-800 tabular-nums">{{ op.fecha_creacion ?? '—' }}</span>
                        </div>
                        <div class="px-5 py-2.5 flex justify-between gap-3">
                            <span class="text-xs text-tinta-400">Entrega</span>
                            <span class="text-tinta-800 tabular-nums">{{ op.fecha_entrega ?? '—' }}</span>
                        </div>
                        <div class="px-5 py-2.5 flex justify-between gap-3">
                            <span class="text-xs text-tinta-400">Estado</span>
                            <span class="text-tinta-800 capitalize">{{ (op.estado ?? '').replace('_', ' ') }}</span>
                        </div>
                        <div v-if="item.numero_serie" class="px-5 py-2.5 flex justify-between gap-3">
                            <span class="text-xs text-tinta-400">Número de serie</span>
                            <span class="text-tinta-800 font-mono text-xs">{{ item.numero_serie }}</span>
                        </div>
                    </div>
                </div>

                <!-- Lo que pidió el cliente, tal cual quedó escrito -->
                <div v-if="item.descripcion_larga || item.notas || op.condiciones"
                    class="bg-superficie rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-linea">
                        <h3 class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Especificaciones</h3>
                    </div>
                    <div class="px-5 py-3 space-y-3 text-sm text-tinta-600">
                        <p v-if="item.descripcion_larga" class="whitespace-pre-line">{{ item.descripcion_larga }}</p>
                        <p v-if="item.notas" class="whitespace-pre-line text-tinta-500">{{ item.notas }}</p>
                        <p v-if="op.condiciones" class="whitespace-pre-line text-xs text-tinta-400">{{ op.condiciones }}</p>
                    </div>
                </div>

                <!-- Imágenes de la instancia -->
                <div v-if="item.imagenes?.length" class="bg-superficie rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-linea">
                        <h3 class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Planos e imágenes</h3>
                    </div>
                    <div class="p-4 grid grid-cols-2 gap-2">
                        <a v-for="(img, i) in item.imagenes" :key="i" :href="img" target="_blank" rel="noopener">
                            <img :src="img" alt="Imagen del proyecto"
                                class="w-full h-24 object-cover rounded-lg border border-linea" />
                        </a>
                    </div>
                </div>

                <!-- La receta congelada: lo que se supone que lleva adentro -->
                <div v-if="item.componentes?.length" class="bg-superficie rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-5 py-3 border-b border-linea">
                        <h3 class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Materiales de la receta</h3>
                    </div>
                    <div class="divide-y divide-separador max-h-80 overflow-y-auto">
                        <div v-for="(c, i) in item.componentes" :key="i"
                            class="px-5 py-2 flex items-center justify-between gap-3">
                            <span class="text-xs text-tinta-500 truncate">{{ c.nombre }}</span>
                            <span class="text-xs text-tinta-700 tabular-nums shrink-0">
                                {{ c.cantidad !== null ? formatCantidad(c.cantidad) : '—' }}
                                <span class="text-tinta-300">{{ c.unidad }}</span>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
