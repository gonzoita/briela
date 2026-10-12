<script setup>
/**
 * La tarjeta con que la guía pide permiso antes de hacer algo: remisionar, registrar un pago,
 * generar una orden, cerrar un paso…
 *
 * La guía nunca ejecuta una transacción por su cuenta: la propone con un resumen, y se hace solo
 * al tocar «Confirmar». El servidor vuelve a revisar permiso, sede y estado al confirmar
 * (`TransaccionesIaService::ejecutar`), así que esta tarjeta no decide nada: pregunta.
 */
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'

const props = defineProps({
    // { accion, parametros, resumen }
    propuesta: { type: Object, required: true },
})

const emit = defineEmits(['resultado'])

// pendiente | enviando | hecho | error | cancelada
const estado    = ref(props.propuesta.estado ?? 'pendiente')
const resultado = ref(props.propuesta.resultado ?? null)

function cookie(nombre) {
    const m = document.cookie.match(new RegExp('(^| )' + nombre + '=([^;]+)'))
    return m ? decodeURIComponent(m[2]) : ''
}

async function confirmar() {
    if (estado.value !== 'pendiente') return
    estado.value = 'enviando'

    try {
        const resp = await fetch('/api/asistente/confirmar', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-XSRF-TOKEN': cookie('XSRF-TOKEN') },
            credentials: 'same-origin',
            body: JSON.stringify({ accion: props.propuesta.accion, parametros: props.propuesta.parametros ?? {} }),
        })
        const data = await resp.json().catch(() => ({}))

        resultado.value = { exito: !! data.exito, mensaje: data.mensaje ?? data.message ?? 'No se pudo hacer.', url: data.url ?? null }
        estado.value    = data.exito ? 'hecho' : 'error'
    } catch {
        resultado.value = { exito: false, mensaje: 'No hubo conexión. Inténtalo otra vez.' }
        estado.value    = 'error'
    }

    props.propuesta.estado    = estado.value
    props.propuesta.resultado = resultado.value
    emit('resultado', resultado.value)
}

function cancelar() {
    estado.value = 'cancelada'
    props.propuesta.estado = 'cancelada'
}
</script>

<template>
    <div class="mt-2 rounded-xl border p-3 text-sm"
        :class="estado === 'hecho' ? 'border-borde-aviso-verde bg-pastel-verde'
            : estado === 'error' ? 'border-borde-aviso-rojo bg-pastel-rojo'
            : 'border-borde-aviso-azul bg-pastel-azul'">
        <p class="text-[11px] font-semibold uppercase tracking-[0.12em] mb-1"
            :class="estado === 'hecho' ? 'text-aviso-verde' : estado === 'error' ? 'text-aviso-rojo' : 'text-aviso-azul'">
            {{ estado === 'hecho' ? 'Hecho' : estado === 'error' ? 'No se pudo' : estado === 'cancelada' ? 'Cancelado' : 'Para confirmar' }}
        </p>
        <p class="text-tinta-800">{{ propuesta.resumen }}</p>

        <p v-if="resultado" class="mt-1.5 text-xs" :class="resultado.exito ? 'text-aviso-verde' : 'text-aviso-rojo'">
            {{ resultado.mensaje }}
            <button v-if="resultado.url" type="button" @click="router.visit(resultado.url)" class="underline font-semibold ml-1">Ver</button>
        </p>

        <div v-if="estado === 'pendiente' || estado === 'enviando'" class="flex gap-2 mt-2.5">
            <button type="button" @click="confirmar" :disabled="estado === 'enviando'"
                class="flex-1 py-1.5 rounded-lg text-white text-sm font-semibold disabled:opacity-60" style="background:var(--marca);">
                {{ estado === 'enviando' ? 'Haciéndolo…' : 'Confirmar' }}
            </button>
            <button type="button" @click="cancelar" :disabled="estado === 'enviando'"
                class="px-3 py-1.5 rounded-lg border border-linea text-sm text-tinta-500 bg-superficie">
                Cancelar
            </button>
        </div>
    </div>
</template>
