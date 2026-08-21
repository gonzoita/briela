<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import OrdenarLista from '@/Components/OrdenarLista.vue'
import { useOrden } from '@/composables/useOrden'
import BuscadorModulo from '@/Components/BuscadorModulo.vue'

const props = defineProps({
    clientes:               Object,
    filters:                Object,
    segmentacion_opciones:  { type: Object, default: () => ({}) },
    // El orden vigente, que decide el servidor: { campo, dir }.
    orden: { type: Object, default: () => ({}) },
})

// Ordenar mantiene los filtros: reordenar no es empezar de cero.
const { ordenarPor } = useOrden('/clientes', props.orden, props.filters)

const camposOrden = [
    { campo: 'nombre', etiqueta: 'Nombre' },
    { campo: 'ciudad', etiqueta: 'Ciudad' },
    { campo: 'created_at', etiqueta: 'Más reciente', texto: false },
]

const buscar              = ref(props.filters?.buscar ?? '')
const tipo                = ref(props.filters?.tipo ?? '')
const industria           = ref(props.filters?.industria ?? '')
const fuente_contacto     = ref(props.filters?.fuente_contacto ?? '')
const proceso_seguimiento = ref(props.filters?.proceso_seguimiento ?? '')

function aplicarFiltros() {
    router.get('/clientes', {
        buscar:              buscar.value || undefined,
        tipo:                tipo.value || undefined,
        industria:           industria.value || undefined,
        fuente_contacto:     fuente_contacto.value || undefined,
        proceso_seguimiento: proceso_seguimiento.value || undefined,
    }, { preserveState: true, replace: true })
}

function limpiar() {
    buscar.value = ''; tipo.value = ''; industria.value = ''
    fuente_contacto.value = ''; proceso_seguimiento.value = ''
    aplicarFiltros()
}

const hayFiltros = () => !!(buscar.value || tipo.value || industria.value || fuente_contacto.value || proceso_seguimiento.value)

function tipoLabel(t) {
    return t === 'persona' ? 'Persona' : 'Empresa'
}

function tipoColor(t) {
    return t === 'persona'
        ? 'bg-pastel-azul-2 text-aviso-azul'
        : 'bg-pastel-violeta-2 text-aviso-violeta'
}

function iniciales(c) {
    const nombre = c.nombre ?? ''
    const apellido = c.apellido ?? ''
    return (nombre[0] ?? '') + (apellido[0] ?? '') || nombre.substring(0, 2).toUpperCase()
}
</script>

<template>
    <AppLayout title="Clientes">
        <div class="max-w-5xl mx-auto px-4 py-4">

            <!-- Cabecera -->
            <div class="flex items-center justify-between mb-4">
                <h1 class="text-xl font-semibold text-tinta-900">Clientes</h1>
                <div class="flex items-center gap-2">
                    <a href="/clientes/importar"
                       class="inline-flex items-center gap-1 px-3 py-2 rounded-lg text-sm font-medium text-tinta-700 border border-tinta-200 bg-superficie hover:bg-tinta-50">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6"
                                d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                        </svg>
                        Importar
                    </a>
                    <a href="/clientes/create"
                       class="inline-flex items-center gap-1 px-3 py-2 rounded-lg text-sm font-medium text-white"
                       style="background:var(--marca)">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M12 4v16m8-8H4"/>
                        </svg>
                        Nuevo
                    </a>
                </div>
            </div>

            <!-- Ordenar. Vale para las listas que son tabla y para las que son tarjetas, y
                 en celular es el único camino: ahí no hay encabezados donde hacer clic. -->
            <div class="mb-3">
                <OrdenarLista :campos="camposOrden" :orden="orden" @ordenar="ordenarPor" />
            </div>

            <!-- Filtros -->
            <div class="bg-superficie rounded-xl border border-linea p-3 mb-4 space-y-2">
                <div class="flex flex-col sm:flex-row gap-2">
                    <BuscadorModulo
                        v-model="buscar"
                        tipos="cliente"
                        placeholder="Buscar por nombre, ID, email..."
                        @filtrar="aplicarFiltros"
                        class="flex-1"
                    />
                    <select v-model="tipo" class="rounded-lg border border-tinta-200 px-3 py-2 text-sm" @change="aplicarFiltros">
                        <option value="">Todos los tipos</option>
                        <option value="persona">Persona</option>
                        <option value="empresa">Empresa</option>
                    </select>
                    <button @click="aplicarFiltros" class="px-3 py-2 rounded-lg text-sm font-medium text-white" style="background:var(--marca)">Buscar</button>
                    <button v-if="hayFiltros()" @click="limpiar" class="px-3 py-2 rounded-lg text-sm font-medium text-tinta-500 bg-tinta-100 hover:bg-tinta-200">Limpiar</button>
                </div>
                <!-- Filtros segmentación -->
                <div class="flex flex-wrap gap-2">
                    <select v-model="industria" class="rounded-lg border border-linea px-2 py-1.5 text-xs text-tinta-500 focus:outline-none" @change="aplicarFiltros">
                        <option value="">Industria: Todas</option>
                        <option v-for="op in (segmentacion_opciones.industria ?? [])" :key="op.valor" :value="op.valor">
                            {{ op.etiqueta }}
                        </option>
                    </select>
                    <select v-model="fuente_contacto" class="rounded-lg border border-linea px-2 py-1.5 text-xs text-tinta-500 focus:outline-none" @change="aplicarFiltros">
                        <option value="">Fuente: Todas</option>
                        <option v-for="op in (segmentacion_opciones.fuente_contacto ?? [])" :key="op.valor" :value="op.valor">
                            {{ op.etiqueta }}
                        </option>
                    </select>
                    <select v-model="proceso_seguimiento" class="rounded-lg border border-linea px-2 py-1.5 text-xs text-tinta-500 focus:outline-none" @change="aplicarFiltros">
                        <option value="">Proceso: Todos</option>
                        <option v-for="op in (segmentacion_opciones.proceso_seguimiento ?? [])" :key="op.valor" :value="op.valor">
                            {{ op.etiqueta }}
                        </option>
                    </select>
                </div>
            </div>

            <!-- Lista -->
            <div v-if="clientes.data.length === 0" class="text-center py-16 text-tinta-300">
                <svg class="w-12 h-12 mx-auto mb-3 opacity-40" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                          d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                <p class="font-medium">Sin clientes</p>
                <p class="text-sm mt-1">Ajusta los filtros o crea un nuevo cliente.</p>
            </div>

            <div v-else class="space-y-2">
                <a
                    v-for="c in clientes.data"
                    :key="c.id"
                    :href="`/clientes/${c.id}`"
                    class="flex items-center gap-3 bg-superficie rounded-xl border border-linea px-4 py-3 hover:border-borde-aviso-azul hover:shadow-sm transition-all"
                >
                    <!-- Avatar -->
                    <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-semibold text-white flex-shrink-0"
                         style="background:var(--marca)">
                        {{ iniciales(c) }}
                    </div>

                    <!-- Info -->
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="font-medium text-tinta-900 text-sm truncate">
                                {{ c.nombre }}{{ c.apellido ? ' ' + c.apellido : '' }}
                            </span>
                            <span :class="['text-xs px-2 py-0.5 rounded-full font-medium', tipoColor(c.tipo)]">
                                {{ tipoLabel(c.tipo) }}
                            </span>
                        </div>
                        <div class="flex items-center gap-3 mt-0.5 text-xs text-tinta-400 flex-wrap">
                            <span v-if="c.numero_identificacion">{{ c.tipo_identificacion }}: {{ c.numero_identificacion }}<template v-if="c.digito_verificacion">-{{ c.digito_verificacion }}</template></span>
                            <span v-if="c.email">{{ c.email }}</span>
                            <span v-if="c.celular">{{ c.celular }}</span>
                            <span v-if="c.ciudad">{{ c.ciudad }}</span>
                        </div>
                    </div>

                    <!-- Flecha -->
                    <svg class="w-4 h-4 text-tinta-300 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M9 5l7 7-7 7"/>
                    </svg>
                </a>
            </div>

            <!-- Paginación -->
            <div v-if="clientes.last_page > 1" class="flex items-center justify-center gap-2 mt-6">
                <a v-if="clientes.prev_page_url" :href="clientes.prev_page_url"
                   class="px-3 py-1.5 rounded-lg border border-tinta-200 text-sm text-tinta-500 hover:bg-tinta-50">
                    ← Anterior
                </a>
                <span class="text-sm text-tinta-400">
                    Página {{ clientes.current_page }} de {{ clientes.last_page }}
                </span>
                <a v-if="clientes.next_page_url" :href="clientes.next_page_url"
                   class="px-3 py-1.5 rounded-lg border border-tinta-200 text-sm text-tinta-500 hover:bg-tinta-50">
                    Siguiente →
                </a>
            </div>

        </div>
    </AppLayout>
</template>
