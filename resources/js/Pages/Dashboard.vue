<script setup>
import { ref, computed } from 'vue'
import { router, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ChatResumen from '@/Components/ChatResumen.vue'

const props = defineProps({
    metricas:      Object,
    atencion:      { type: Array,  default: () => [] },
    ops_recientes: Array,
    contexto:      { type: Object, default: () => ({}) },
    permisos:      Object,
})

// ─── Saludo ─────────────────────────────────────────────────────────────────
const saludo = computed(() => {
    const h = new Date().getHours()
    if (h < 12) return 'Buenos días'
    if (h < 19) return 'Buenas tardes'
    return 'Buenas noches'
})

// Solo el primer nombre: "Buenos días, Diego" se lee mejor que el nombre completo.
const primerNombre = computed(() => (props.contexto?.usuario ?? '').split(' ')[0])

const fechaHoy = computed(() =>
    new Date().toLocaleDateString('es-CO', { weekday: 'long', day: 'numeric', month: 'long' })
)

// ─── Tonos del bloque de atención ───────────────────────────────────────────
const tonos = {
    rojo:  { caja: 'border-red-200 bg-red-50',     numero: 'text-red-700',   texto: 'text-red-600',   icono: 'bg-red-100 text-red-600' },
    ambar: { caja: 'border-amber-200 bg-amber-50', numero: 'text-amber-700', texto: 'text-amber-600', icono: 'bg-amber-100 text-amber-600' },
}

// ─── Pull-to-refresh ────────────────────────────────────────────────────────
const PULL_THRESHOLD = 72
const pullStartY  = ref(0)
const pulling     = ref(false)
const pullDelta   = ref(0)
const refreshing  = ref(false)

function onTouchStart(e) {
    if (window.scrollY === 0) {
        pullStartY.value = e.touches[0].clientY
        pulling.value = true
    }
}
function onTouchMove(e) {
    if (!pulling.value) return
    const dy = e.touches[0].clientY - pullStartY.value
    pullDelta.value = dy > 0 ? Math.min(dy, PULL_THRESHOLD * 1.5) : 0
}
function onTouchEnd() {
    if (pulling.value && pullDelta.value >= PULL_THRESHOLD) doRefresh()
    pulling.value = false
    pullDelta.value = 0
}
function doRefresh() {
    if (refreshing.value) return
    refreshing.value = true
    router.reload({ onFinish: () => { refreshing.value = false } })
}

// ─── Tarjetas OPs ───────────────────────────────────────────────────────────
const tarjetasOps = [
    { key: 'en_produccion',   label: 'En producción',   color: 'var(--marca)', bg: '#EFF6FF', href: '/produccion/ops?estado=en_produccion', icon: 'clipboard' },
    { key: 'borrador',        label: 'Por confirmar',   color: '#D97706', bg: '#FFFBEB', href: '/produccion/ops?estado=borrador',      icon: 'clock'     },
    { key: 'calidad',         label: 'Ctrl. calidad',   color: '#7C3AED', bg: '#F5F3FF', href: '/produccion/ops?estado=calidad',       icon: 'check'     },
    { key: 'despachadas_mes', label: 'Despachadas/mes', color: '#059669', bg: '#ECFDF5', href: '/produccion/ops?estado=despachada',    icon: 'truck'     },
]

// ─── Tarjetas Cotizaciones ───────────────────────────────────────────────────
const tarjetasCots = [
    { key: 'cots_enviadas', label: 'Cots. enviadas', color: '#1D4ED8', bg: '#DBEAFE', href: '/cotizaciones?estado=enviada', icon: 'doc-sent' },
    { key: 'cots_mes',      label: 'Cots. del mes',  color: '#4F46E5', bg: '#EEF2FF', href: '/cotizaciones',               icon: 'doc-list' },
]

// ─── Alertas mantenimiento ──────────────────────────────────────────────────
const hayAlertasMant = computed(() =>
    (props.metricas?.mant_vencidos ?? 0) > 0 ||
    (props.metricas?.mant_proximos ?? 0) > 0
)

// ─── Badge estado OP ────────────────────────────────────────────────────────
const badgeClass = (estado) => ({
    borrador:      'bg-tinta-100 text-tinta-500',
    confirmada:    'bg-blue-100 text-blue-800',
    en_produccion: 'bg-yellow-100 text-yellow-800',
    calidad:       'bg-violet-100 text-violet-800',
    reproceso:     'bg-orange-100 text-orange-800',
    despachada:    'bg-green-100 text-green-800',
}[estado] ?? 'bg-tinta-100 text-tinta-500')
</script>

<template>
    <AppLayout title="Dashboard">

        <!-- ── Pull-to-refresh indicator ──────────────────────────────────── -->
        <div
            class="flex items-center justify-center overflow-hidden transition-all duration-200 ease-out"
            :style="{ height: refreshing ? '48px' : pullDelta > 8 ? `${Math.min(pullDelta, 48)}px` : '0px' }"
        >
            <div
                class="w-7 h-7 rounded-full border-2 border-blue-200 border-t-blue-600 transition-opacity"
                :class="refreshing ? 'animate-spin opacity-100' : pullDelta >= PULL_THRESHOLD ? 'opacity-100' : 'opacity-40'"
            ></div>
        </div>

        <!-- ── Contenido ──────────────────────────────────────────────────── -->
        <div
            @touchstart.passive="onTouchStart"
            @touchmove.passive="onTouchMove"
            @touchend.passive="onTouchEnd"
        >

            <!-- Saludo ──────────────────────────────────────────────────── -->
            <div class="mb-5">
                <h1 class="text-xl font-semibold text-tinta-900">
                    {{ saludo }}<template v-if="primerNombre">, {{ primerNombre }}</template>
                </h1>
                <p class="text-xs text-tinta-400 mt-0.5 first-letter:uppercase">
                    {{ fechaHoy }}<template v-if="contexto?.sede"> · {{ contexto.sede }}</template>
                </p>
            </div>

            <ChatResumen />



            <!-- Requiere tu atención ────────────────────────────────────── -->
            <!-- Solo aparece si hay algo. Un bloque vacío que dice "todo bien"
                 se vuelve ruido y la gente deja de mirarlo. -->
            <div v-if="atencion.length" class="mb-5">
                <p class="text-[11px] font-semibold text-tinta-300 uppercase tracking-[0.12em] mb-2.5">Requiere tu atención</p>
                <div class="space-y-2">
                    <Link
                        v-for="a in atencion"
                        :key="a.clave"
                        :href="a.href"
                        :class="['flex items-center gap-3 rounded-2xl border p-4 active:scale-[0.98] transition-transform no-underline',
                                 tonos[a.tono].caja]"
                    >
                        <div :class="['w-10 h-10 rounded-xl flex items-center justify-center shrink-0', tonos[a.tono].icono]">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                            </svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p :class="['text-sm font-semibold', tonos[a.tono].numero]">
                                {{ a.cantidad }} {{ a.titulo }}
                            </p>
                            <p :class="['text-xs mt-0.5', tonos[a.tono].texto]">{{ a.detalle }}</p>
                        </div>
                        <svg class="w-4 h-4 shrink-0 opacity-40" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                        </svg>
                    </Link>
                </div>
            </div>

            <!-- Sección: Producción ─────────────────────────────────────── -->
            <div class="mb-5">
                <p class="text-[11px] font-semibold text-tinta-300 uppercase tracking-[0.12em] mb-2.5">Producción</p>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <Link
                        v-for="t in tarjetasOps"
                        :key="t.key"
                        :href="t.href"
                        class="bg-superficie rounded-lg border border-linea p-4 flex flex-col gap-3 hover:border-tinta-200 active:scale-[.99] transition-all cursor-pointer no-underline"
                    >
                        <div class="w-9 h-9 rounded-lg bg-tinta-50 flex items-center justify-center text-tinta-400">
                            <svg v-if="t.icon === 'clipboard'" class="w-[18px] h-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
                            </svg>
                            <svg v-if="t.icon === 'clock'" class="w-[18px] h-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <svg v-if="t.icon === 'check'" class="w-[18px] h-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <svg v-if="t.icon === 'truck'" class="w-[18px] h-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 17H3a2 2 0 01-2-2V5a2 2 0 012-2h11a2 2 0 012 2v3m0 0h4l3 3v4h-7m0-7H8m9 7a2 2 0 11-4 0 2 2 0 014 0zM7 17a2 2 0 11-4 0 2 2 0 014 0z"/>
                            </svg>
                        </div>
                        <div>
                            <p class="text-[13px] text-tinta-400 leading-snug">{{ t.label }}</p>
                            <p class="text-[26px] font-semibold text-tinta-900 leading-none mt-1.5 tracking-[-0.02em]">{{ metricas?.[t.key] ?? 0 }}</p>
                        </div>
                    </Link>
                </div>
            </div>

            <!-- Sección: Cotizaciones (admin/vendedor) ──────────────────── -->
            <div v-if="permisos?.esCotizador" class="mb-5">
                <p class="text-[11px] font-semibold text-tinta-300 uppercase tracking-[0.12em] mb-2.5">Cotizaciones</p>
                <div class="grid grid-cols-2 gap-3">
                    <Link
                        v-for="t in tarjetasCots"
                        :key="t.key"
                        :href="t.href"
                        class="bg-superficie rounded-lg border border-linea p-4 flex flex-col gap-3 hover:border-tinta-200 active:scale-[.99] transition-all cursor-pointer no-underline"
                    >
                        <div class="w-9 h-9 rounded-lg bg-tinta-50 flex items-center justify-center text-tinta-400">
                            <svg v-if="t.icon === 'doc-sent'" class="w-[18px] h-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                            <svg v-if="t.icon === 'doc-list'" class="w-[18px] h-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 10h16M4 14h16M4 18h16"/>
                            </svg>
                        </div>
                        <div>
                            <p class="text-[13px] text-tinta-400 leading-snug">{{ t.label }}</p>
                            <p class="text-[26px] font-semibold text-tinta-900 leading-none mt-1.5 tracking-[-0.02em]">{{ metricas?.[t.key] ?? 0 }}</p>
                        </div>
                    </Link>
                </div>
            </div>

            <!-- Sección: Alertas mantenimiento (admin/jefe) ─────────────── -->
            <div v-if="permisos?.esMantenimiento && hayAlertasMant" class="mb-5">
                <p class="text-[11px] font-semibold text-tinta-300 uppercase tracking-[0.12em] mb-2.5">Alertas mantenimiento</p>
                <div class="grid grid-cols-2 gap-3">
                    <Link
                        v-if="(metricas?.mant_vencidos ?? 0) > 0"
                        href="/mantenimiento/equipos"
                        class="bg-red-50 border border-red-200 rounded-2xl p-4 flex flex-col gap-2 active:scale-95 transition-transform no-underline"
                    >
                        <div class="w-10 h-10 rounded-xl bg-red-100 flex items-center justify-center">
                            <svg class="w-5 h-5 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                            </svg>
                        </div>
                        <div>
                            <p class="text-2xl font-semibold text-red-700 leading-none">{{ metricas?.mant_vencidos }}</p>
                            <p class="text-xs text-red-500 mt-1">Equipos vencidos</p>
                        </div>
                    </Link>
                    <Link
                        v-if="(metricas?.mant_proximos ?? 0) > 0"
                        href="/mantenimiento/equipos"
                        class="bg-amber-50 border border-amber-200 rounded-2xl p-4 flex flex-col gap-2 active:scale-95 transition-transform no-underline"
                    >
                        <div class="w-10 h-10 rounded-xl bg-amber-100 flex items-center justify-center">
                            <svg class="w-5 h-5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                            </svg>
                        </div>
                        <div>
                            <p class="text-2xl font-semibold text-amber-700 leading-none">{{ metricas?.mant_proximos }}</p>
                            <p class="text-xs text-amber-600 mt-1">Próximos 7 días</p>
                        </div>
                    </Link>
                </div>
            </div>

            <!-- Accesos rápidos ─────────────────────────────────────────── -->
            <div class="flex gap-3 mb-5 overflow-x-auto pb-1 -mx-1 px-1 md:overflow-visible md:flex-wrap">
                <Link
                    v-if="permisos?.puedeCrearOps"
                    href="/produccion/ops/create"
                    class="flex items-center gap-2 shrink-0 px-5 py-3 rounded-xl text-white text-sm font-semibold active:opacity-80 transition-opacity"
                    style="background-color: var(--marca);"
                >
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                    </svg>
                    Nueva OP
                </Link>

                <Link
                    v-if="permisos?.puedeVerificarOps"
                    href="/produccion/ops?estado=borrador"
                    class="flex items-center gap-2 shrink-0 px-5 py-3 rounded-xl text-sm font-semibold border-2 bg-superficie active:bg-yellow-50 transition-colors"
                    style="border-color: #D97706; color: #D97706;"
                >
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 8l2 2 4-4"/>
                    </svg>
                    Verificar
                </Link>

                <Link
                    v-if="permisos?.esCotizador"
                    href="/cotizaciones/crear"
                    class="flex items-center gap-2 shrink-0 px-5 py-3 rounded-xl text-sm font-semibold border-2 bg-superficie active:bg-blue-50 transition-colors"
                    style="border-color: #1D4ED8; color: var(--texto-azul);"
                >
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    Nueva Cot.
                </Link>

                <Link
                    href="/seguimiento"
                    class="flex items-center gap-2 shrink-0 px-5 py-3 rounded-xl text-sm font-semibold border-2 bg-superficie active:bg-tinta-50 transition-colors"
                    style="border-color: var(--texto-3); color: var(--texto-2);"
                >
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    Seguimiento
                </Link>
            </div>

            <!-- OPs recientes ───────────────────────────────────────────── -->
            <div class="bg-superficie rounded-2xl shadow-sm overflow-hidden">
                <div class="flex items-center justify-between px-4 py-3 border-b border-linea">
                    <h2 class="font-semibold text-tinta-900 text-sm">OPs recientes</h2>
                    <Link href="/produccion/ops" class="text-xs font-medium" style="color: var(--marca);">
                        Ver todas →
                    </Link>
                </div>

                <!-- Cards (mobile) -->
                <div v-if="ops_recientes?.length" class="divide-y divide-linea md:hidden">
                    <Link
                        v-for="op in ops_recientes"
                        :key="op.id"
                        :href="`/produccion/ops/${op.id}`"
                        class="flex items-center gap-3 px-4 py-3 active:bg-tinta-50 transition-colors no-underline"
                    >
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 mb-0.5">
                                <span class="font-mono text-sm font-semibold" style="color: var(--marca);">{{ op.numero_op }}</span>
                                <span
                                    class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium"
                                    :class="badgeClass(op.estado)"
                                >{{ op.estado_label }}</span>
                            </div>
                            <p class="text-sm text-tinta-700 truncate">{{ op.cliente }}</p>
                            <p class="text-xs text-tinta-300 mt-0.5">{{ op.created_at }}</p>
                        </div>
                        <svg class="w-4 h-4 text-tinta-200 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                        </svg>
                    </Link>
                </div>

                <!-- Tabla (desktop) -->
                <div v-if="ops_recientes?.length" class="hidden md:block overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-tinta-50 text-xs text-tinta-400 uppercase tracking-wide">
                            <tr>
                                <th class="px-5 py-3 text-left">Número OP</th>
                                <th class="px-5 py-3 text-left">Cliente</th>
                                <th class="px-5 py-3 text-left">Estado</th>
                                <th class="px-5 py-3 text-left">Fecha</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-linea">
                            <tr
                                v-for="op in ops_recientes"
                                :key="op.id"
                                class="hover:bg-tinta-50 transition-colors cursor-pointer"
                                @click="router.visit(`/produccion/ops/${op.id}`)"
                            >
                                <td class="px-5 py-3 font-mono font-semibold" style="color: var(--marca);">{{ op.numero_op }}</td>
                                <td class="px-5 py-3 text-tinta-700">{{ op.cliente }}</td>
                                <td class="px-5 py-3">
                                    <span
                                        class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                                        :class="badgeClass(op.estado)"
                                    >{{ op.estado_label }}</span>
                                </td>
                                <td class="px-5 py-3 text-tinta-400">{{ op.created_at }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-else class="px-5 py-10 text-center text-tinta-300 text-sm">
                    No hay órdenes de producción aún.
                </div>
            </div>

        </div>

    </AppLayout>
</template>
