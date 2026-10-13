/**
 * Los personajes, píxel a píxel.
 *
 * Cada función recibe un pintor `r(x, y, ancho, alto, color)` anclado en los pies (o en el asiento,
 * si está sentado), el aspecto (`k`: piel, pelo, peinado, camisa, pantalón, accesorio) y la pose
 * (`p`: caminando y en qué paso, mano levantada, papel, taza, cara, tecleando).
 *
 * Están dibujados a doble detalle: cabeza de 12 píxeles con ojos con brillo, cejas, nariz y boca
 * que cambia con el ánimo; camisa con cuello, sombra y botones; brazos que se balancean al caminar.
 */
import { tono } from './motor'

const NEGRO = '#1b1b1b'
const BOCA = '#8a3b3b'
const ZAPATO = '#1c1f24'

/** La cara completa: cabeza, orejas, ojos, cejas, nariz, boca, accesorios y pelo. `y0` = arriba de la cabeza. */
function cara(r, k, p, y0, parpadeo) {
    const sombra = tono(k.piel, 0.9)
    r(-6, y0, 12, 12, k.piel)
    r(3, y0, 3, 12, sombra)
    r(-5, y0 + 12, 10, 1, tono(k.piel, 0.86))
    r(-7, y0 + 4, 1, 4, tono(k.piel, 0.85))
    r(6, y0 + 4, 1, 4, tono(k.piel, 0.8))

    // Ojos (parpadean de vez en cuando)
    if (parpadeo) {
        r(-4, y0 + 6, 2, 1, NEGRO)
        r(2, y0 + 6, 2, 1, NEGRO)
    } else {
        r(-4, y0 + 5, 2, 2, NEGRO)
        r(2, y0 + 5, 2, 2, NEGRO)
        r(-4, y0 + 5, 1, 1, '#ffffff')
        r(2, y0 + 5, 1, 1, '#ffffff')
    }
    r(-4, y0 + 3, 3, 1, tono(k.pelo, 0.9))
    r(1, y0 + 3, 3, 1, tono(k.pelo, 0.9))
    r(0, y0 + 7, 1, 2, tono(k.piel, 0.8))

    if (p.cara === 'feliz') {
        r(-2, y0 + 10, 4, 1, BOCA); r(-3, y0 + 9, 1, 1, BOCA); r(2, y0 + 9, 1, 1, BOCA)
    } else if (p.cara === 'triste') {
        r(-2, y0 + 9, 4, 1, BOCA); r(-3, y0 + 10, 1, 1, BOCA); r(2, y0 + 10, 1, 1, BOCA)
    } else {
        r(-1, y0 + 10, 3, 1, BOCA)
    }

    if (k.accesorio === 'gafas') {
        r(-5, y0 + 4, 4, 1, '#111827'); r(1, y0 + 4, 4, 1, '#111827'); r(-1, y0 + 5, 2, 1, '#111827')
        r(-5, y0 + 4, 1, 3, '#111827'); r(4, y0 + 4, 1, 3, '#111827')
    }

    pelo(r, k, y0)

    if (k.accesorio === 'audifonos') {
        r(-8, y0 + 2, 2, 7, '#0f172a'); r(6, y0 + 2, 2, 7, '#0f172a')
        r(-7, y0 - 2, 14, 2, '#0f172a')
        r(-8, y0 + 8, 1, 3, '#0f172a'); r(-7, y0 + 10, 4, 1, '#22d3ee')
    }
}

function pelo(r, k, y0) {
    const c = k.pelo
    const luz = tono(c, 1.4)
    switch (k.peinado) {
        case 'rapado':
            r(-6, y0 - 1, 12, 3, c)
            break
        case 'largo':
            r(-6, y0 - 2, 12, 5, c); r(-8, y0, 2, 15, c); r(6, y0, 2, 15, c); r(-6, y0 + 2, 4, 1, c)
            r(-3, y0 - 2, 4, 1, luz)
            break
        case 'mono':
            r(-6, y0 - 2, 12, 5, c); r(-7, y0, 1, 5, c); r(6, y0, 1, 5, c); r(-2, y0 - 6, 5, 4, c)
            r(-1, y0 - 6, 2, 1, luz)
            break
        case 'rizado':
            r(-7, y0 - 3, 14, 6, c); r(-8, y0, 2, 7, c); r(6, y0, 2, 7, c)
            for (const [x, y] of [[-6, -3], [-2, -3], [2, -3], [-4, -1], [0, -1], [4, -1]]) r(x, y0 + y, 1, 1, luz)
            break
        default: // corto
            r(-6, y0 - 2, 12, 5, c); r(-7, y0, 1, 5, c); r(6, y0, 1, 4, c); r(-6, y0 + 3, 3, 1, c)
            r(-4, y0 - 2, 4, 1, luz)
    }
}

function sombraPiso(r, ancho = 18) {
    r(-ancho / 2, -1, ancho, 2, 'rgba(0,0,0,0.22)')
    r(-ancho / 2 + 2, 1, ancho - 4, 1, 'rgba(0,0,0,0.14)')
}

/** De pie, de frente (también caminando hacia el frente o de lado). Pies en (0, 0). */
export function dePie(r, k, p = {}) {
    const camisa = k.chaqueta ?? k.camisa
    const sombra = tono(camisa, 0.78)
    const luz = tono(camisa, 1.12)
    const pantalon = k.pantalon ?? '#273449'
    const paso = p.caminando ? p.paso : -1
    const li = paso === 0 ? -2 : 0
    const ri = paso === 1 ? -2 : 0
    const brazo = p.caminando ? (paso === 0 ? 1 : -1) : 0

    sombraPiso(r)
    r(-7, -3 + li, 6, 3, ZAPATO); r(-7, -3 + li, 6, 1, '#3a4048')
    r(1, -3 + ri, 6, 3, ZAPATO); r(1, -3 + ri, 6, 1, '#3a4048')
    r(-6, -17, 5, 14 + li, pantalon); r(-3, -17, 2, 14 + li, tono(pantalon, 0.75))
    r(1, -17, 5, 14 + ri, pantalon); r(1, -17, 1, 14 + ri, tono(pantalon, 0.75))
    r(-7, -18, 14, 2, '#1f2937'); r(-1, -18, 2, 2, '#d1b45a')

    r(-8, -31, 16, 13, camisa)
    r(4, -31, 4, 13, sombra)
    r(-8, -31, 1, 13, luz)
    if (k.chaqueta) {
        // Camisa blanca en V bajo la chaqueta, y solapas
        r(-2, -31, 4, 7, '#f1f5f9'); r(-1, -24, 2, 2, '#f1f5f9')
        r(-3, -31, 1, 8, tono(k.chaqueta, 0.7)); r(2, -31, 1, 8, tono(k.chaqueta, 0.7))
    } else {
        r(0, -26, 1, 1, sombra); r(0, -22, 1, 1, sombra)
    }
    r(-2, -31, 4, 2, k.piel); r(-1, -29, 2, 1, k.piel)
    r(-4, -31, 2, 1, '#f1f5f9'); r(2, -31, 2, 1, '#f1f5f9')
    if (k.accesorio === 'corbata' || k.chaqueta) { r(-1, -29, 2, 9, k.corbata ?? '#b91c1c'); r(-1, -29, 2, 1, tono(k.corbata ?? '#b91c1c', 0.7)) }

    if (p.mano) {
        // Levanta la mano derecha; con la izquierda sostiene un papel
        const sube = p.saluda ? 1 : 0
        r(8, -42 - sube, 3, 12, camisa); r(8, -45 - sube, 3, 3, k.piel)
        r(-11, -30, 3, 11, camisa); r(-11, -19, 3, 3, k.piel)
        if (p.papel) { r(-15, -24, 6, 8, '#f8fafc'); r(-14, -22, 4, 1, '#94a3b8'); r(-14, -20, 4, 1, '#94a3b8') }
    } else {
        r(-11, -30 + brazo, 3, 11, camisa); r(-11, -30 + brazo, 1, 11, luz); r(-11, -19 + brazo, 3, 3, k.piel)
        r(8, -30 - brazo, 3, 11, sombra); r(8, -19 - brazo, 3, 3, tono(k.piel, 0.9))
        if (p.taza) { r(10, -22, 4, 5, '#f8fafc'); r(14, -21, 1, 3, '#cbd5e1') }
        if (p.tableta) { r(-14, -26, 7, 9, '#0f172a'); r(-13, -25, 5, 7, '#22d3ee'); r(-12, -23, 3, 1, '#ecfeff') }
    }

    r(-2, -33, 4, 2, tono(k.piel, 0.88))
    cara(r, k, p, -45, p.parpadeo)
}

/** De pie, de espaldas (mirando hacia el mostrador, o caminando hacia el fondo). */
export function dePieEspalda(r, k, p = {}) {
    const camisa = k.chaqueta ?? k.camisa
    const sombra = tono(camisa, 0.78)
    const pantalon = k.pantalon ?? '#273449'
    const paso = p.caminando ? p.paso : -1
    const li = paso === 0 ? -2 : 0
    const ri = paso === 1 ? -2 : 0

    sombraPiso(r)
    r(-7, -3 + li, 6, 3, ZAPATO)
    r(1, -3 + ri, 6, 3, ZAPATO)
    r(-6, -17, 5, 14 + li, pantalon)
    r(1, -17, 5, 14 + ri, pantalon); r(1, -17, 1, 14 + ri, tono(pantalon, 0.75))
    r(-7, -18, 14, 2, '#1f2937')
    r(-8, -31, 16, 13, camisa); r(4, -31, 4, 13, sombra); r(-8, -31, 16, 1, tono(camisa, 1.1))
    r(-11, -30, 3, 11, camisa); r(8, -30, 3, 11, sombra)
    r(-11, -19, 3, 3, k.piel); r(8, -19, 3, 3, k.piel)
    r(-2, -33, 4, 2, tono(k.piel, 0.88))
    r(-6, -45, 12, 12, k.pelo)
    r(-7, -41, 1, 4, k.piel); r(6, -41, 1, 4, tono(k.piel, 0.85))
    r(-3, -45, 4, 1, tono(k.pelo, 1.35))
    if (k.peinado === 'largo') r(-7, -37, 14, 8, k.pelo)
    if (k.peinado === 'mono') r(-2, -49, 5, 4, k.pelo)
    if (k.peinado === 'rapado') { r(-6, -42, 12, 9, k.piel); r(-6, -45, 12, 3, k.pelo) }
}

/** Sentado de espaldas, frente a su computador. El ancla es el asiento. */
export function sentadoEspalda(r, k, p = {}) {
    const sombra = tono(k.camisa, 0.78)
    const t = p.teclea ?? 0
    r(-9, -16, 18, 16, k.camisa)
    r(4, -16, 5, 16, sombra)
    r(-9, -16, 18, 1, tono(k.camisa, 1.12))
    r(-11, -14 + t, 3, 9, k.camisa)
    r(8, -14 + (1 - t), 3, 9, sombra)
    r(-2, -18, 4, 2, tono(k.piel, 0.88))
    r(-6, -30, 12, 12, k.pelo)
    r(-7, -26, 1, 4, k.piel)
    r(6, -26, 1, 4, tono(k.piel, 0.85))
    r(-3, -30, 4, 1, tono(k.pelo, 1.35))
    if (k.peinado === 'largo') r(-7, -22, 14, 7, k.pelo)
    if (k.peinado === 'mono') r(-2, -34, 5, 4, k.pelo)
    if (k.peinado === 'rapado') { r(-6, -27, 12, 9, k.piel); r(-6, -30, 12, 3, k.pelo) }
    if (k.accesorio === 'audifonos') { r(-8, -27, 2, 6, '#0f172a'); r(6, -27, 2, 6, '#0f172a'); r(-7, -32, 14, 2, '#0f172a') }
}

/** Sentado de frente, detrás de un escritorio o un mostrador (el mueble tapa de la cintura abajo). */
export function sentadoFrente(r, k, p = {}) {
    const camisa = k.chaqueta ?? k.camisa
    const sombra = tono(camisa, 0.78)
    r(-9, -15, 18, 15, camisa)
    r(4, -15, 5, 15, sombra)
    r(-9, -15, 1, 15, tono(camisa, 1.12))
    if (k.chaqueta) {
        r(-2, -15, 4, 8, '#f1f5f9')
        r(-1, -13, 2, 9, k.corbata ?? '#b91c1c')
    } else {
        r(-4, -15, 2, 1, '#f1f5f9'); r(2, -15, 2, 1, '#f1f5f9')
        r(-2, -15, 4, 2, k.piel)
    }
    // Brazos sobre el escritorio; si teclea, las manos se mueven
    const t = p.teclea ?? 0
    r(-12, -13, 3, 10, camisa); r(9, -13, 3, 10, sombra)
    r(-12, -3 - t, 4, 3, k.piel); r(8, -3 - (1 - t), 4, 3, tono(k.piel, 0.9))
    r(-2, -17, 4, 2, tono(k.piel, 0.88))
    cara(r, k, p, -29, p.parpadeo)
}
