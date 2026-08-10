<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import { router } from '@inertiajs/vue3'
import { ref, computed } from 'vue'

const props = defineProps({
    producto: Object,
    bodegas:  Array,
})

const p = computed(() => props.producto)

const imagenActiva = ref(p.value.imagenes?.[0] ?? null)

const setImagenActiva = (img) => { imagenActiva.value = img }

const formatCOP = (v) =>
    new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', minimumFractionDigits: 0 }).format(v ?? 0)

const badgeStyle = (color) => {
    const map = {
        blue:   { bg: '#DBEAFE', text: '#1D4ED8' },
        green:  { bg: '#D1FAE5', text: '#065F46' },
        orange: { bg: '#FED7AA', text: '#C2410C' },
        gray:   { bg: '#F3F4F6', text: '#374151' },
    }
    return map[color] ?? map.gray
}

const totalComponentes = computed(() =>
    (p.value.componentes || []).reduce((s, c) => s + Number(c.precio_costo_snapshot) * Number(c.cantidad), 0)
)

const confirmarEliminar = ref(false)

const eliminar = () => {
    router.delete(`/productos/${p.value.id}`)
}

const stockPorcentaje = computed(() => {
    if (!p.value.inventariable || !p.value.stock_maximo) return 0
    return Math.min(100, Math.round((p.value.stock_total / p.value.stock_maximo) * 100))
})

const stockColor = computed(() => {
    if (p.value.stock_total <= p.value.stock_minimo) return '#ef4444'
    if (p.value.stock_total < p.value.stock_maximo * 0.3) return '#f59e0b'
    return '#10b981'
})

// ── Ajuste de stock ───────────────────────────────────────────────────────────
const modalAjuste = ref(false)
const guardandoAjuste = ref(false)
const formAjuste = ref({
    bodega_id:         props.bodegas?.[0]?.id ?? '',
    tipo:              'entrada',
    cantidad:          '',
    bodega_destino_id: '',
    notas:             '',
})

const tiposMovimiento = [
    { value: 'entrada',       label: 'Entrada' },
    { value: 'salida',        label: 'Salida' },
    { value: 'ajuste',        label: 'Ajuste (+/-)' },
    { value: 'transferencia', label: 'Transferencia entre bodegas' },
    { value: 'devolucion',    label: 'Devolución' },
]

const abrirAjuste = () => {
    formAjuste.value = {
        bodega_id:         props.bodegas?.[0]?.id ?? '',
        tipo:              'entrada',
        cantidad:          '',
        bodega_destino_id: '',
        notas:             '',
    }
    modalAjuste.value = true
}

const csrf = () => {
    const c = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))
    return c ? decodeURIComponent(c.split('=')[1]) : ''
}

const guardarAjuste = () => {
    guardandoAjuste.value = true
    router.post(`/productos/${p.value.id}/ajuste-stock`, formAjuste.value, {
        onSuccess: () => { modalAjuste.value = false; guardandoAjuste.value = false },
        onError:   () => { guardandoAjuste.value = false },
        preserveScroll: true,
    })
}

// ── Movimientos recientes ─────────────────────────────────────────────────────
const tipoMovColor = (tipo) => {
    const map = {
        entrada: '#10b981', salida: '#ef4444', ajuste: '#3b82f6',
        transferencia: '#8b5cf6', devolucion: '#f59e0b', consumo_ensamble: '#4f46e5',
    }
    return map[tipo] ?? '#6b7280'
}

const tipoMovLabel = (tipo) => {
    const map = {
        entrada: 'Entrada', salida: 'Salida', ajuste: 'Ajuste',
        transferencia: 'Transferencia', devolucion: 'Devolución',
        consumo_ensamble: 'Consumo producción', creacion_producto: 'Inicial', venta: 'Venta',
    }
    return map[tipo] ?? tipo
}

const fmtFecha = (d) => d ? new Date(d).toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
</script>

<template>
    <AppLayout :title="p.nombre">

        <!-- ── Navegación superior ───────────────────────────────────────── -->
        <div class="flex items-center justify-between mb-4">
            <a href="/productos"
                class="flex items-center gap-1.5 text-sm text-tinta-400 hover:text-tinta-900 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                </svg>
                Productos
            </a>
            <a :href="`/productos/crear?tipo=${p.tipo}`"
                class="flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-medium text-white"
                style="background:var(--marca);">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                </svg>
                Nuevo {{ p.tipo === 'servicio' ? 'servicio' : 'producto' }}
            </a>
        </div>

        <!-- ── Layout: imagen 40% | info 60% en desktop ─────────────────── -->
        <div class="grid grid-cols-1 md:grid-cols-5 gap-5">

            <!-- ── Col izquierda: imagen + miniaturas ─────────────────────── -->
            <div class="md:col-span-2 space-y-4">
                <div class="bg-white rounded-2xl shadow-sm overflow-hidden">
                    <!-- Imagen compacta -->
                    <div class="flex items-center justify-center" style="height:288px; background:#F1F5F9;">
                        <img
                            v-if="imagenActiva"
                            :src="imagenActiva.url"
                            :alt="p.nombre"
                            class="max-h-72 max-w-full object-contain"
                        />
                        <span v-else
                            class="text-5xl font-semibold text-white rounded-full flex items-center justify-center"
                            style="width:80px;height:80px;background-color:var(--marca);">
                            {{ p.nombre.charAt(0).toUpperCase() }}
                        </span>
                    </div>
                    <!-- Miniaturas -->
                    <div v-if="p.imagenes?.length > 1" class="flex gap-2 px-4 py-3 overflow-x-auto">
                        <button
                            v-for="img in p.imagenes" :key="img.id"
                            @click="setImagenActiva(img)"
                            class="w-14 h-14 shrink-0 rounded-xl overflow-hidden border-2 transition-colors"
                            :style="imagenActiva?.id === img.id ? 'border-color:var(--marca);' : 'border-color:#E5E7EB;'"
                        >
                            <img :src="img.url" class="w-full h-full object-cover"/>
                        </button>
                    </div>
                </div>

                <!-- Descripción larga (solo desktop — mobile queda abajo en info) -->
                <div v-if="p.descripcion_larga" class="hidden md:block bg-white rounded-2xl shadow-sm p-5">
                    <h3 class="text-sm font-semibold text-tinta-700 mb-2">Descripción</h3>
                    <div class="tiptap-content text-sm text-tinta-500 leading-relaxed" v-html="p.descripcion_larga"></div>
                </div>

                <!-- Tabla componentes (ensamble) -->
                <div v-if="p.tipo === 'ensamble'" class="bg-white rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-5 py-4 border-b border-linea">
                        <h3 class="text-sm font-semibold text-tinta-700">Componentes</h3>
                    </div>
                    <div class="p-4">
                        <div v-if="!p.componentes?.length" class="text-center text-tinta-300 text-sm py-6">
                            Sin componentes.
                            <button @click="router.visit(`/productos/${p.id}/editar`)" class="underline ml-1" style="color:var(--marca);">Agregar</button>
                        </div>
                        <div v-else class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-linea">
                                        <th class="text-left text-xs text-tinta-400 pb-2 font-medium">#</th>
                                        <th class="text-left text-xs text-tinta-400 pb-2 font-medium">Producto</th>
                                        <th class="text-center text-xs text-tinta-400 pb-2 font-medium">Cant.</th>
                                        <th class="text-right text-xs text-tinta-400 pb-2 font-medium">Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(item, idx) in p.componentes" :key="item.id" class="border-b border-gray-50">
                                        <td class="py-2 text-xs text-tinta-300">{{ idx + 1 }}</td>
                                        <td class="py-2">
                                            <div class="flex items-center gap-2">
                                                <img v-if="item.imagen_url" :src="item.imagen_url" class="w-7 h-7 rounded-lg object-cover shrink-0"/>
                                                <div v-else class="w-7 h-7 rounded-lg flex items-center justify-center text-white text-xs font-semibold shrink-0" style="background:var(--marca);">
                                                    {{ item.nombre.charAt(0) }}
                                                </div>
                                                <div class="min-w-0">
                                                    <p class="font-medium text-tinta-900 truncate text-xs">{{ item.nombre }}</p>
                                                    <p class="text-xs text-tinta-300">×{{ item.cantidad }}</p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="py-2 text-center text-xs text-tinta-700">{{ item.cantidad }}</td>
                                        <td class="py-2 text-right text-xs font-semibold text-tinta-900">{{ formatCOP(item.precio_costo_snapshot * item.cantidad) }}</td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="3" class="pt-3 text-xs font-semibold text-tinta-500 text-right">Total costo:</td>
                                        <td class="pt-3 text-right font-semibold text-sm" style="color:var(--marca);">{{ formatCOP(totalComponentes) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Config puerta (ensamble) -->
                <div v-if="p.tipo === 'ensamble' && p.configuracion_puerta" class="bg-white rounded-2xl shadow-sm overflow-hidden">
                    <div class="px-5 py-4 border-b border-linea">
                        <h3 class="text-sm font-semibold text-tinta-700">Configuración de puerta</h3>
                    </div>
                    <div class="p-4">
                        <div class="grid grid-cols-2 gap-3 mb-4 text-sm">
                            <div><p class="text-xs text-tinta-300">Tipo</p><p class="font-medium text-tinta-900 text-xs">{{ p.configuracion_puerta.tipo_puerta?.replace(/_/g,' ') }}</p></div>
                            <div><p class="text-xs text-tinta-300">Dimensiones</p><p class="font-medium text-tinta-900 text-xs">{{ p.configuracion_puerta.ancho_vano }}m × {{ p.configuracion_puerta.alto_vano }}m</p></div>
                            <div><p class="text-xs text-tinta-300">Temperatura</p><p class="font-medium text-tinta-900 text-xs">{{ p.configuracion_puerta.temperatura }}</p></div>
                            <div><p class="text-xs text-tinta-300">Lámina</p><p class="font-medium text-tinta-900 text-xs">{{ p.configuracion_puerta.lamina }}</p></div>
                        </div>
                        <div class="border border-linea rounded-xl overflow-hidden">
                            <div class="px-3 py-1.5 bg-tinta-50 border-b border-linea">
                                <p class="text-xs font-semibold text-tinta-400 uppercase tracking-wide">Precios por canal</p>
                            </div>
                            <div class="divide-y divide-gray-50">
                                <div class="flex justify-between px-3 py-2"><span class="text-xs text-tinta-400">Costo</span><span class="text-xs font-semibold text-tinta-900">{{ formatCOP(p.configuracion_puerta.costo) }}</span></div>
                                <div class="flex justify-between px-3 py-2"><span class="text-xs text-tinta-400">Mayorista</span><span class="text-xs font-semibold text-tinta-900">{{ formatCOP(p.configuracion_puerta.mayorista) }}</span></div>
                                <div class="flex justify-between px-3 py-2"><span class="text-xs text-tinta-400">Distribuidor</span><span class="text-xs font-semibold text-tinta-900">{{ formatCOP(p.configuracion_puerta.distribuidor) }}</span></div>
                                <div class="flex justify-between px-3 py-2 bg-blue-50"><span class="text-xs font-semibold" style="color:var(--marca);">Cliente Final</span><span class="text-xs font-semibold" style="color:var(--marca);">{{ formatCOP(p.configuracion_puerta.cliente_final) }}</span></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Col derecha: info completa ─────────────────────────────── -->
            <div class="md:col-span-3 space-y-4">

                <div class="bg-white rounded-2xl shadow-sm p-5">
                    <!-- Nombre y badges -->
                    <h1 class="text-xl font-semibold text-tinta-900 mb-2 leading-snug">{{ p.nombre }}</h1>
                    <div class="flex flex-wrap gap-2 mb-3">
                        <span class="text-xs font-semibold px-2.5 py-1 rounded-full"
                            :style="{ background: badgeStyle(p.tipo_color).bg, color: badgeStyle(p.tipo_color).text }">
                            {{ p.tipo_label }}
                        </span>
                        <span v-if="p.categoria_nombre" class="text-xs font-medium px-2.5 py-1 rounded-full"
                            :style="`background-color:${p.categoria_color}22;color:${p.categoria_color};`">
                            {{ p.categoria_nombre }}
                        </span>
                        <span class="text-xs font-medium px-2.5 py-1 rounded-full"
                            :style="p.activo ? 'background:#D1FAE5;color:#065F46;' : 'background:#FEE2E2;color:#991B1B;'">
                            {{ p.activo ? 'Activo' : 'Inactivo' }}
                        </span>
                        <span v-if="p.valor_variante" class="text-xs font-semibold px-2.5 py-1 rounded-full" style="background:#EDE9FE;color:#6D28D9;">
                            Variante de: {{ p.padre?.nombre }} — {{ p.valor_variante }}
                        </span>
                        <span v-if="p.es_padre" class="text-xs font-semibold px-2.5 py-1 rounded-full" style="background:#EDE9FE;color:#6D28D9;">
                            Producto padre · {{ p.variantes?.length ?? 0 }} variante{{ (p.variantes?.length ?? 0) === 1 ? '' : 's' }}
                        </span>
                    </div>
                    <p class="font-mono text-xs text-tinta-300 mb-3">{{ p.referencia }} · {{ p.unidad_medida }}</p>
                    <p v-if="p.descripcion_corta" class="text-sm text-tinta-500 mb-4">{{ p.descripcion_corta }}</p>

                    <!-- Descripción larga (mobile) -->
                    <div v-if="p.descripcion_larga" class="md:hidden mb-4">
                        <h3 class="text-sm font-semibold text-tinta-700 mb-1">Descripción</h3>
                        <div class="tiptap-content text-sm text-tinta-500 leading-relaxed" v-html="p.descripcion_larga"></div>
                    </div>

                    <!-- Lista de precios -->
                    <div class="border border-linea rounded-xl overflow-hidden mb-4">
                        <div class="px-3 py-2 bg-tinta-50 border-b border-linea">
                            <p class="text-xs font-semibold text-tinta-400 uppercase tracking-wide">Lista de precios</p>
                        </div>
                        <div class="divide-y divide-gray-50">
                            <div class="flex items-center justify-between px-3 py-2.5">
                                <span class="text-xs text-tinta-400">Precio Costo</span>
                                <span class="text-sm font-semibold text-tinta-900">{{ formatCOP(p.precio_costo) }}</span>
                            </div>
                            <div class="flex items-center justify-between px-3 py-2.5">
                                <span class="text-xs text-tinta-400">Mayorista</span>
                                <span class="text-sm font-semibold text-tinta-900">{{ formatCOP(p.precio_mayorista) }}</span>
                            </div>
                            <div class="flex items-center justify-between px-3 py-2.5">
                                <span class="text-xs text-tinta-400">Distribuidor</span>
                                <span class="text-sm font-semibold text-tinta-900">{{ formatCOP(p.precio_distribuidor) }}</span>
                            </div>
                            <div v-if="p.tipo !== 'ensamble'" class="flex items-center justify-between px-3 py-2.5 bg-blue-50">
                                <span class="text-xs font-semibold" style="color:var(--marca);">Cliente Final</span>
                                <span class="text-sm font-semibold" style="color:var(--marca);">{{ formatCOP(p.precio_cliente_final) }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Variantes (solo padre) -->
                    <div v-if="p.es_padre" class="border border-linea rounded-xl overflow-hidden mb-4">
                        <div class="px-3 py-2 bg-tinta-50 border-b border-linea flex items-center justify-between">
                            <p class="text-xs font-semibold text-tinta-400 uppercase tracking-wide">Variantes ({{ p.atributo_variante }})</p>
                            <button @click="router.visit(`/productos/${p.id}/editar`)"
                                class="text-xs text-white px-2.5 py-1 rounded-lg font-medium" style="background:var(--marca);">
                                + Agregar
                            </button>
                        </div>
                        <div class="divide-y divide-gray-50">
                            <div v-for="v in p.variantes" :key="v.id"
                                class="flex items-center justify-between px-3 py-2.5 cursor-pointer hover:bg-blue-50/40"
                                @click="router.visit(`/productos/${v.id}`)">
                                <div>
                                    <p class="text-sm text-tinta-900">{{ v.nombre }}</p>
                                    <p class="text-xs text-tinta-300 font-mono">{{ v.referencia }}</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-medium px-2 py-0.5 rounded-full" style="background:#EDE9FE;color:#6D28D9;">{{ v.valor_variante }}</span>
                                    <span class="text-xs font-semibold text-green-600">{{ v.stock_total }}</span>
                                </div>
                            </div>
                            <p v-if="!p.variantes?.length" class="text-xs text-tinta-300 text-center py-3">Sin variantes.</p>
                        </div>
                    </div>

                    <!-- Inventario por bodega -->
                    <div v-if="p.inventariable && !p.es_padre" class="border border-linea rounded-xl overflow-hidden mb-4">
                        <div class="px-3 py-2 bg-tinta-50 border-b border-linea flex items-center justify-between">
                            <p class="text-xs font-semibold text-tinta-400 uppercase tracking-wide">Stock</p>
                            <button @click="abrirAjuste"
                                class="text-xs text-white px-2.5 py-1 rounded-lg font-medium"
                                style="background:var(--marca);">
                                + Ajuste
                            </button>
                        </div>
                        <div class="p-3 space-y-3">
                            <!-- Total -->
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <span class="text-xs text-tinta-400">Stock total</span>
                                    <span class="text-sm font-semibold" :style="`color:${stockColor};`">{{ p.stock_total }} {{ p.unidad_medida }}</span>
                                </div>
                                <div class="w-full bg-tinta-100 rounded-full h-2">
                                    <div class="h-2 rounded-full transition-all"
                                        :style="`width:${stockPorcentaje}%;background-color:${stockColor};`"/>
                                </div>
                                <div class="flex justify-between mt-1">
                                    <span class="text-xs text-tinta-300">Mín: {{ p.stock_minimo }}</span>
                                    <span class="text-xs text-tinta-300">Máx: {{ p.stock_maximo }}</span>
                                </div>
                            </div>
                            <!-- Tabla por bodega -->
                            <div v-if="p.stocks?.length" class="divide-y divide-gray-50">
                                <div v-for="s in p.stocks" :key="s.bodega_id"
                                    class="flex items-center justify-between py-1.5">
                                    <span class="text-xs text-tinta-500 flex items-center gap-1">
                                        {{ s.bodega_nombre }}
                                        <span v-if="s.es_principal" class="text-blue-400 text-[10px]">★</span>
                                    </span>
                                    <span class="text-xs font-semibold text-tinta-900">{{ s.cantidad }}</span>
                                </div>
                            </div>
                            <p v-else class="text-xs text-tinta-300 text-center py-1">Sin movimientos registrados</p>
                        </div>
                    </div>

                    <!-- Badges vendible / insumo -->
                    <div v-if="p.es_vendible || p.es_insumo" class="flex gap-2 mb-4">
                        <span v-if="p.es_vendible" class="text-xs font-medium px-2.5 py-1 rounded-full" style="background:#DBEAFE;color:#1D4ED8;">
                            Vendible
                        </span>
                        <span v-if="p.es_insumo" class="text-xs font-medium px-2.5 py-1 rounded-full" style="background:#FEF3C7;color:#B45309;">
                            Insumo
                        </span>
                    </div>

                    <!-- Botones catálogo -->
                    <div class="flex gap-2 mb-1">
                        <a :href="`/catalogo/productos/${p.id}`" target="_blank"
                            class="flex-1 py-2.5 rounded-xl border border-linea text-xs text-tinta-500 font-medium text-center hover:bg-tinta-50">
                            Catálogo (con precio)
                        </a>
                        <a :href="`/catalogo/productos/${p.id}/pdf`"
                            class="flex-1 py-2.5 rounded-xl border border-linea text-xs text-tinta-500 font-medium text-center hover:bg-tinta-50">
                            PDF (con precio)
                        </a>
                    </div>
                    <div class="flex gap-2 mb-2">
                        <a :href="`/catalogo/productos/${p.id}?precio=0`" target="_blank"
                            class="flex-1 py-2.5 rounded-xl border border-dashed border-linea text-xs text-tinta-300 font-medium text-center hover:bg-tinta-50">
                            Catálogo sin precio
                        </a>
                        <a :href="`/catalogo/productos/${p.id}/pdf?precio=0`"
                            class="flex-1 py-2.5 rounded-xl border border-dashed border-linea text-xs text-tinta-300 font-medium text-center hover:bg-tinta-50">
                            PDF sin precio
                        </a>
                    </div>
                    <!-- Botones -->
                    <div class="flex gap-2">
                        <button @click="router.visit(`/productos/${p.id}/editar`)"
                            class="flex-1 py-3 rounded-xl text-sm font-medium text-white"
                            style="background-color:var(--marca);">
                            Editar
                        </button>
                        <button @click="confirmarEliminar = true"
                            class="px-4 py-3 rounded-xl border border-red-200 text-red-500 text-sm font-medium hover:bg-red-50">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                            </svg>
                        </button>
                    </div>
                    <a v-if="p.tipo === 'ensamble'" href="/cotizaciones/crear"
                        class="block w-full text-center py-2.5 rounded-xl border-2 text-sm font-medium mt-2"
                        style="border-color:var(--marca);color:var(--marca);">
                        Usar en cotización
                    </a>
                </div>

            </div>
        </div>

        <!-- ── Movimientos recientes ──────────────────────────────────────── -->
        <div v-if="p.movimientos_recientes?.length" class="mt-5 bg-white rounded-2xl shadow-sm overflow-hidden">
            <div class="px-5 py-3 border-b border-linea">
                <h3 class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Movimientos recientes</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-xs min-w-[480px]">
                    <thead>
                        <tr style="background:#F8FAFC; border-bottom:1px solid #E5E7EB;">
                            <th class="text-left px-4 py-2.5 font-semibold text-tinta-400">Fecha</th>
                            <th class="text-left px-3 py-2.5 font-semibold text-tinta-400">Tipo</th>
                            <th class="text-right px-3 py-2.5 font-semibold text-tinta-400">Cantidad</th>
                            <th class="text-left px-3 py-2.5 font-semibold text-tinta-400">Bodega</th>
                            <th class="text-left px-3 py-2.5 font-semibold text-tinta-400">Usuario</th>
                            <th class="text-left px-3 py-2.5 font-semibold text-tinta-400">Notas</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <tr v-for="mv in p.movimientos_recientes" :key="mv.id" class="hover:bg-tinta-50">
                            <td class="px-4 py-2 text-tinta-300">{{ fmtFecha(mv.created_at) }}</td>
                            <td class="px-3 py-2">
                                <span class="px-2 py-0.5 rounded-full text-white text-[11px] font-semibold"
                                    :style="`background:${tipoMovColor(mv.tipo)};`">
                                    {{ tipoMovLabel(mv.tipo) }}
                                </span>
                            </td>
                            <td class="px-3 py-2 text-right font-semibold" :style="`color:${tipoMovColor(mv.tipo)};`">
                                {{ ['entrada','devolucion','creacion_producto'].includes(mv.tipo) ? '+' : '-' }}{{ mv.cantidad }}
                            </td>
                            <td class="px-3 py-2 text-tinta-500">
                                <template v-if="mv.tipo === 'transferencia'">
                                    {{ mv.bodega?.nombre ?? '—' }} → {{ mv.bodega_destino?.nombre ?? '—' }}
                                </template>
                                <template v-else>{{ mv.bodega?.nombre ?? '—' }}</template>
                            </td>
                            <td class="px-3 py-2 text-tinta-400">{{ mv.usuario?.name ?? '—' }}</td>
                            <td class="px-3 py-2 text-tinta-300 truncate max-w-xs">{{ mv.notas ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ── Modal confirmar eliminar ───────────────────────────────────── -->
        <Teleport to="body">
            <div v-if="confirmarEliminar" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-4" style="background: rgba(0,0,0,0.5);">
                <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm p-5">
                    <h3 class="text-base font-semibold text-tinta-900 mb-2">¿Eliminar producto?</h3>
                    <p class="text-sm text-tinta-400 mb-5">Esta acción no se puede deshacer. El producto quedará archivado.</p>
                    <div class="flex gap-3">
                        <button @click="confirmarEliminar = false" class="flex-1 py-3 rounded-xl border border-linea text-sm text-tinta-500">Cancelar</button>
                        <button @click="eliminar" class="flex-1 py-3 rounded-xl bg-red-500 text-white text-sm font-medium">Eliminar</button>
                    </div>
                </div>
            </div>

            <!-- Modal ajuste de stock -->
            <div v-if="modalAjuste" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-4" style="background:rgba(0,0,0,0.5);">
                <div class="bg-white rounded-2xl shadow-xl w-full max-w-md p-5">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-base font-semibold text-tinta-900">Ajuste de stock</h3>
                        <button @click="modalAjuste = false" class="text-tinta-300 hover:text-tinta-500 text-xl">✕</button>
                    </div>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-xs font-medium text-tinta-500 mb-1">Bodega</label>
                            <select v-model="formAjuste.bodega_id" class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none">
                                <option v-for="b in bodegas" :key="b.id" :value="b.id">{{ b.nombre }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-tinta-500 mb-1">Tipo de movimiento</label>
                            <select v-model="formAjuste.tipo" class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none">
                                <option v-for="t in tiposMovimiento" :key="t.value" :value="t.value">{{ t.label }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-tinta-500 mb-1">Cantidad</label>
                            <input v-model.number="formAjuste.cantidad" type="number" min="0.001" step="0.001"
                                class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none"
                                placeholder="Ej: 10" />
                        </div>
                        <div v-if="formAjuste.tipo === 'transferencia'">
                            <label class="block text-xs font-medium text-tinta-500 mb-1">Bodega destino</label>
                            <select v-model="formAjuste.bodega_destino_id" class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none">
                                <option value="">Seleccionar...</option>
                                <option v-for="b in bodegas" :key="b.id" :value="b.id"
                                    :disabled="b.id == formAjuste.bodega_id">
                                    {{ b.nombre }}
                                </option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-tinta-500 mb-1">Notas (opcional)</label>
                            <input v-model="formAjuste.notas" type="text"
                                class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none"
                                placeholder="Ej: Inventario físico julio 2026" />
                        </div>
                    </div>
                    <div class="flex gap-3 mt-5">
                        <button @click="modalAjuste = false" class="flex-1 py-2.5 rounded-xl border border-linea text-sm text-tinta-500">Cancelar</button>
                        <button @click="guardarAjuste" :disabled="guardandoAjuste || !formAjuste.cantidad"
                            class="flex-1 py-2.5 rounded-xl text-white text-sm font-medium disabled:opacity-60"
                            style="background:var(--marca);">
                            {{ guardandoAjuste ? 'Guardando...' : 'Registrar ajuste' }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

    </AppLayout>
</template>

<style>
.tiptap-content p { margin-bottom: 0.5rem; }
.tiptap-content p:last-child { margin-bottom: 0; }
.tiptap-content ul { list-style-type: disc; padding-left: 1.5rem; margin-bottom: 0.5rem; }
.tiptap-content ol { list-style-type: decimal; padding-left: 1.5rem; margin-bottom: 0.5rem; }
.tiptap-content li p { margin-bottom: 0; }
.tiptap-content strong { font-weight: 700; }
.tiptap-content em { font-style: italic; }
.tiptap-content u { text-decoration: underline; }
.tiptap-content a { color: var(--marca); text-decoration: underline; }
.tiptap-content h1 { font-size: 1.125rem; font-weight: 700; margin-bottom: 0.5rem; }
.tiptap-content h2 { font-size: 1rem; font-weight: 700; margin-bottom: 0.5rem; }
.tiptap-content h3 { font-size: 0.9rem; font-weight: 600; margin-bottom: 0.4rem; }
</style>
