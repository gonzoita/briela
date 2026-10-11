<script setup>
/**
 * Si un producto pasa por planta antes de salir, y cómo.
 *
 * Un producto no se fabrica desde una receta, pero muchas veces sí se procesa: se corta a
 * medida, se pinta, se empaca. Con esto prendido, cada vez que entra a una orden sus unidades
 * nacen con QR, pasos y revisión de calidad, igual que las de un ensamble, y quedan listas
 * para remisionar cuando la planta las termina. Apagado, el producto solo se alista.
 *
 * Muta el objeto en sitio: el padre lo pasa y lo manda tal cual al guardar.
 */
import PasosProduccion from '@/Components/PasosProduccion.vue'
import ChecksCalidad from '@/Components/ChecksCalidad.vue'

const props = defineProps({
    // { activo: bool, pasos: [], checks: [] }
    proceso: { type: Object, required: true },
})

function prender() {
    if (props.proceso.activo && ! props.proceso.pasos.length) {
        props.proceso.pasos.push({
            nombre:            'Proceso',
            descripcion:       '',
            peso_porcentaje:   100,
            orden:             0,
            nivel_dificultad:  2,
            depende_de:        [],
            es_paso_final:     true,
            bodega_destino_id: null,
        })
    }
}
</script>

<template>
    <div class="bg-superficie rounded-xl border border-linea p-5">
        <label class="flex items-start gap-3 cursor-pointer">
            <input type="checkbox" v-model="proceso.activo" @change="prender"
                class="mt-0.5 w-4 h-4 rounded accent-[var(--marca)]" />
            <span>
                <span class="block text-sm font-semibold text-tinta-900">Se procesa en planta</span>
                <span class="block text-xs text-tinta-400 mt-0.5">
                    Para lo que hay que cortar, pintar o empacar antes de entregar. En cada orden sus
                    unidades salen a Trabajos con QR y pasos, y quedan listas para remisionar al
                    terminarlos. Sin esto, el producto solo se alista.
                </span>
            </span>
        </label>

        <div v-if="proceso.activo" class="mt-4 space-y-5">
            <PasosProduccion :pasos="proceso.pasos"
                texto-final="Paso final — la unidad queda lista para despachar">
                <template #titulo>Pasos en planta</template>
            </PasosProduccion>
            <ChecksCalidad :checks="proceso.checks" />
        </div>
    </div>
</template>
