<script setup>
import { ref, computed } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import OrdenarLista from '@/Components/OrdenarLista.vue'
import { useOrden } from '@/composables/useOrden'

const props = defineProps({
    remisiones: Object,
    filtros:    Object,
    // Órdenes con algo listo y todavía sin remisión, con lo que tiene cada una.
    pendientes: { type: Array, default: () => [] },
    // 'pendientes' (por remisionar) o 'creadas' (las remisiones ya hechas).
    vista:      { type: String, default: 'creadas' },
    // El orden vigente, que decide el servidor: { campo, dir }.
    orden: { type: Object, default: () => ({}) },
})

// Ordenar mantiene los filtros: reordenar no es empezar de cero.
const { ordenarPor } = useOrden('/logistica/remisiones', props.orden, props.filtros)

const camposOrden = [
    { campo: 'numero', etiqueta: 'Número' },
    { campo: 'estado', etiqueta: 'Estado' },
    { campo: 'created_at', etiqueta: 'Más reciente', texto: false },
]

const page    = usePage()
const flash   = computed(() => page.props.flash)

// Familias del tema, no colores fijos: de noche un fondo claro escrito a mano queda ilegible.
const ESTADO_BADGE = {
    borrador:   { label: 'Borrador',   clases: 'bg-tinta-100 text-tinta-700' },
    confirmada: { label: 'Confirmada', clases: 'bg-pastel-azul-2 text-aviso-azul' },
    en_camino:  { label: 'En camino',  clases: 'bg-pastel-ambar-2 text-aviso-ambar' },
    entregada:  { label: 'Entregada',  clases: 'bg-pastel-verde-2 text-aviso-verde' },
    // Entregada y pagada con pagos verificados: se pone sola.
    cerrada:    { label: 'Cerrada',    clases: 'bg-pastel-violeta-2 text-aviso-violeta' },
    anulada:    { label: 'Anulada',    clases: 'bg-pastel-rojo-2 text-aviso-rojo' },
}

// ── Por remisionar / creadas ───────────────────────────────────────────────
const vistaActiva = ref(props.vista)
function cambiarVista(v) {
    vistaActiva.value = v
    router.get('/logistica/remisiones', { ...props.filtros, vista: v }, { preserveState: true, preserveScroll: true, replace: true })
}
const unidadesListas = (op) => op.items.reduce((t, i) => t + i.disponibles, 0)
const formatFechaCorta = (d) => d ? new Date(`${d}T00:00:00`).toLocaleDateString('es-CO', { day: '2-digit', month: 'short' }) : 'Sin fecha'

const TABS = [
    { value: '',          label: 'Todas'     },
    { value: 'borrador',  label: 'Borrador'  },
    { value: 'confirmada',label: 'Confirmada'},
    { value: 'en_camino', label: 'En camino' },
    { value: 'entregada', label: 'Entregada' },
    { value: 'cerrada',   label: 'Cerrada'   },
    { value: 'anulada',   label: 'Anulada'   },
]

const estadoActivo = ref(props.filtros?.estado ?? '')
const tipoFiltro   = ref(props.filtros?.tipo ?? '')

function filtrar(estado) {
    estadoActivo.value = estado
    router.get('/logistica/remisiones', { estado, tipo: tipoFiltro.value, vista: 'creadas' }, { preserveState: true, replace: true })
}

function filtrarTipo(e) {
    tipoFiltro.value = e.target.value
    router.get('/logistica/remisiones', { estado: estadoActivo.value, tipo: tipoFiltro.value, vista: 'creadas' }, { preserveState: true, replace: true })
}

const formatFecha = (d) => {
    if (!d) return '—'
    return new Date(d).toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric' })
}

function badge(estado) {
    return ESTADO_BADGE[estado] ?? ESTADO_BADGE.borrador
}

function anular(id) {
    if (!confirm('¿Anular esta remisión? Se desbloquearán los ítems de la OP.')) return
    router.patch(`/logistica/remisiones/${id}/estado`, { estado: 'anulada' })
}

function eliminar(id) {
    if (!confirm('¿Eliminar esta remisión?')) return
    router.delete(`/logistica/remisiones/${id}`)
}
</script>

<template>
    <AppLayout title="Remisiones">
        <div class="max-w-5xl mx-auto">

            <!-- Flash -->
            <div v-if="flash?.success"
                class="mb-4 px-4 py-3 rounded-xl bg-pastel-verde border border-borde-aviso-verde text-aviso-verde text-sm">
                {{ flash.success }}
            </div>

            <!-- Header -->
            <div class="flex items-center justify-between mb-5">
                <h1 class="text-xl font-semibold text-tinta-900">Remisiones</h1>
                <a href="/logistica/remisiones/crear"
                    class="flex items-center gap-1.5 px-4 py-2 rounded-xl text-sm font-semibold text-white"
                    style="background:var(--marca);">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                    </svg>
                    Nueva Remisión
                </a>
            </div>

            <!-- Dos listas: lo que está listo y falta remisionar, y las remisiones ya hechas. -->
            <div class="flex rounded-xl border border-linea overflow-hidden bg-superficie mb-4">
                <button type="button" @click="cambiarVista('pendientes')"
                    class="flex-1 py-2.5 text-sm font-medium"
                    :class="vistaActiva === 'pendientes' ? 'text-white' : 'text-tinta-500 hover:bg-realce'"
                    :style="vistaActiva === 'pendientes' ? 'background:var(--marca);' : ''">
                    Por remisionar <span v-if="pendientes.length">({{ pendientes.length }})</span>
                </button>
                <button type="button" @click="cambiarVista('creadas')"
                    class="flex-1 py-2.5 text-sm font-medium"
                    :class="vistaActiva === 'creadas' ? 'text-white' : 'text-tinta-500 hover:bg-realce'"
                    :style="vistaActiva === 'creadas' ? 'background:var(--marca);' : ''">
                    Remisiones creadas
                </button>
            </div>

            <!-- ═══ Por remisionar ═══ -->
            <template v-if="vistaActiva === 'pendientes'">
                <p class="text-xs text-tinta-400 mb-3">
                    Órdenes con algo listo y todavía sin remisión: productos alistados y unidades de ensamble terminadas
                    y aprobadas por calidad. Los servicios no se remisionan.
                </p>
                <div v-if="!pendientes.length" class="bg-superficie rounded-2xl border border-linea p-8 text-center text-sm text-tinta-300">
                    No hay nada listo para remisionar. Los productos se alistan en
                    <a href="/produccion/alistamiento" class="underline underline-offset-2 font-medium">Alistamiento</a>;
                    las unidades de ensamble, al terminar en planta y pasar calidad.
                </div>
                <div v-else class="space-y-3">
                    <div v-for="op in pendientes" :key="op.id" class="bg-superficie rounded-2xl border border-linea p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <a :href="`/produccion/ops/${op.id}`" class="font-mono text-sm font-semibold text-tinta-900 hover:underline">{{ op.numero }}</a>
                                <span class="ml-2 text-xs px-2 py-0.5 rounded-full bg-tinta-100 text-tinta-500">{{ op.estado_label }}</span>
                                <p class="text-sm text-tinta-700 mt-0.5">{{ op.cliente }}</p>
                                <p class="text-xs text-tinta-400">Entrega: {{ formatFechaCorta(op.fecha_entrega) }}</p>
                            </div>
                            <a :href="`/logistica/remisiones/crear?op_id=${op.id}`"
                                class="shrink-0 px-4 py-2 rounded-xl text-sm font-semibold text-white" style="background:var(--marca);">
                                Remisionar {{ unidadesListas(op) }} {{ unidadesListas(op) === 1 ? 'unidad' : 'unidades' }}
                            </a>
                        </div>
                        <ul class="mt-3 divide-y divide-separador text-sm">
                            <li v-for="(i, k) in op.items" :key="k" class="py-1.5 flex justify-between gap-3">
                                <span class="text-tinta-700 min-w-0">{{ i.descripcion }}</span>
                                <span class="shrink-0 text-xs text-aviso-verde font-medium">{{ i.disponibles }} de {{ i.total }} listas</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </template>

            <!-- ═══ Remisiones creadas ═══ -->
            <template v-else>
            <!-- Ordenar. Vale para las listas que son tabla y para las que son tarjetas, y
                 en celular es el único camino: ahí no hay encabezados donde hacer clic. -->
            <div class="mb-3">
                <OrdenarLista :campos="camposOrden" :orden="orden" @ordenar="ordenarPor" />
            </div>

            <!-- Filtros -->
            <div class="flex flex-col sm:flex-row gap-3 mb-5">
                <!-- Tabs estado -->
                <div class="flex gap-1 overflow-x-auto pb-1 flex-1">
                    <button v-for="tab in TABS" :key="tab.value"
                        @click="filtrar(tab.value)"
                        class="flex-shrink-0 px-3 py-1.5 rounded-lg text-xs font-medium transition-colors"
                        :class="estadoActivo === tab.value
                            ? 'text-white' : 'bg-tinta-100 text-tinta-500 hover:bg-tinta-200'"
                        :style="estadoActivo === tab.value ? 'background:var(--marca);' : ''">
                        {{ tab.label }}
                    </button>
                </div>
                <!-- Tipo -->
                <select @change="filtrarTipo" :value="tipoFiltro"
                    class="px-3 py-1.5 rounded-xl border border-linea text-sm text-tinta-700 bg-superficie">
                    <option value="">Todos los tipos</option>
                    <option value="op">Desde OP</option>
                    <option value="manual">Manual</option>
                </select>
            </div>

            <!-- Tabla desktop -->
            <div class="hidden md:block bg-superficie rounded-2xl border border-linea overflow-hidden">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-tinta-50 border-b border-linea">
                            <th class="text-left px-4 py-2.5 text-xs font-semibold text-tinta-400 uppercase">Número</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold text-tinta-400 uppercase">Tipo</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold text-tinta-400 uppercase">Cliente / OP</th>
                            <th class="text-center px-3 py-2.5 text-xs font-semibold text-tinta-400 uppercase">Ítems</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold text-tinta-400 uppercase">Estado</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold text-tinta-400 uppercase">Fecha</th>
                            <th class="w-24"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-separador">
                        <tr v-if="!remisiones.data.length">
                            <td colspan="7" class="px-4 py-12 text-center text-sm text-tinta-300">
                                No hay remisiones con los filtros seleccionados.
                            </td>
                        </tr>
                        <tr v-for="rem in remisiones.data" :key="rem.id"
                            class="hover:bg-tinta-50 transition-colors">
                            <td class="px-4 py-3 font-mono text-xs font-semibold text-tinta-900">
                                {{ rem.numero }}
                            </td>
                            <td class="px-4 py-3">
                                <span class="text-xs px-2 py-0.5 rounded-full"
                                    :class="rem.tipo === 'op' ? 'bg-pastel-violeta-2 text-aviso-violeta' : 'bg-pastel-azul-2 text-aviso-azul'">
                                    {{ rem.tipo === 'op' ? 'OP' : 'Manual' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm text-tinta-700">
                                <div>{{ rem.cliente?.nombre ?? '—' }}</div>
                                <div v-if="rem.op" class="text-xs text-aviso-azul">{{ rem.op.numero }}</div>
                            </td>
                            <td class="px-3 py-3 text-center text-sm font-medium text-tinta-700">
                                {{ rem.items?.length ?? 0 }}
                            </td>
                            <td class="px-4 py-3">
                                <span class="text-xs px-2.5 py-1 rounded-full font-semibold"
                                    :class="badge(rem.estado).clases">
                                    {{ badge(rem.estado).label }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm text-tinta-400">
                                {{ formatFecha(rem.fecha_remision ?? rem.created_at) }}
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2 justify-end">
                                    <a :href="`/logistica/remisiones/${rem.id}`"
                                        class="text-xs text-aviso-azul hover:underline">Ver</a>
                                    <a v-if="rem.estado === 'borrador'" :href="`/logistica/remisiones/${rem.id}/editar`"
                                        class="text-xs text-tinta-500 hover:underline">Editar</a>
                                    <button v-if="!['anulada','entregada','cerrada'].includes(rem.estado)"
                                        @click="anular(rem.id)"
                                        class="text-xs text-aviso-rojo hover:underline">Anular</button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Cards mobile -->
            <div class="md:hidden space-y-3">
                <div v-if="!remisiones.data.length" class="text-center py-12 text-sm text-tinta-300">
                    No hay remisiones.
                </div>
                <div v-for="rem in remisiones.data" :key="rem.id"
                    class="bg-superficie rounded-2xl border border-linea p-4">
                    <div class="flex items-center justify-between mb-2">
                        <span class="font-mono text-sm font-semibold text-tinta-900">{{ rem.numero }}</span>
                        <span class="text-xs px-2.5 py-1 rounded-full font-semibold"
                            :class="badge(rem.estado).clases">
                            {{ badge(rem.estado).label }}
                        </span>
                    </div>
                    <p class="text-sm text-tinta-700 mb-1">{{ rem.cliente?.nombre ?? '—' }}</p>
                    <p v-if="rem.op" class="text-xs text-aviso-azul mb-2">{{ rem.op.numero }}</p>
                    <div class="flex items-center justify-between text-xs text-tinta-300">
                        <span>{{ rem.items?.length ?? 0 }} ítems · {{ rem.tipo === 'op' ? 'OP' : 'Manual' }}</span>
                        <span>{{ formatFecha(rem.fecha_remision ?? rem.created_at) }}</span>
                    </div>
                    <div class="flex gap-3 mt-3 pt-3 border-t border-linea">
                        <a :href="`/logistica/remisiones/${rem.id}`"
                            class="text-xs font-medium text-aviso-azul">Ver detalle →</a>
                        <a v-if="rem.estado === 'borrador'" :href="`/logistica/remisiones/${rem.id}/editar`"
                            class="text-xs font-medium text-tinta-500">Editar</a>
                        <button v-if="!['anulada','entregada','cerrada'].includes(rem.estado)"
                            @click="anular(rem.id)"
                            class="text-xs font-medium text-aviso-rojo ml-auto">Anular</button>
                    </div>
                </div>
            </div>

            <!-- Paginación -->
            <div v-if="remisiones.last_page > 1" class="flex justify-center gap-2 mt-6">
                <a v-if="remisiones.prev_page_url" :href="remisiones.prev_page_url"
                    class="px-3 py-1.5 rounded-lg border border-linea text-sm text-tinta-700 hover:bg-tinta-50">
                    ← Anterior
                </a>
                <span class="px-3 py-1.5 text-sm text-tinta-400">
                    {{ remisiones.current_page }} / {{ remisiones.last_page }}
                </span>
                <a v-if="remisiones.next_page_url" :href="remisiones.next_page_url"
                    class="px-3 py-1.5 rounded-lg border border-linea text-sm text-tinta-700 hover:bg-tinta-50">
                    Siguiente →
                </a>
            </div>
            </template>

        </div>
    </AppLayout>
</template>
