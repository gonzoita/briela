<script setup>
/**
 * Los proveedores de un producto, con su precio, para comparar antes de comprar.
 *
 * El producto guardaba **un** proveedor. Eso alcanza para saber a quién se le compró la
 * última vez, y no alcanza para lo que de verdad se hace antes de comprar: mirar los tres que
 * lo venden y elegir. Esa comparación se hacía en un cuaderno o en un chat, y por eso se
 * compraba caro sin darse cuenta.
 *
 * Lo que hace la comparación honesta no es solo el precio:
 *
 * - **Días de entrega**: el más barato que llega en tres semanas no sirve para una OP de
 *   mañana.
 * - **Mínimo de compra**: un precio bueno comprando cien no es un precio bueno comprando dos.
 * - **Fecha del precio**: un precio de hace ocho meses no es un precio, es un recuerdo.
 *   Comparar tres cifras sin saber de cuándo son da una respuesta con cara de exacta.
 *
 * Por eso el más barato se marca, pero **no se elige solo**: se marca, se dice por qué, y se
 * avisa si el precio está viejo o si el mínimo no cuadra. La decisión es de quien compra.
 *
 * Muta el arreglo del padre en sitio: es el mismo que se manda al guardar.
 */
import { computed } from 'vue'

const props = defineProps({
    // Las filas: { proveedor_id, referencia_proveedor, precio, dias_entrega, minimo_compra,
    // es_preferido, actualizado_el, notas }
    filas: { type: Array, required: true },
    // El catálogo de proveedores para elegir.
    proveedores: { type: Array, default: () => [] },
    // Cuántas unidades se suelen comprar. Sirve para descartar mínimos que no cuadran.
    // Sin valor, no se descarta a nadie.
    cantidadHabitual: { type: [Number, String], default: null },
})

// Cuántos días hacen que un precio deja de ser confiable. No es una regla del negocio, es
// un umbral para avisar: tres meses es lo que tarda un material en cambiar de precio sin
// que nadie lo note.
const DIAS_VIEJO = 90

const conPrecio = computed(() =>
    props.filas.filter(f => f.proveedor_id && (Number(f.precio) || 0) > 0)
)

const masBarato = computed(() => {
    if (! conPrecio.value.length) return null

    return conPrecio.value.reduce((a, b) => (Number(a.precio) <= Number(b.precio) ? a : b))
})

const masCaro = computed(() => {
    if (conPrecio.value.length < 2) return null

    return conPrecio.value.reduce((a, b) => (Number(a.precio) >= Number(b.precio) ? a : b))
})

const ahorro = computed(() =>
    masBarato.value && masCaro.value
        ? Number(masCaro.value.precio) - Number(masBarato.value.precio)
        : 0
)

const ahorroPct = computed(() =>
    ahorro.value > 0 && Number(masCaro.value.precio) > 0
        ? Math.round((ahorro.value / Number(masCaro.value.precio)) * 100)
        : 0
)

function diasDesde(fecha) {
    if (! fecha) return null

    const dias = Math.floor((Date.now() - new Date(fecha).getTime()) / 86400000)

    return Number.isFinite(dias) ? dias : null
}

const esViejo = (f) => {
    const d = diasDesde(f.actualizado_el)

    return d !== null && d > DIAS_VIEJO
}

const sinFecha = (f) => ! f.actualizado_el && (Number(f.precio) || 0) > 0

const minimoNoCuadra = (f) => {
    const necesito = Number(props.cantidadHabitual) || 0

    return necesito > 0 && f.minimo_compra && Number(f.minimo_compra) > necesito
}

const nombreDe = (id) =>
    props.proveedores.find(p => String(p.id) === String(id))?.nombre ?? '—'

// Los que ya están elegidos no vuelven a ofrecerse: un proveedor no puede tener dos precios
// para el mismo producto, serían dos respuestas a la misma pregunta.
const disponiblesPara = (fila) => props.proveedores.filter(p =>
    String(p.id) === String(fila.proveedor_id)
    || ! props.filas.some(f => String(f.proveedor_id) === String(p.id))
)

function agregar() {
    props.filas.push({
        proveedor_id: '',
        referencia_proveedor: '',
        precio: 0,
        dias_entrega: null,
        minimo_compra: null,
        es_preferido: props.filas.length === 0,
        actualizado_el: new Date().toISOString().slice(0, 10),
        notas: '',
    })
}

function quitar(i) {
    const eraPreferido = props.filas[i]?.es_preferido
    props.filas.splice(i, 1)

    // El preferido no puede quedar vacío mientras haya filas: es el que se copia a la
    // columna de siempre y el que usan las órdenes de compra.
    if (eraPreferido && props.filas.length) {
        props.filas[0].es_preferido = true
    }
}

function marcarPreferido(i) {
    props.filas.forEach((f, idx) => { f.es_preferido = idx === i })
}

const formatCOP = (v) =>
    new Intl.NumberFormat('es-CO', { maximumFractionDigits: 0 }).format(Number(v) || 0)
</script>

<template>
    <div class="space-y-3">

        <!-- El resumen de la comparación. Es la razón de ser de la lista: si no dice cuánto
             se ahorra, es solo una tabla más. -->
        <div v-if="ahorro > 0" class="rounded-xl px-4 py-3 text-sm"
            style="background:var(--pastel-verde); border:1px solid #6EE7B7;">
            <p class="font-semibold" style="color:var(--texto-verde);">
                Diferencia de ${{ formatCOP(ahorro) }} por unidad ({{ ahorroPct }}%)
            </p>
            <p class="text-xs mt-0.5" style="color:var(--texto-verde);">
                Más barato: {{ nombreDe(masBarato.proveedor_id) }} a ${{ formatCOP(masBarato.precio) }}.
                Más caro: {{ nombreDe(masCaro.proveedor_id) }} a ${{ formatCOP(masCaro.precio) }}.
            </p>
        </div>

        <div v-for="(f, i) in filas" :key="i"
            class="border rounded-xl p-3 space-y-3"
            :class="masBarato && f === masBarato && filas.length > 1 ? 'border-borde-aviso-verde bg-pastel-verde/40' : 'border-linea'">

            <div class="flex items-center gap-2 flex-wrap">
                <select v-model="f.proveedor_id"
                    class="flex-1 min-w-0 border border-linea rounded-lg px-2.5 py-1.5 text-sm focus:outline-none focus:border-[var(--marca)]">
                    <option value="">— Elegir proveedor —</option>
                    <option v-for="pv in disponiblesPara(f)" :key="pv.id" :value="String(pv.id)">{{ pv.nombre }}</option>
                </select>

                <span v-if="masBarato && f === masBarato && filas.length > 1"
                    class="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-pastel-verde-2 text-aviso-verde shrink-0">
                    más barato
                </span>

                <button type="button" @click="marcarPreferido(i)"
                    class="text-[10px] font-semibold px-2 py-0.5 rounded-full shrink-0 transition-colors"
                    :class="f.es_preferido ? 'bg-[var(--marca)] text-white' : 'bg-tinta-100 text-tinta-500 hover:bg-tinta-200'"
                    :title="f.es_preferido ? 'Es el proveedor preferido' : 'Marcar como preferido'">
                    {{ f.es_preferido ? '★ preferido' : '☆ preferido' }}
                </button>

                <button type="button" @click="quitar(i)" class="text-tinta-300 hover:text-aviso-rojo shrink-0" title="Quitar">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                <div>
                    <label class="block text-xs text-tinta-400 mb-1">Precio</label>
                    <div class="relative">
                        <span class="absolute left-2.5 top-1/2 -translate-y-1/2 text-xs text-tinta-300">$</span>
                        <input v-model.number="f.precio" type="number" min="0" step="0.01"
                            class="w-full border border-linea rounded-lg pl-6 pr-2 py-1.5 text-sm text-right focus:outline-none focus:border-[var(--marca)]" />
                    </div>
                </div>
                <div>
                    <label class="block text-xs text-tinta-400 mb-1">Días de entrega</label>
                    <input v-model.number="f.dias_entrega" type="number" min="0" step="1" placeholder="—"
                        class="w-full border border-linea rounded-lg px-2.5 py-1.5 text-sm text-right focus:outline-none focus:border-[var(--marca)]" />
                </div>
                <div>
                    <label class="block text-xs text-tinta-400 mb-1">Mínimo de compra</label>
                    <input v-model.number="f.minimo_compra" type="number" min="0" step="0.001" placeholder="—"
                        class="w-full border border-linea rounded-lg px-2.5 py-1.5 text-sm text-right focus:outline-none focus:border-[var(--marca)]" />
                </div>
                <div>
                    <label class="block text-xs text-tinta-400 mb-1">Fecha del precio</label>
                    <input v-model="f.actualizado_el" type="date"
                        class="w-full border border-linea rounded-lg px-2.5 py-1.5 text-sm focus:outline-none focus:border-[var(--marca)]" />
                </div>
            </div>

            <div>
                <label class="block text-xs text-tinta-400 mb-1">Referencia del proveedor</label>
                <input v-model="f.referencia_proveedor" type="text" placeholder="El código con el que ellos lo llaman"
                    class="w-full border border-linea rounded-lg px-2.5 py-1.5 text-sm focus:outline-none focus:border-[var(--marca)]" />
                <p class="text-xs text-tinta-300 mt-1">Es lo que va en la orden de compra para que no manden otra cosa.</p>
            </div>

            <!-- Los avisos. Un precio marcado como el más barato sin decir que es de hace
                 ocho meses es peor que no marcar nada. -->
            <p v-if="esViejo(f)" class="text-xs text-aviso-ambar">
                ⚠ Este precio tiene {{ diasDesde(f.actualizado_el) }} días. Conviene confirmarlo antes de decidir.
            </p>
            <p v-else-if="sinFecha(f)" class="text-xs text-tinta-400">
                Sin fecha: no se sabe de cuándo es este precio.
            </p>
            <p v-if="minimoNoCuadra(f)" class="text-xs text-aviso-ambar">
                ⚠ Exige comprar {{ f.minimo_compra }} y normalmente se compran {{ cantidadHabitual }}.
            </p>
        </div>

        <p v-if="!filas.length" class="text-sm text-tinta-400 border border-dashed border-linea rounded-xl px-4 py-6 text-center">
            Sin proveedores cargados. Agrega los que venden este producto para poder comparar precios.
        </p>

        <button type="button" @click="agregar"
            class="text-xs text-[var(--marca)] border border-[var(--marca)] rounded-lg px-3 py-2 hover:bg-[var(--marca-suave)] transition-colors">
            + Agregar proveedor
        </button>
    </div>
</template>
