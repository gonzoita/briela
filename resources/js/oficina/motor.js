/**
 * El motor de las escenas en pixel art isométrico (la oficina del Equipo de IA y la recepción).
 *
 * No hay imágenes ni librerías de juegos: todo se pinta con rectángulos de un píxel sobre un
 * lienzo pequeño (el «búfer») que después se amplía sin suavizar. Por eso pesa casi nada, va igual
 * en cada instalación y cada escena arma su plano con los datos que llegan.
 *
 * **Nitidez.** El búfer se pinta a doble detalle (baldosas de 44 px) y se amplía con una escala
 * ENTERA cuando la pantalla es de densidad normal y la escena llena al menos el 70 % del ancho:
 * cada píxel del dibujo ocupa exactamente los mismos píxeles de la pantalla. En pantallas de alta
 * densidad (retina, celulares) se llena el ancho: ahí la diferencia de un píxel no se ve.
 *
 * Los textos —nombres, letreros, globos— se escriben aparte, a la resolución real, y se acomodan
 * para no taparse entre sí.
 *
 * Una escena hereda de `EscenaPixel` y pone tres cosas: `armar()` (el plano), `pintarFondo()`
 * (lo que no se mueve) y `componer()` (muebles y personajes, de atrás hacia adelante).
 */

export const TW = 44   // ancho de una baldosa en píxeles del búfer
export const TH = 22   // alto de una baldosa
export const ALTO_PARED = 54
// Las alturas de los muebles se escriben en «píxeles de mueble» y se multiplican por este factor;
// los personajes no. Así ganan protagonismo, como en un juego.
export const Z = 1.44

// ─── Colores ────────────────────────────────────────────────────────────────────

export const C = {
    fondo: '#0b1220',
    piso1: '#dccfb4', piso2: '#d2c4a8', pisoJunta: '#c2b495',
    pasillo1: '#9fb3c8', pasillo2: '#95aac0',
    alfombra1: '#5a5f9e', alfombra2: '#53588f', alfombraBorde: '#3f4378',
    plataforma1: '#45697f', plataforma2: '#3f6178', plataformaLuz: '#38bdf8',
    pared: '#8fb1c4', paredOscura: '#6f93a8', zocalo: '#4c6b7d', molduraPared: '#a9c7d6',
    ventana: '#cfeaf7', ventanaBrillo: '#eef9ff', marcoVentana: '#5d7f92',
    panel: '#7f9fb0', panelBorde: '#5f7e8f',
    madera: '#c8874e', maderaOscura: '#9a5f31', maderaDirector: '#7a3f22',
    metal: '#9aa5b1', metalOscuro: '#5b6672',
    monitor: '#20262e', teclado: '#3b4350',
    silla: '#2c4a6e', sillaDirector: '#2a2a33',
    vidrio: '#a9d3e3',
    planta: '#2f8f4e', plantaClara: '#4cb96b', maceta: '#a0522d',
    servidor: '#1f2937',
    mostrador: '#e7e2d6', mostradorFrente: '#3b4a6b',
    sofa: '#7c3aed',
    pantalla: {
        trabajando: '#0f2a1a', libre: '#1e3a8a', aprobacion: '#7c2d12',
        error: '#7f1d1d', terminado: '#065f46', apagado: '#0b0f14', atendiendo: '#0c4a6e',
    },
}

export const PELO = ['#2b1d14', '#4b3621', '#1f1f1f', '#7a4b21', '#c58b3c', '#5a2e1b', '#9ca3af']
export const PIEL = ['#f2c9a0', '#e0ac80', '#c68642', '#8d5524', '#f5d0b5']
export const PEINADOS = ['corto', 'largo', 'mono', 'rizado', 'corto', 'rapado']
export const CAMISA = {
    azul: '#3b82f6', ambar: '#f59e0b', verde: '#10b981', rojo: '#ef4444',
    violeta: '#8b5cf6', naranja: '#f97316', gris: '#64748b', rosa: '#ec4899',
}

export function tono(hex, f) {
    if (! hex || hex[0] !== '#') return hex
    const n = parseInt(hex.slice(1), 16)
    const c = (v) => Math.max(0, Math.min(255, Math.round(v * f)))
    return '#' + [(n >> 16) & 255, (n >> 8) & 255, n & 255].map(v => c(v).toString(16).padStart(2, '0')).join('')
}

export function recortar(texto, max) {
    const t = String(texto ?? '')
    return t.length > max ? t.slice(0, max - 1) + '…' : t
}

/** Un aspecto estable para una persona: el mismo número da siempre el mismo personaje. */
export function aspecto(semilla, camisa) {
    const s = Math.abs(Math.round(semilla)) || 1
    return {
        pelo: PELO[(s * 7) % PELO.length],
        piel: PIEL[(s * 3) % PIEL.length],
        peinado: PEINADOS[(s * 5) % PEINADOS.length],
        camisa: camisa ?? Object.values(CAMISA)[(s * 11) % Object.values(CAMISA).length],
        pantalon: ['#273449', '#1f2937', '#3f3f46', '#1e3a5f'][(s * 13) % 4],
        accesorio: (s % 5 === 0) ? 'gafas' : null,
        fase: (s * 13) % 10,
    }
}

// ─── Dibujo de bajo nivel ─────────────────────────────────────────────────────────

/** Rellena un polígono fila por fila con rectángulos de un píxel: bordes nítidos, sin suavizado. */
export function poligono(ctx, pts, color) {
    ctx.fillStyle = color
    let minY = Infinity, maxY = -Infinity
    for (const p of pts) { minY = Math.min(minY, p[1]); maxY = Math.max(maxY, p[1]) }

    for (let y = Math.floor(minY); y < Math.ceil(maxY); y++) {
        const yc = y + 0.5
        const xs = []
        for (let i = 0; i < pts.length; i++) {
            const [x1, y1] = pts[i]
            const [x2, y2] = pts[(i + 1) % pts.length]
            if ((y1 <= yc && y2 > yc) || (y2 <= yc && y1 > yc)) {
                xs.push(x1 + (yc - y1) * (x2 - x1) / (y2 - y1))
            }
        }
        xs.sort((p, q) => p - q)
        for (let i = 0; i + 1 < xs.length; i += 2) {
            const xa = Math.round(xs[i]), xb = Math.round(xs[i + 1])
            if (xb > xa) ctx.fillRect(xa, y, xb - xa, 1)
        }
    }
}

// ─── Buscar el camino (A* sobre baldosas) ──────────────────────────────────────

export function camino(plano, desde, hasta) {
    const { W, H, libre } = plano
    const a = [Math.floor(desde.x), Math.floor(desde.y)]
    const b = [Math.floor(hasta.x), Math.floor(hasta.y)]
    const clave = (x, y) => x * 1000 + y
    const ok = (x, y) => x >= 0 && y >= 0 && x < W && y < H && (libre[x][y] || (x === b[0] && y === b[1]) || (x === a[0] && y === a[1]))

    const abiertos = [[a[0], a[1]]]
    const g = new Map([[clave(...a), 0]])
    const de = new Map()
    const h = (x, y) => Math.abs(x - b[0]) + Math.abs(y - b[1])

    let vueltas = 0
    while (abiertos.length && vueltas++ < 4000) {
        abiertos.sort((p, q) => (g.get(clave(...p)) + h(...p)) - (g.get(clave(...q)) + h(...q)))
        const [x, y] = abiertos.shift()

        if (x === b[0] && y === b[1]) {
            const ruta = [{ x: hasta.x, y: hasta.y }]
            let k = clave(x, y)
            while (de.has(k)) {
                const [px, py] = de.get(k)
                ruta.unshift({ x: px + 0.5, y: py + 0.5 })
                k = clave(px, py)
            }
            ruta.shift()
            return ruta
        }

        for (const [dx, dy] of [[1, 0], [-1, 0], [0, 1], [0, -1]]) {
            const nx = x + dx, ny = y + dy
            if (! ok(nx, ny)) continue
            const ng = g.get(clave(x, y)) + 1
            if (ng < (g.get(clave(nx, ny)) ?? Infinity)) {
                g.set(clave(nx, ny), ng)
                de.set(clave(nx, ny), [x, y])
                if (! abiertos.some(([ax, ay]) => ax === nx && ay === ny)) abiertos.push([nx, ny])
            }
        }
    }

    return [{ x: hasta.x, y: hasta.y }]
}

/** La cuadrícula de lo que se puede pisar: una baldosa está ocupada si un mueble la toca. */
export function cuadricula(W, H, muebles, noBloquean = []) {
    const libre = Array.from({ length: W }, () => Array(H).fill(true))
    for (const m of muebles) {
        if (noBloquean.includes(m.tipo) || m.z > 0) continue
        for (let gx = Math.floor(m.x); gx < Math.ceil(m.x + m.w); gx++) {
            for (let gy = Math.floor(m.y); gy < Math.ceil(m.y + m.d); gy++) {
                if (gx >= 0 && gy >= 0 && gx < W && gy < H) libre[gx][gy] = false
            }
        }
    }
    return libre
}

// ─── La escena ──────────────────────────────────────────────────────────────────

export class EscenaPixel {
    constructor(lienzo, opciones = {}) {
        this.lienzo = lienzo
        this.ctx = lienzo.getContext('2d')
        this.buf = document.createElement('canvas')
        this.bctx = this.buf.getContext('2d')
        this.fondo = document.createElement('canvas')
        this.quieto = !! opciones.quieto
        this.escala = 1
        this.dpr = 1
        this.t = 0
        this.ultimo = 0
        this.corriendo = false
        this.personajes = new Map()
        this.cajas = []
        this.ocupados = []
        this.elegido = null
        this.cuadro = this.cuadro.bind(this)
    }

    // ─── Tamaño y escala ─────────────────────────────────────────────────────

    /** Arma el búfer a la medida del plano. Lo llama la escena cuando cambia el plano. */
    prepararBufer() {
        const { W, H } = this.plano
        this.ox = H * (TW / 2) + 16
        this.oy = Math.round(ALTO_PARED * Z) + 70
        this.bw = Math.ceil((W + H) * (TW / 2) + 32)
        this.bh = Math.ceil((W + H) * (TH / 2) + this.oy + 24)
        this.buf.width = this.bw
        this.buf.height = this.bh
        this.fondo.width = this.bw
        this.fondo.height = this.bh
        const ctx = this.fondo.getContext('2d')
        ctx.fillStyle = C.fondo
        ctx.fillRect(0, 0, this.bw, this.bh)
        this.pintarFondo(ctx)
        this.ajustar()
    }

    redimensionar(anchoCss, dpr = 1) {
        this.anchoCss = anchoCss
        this.dpr = dpr
        this.ajustar()
    }

    ajustar() {
        if (! this.anchoCss || ! this.bw) return
        const disponible = this.anchoCss * this.dpr
        const entera = Math.floor(disponible / this.bw)

        // Densidad normal: escala entera si la escena llena al menos el 70 %. Alta densidad, o si
        // la entera queda muy chica: se llena el ancho.
        if (this.dpr < 2 && entera >= 1 && (this.bw * entera) / disponible >= 0.7) {
            this.escala = entera
        } else {
            this.escala = Math.max(disponible / this.bw, 0.8 * this.dpr)
        }

        this.lienzo.width = Math.round(this.bw * this.escala)
        this.lienzo.height = Math.round(this.bh * this.escala)
        this.lienzo.style.width = (this.lienzo.width / this.dpr) + 'px'
        this.lienzo.style.height = (this.lienzo.height / this.dpr) + 'px'
        this.dibujar()
    }

    iso(gx, gy, z = 0) {
        return [Math.round((gx - gy) * (TW / 2) + this.ox), Math.round((gx + gy) * (TH / 2) + this.oy - z * Z)]
    }

    // ─── Animación ────────────────────────────────────────────────────────────

    iniciar() {
        if (this.corriendo || this.quieto) return
        this.corriendo = true
        this.ultimo = performance.now()
        requestAnimationFrame(this.cuadro)
    }

    detener() {
        this.corriendo = false
    }

    cuadro(ahora) {
        if (! this.corriendo) return
        const dt = Math.min(0.1, (ahora - this.ultimo) / 1000)

        // ~15 cuadros por segundo: más no se nota en pixel art y gasta batería.
        if (dt >= 1 / 15) {
            this.ultimo = ahora
            this.t += dt
            this.mover(dt)
            this.dibujar()
        }
        requestAnimationFrame(this.cuadro)
    }

    mover(dt) {
        const velocidad = 2.2 // baldosas por segundo
        for (const p of this.personajes.values()) {
            if (! p.ruta?.length) { p.caminando = false; continue }
            const sig = p.ruta[0]
            const dx = sig.x - p.x, dy = sig.y - p.y
            const dist = Math.hypot(dx, dy)
            const paso = velocidad * dt
            p.caminando = true
            p.mira = Math.abs(dx) > Math.abs(dy) ? (dx > 0 ? 'derecha' : 'izquierda') : (dy > 0 ? 'frente' : 'espalda')
            if (dist <= paso) { p.x = sig.x; p.y = sig.y; p.ruta.shift() }
            else { p.x += dx / dist * paso; p.y += dy / dist * paso }
            if (! p.ruta.length) p.alLlegar?.()
        }
    }

    /** Manda un personaje a un sitio: camina por el piso, o aparece ahí si es la primera vez. */
    llevar(p, destino, aparecer = false) {
        if (p.destino && p.destino.x === destino.x && p.destino.y === destino.y) return
        const primera = p.destino === null || p.destino === undefined
        p.destino = destino
        if (aparecer || primera || this.quieto) {
            p.x = destino.x
            p.y = destino.y
            p.ruta = []
        } else {
            p.ruta = camino(this.plano, p, destino)
        }
    }

    // ─── El cuadro ────────────────────────────────────────────────────────────

    dibujar() {
        if (! this.bw) return
        this.bctx.drawImage(this.fondo, 0, 0)
        this.componer(this.bctx)

        const ctx = this.ctx
        ctx.imageSmoothingEnabled = false
        ctx.drawImage(this.buf, 0, 0, this.lienzo.width, this.lienzo.height)

        this.ocupados = []
        this.cajas = []
        this.textos()
    }

    /** Ordena de atrás hacia adelante y pinta: cada cosa trae su profundidad y su función. */
    pintarOrdenado(cosas) {
        cosas.sort((a, c) => a.prof - c.prof)
        for (const c of cosas) c.pintar()
    }

    /** Una caja isométrica con sus tres caras visibles, sombreadas. */
    caja(m, color = m.color, b = this.bctx) {
        const { x, y, z, w, d, h } = m
        poligono(b, [this.iso(x, y + d, z), this.iso(x + w, y + d, z), this.iso(x + w, y + d, z + h), this.iso(x, y + d, z + h)], tono(color, 0.84))
        poligono(b, [this.iso(x + w, y, z), this.iso(x + w, y + d, z), this.iso(x + w, y + d, z + h), this.iso(x + w, y, z + h)], tono(color, 0.68))
        poligono(b, [this.iso(x, y, z + h), this.iso(x + w, y, z + h), this.iso(x + w, y + d, z + h), this.iso(x, y + d, z + h)], color)
        // Un filo de luz en la arista de arriba: le da volumen sin costo
        if (w > 0.3 && d > 0.3 && h > 2) {
            poligono(b, [this.iso(x, y + d, z + h), this.iso(x + w, y + d, z + h), this.iso(x + w, y + d, z + h - 0.7), this.iso(x, y + d, z + h - 0.7)], tono(color, 1.12))
        }
    }

    /** Pinta un rectángulo en la cara de enfrente (la de y + d) de una caja. */
    caraFrente(m, x0, x1, z0, z1, color, b = this.bctx) {
        const yy = m.y + m.d + 0.002
        poligono(b, [this.iso(m.x + x0, yy, m.z + z0), this.iso(m.x + x1, yy, m.z + z0), this.iso(m.x + x1, yy, m.z + z1), this.iso(m.x + x0, yy, m.z + z1)], color)
    }

    /** Un pintor de rectángulos relativo a un punto de la pantalla (el ancla de un personaje). */
    pintor(sx, sy, b = this.bctx) {
        return (x, y, w, h, c) => { b.fillStyle = c; b.fillRect(sx + x, sy + y, w, h) }
    }

    /** Una plantita: maceta y hojas en capas. */
    planta(x, y, alto = 1) {
        const b = this.bctx
        this.caja({ x, y, z: 0, w: 0.55, d: 0.55, h: 9 }, C.maceta)
        const [sx, sy] = this.iso(x + 0.27, y + 0.27, 9)
        const r = this.pintor(sx, sy, b)
        const f = this.quieto ? 0 : Math.round(Math.sin(this.t * 1.5 + x) * 1)
        for (let k = 0; k < 4 + alto * 2; k++) {
            const ancho = 14 - k * 2
            r(-ancho / 2 + (k % 2 ? f : 0), -4 - k * 4, ancho, 5, k % 2 ? C.plantaClara : C.planta)
        }
        r(-1, -2, 2, 3, '#3f6212')
    }

    /** Puntos de luz que viajan de un punto a otro: la guía «hablando» con un agente. */
    rayo(a, b, color = '#67e8f9') {
        if (this.quieto) return
        const bx = this.bctx
        const dx = b[0] - a[0], dy = b[1] - a[1]
        const largo = Math.hypot(dx, dy)
        const pasos = Math.floor(largo / 9)
        const fase = (this.t * 30) % 9
        bx.fillStyle = color
        for (let k = 0; k <= pasos; k++) {
            const d = (k * 9 + fase) / largo
            if (d > 1) break
            bx.globalAlpha = 0.25 + 0.6 * Math.sin(d * Math.PI)
            bx.fillRect(Math.round(a[0] + dx * d), Math.round(a[1] + dy * d), 2, 2)
        }
        bx.globalAlpha = 1
    }

    // ─── Textos a resolución real ────────────────────────────────────────────

    fuente(px, peso = 700) {
        return `${peso} ${Math.round(px * this.dpr)}px ui-monospace, SFMono-Regular, Menlo, Consolas, monospace`
    }

    /** La y más cercana, hacia arriba, donde un rótulo no tapa a otro ya puesto. */
    sitioLibre(x0, y0, ancho, alto) {
        const choca = (y) => this.ocupados.filter(o => x0 < o.x1 && x0 + ancho > o.x0 && y < o.y1 && y + alto > o.y0)
        let y = y0
        for (let i = 0; i < 6; i++) {
            const c = choca(y)
            if (! c.length) break
            y = Math.min(...c.map(o => o.y0)) - alto - 2 * this.dpr
        }
        this.ocupados.push({ x0, y0: y, x1: x0 + ancho, y1: y + alto })
        return y
    }

    /** Un letrero centrado en x, con su base en y. Devuelve la y de su borde de arriba. */
    letrero(x, y, texto, { fuente, fondo, texto: colorTexto, borde, esquinas = false, libre = true }) {
        const ctx = this.ctx
        const k = this.dpr
        ctx.font = fuente
        const ancho = ctx.measureText(texto).width + 12 * k
        const alto = parseInt(fuente.match(/(\d+)px/)[1], 10) + 8 * k
        const x0 = Math.round(x - ancho / 2)
        const y0 = libre ? this.sitioLibre(x0, Math.round(y - alto), ancho, alto) : Math.round(y - alto)

        ctx.fillStyle = fondo
        ctx.fillRect(x0, y0, ancho, alto)
        ctx.strokeStyle = borde
        ctx.lineWidth = Math.max(1, k)
        ctx.strokeRect(x0 + 0.5, y0 + 0.5, ancho - 1, alto - 1)

        if (esquinas) {
            const l = 5 * k
            ctx.lineWidth = 2 * k
            ctx.beginPath()
            for (const [cx, cy, dx, dy] of [[x0 - 3 * k, y0 - 3 * k, 1, 1], [x0 + ancho + 3 * k, y0 - 3 * k, -1, 1], [x0 - 3 * k, y0 + alto + 3 * k, 1, -1], [x0 + ancho + 3 * k, y0 + alto + 3 * k, -1, -1]]) {
                ctx.moveTo(cx, cy + dy * l); ctx.lineTo(cx, cy); ctx.lineTo(cx + dx * l, cy)
            }
            ctx.stroke()
        }

        ctx.fillStyle = colorTexto
        ctx.textBaseline = 'middle'
        ctx.textAlign = 'center'
        ctx.fillText(texto, x, y0 + alto / 2 + k)
        return y0
    }

    /** El globo blanco de lo que alguien está diciendo o haciendo, con su piquito. */
    globo(x, y, texto, fuente = this.fuente(10, 600)) {
        const ctx = this.ctx
        const k = this.dpr
        ctx.font = fuente
        const ancho = ctx.measureText(texto).width + 14 * k
        const alto = parseInt(fuente.match(/(\d+)px/)[1], 10) + 10 * k
        const x0 = Math.round(x - ancho / 2)
        const y0 = this.sitioLibre(x0, Math.round(y - alto - 5 * k), ancho, alto + 5 * k)

        ctx.fillStyle = '#f8fafc'
        ctx.strokeStyle = '#334155'
        ctx.lineWidth = Math.max(1, k)
        ctx.beginPath()
        ctx.roundRect ? ctx.roundRect(x0, y0, ancho, alto, 5 * k) : ctx.rect(x0, y0, ancho, alto)
        ctx.fill()
        ctx.stroke()
        ctx.beginPath()
        ctx.moveTo(x - 4 * k, y0 + alto)
        ctx.lineTo(x, y0 + alto + 5 * k)
        ctx.lineTo(x + 4 * k, y0 + alto)
        ctx.fill()

        ctx.fillStyle = '#0f172a'
        ctx.textBaseline = 'middle'
        ctx.textAlign = 'center'
        ctx.fillText(texto, x, y0 + alto / 2 + k)
        return y0
    }

    /** Registra dónde se puede tocar algo (en coordenadas del búfer). */
    tocable(id, tipo, x0, y0, x1, y1) {
        this.cajas.push({ id, tipo, x0, y0, x1, y1 })
    }

    /** Lo que hay en ese punto de la pantalla: { id, tipo } o null. El que está más adelante gana. */
    queHayEn(clienteX, clienteY) {
        const r = this.lienzo.getBoundingClientRect()
        const bx = (clienteX - r.left) * (this.bw / r.width)
        const by = (clienteY - r.top) * (this.bh / r.height)
        const candidatos = this.cajas.filter(c => bx >= c.x0 && bx <= c.x1 && by >= c.y0 && by <= c.y1)
        candidatos.sort((a, c) => c.y1 - a.y1)
        return candidatos[0] ? { id: candidatos[0].id, tipo: candidatos[0].tipo } : null
    }

    // ─── Lo que pone cada escena ─────────────────────────────────────────────

    pintarFondo() {}
    componer() {}
    textos() {}

    // ─── Piezas comunes del fondo ────────────────────────────────────────────

    /** Paredes del fondo (con zócalo y moldura) y piso de baldosas con juntas. */
    pintarParedesYPiso(ctx, colorBaldosa) {
        const { W, H } = this.plano
        const pared = (pts, color) => poligono(ctx, pts, color)

        pared([this.iso(0, 0, ALTO_PARED), this.iso(W, 0, ALTO_PARED), this.iso(W, 0), this.iso(0, 0)], C.pared)
        pared([this.iso(0, 0, ALTO_PARED), this.iso(0, H, ALTO_PARED), this.iso(0, H), this.iso(0, 0)], C.paredOscura)
        pared([this.iso(0, 0, ALTO_PARED), this.iso(W, 0, ALTO_PARED), this.iso(W, 0, ALTO_PARED - 2), this.iso(0, 0, ALTO_PARED - 2)], C.molduraPared)
        pared([this.iso(0, 0, ALTO_PARED), this.iso(0, H, ALTO_PARED), this.iso(0, H, ALTO_PARED - 2), this.iso(0, 0, ALTO_PARED - 2)], tono(C.molduraPared, 0.9))
        pared([this.iso(0, 0, 4), this.iso(W, 0, 4), this.iso(W, 0), this.iso(0, 0)], C.zocalo)
        pared([this.iso(0, 0, 4), this.iso(0, H, 4), this.iso(0, H), this.iso(0, 0)], tono(C.zocalo, 0.85))

        for (let gx = 0; gx < W; gx++) {
            for (let gy = 0; gy < H; gy++) {
                const color = colorBaldosa(gx, gy)
                poligono(ctx, [this.iso(gx, gy), this.iso(gx + 1, gy), this.iso(gx + 1, gy + 1), this.iso(gx, gy + 1)], color)
                // Junta fina en dos bordes de cada baldosa
                poligono(ctx, [this.iso(gx, gy), this.iso(gx + 1, gy), this.iso(gx + 1, gy + 0.04), this.iso(gx, gy + 0.04)], tono(color, 0.9))
                poligono(ctx, [this.iso(gx, gy), this.iso(gx + 0.03, gy), this.iso(gx + 0.03, gy + 1), this.iso(gx, gy + 1)], tono(color, 0.93))
            }
        }
    }

    /** Una ventana en la pared del fondo (la de y = 0), con su marco y un reflejo. */
    ventana(ctx, x, ancho = 2) {
        const p = (pts, c) => poligono(ctx, pts, c)
        p([this.iso(x, 0, 46), this.iso(x + ancho, 0, 46), this.iso(x + ancho, 0, 22), this.iso(x, 0, 22)], C.marcoVentana)
        p([this.iso(x + 0.12, 0, 44), this.iso(x + ancho - 0.12, 0, 44), this.iso(x + ancho - 0.12, 0, 24), this.iso(x + 0.12, 0, 24)], C.ventana)
        p([this.iso(x + ancho / 2 - 0.04, 0, 44), this.iso(x + ancho / 2 + 0.04, 0, 44), this.iso(x + ancho / 2 + 0.04, 0, 24), this.iso(x + ancho / 2 - 0.04, 0, 24)], C.marcoVentana)
        p([this.iso(x + 0.3, 0, 42), this.iso(x + 0.5, 0, 42), this.iso(x + 0.35, 0, 30), this.iso(x + 0.15, 0, 30)], C.ventanaBrillo)
        // La luz que entra al piso
        ctx.globalAlpha = 0.08
        p([this.iso(x, 0), this.iso(x + ancho, 0), this.iso(x + ancho + 0.5, 2.2), this.iso(x + 0.5, 2.2)], '#ffffff')
        ctx.globalAlpha = 1
    }
}
