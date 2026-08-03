<script setup>
import { ref, watch, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    proveedores: Array,
    items:       Array,
})

const guardando  = ref(false)
const errores    = ref({})
const buscarItem = ref('')

const form = ref({
    proveedor_id:            '',
    solicitud_id:            '',
    fecha_entrega_esperada:  '',
    condiciones:             '',
    notas:                   '',
    items:                   [],
})

const itemsFiltrados = computed(() => {
    if (!buscarItem.value) return props.items
    const q = buscarItem.value.toLowerCase()
    return props.items.filter(i =>
        i.nombre.toLowerCase().includes(q) || i.codigo.toLowerCase().includes(q)
    )
})

function agregarItemDesdeInventario(item) {
    form.value.items.push({
        item_id:          item.id,
        descripcion:      item.nombre,
        cantidad:         1,
        unidad:           item.unidad,
        precio_unitario:  Number(item.precio_promedio) || 0,
        impuesto_pct:     0,
        _nombre_item:     item.nombre,
        _codigo_item:     item.codigo,
    })
    buscarItem.value = ''
}

function agregarItemManual() {
    form.value.items.push({
        item_id: null, descripcion: '', cantidad: 1,
        unidad: 'unidad', precio_unitario: 0, impuesto_pct: 0,
    })
}

function quitarItem(idx) {
    form.value.items.splice(idx, 1)
}

const totalGeneral = computed(() => {
    return form.value.items.reduce((sum, i) => {
        const base = (Number(i.cantidad) || 0) * (Number(i.precio_unitario) || 0)
        return sum + base + (base * (Number(i.impuesto_pct) || 0) / 100)
    }, 0)
})

function guardar() {
    if (!form.value.items.length) { alert('Agrega al menos un ítem'); return }
    guardando.value = true
    errores.value = {}

    router.post('/compras/ordenes', form.value, {
        onError:  (e) => { errores.value = e; guardando.value = false },
        onFinish: ()  => { guardando.value = false },
    })
}

function fmtMoney(n) {
    return '$ ' + Number(n).toLocaleString('es-CO', { minimumFractionDigits: 0, maximumFractionDigits: 0 })
}
</script>

<template>
    <AppLayout title="Nueva Orden de Compra">
        <div class="max-w-3xl mx-auto px-4 py-4">

            <div class="flex items-center gap-3 mb-4">
                <a href="/compras/ordenes" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                    </svg>
                </a>
                <h1 class="text-xl font-bold text-gray-900">Nueva Orden de Compra</h1>
            </div>

            <!-- Info general -->
            <div class="bg-white rounded-xl border border-gray-200 p-4 mb-4 space-y-3">
                <h2 class="font-semibold text-gray-900">Información general</h2>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Proveedor *</label>
                    <select v-model="form.proveedor_id" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none">
                        <option value="">Seleccionar proveedor...</option>
                        <option v-for="p in proveedores" :key="p.id" :value="p.id">{{ p.nombre }}</option>
                    </select>
                    <p v-if="errores.proveedor_id" class="text-red-500 text-xs mt-1">{{ errores.proveedor_id }}</p>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Fecha entrega esperada</label>
                        <input v-model="form.fecha_entrega_esperada" type="date"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Condiciones de pago</label>
                    <input v-model="form.condiciones" type="text" placeholder="Ej: 30 días, contado..."
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Notas</label>
                    <textarea v-model="form.notas" rows="1"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
                </div>
            </div>

            <!-- Ítems -->
            <div class="bg-white rounded-xl border border-gray-200 p-4 mb-4">
                <h2 class="font-semibold text-gray-900 mb-3">Ítems</h2>

                <!-- Buscador -->
                <div class="relative mb-3">
                    <input v-model="buscarItem" type="text" placeholder="Buscar en inventario..."
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none" />
                    <div v-if="buscarItem && itemsFiltrados.length" class="absolute z-10 top-full left-0 right-0 mt-1 bg-white border border-gray-200 rounded-lg shadow-lg max-h-48 overflow-y-auto">
                        <button v-for="item in itemsFiltrados.slice(0,8)" :key="item.id"
                            @click="agregarItemDesdeInventario(item)"
                            class="w-full text-left px-3 py-2 hover:bg-blue-50 text-sm border-b border-gray-50 last:border-0">
                            <span class="font-medium">{{ item.nombre }}</span>
                            <span class="text-gray-400 ml-2 text-xs">{{ item.codigo }} · {{ item.unidad }} · $ {{ Number(item.precio_promedio).toLocaleString('es-CO') }}</span>
                        </button>
                    </div>
                </div>

                <!-- Lista ítems -->
                <div class="space-y-3 mb-3">
                    <div v-for="(item, idx) in form.items" :key="idx"
                        class="border border-gray-200 rounded-lg p-3 bg-gray-50">
                        <div class="flex items-start justify-between mb-2">
                            <span class="text-xs font-medium text-gray-500">
                                {{ item._codigo_item ?? 'Manual' }}
                            </span>
                            <button @click="quitarItem(idx)" class="text-red-400 hover:text-red-600">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>
                        <div class="space-y-2">
                            <div>
                                <label class="block text-xs text-gray-600 mb-0.5">Descripción *</label>
                                <input v-model="item.descripcion" type="text"
                                    class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm" />
                            </div>
                            <div class="grid grid-cols-4 gap-2">
                                <div>
                                    <label class="block text-xs text-gray-600 mb-0.5">Cantidad *</label>
                                    <input v-model="item.cantidad" type="number" min="0.001" step="0.001"
                                        class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm" />
                                </div>
                                <div>
                                    <label class="block text-xs text-gray-600 mb-0.5">Unidad</label>
                                    <input v-model="item.unidad" type="text"
                                        class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm" />
                                </div>
                                <div>
                                    <label class="block text-xs text-gray-600 mb-0.5">Precio Unit. *</label>
                                    <input v-model="item.precio_unitario" type="number" min="0" step="0.01"
                                        class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm" />
                                </div>
                                <div>
                                    <label class="block text-xs text-gray-600 mb-0.5">IVA %</label>
                                    <input v-model="item.impuesto_pct" type="number" min="0" max="100" step="1"
                                        class="w-full rounded border border-gray-300 px-2 py-1.5 text-sm" />
                                </div>
                            </div>
                            <p class="text-xs text-right text-gray-500">
                                Total: {{ fmtMoney(Number(item.cantidad) * Number(item.precio_unitario) * (1 + Number(item.impuesto_pct)/100)) }}
                            </p>
                        </div>
                    </div>

                    <div v-if="!form.items.length" class="text-center py-4 text-gray-400 text-sm border-2 border-dashed border-gray-200 rounded-lg">
                        Busca un ítem o agrega uno manual
                    </div>
                </div>

                <button @click="agregarItemManual"
                    class="w-full py-2 rounded-lg border-2 border-dashed border-gray-300 text-sm text-gray-500 hover:border-blue-400 hover:text-blue-600 transition-colors">
                    + Agregar ítem manual
                </button>

                <div v-if="form.items.length" class="mt-3 text-right text-sm font-semibold text-gray-900">
                    Total estimado: {{ fmtMoney(totalGeneral) }}
                </div>
            </div>

            <!-- Errores -->
            <div v-if="Object.keys(errores).length" class="bg-red-50 border border-red-200 rounded-xl p-4 mb-4">
                <p class="text-sm font-semibold text-red-700 mb-1">Errores:</p>
                <ul class="text-sm text-red-600 list-disc list-inside space-y-0.5">
                    <li v-for="(msg, key) in errores" :key="key">{{ msg }}</li>
                </ul>
            </div>

            <!-- Botón -->
            <button @click="guardar" :disabled="guardando || !form.proveedor_id"
                class="w-full py-3 rounded-xl text-sm font-semibold text-white disabled:opacity-50"
                style="background:var(--marca)">
                {{ guardando ? 'Creando...' : 'Crear Orden de Compra' }}
            </button>
        </div>
    </AppLayout>
</template>
