<script setup>
/**
 * Publicar un producto o un ensamble en el sitio web del cliente.
 *
 * El mismo control sirve para los dos: en WordPress un ensamble es un producto más.
 * Guarda solo, sin recargar la pantalla, y cuenta qué pasó — incluido el caso de que el
 * sitio no se haya podido avisar en el momento, que no es un error: el plugin sincroniza
 * cada hora igual.
 */
import { ref, computed } from 'vue'

const props = defineProps({
    tipo:        { type: String, required: true },   // 'producto' | 'ensamble'
    id:          { type: Number, required: true },
    publicado:   { type: Boolean, default: false },
    publicadoAt: { type: String, default: null },
    // Cuando falta el precio público, publicar igual está permitido: la ficha sale sin
    // cifra y con el botón de cotizar. Pero conviene decirlo antes, no después.
    sinPrecio:   { type: Boolean, default: false },
})

const emit = defineEmits(['cambio'])

const estado    = ref(props.publicado)
const guardando = ref(false)
const aviso     = ref('')
const error     = ref('')

const csrf = () => {
    const c = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))
    return c ? decodeURIComponent(c.split('=')[1]) : ''
}

const fecha = computed(() => {
    if (! props.publicadoAt) return ''
    return new Date(props.publicadoAt).toLocaleDateString('es-CO', { day: 'numeric', month: 'short', year: 'numeric' })
})

async function alternar() {
    if (guardando.value) return

    const siguiente = ! estado.value
    guardando.value = true
    error.value = ''
    aviso.value = ''

    try {
        const res = await fetch(`/api/publicacion-web/${props.tipo}/${props.id}`, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': csrf(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ publicar: siguiente }),
        })

        const data = await res.json().catch(() => null)

        if (! res.ok || ! data?.ok) {
            throw new Error(data?.mensaje || `No se pudo guardar (${res.status}).`)
        }

        estado.value = data.publicado_web
        aviso.value  = data.mensaje
        emit('cambio', { publicado: data.publicado_web, publicado_web_at: data.publicado_web_at })
    } catch (e) {
        error.value = e.message
    } finally {
        guardando.value = false
    }
}
</script>

<template>
    <div class="bg-superficie rounded-2xl shadow-sm p-4">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <h3 class="text-sm font-semibold text-tinta-700 flex items-center gap-2">
                    <svg class="w-4 h-4 text-tinta-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.6 9h16.8M3.6 15h16.8M12 3a15 15 0 010 18M12 3a15 15 0 000 18"/>
                    </svg>
                    Sitio web
                </h3>
                <p class="text-xs text-tinta-400 mt-1">
                    <template v-if="estado">
                        Publicado{{ fecha ? ` desde el ${fecha}` : '' }}. El precio y las existencias
                        los manda Briela; el texto y las fotos se pueden mejorar en el sitio.
                    </template>
                    <template v-else>
                        No está en la web. Al publicarlo, el sitio crea su ficha en la siguiente
                        sincronización.
                    </template>
                </p>
            </div>

            <button
                type="button"
                @click="alternar"
                :disabled="guardando"
                class="relative w-11 h-6 rounded-full shrink-0 transition-colors disabled:opacity-50"
                :style="estado ? 'background:var(--marca);' : 'background:var(--tinta-200);'"
                :title="estado ? 'Retirar del sitio web' : 'Publicar en el sitio web'"
            >
                <span
                    class="absolute top-0.5 w-5 h-5 rounded-full bg-superficie shadow transition-transform"
                    :style="estado ? 'transform:translateX(22px);' : 'transform:translateX(2px);'"
                />
            </button>
        </div>

        <p v-if="sinPrecio && estado" class="mt-3 text-xs px-3 py-2 rounded-xl"
            style="background:var(--pastel-ambar); color:var(--texto-ambar);">
            No tiene precio público cargado, así que la ficha sale sin cifra y con el botón de
            cotizar. Ponle precio si quieres que se vea.
        </p>

        <p v-if="aviso" class="mt-3 text-xs text-tinta-500">{{ aviso }}</p>

        <p v-if="error" class="mt-3 text-xs px-3 py-2 rounded-xl"
            style="background:var(--pastel-rojo, #FEF2F2); color:#B91C1C;">
            {{ error }}
        </p>
    </div>
</template>
