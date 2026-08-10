<script setup>
import { computed, ref } from 'vue'
import { usePage } from '@inertiajs/vue3'

/**
 * El aviso de la suscripción.
 *
 * Vencer no bloquea el sistema: quien está facturando a las once de la mañana no
 * puede quedarse sin trabajar por una fecha. Pero el aviso aparece en cada carga y
 * no se puede esconder para siempre — solo posponer por hoy —, porque un aviso que
 * se cierra y no vuelve es un aviso que nadie atiende.
 */
const page = usePage()
const licencia = computed(() => page.props.licencia ?? null)

const CLAVE = 'briela.aviso-licencia.pospuesto'
const pospuestoHoy = ref(localStorage.getItem(CLAVE) === new Date().toDateString())

const tipo = computed(() => {
    const l = licencia.value
    if (!l) return null

    if (l.estado === 'suspendida' || l.estado === 'cancelada') return 'bloqueado'
    if (!l.al_dia) return 'vencido'
    if (l.sin_verificar) return 'sin_verificar'
    if (l.por_vencer) return 'por_vencer'

    return null
})

const mensaje = computed(() => {
    const l = licencia.value
    if (!l) return ''

    switch (tipo.value) {
        case 'bloqueado':
            return 'Esta instalación está suspendida. Escríbenos para reactivarla.'
        case 'vencido':
            return 'La suscripción venció. El sistema sigue funcionando, pero el asistente de IA '
                + 'y las actualizaciones están detenidos hasta que se regularice el pago.'
        case 'sin_verificar':
            return l.mensaje ?? 'No se ha podido verificar la licencia.'
        case 'por_vencer':
            return l.dias === 0
                ? 'La suscripción vence hoy.'
                : `La suscripción vence en ${l.dias} ${l.dias === 1 ? 'día' : 'días'}.`
        default:
            return ''
    }
})

// Lo urgente no se puede posponer.
const sePuedePosponer = computed(() => tipo.value === 'por_vencer' || tipo.value === 'sin_verificar')
const visible = computed(() => tipo.value !== null && !(pospuestoHoy.value && sePuedePosponer.value))

function posponer() {
    localStorage.setItem(CLAVE, new Date().toDateString())
    pospuestoHoy.value = true
}
</script>

<template>
    <div
        v-if="visible"
        class="flex items-start gap-3 px-4 py-2.5 text-sm border-b"
        :class="tipo === 'por_vencer'
            ? 'bg-amber-50 border-amber-200 text-amber-800'
            : 'bg-red-50 border-red-200 text-red-700'"
    >
        <svg class="w-4 h-4 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>
        </svg>

        <p class="flex-1 min-w-0 leading-snug">{{ mensaje }}</p>

        <button
            v-if="sePuedePosponer"
            type="button"
            @click="posponer"
            class="shrink-0 text-xs font-semibold underline underline-offset-2 opacity-70 hover:opacity-100"
        >Recordar mañana</button>
    </div>
</template>
