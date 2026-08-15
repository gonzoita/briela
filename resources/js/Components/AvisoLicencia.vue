<script setup>
import { computed, ref } from 'vue'
import { usePage, router } from '@inertiajs/vue3'

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

// Se posterga por tipo de aviso y no de golpe: haber dicho "recordar mañana" al
// aviso de vencimiento no debería esconder también el de una versión nueva.
const pospuestos = ref(JSON.parse(localStorage.getItem('briela.avisos-pospuestos') ?? '{}'))

const tipo = computed(() => {
    const l = licencia.value
    if (!l) return null

    if (l.estado === 'suspendida' || l.estado === 'cancelada') return 'bloqueado'
    if (!l.al_dia) return 'vencido'
    if (l.sin_verificar) return 'sin_verificar'
    if (l.por_vencer) return 'por_vencer'
    // Una versión nueva no es un problema, así que va en tono neutro y de último:
    // si además la suscripción está por vencer, eso es lo que hay que atender.
    if (l.actualizacion) return 'actualizacion'

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
        case 'actualizacion':
            return l.actualizacion.obligatoria
                ? `La versión ${l.actualizacion.version} es obligatoria. Hasta instalarla, el `
                    + 'asistente de IA queda detenido.'
                : `Hay una versión nueva disponible: ${l.actualizacion.version}.`
        default:
            return ''
    }
})

// Lo urgente no se puede posponer.
const sePuedePosponer = computed(() =>
    tipo.value === 'por_vencer'
    || tipo.value === 'sin_verificar'
    || (tipo.value === 'actualizacion' && ! licencia.value?.actualizacion?.obligatoria)
)
const pospuestoHoy = computed(() => pospuestos.value[tipo.value] === new Date().toDateString())
const visible = computed(() => tipo.value !== null && !(pospuestoHoy.value && sePuedePosponer.value))

function posponer() {
    pospuestos.value = { ...pospuestos.value, [tipo.value]: new Date().toDateString() }
    localStorage.setItem('briela.avisos-pospuestos', JSON.stringify(pospuestos.value))
}
</script>

<template>
    <div
        v-if="visible"
        class="flex items-start gap-3 px-4 py-2.5 text-sm border-b"
        :class="tipo === 'actualizacion' && !licencia.actualizacion.obligatoria
            ? 'bg-pastel-azul border-borde-aviso-azul text-aviso-azul'
            : tipo === 'por_vencer'
                ? 'bg-pastel-ambar border-borde-aviso-ambar text-aviso-ambar'
                : 'bg-pastel-rojo border-borde-aviso-rojo text-aviso-rojo'"
    >
        <svg v-if="tipo === 'actualizacion'" class="w-4 h-4 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v12m0 0l-4-4m4 4l4-4M4 18v1a1 1 0 001 1h14a1 1 0 001-1v-1"/>
        </svg>
        <svg v-else class="w-4 h-4 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/>
        </svg>

        <p class="flex-1 min-w-0 leading-snug">{{ mensaje }}</p>

        <a
            v-if="tipo === 'actualizacion'"
            href="/administracion/actualizacion"
            @click.prevent="router.visit('/administracion/actualizacion')"
            class="shrink-0 text-xs font-semibold underline underline-offset-2"
        >Actualizar</a>

        <button
            v-if="sePuedePosponer"
            type="button"
            @click="posponer"
            class="shrink-0 text-xs font-semibold underline underline-offset-2 opacity-70 hover:opacity-100"
        >Recordar mañana</button>
    </div>
</template>
