<script setup>
import { computed, watch } from 'vue'

const props = defineProps({
    precioCosto:        { type: Number, default: 0 },
    precioMayorista:    { type: Number, default: 0 },
    precioDistribuidor: { type: Number, default: 0 },
    precioClienteFinal: { type: Number, default: 0 },
    utilidadMin:        { type: Number, default: 15 },
    comisionMin:        { type: Number, default: 0 },
    comisionMax:        { type: Number, default: 0 },
})

const emit = defineEmits([
    'update:comisionMin',
    'update:comisionMax',
    'update:utilidadMin',
    'update:descuentoMaxDistribuidor',
    'update:descuentoMaxClienteFinal',
])

const formatCOP = (v) =>
    new Intl.NumberFormat('es-CO', { maximumFractionDigits: 0 }).format(Math.round(v ?? 0))

// El precio mínimo absoluto de la empresa ES el precio mayorista.
// El margen mayorista ya garantiza la utilidad mínima de la empresa.
const precioMinDistribuidor = computed(() => props.precioMayorista || 0)
const precioMinClienteFinal = computed(() => props.precioDistribuidor || 0)

const descuentoMaxRealDistribuidor = computed(() => {
    const base = props.precioDistribuidor || 0
    const min  = precioMinDistribuidor.value
    if (!base) return 0
    return Math.max(0, parseFloat(((base - min) / base * 100).toFixed(2)))
})

const descuentoMaxRealClienteFinal = computed(() => {
    const base = props.precioClienteFinal || 0
    const min  = precioMinClienteFinal.value
    if (!base) return 0
    return Math.max(0, parseFloat(((base - min) / base * 100).toFixed(2)))
})

const validacionDistribuidor = computed(() => {
    const base = props.precioDistribuidor || 0
    const may  = props.precioMayorista || 0
    if (!base) return { ok: null, mensaje: 'Sin precio distribuidor configurado' }
    if (may && base < may)
        return { ok: false, mensaje: `ERROR: Precio distribuidor ($${formatCOP(base)}) es menor al mayorista ($${formatCOP(may)})` }
    if (base <= may)
        return { ok: false, mensaje: 'Sin margen de negociación — el precio distribuidor debe superar el mayorista' }
    return { ok: true, mensaje: `Rango: $${formatCOP(may)} — $${formatCOP(base)} (${descuentoMaxRealDistribuidor.value}% máx descuento)` }
})

const validacionClienteFinal = computed(() => {
    const base = props.precioClienteFinal || 0
    const dist = props.precioDistribuidor || 0
    if (!base) return { ok: null, mensaje: 'Sin precio cliente final configurado' }
    if (dist && base < dist)
        return { ok: false, mensaje: 'ERROR: Precio cliente final es menor al distribuidor' }
    if (base <= dist)
        return { ok: false, mensaje: 'Sin margen de negociación — el precio cliente final debe superar el distribuidor' }
    return { ok: true, mensaje: `Rango: $${formatCOP(dist)} — $${formatCOP(base)} (${descuentoMaxRealClienteFinal.value}% máx descuento)` }
})

function sugerirComisiones() {
    const dDist  = descuentoMaxRealDistribuidor.value
    const dFinal = descuentoMaxRealClienteFinal.value
    const dMax   = Math.max(dDist, dFinal)
    if (!dMax) return
    const cMax = Math.round(dMax * 0.7 * 10) / 10
    const cMin = Math.round(dMax * 0.4 * 10) / 10
    emit('update:comisionMax', cMax)
    emit('update:comisionMin', cMin)
}

watch(descuentoMaxRealDistribuidor, (v) => emit('update:descuentoMaxDistribuidor', v), { immediate: true })
watch(descuentoMaxRealClienteFinal, (v) => emit('update:descuentoMaxClienteFinal', v), { immediate: true })
</script>

<template>
    <div class="bg-superficie rounded-2xl shadow-sm overflow-hidden">
        <div class="px-5 py-3 border-b border-linea">
            <h3 class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Comisiones y descuentos</h3>
        </div>
        <div class="p-5 space-y-4">

            <!-- Resumen de 4 precios -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                <div class="bg-tinta-50 rounded-lg p-3 text-center">
                    <p class="text-xs text-tinta-300 mb-1">Costo</p>
                    <p class="font-semibold text-tinta-700">${{ formatCOP(precioCosto) }}</p>
                </div>
                <div class="bg-pastel-azul rounded-lg p-3 text-center">
                    <p class="text-xs text-blue-400 mb-1">Mayorista</p>
                    <p class="font-semibold text-aviso-azul">${{ formatCOP(precioMayorista) }}</p>
                    <p class="text-xs text-blue-300 mt-0.5">Precio fijo · sin descuento</p>
                </div>
                <div class="bg-[var(--marca-suave)] rounded-lg p-3 text-center">
                    <p class="text-xs text-[var(--marca)] mb-1">Distribuidor</p>
                    <p class="font-semibold text-[var(--marca)]">${{ formatCOP(precioDistribuidor) }}</p>
                    <p class="text-xs text-tinta-300 mt-0.5">Mín: ${{ formatCOP(precioMinDistribuidor) }}</p>
                </div>
                <div class="bg-pastel-verde rounded-lg p-3 text-center">
                    <p class="text-xs text-green-400 mb-1">Cliente final</p>
                    <p class="font-semibold text-aviso-verde">${{ formatCOP(precioClienteFinal) }}</p>
                    <p class="text-xs text-green-300 mt-0.5">Mín: ${{ formatCOP(precioMinClienteFinal) }}</p>
                </div>
            </div>

            <!-- Canal MAYORISTA — precio fijo -->
            <div class="bg-pastel-azul border border-borde-aviso-azul rounded-lg p-3">
                <div class="flex items-center gap-2 mb-1">
                    <span class="text-xs font-semibold text-aviso-azul uppercase">Mayorista</span>
                    <span class="bg-pastel-azul-2 text-aviso-azul text-xs px-2 py-0.5 rounded-full">Precio fijo</span>
                </div>
                <p class="text-xs text-aviso-azul">Sin comisión de vendedor · Sin descuento al cliente</p>
                <p class="text-xs text-blue-400 mt-1">Precio = ${{ formatCOP(precioMayorista) }} (fijo, no negociable)</p>
            </div>

            <!-- Canal DISTRIBUIDOR -->
            <div class="border border-linea rounded-lg p-3 space-y-3">
                <p class="text-xs font-semibold text-tinta-700 uppercase">Distribuidor</p>
                <div class="grid grid-cols-3 gap-3 text-xs text-center">
                    <div class="bg-tinta-50 rounded p-2">
                        <p class="text-tinta-300">Precio base</p>
                        <p class="font-semibold">${{ formatCOP(precioDistribuidor) }}</p>
                    </div>
                    <div class="bg-pastel-rojo rounded p-2">
                        <p class="text-red-400">Precio mínimo</p>
                        <p class="font-semibold text-aviso-rojo">${{ formatCOP(precioMinDistribuidor) }}</p>
                    </div>
                    <div class="bg-pastel-verde rounded p-2">
                        <p class="text-green-400">Descuento máx real</p>
                        <p class="font-semibold text-aviso-verde">{{ descuentoMaxRealDistribuidor }}%</p>
                    </div>
                </div>
                <div class="flex items-center justify-between mb-1">
                    <span class="text-xs text-tinta-400">Comisión vendedor</span>
                    <button type="button" @click="sugerirComisiones"
                        :disabled="!descuentoMaxRealDistribuidor && !descuentoMaxRealClienteFinal"
                        class="text-xs px-2 py-0.5 rounded-lg border transition-colors disabled:opacity-40"
                        style="border-color:#93C5FD;color:var(--texto-azul);background:var(--pastel-azul);">
                        ✦ Sugerir
                    </button>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-tinta-400 mb-1">Mínima (%)</label>
                        <input :value="comisionMin"
                            @input="emit('update:comisionMin', parseFloat($event.target.value) || 0)"
                            type="number" step="0.01" min="0"
                            class="w-full border border-tinta-200 rounded px-2 py-1.5 text-sm focus:outline-none focus:border-[var(--marca)]" />
                    </div>
                    <div>
                        <label class="block text-xs text-tinta-400 mb-1">Máxima (%)</label>
                        <input :value="comisionMax"
                            @input="emit('update:comisionMax', parseFloat($event.target.value) || 0)"
                            type="number" step="0.01" min="0"
                            class="w-full border border-tinta-200 rounded px-2 py-1.5 text-sm focus:outline-none focus:border-[var(--marca)]" />
                    </div>
                </div>
                <p :class="['text-xs', validacionDistribuidor.ok === true ? 'text-aviso-verde' : validacionDistribuidor.ok === false ? 'text-aviso-rojo' : 'text-tinta-300']">
                    {{ validacionDistribuidor.ok === true ? '✅' : validacionDistribuidor.ok === false ? '❌' : 'ℹ️' }}
                    {{ validacionDistribuidor.mensaje }}
                </p>
            </div>

            <!-- Canal CLIENTE FINAL -->
            <div class="border border-linea rounded-lg p-3 space-y-3">
                <p class="text-xs font-semibold text-tinta-700 uppercase">Cliente Final</p>
                <div class="grid grid-cols-3 gap-3 text-xs text-center">
                    <div class="bg-tinta-50 rounded p-2">
                        <p class="text-tinta-300">Precio base</p>
                        <p class="font-semibold">${{ formatCOP(precioClienteFinal) }}</p>
                    </div>
                    <div class="bg-pastel-rojo rounded p-2">
                        <p class="text-red-400">Precio mínimo</p>
                        <p class="font-semibold text-aviso-rojo">${{ formatCOP(precioMinClienteFinal) }}</p>
                    </div>
                    <div class="bg-pastel-verde rounded p-2">
                        <p class="text-green-400">Descuento máx real</p>
                        <p class="font-semibold text-aviso-verde">{{ descuentoMaxRealClienteFinal }}%</p>
                    </div>
                </div>
                <p :class="['text-xs', validacionClienteFinal.ok === true ? 'text-aviso-verde' : validacionClienteFinal.ok === false ? 'text-aviso-rojo' : 'text-tinta-300']">
                    {{ validacionClienteFinal.ok === true ? '✅' : validacionClienteFinal.ok === false ? '❌' : 'ℹ️' }}
                    {{ validacionClienteFinal.mensaje }}
                </p>
            </div>

        </div>
    </div>
</template>
