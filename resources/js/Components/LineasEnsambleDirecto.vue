<script setup>
/**
 * La receta de un ensamble **directo**: líneas con cantidades exactas, sin fórmulas.
 *
 * Es la alternativa a la plantilla del cotizador. La plantilla existe para fabricar por
 * medidas —una puerta de 2400 x 2600, donde los materiales salen de una fórmula—. Para un
 * kit que siempre lleva lo mismo, escribir esa fórmula es trabajo de más: aquí se escriben
 * las cantidades y ya.
 *
 * Dos clases de línea:
 *
 * - **Producto del catálogo**: su costo lo trae el producto y al despachar descuenta
 *   inventario, como cualquier material de una receta.
 * - **Concepto libre** —mano de obra, transporte, instalación—: suma al costo y no descuenta
 *   nada, porque no vive en ninguna bodega.
 *
 * Muta el arreglo del padre en sitio: es el mismo que se manda al guardar.
 */
import { ref, computed, watch } from 'vue'

const props = defineProps({
    // Las líneas. Se mutan aquí; el padre las manda tal cual.
    lineas: { type: Array, required: true },
})

const busqueda   = ref('')
const resultados = ref([])
const buscando   = ref(false)
const abierto    = ref(false)

let temporizador = null

const csrf = () => {
    const c = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))
    return c ? decodeURIComponent(c.split('=')[1]) : ''
}

// La búsqueda espera 300 ms: escribir «perfil» son seis letras, y sin esto son seis
// consultas a la base por una sola intención.
watch(busqueda, (q) => {
    clearTimeout(temporizador)

    if (!q || q.length < 2) {
        resultados.value = []
        abierto.value    = false
        return
    }

    temporizador = setTimeout(async () => {
        buscando.value = true
        try {
            const res = await fetch(`/api/productos/buscar?q=${encodeURIComponent(q)}`, {
                headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrf() },
                credentials: 'same-origin',
            })
            resultados.value = await res.json()
            abierto.value    = true
        } catch {
            resultados.value = []
        } finally {
            buscando.value = false
        }
    }, 300)
})

function agregarProducto(p) {
    const yaEsta = props.lineas.find(l => l.producto_id === p.id)

    // Elegir dos veces el mismo material es querer más cantidad, no una línea repetida.
    if (yaEsta) {
        yaEsta.cantidad = Number((Number(yaEsta.cantidad || 0) + 1).toFixed(4))
    } else {
        props.lineas.push({
            producto_id: p.id,
            concepto:    p.nombre_completo ?? p.nombre,
            referencia:  p.referencia,
            unidad:      p.unidad_medida ?? 'unidad',
            cantidad:    1,
            precio_unit: Number(p.precio_costo) || 0,
        })
    }

    busqueda.value   = ''
    resultados.value = []
    abierto.value    = false
}

function agregarConcepto() {
    props.lineas.push({
        producto_id: null,
        concepto:    '',
        unidad:      'unidad',
        cantidad:    1,
        precio_unit: 0,
    })
}

function quitar(i) {
    props.lineas.splice(i, 1)
}

const subtotalDe = (l) => (Number(l.cantidad) || 0) * (Number(l.precio_unit) || 0)

const total = computed(() => props.lineas.reduce((s, l) => s + subtotalDe(l), 0))

const formatCOP = (v) =>
    new Intl.NumberFormat('es-CO', { maximumFractionDigits: 0 }).format(Math.round(v ?? 0))

defineExpose({ total })
</script>

<template>
    <div class="space-y-4">

        <!-- Buscador de productos -->
        <div class="relative">
            <label class="block text-sm font-medium text-tinta-700 mb-1.5">Agregar material del inventario</label>
            <div class="relative">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-tinta-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/>
                </svg>
                <input v-model="busqueda" type="text" placeholder="Nombre o referencia del producto…"
                    class="w-full border border-linea rounded-xl pl-9 pr-3 py-2.5 text-sm focus:outline-none focus:border-[var(--marca)]" />
                <span v-if="buscando" class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-tinta-300">Buscando…</span>
            </div>

            <!-- Resultados -->
            <div v-if="abierto && resultados.length"
                class="absolute z-20 mt-1 w-full bg-superficie border border-linea rounded-xl shadow-lg max-h-72 overflow-y-auto">
                <button v-for="p in resultados" :key="p.id" type="button" @click="agregarProducto(p)"
                    class="w-full text-left px-3 py-2.5 hover:bg-tinta-50 border-b border-linea last:border-0">
                    <p class="text-sm font-medium text-tinta-800">{{ p.nombre_completo ?? p.nombre }}</p>
                    <p class="text-xs text-tinta-400">
                        {{ p.referencia }} · costo ${{ formatCOP(p.precio_costo) }}
                        <span v-if="p.tipo === 'servicio'" class="ml-1 text-[10px] px-1.5 py-0.5 rounded-full bg-purple-50 text-purple-600">servicio</span>
                        <span v-else class="ml-1">· stock {{ p.stock_total }}</span>
                    </p>
                </button>
            </div>

            <p v-if="abierto && !resultados.length && !buscando" class="text-xs text-tinta-400 mt-1.5">
                Ningún producto coincide. Si es mano de obra o transporte, agrégalo como concepto libre.
            </p>
        </div>

        <!-- Líneas -->
        <div v-if="lineas.length" class="space-y-2">
            <!-- Encabezados: solo en pantalla ancha. En celular cada línea es una tarjeta. -->
            <div class="hidden sm:grid sm:grid-cols-12 gap-2 px-1 text-xs text-tinta-300 font-medium">
                <span class="col-span-5">Componente</span>
                <span class="col-span-2 text-right">Cantidad</span>
                <span class="col-span-2">Unidad</span>
                <span class="col-span-2 text-right">Costo unit.</span>
                <span class="col-span-1"></span>
            </div>

            <div v-for="(l, i) in lineas" :key="i"
                class="border border-linea rounded-xl p-3 sm:p-2 sm:grid sm:grid-cols-12 sm:gap-2 sm:items-center space-y-2 sm:space-y-0">

                <!-- Nombre: fijo si es un producto, escribible si es un concepto -->
                <div class="sm:col-span-5 min-w-0">
                    <template v-if="l.producto_id">
                        <p class="text-sm font-medium text-tinta-800 truncate">{{ l.concepto }}</p>
                        <p class="text-xs text-tinta-400">{{ l.referencia }} · descuenta inventario</p>
                    </template>
                    <template v-else>
                        <input v-model="l.concepto" type="text" placeholder="Mano de obra, transporte, instalación…"
                            class="w-full border border-linea rounded-lg px-2.5 py-1.5 text-sm focus:outline-none focus:border-[var(--marca)]" />
                        <p class="text-xs text-tinta-400 mt-0.5">Concepto libre · no descuenta inventario</p>
                    </template>
                </div>

                <div class="sm:col-span-2">
                    <label class="sm:hidden block text-xs text-tinta-400 mb-1">Cantidad</label>
                    <input v-model.number="l.cantidad" type="number" min="0" step="0.001"
                        class="w-full border border-linea rounded-lg px-2.5 py-1.5 text-sm text-right focus:outline-none focus:border-[var(--marca)]" />
                </div>

                <div class="sm:col-span-2">
                    <label class="sm:hidden block text-xs text-tinta-400 mb-1">Unidad</label>
                    <input v-model="l.unidad" type="text" :readonly="!!l.producto_id"
                        class="w-full border border-linea rounded-lg px-2.5 py-1.5 text-sm focus:outline-none focus:border-[var(--marca)]"
                        :class="l.producto_id ? 'bg-tinta-50 text-tinta-500' : ''" />
                </div>

                <div class="sm:col-span-2">
                    <label class="sm:hidden block text-xs text-tinta-400 mb-1">Costo unitario</label>
                    <div class="relative">
                        <span class="absolute left-2.5 top-1/2 -translate-y-1/2 text-xs text-tinta-300">$</span>
                        <input v-model.number="l.precio_unit" type="number" min="0" step="0.01"
                            class="w-full border border-linea rounded-lg pl-6 pr-2 py-1.5 text-sm text-right focus:outline-none focus:border-[var(--marca)]" />
                    </div>
                </div>

                <div class="sm:col-span-1 flex items-center justify-between sm:justify-end gap-2">
                    <span class="sm:hidden text-xs text-tinta-400">Subtotal ${{ formatCOP(subtotalDe(l)) }}</span>
                    <button type="button" @click="quitar(i)" title="Quitar"
                        class="text-tinta-300 hover:text-red-500 transition-colors shrink-0">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <!-- El subtotal en pantalla ancha va en su propia fila fina para no robarle
                     ancho a los campos que sí se escriben. -->
                <div class="hidden sm:block sm:col-span-12 text-right text-xs text-tinta-400 pr-8 -mt-1">
                    Subtotal ${{ formatCOP(subtotalDe(l)) }}
                </div>
            </div>
        </div>

        <p v-else class="text-sm text-tinta-400 border border-dashed border-linea rounded-xl px-4 py-6 text-center">
            Todavía no hay componentes. Busca un material arriba, o agrega un concepto libre.
        </p>

        <div class="flex flex-col sm:flex-row sm:items-center gap-3 sm:justify-between pt-1">
            <button type="button" @click="agregarConcepto"
                class="text-xs text-[var(--marca)] border border-[var(--marca)] rounded-lg px-3 py-2 hover:bg-[var(--marca-suave)] transition-colors">
                + Agregar concepto libre
            </button>

            <p class="text-sm">
                <span class="text-tinta-400 mr-2">Costo total del ensamble</span>
                <span class="font-semibold text-tinta-900">${{ formatCOP(total) }}</span>
            </p>
        </div>

    </div>
</template>
