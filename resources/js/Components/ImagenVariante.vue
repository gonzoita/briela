<script setup>
import { ref, watch, onUnmounted } from 'vue'

// La imagen de una variante: la del producto principal, o una propia.
//
// Una variante es un producto más, así que puede tener las suyas. Si no sube ninguna,
// donde la variante se muestre sola —al cotizar, en el catálogo, en su ficha— se usa la
// del producto principal. Por eso «no subir nada» es una opción válida, y la que sale por
// omisión: no se copia ningún archivo, y si el principal cambia su foto, la variante cambia
// con él.
const props = defineProps({
    modelValue: { default: null },
})

const emit = defineEmits(['update:modelValue'])

// La vista previa es un enlace temporal del navegador: hay que soltarlo al cambiar de
// archivo y al desmontar, o cada foto elegida se queda en memoria toda la jornada.
const vista = ref(null)

const soltarVista = () => {
    if (vista.value) URL.revokeObjectURL(vista.value)
    vista.value = null
}

watch(() => props.modelValue, (archivo) => {
    soltarVista()
    if (archivo) vista.value = URL.createObjectURL(archivo)
}, { immediate: true })

onUnmounted(soltarVista)

const elegir = (e) => {
    const archivo = e.target.files?.[0]
    if (archivo) emit('update:modelValue', archivo)
    // Sin esto, volver a elegir el mismo archivo después de quitarlo no dispara nada.
    e.target.value = ''
}
</script>

<template>
    <div>
        <p class="text-xs font-medium text-tinta-500 mb-1.5">Imagen de la variante</p>
        <div class="flex items-center gap-3">
            <div class="w-14 h-14 rounded-xl border border-linea bg-superficie overflow-hidden flex items-center justify-center shrink-0">
                <img v-if="vista" :src="vista" alt="" class="w-full h-full object-cover" />
                <svg v-else class="w-6 h-6 text-tinta-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4-4a3 3 0 014 0l4 4m-2-2l1-1a3 3 0 014 0l2 2M4 6h16a1 1 0 011 1v10a1 1 0 01-1 1H4a1 1 0 01-1-1V7a1 1 0 011-1zm10 3h.01" />
                </svg>
            </div>
            <div class="min-w-0 flex-1">
                <p class="text-xs text-tinta-500 truncate">
                    {{ modelValue ? modelValue.name : 'Usa la imagen del producto principal' }}
                </p>
                <div class="mt-1 flex items-center gap-3">
                    <label class="text-xs font-medium cursor-pointer hover:underline" style="color:var(--marca);">
                        {{ modelValue ? 'Cambiar' : 'Subir una propia' }}
                        <input type="file" accept="image/*" class="hidden" @change="elegir" />
                    </label>
                    <button v-if="modelValue" type="button" class="text-xs text-aviso-rojo hover:underline"
                        @click="emit('update:modelValue', null)">
                        Quitar
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
