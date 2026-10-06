<script setup>
import { computed, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    // Lo que dijo el panel de Briela en el último latido; null si nunca lo ha dicho.
    correo:        { type: Object, default: null },
    por_briela:    { type: Boolean, default: false },
    tiene_serial:  { type: Boolean, default: false },
    smtp_respaldo: { type: Boolean, default: false },
    supresiones:   { type: Object, default: () => ({ total: 0, ultimas: [] }) },
})

const page = usePage()
const puedeEditar = computed(() => (page.props.auth?.permisosLista ?? []).includes('configuracion.editar'))

const numero = (v) => new Intl.NumberFormat('es-CO').format(Number(v) || 0)
const MOTIVOS = { rebote_duro: 'El buzón no existe', queja: 'Lo marcó como spam', baja: 'Se dio de baja' }

const estado = computed(() => {
    if (props.por_briela) return { texto: 'El correo sale por Briela', clase: 'bg-pastel-verde border-borde-aviso-verde text-aviso-verde' }
    if (props.correo?.suspendido) return { texto: 'El correo por Briela está suspendido', clase: 'bg-pastel-rojo border-borde-aviso-rojo text-aviso-rojo' }
    if (props.correo?.dominio) return { texto: 'El dominio de envío se está verificando', clase: 'bg-pastel-ambar border-borde-aviso-ambar text-aviso-ambar' }
    return { texto: 'El correo todavía no sale por Briela', clase: 'bg-pastel-ambar border-borde-aviso-ambar text-aviso-ambar' }
})

const pctMes = computed(() => {
    const incluidos = Number(props.correo?.incluidos_mes) || 0
    return incluidos > 0 ? Math.min(100, Math.round((Number(props.correo?.masivos_mes) || 0) / incluidos * 100)) : 0
})

// ─── Actualizar y probar ─────────────────────────────────────────────────────
const actualizando = ref(false)
function actualizar() {
    router.post('/configuracion/correo/actualizar', {}, {
        preserveScroll: true,
        onStart: () => { actualizando.value = true },
        onFinish: () => { actualizando.value = false },
    })
}

const destino = ref(page.props.auth?.user?.email ?? '')
const probando = ref(false)
const prueba = ref(null)
async function probar() {
    probando.value = true
    prueba.value = null
    try {
        const xsrf = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))
        const res = await fetch('/configuracion/correo/probar', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrf ? decodeURIComponent(xsrf.split('=')[1]) : '',
            },
            body: JSON.stringify({ email: destino.value }),
        })
        prueba.value = await res.json().catch(() => ({ ok: false, mensaje: `Error ${res.status}` }))
    } catch (e) {
        prueba.value = { ok: false, mensaje: e.message }
    } finally {
        probando.value = false
    }
}

const ic = 'w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm focus:ring-4 focus:ring-[var(--marca-suave)] focus:outline-none'
</script>

<template>
    <AppLayout title="Correo">
        <div class="max-w-2xl mx-auto space-y-4 pb-8">

            <a href="/configuracion" @click.prevent="router.visit('/configuracion')"
                class="inline-flex items-center gap-1.5 text-sm text-tinta-400 hover:text-tinta-700">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                </svg>
                Configuración
            </a>

            <!-- Estado -->
            <div class="rounded-xl border px-4 py-3" :class="estado.clase">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-sm font-semibold">{{ estado.texto }}</p>
                    <button v-if="puedeEditar && tiene_serial" type="button" @click="actualizar" :disabled="actualizando"
                        class="text-xs font-semibold underline underline-offset-2 disabled:opacity-60">
                        {{ actualizando ? 'Consultando…' : 'Volver a consultar' }}
                    </button>
                </div>
                <p class="text-xs mt-1">
                    <template v-if="por_briela">
                        Las notificaciones, las cotizaciones y los formularios salen firmados desde el
                        dominio de abajo, y no caen en spam por falta de firma.
                    </template>
                    <template v-else-if="! tiene_serial">
                        Esta instalación no tiene serial, así que no puede enviar por Briela.
                    </template>
                    <template v-else>
                        Lo activa el equipo de Briela desde su panel. Mientras tanto, los correos salen
                        {{ smtp_respaldo ? 'por el SMTP configurado en esta instalación' : 'solo si se configura un SMTP en Configuración, pestaña Email' }}.
                    </template>
                </p>
            </div>

            <!-- Dominio y remitentes -->
            <div v-if="correo?.dominio" class="bg-superficie rounded-xl border border-linea p-4 space-y-3">
                <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Desde dónde sale</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                    <div>
                        <p class="text-xs text-tinta-400">Notificaciones</p>
                        <p class="font-mono text-tinta-900 break-all">{{ correo.remitentes?.transaccional }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-tinta-400">Boletines y campañas</p>
                        <p class="font-mono text-tinta-900 break-all">{{ correo.remitentes?.masivo }}</p>
                    </div>
                </div>
                <p class="text-xs text-tinta-400">
                    Las respuestas llegan al correo de la empresa configurado en Perfil fiscal.
                </p>
            </div>

            <!-- El mes -->
            <div v-if="correo" class="bg-superficie rounded-xl border border-linea p-4 space-y-3">
                <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Este mes</p>
                <div>
                    <div class="flex justify-between text-sm">
                        <span class="text-tinta-700">Correo masivo</span>
                        <span class="text-tinta-900 font-semibold">{{ numero(correo.masivos_mes) }} <span class="text-tinta-400 font-normal">de {{ numero(correo.incluidos_mes) }} incluidos</span></span>
                    </div>
                    <div class="mt-1.5 h-2 rounded-full bg-tinta-100 overflow-hidden">
                        <div class="h-full rounded-full" :style="`width:${pctMes}%; background:var(--marca);`"/>
                    </div>
                    <p v-if="Number(correo.masivos_mes) > Number(correo.incluidos_mes)" class="text-xs text-aviso-ambar mt-1">
                        Se pasó de lo incluido: los {{ numero(correo.masivos_mes - correo.incluidos_mes) }} adicionales se suman a la factura del mes.
                    </p>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-tinta-700">Notificaciones</span>
                    <span class="text-tinta-900">{{ numero(correo.transaccionales_mes) }} <span class="text-tinta-400">· no se cobran</span></span>
                </div>
                <p class="text-[11px] text-tinta-300">Cifras del último latido con el panel de Briela.</p>
            </div>

            <!-- Prueba -->
            <div v-if="puedeEditar" class="bg-superficie rounded-xl border border-linea p-4 space-y-3">
                <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Enviar un correo de prueba</p>
                <div class="flex flex-col sm:flex-row gap-2">
                    <input v-model="destino" type="email" :class="ic" placeholder="tu@correo.com" aria-label="Correo de prueba"/>
                    <button type="button" @click="probar" :disabled="probando || ! destino"
                        class="shrink-0 px-4 py-2 rounded-lg text-sm font-medium text-white disabled:opacity-60" style="background:var(--marca);">
                        {{ probando ? 'Enviando…' : 'Enviar prueba' }}
                    </button>
                </div>
                <p v-if="prueba" class="text-xs rounded-lg px-3 py-2 border"
                    :class="prueba.ok ? 'bg-pastel-verde border-borde-aviso-verde text-aviso-verde' : 'bg-pastel-rojo border-borde-aviso-rojo text-aviso-rojo'">
                    {{ prueba.ok ? `Enviado por ${prueba.via}. Revisa la bandeja de entrada.` : prueba.mensaje }}
                </p>
            </div>

            <!-- Supresiones -->
            <div class="bg-superficie rounded-xl border border-linea p-4">
                <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1">A quién no se le escribe</p>
                <p class="text-xs text-tinta-400 mb-3">
                    {{ numero(supresiones.total) }} dirección{{ supresiones.total === 1 ? '' : 'es' }} que rebotaron, se quejaron o
                    se dieron de baja. Escribirles otra vez daña la reputación del dominio y hace que los demás
                    correos caigan en spam.
                </p>
                <div v-if="supresiones.ultimas.length" class="divide-y divide-separador">
                    <div v-for="s in supresiones.ultimas" :key="s.email" class="py-1.5 flex flex-wrap justify-between gap-2 text-sm">
                        <span class="font-mono text-tinta-700 break-all">{{ s.email }}</span>
                        <span class="text-xs text-tinta-400">{{ MOTIVOS[s.motivo] ?? s.motivo }}</span>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
