/**
 * Convierte la respuesta de la IA en HTML legible.
 *
 * Los modelos escriben en Markdown (**negrita**, viñetas con -, títulos con #).
 * Si se muestra tal cual, el usuario ve los asteriscos crudos.
 *
 * SEGURIDAD: primero se escapa TODO el HTML del texto y solo después se
 * agregan las etiquetas que nosotros generamos. Así, aunque la IA devolviera
 * algo con HTML o un script, nunca se ejecuta.
 */

const escaparHtml = (s) =>
    String(s).replace(/[&<>"']/g, (c) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
    }[c]))

/** Marcas dentro de una línea: negrita y cursiva. */
function enLinea(texto) {
    return texto
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/(^|[^*])\*([^*\n]+?)\*/g, '$1<em>$2</em>')
        .replace(/`([^`\n]+?)`/g, '<code class="px-1 rounded bg-gray-100 text-[0.85em]">$1</code>')
}

export function formatearMensaje(texto) {
    if (!texto) return ''

    const lineas = escaparHtml(texto).split('\n')
    const salida = []

    for (const linea of lineas) {
        const l = linea.trim()

        if (l === '') {
            salida.push('<div class="h-2"></div>')
            continue
        }

        // Títulos: # Algo  → se muestran como una línea destacada.
        const titulo = l.match(/^#{1,6}\s*(.+)$/)
        if (titulo) {
            salida.push(`<div class="font-semibold mt-1 mb-0.5">${enLinea(titulo[1])}</div>`)
            continue
        }

        // Viñetas: - algo  /  • algo  /  * algo
        const vineta = l.match(/^[-•*]\s+(.+)$/)
        if (vineta) {
            salida.push(
                `<div class="flex gap-1.5"><span class="text-gray-400 shrink-0">·</span>` +
                `<span>${enLinea(vineta[1])}</span></div>`
            )
            continue
        }

        // Listas numeradas: 1. algo
        const numerada = l.match(/^(\d+)[.)]\s+(.+)$/)
        if (numerada) {
            salida.push(
                `<div class="flex gap-1.5"><span class="text-gray-400 shrink-0">${numerada[1]}.</span>` +
                `<span>${enLinea(numerada[2])}</span></div>`
            )
            continue
        }

        salida.push(`<div>${enLinea(l)}</div>`)
    }

    return salida.join('')
}

/**
 * Texto plano, para leerlo en voz alta: sin asteriscos ni símbolos que la voz
 * pronunciaría de forma rara.
 */
export function comoTextoPlano(texto) {
    return String(texto || '')
        .replace(/\*\*(.+?)\*\*/g, '$1')
        .replace(/[*#`]/g, '')
        .replace(/^\s*[-•]\s+/gm, '')
        .replace(/\n{2,}/g, '. ')
        .replace(/\n/g, '. ')
        .trim()
}
