import { computed, watch } from 'vue'

/**
 * La lógica de precios y comisiones por canal, en un solo lugar.
 *
 * Vivía escrita dentro de la pantalla de crear producto. La de editar tenía otra cosa: tres
 * cajas fijas —mayorista, distribuidor, cliente final— que escribían solo las columnas
 * antiguas. Esa diferencia es la que produjo el problema de fondo: un producto creado con
 * cuatro canales, al guardarlo desde editar, quedaba con datos a medias y un canal sin
 * forma de llenarse.
 *
 * @param {import('vue').Ref<Array>}  canales      Las filas por canal, que se mutan en sitio.
 * @param {import('vue').Ref<number>} precioCosto  El costo del que sale cada precio.
 */
export function usePreciosPorCanal(canales, precioCosto) {
    /** El precio de venta a partir del costo y el margen, redondeado al millar. */
    const calcPrecio = (costo, margenPct) => {
        if (! costo || margenPct >= 100) return 0

        return Math.ceil(costo / (1 - margenPct / 100) / 1000) * 1000
    }

    // El precio se recalcula cuando cambia el costo o un margen. `immediate` a propósito:
    // al abrir un producto viejo con costo y margen guardados pero precio en cero, la
    // pantalla debe mostrar el precio que le corresponde, no el cero de la base.
    watch(
        [precioCosto, () => (canales.value ?? []).map(c => c.margen_pct).join(',')],
        () => {
            (canales.value ?? []).forEach(c => {
                c.precio = calcPrecio(Number(precioCosto.value) || 0, Number(c.margen_pct) || 0)
            })
        },
        { immediate: true },
    )

    /** El canal base: el piso de utilidad de la empresa. No lleva comisión. */
    const canalBase = computed(() => (canales.value ?? []).find(c => c.es_canal_base) ?? null)

    /** Los que sí pagan comisión, en el orden de prioridad que puso la empresa. */
    const canalesConComision = computed(() => (canales.value ?? []).filter(c => ! c.es_canal_base))

    /**
     * Lo que se vende por encima del canal base, que es sobre lo que se paga comisión.
     *
     * La comisión no se calcula sobre el precio de venta completo: sobre el excedente.
     * Vender al precio del canal base no genera comisión porque no hay nada que repartir.
     */
    function excedenteDe(canal) {
        return Math.max(0, (Number(canal.precio) || 0) - (Number(canalBase.value?.precio) || 0))
    }

    /** La escalera de incentivos: cada canal paga al menos lo que el anterior. */
    function minimoExigido(i) {
        return i > 0 ? (Number(canalesConComision.value[i - 1].comision_max_pct) || 0) : 0
    }

    function errorEscalera(i) {
        return (Number(canalesConComision.value[i].comision_min_pct) || 0) < minimoExigido(i)
    }

    const hayErrorDeEscalera = computed(() =>
        canalesConComision.value.some((c, i) => (Number(c.comision_min_pct) || 0) > 0 && errorEscalera(i))
    )

    /**
     * Hasta dónde puede descontar un canal sin invadir al de abajo.
     *
     * Cada uno descuenta hasta llegar al precio del canal anterior en la lista, y el primero
     * hasta el canal base. Así un descuento nunca hace que un cliente pague menos que el
     * canal que tiene mejor precio por derecho.
     */
    function descuentoMaxDe(canal) {
        const lista = canales.value ?? []
        const i     = lista.findIndex(c => c.segmentacion_opcion_id === canal.segmentacion_opcion_id)
        const piso  = i > 0 ? (Number(lista[i - 1].precio) || 0) : 0
        const base  = Number(canal.precio) || 0

        if (! base) return 0

        return Math.max(0, parseFloat(((base - piso) / base * 100).toFixed(2)))
    }

    /**
     * Propone comisiones que respetan la escalera.
     *
     * Reparte parte del excedente de cada canal y arranca cada uno donde terminó el
     * anterior. El que sea precio público lleva un poco más: traer un cliente nuevo cuesta
     * más que atender a uno que ya compra.
     */
    function sugerirComisiones() {
        let anterior = 0

        canalesConComision.value.forEach(canal => {
            const precio    = Number(canal.precio) || 0
            const excedente = excedenteDe(canal)

            if (! precio || ! excedente) {
                canal.comision_min_pct = anterior
                canal.comision_max_pct = anterior

                return
            }

            const disponible = (excedente / precio) * 100
            const reparto    = canal.es_precio_publico ? 0.8 : 0.65

            canal.comision_min_pct = anterior
            canal.comision_max_pct = parseFloat(Math.max(anterior * 1.2, disponible * reparto).toFixed(2))

            anterior = canal.comision_max_pct
        })
    }

    /**
     * Escribe el descuento máximo de cada canal antes de guardar.
     *
     * Sale de la distancia con el canal de abajo, así que no se pide en pantalla: se calcula.
     * El canal base no descuenta — su precio es el piso.
     */
    function aplicarDescuentosMax() {
        (canales.value ?? []).forEach(canal => {
            canal.descuento_max_pct = canal.es_canal_base ? 0 : descuentoMaxDe(canal)
        })
    }

    return {
        calcPrecio, canalBase, canalesConComision, excedenteDe, minimoExigido,
        errorEscalera, hayErrorDeEscalera, descuentoMaxDe, sugerirComisiones,
        aplicarDescuentosMax,
    }
}
