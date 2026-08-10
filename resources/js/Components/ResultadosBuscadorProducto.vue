<script setup>
import { computed } from 'vue'

const props = defineProps({
    resultados: { type: Array, default: () => [] },
})

const emit = defineEmits(['elegir'])

// Agrupa resultados consecutivos por padre_nombre para mostrar un encabezado
// no seleccionable seguido de sus variantes. Los productos simples (sin padre)
// se muestran como filas planas, igual que antes.
const filas = computed(() => {
    const out = []
    let ultimoPadre = null
    for (const p of props.resultados) {
        if (p.padre_nombre && p.padre_nombre !== ultimoPadre) {
            out.push({ tipo: 'encabezado', nombre: p.padre_nombre, atributo_variante: p.atributo_variante })
            ultimoPadre = p.padre_nombre
        } else if (!p.padre_nombre) {
            ultimoPadre = null
        }
        out.push({ tipo: 'producto', producto: p, esVariante: !!p.padre_nombre })
    }
    return out
})
</script>

<template>
    <div class="divide-y divide-gray-50">
        <template v-for="(fila, idx) in filas" :key="idx">
            <div v-if="fila.tipo === 'encabezado'" class="px-4 py-1.5 text-xs font-semibold text-tinta-300 uppercase tracking-wide bg-tinta-50">
                {{ fila.nombre }}
            </div>
            <button
                v-else
                type="button"
                class="w-full text-left px-4 py-2.5 hover:bg-blue-50/60 transition-colors flex items-center justify-between gap-2"
                :class="fila.esVariante ? 'pl-7' : ''"
                @click="emit('elegir', fila.producto)"
            >
                <div class="min-w-0">
                    <p class="text-sm text-tinta-900 truncate">{{ fila.producto.nombre }}</p>
                    <div class="flex items-center gap-2 mt-0.5">
                        <span class="text-xs text-tinta-300 font-mono truncate">{{ fila.producto.referencia }}</span>
                        <span v-if="fila.producto.tipo === 'servicio'" class="text-xs px-1.5 py-0.5 rounded-full shrink-0" style="background:#ECFDF5;color:#065F46;">Servicio</span>
                    </div>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    <span v-if="fila.producto.valor_variante" class="text-xs font-medium px-1.5 py-0.5 rounded-full" style="background:#EDE9FE;color:#6D28D9;">
                        {{ fila.producto.valor_variante }}
                    </span>
                    <slot name="extra" :producto="fila.producto">
                        <span v-if="fila.producto.stock_total !== undefined" class="text-xs font-semibold text-green-600">
                            {{ fila.producto.stock_total }}
                        </span>
                    </slot>
                </div>
            </button>
        </template>
        <div v-if="!resultados.length" class="px-4 py-6 text-center text-sm text-tinta-300">
            Sin resultados.
        </div>
    </div>
</template>
