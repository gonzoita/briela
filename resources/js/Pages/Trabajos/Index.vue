<script setup>
import { ref, computed, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    trabajos:               { type: Object, default: () => ({}) },
    operarios:              { type: Array,  default: () => [] },
    templates:              { type: Array,  default: () => [] },
    filters:                { type: Object, default: () => ({}) },
    metricas:               { type: Object, default: () => ({}) },
    variables_disponibles:  { type: Array,  default: () => [] },
    pasos_disponibles:      { type: Array,  default: () => [] },
})

const csrf = () => {
    const c = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))
    return c ? decodeURIComponent(c.split('=')[1]) : ''
}

// ── Filtros ───────────────────────────────────────────────────────────────────
const filtros = ref({
    op_numero:   props.filters?.op_numero   ?? '',
    template_id: props.filters?.template_id ?? '',
    operario_id: props.filters?.operario_id ?? '',
    estado:      props.filters?.estado      ?? '',
    variable:    props.filters?.variable    ?? '',
    paso:        props.filters?.paso        ?? '',
})

const lista       = ref(props.trabajos?.data ?? [])
const paginacion  = ref({ current_page: 1, last_page: 1, total: 0, ...(props.trabajos ?? {}) })
const cargando    = ref(false)

const formatTiempo = (min) => {
    if (!min) return '0 min'
    if (min < 60) return `${min} min`
    const h = Math.floor(min / 60)
    const m = min % 60
    return m > 0 ? `${h}h ${m}min` : `${h}h`
}

// El estado se basa en actividad real de los pasos (iniciado/completado), no
// solo en el porcentaje de avance — un paso con peso 0% puede estar
// completado y dejar el porcentaje en 0, lo que antes mostraba "Sin iniciar"
// aunque el trabajo ya estuviera en curso.
const estadoLabel = (t) => {
    if (t.pasos_total > 0 && t.pasos_completados === t.pasos_total) return 'completado'
    if (t.iniciado) return 'en_progreso'
    return 'sin_iniciar'
}

const badgeEstado = (t) => {
    const e = estadoLabel(t)
    if (e === 'completado')  return 'bg-green-100 text-green-700'
    if (e === 'en_progreso') return 'bg-yellow-100 text-yellow-700'
    return 'bg-gray-100 text-gray-600'
}

const textoEstado = (t) => {
    const e = estadoLabel(t)
    if (e === 'completado')  return 'Completado'
    if (e === 'en_progreso') return 'En progreso'
    return 'Sin iniciar'
}

// ── Fetch con filtros ─────────────────────────────────────────────────────────
let debounceTimer = null

const fetchTrabajo = async (page = 1) => {
    cargando.value = true
    try {
        const params = new URLSearchParams()
        if (filtros.value.op_numero)   params.set('op_numero',   filtros.value.op_numero)
        if (filtros.value.template_id) params.set('template_id', filtros.value.template_id)
        if (filtros.value.operario_id) params.set('operario_id', filtros.value.operario_id)
        if (filtros.value.estado)      params.set('estado',      filtros.value.estado)
        if (filtros.value.variable)    params.set('variable',    filtros.value.variable)
        if (filtros.value.paso)        params.set('paso',        filtros.value.paso)
        params.set('page', page)

        const res  = await fetch(`/trabajos?${params}`, {
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
        const data = await res.json()
        lista.value      = data.data ?? []
        paginacion.value = data
    } catch (e) {
        console.error('Error cargando trabajos:', e)
    } finally {
        cargando.value = false
    }
}

watch(filtros, () => {
    clearTimeout(debounceTimer)
    debounceTimer = setTimeout(() => fetchTrabajo(1), 350)
}, { deep: true })

const irPagina = (page) => {
    if (page < 1 || page > paginacion.value.last_page) return
    fetchTrabajo(page)
}

const page = usePage()
const puedeEliminar = computed(() =>
    ['administrador', 'jefe_produccion'].includes(page.props.auth?.user?.rol)
)

async function eliminarTrabajo(t) {
    if (!confirm(`¿Eliminar el trabajo ${t.op_numero} — ${t.item_descripcion}? Se perderá todo el progreso.`)) return
    try {
        cargando.value = true
        const res = await fetch(`/trabajos/${t.id}`, {
            method: 'DELETE',
            headers: {
                'X-XSRF-TOKEN': csrf(),
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        })
        if (res.ok) {
            lista.value = lista.value.filter(item => item.id !== t.id)
            paginacion.value.total = Math.max(0, (paginacion.value.total ?? 1) - 1)
        }
    } catch (e) {
        console.error('Error eliminando trabajo:', e)
    } finally {
        cargando.value = false
    }
}
</script>

<template>
    <AppLayout title="Trabajos de Producción">

        <!-- ── Topbar ────────────────────────────────────────────────────────── -->
        <div class="flex items-center justify-between mb-4">
            <div>
                <h2 class="text-lg font-bold text-gray-900">Trabajos de Producción</h2>
                <p class="text-sm text-gray-500 mt-0.5">{{ paginacion.total ?? 0 }} trabajo(s) registrado(s)</p>
            </div>
        </div>

        <!-- ── Dashboard métricas ────────────────────────────────────────────── -->
        <div class="mb-5 space-y-4">

            <!-- Fila 1: cards de estado (clickeables) -->
            <div class="grid grid-cols-3 gap-3">
                <button @click="filtros.estado = filtros.estado === 'sin_iniciar' ? '' : 'sin_iniciar'"
                    class="bg-white rounded-2xl border shadow-sm px-4 py-4 text-center w-full transition-all hover:shadow-md"
                    :class="filtros.estado === 'sin_iniciar' ? 'border-gray-400 ring-2 ring-gray-300' : 'border-gray-100'">
                    <p class="text-2xl font-bold text-gray-500">{{ metricas.sin_iniciar ?? 0 }}</p>
                    <p class="text-xs text-gray-400 mt-1">Sin iniciar</p>
                </button>
                <button @click="filtros.estado = filtros.estado === 'en_progreso' ? '' : 'en_progreso'"
                    class="bg-white rounded-2xl border shadow-sm px-4 py-4 text-center w-full transition-all hover:shadow-md"
                    :class="filtros.estado === 'en_progreso' ? 'border-yellow-400 ring-2 ring-yellow-200' : 'border-yellow-100'">
                    <p class="text-2xl font-bold text-yellow-600">{{ metricas.en_progreso ?? 0 }}</p>
                    <p class="text-xs text-gray-400 mt-1">En progreso</p>
                </button>
                <button @click="filtros.estado = filtros.estado === 'completado' ? '' : 'completado'"
                    class="bg-white rounded-2xl border shadow-sm px-4 py-4 text-center w-full transition-all hover:shadow-md"
                    :class="filtros.estado === 'completado' ? 'border-green-400 ring-2 ring-green-200' : 'border-green-100'">
                    <p class="text-2xl font-bold text-green-600">{{ metricas.completados ?? 0 }}</p>
                    <p class="text-xs text-gray-400 mt-1">Completados</p>
                </button>
            </div>

            <!-- Fila 2: pasos + top operarios -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">

                <!-- Pasos -->
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                    <h3 class="text-sm font-semibold text-gray-700 mb-3">Pasos de trabajo</h3>
                    <div class="flex items-center gap-4 mb-3">
                        <div class="text-center">
                            <p class="text-2xl font-bold text-red-500">{{ metricas.pasos_pendientes ?? 0 }}</p>
                            <p class="text-xs text-gray-400 mt-0.5">Pendientes</p>
                        </div>
                        <div class="flex-1 h-px bg-gray-100"></div>
                        <div class="text-center">
                            <p class="text-2xl font-bold text-green-600">{{ metricas.pasos_completados ?? 0 }}</p>
                            <p class="text-xs text-gray-400 mt-0.5">Completados</p>
                        </div>
                    </div>
                    <div v-if="(metricas.pasos_pendientes ?? 0) + (metricas.pasos_completados ?? 0) > 0"
                        class="h-2 rounded-full bg-gray-100 overflow-hidden">
                        <div class="h-full rounded-full bg-green-500 transition-all"
                            :style="`width:${Math.round((metricas.pasos_completados / ((metricas.pasos_pendientes ?? 0) + (metricas.pasos_completados ?? 0))) * 100)}%`">
                        </div>
                    </div>
                    <p class="text-xs text-gray-400 mt-1.5 text-right">
                        {{ metricas.pasos_completados ?? 0 }} de {{ (metricas.pasos_pendientes ?? 0) + (metricas.pasos_completados ?? 0) }} pasos totales
                    </p>
                </div>

                <!-- Top operarios -->
                <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                    <h3 class="text-sm font-semibold text-gray-700 mb-3">Top operarios por tiempo</h3>
                    <div v-if="metricas.top_operarios?.length" class="space-y-2">
                        <div v-for="(op, idx) in metricas.top_operarios" :key="idx"
                            class="flex items-center gap-3">
                            <span class="w-5 h-5 rounded-full flex items-center justify-center text-xs font-bold shrink-0"
                                :class="idx === 0 ? 'bg-yellow-100 text-yellow-700'
                                    : idx === 1 ? 'bg-gray-100 text-gray-600'
                                    : idx === 2 ? 'bg-orange-100 text-orange-600'
                                    : 'bg-gray-50 text-gray-400'">
                                {{ idx + 1 }}
                            </span>
                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-medium text-gray-800 truncate">{{ op.nombre }}</p>
                                <p class="text-xs text-gray-400">{{ op.pasos }} paso(s)</p>
                            </div>
                            <span class="text-xs font-semibold text-blue-700 shrink-0">
                                {{ formatTiempo(op.total_minutos) }}
                            </span>
                        </div>
                    </div>
                    <p v-else class="text-xs text-gray-400 italic py-2">Sin datos de tiempo aún.</p>
                </div>

            </div>
        </div>

        <!-- ── Filtros ───────────────────────────────────────────────────────── -->
        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 mb-4">
            <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                <!-- OP numero -->
                <div>
                    <label class="text-xs font-medium text-gray-500 mb-1 block">Buscar OP</label>
                    <input
                        v-model="filtros.op_numero"
                        type="text"
                        placeholder="OP-0001..."
                        class="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[var(--marca)]/30 focus:border-[var(--marca)]"
                    />
                </div>
                <!-- Template -->
                <div>
                    <label class="text-xs font-medium text-gray-500 mb-1 block">Template</label>
                    <select
                        v-model="filtros.template_id"
                        class="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[var(--marca)]/30 focus:border-[var(--marca)] bg-white"
                    >
                        <option value="">Todos</option>
                        <option v-for="t in templates" :key="t.id" :value="t.id">{{ t.nombre }}</option>
                    </select>
                </div>
                <!-- Operario -->
                <div>
                    <label class="text-xs font-medium text-gray-500 mb-1 block">Operario</label>
                    <select
                        v-model="filtros.operario_id"
                        class="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[var(--marca)]/30 focus:border-[var(--marca)] bg-white"
                    >
                        <option value="">Todos</option>
                        <option v-for="o in operarios" :key="o.id" :value="o.id">{{ o.nombre }}</option>
                    </select>
                </div>
                <!-- Estado -->
                <div>
                    <label class="text-xs font-medium text-gray-500 mb-1 block">Estado</label>
                    <select
                        v-model="filtros.estado"
                        class="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[var(--marca)]/30 focus:border-[var(--marca)] bg-white"
                    >
                        <option value="">Todos</option>
                        <option value="sin_iniciar">Sin iniciar</option>
                        <option value="en_progreso">En progreso</option>
                        <option value="completado">Completado</option>
                    </select>
                </div>
                <!-- Variable -->
                <div>
                    <label class="text-xs font-medium text-gray-500 mb-1 block">Variable</label>
                    <input
                        v-model="filtros.variable"
                        type="text"
                        list="lista-variables"
                        placeholder="Ej: ancho_vano..."
                        class="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[var(--marca)]/30 focus:border-[var(--marca)]"
                    />
                    <datalist id="lista-variables">
                        <option v-for="v in variables_disponibles" :key="v" :value="v" />
                    </datalist>
                </div>
                <!-- Paso -->
                <div>
                    <label class="text-xs font-medium text-gray-500 mb-1 block">Paso de trabajo</label>
                    <select
                        v-model="filtros.paso"
                        class="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-[var(--marca)]/30 focus:border-[var(--marca)] bg-white"
                    >
                        <option value="">Todos los pasos</option>
                        <option v-for="p in pasos_disponibles" :key="p" :value="p">{{ p }}</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- ── Indicador cargando ────────────────────────────────────────────── -->
        <div v-if="cargando" class="text-center py-8 text-gray-400 text-sm">Cargando...</div>

        <template v-else>

        <div class="hidden md:block bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100">
                        <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">OP</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Ítem</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider w-40">Progreso</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Operarios</th>
                        <th class="text-left px-5 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Estado</th>
                        <th class="px-5 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    <tr v-if="lista.length === 0">
                        <td colspan="6" class="text-center py-10 text-gray-400 text-sm">No hay trabajos registrados</td>
                    </tr>
                    <tr v-for="t in lista" :key="t.id" class="hover:bg-gray-50 transition-colors">
                        <!-- OP -->
                        <td class="px-5 py-3">
                            <a
                                :href="`/produccion/ops/${t.op_id}`"
                                class="font-semibold text-[var(--marca)] hover:underline"
                                @click.prevent="router.visit(`/produccion/ops/${t.op_id}`)"
                            >{{ t.op_numero }}</a>
                            <p class="text-xs text-gray-400 mt-0.5 truncate max-w-[120px]">{{ t.cliente_nombre }}</p>
                            <span v-if="t.op_item_codigo"
                                class="inline-block mt-1 px-1.5 py-0.5 rounded bg-gray-100 text-gray-500 text-xs font-mono">
                                {{ t.op_item_codigo }}
                            </span>
                        </td>
                        <!-- Ítem -->
                        <td class="px-5 py-3 max-w-[260px]">
                            <span class="text-gray-700 text-xs line-clamp-2">{{ t.item_descripcion ?? '—' }}</span>
                            <div v-if="t.variables_etiquetadas?.length" class="flex flex-wrap gap-1.5 mt-2">
                                <span
                                    v-for="v in t.variables_etiquetadas"
                                    :key="v.clave"
                                    class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs bg-yellow-50 border border-yellow-200 text-yellow-800"
                                >
                                    <span class="font-medium">{{ v.etiqueta }}:</span>
                                    <span>{{ v.valor }}</span>
                                </span>
                            </div>
                        </td>
                        <!-- Progreso -->
                        <td class="px-5 py-3">
                            <div class="flex flex-wrap gap-1 mb-1">
                                <template v-for="n in t.pasos_total" :key="n">
                                    <span class="w-4 h-4 rounded-full flex items-center justify-center"
                                        :class="n <= t.pasos_completados ? 'bg-green-500' : 'bg-gray-200'">
                                        <svg v-if="n <= t.pasos_completados"
                                            class="w-2.5 h-2.5 text-white" fill="none" viewBox="0 0 24 24"
                                            stroke="currentColor" stroke-width="3">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                        </svg>
                                    </span>
                                </template>
                            </div>
                            <p class="text-xs text-gray-400">{{ t.pasos_completados }}/{{ t.pasos_total }} · {{ Math.round(t.porcentaje_avance) }}%</p>
                        </td>
                        <!-- Operarios -->
                        <td class="px-5 py-3">
                            <div v-if="t.operarios?.length" class="flex flex-wrap gap-1">
                                <span
                                    v-for="op in t.operarios.slice(0,3)"
                                    :key="op.id"
                                    class="inline-block bg-blue-50 text-[var(--marca)] rounded-lg px-2 py-0.5 text-xs font-medium"
                                >{{ op.nombre?.split(' ')[0] }}</span>
                                <span v-if="t.operarios.length > 3" class="text-xs text-gray-400">+{{ t.operarios.length - 3 }}</span>
                            </div>
                            <span v-else class="text-xs text-gray-400">Sin asignar</span>
                        </td>
                        <!-- Estado -->
                        <td class="px-5 py-3">
                            <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-semibold" :class="badgeEstado(t)">
                                {{ textoEstado(t) }}
                            </span>
                        </td>
                        <!-- Acción -->
                        <td class="px-5 py-3">
                            <div class="flex items-center gap-2">
                                <a
                                    :href="`/trabajos/${t.id}`"
                                    class="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl text-xs font-semibold text-white transition-colors"
                                    style="background:var(--marca);"
                                    @click.prevent="router.visit(`/trabajos/${t.id}`)"
                                >
                                    Ver detalle
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                                    </svg>
                                </a>
                                <button
                                    v-if="puedeEliminar"
                                    @click.stop="eliminarTrabajo(t)"
                                    class="inline-flex items-center gap-1 px-3 py-1.5 rounded-xl text-xs font-medium text-red-600 border border-red-200 hover:bg-red-50 transition-colors"
                                >
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                    </svg>
                                    Eliminar
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="md:hidden space-y-3">
            <div v-if="lista.length === 0" class="text-center py-10 text-gray-400 text-sm bg-white rounded-2xl">
                No hay trabajos registrados
            </div>
            <div
                v-for="t in lista"
                :key="t.id"
                class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4"
            >
                <!-- Header -->
                <div class="flex items-center justify-between mb-2">
                    <div class="flex items-center gap-2 flex-wrap">
                        <a
                            :href="`/produccion/ops/${t.op_id}`"
                            class="font-bold text-[var(--marca)]"
                            @click.prevent="router.visit(`/produccion/ops/${t.op_id}`)"
                        >{{ t.op_numero }}</a>
                        <span v-if="t.op_item_codigo"
                            class="px-1.5 py-0.5 rounded bg-gray-100 text-gray-500 text-xs font-mono">
                            {{ t.op_item_codigo }}
                        </span>
                    </div>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-lg text-xs font-semibold" :class="badgeEstado(t)">
                        {{ textoEstado(t) }}
                    </span>
                </div>
                <!-- Descripción ítem -->
                <p class="text-sm text-gray-600 mb-2 line-clamp-2">{{ t.item_descripcion ?? '—' }}</p>
                <!-- Variables -->
                <div v-if="t.variables_etiquetadas?.length" class="flex flex-wrap gap-1.5 mb-3">
                    <span
                        v-for="v in t.variables_etiquetadas"
                        :key="v.clave"
                        class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs bg-yellow-50 border border-yellow-200 text-yellow-800"
                    >
                        <span class="font-medium">{{ v.etiqueta }}:</span>
                        <span>{{ v.valor }}</span>
                    </span>
                </div>
                <!-- Dots de pasos -->
                <div class="mb-2">
                    <div class="flex flex-wrap gap-1 my-2">
                        <template v-for="n in t.pasos_total" :key="n">
                            <span class="w-4 h-4 rounded-full"
                                :class="n <= t.pasos_completados ? 'bg-green-500' : 'bg-gray-200'">
                            </span>
                        </template>
                    </div>
                    <p class="text-xs text-gray-400 mb-2">{{ t.pasos_completados }}/{{ t.pasos_total }} pasos · {{ Math.round(t.porcentaje_avance) }}%</p>
                </div>
                <!-- Operarios -->
                <div v-if="t.operarios?.length" class="flex flex-wrap gap-1 mb-3">
                    <span
                        v-for="op in t.operarios"
                        :key="op.id"
                        class="inline-block bg-blue-50 text-[var(--marca)] rounded-lg px-2 py-0.5 text-xs font-medium"
                    >{{ op.nombre?.split(' ')[0] }}</span>
                </div>
                <!-- Botones acción -->
                <div class="flex gap-2">
                    <button
                        class="flex-1 py-2.5 rounded-xl text-sm font-semibold text-white"
                        style="background:var(--marca);"
                        @click="router.visit(`/trabajos/${t.id}`)"
                    >
                        Ver detalle
                    </button>
                    <button
                        v-if="puedeEliminar"
                        @click.stop="eliminarTrabajo(t)"
                        class="flex-1 py-2.5 rounded-xl text-sm font-medium text-red-600 border border-red-200 hover:bg-red-50"
                    >
                        Eliminar
                    </button>
                </div>
            </div>
        </div>

        </template>

        <!-- ── Paginación ────────────────────────────────────────────────────── -->
        <div v-if="paginacion.last_page > 1" class="flex items-center justify-center gap-2 mt-5">
            <button
                @click="irPagina(paginacion.current_page - 1)"
                :disabled="paginacion.current_page <= 1"
                class="px-3 py-1.5 rounded-xl border border-gray-200 text-sm font-medium disabled:opacity-40 hover:bg-gray-50 transition-colors"
            >‹ Anterior</button>
            <span class="text-sm text-gray-600">
                Página {{ paginacion.current_page }} de {{ paginacion.last_page }}
            </span>
            <button
                @click="irPagina(paginacion.current_page + 1)"
                :disabled="paginacion.current_page >= paginacion.last_page"
                class="px-3 py-1.5 rounded-xl border border-gray-200 text-sm font-medium disabled:opacity-40 hover:bg-gray-50 transition-colors"
            >Siguiente ›</button>
        </div>

    </AppLayout>
</template>
