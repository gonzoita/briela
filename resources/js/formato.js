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
 * Pesos, sin decimales.
 *
 * En pesos colombianos los centavos no se usan al cobrar. La base los admite —las columnas
 * son `decimal(_,2)`— y por eso los campos aceptan escribirlos; mostrarlos en cada cifra del
 * catálogo sería ruido.
 */
export function formatCOP(valor) {
    return new Intl.NumberFormat('es-CO', { maximumFractionDigits: 0 })
        .format(Math.round(Number(valor) || 0))
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
