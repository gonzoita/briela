<script setup>
/**
 * Las responsabilidades del RUT de un cliente, su actividad y si retiene ICA.
 *
 * Es lo que decide qué retenciones va a practicar al pagar. Se llena leyendo el RUT, y se
 * puede corregir a mano: la IA puede no ver una casilla, y el ICA no está en el RUT.
 */
import { computed } from 'vue'

const props = defineProps({
    // { responsabilidades_fiscales: [], actividad_economica: '', retenedor_ica: false }
    modelo:   { type: Object, required: true },
    // [{ codigo, nombre }] — los códigos que Briela conoce.
    catalogo: { type: Array, default: () => [] },
    // El ICA lo retiene quien paga: tiene sentido en un cliente, no en la propia empresa.
    conIca:   { type: Boolean, default: true },
})

const marcados = computed(() => props.modelo.responsabilidades_fiscales ?? [])

// Los que vinieron en el RUT pero no están en el catálogo: se muestran igual, con su número.
const extra = computed(() => marcados.value.filter(c => ! props.catalogo.some(o => o.codigo === c)))

function alternar(codigo) {
    const lista = [...marcados.value]
    const i = lista.indexOf(codigo)
    i >= 0 ? lista.splice(i, 1) : lista.push(codigo)
    props.modelo.responsabilidades_fiscales = lista.sort()
}
</script>

<template>
    <div class="space-y-3">
        <div>
            <p class="block text-xs font-medium text-tinta-700 mb-1.5">Responsabilidades (casilla 53 del RUT)</p>
            <div class="flex flex-wrap gap-1.5">
                <button v-for="o in catalogo" :key="o.codigo" type="button" @click="alternar(o.codigo)"
                    class="text-xs px-2.5 py-1 rounded-full border transition-colors"
                    :class="marcados.includes(o.codigo)
                        ? 'bg-pastel-azul border-borde-aviso-azul text-aviso-azul font-semibold'
                        : 'border-linea text-tinta-400 hover:bg-realce'">
                    <span class="font-mono">{{ o.codigo }}</span> · {{ o.nombre }}
                </button>
                <button v-for="c in extra" :key="c" type="button" @click="alternar(c)"
                    class="text-xs px-2.5 py-1 rounded-full border bg-pastel-azul border-borde-aviso-azul text-aviso-azul font-semibold">
                    <span class="font-mono">{{ c }}</span> · Código {{ c }}
                </button>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label class="block text-xs font-medium text-tinta-700 mb-1">Actividad económica (CIIU)</label>
                <input v-model="modelo.actividad_economica" type="text" maxlength="10" inputmode="numeric" placeholder="Ej: 2511"
                    class="w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm focus:ring-4 focus:ring-[var(--marca-suave)] focus:outline-none"/>
            </div>
            <label v-if="conIca" class="flex items-start gap-2 sm:pt-6 text-sm text-tinta-700 cursor-pointer">
                <input v-model="modelo.retenedor_ica" type="checkbox" class="rounded mt-0.5"/>
                <span>
                    Retiene ICA
                    <span class="block text-xs text-tinta-400">No sale del RUT: cada municipio nombra a sus agentes de retención.</span>
                </span>
            </label>
        </div>
    </div>
</template>
