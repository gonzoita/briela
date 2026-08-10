<script setup>
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    cliente:   Object,
    contactos: { type: Array, default: () => [] },
    archivos:  { type: Array, default: () => [] },
    historial: { type: Object, default: () => ({}) },
})

// ─── Historial ───────────────────────────────────────────────────────────────
// El servidor solo manda los bloques que el usuario puede ver, así que aquí
// basta con pintar lo que llegue.
const definicion = {
    cotizaciones: { etiqueta: 'Cotizaciones', vacio: 'Sin cotizaciones.',        ruta: '/cotizaciones' },
    ops:          { etiqueta: 'Órdenes de producción', vacio: 'Sin órdenes de producción.', ruta: '/produccion/ops' },
    remisiones:   { etiqueta: 'Remisiones',   vacio: 'Sin remisiones.',          ruta: '/logistica/remisiones' },
    leads:        { etiqueta: 'Oportunidades del CRM', vacio: 'Sin oportunidades.', ruta: '/crm' },
}

const bloques = computed(() =>
    Object.entries(props.historial ?? {})
        .filter(([clave]) => definicion[clave])
        .map(([clave, items]) => ({
            clave,
            items: items ?? [],
            etiqueta: definicion[clave].etiqueta,
            vacio: definicion[clave].vacio,
            verTodos: `${definicion[clave].ruta}?buscar=${encodeURIComponent(props.cliente?.nombre ?? '')}`,
        }))
)

function dinero(v) {
    if (v == null) return ''
    return new Intl.NumberFormat('es-CO', {
        style: 'currency', currency: 'COP', maximumFractionDigits: 0,
    }).format(v)
}

function tipoLabel(t) {
    return t === 'persona' ? 'Persona natural' : 'Empresa'
}

function tipoColor(t) {
    return t === 'persona'
        ? 'bg-blue-100 text-blue-700'
        : 'bg-purple-100 text-purple-700'
}

function iniciales(c) {
    const nombre = c.nombre ?? ''
    const apellido = c.apellido ?? ''
    return (nombre[0] ?? '') + (apellido[0] ?? '') || nombre.substring(0, 2).toUpperCase()
}

function eliminar() {
    if (confirm('¿Eliminar este cliente? Esta acción no se puede deshacer.')) {
        router.delete(`/clientes/${props.cliente.id}`)
    }
}
</script>

<template>
    <AppLayout :title="cliente.nombre">
        <div class="max-w-3xl mx-auto px-4 py-4">

            <!-- Cabecera -->
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-3">
                    <a href="/clientes" class="text-tinta-400 hover:text-tinta-700">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M15 19l-7-7 7-7"/>
                        </svg>
                    </a>
                    <h1 class="text-xl font-semibold text-tinta-900">Detalle del cliente</h1>
                </div>
                <div class="flex gap-2">
                    <a :href="`/clientes/${cliente.id}/edit`"
                       class="px-3 py-1.5 rounded-lg text-xs font-medium border border-tinta-200 text-tinta-700 hover:bg-tinta-50">
                        Editar
                    </a>
                    <button @click="eliminar"
                            class="px-3 py-1.5 rounded-lg text-xs font-medium text-red-600 border border-red-200 hover:bg-red-50">
                        Eliminar
                    </button>
                </div>
            </div>

            <!-- Tarjeta principal -->
            <div class="bg-white rounded-xl border border-linea p-5 mb-4">
                <div class="flex items-center gap-4 mb-4">
                    <div class="w-14 h-14 rounded-full flex items-center justify-center text-lg font-semibold text-white flex-shrink-0"
                         style="background:var(--marca)">
                        {{ iniciales(cliente) }}
                    </div>
                    <div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <h2 class="text-lg font-semibold text-tinta-900">
                                {{ cliente.nombre }}{{ cliente.apellido ? ' ' + cliente.apellido : '' }}
                            </h2>
                            <span :class="['text-xs px-2 py-0.5 rounded-full font-medium', tipoColor(cliente.tipo)]">
                                {{ tipoLabel(cliente.tipo) }}
                            </span>
                        </div>
                        <p v-if="cliente.numero_identificacion" class="text-sm text-tinta-400 mt-0.5">
                            {{ cliente.tipo_identificacion }}: {{ cliente.numero_identificacion }}<template v-if="cliente.digito_verificacion">-{{ cliente.digito_verificacion }}</template>
                        </p>
                    </div>
                </div>

                <!-- Grid de datos -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div v-if="cliente.email">
                        <p class="text-xs font-medium text-tinta-400 mb-0.5">Email</p>
                        <a :href="`mailto:${cliente.email}`" class="text-sm text-blue-600 hover:underline">{{ cliente.email }}</a>
                    </div>
                    <div v-if="cliente.celular">
                        <p class="text-xs font-medium text-tinta-400 mb-0.5">Celular</p>
                        <a :href="`tel:${cliente.celular}`" class="text-sm text-tinta-900 hover:text-blue-600">{{ cliente.celular }}</a>
                    </div>
                    <div v-if="cliente.telefono">
                        <p class="text-xs font-medium text-tinta-400 mb-0.5">Teléfono fijo</p>
                        <p class="text-sm text-tinta-900">{{ cliente.telefono }}</p>
                    </div>
                    <div v-if="cliente.ciudad">
                        <p class="text-xs font-medium text-tinta-400 mb-0.5">Ciudad</p>
                        <p class="text-sm text-tinta-900">{{ cliente.ciudad }}</p>
                    </div>
                    <div v-if="cliente.direccion" class="sm:col-span-2">
                        <p class="text-xs font-medium text-tinta-400 mb-0.5">Dirección</p>
                        <p class="text-sm text-tinta-900">{{ cliente.direccion }}</p>
                    </div>
                </div>
            </div>

            <!-- Notas -->
            <div v-if="cliente.notas" class="bg-white rounded-xl border border-linea p-4 mb-4">
                <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-2">Notas</p>
                <p class="text-sm text-tinta-700 whitespace-pre-line">{{ cliente.notas }}</p>
            </div>

            <!-- Sección de Contactos -->
            <div class="bg-white rounded-xl border border-linea p-4 mb-4">
                <div class="flex items-center justify-between mb-3">
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Contactos</p>
                    <a :href="`/clientes/${cliente.id}/edit`"
                       class="text-xs font-medium px-2 py-1 rounded text-blue-600 hover:bg-blue-50">
                        Gestionar
                    </a>
                </div>

                <div v-if="contactos.length > 0" class="space-y-3">
                    <div v-for="c in contactos" :key="c.id"
                         class="flex items-start gap-3 p-3 rounded-xl border"
                         :style="c.es_principal ? 'border-color: var(--marca); background: #EFF6FF;' : 'border-color: #F3F4F6; background: #FAFAFA;'">
                        <div class="w-9 h-9 rounded-full flex items-center justify-center text-sm font-semibold text-white flex-shrink-0"
                             :style="c.es_principal ? 'background:var(--marca);' : 'background:#9CA3AF;'">
                            {{ (c.nombre[0] ?? '') + (c.apellido?.[0] ?? '') || c.nombre.substring(0,2).toUpperCase() }}
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <p class="text-sm font-semibold text-tinta-900">{{ c.nombre }} {{ c.apellido }}</p>
                                <span v-if="c.es_principal"
                                    class="text-xs px-1.5 py-0.5 rounded-full font-medium"
                                    style="background:#DBEAFE; color:#1D4ED8;">
                                    Principal
                                </span>
                            </div>
                            <p v-if="c.cargo" class="text-xs text-tinta-400 mt-0.5">{{ c.cargo }}</p>
                            <div class="flex flex-wrap gap-x-4 gap-y-1 mt-1">
                                <a v-if="c.email" :href="`mailto:${c.email}`" class="text-xs text-blue-600 hover:underline">{{ c.email }}</a>
                                <a v-if="c.celular" :href="`tel:${c.celular}`" class="text-xs text-tinta-500">{{ c.celular }}</a>
                                <span v-if="c.telefono" class="text-xs text-tinta-500">{{ c.telefono }}</span>
                            </div>
                            <p v-if="c.notas" class="text-xs text-tinta-300 mt-1 italic">{{ c.notas }}</p>
                        </div>
                    </div>
                </div>
                <p v-else class="text-sm text-tinta-300 text-center py-6">Sin contactos registrados.</p>
            </div>

            <!-- Documentos -->
            <div class="bg-white rounded-xl border border-linea p-4 mb-4">
                <div class="flex items-center justify-between mb-3">
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Documentos</p>
                    <a :href="`/clientes/${cliente.id}/edit`"
                       class="text-xs font-medium px-2 py-1 rounded text-blue-600 hover:bg-blue-50">
                        Gestionar
                    </a>
                </div>
                <div v-if="archivos.length > 0" class="space-y-2">
                    <a v-for="a in archivos" :key="a.id"
                       :href="a.url" target="_blank"
                       class="flex items-center gap-3 p-2.5 rounded-lg border border-linea hover:bg-tinta-50 transition-colors">
                        <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0" style="background:#EFF6FF;">
                            <svg class="w-4 h-4" style="color:var(--marca);" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                            </svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-tinta-900 truncate">{{ a.nombre_original }}</p>
                            <p class="text-xs text-tinta-300">{{ a.extension?.toUpperCase() }} · {{ a.tamano_formateado }}</p>
                        </div>
                        <svg class="w-4 h-4 text-tinta-300 shrink-0" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                        </svg>
                    </a>
                </div>
                <p v-else class="text-sm text-tinta-300 text-center py-6">Sin documentos adjuntos.</p>
            </div>

            <!-- ── Historial del cliente ─────────────────────────────────── -->
            <!-- Todo lo que este cliente tiene en el sistema. Cada bloque solo
                 aparece si el usuario puede ver ese módulo. -->
            <div v-for="b in bloques" :key="b.clave"
                class="bg-white rounded-xl border border-linea p-4">
                <div class="flex items-center justify-between mb-3">
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">
                        {{ b.etiqueta }}
                        <span v-if="b.items.length" class="ml-1 text-tinta-200">{{ b.items.length }}</span>
                    </p>
                    <a v-if="b.items.length" :href="b.verTodos"
                       class="text-xs font-medium px-2 py-1 rounded text-blue-600 hover:bg-blue-50">
                        Ver todas
                    </a>
                </div>

                <div v-if="b.items.length" class="space-y-1.5">
                    <a v-for="it in b.items" :key="it.id" :href="it.url"
                       class="flex items-center gap-3 p-2.5 rounded-lg border border-linea hover:bg-tinta-50 transition-colors">
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-tinta-900 truncate">
                                {{ it.numero || it.titulo }}
                            </p>
                            <p class="text-xs text-tinta-300">
                                <span v-if="it.fecha">{{ it.fecha }} · </span>
                                <span class="capitalize">{{ (it.estado || '').replace(/_/g, ' ') }}</span>
                                <span v-if="it.avance != null"> · {{ it.avance }}% de avance</span>
                            </p>
                        </div>
                        <span v-if="it.total" class="text-sm font-semibold text-tinta-700 shrink-0">
                            {{ dinero(it.total) }}
                        </span>
                        <svg class="w-4 h-4 text-tinta-200 shrink-0" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                        </svg>
                    </a>
                </div>

                <p v-else class="text-sm text-tinta-300 text-center py-5">{{ b.vacio }}</p>
            </div>

            <!-- Acciones -->
            <div class="flex gap-3 pb-4">
                <a :href="`/clientes/${cliente.id}/edit`"
                   class="flex-1 text-center px-4 py-2.5 rounded-lg text-sm font-medium text-white"
                   style="background:var(--marca)">
                    Editar cliente
                </a>
            </div>

        </div>
    </AppLayout>
</template>
