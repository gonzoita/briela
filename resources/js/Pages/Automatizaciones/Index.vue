<script setup>
/**
 * Automatizaciones: «cuando pase esto, haz aquello».
 *
 * Al abrir pide tres cosas —la lista, el catálogo y las aprobaciones que esperan a esta persona— y
 * nada más; el editor y el historial se montan solo cuando se abren. Una automatización nace
 * apagada: se prueba con datos reales sin tocar nada y recién entonces se enciende.
 */
import { ref, computed, onMounted } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import EditorAutomatizacion from '@/Components/Automatizaciones/EditorAutomatizacion.vue'
import HistorialAutomatizacion from '@/Components/Automatizaciones/HistorialAutomatizacion.vue'
import { get, post, del, mensajeDe, hace } from '@/composables/useEquipoIa'

const props = defineProps({
    permisos: { type: Object, default: () => ({ gestionar: false, ejecutar: false }) },
})

const lista        = ref([])
const catalogo     = ref(null)
const aprobaciones = ref([])
const cargando     = ref(true)
const aviso        = ref('')
const editor       = ref(null)    // { id, plantilla } mientras el editor está abierto
const historial    = ref(null)    // automatización cuyo historial está abierto
const plantillas   = ref(false)
const filtro       = ref('')

async function cargar() {
    const r = await get('/api/automatizaciones')
    if (r.ok) lista.value = r.data
    cargando.value = false
}

async function cargarAprobaciones() {
    const r = await get('/api/automatizaciones/aprobaciones')
    if (r.ok) aprobaciones.value = r.data
}

onMounted(async () => {
    const [c] = await Promise.all([get('/api/automatizaciones/catalogo'), cargar(), cargarAprobaciones()])
    if (c.ok) catalogo.value = c.data
})

const nueva = () => { plantillas.value = false; editor.value = { id: null, plantilla: null } }
const editar = (a) => { editor.value = { id: a.id, plantilla: null } }
const usarPlantilla = (p) => { plantillas.value = false; editor.value = { id: null, plantilla: p } }
const cerrarEditor = () => { editor.value = null; cargar() }

async function alternar(a) {
    const r = await post(`/api/automatizaciones/${a.id}/activar`, { activa: ! a.activa })
    aviso.value = r.ok ? (a.activa ? `«${a.nombre}» quedó pausada. Lo que estaba esperando se canceló.` : `«${a.nombre}» quedó encendida.`) : mensajeDe(r)
    await cargar()
}

async function ejecutar(a) {
    const r = await post(`/api/automatizaciones/${a.id}/ejecutar`)
    aviso.value = r.ok ? 'Corrida lanzada. Mira el historial.' : mensajeDe(r)
    await cargar()
}

async function eliminar(a) {
    if (! window.confirm(`¿Eliminar «${a.nombre}»? Lo que esté esperando se cancela.`)) return
    const r = await del(`/api/automatizaciones/${a.id}`)
    aviso.value = r.ok ? 'Automatización eliminada.' : mensajeDe(r)
    await cargar()
}

const pausadaPorFallas = (a) => ! a.activa && a.fallas_seguidas >= 5

const visibles = computed(() => {
    const t = filtro.value.trim().toLowerCase()
    return t ? lista.value.filter(a => (a.nombre + ' ' + a.disparador + ' ' + (a.descripcion ?? '')).toLowerCase().includes(t)) : lista.value
})

const plantillasPorArea = computed(() => {
    const grupos = {}
    for (const p of catalogo.value?.plantillas ?? []) (grupos[p.area] ??= []).push(p)
    return grupos
})

const ICONO_DISPARO = { evento: '⚡', horario: '⏰', manual: '▶' }
</script>

<template>
    <AppLayout title="Automatizaciones">
        <div class="max-w-4xl mx-auto px-4 py-4 sm:py-6 space-y-4">
            <!-- El editor reemplaza la lista mientras está abierto: en el celular no hay espacio para los dos. -->
            <EditorAutomatizacion v-if="editor && catalogo" :id="editor.id" :plantilla="editor.plantilla" :catalogo="catalogo"
                                  @cerrar="cerrarEditor" @guardado="cargar" />

            <template v-else>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 class="text-xl font-bold text-tinta-900">Automatizaciones</h1>
                        <p class="text-sm text-tinta-500">Cuando pase algo en Briela —o a una hora— que se encargue sola del siguiente paso.</p>
                    </div>
                    <div v-if="permisos.gestionar" class="flex gap-2">
                        <button type="button" @click="plantillas = ! plantillas" :disabled="! catalogo" class="px-3 py-2 rounded-xl border border-linea text-sm text-tinta-700 hover:bg-realce disabled:opacity-50">Plantillas</button>
                        <button type="button" @click="nueva" :disabled="! catalogo" class="px-3 py-2 rounded-xl text-white text-sm font-semibold disabled:opacity-50" style="background:var(--marca);">Nueva</button>
                    </div>
                </div>

                <p v-if="aviso" class="text-sm rounded-xl bg-pastel-azul border border-borde-aviso-azul text-aviso-azul px-3 py-2">{{ aviso }}</p>

                <!-- Lo que espera la decisión de esta persona -->
                <section v-if="aprobaciones.length" class="rounded-2xl border border-borde-aviso-naranja bg-pastel-naranja p-4 space-y-2">
                    <h2 class="text-sm font-semibold text-aviso-naranja">Esperan tu aprobación ({{ aprobaciones.length }})</h2>
                    <a v-for="ap in aprobaciones" :key="ap.id" :href="`/automatizaciones/aprobar/${ap.id}`"
                       class="block rounded-xl bg-superficie border border-linea p-3 hover:bg-realce">
                        <p class="text-sm text-tinta-800">{{ ap.pregunta }}</p>
                        <p class="text-[11px] text-tinta-400 mt-0.5">{{ ap.automatizacion }}<span v-if="ap.documento"> · {{ ap.documento }}</span> · vence {{ new Date(ap.vence).toLocaleString('es-CO', { dateStyle: 'short', timeStyle: 'short' }) }}</p>
                    </a>
                </section>

                <!-- Plantillas -->
                <section v-if="plantillas && catalogo" class="space-y-3">
                    <p class="text-xs text-tinta-400">Se abren en el editor para ajustarlas; ninguna se enciende sola.</p>
                    <div v-for="(lista, area) in plantillasPorArea" :key="area" class="space-y-2">
                        <h3 class="text-xs font-semibold text-tinta-500 uppercase tracking-[0.12em]">{{ area }}</h3>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <button v-for="p in lista" :key="p.clave" type="button" @click="usarPlantilla(p)"
                                    class="text-left rounded-xl border border-linea bg-superficie p-3 hover:bg-realce">
                                <p class="text-sm font-semibold text-tinta-900">{{ p.nombre }}</p>
                                <p class="text-xs text-tinta-500 mt-1">{{ p.descripcion }}</p>
                                <p v-if="p.pendientes?.length" class="text-[11px] text-aviso-ambar mt-1.5">Al abrirla: {{ p.pendientes[0] }}</p>
                            </button>
                        </div>
                    </div>
                    <p v-if="! catalogo.plantillas.length" class="text-sm text-tinta-400">No hay plantillas para los módulos encendidos.</p>
                </section>

                <input v-if="lista.length > 5" v-model="filtro" type="search" placeholder="Buscar…"
                       class="w-full border border-linea rounded-xl px-3 py-2 text-sm bg-superficie focus:outline-none focus:border-[var(--marca)]" />

                <p v-if="cargando" class="text-sm text-tinta-400">Cargando…</p>

                <div v-else-if="! lista.length" class="rounded-2xl border border-dashed border-linea p-8 text-center text-sm text-tinta-500 space-y-2">
                    <p>Aún no hay automatizaciones.</p>
                    <p v-if="permisos.gestionar">Empieza con una <button type="button" class="underline" @click="plantillas = true">plantilla</button>: se arma sola y la puedes ajustar.</p>
                </div>

                <ul v-else class="space-y-2">
                    <li v-for="a in visibles" :key="a.id" class="rounded-2xl border border-linea bg-superficie p-4 space-y-2">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="font-semibold text-tinta-900 break-words">{{ a.nombre }}</p>
                                <p class="text-xs text-tinta-500">
                                    <span aria-hidden="true">{{ ICONO_DISPARO[a.disparador_tipo] }}</span> {{ a.disparador }}
                                </p>
                            </div>
                            <div class="flex items-center gap-1.5 flex-wrap">
                                <span v-if="a.en_curso" class="text-[11px] px-2 py-0.5 rounded-full bg-pastel-violeta-2 text-aviso-violeta">{{ a.en_curso }} en curso</span>
                                <span v-if="pausadaPorFallas(a)" class="text-[11px] px-2 py-0.5 rounded-full bg-pastel-rojo-2 text-aviso-rojo">Pausada por fallas</span>
                                <span class="text-[11px] px-2 py-0.5 rounded-full" :class="a.activa ? 'bg-pastel-verde-2 text-aviso-verde' : 'bg-tinta-100 text-tinta-500'">{{ a.activa ? 'Encendida' : 'Apagada' }}</span>
                            </div>
                        </div>

                        <p v-if="a.descripcion" class="text-sm text-tinta-500">{{ a.descripcion }}</p>

                        <div v-if="a.acciones?.length" class="flex flex-wrap gap-1">
                            <span v-for="x in a.acciones" :key="x" class="text-[11px] px-2 py-0.5 rounded-full border border-linea text-tinta-500">{{ x }}</span>
                        </div>

                        <p class="text-[11px] text-tinta-400">
                            {{ a.pasos }} pasos<span v-if="a.autor"> · de {{ a.autor }}</span>
                            <span v-if="a.corridas_24h"> · {{ a.corridas_24h }} corridas en 24 h</span>
                            <span v-if="a.fallidas_24h" class="text-aviso-rojo"> ({{ a.fallidas_24h }} con error)</span>
                            <span v-if="a.ultima"> · última {{ hace(a.ultima) }}</span>
                        </p>

                        <div class="flex flex-wrap gap-2 pt-1">
                            <button v-if="permisos.gestionar" type="button" @click="alternar(a)" class="px-3 py-1.5 rounded-lg border border-linea text-xs text-tinta-700 hover:bg-realce">{{ a.activa ? 'Pausar' : 'Encender' }}</button>
                            <button v-if="permisos.gestionar && catalogo" type="button" @click="editar(a)" class="px-3 py-1.5 rounded-lg border border-linea text-xs text-tinta-700 hover:bg-realce">Editar</button>
                            <button v-if="permisos.ejecutar && a.disparador_tipo === 'manual' && a.activa" type="button" @click="ejecutar(a)" class="px-3 py-1.5 rounded-lg text-white text-xs font-semibold" style="background:var(--marca);">Ejecutar</button>
                            <button type="button" @click="historial = a" class="px-3 py-1.5 rounded-lg border border-linea text-xs text-tinta-700 hover:bg-realce">Historial</button>
                            <button v-if="permisos.gestionar" type="button" @click="eliminar(a)" class="px-3 py-1.5 rounded-lg text-xs text-aviso-rojo hover:bg-realce">Eliminar</button>
                        </div>
                    </li>
                    <li v-if="! visibles.length" class="text-sm text-tinta-400 text-center py-4">Nada coincide con «{{ filtro }}».</li>
                </ul>
            </template>
        </div>

        <HistorialAutomatizacion v-if="historial" :id="historial.id" :nombre="historial.nombre" :ejecutar="permisos.ejecutar"
                                 @cerrar="historial = null" @cambio="cargar" />
    </AppLayout>
</template>
