<script setup>
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import OrdenarLista from '@/Components/OrdenarLista.vue'
import { useOrden } from '@/composables/useOrden'
import { formatCOP } from '@/formato'

const props = defineProps({
    importaciones: Object,
    filters:       { type: Object, default: () => ({}) },
    orden:         { type: Object, default: () => ({}) },
    criterio:      { type: String, default: 'ultimo' },
    base:          { type: String, default: 'COP' },
})

const page = usePage()
const permisos = computed(() => page.props.auth?.permisosLista ?? [])
const puedeCrear    = computed(() => permisos.value.includes('importaciones.crear'))
const puedeLiquidar = computed(() => permisos.value.includes('importaciones.liquidar'))

const { ordenarPor } = useOrden('/compras/importaciones', props.orden, props.filters)

const camposOrden = [
    { campo: 'fecha', etiqueta: 'Más reciente', texto: false },
    { campo: 'numero', etiqueta: 'Número' },
    { campo: 'estado', etiqueta: 'Estado' },
    { campo: 'total_costo', etiqueta: 'Costo total', texto: false },
]

const buscar = ref(props.filters?.buscar ?? '')
const estado = ref(props.filters?.estado ?? '')

function aplicarFiltros() {
    router.get('/compras/importaciones', { buscar: buscar.value || undefined, estado: estado.value || undefined },
        { preserveState: true, replace: true })
}

const estados = {
    borrador:  { label: 'Borrador',  clase: 'bg-tinta-100 text-tinta-700' },
    liquidada: { label: 'Liquidada', clase: 'bg-pastel-verde-2 text-aviso-verde' },
}

const criterioElegido = ref(props.criterio)
function guardarCriterio() {
    router.post('/compras/importaciones/ajustes', { criterio: criterioElegido.value }, { preserveScroll: true })
}

const fecha = (f) => f ? new Date(f + 'T12:00:00').toLocaleDateString('es-CO', { day: 'numeric', month: 'short', year: 'numeric' }) : ''
</script>

<template>
    <AppLayout title="Importaciones">
        <div class="max-w-5xl mx-auto px-4 py-4 space-y-4">

            <div class="flex items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold text-tinta-900">Importaciones</h1>
                    <p class="text-xs text-tinta-400 mt-0.5">Cuánto cuesta de verdad lo que se trae de afuera, puesto en bodega.</p>
                </div>
                <a v-if="puedeCrear" href="/compras/importaciones/crear" @click.prevent="router.visit('/compras/importaciones/crear')"
                    class="shrink-0 inline-flex items-center gap-1 px-3 py-2 rounded-lg text-sm font-medium text-white" style="background:var(--marca)">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M12 4v16m8-8H4"/></svg>
                    Nueva importación
                </a>
            </div>

            <OrdenarLista :campos="camposOrden" :orden="orden" @ordenar="ordenarPor" />

            <div class="flex flex-col sm:flex-row gap-2">
                <input v-model="buscar" @keyup.enter="aplicarFiltros" type="text" placeholder="Número, referencia o proveedor…"
                    class="flex-1 rounded-lg border border-tinta-200 px-3 py-2 text-sm focus:ring-4 focus:ring-[var(--marca-suave)] focus:outline-none"/>
                <select v-model="estado" @change="aplicarFiltros"
                    class="rounded-lg border border-tinta-200 px-3 py-2 text-sm bg-superficie focus:outline-none">
                    <option value="">Todas</option>
                    <option v-for="(v, k) in estados" :key="k" :value="k">{{ v.label }}</option>
                </select>
            </div>

            <div v-if="importaciones.data.length" class="space-y-2">
                <a v-for="i in importaciones.data" :key="i.id" :href="`/compras/importaciones/${i.id}`"
                    @click.prevent="router.visit(`/compras/importaciones/${i.id}`)"
                    class="block bg-superficie rounded-xl border border-linea p-4 hover:bg-realce transition-colors">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-tinta-900">{{ i.numero }}
                                <span class="ml-2 text-[11px] font-semibold px-2 py-0.5 rounded-full" :class="estados[i.estado]?.clase">{{ estados[i.estado]?.label ?? i.estado }}</span>
                            </p>
                            <p class="text-xs text-tinta-400 mt-0.5 truncate">
                                {{ i.proveedor ?? 'Sin proveedor' }}<span v-if="i.referencia"> · {{ i.referencia }}</span> · {{ fecha(i.fecha) }}
                            </p>
                        </div>
                        <div class="text-right shrink-0">
                            <p v-if="i.total_costo !== null" class="text-sm font-semibold text-tinta-900">{{ formatCOP(i.total_costo) }} <span class="text-xs font-normal text-tinta-400">{{ base }}</span></p>
                            <p v-else class="text-xs text-tinta-300">Sin liquidar</p>
                            <p v-if="i.total_fob !== null" class="text-[11px] text-tinta-400">Factura: {{ formatCOP(i.total_fob) }}</p>
                        </div>
                    </div>
                </a>
            </div>
            <div v-else class="bg-superficie rounded-xl border border-linea p-8 text-center">
                <p class="text-sm text-tinta-700 font-medium">Todavía no hay importaciones</p>
                <p class="text-xs text-tinta-400 mt-1">Crea una con lo que cobró el proveedor y lo que costó traerlo: Briela calcula el costo de cada producto puesto en bodega.</p>
            </div>

            <div v-if="importaciones.last_page > 1" class="flex justify-center gap-2">
                <template v-for="link in importaciones.links" :key="link.label">
                    <button v-if="link.url" @click="router.visit(link.url, { preserveState: true })"
                        :class="['px-3 py-1.5 rounded-lg text-sm', link.active ? 'text-white font-semibold' : 'bg-superficie border border-linea text-tinta-700']"
                        :style="link.active ? 'background:var(--marca)' : ''" v-html="link.label"/>
                    <span v-else class="px-3 py-1.5 text-sm text-tinta-200" v-html="link.label"/>
                </template>
            </div>

            <!-- Cómo se fija el costo de un producto que se importa varias veces -->
            <div class="bg-superficie rounded-xl border border-linea p-4 space-y-2">
                <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Costo del producto al liquidar</p>
                <div class="flex flex-col sm:flex-row gap-2">
                    <select v-model="criterioElegido" :disabled="! puedeLiquidar"
                        class="flex-1 rounded-lg border border-tinta-200 px-3 py-2 text-sm bg-superficie focus:outline-none">
                        <option value="ultimo">El del último embarque</option>
                        <option value="promedio">El promedio de todos los embarques, ponderado por cantidad</option>
                    </select>
                    <button v-if="puedeLiquidar" type="button" @click="guardarCriterio" :disabled="criterioElegido === criterio"
                        class="shrink-0 px-4 py-2 rounded-lg text-sm font-medium text-white disabled:opacity-60" style="background:var(--marca)">Guardar</button>
                </div>
                <p class="text-xs text-tinta-400">
                    Al liquidar, el costo del producto pasa a ser el que salió del embarque y sus precios se recalculan con el margen de cada canal.
                    Un precio que alguien escribió a mano no se toca.
                </p>
            </div>
        </div>
    </AppLayout>
</template>
