<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import OrdenarLista from '@/Components/OrdenarLista.vue'
import { useOrden } from '@/composables/useOrden'

const props = defineProps({
    solicitudes: Object,
    filters:     Object,
    // El orden vigente, que decide el servidor: { campo, dir }.
    orden: { type: Object, default: () => ({}) },
})

// Ordenar mantiene los filtros: reordenar no es empezar de cero.
const { ordenarPor } = useOrden('/compras/solicitudes', props.orden, props.filters)

const camposOrden = [
    { campo: 'numero', etiqueta: 'Número' },
    { campo: 'estado', etiqueta: 'Estado' },
    { campo: 'created_at', etiqueta: 'Más reciente', texto: false },
]

const buscar = ref(props.filters?.buscar ?? '')
const estado = ref(props.filters?.estado ?? '')
const desde  = ref(props.filters?.desde  ?? '')
const hasta  = ref(props.filters?.hasta  ?? '')

function aplicarFiltros() {
    router.get('/compras/solicitudes', {
        buscar: buscar.value || undefined,
        estado: estado.value || undefined,
        desde:  desde.value  || undefined,
        hasta:  hasta.value  || undefined,
    }, { preserveState: true, replace: true })
}

function aprobar(s) {
    if (!confirm(`¿Aprobar solicitud ${s.numero}?`)) return
    router.post(`/compras/solicitudes/${s.id}/aprobar`)
}

function rechazar(s) {
    const notas = prompt(`Motivo de rechazo para ${s.numero}:`)
    if (notas === null) return
    router.post(`/compras/solicitudes/${s.id}/rechazar`, { notas })
}

const estados = {
    borrador:   { label: 'Borrador',    bg: 'bg-tinta-100',   text: 'text-tinta-700'  },
    pendiente:  { label: 'Pendiente',   bg: 'bg-pastel-ambar-2', text: 'text-aviso-ambar'},
    aprobada:   { label: 'Aprobada',    bg: 'bg-pastel-verde-2',  text: 'text-aviso-verde' },
    rechazada:  { label: 'Rechazada',   bg: 'bg-pastel-rojo-2',    text: 'text-aviso-rojo'   },
    en_proceso: { label: 'En proceso',  bg: 'bg-pastel-azul-2',   text: 'text-aviso-azul'  },
    completada: { label: 'Completada',  bg: 'bg-pastel-verde-2',text: 'text-aviso-verde'},
    cancelada:  { label: 'Cancelada',   bg: 'bg-tinta-200',   text: 'text-tinta-400'  },
}

function estadoBadge(e) {
    return estados[e] ?? { label: e, bg: 'bg-tinta-100', text: 'text-tinta-700' }
}
</script>

<template>
    <AppLayout title="Solicitudes de Compra">
        <div class="max-w-5xl mx-auto px-4 py-4">

            <!-- Cabecera -->
            <div class="flex items-center justify-between mb-4">
                <h1 class="text-xl font-semibold text-tinta-900">Solicitudes de Compra</h1>
                <a href="/compras/solicitudes/crear"
                    class="inline-flex items-center gap-1 px-3 py-2 rounded-lg text-sm font-medium text-white"
                    style="background:var(--marca)">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M12 4v16m8-8H4"/>
                    </svg>
                    Nueva solicitud
                </a>
            </div>

            <!-- Ordenar. Vale para las listas que son tabla y para las que son tarjetas, y
                 en celular es el único camino: ahí no hay encabezados donde hacer clic. -->
            <div class="mb-3">
                <OrdenarLista :campos="camposOrden" :orden="orden" @ordenar="ordenarPor" />
            </div>

            <!-- Filtros -->
            <div class="bg-superficie rounded-xl border border-linea p-3 mb-4">
                <div class="flex flex-col sm:flex-row gap-2">
                    <input v-model="buscar" type="text" placeholder="Número de solicitud..."
                        class="flex-1 rounded-lg border border-tinta-200 px-3 py-2 text-sm" @keyup.enter="aplicarFiltros" />
                    <select v-model="estado" class="rounded-lg border border-tinta-200 px-3 py-2 text-sm" @change="aplicarFiltros">
                        <option value="">Todos los estados</option>
                        <option v-for="(v, k) in estados" :key="k" :value="k">{{ v.label }}</option>
                    </select>
                    <input v-model="desde" type="date" class="rounded-lg border border-tinta-200 px-3 py-2 text-sm" @change="aplicarFiltros" />
                    <input v-model="hasta" type="date" class="rounded-lg border border-tinta-200 px-3 py-2 text-sm" @change="aplicarFiltros" />
                    <button @click="aplicarFiltros" class="px-4 py-2 rounded-lg bg-tinta-100 text-tinta-700 text-sm font-medium">Buscar</button>
                </div>
            </div>

            <!-- Lista -->
            <div class="space-y-2">
                <div v-for="s in solicitudes.data" :key="s.id"
                    class="bg-superficie rounded-xl border border-linea p-4">
                    <div class="flex items-start justify-between">
                        <div>
                            <div class="flex items-center gap-2 mb-1">
                                <p class="font-semibold text-tinta-900">{{ s.numero }}</p>
                                <span :class="['text-xs px-2 py-0.5 rounded-full font-medium', estadoBadge(s.estado).bg, estadoBadge(s.estado).text]">
                                    {{ estadoBadge(s.estado).label }}
                                </span>
                            </div>
                            <p class="text-sm text-tinta-400">{{ s.motivo ?? 'Sin motivo' }}</p>
                            <p class="text-xs text-tinta-300 mt-1">
                                Por: {{ s.solicitado_por?.name }}
                                <span v-if="s.op"> · OP: {{ s.op.numero }}</span>
                                <span v-if="s.fecha_requerida"> · Req.: {{ new Date(s.fecha_requerida).toLocaleDateString('es-CO') }}</span>
                            </p>
                        </div>
                        <p class="text-xs text-tinta-300 whitespace-nowrap">{{ new Date(s.created_at).toLocaleDateString('es-CO') }}</p>
                    </div>

                    <div class="flex gap-3 mt-3 flex-wrap">
                        <button v-if="s.estado === 'pendiente'" @click="aprobar(s)"
                            class="text-sm text-aviso-verde font-medium">✓ Aprobar</button>
                        <button v-if="s.estado === 'pendiente'" @click="rechazar(s)"
                            class="text-sm text-aviso-rojo font-medium">✗ Rechazar</button>
                        <span v-if="s.aprobado_por" class="text-xs text-tinta-300">
                            Aprobado por {{ s.aprobado_por.name }}
                        </span>
                    </div>
                </div>

                <div v-if="!solicitudes.data?.length" class="text-center py-8 text-tinta-300">
                    No hay solicitudes de compra
                </div>
            </div>

            <!-- Paginación -->
            <div v-if="solicitudes.last_page > 1" class="flex justify-center gap-2 mt-4">
                <template v-for="link in solicitudes.links" :key="link.label">
                    <button v-if="link.url"
                        @click="router.visit(link.url, { preserveState: true })"
                        :class="['px-3 py-1.5 rounded-lg text-sm', link.active ? 'text-white font-semibold' : 'bg-superficie border border-linea text-tinta-700']"
                        :style="link.active ? 'background:var(--marca)' : ''"
                        v-html="link.label" />
                    <span v-else class="px-3 py-1.5 text-sm text-tinta-200" v-html="link.label" />
                </template>
            </div>
        </div>
    </AppLayout>
</template>
