<script setup>
/**
 * Automatizaciones: «cuando pase esto, haz aquello».
 *
 * La lista no pide nada más que dos cosas al abrir (la lista y el catálogo); el editor y el historial
 * se montan solo cuando se abren. Una automatización nace apagada: se prueba con datos reales sin
 * tocar nada y recién entonces se enciende.
 */
import { ref, computed, onMounted } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import EditorAutomatizacion from '@/Components/Automatizaciones/EditorAutomatizacion.vue'
import HistorialAutomatizacion from '@/Components/Automatizaciones/HistorialAutomatizacion.vue'
import { get, post, del, mensajeDe, hace } from '@/composables/useEquipoIa'

const props = defineProps({
    permisos: { type: Object, default: () => ({ gestionar: false, ejecutar: false }) },
})

const lista     = ref([])
const catalogo  = ref(null)
const cargando  = ref(true)
const aviso     = ref('')
const editor    = ref(null)    // { id, plantilla } mientras el editor está abierto
const historial = ref(null)    // automatización cuyo historial está abierto
const plantillas = ref(false)

async function cargar() {
    const r = await get('/api/automatizaciones')
    if (r.ok) lista.value = r.data
    cargando.value = false
}

onMounted(async () => {
    const c = await get('/api/automatizaciones/catalogo')
    if (c.ok) catalogo.value = c.data
    await cargar()
})

const nueva = () => { editor.value = { id: null, plantilla: null } }
const editar = (a) => { editor.value = { id: a.id, plantilla: null } }
const usarPlantilla = (p) => { plantillas.value = false; editor.value = { id: null, plantilla: p } }

async function alternar(a) {
    const r = await post(`/api/automatizaciones/${a.id}/activar`, { activa: !a.activa })
    aviso.value = r.ok ? (a.activa ? 'Automatización pausada.' : 'Automatización encendida.') : mensajeDe(r)
    await cargar()
}

async function ejecutar(a) {
    const r = await post(`/api/automatizaciones/${a.id}/ejecutar`)
    aviso.value = r.ok ? 'Corrida lanzada. Mira el historial.' : mensajeDe(r)
    await cargar()
}

async function eliminar(a) {
    if (!window.confirm(`¿Eliminar «${a.nombre}»? Lo que esté esperando se cancela.`)) return
    const r = await del(`/api/automatizaciones/${a.id}`)
    aviso.value = r.ok ? 'Automatización eliminada.' : mensajeDe(r)
    await cargar()
}

const pausadaPorFallas = (a) => !a.activa && a.fallas_seguidas >= 5
const hayLista = computed(() => lista.value.length > 0)
</script>

<template>
    <AppLayout title="Automatizaciones">
        <div class="max-w-4xl mx-auto p-4 space-y-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 class="text-xl font-bold text-tinta-900">Automatizaciones</h1>
                    <p class="text-sm text-tinta-500">Cuando pase algo en Briela, que se encargue solo del siguiente paso.</p>
                </div>
                <div v-if="permisos.gestionar" class="flex gap-2">
                    <button type="button" @click="plantillas = !plantillas" class="px-3 py-2 rounded-xl border border-linea text-sm text-tinta-700 hover:bg-realce">Usar una plantilla</button>
                    <button type="button" @click="nueva" :disabled="!catalogo" class="px-3 py-2 rounded-xl bg-blue-600 text-white text-sm font-semibold disabled:opacity-50">Nueva</button>
                </div>
            </div>

            <p v-if="aviso" class="text-sm rounded-xl bg-pastel-azul border border-borde-aviso-azul text-aviso-azul px-3 py-2">{{ aviso }}</p>

            <div v-if="plantillas && catalogo" class="grid gap-2 sm:grid-cols-2">
                <button v-for="p in catalogo.plantillas" :key="p.clave" type="button" @click="usarPlantilla(p)"
                        class="text-left rounded-xl border border-linea bg-superficie p-3 hover:bg-realce">
                    <p class="text-sm font-semibold text-tinta-900">{{ p.nombre }}</p>
                    <p class="text-xs text-tinta-500 mt-1">{{ p.descripcion }}</p>
                </button>
            </div>

            <p v-if="cargando" class="text-sm text-tinta-400">Cargando…</p>

            <div v-else-if="!hayLista" class="rounded-2xl border border-dashed border-linea p-8 text-center text-sm text-tinta-500">
                Aún no hay automatizaciones. Empieza con una plantilla: se arma sola y la puedes ajustar.
            </div>

            <ul v-else class="space-y-2">
                <li v-for="a in lista" :key="a.id" class="rounded-2xl border border-linea bg-superficie p-4 space-y-2">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="font-semibold text-tinta-900 truncate">{{ a.nombre }}</p>
                            <p class="text-xs text-tinta-500">
                                {{ a.disparador }} · {{ a.pasos }} pasos<span v-if="a.autor"> · de {{ a.autor }}</span>
                                <span v-if="a.ultima"> · corrió {{ hace(a.ultima) }}</span>
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span v-if="a.en_curso" class="text-xs px-2 py-0.5 rounded-full bg-pastel-violeta-2 text-aviso-violeta">{{ a.en_curso }} en curso</span>
                            <span v-if="pausadaPorFallas(a)" class="text-xs px-2 py-0.5 rounded-full bg-pastel-rojo-2 text-aviso-rojo">Pausada por fallas</span>
                            <span class="text-xs px-2 py-0.5 rounded-full" :class="a.activa ? 'bg-pastel-verde-2 text-aviso-verde' : 'bg-tinta-100 text-tinta-500'">{{ a.activa ? 'Encendida' : 'Apagada' }}</span>
                        </div>
                    </div>
                    <p v-if="a.descripcion" class="text-sm text-tinta-500">{{ a.descripcion }}</p>
                    <div class="flex flex-wrap gap-2 pt-1">
                        <button v-if="permisos.gestionar" type="button" @click="alternar(a)" class="px-3 py-1.5 rounded-lg border border-linea text-xs text-tinta-700 hover:bg-realce">{{ a.activa ? 'Pausar' : 'Encender' }}</button>
                        <button v-if="permisos.gestionar && catalogo" type="button" @click="editar(a)" class="px-3 py-1.5 rounded-lg border border-linea text-xs text-tinta-700 hover:bg-realce">Editar</button>
                        <button v-if="permisos.ejecutar && a.disparador_tipo === 'manual' && a.activa" type="button" @click="ejecutar(a)" class="px-3 py-1.5 rounded-lg bg-blue-600 text-white text-xs font-semibold">Ejecutar</button>
                        <button type="button" @click="historial = a" class="px-3 py-1.5 rounded-lg border border-linea text-xs text-tinta-700 hover:bg-realce">Historial</button>
                        <button v-if="permisos.gestionar" type="button" @click="eliminar(a)" class="px-3 py-1.5 rounded-lg text-xs text-aviso-rojo hover:bg-realce">Eliminar</button>
                    </div>
                </li>
            </ul>
        </div>

        <EditorAutomatizacion v-if="editor && catalogo" :id="editor.id" :plantilla="editor.plantilla" :catalogo="catalogo"
                              @cerrar="editor = null" @guardado="cargar" />
        <HistorialAutomatizacion v-if="historial" :id="historial.id" :nombre="historial.nombre" :ejecutar="permisos.ejecutar"
                                 @cerrar="historial = null" @cambio="cargar" />
    </AppLayout>
</template>
