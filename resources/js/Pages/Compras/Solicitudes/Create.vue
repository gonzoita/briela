<script setup>
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    ops:   Array,
    items: Array,
})

const guardando  = ref(false)
const errores    = ref({})
const buscarItem = ref('')

const form = ref({
    motivo:          '',
    fecha_requerida: '',
    notas:           '',
    op_id:           '',
    items:           [],
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
        precio_estimado:  '',
        notas:            '',
        _nombre_item:     item.nombre,
        _codigo_item:     item.codigo,
    })
    buscarItem.value = ''
}

function agregarItemManual() {
    form.value.items.push({
        item_id:         null,
        descripcion:     '',
        cantidad:        1,
        unidad:          'unidad',
        precio_estimado: '',
        notas:           '',
    })
}

function quitarItem(idx) {
    form.value.items.splice(idx, 1)
}

function guardarBorrador() {
    enviar('borrador')
}

function enviarParaAprobacion() {
    if (!form.value.items.length) {
        alert('Agrega al menos un ítem')
        return
    }
    enviar('pendiente')
}

function enviar(estado) {
    guardando.value = true
    errores.value = {}

    const payload = {
        ...form.value,
        estado,
        items: form.value.items.map(i => ({
            item_id:         i.item_id || null,
            descripcion:     i.descripcion,
            cantidad:        i.cantidad,
            unidad:          i.unidad,
            precio_estimado: i.precio_estimado || null,
            notas:           i.notas || null,
        })),
    }

    router.post('/compras/solicitudes', payload, {
        onError:  (e) => { errores.value = e; guardando.value = false },
        onFinish: ()  => { guardando.value = false },
    })
}
</script>

<template>
    <AppLayout title="Nueva Solicitud de Compra">
        <div class="max-w-3xl mx-auto px-4 py-4">

            <div class="flex items-center gap-3 mb-4">
                <a href="/compras/solicitudes" class="text-tinta-300 hover:text-tinta-500">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M15 19l-7-7 7-7"/>
                    </svg>
                </a>
                <h1 class="text-xl font-semibold text-tinta-900">Nueva Solicitud de Compra</h1>
            </div>

            <!-- Info general -->
            <div class="bg-white rounded-xl border border-linea p-4 mb-4 space-y-3">
                <h2 class="font-semibold text-tinta-900">Información general</h2>
                <div>
                    <label class="block text-sm font-medium text-tinta-700 mb-1">Motivo / descripción</label>
                    <textarea v-model="form.motivo" rows="2"
                        class="w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm focus:ring-4 focus:ring-[var(--marca-suave)] focus:outline-none"
                        placeholder="¿Para qué se necesitan estos materiales?" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-tinta-700 mb-1">Fecha requerida</label>
                        <input v-model="form.fecha_requerida" type="date"
                            class="w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm focus:ring-4 focus:ring-[var(--marca-suave)] focus:outline-none" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-tinta-700 mb-1">OP vinculada (opcional)</label>
                        <select v-model="form.op_id" class="w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm focus:ring-4 focus:ring-[var(--marca-suave)] focus:outline-none">
                            <option value="">Sin OP</option>
                            <option v-for="op in ops" :key="op.id" :value="op.id">{{ op.numero }}</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-tinta-700 mb-1">Notas adicionales</label>
                    <textarea v-model="form.notas" rows="1"
                        class="w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm focus:ring-4 focus:ring-[var(--marca-suave)] focus:outline-none" />
                </div>
            </div>

            <!-- Ítems -->
            <div class="bg-white rounded-xl border border-linea p-4 mb-4">
                <h2 class="font-semibold text-tinta-900 mb-3">Ítems a solicitar</h2>

                <!-- Buscador inventario -->
                <div class="relative mb-3">
                    <input v-model="buscarItem" type="text" placeholder="Buscar en inventario por nombre o código..."
                        class="w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm focus:ring-4 focus:ring-[var(--marca-suave)] focus:outline-none" />
                    <div v-if="buscarItem && itemsFiltrados.length" class="absolute z-10 top-full left-0 right-0 mt-1 bg-white border border-linea rounded-lg shadow-lg max-h-48 overflow-y-auto">
                        <button v-for="item in itemsFiltrados.slice(0, 8)" :key="item.id"
                            @click="agregarItemDesdeInventario(item)"
                            class="w-full text-left px-3 py-2 hover:bg-blue-50 text-sm border-b border-gray-50 last:border-0">
                            <span class="font-medium">{{ item.nombre }}</span>
                            <span class="text-tinta-300 ml-2 text-xs">{{ item.codigo }} · {{ item.unidad }}</span>
                        </button>
                    </div>
                </div>

                <!-- Lista de ítems -->
                <div class="space-y-3 mb-3">
                    <div v-for="(item, idx) in form.items" :key="idx"
                        class="border border-linea rounded-lg p-3 bg-tinta-50">
                        <div class="flex items-start justify-between mb-2">
                            <span class="text-xs font-medium text-tinta-400">
                                {{ item._codigo_item ? `#${item._codigo_item}` : 'Ítem manual' }}
                            </span>
                            <button @click="quitarItem(idx)" class="text-red-400 hover:text-red-600">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>
                        <div class="space-y-2">
                            <div>
                                <label class="block text-xs text-tinta-500 mb-0.5">Descripción *</label>
                                <input v-model="item.descripcion" type="text"
                                    class="w-full rounded border border-tinta-200 px-2 py-1.5 text-sm focus:ring-1 focus:ring-[var(--marca-suave)] focus:outline-none" />
                            </div>
                            <div class="grid grid-cols-3 gap-2">
                                <div>
                                    <label class="block text-xs text-tinta-500 mb-0.5">Cantidad *</label>
                                    <input v-model="item.cantidad" type="number" min="0.001" step="0.001"
                                        class="w-full rounded border border-tinta-200 px-2 py-1.5 text-sm" />
                                </div>
                                <div>
                                    <label class="block text-xs text-tinta-500 mb-0.5">Unidad</label>
                                    <input v-model="item.unidad" type="text"
                                        class="w-full rounded border border-tinta-200 px-2 py-1.5 text-sm" />
                                </div>
                                <div>
                                    <label class="block text-xs text-tinta-500 mb-0.5">Precio est.</label>
                                    <input v-model="item.precio_estimado" type="number" min="0" step="0.01"
                                        class="w-full rounded border border-tinta-200 px-2 py-1.5 text-sm" />
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs text-tinta-500 mb-0.5">Notas</label>
                                <input v-model="item.notas" type="text"
                                    class="w-full rounded border border-tinta-200 px-2 py-1.5 text-sm" />
                            </div>
                        </div>
                    </div>

                    <div v-if="!form.items.length" class="text-center py-4 text-tinta-300 text-sm border-2 border-dashed border-linea rounded-lg">
                        Busca un ítem del inventario o agrega uno manual
                    </div>
                </div>

                <button @click="agregarItemManual"
                    class="w-full py-2 rounded-lg border-2 border-dashed border-tinta-200 text-sm text-tinta-400 hover:border-blue-400 hover:text-blue-600 transition-colors">
                    + Agregar ítem manual
                </button>
            </div>

            <!-- Errores -->
            <div v-if="Object.keys(errores).length" class="bg-red-50 border border-red-200 rounded-xl p-4 mb-4">
                <p class="text-sm font-semibold text-red-700 mb-1">Errores de validación:</p>
                <ul class="text-sm text-red-600 list-disc list-inside space-y-0.5">
                    <li v-for="(msg, key) in errores" :key="key">{{ msg }}</li>
                </ul>
            </div>

            <!-- Botones -->
            <div class="flex gap-3">
                <button @click="guardarBorrador" :disabled="guardando"
                    class="flex-1 py-3 rounded-xl border border-tinta-200 text-sm font-medium text-tinta-700 disabled:opacity-50">
                    Guardar borrador
                </button>
                <button @click="enviarParaAprobacion" :disabled="guardando"
                    class="flex-1 py-3 rounded-xl text-sm font-semibold text-white disabled:opacity-50"
                    style="background:var(--marca)">
                    {{ guardando ? 'Enviando...' : 'Enviar para aprobación' }}
                </button>
            </div>
        </div>
    </AppLayout>
</template>
