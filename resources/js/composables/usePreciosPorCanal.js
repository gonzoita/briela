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
/**
 * Cuánto del excedente se le propone al vendedor cuando vende sin descuento.
 *
 * Es una sugerencia, no un tope del sistema: los dos campos quedan editables. El canal de
 * precio público lleva más porque traer un cliente nuevo cuesta más que atender a uno que
 * ya compra.
 */
const REPARTO_MAX         = 0.5
const REPARTO_MAX_PUBLICO = 0.7

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

    /**
     * La plata que paga el canal anterior en su tope, que es el piso del siguiente.
     *
     * Vender un canal con su descuento máximo deja el mismo precio que vender el de abajo
     * sin descuento: la comisión tiene que ser la misma. El primer canal con comisión no
     * tiene piso porque su descuento máximo lo lleva hasta el canal base, donde no queda
     * excedente que repartir.
     */
    function pisoComisionValor(i) {
        if (i <= 0) return 0

        const anterior = canalesConComision.value[i - 1]

        return excedenteDe(anterior) * (Number(anterior.comision_max_pct) || 0) / 100
    }

    /**
     * Ese mismo piso, dicho en el porcentaje de ESTE canal.
     *
     * Cada canal cobra su porcentaje sobre su propio excedente, así que los porcentajes de
     * dos canales no se pueden comparar crudos —es lo que se hacía antes, y comparaba dos
     * bases distintas—: hay que convertirlos por la plata.
     */
    function minimoExigido(i) {
        const excedente = i > 0 ? excedenteDe(canalesConComision.value[i]) : 0

        if (! excedente) return 0

        return Math.min(100, parseFloat((pisoComisionValor(i) / excedente * 100).toFixed(2)))
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
     * Propone comisiones que reparten el excedente y respetan la escalera.
     *
     * El porcentaje guardado se cobra SOBRE EL EXCEDENTE, no sobre el precio: así lo
     * calculan la cotización y la liquidación. Hasta el 17 ago 2026 la sugerencia sacaba el
     * porcentaje que el excedente representa del precio —49.000 de 1.375.000 es 3,56 %— y
     * ese 3,56 % después se gastaba otra vez sobre el excedente: el vendedor terminaba con
     * 1.137 de los 49.000 que había en juego. El excedente entraba dos veces.
     *
     * El tope es el reparto directo; el piso es lo que ya paga el canal de abajo.
     */
    function sugerirComisiones() {
        canalesConComision.value.forEach((canal, i) => {
            // Sin excedente no hay nada que repartir: el canal vale lo mismo que el base.
            if (! excedenteDe(canal)) {
                canal.comision_min_pct = 0
                canal.comision_max_pct = 0

                return
            }

            const minimo  = minimoExigido(i)
            const reparto = (canal.es_precio_publico ? REPARTO_MAX_PUBLICO : REPARTO_MAX) * 100

            canal.comision_min_pct = minimo
            canal.comision_max_pct = parseFloat(Math.min(100, Math.max(minimo, reparto)).toFixed(2))
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
        pisoComisionValor, errorEscalera, hayErrorDeEscalera, descuentoMaxDe,
        sugerirComisiones, aplicarDescuentosMax,
    }
}
