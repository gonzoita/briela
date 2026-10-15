/**
 * Cómo se escriben los números en pantalla, en un solo lugar.
 *
 * Existe por un problema concreto: los porcentajes se guardan con dos decimales
 * —`decimal(5,2)` en toda la base— y las pantallas los mostraban con `toFixed(0)` o
 * `toFixed(1)`. Tres pasos de producción repartidos al 33,33% salían como «33%» tres veces,
 * que suma 99 y parece un error de cuentas cuando en realidad estaba bien.
 */

/**
 * Un porcentaje con hasta dos decimales, sin ceros de relleno.
 *
 * 30 → «30». 33.33 → «33,33». 2.5 → «2,5». 2.50 → «2,5».
 *
 * Los ceros se quitan porque un catálogo lleno de «30,00%» es ruido: los decimales importan
 * cuando existen, y cuando no existen estorban.
 */
export function formatPct(valor) {
    const n = Number(valor) || 0

    return new Intl.NumberFormat('es-CO', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 2,
    }).format(n)
}

/**
 * Las monedas, como las escribe `App\Support\Monedas::CATALOGO`. Si se agrega una, se agrega
 * en las dos: `tests/Feature/PaisesTest.php` compara las listas.
 */
const MONEDAS = {
    COP: { simbolo: '$',    decimales: 2 },
    USD: { simbolo: 'US$',  decimales: 2 },
    EUR: { simbolo: '€',    decimales: 2 },
    VES: { simbolo: 'Bs.S', decimales: 2 },
    PEN: { simbolo: 'S/',   decimales: 2 },
    MXN: { simbolo: 'MX$',  decimales: 2 },
    CLP: { simbolo: 'CL$',  decimales: 2 },
    ARS: { simbolo: 'AR$',  decimales: 2 },
    BRL: { simbolo: 'R$',   decimales: 2 },
    BOB: { simbolo: 'Bs',   decimales: 2 },
    PYG: { simbolo: '₲',    decimales: 2 },
    UYU: { simbolo: '$U',   decimales: 2 },
    CRC: { simbolo: '₡',    decimales: 2 },
    GTQ: { simbolo: 'Q',    decimales: 2 },
    HNL: { simbolo: 'L',    decimales: 2 },
    NIO: { simbolo: 'C$',   decimales: 2 },
    DOP: { simbolo: 'RD$',  decimales: 2 },
    CAD: { simbolo: 'CA$',  decimales: 2 },
    GBP: { simbolo: '£',    decimales: 2 },
    CNY: { simbolo: 'CN¥',  decimales: 2 },
}

/**
 * La moneda base de la instalación: en la que se guarda todo. `app.js` la fija desde lo que
 * comparte el servidor, al arrancar y en cada navegación.
 */
let base = 'COP'

/** Los códigos de moneda disponibles, para un selector. */
export function codigosMoneda() {
    return Object.keys(MONEDAS)
}

export function setMonedaBase(codigo) {
    if (MONEDAS[codigo]) base = codigo
}

export function monedaBase() {
    return base
}

/** Los decimales con los que se escribe un monto en la moneda base. */
export function decimalesBase() {
    return MONEDAS[base].decimales
}

/**
 * Un monto en la moneda base, sin símbolo.
 *
 * El nombre es de cuando todo era en pesos y se conserva porque lo usan 134 pantallas. En
 * pesos colombianos los centavos no se usan al cobrar —la base los admite, las columnas son
 * `decimal(_,2)`, pero mostrarlos en cada cifra del catálogo sería ruido—; en dólares, sí.
 */
export function formatCOP(valor) {
    const d = decimalesBase()

    return new Intl.NumberFormat('es-CO', { minimumFractionDigits: d, maximumFractionDigits: d })
        .format(d === 0 ? Math.round(Number(valor) || 0) : (Number(valor) || 0))
}

/**
 * Un valor en la moneda base, escrito en la moneda de un documento.
 *
 * Por dentro todo se guarda en la moneda base; una cotización en dólares guarda una tasa y así
 * se le muestra al cliente. 6.075.000 a 4.500 → «US$ 1.350,00». Es la misma cuenta de
 * `App\Support\Monedas::formatear()`: si se cambia una, se cambia la otra.
 */
export function formatMoneda(valorEnBase, moneda = null, tasa = 1) {
    moneda = moneda || base
    const info  = MONEDAS[moneda] ?? MONEDAS[base]
    const t     = Number(tasa) || 0
    const monto = Number(valorEnBase) || 0
    const valor = moneda === base || t <= 0 ? monto : monto / t

    const texto = new Intl.NumberFormat('es-CO', {
        minimumFractionDigits: info.decimales,
        maximumFractionDigits: info.decimales,
    }).format(info.decimales === 0 ? Math.round(valor) : valor)

    return moneda === base ? `${info.simbolo}${texto}` : `${info.simbolo} ${texto}`
}

/**
 * Cantidades con hasta tres decimales, sin ceros de relleno.
 *
 * Las cantidades se guardan como `decimal(_,3)` porque hay unidades que se miden en metros y
 * en kilos. «3,5 m» se lee; «3,500 m» hace dudar de si son tres mil quinientos.
 */
export function formatCantidad(valor) {
    return new Intl.NumberFormat('es-CO', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 3,
    }).format(Number(valor) || 0)
}
