<script setup>
/**
 * Las dos bodegas del paso final: a dónde entra la unidad y de dónde salió su material.
 *
 * Aparece al cerrar el paso que entrega, y **llega con las dos ya elegidas**: las que declaró
 * la orden al confirmarse, o las que se eligieron en la unidad anterior de la misma orden. Casi
 * siempre es confirmar y seguir; el selector está para el caso real de que la puerta terminara
 * en otro estante del que se había planeado.
 *
 * Son dos y no una a propósito. Una bodega de producto terminado no guarda insumos: descontar
 * el material contra ella lo recorta a cero en silencio y el inventario queda mintiendo por los
 * dos lados. Ver `EntregaAlmacenService`.
 */
import { ref, watch } from 'vue'

const props = defineProps({
    abierto:   { type: Boolean, default: false },
    bodegas:   { type: Array,  default: () => [] },
    entrega:   { type: [Number, String], default: '' },
    material:  { type: [Number, String], default: '' },
    titulo:    { type: String, default: 'Cerrar la unidad' },
    subtitulo: { type: String, default: '' },
    guardando: { type: Boolean, default: false },
    error:     { type: String, default: '' },
})

const emit = defineEmits(['confirmar', 'cerrar'])

const entregaId  = ref(props.entrega  || '')
const materialId = ref(props.material || '')

watch(() => [props.abierto, props.entrega, props.material], () => {
    if (props.abierto) {
        entregaId.value  = props.entrega  || ''
        materialId.value = props.material || ''
    }
})

const confirmar = () => {
    if (! entregaId.value || ! materialId.value) return

    emit('confirmar', {
        bodega_entrega_id:  Number(entregaId.value),
        bodega_material_id: Number(materialId.value),
    })
}
</script>

<template>
    <Teleport to="body">
        <div v-if="abierto" class="fixed inset-0 z-[70] flex items-end md:items-center justify-center p-0 md:p-6"
             style="background: rgba(16,24,40,.55); backdrop-filter: blur(4px);"
             @click.self="emit('cerrar')">

            <div class="w-full md:max-w-md bg-superficie rounded-t-3xl md:rounded-3xl shadow-flotante overflow-hidden">

                <div class="px-5 py-4 border-b border-linea">
                    <h3 class="text-base font-semibold text-tinta-900">{{ titulo }}</h3>
                    <p class="text-xs text-tinta-400 mt-0.5">
                        {{ subtitulo || 'Este paso entrega la unidad: entra a bodega y sus materiales se descuentan.' }}
                    </p>
                </div>

                <div class="p-5 space-y-4">
                    <p v-if="error"
                       class="text-xs text-aviso-rojo bg-pastel-rojo border border-borde-aviso-rojo rounded-xl px-3 py-2">
                        {{ error }}
                    </p>

                    <div>
                        <label class="text-xs font-medium text-tinta-500 mb-1 block">
                            ¿A qué bodega entra la unidad terminada?
                        </label>
                        <select v-model="entregaId"
                            class="w-full rounded-xl border border-linea px-3 py-2.5 text-sm bg-superficie focus:outline-none focus:border-[var(--marca)]">
                            <option value="">Elige una bodega…</option>
                            <option v-for="b in bodegas" :key="b.id" :value="b.id">{{ b.nombre }}</option>
                        </select>
                    </div>

                    <div>
                        <label class="text-xs font-medium text-tinta-500 mb-1 block">
                            ¿De qué bodega salieron los insumos?
                        </label>
                        <select v-model="materialId"
                            class="w-full rounded-xl border border-linea px-3 py-2.5 text-sm bg-superficie focus:outline-none focus:border-[var(--marca)]">
                            <option value="">Elige una bodega…</option>
                            <option v-for="b in bodegas" :key="b.id" :value="b.id">{{ b.nombre }}</option>
                        </select>
                        <p class="text-xs text-tinta-300 mt-1 leading-snug">
                            De aquí se descuenta el material de esta unidad. No suele ser la misma:
                            una bodega de producto terminado no guarda insumos.
                        </p>
                    </div>
                </div>

                <div class="px-5 py-4 border-t border-linea flex items-center gap-2"
                     style="padding-bottom: calc(16px + env(safe-area-inset-bottom));">
                    <button type="button" @click="emit('cerrar')"
                        class="px-4 py-2.5 rounded-xl text-sm font-medium text-tinta-500 hover:bg-realce transition-colors">
                        Cancelar
                    </button>
                    <button type="button" @click="confirmar" :disabled="! entregaId || ! materialId || guardando"
                        class="flex-1 py-2.5 rounded-xl text-sm font-semibold text-white disabled:opacity-40 transition-opacity"
                        style="background: var(--marca);">
                        {{ guardando ? 'Cerrando…' : 'Cerrar y entregar' }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
