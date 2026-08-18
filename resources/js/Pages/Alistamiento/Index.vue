<script setup>
/**
 * Alistamiento: la mesa de trabajo del almacenista.
 *
 * Todos los ítems de todas las órdenes en una sola lista. Lo que se despacha no es una orden,
 * son ítems —cinco bisagras de una y una puerta de otra salen en el mismo viaje—, así que la
 * pantalla se ordena por ítem y la orden es apenas una columna más.
 */
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import OrdenarLista from '@/Components/OrdenarLista.vue'
import BotonOrden from '@/Components/BotonOrden.vue'
import { useOrden } from '@/composables/useOrden'
import GraficosPersonalizados from '@/Components/GraficosPersonalizados.vue'

const props = defineProps({
    items:        { type: Object, default: () => ({ data: [] }) },
    filters:      { type: Object, default: () => ({}) },
    orden:        { type: Object, default: () => ({}) },
    resumen:      { type: Object, default: () => ({}) },
    plantillas:   { type: Array,  default: () => [] },
    puedeAlistar: { type: Boolean, default: false },
})

const { estadoDe, ordenarPor } = useOrden('/produccion/alistamiento', props.orden, props.filters)

const q         = ref(props.filters.q ?? '')
const estado    = ref(props.filters.estado ?? '')
const tipo      = ref(props.filters.tipo ?? '')
const plantilla = ref(props.filters.plantilla ?? '')

let temporizador = null

function filtrar(inmediato = false) {
    clearTimeout(temporizador)

    const ir = () => router.get('/produccion/alistamiento', {
        q: q.value || undefined,
        estado: estado.value || undefined,
        tipo: tipo.value || undefined,
        plantilla: plantilla.value || undefined,
    }, { preserveState: true, replace: true })

    inmediato ? ir() : (temporizador = setTimeout(ir, 350))
}

function limpiar() {
    q.value = estado.value = tipo.value = plantilla.value = ''
    filtrar(true)
}

const hayFiltros = computed(() => !! (q.value || estado.value || tipo.value || plantilla.value))

function alternar(item) {
    router.patch(`/produccion/alistamiento/${item.id}`, { alistado: ! item.alistado }, {
        preserveScroll: true,
        preserveState: true,
    })
}

const colorTipo = (t) => t === 'ensamble'
    ? 'bg-pastel-violeta text-aviso-violeta'
    : t === 'servicio' ? 'bg-pastel-ambar text-aviso-ambar' : 'bg-pastel-azul text-aviso-azul'
</script>

<template>
    <AppLayout title="Alistamiento">
        <div class="max-w-7xl mx-auto">

            <div class="mb-5">
                <h1 class="text-xl font-semibold text-tinta-900">Alistamiento</h1>
                <p class="text-sm text-tinta-400 mt-0.5">
                    Todo lo que hay que dejar listo, de todas las órdenes. Lo que quede alistado es
                    lo que se puede remisionar.
                </p>
            </div>

            <!-- Tablero. No cambia con los filtros: es cómo va el día, no cómo va la búsqueda. -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
                <div class="bg-superficie rounded-2xl shadow-sm p-4">
                    <p class="text-xs text-tinta-400">Pendientes</p>
                    <p class="text-2xl font-semibold text-aviso-ambar mt-1">{{ resumen.pendientes ?? 0 }}</p>
                </div>
                <div class="bg-superficie rounded-2xl shadow-sm p-4">
                    <p class="text-xs text-tinta-400">Alistados</p>
                    <p class="text-2xl font-semibold text-aviso-verde mt-1">{{ resumen.alistados ?? 0 }}</p>
                </div>
                <div class="bg-superficie rounded-2xl shadow-sm p-4">
                    <p class="text-xs text-tinta-400">Alistados hoy</p>
                    <p class="text-2xl font-semibold text-tinta-700 mt-1">{{ resumen.alistados_hoy ?? 0 }}</p>
                </div>
                <div class="bg-superficie rounded-2xl shadow-sm p-4">
                    <p class="text-xs text-tinta-400">Por despachar</p>
                    <p class="text-2xl font-semibold text-aviso-azul mt-1">{{ resumen.por_despachar ?? 0 }}</p>
                </div>
            </div>

            <!-- Filtros -->
            <div class="bg-superficie rounded-2xl shadow-sm p-4 mb-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    <input v-model="q" @input="filtrar()" type="text"
                        placeholder="Ítem, serie, número de OP o cliente…"
                        class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]" />
                    <select v-model="estado" @change="filtrar(true)"
                        class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]">
                        <option value="">Todos los estados</option>
                        <option value="pendiente">Pendientes</option>
                        <option value="alistado">Alistados</option>
                    </select>
                    <select v-model="tipo" @change="filtrar(true)"
                        class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]">
                        <option value="">Todos los tipos</option>
                        <option value="ensamble">Ensambles</option>
                        <option value="producto">Productos</option>
                        <option value="servicio">Servicios</option>
                    </select>
                    <select v-model="plantilla" @change="filtrar(true)"
                        class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]">
                        <option value="">Todas las plantillas</option>
                        <option v-for="p in plantillas" :key="p.id" :value="p.id">{{ p.nombre }}</option>
                    </select>
                </div>
                <div class="flex items-center justify-between gap-3 mt-3 flex-wrap">
                    <OrdenarLista
                        :campos="[
                            { campo: 'created_at',  etiqueta: 'Más reciente' },
                            { campo: 'descripcion', etiqueta: 'Descripción' },
                            { campo: 'estado_item', etiqueta: 'Estado' },
                            { campo: 'cantidad',    etiqueta: 'Cantidad' },
                        ]"
                        :orden="orden" @ordenar="ordenarPor" />
                    <button v-if="hayFiltros" type="button" @click="limpiar"
                        class="text-xs text-tinta-400 underline underline-offset-2 hover:text-tinta-600">
                        Limpiar filtros
                    </button>
                </div>
            </div>

            <!-- Lista -->
            <div class="bg-superficie rounded-2xl shadow-sm overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-tinta-50 text-xs text-tinta-400 uppercase tracking-wide">
                            <tr>
                                <th class="text-left px-4 py-3">
                                    <BotonOrden campo="descripcion" :estado="estadoDe('descripcion')" @click="ordenarPor('descripcion')">
                                        Ítem
                                    </BotonOrden>
                                </th>
                                <th class="text-left px-4 py-3">Orden</th>
                                <th class="text-right px-4 py-3">
                                    <BotonOrden campo="cantidad" :estado="estadoDe('cantidad')" derecha @click="ordenarPor('cantidad')">
                                        Cant.
                                    </BotonOrden>
                                </th>
                                <th class="text-left px-4 py-3">Avance</th>
                                <th class="text-left px-4 py-3">
                                    <BotonOrden campo="estado_item" :estado="estadoDe('estado_item')" @click="ordenarPor('estado_item')">
                                        Estado
                                    </BotonOrden>
                                </th>
                                <th class="text-right px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-separador">
                            <tr v-for="item in items.data" :key="item.id" class="hover:bg-realce transition-colors">
                                <td class="px-4 py-3">
                                    <div class="flex items-start gap-2">
                                        <span :class="['text-[10px] px-1.5 py-0.5 rounded-full shrink-0 mt-0.5', colorTipo(item.tipo)]">
                                            {{ item.tipo }}
                                        </span>
                                        <div class="min-w-0">
                                            <p class="text-tinta-800 font-medium truncate">{{ item.descripcion }}</p>
                                            <p v-if="item.numero_serie" class="text-xs text-tinta-300">Serie {{ item.numero_serie }}</p>
                                            <p v-if="item.plantilla" class="text-xs text-tinta-300">{{ item.plantilla }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <button type="button" @click="router.visit(`/produccion/ops/${item.op.id}`)"
                                        class="text-[var(--marca)] hover:underline">{{ item.op.numero }}</button>
                                    <p class="text-xs text-tinta-300">{{ item.op.cliente ?? '—' }}</p>
                                </td>
                                <td class="px-4 py-3 text-right text-tinta-700">{{ item.cantidad }}</td>
                                <td class="px-4 py-3">
                                    <span v-if="item.avance === null" class="text-xs text-tinta-300">—</span>
                                    <div v-else class="flex items-center gap-2">
                                        <div class="w-16 h-1.5 rounded-full bg-tinta-100 overflow-hidden">
                                            <div class="h-full rounded-full bg-[var(--marca)]" :style="`width:${item.avance}%`"></div>
                                        </div>
                                        <span class="text-xs text-tinta-400">{{ item.avance }}%</span>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <span v-if="item.remisionado" class="text-xs px-2 py-0.5 rounded-full bg-pastel-azul text-aviso-azul">Despachado</span>
                                    <span v-else-if="item.alistado" class="text-xs px-2 py-0.5 rounded-full bg-pastel-verde text-aviso-verde">Alistado</span>
                                    <span v-else class="text-xs px-2 py-0.5 rounded-full bg-pastel-ambar text-aviso-ambar">Pendiente</span>
                                    <!-- Un servicio se alista pero no viaja: mejor decirlo aquí que
                                         dejar que lo busquen en la remisión y no aparezca. -->
                                    <p v-if="! item.despachable" class="text-xs text-tinta-300 mt-0.5">No se remisiona</p>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <button v-if="puedeAlistar && ! item.remisionado" type="button" @click="alternar(item)"
                                        :class="['text-xs px-3 py-1.5 rounded-lg border transition-colors',
                                            item.alistado
                                                ? 'border-linea text-tinta-500 hover:bg-realce'
                                                : 'border-borde-aviso-verde text-aviso-verde hover:bg-pastel-verde']">
                                        {{ item.alistado ? 'Devolver a pendiente' : 'Marcar alistado' }}
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="! items.data.length">
                                <td colspan="6" class="px-4 py-10 text-center text-sm text-tinta-300">
                                    {{ hayFiltros ? 'Nada con esos filtros.' : 'No hay ítems para alistar.' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Paginación -->
            <div v-if="items.last_page > 1" class="flex justify-center gap-1 mt-4 flex-wrap">
                <button v-for="p in items.last_page" :key="p"
                    @click="router.get('/produccion/alistamiento', { ...filters, page: p }, { preserveState: true })"
                    :class="['w-8 h-8 rounded-lg text-xs transition-colors',
                        p === items.current_page ? 'bg-[var(--marca)] text-white' : 'border border-linea text-tinta-500 hover:bg-realce']">
                    {{ p }}
                </button>
            </div>

        </div>
            <!-- Los graficos que la empresa arma para este tablero. -->
            <GraficosPersonalizados modulo="alistamiento" :puede-gestionar="$page.props.auth?.permisos?.includes('graficos.gestionar') ?? false" />

    </AppLayout>
</template>
