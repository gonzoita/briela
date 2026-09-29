<script setup>
import { computed, ref } from 'vue'
import { router, useForm, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { formatPct } from '@/formato'

const props = defineProps({
    monedas:   { type: Array,  default: () => [] },
    tasas:     { type: Object, default: () => ({}) },
    historial: { type: Array,  default: () => [] },
    ajustes:   { type: Object, default: () => ({ colchon_pct: 0, modo: 'fija' }) },
    productos_en_moneda: { type: Number, default: 0 },
})

const page = usePage()
const puedeEditar = computed(() => (page.props.auth?.permisosLista ?? []).includes('configuracion.editar'))

const FUENTES = {
    superfinanciera: 'TRM oficial · Superintendencia Financiera',
    bce:             'TRM × cotización del Banco Central Europeo',
    manual:          'Escrita a mano',
}

const hoy = new Date().toLocaleDateString('en-CA')
const formatTasa  = (v) => new Intl.NumberFormat('es-CO', { minimumFractionDigits: 2, maximumFractionDigits: 4 }).format(Number(v) || 0)
const formatFecha = (f) => f ? new Date(f + 'T12:00:00').toLocaleDateString('es-CO', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }) : ''

// ─── Actualizar ahora ────────────────────────────────────────────────────────
const actualizando = ref(false)
function actualizar() {
    router.post('/configuracion/monedas/actualizar', {}, {
        preserveScroll: true,
        onStart:  () => { actualizando.value = true },
        onFinish: () => { actualizando.value = false },
    })
}

// ─── A mano ──────────────────────────────────────────────────────────────────
const manual = useForm({ moneda: props.monedas[0]?.codigo ?? 'USD', valor: '' })
function guardarManual() {
    manual.post('/configuracion/monedas/manual', { preserveScroll: true, onSuccess: () => manual.reset('valor') })
}

// ─── Ajustes ─────────────────────────────────────────────────────────────────
const ajustes = useForm({ colchon_pct: props.ajustes.colchon_pct ?? 0, modo: props.ajustes.modo ?? 'fija' })
function guardarAjustes() {
    ajustes.post('/configuracion/monedas', { preserveScroll: true })
}

const ic = 'w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm focus:ring-4 focus:ring-[var(--marca-suave)] focus:outline-none'
</script>

<template>
    <AppLayout title="Monedas y tasa de cambio">
        <div class="max-w-2xl mx-auto space-y-4 pb-8">

            <a href="/configuracion" @click.prevent="router.visit('/configuracion')"
                class="inline-flex items-center gap-1.5 text-sm text-tinta-400 hover:text-tinta-700">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                </svg>
                Configuración
            </a>

            <!-- Tasas vigentes -->
            <div class="bg-superficie rounded-xl border border-linea p-4">
                <div class="flex flex-wrap items-start justify-between gap-3 mb-3">
                    <div>
                        <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Tasas vigentes</p>
                        <p class="text-xs text-tinta-400 mt-1">
                            Se traen solas cada mañana. Con ellas se recalcula el costo en pesos de lo que
                            se compra en otra moneda, y se muestran las cotizaciones en dólares o euros.
                        </p>
                    </div>
                    <button v-if="puedeEditar" type="button" @click="actualizar" :disabled="actualizando"
                        class="shrink-0 text-xs font-semibold px-3 py-1.5 rounded-lg text-white disabled:opacity-60"
                        style="background:var(--marca);">
                        {{ actualizando ? 'Consultando…' : 'Actualizar ahora' }}
                    </button>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div v-for="m in monedas" :key="m.codigo" class="rounded-xl border border-linea p-3">
                        <p class="text-xs text-tinta-400">{{ m.nombre }} · {{ m.codigo }}</p>
                        <template v-if="tasas[m.codigo]">
                            <p class="text-xl font-semibold text-tinta-900 mt-0.5">${{ formatTasa(tasas[m.codigo].valor) }}</p>
                            <p class="text-xs mt-1" :class="tasas[m.codigo].de_hoy ? 'text-tinta-400' : 'text-aviso-ambar font-semibold'">
                                {{ tasas[m.codigo].de_hoy ? 'De hoy' : `Del ${formatFecha(tasas[m.codigo].fecha)}: la de hoy no ha llegado` }}
                            </p>
                            <p class="text-[11px] text-tinta-300 mt-0.5">{{ FUENTES[tasas[m.codigo].fuente] ?? tasas[m.codigo].fuente }}</p>
                        </template>
                        <p v-else class="text-sm text-aviso-ambar mt-1">
                            Sin tasa todavía. Oprime «Actualizar ahora» o escríbela abajo.
                        </p>
                    </div>
                </div>

                <p v-if="productos_en_moneda > 0" class="text-xs text-tinta-400 mt-3">
                    {{ productos_en_moneda }} producto{{ productos_en_moneda === 1 ? '' : 's' }} con el costo en otra moneda.
                </p>
            </div>

            <!-- A mano -->
            <form v-if="puedeEditar" @submit.prevent="guardarManual" class="bg-superficie rounded-xl border border-linea p-4 space-y-3">
                <div>
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Escribir la tasa de hoy</p>
                    <p class="text-xs text-tinta-400 mt-1">
                        Para cuando el servidor no puede salir a internet, o se negoció otra tasa. La
                        consulta automática no pisa una tasa escrita a mano el mismo día.
                    </p>
                </div>
                <div class="grid grid-cols-3 gap-2">
                    <select v-model="manual.moneda" :class="ic" aria-label="Moneda">
                        <option v-for="m in monedas" :key="m.codigo" :value="m.codigo">{{ m.codigo }}</option>
                    </select>
                    <input v-model="manual.valor" type="number" step="0.0001" min="1" placeholder="Pesos por 1 unidad"
                        :class="ic + ' col-span-2'" aria-label="Valor"/>
                </div>
                <p v-if="manual.errors.valor" class="text-xs text-aviso-rojo">{{ manual.errors.valor }}</p>
                <button type="submit" :disabled="manual.processing || ! manual.valor"
                    class="w-full py-2 rounded-lg text-sm font-medium text-white disabled:opacity-60" style="background:var(--marca);">
                    Guardar tasa de hoy
                </button>
            </form>

            <!-- Ajustes -->
            <form @submit.prevent="guardarAjustes" class="bg-superficie rounded-xl border border-linea p-4 space-y-4">
                <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Cómo se usan</p>

                <div>
                    <label class="block text-sm font-medium text-tinta-700 mb-1">Colchón sobre la tasa para costos (%)</label>
                    <input v-model.number="ajustes.colchon_pct" type="number" step="0.01" min="0" max="50" :class="ic" :disabled="! puedeEditar"/>
                    <p class="text-xs text-tinta-400 mt-1">
                        Un costo de 1.000 USD con la tasa en $4.000 y un colchón de {{ formatPct(ajustes.colchon_pct || 0) }}%
                        queda en ${{ new Intl.NumberFormat('es-CO').format(Math.round(1000 * 4000 * (1 + (ajustes.colchon_pct || 0) / 100))) }}.
                        Cubre lo que se mueve la tasa entre cotizar y pagarle al proveedor.
                    </p>
                </div>

                <div>
                    <p class="text-sm font-medium text-tinta-700 mb-2">Cotizaciones en dólares o euros</p>
                    <div class="space-y-2">
                        <label class="flex items-start gap-2 rounded-lg border p-3 cursor-pointer"
                            :class="ajustes.modo === 'fija' ? 'border-borde-aviso-azul bg-pastel-azul' : 'border-linea'">
                            <input v-model="ajustes.modo" type="radio" value="fija" class="mt-0.5" :disabled="! puedeEditar"/>
                            <span class="text-sm text-tinta-700">
                                <span class="font-semibold">La tasa queda fija</span>
                                <span class="block text-xs text-tinta-400">El cliente ve siempre el mismo precio en su moneda. Si la tasa baja, la empresa recibe menos pesos.</span>
                            </span>
                        </label>
                        <label class="flex items-start gap-2 rounded-lg border p-3 cursor-pointer"
                            :class="ajustes.modo === 'diaria' ? 'border-borde-aviso-azul bg-pastel-azul' : 'border-linea'">
                            <input v-model="ajustes.modo" type="radio" value="diaria" class="mt-0.5" :disabled="! puedeEditar"/>
                            <span class="text-sm text-tinta-700">
                                <span class="font-semibold">Sigue a la TRM hasta que la aprueben</span>
                                <span class="block text-xs text-tinta-400">Lo que se protege es el valor en pesos: el precio en la moneda del cliente cambia cada día. Al aprobarse, queda fija.</span>
                            </span>
                        </label>
                    </div>
                </div>

                <button v-if="puedeEditar" type="submit" :disabled="ajustes.processing"
                    class="w-full py-2 rounded-lg text-sm font-medium text-white disabled:opacity-60" style="background:var(--marca);">
                    Guardar
                </button>
            </form>

            <!-- Historial -->
            <div v-if="historial.length" class="bg-superficie rounded-xl border border-linea p-4">
                <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-2">Últimas tasas</p>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-xs text-tinta-400 text-left">
                                <th class="py-1.5 pr-3 font-medium">Fecha</th>
                                <th class="py-1.5 pr-3 font-medium">Moneda</th>
                                <th class="py-1.5 pr-3 font-medium text-right">Valor</th>
                                <th class="py-1.5 font-medium">Fuente</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-separador">
                            <tr v-for="t in historial" :key="t.moneda + t.fecha">
                                <td class="py-1.5 pr-3 whitespace-nowrap" :class="t.fecha === hoy ? 'font-semibold text-tinta-900' : 'text-tinta-600'">{{ formatFecha(t.fecha) }}</td>
                                <td class="py-1.5 pr-3 text-tinta-600">{{ t.moneda }}</td>
                                <td class="py-1.5 pr-3 text-right text-tinta-900">${{ formatTasa(t.valor) }}</td>
                                <td class="py-1.5 text-xs text-tinta-400">{{ FUENTES[t.fuente] ?? t.fuente }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
