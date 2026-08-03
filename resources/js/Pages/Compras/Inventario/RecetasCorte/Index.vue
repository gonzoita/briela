<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    recetas:    Array,
    insumos:    Array,
    resultados: Array,
    bodegas:    Array,
})

const guardando = ref(false)

// ── Crear / editar receta ──────────────────────────────────────────────────
const modalReceta    = ref(false)
const editandoReceta = ref(null)
const form = ref({
    nombre: '', producto_insumo_id: '', producto_resultado_id: '',
    cantidad_insumo: '', activo: true,
})

function abrirCrear() {
    editandoReceta.value = null
    form.value = { nombre: '', producto_insumo_id: '', producto_resultado_id: '', cantidad_insumo: '', activo: true }
    modalReceta.value = true
}

function abrirEditar(receta) {
    editandoReceta.value = receta
    form.value = {
        nombre: receta.nombre ?? '',
        producto_insumo_id: receta.producto_insumo_id,
        producto_resultado_id: receta.producto_resultado_id,
        cantidad_insumo: receta.cantidad_insumo,
        activo: receta.activo,
    }
    modalReceta.value = true
}

function cerrarModalReceta() { modalReceta.value = false; editandoReceta.value = null }

function guardarReceta() {
    guardando.value = true
    if (editandoReceta.value) {
        router.put(`/inventario/recetas-corte/${editandoReceta.value.id}`, form.value, {
            onSuccess: () => { cerrarModalReceta(); guardando.value = false },
            onError:   () => { guardando.value = false },
        })
    } else {
        router.post('/inventario/recetas-corte', form.value, {
            onSuccess: () => { cerrarModalReceta(); guardando.value = false },
            onError:   () => { guardando.value = false },
        })
    }
}

function eliminarReceta(receta) {
    if (!confirm(`¿Eliminar la receta "${receta.nombre || 'sin nombre'}"?`)) return
    router.delete(`/inventario/recetas-corte/${receta.id}`)
}

// ── Construir (ejecutar corte) ─────────────────────────────────────────────
const modalConstruir  = ref(false)
const recetaConstruir = ref(null)
const formConstruir   = ref({ bodega_id: '', cantidad: 1, notas: '' })

function abrirConstruir(receta) {
    recetaConstruir.value = receta
    formConstruir.value = { bodega_id: props.bodegas?.[0]?.id ?? '', cantidad: 1, notas: '' }
    modalConstruir.value = true
}

function cerrarModalConstruir() { modalConstruir.value = false; recetaConstruir.value = null }

function guardarConstruir() {
    guardando.value = true
    router.post(`/inventario/recetas-corte/${recetaConstruir.value.id}/construir`, formConstruir.value, {
        onSuccess: () => { cerrarModalConstruir(); guardando.value = false },
        onError:   () => { guardando.value = false },
    })
}

function insumoRequerido(receta, cantidad) {
    const c = Number(cantidad) || 0
    return (Number(receta?.cantidad_insumo) * c).toLocaleString('es-CO', { maximumFractionDigits: 3 })
}

function fmt(n) {
    return Number(n ?? 0).toLocaleString('es-CO', { minimumFractionDigits: 0, maximumFractionDigits: 3 })
}
</script>

<template>
    <AppLayout title="Recetas de corte">
        <div class="max-w-4xl mx-auto px-4 py-4">

            <div class="flex items-center justify-between mb-4">
                <div>
                    <h1 class="text-xl font-bold text-gray-900">Recetas de corte</h1>
                    <a href="/inventario" class="text-sm text-blue-600 underline">Volver a inventario</a>
                </div>
                <button @click="abrirCrear"
                    class="inline-flex items-center gap-1 px-3 py-2 rounded-lg text-sm font-medium text-white"
                    style="background:var(--marca)">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    Nueva receta
                </button>
            </div>

            <p class="text-sm text-gray-500 mb-4">
                Cada receta convierte metros/unidades de un insumo (rollo, barra) en piezas de stock de una variante,
                mediante corte en planta.
            </p>

            <div class="space-y-3">
                <div v-for="receta in recetas" :key="receta.id"
                    class="bg-white rounded-xl border border-gray-200 p-4">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="font-semibold text-gray-900 truncate">
                                {{ receta.nombre || `${receta.insumo?.nombre} → ${receta.resultado_nombre_completo}` }}
                            </p>
                            <p class="text-sm text-gray-500 mt-0.5">
                                {{ receta.cantidad_insumo }} {{ receta.insumo?.unidad_medida }}
                                de <span class="font-medium text-gray-700">{{ receta.insumo?.nombre }}</span>
                                → 1 {{ receta.resultado?.unidad_medida }}
                                de <span class="font-medium text-gray-700">{{ receta.resultado_nombre_completo }}</span>
                            </p>
                            <div class="flex gap-4 mt-2 text-xs text-gray-500">
                                <span>Stock insumo: <strong>{{ fmt(receta.stock_insumo) }}</strong></span>
                                <span>Stock resultado: <strong>{{ fmt(receta.stock_resultado) }}</strong></span>
                            </div>
                        </div>
                        <span v-if="!receta.activo"
                            class="text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-500 font-medium whitespace-nowrap">
                            Inactiva
                        </span>
                    </div>

                    <div class="flex flex-wrap gap-3 mt-3 pt-3 border-t border-gray-100">
                        <button @click="abrirConstruir(receta)"
                            class="text-sm font-semibold text-white px-3 py-1.5 rounded-lg"
                            style="background:var(--marca)">
                            Construir
                        </button>
                        <button @click="abrirEditar(receta)" class="text-sm text-gray-600 font-medium">Editar</button>
                        <button @click="eliminarReceta(receta)" class="text-sm text-red-600 font-medium">Eliminar</button>
                    </div>
                </div>

                <div v-if="!recetas.length" class="text-center py-10 text-gray-400 bg-white rounded-xl border border-gray-200">
                    No hay recetas de corte registradas.
                </div>
            </div>
        </div>

        <!-- Modal crear/editar receta -->
        <Teleport to="body">
            <div v-if="modalReceta" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
                <div class="absolute inset-0 bg-black/40" @click="cerrarModalReceta" />
                <div class="relative bg-white w-full sm:max-w-md rounded-t-2xl sm:rounded-2xl p-5 max-h-[90vh] overflow-y-auto">
                    <h2 class="text-lg font-bold text-gray-900 mb-4">
                        {{ editandoReceta ? 'Editar receta de corte' : 'Nueva receta de corte' }}
                    </h2>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Nombre (opcional)</label>
                            <input v-model="form.nombre" type="text" placeholder="Ej: Perfil IGO 12 — 2m"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Insumo (rollo/barra) *</label>
                            <select v-model="form.producto_insumo_id" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                                <option value="">Selecciona...</option>
                                <option v-for="i in insumos" :key="i.id" :value="i.id">{{ i.nombre }} ({{ i.unidad_medida }})</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Cantidad de insumo por unidad *</label>
                            <input v-model="form.cantidad_insumo" type="number" min="0.001" step="0.001"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Producto resultado (variante) *</label>
                            <select v-model="form.producto_resultado_id" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                                <option value="">Selecciona...</option>
                                <option v-for="r in resultados" :key="r.id" :value="r.id">{{ r.nombre_completo }} ({{ r.unidad_medida }})</option>
                            </select>
                        </div>
                        <label class="flex items-center gap-2 text-sm text-gray-700 cursor-pointer">
                            <input v-model="form.activo" type="checkbox" class="rounded" />
                            Activa
                        </label>
                    </div>
                    <div class="flex gap-3 mt-5">
                        <button @click="cerrarModalReceta" class="flex-1 py-2.5 rounded-xl border border-gray-300 text-sm text-gray-700">Cancelar</button>
                        <button @click="guardarReceta"
                            :disabled="guardando || !form.producto_insumo_id || !form.producto_resultado_id || !form.cantidad_insumo"
                            class="flex-1 py-2.5 rounded-xl text-sm font-semibold text-white disabled:opacity-50"
                            style="background:var(--marca)">
                            {{ guardando ? 'Guardando...' : (editandoReceta ? 'Actualizar' : 'Crear') }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Modal construir -->
        <Teleport to="body">
            <div v-if="modalConstruir" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
                <div class="absolute inset-0 bg-black/40" @click="cerrarModalConstruir" />
                <div class="relative bg-white w-full sm:max-w-sm rounded-t-2xl sm:rounded-2xl p-5">
                    <h2 class="text-lg font-bold text-gray-900 mb-1">Construir por corte</h2>
                    <p class="text-sm text-gray-500 mb-4">{{ recetaConstruir?.resultado_nombre_completo }}</p>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Bodega</label>
                            <select v-model="formConstruir.bodega_id" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                                <option v-for="b in bodegas" :key="b.id" :value="b.id">{{ b.nombre }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Piezas a producir *</label>
                            <input v-model="formConstruir.cantidad" type="number" min="1" step="1"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
                        </div>
                        <p class="text-xs text-gray-500">
                            Consumirá <strong>{{ insumoRequerido(recetaConstruir, formConstruir.cantidad) }} {{ recetaConstruir?.insumo?.unidad_medida }}</strong>
                            de {{ recetaConstruir?.insumo?.nombre }}.
                        </p>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Notas</label>
                            <input v-model="formConstruir.notas" type="text"
                                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
                        </div>
                    </div>
                    <div class="flex gap-3 mt-5">
                        <button @click="cerrarModalConstruir" class="flex-1 py-2.5 rounded-xl border border-gray-300 text-sm text-gray-700">Cancelar</button>
                        <button @click="guardarConstruir" :disabled="guardando || !formConstruir.cantidad || !formConstruir.bodega_id"
                            class="flex-1 py-2.5 rounded-xl text-sm font-semibold text-white disabled:opacity-50"
                            style="background:var(--marca)">
                            {{ guardando ? 'Procesando...' : 'Construir' }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
