<script setup>
import { formatCantidad } from '@/formato'
import AppLayout from '@/Layouts/AppLayout.vue'

// Lo que las cotizaciones enviadas tienen apartado por 24 horas. Apartar es una marca: el stock
// real no se mueve. Ver `ReservaStockService`.
defineProps({
    activas:   { type: Array, default: () => [] },
    recientes: { type: Array, default: () => [] },
    horas:     { type: Number, default: 24 },
    activo:    { type: Boolean, default: true },
})

// Cuánto falta, en la unidad que se entienda a primera vista.
function restante(iso) {
    const min = Math.round((new Date(iso).getTime() - Date.now()) / 60000)
    if (min <= 0) return 'vence ya'
    if (min < 60) return `${min} min`

    return `${Math.floor(min / 60)} h ${min % 60} min`
}

const fecha = (iso) => iso
    ? new Date(iso).toLocaleString('es-CO', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })
    : ''

const etiqueta = {
    cedida:  { texto: 'Cedida a una venta', clases: 'bg-pastel-ambar-2 text-aviso-ambar' },
    vencida: { texto: 'Venció el plazo',    clases: 'bg-pastel-rojo-2 text-aviso-rojo' },
    activa:  { texto: 'Cedida en parte',    clases: 'bg-pastel-ambar-2 text-aviso-ambar' },
}
</script>

<template>
    <AppLayout title="Stock apartado">
        <div class="max-w-5xl mx-auto px-4 py-6">
            <h1 class="text-xl font-bold text-tinta-800">Stock apartado</h1>
            <p class="mt-1 text-sm text-tinta-400">
                Una cotización enviada aparta sus productos por {{ horas }} horas. Es solo una marca: el inventario
                no se mueve. Si una venta se aprueba y necesita esas unidades, se le ceden y se avisa al vendedor y a
                administración.
            </p>

            <p v-if="!activo" class="mt-4 text-sm rounded-xl border border-borde-aviso-ambar bg-pastel-ambar text-aviso-ambar p-3">
                Los módulos de inventario y cotizaciones tienen que estar encendidos para apartar stock.
            </p>

            <section class="mt-6">
                <h2 class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-2">Apartado ahora</h2>
                <div v-if="!activas.length" class="bg-superficie rounded-2xl border border-linea p-5 text-sm text-tinta-300">
                    Ninguna cotización tiene stock apartado en este momento.
                </div>
                <div v-else class="bg-superficie rounded-2xl border border-linea divide-y divide-separador">
                    <div v-for="r in activas" :key="r.id" class="p-4 flex flex-wrap items-center justify-between gap-2">
                        <div class="min-w-0">
                            <a :href="`/productos/${r.producto_id}`" class="text-sm font-medium text-tinta-700 hover:underline">{{ r.producto }}</a>
                            <p class="text-xs text-tinta-400">
                                Cotización
                                <a :href="`/cotizaciones/${r.cotizacion_id}`" class="font-medium hover:underline">{{ r.cotizacion }}</a>
                                <span v-if="r.vendedor"> · {{ r.vendedor }}</span>
                            </p>
                        </div>
                        <div class="text-right shrink-0">
                            <p class="text-sm font-semibold text-tinta-700">
                                {{ formatCantidad(r.cantidad - r.cedida) }} {{ r.unidad }}
                            </p>
                            <p class="text-xs text-tinta-400">{{ restante(r.expira_at) }} · hasta {{ fecha(r.expira_at) }}</p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="mt-6">
                <h2 class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-2">Últimos 7 días: lo que se cedió o venció</h2>
                <div v-if="!recientes.length" class="bg-superficie rounded-2xl border border-linea p-5 text-sm text-tinta-300">
                    Nada se ha cedido ni vencido en la última semana.
                </div>
                <div v-else class="bg-superficie rounded-2xl border border-linea divide-y divide-separador">
                    <div v-for="r in recientes" :key="r.id" class="p-4 flex flex-wrap items-center justify-between gap-2">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-tinta-700">{{ r.producto }}</p>
                            <p class="text-xs text-tinta-400">
                                Cotización
                                <a :href="`/cotizaciones/${r.cotizacion_id}`" class="font-medium hover:underline">{{ r.cotizacion }}</a>
                                <span v-if="r.vendedor"> · {{ r.vendedor }}</span>
                                <span v-if="r.cedida_a"> · la usó la venta {{ r.cedida_a }}</span>
                            </p>
                        </div>
                        <div class="text-right shrink-0">
                            <span class="inline-flex text-xs font-semibold px-2.5 py-0.5 rounded-full" :class="etiqueta[r.estado]?.clases">
                                {{ etiqueta[r.estado]?.texto }}
                            </span>
                            <p class="text-xs text-tinta-400 mt-1">
                                <template v-if="r.cedida">{{ formatCantidad(r.cedida) }} de {{ formatCantidad(r.cantidad) }} {{ r.unidad }}</template>
                                <template v-else>{{ formatCantidad(r.cantidad) }} {{ r.unidad }}</template>
                            </p>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
