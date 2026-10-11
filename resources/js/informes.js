import { formatCOP, formatPct, formatCantidad } from '@/formato'

/**
 * Cómo se muestra un valor de un informe según el tipo de su columna. Lo usan la tarjeta del
 * chat y la pantalla de Informes: el servidor manda los valores crudos —números de verdad,
 * fechas AAAA-MM-DD— y la pantalla los escribe.
 */
export function formatoValor(valor, tipo) {
    if (valor === null || valor === undefined || valor === '') return '—'

    switch (tipo) {
        case 'bool':   return valor ? 'Sí' : 'No'
        case 'moneda': return '$' + formatCOP(valor)
        case 'pct':    return formatPct(valor) + ' %'
        case 'entero': return formatCantidad(Math.round(Number(valor)))
        case 'numero': return formatCantidad(valor)
        case 'fecha': {
            const [a, m, d] = String(valor).slice(0, 10).split('-')
            return d ? `${d}/${m}/${a}` : String(valor)
        }
        default:       return String(valor)
    }
}

/** Los tipos que van alineados a la derecha en una tabla. */
export const esNumerico = (tipo) => ['moneda', 'pct', 'entero', 'numero'].includes(tipo)
