/**
 * La oficina del Equipo de IA en pixel art isométrico, dibujada en código.
 *
 * No hay imágenes ni librerías de juegos: todo se pinta con rectángulos de un píxel sobre un
 * lienzo pequeño (el «búfer») que después se amplía sin suavizar, que es lo que da el aspecto de
 * pixel art. Por eso pesa casi nada, va igual en cada instalación y se acomoda solo al número de
 * agentes: el plano se arma con un cubículo por agente.
 *
 * Los textos —nombres, letreros, globos, el nombre de la empresa— se escriben en una segunda
 * pasada a la resolución real de la pantalla, para que se lean nítidos.
 *
 * Quién está dónde lo decide el estado que manda el servidor:
 *   trabajando → en su escritorio, tecleando, con código en la pantalla
 *   libre      → en su escritorio, con el café humeando
 *   aprobacion → camina a la oficina del director y espera con un papel en la mano
 *   error      → de pie junto al servidor, que echa humo
 *   terminado  → en la zona de café
 *   apagado    → no está: silla vacía y monitor apagado
 *
 * Los personajes van de un sitio a otro por el piso (búsqueda A* sobre la cuadrícula), sin
 * atravesar escritorios ni paredes.
 */

const TW = 22   // ancho de una baldosa en píxeles del búfer
const TH = 11   // alto de una baldosa
const ALTO_PARED = 54
// Las alturas de los muebles se escriben en «píxeles de mueble» y se encogen con este factor; los
// personajes no. Así los personajes ganan protagonismo, como en un juego, sin redibujar nada.
const Z = 0.72

// ─── Colores ────────────────────────────────────────────────────────────────────

const C = {
    fondo: '#0b1220',
    piso1: '#d9cdb4', piso2: '#cfc2a7',
    pasillo1: '#9fb3c8', pasillo2: '#94a9bf',
    alfombra1: '#5a5f9e', alfombra2: '#53588f',
    pared: '#8fb1c4', paredOscura: '#6f93a8', zocalo: '#4c6b7d',
    ventana: '#cfeaf7', marcoVentana: '#5d7f92',
    panel: '#7f9fb0', panelBorde: '#5f7e8f',
    madera: '#c8874e', maderaOscura: '#9a5f31', maderaDirector: '#7a3f22',
    metal: '#9aa5b1', metalOscuro: '#5b6672',
    monitor: '#20262e', teclado: '#3b4350',
    silla: '#2c4a6e', sillaDirector: '#2a2a33',
    vidrio: '#a9d3e3',
    planta: '#2f8f4e', maceta: '#a0522d',
    servidor: '#1f2937',
    pantalla: {
        trabajando: '#0f2a1a', libre: '#1e3a8a', aprobacion: '#7c2d12',
        error: '#7f1d1d', terminado: '#065f46', apagado: '#0b0f14',
    },
}

const PELO = ['#2b1d14', '#4b3621', '#1f1f1f', '#7a4b21', '#c58b3c', '#5a2e1b']
const PIEL = ['#f2c9a0', '#e0ac80', '#c68642', '#8d5524', '#f5d0b5']
const CAMISA = {
    azul: '#3b82f6', ambar: '#f59e0b', verde: '#10b981', rojo: '#ef4444',
    violeta: '#8b5cf6', naranja: '#f97316',
}

function tono(hex, f) {
    const n = parseInt(hex.slice(1), 16)
    const c = (v) => Math.max(0, Math.min(255, Math.round(v * f)))
    return '#' + [(n >> 16) & 255, (n >> 8) & 255, n & 255].map(v => c(v).toString(16).padStart(2, '0')).join('')
}

// ─── El plano ───────────────────────────────────────────────────────────────────

/**
 * Arma el plano para `n` agentes: la oficina del director a la izquierda, los cubículos en
 * filas con un pasillo debajo de cada una, y a la derecha el servidor y la zona de café.
 */
function armarPlano(n) {
    const cols = n <= 2 ? 2 : n <= 6 ? 3 : 4
    const filas = Math.max(1, Math.ceil(n / cols))
    const X0 = 6
    const W = X0 + cols * 4 + 4
    const H = Math.max(10, 1 + filas * 4 + 2)

    const puestos = Array.from({ length: n }, (_, i) => {
        const cx = X0 + (i % cols) * 4
        const cy = 1 + Math.floor(i / cols) * 4
        return { cx, cy, asiento: { x: cx + 1.5, y: cy + 1.6 } }
    })

    // Muebles: lo que bloquea el paso y se dibuja. Cada uno es una caja.
    const muebles = []
    const caja = (x, y, z, w, d, h, color, extra = {}) => muebles.push({ x, y, z, w, d, h, color, ...extra })

    // Oficina del director
    caja(0.15, 0.6, 0, 0.5, 2.4, 34, C.madera, { tipo: 'estante' })
    caja(0.8, 3.0, 0, 2.8, 1.0, 13, C.maderaDirector, { tipo: 'escritorio' })
    caja(1.7, 1.75, 6, 0.9, 0.7, 3, C.sillaDirector, { tipo: 'asiento' })
    caja(1.7, 1.55, 6, 0.9, 0.2, 18, C.sillaDirector, { tipo: 'respaldo' })
    caja(1.0, 3.25, 13, 0.8, 0.12, 9, C.monitor, { tipo: 'monitor', estado: 'director' })
    caja(1.0, 4.8, 0, 0.7, 0.6, 7, C.silla, { tipo: 'silla' })
    caja(2.9, 4.8, 0, 0.7, 0.6, 7, C.silla, { tipo: 'silla' })
    caja(4.1, 0.5, 0, 0.7, 0.7, 10, C.maceta, { tipo: 'maceta' })
    caja(4.2, 0.6, 10, 0.5, 0.5, 14, C.planta, { tipo: 'planta' })

    // Pared de vidrio de la oficina, con la puerta entre y = 3 y y = 5
    caja(X0 - 1, 0, 0, 0.12, 3, 26, C.vidrio, { tipo: 'vidrio' })
    caja(X0 - 1, 5, 0, 0.12, H - 5, 26, C.vidrio, { tipo: 'vidrio' })

    // Cubículos
    puestos.forEach((p, i) => {
        const { cx, cy } = p
        caja(cx, cy, 0, 3, 0.15, 24, C.panel, { tipo: 'panel' })
        caja(cx, cy + 0.15, 0, 0.15, 2.5, 24, C.panel, { tipo: 'panel' })
        caja(cx + 0.25, cy + 0.2, 0, 2.6, 0.95, 11, C.madera, { tipo: 'escritorio' })
        caja(cx + 2.15, cy + 0.25, 0, 0.65, 0.85, 10, C.maderaOscura, { tipo: 'cajonera' })
        caja(cx + 0.7, cy + 0.35, 11, 0.85, 0.12, 10, C.monitor, { tipo: 'monitor', agente: i })
        caja(cx + 1.65, cy + 0.35, 11, 0.85, 0.12, 10, C.monitor, { tipo: 'monitor', agente: i })
        caja(cx + 1.0, cy + 0.8, 11, 0.9, 0.22, 1, C.teclado, { tipo: 'teclado' })
        caja(cx + 2.35, cy + 0.6, 11, 0.25, 0.25, 4, '#f5f5f4', { tipo: 'taza', agente: i })
        caja(cx + 1.2, cy + 1.4, 5, 0.65, 0.6, 2, C.silla, { tipo: 'asiento' })
        caja(cx + 1.2, cy + 2.0, 5, 0.65, 0.1, 10, C.silla, { tipo: 'respaldo' })
    })

    // Archivadores contra la pared del fondo, entre el último cubículo y el servidor
    const xa = X0 + cols * 4
    caja(xa + 0.1, 0.15, 0, 0.8, 0.6, 22, C.metal, { tipo: 'archivador' })

    // Servidor y zona de café a la derecha
    caja(W - 2.0, 0.4, 0, 1.0, 0.8, 38, C.servidor, { tipo: 'servidor' })
    caja(W - 2.8, H - 2.6, 0, 1.4, 0.9, 10, C.madera, { tipo: 'mesa' })
    caja(W - 1.1, H - 3.6, 0, 0.8, 0.6, 16, C.metalOscuro, { tipo: 'cafetera' })
    caja(W - 3.6, H - 1.2, 0, 0.6, 0.6, 9, C.maceta, { tipo: 'maceta' })
    caja(W - 3.5, H - 1.1, 9, 0.4, 0.4, 13, C.planta, { tipo: 'planta' })

    // Los sitios a los que se va según el estado
    const sitios = {
        director: [{ x: 1.7, y: 5.9 }, { x: 3.3, y: 5.9 }, { x: 4.4, y: 4.6 }, { x: 4.4, y: 6.4 }, { x: 0.8, y: 6.6 }, { x: 2.5, y: 7.2 }],
        servidor: [{ x: W - 1.5, y: 2.0 }, { x: W - 2.5, y: 2.0 }, { x: W - 3.5, y: 1.7 }, { x: W - 1.5, y: 3.0 }],
        cafe: [{ x: W - 3.4, y: H - 2.0 }, { x: W - 1.0, y: H - 2.0 }, { x: W - 2.0, y: H - 1.3 }, { x: W - 2.2, y: H - 3.2 }, { x: W - 3.3, y: H - 3.1 }],
    }

    // La cuadrícula de lo que se puede pisar: una baldosa está ocupada si un mueble la toca.
    const libre = Array.from({ length: W }, () => Array(H).fill(true))
    for (const m of muebles) {
        if (['monitor', 'teclado', 'taza', 'asiento', 'respaldo', 'planta'].includes(m.tipo)) continue
        for (let gx = Math.floor(m.x); gx < Math.ceil(m.x + m.w); gx++) {
            for (let gy = Math.floor(m.y); gy < Math.ceil(m.y + m.d); gy++) {
                if (gx >= 0 && gy >= 0 && gx < W && gy < H) libre[gx][gy] = false
            }
        }
    }
    // El asiento de cada cubículo y la baldosa de enfrente sí se pisan.
    for (const p of puestos) {
        libre[p.cx + 1][p.cy + 1] = true
        libre[p.cx + 1][p.cy + 2] = true
    }
    libre[2][2] = true

    const pasillos = new Set()
    for (let f = 0; f < filas; f++) pasillos.add(1 + f * 4 + 3)

    return { W, H, X0, cols, filas, puestos, muebles, sitios, libre, pasillos }
}

// ─── Buscar el camino (A* sobre baldosas) ──────────────────────────────────────

function camino(plano, desde, hasta) {
    const { W, H, libre } = plano
    const a = [Math.floor(desde.x), Math.floor(desde.y)]
    const b = [Math.floor(hasta.x), Math.floor(hasta.y)]
    const clave = (x, y) => x * 1000 + y
    const ok = (x, y) => x >= 0 && y >= 0 && x < W && y < H && (libre[x][y] || (x === b[0] && y === b[1]) || (x === a[0] && y === a[1]))

    const abiertos = [[a[0], a[1]]]
    const g = new Map([[clave(...a), 0]])
    const de = new Map()
    const h = (x, y) => Math.abs(x - b[0]) + Math.abs(y - b[1])

    while (abiertos.length) {
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
            ruta.shift() // la primera baldosa es donde ya está
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

    return [{ x: hasta.x, y: hasta.y }] // sin camino: llega directo antes que quedarse pegado
}

// ─── Dibujar ─────────────────────────────────────────────────────────────────────

/** Rellena un polígono fila por fila con rectángulos de un píxel: bordes nítidos, sin suavizado. */
function poligono(ctx, pts, color) {
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

export class OficinaPixel {
    /**
     * @param {HTMLCanvasElement} lienzo
     * @param {{ quieto?: boolean }} opciones  quieto = «reducir movimiento»: sin caminar ni animar
     */
    constructor(lienzo, opciones = {}) {
        this.lienzo = lienzo
        this.ctx = lienzo.getContext('2d')
        this.buf = document.createElement('canvas')
        this.bctx = this.buf.getContext('2d')
        this.fondo = document.createElement('canvas')
        this.quieto = !! opciones.quieto
        this.escala = 2
        this.dpr = 1
        this.agentes = []
        this.personajes = new Map()
        this.empresa = ''
        this.director = ''
        this.elegido = null
        this.cajas = []
        this.t = 0
        this.ultimo = 0
        this.corriendo = false
        this.cuadro = this.cuadro.bind(this)
        this.plano = armarPlano(0)
        this.prepararBufer()
    }

    // ─── Datos ────────────────────────────────────────────────────────────────

    datos({ agentes, empresa, director, elegido }) {
        const cambioCantidad = agentes.length !== this.agentes.length
        this.agentes = agentes
        this.empresa = empresa || ''
        this.director = director || ''
        this.elegido = elegido ?? null

        if (cambioCantidad || ! this.plano.puestos.length) {
            this.plano = armarPlano(agentes.length)
            this.personajes.clear()
            this.prepararBufer()
        }

        agentes.forEach((a, i) => this.ubicar(a, i))
        this.dibujar()
    }

    elegir(id) {
        this.elegido = id
        this.dibujar()
    }

    /** Decide a dónde va cada agente según su estado, y le arma el camino. */
    ubicar(a, i) {
        const puesto = this.plano.puestos[i]
        let p = this.personajes.get(a.id)

        if (! p) {
            p = {
                id: a.id, i, x: puesto.asiento.x, y: puesto.asiento.y, ruta: [], destino: null,
                pelo: PELO[(a.id * 7) % PELO.length], piel: PIEL[(a.id * 3) % PIEL.length],
                camisa: CAMISA[a.color] ?? '#3b82f6', fase: (a.id * 13) % 10,
            }
            this.personajes.set(a.id, p)
        }

        p.i = i
        p.estado = a.estado
        p.camisa = CAMISA[a.color] ?? p.camisa

        const turno = this.agentes.filter((o, k) => k < i && o.estado === a.estado).length
        const s = this.plano.sitios
        const destino = {
            aprobacion: s.director[turno % s.director.length],
            error: s.servidor[turno % s.servidor.length],
            terminado: s.cafe[turno % s.cafe.length],
        }[a.estado] ?? puesto.asiento

        const mismo = p.destino && p.destino.x === destino.x && p.destino.y === destino.y
        if (mismo) return

        const primeraVez = p.destino === null
        p.destino = destino

        if (primeraVez || this.quieto) {
            p.x = destino.x
            p.y = destino.y
            p.ruta = []
        } else {
            p.ruta = camino(this.plano, p, destino)
        }
    }

    // ─── Tamaño ───────────────────────────────────────────────────────────────

    /** El tamaño del búfer sale del plano: lo que ocupan el piso y las paredes en proyección. */
    prepararBufer() {
        const { W, H } = this.plano
        this.ox = H * (TW / 2) + 8
        this.oy = Math.round(ALTO_PARED * Z) + 30
        this.bw = Math.ceil((W + H) * (TW / 2) + 16)
        this.bh = Math.ceil((W + H) * (TH / 2) + this.oy + 12)
        this.buf.width = this.bw
        this.buf.height = this.bh
        this.fondo.width = this.bw
        this.fondo.height = this.bh
        this.pintarFondo()
        this.ajustar()
    }

    /**
     * La escena cabe a lo ancho del contenedor. La escala puede ser fraccionaria: con el
     * ampliado sin suavizar el pixel art se sigue viendo nítido, y obligar a una escala entera
     * dejaba media oficina por fuera en un portátil. En el celular no baja de 1: ahí el
     * contenedor se desplaza de lado.
     */
    redimensionar(anchoCss, dpr = 1) {
        this.anchoCss = anchoCss
        this.dpr = dpr
        this.ajustar()
    }

    ajustar() {
        if (! this.anchoCss) return
        const css = Math.max(1, this.anchoCss / this.bw)
        this.escala = css * this.dpr
        this.lienzo.width = Math.round(this.bw * this.escala)
        this.lienzo.height = Math.round(this.bh * this.escala)
        this.lienzo.style.width = (this.lienzo.width / this.dpr) + 'px'
        this.lienzo.style.height = (this.lienzo.height / this.dpr) + 'px'
        this.dibujar()
    }

    iso(gx, gy, z = 0) {
        return [Math.round((gx - gy) * (TW / 2) + this.ox), Math.round((gx + gy) * (TH / 2) + this.oy - z * Z)]
    }

    // ─── El fondo: piso y paredes, que no se mueven ──────────────────────────

    pintarFondo() {
        const ctx = this.fondo.getContext('2d')
        const { W, H, X0, pasillos } = this.plano
        ctx.fillStyle = C.fondo
        ctx.fillRect(0, 0, this.bw, this.bh)

        // Paredes del fondo: la de la derecha (a lo largo de x) y la de la izquierda (a lo largo de y)
        const pared = (pts, color) => poligono(ctx, pts, color)
        pared([this.iso(0, 0, ALTO_PARED), this.iso(W, 0, ALTO_PARED), this.iso(W, 0), this.iso(0, 0)], C.pared)
        pared([this.iso(0, 0, ALTO_PARED), this.iso(0, H, ALTO_PARED), this.iso(0, H), this.iso(0, 0)], C.paredOscura)
        pared([this.iso(0, 0, 5), this.iso(W, 0, 5), this.iso(W, 0), this.iso(0, 0)], C.zocalo)
        pared([this.iso(0, 0, 5), this.iso(0, H, 5), this.iso(0, H), this.iso(0, 0)], tono(C.zocalo, 0.85))

        // Ventanas en la pared del fondo
        for (let x = X0 + 1; x < W - 3; x += 4) {
            pared([this.iso(x, 0, 44), this.iso(x + 2, 0, 44), this.iso(x + 2, 0, 24), this.iso(x, 0, 24)], C.marcoVentana)
            pared([this.iso(x + 0.15, 0, 42), this.iso(x + 1.85, 0, 42), this.iso(x + 1.85, 0, 26), this.iso(x + 0.15, 0, 26)], C.ventana)
        }
        // Un cuadro en la oficina del director
        pared([this.iso(0, 3.2, 44), this.iso(0, 5.2, 44), this.iso(0, 5.2, 28), this.iso(0, 3.2, 28)], '#e8e2d0')
        pared([this.iso(0, 3.5, 41), this.iso(0, 4.9, 41), this.iso(0, 4.9, 31), this.iso(0, 3.5, 31)], '#7aa874')

        // Piso: baldosas, el pasillo y la alfombra del director
        for (let gx = 0; gx < W; gx++) {
            for (let gy = 0; gy < H; gy++) {
                const par = (gx + gy) % 2 === 0
                let color = par ? C.piso1 : C.piso2
                if (gx < X0 - 1 && gy >= 1 && gy < H - 1 && gx >= 0.5) color = par ? C.alfombra1 : C.alfombra2
                else if (gx >= X0 - 1 && pasillos.has(gy)) color = par ? C.pasillo1 : C.pasillo2
                poligono(ctx, [this.iso(gx, gy), this.iso(gx + 1, gy), this.iso(gx + 1, gy + 1), this.iso(gx, gy + 1)], color)
            }
        }
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

        // Pixel art a ~12 cuadros por segundo: más rápido no se nota y gasta batería.
        if (dt >= 1 / 12) {
            this.ultimo = ahora
            this.t += dt
            this.mover(dt)
            this.dibujar()
        }
        requestAnimationFrame(this.cuadro)
    }

    mover(dt) {
        const velocidad = 2.4 // baldosas por segundo
        for (const p of this.personajes.values()) {
            if (! p.ruta.length) { p.caminando = false; continue }
            const sig = p.ruta[0]
            const dx = sig.x - p.x, dy = sig.y - p.y
            const dist = Math.hypot(dx, dy)
            const paso = velocidad * dt
            p.caminando = true
            if (dist <= paso) { p.x = sig.x; p.y = sig.y; p.ruta.shift() }
            else { p.x += dx / dist * paso; p.y += dy / dist * paso }
        }
    }

    // ─── El cuadro completo ──────────────────────────────────────────────────

    dibujar() {
        if (! this.bw) return
        const b = this.bctx
        b.drawImage(this.fondo, 0, 0)

        // Muebles y personajes, de atrás hacia adelante
        const cosas = this.plano.muebles.map(m => ({ prof: m.x + m.w / 2 + m.y + m.d / 2 + (m.tipo === 'panel' || m.tipo === 'vidrio' ? -0.6 : 0), m }))
        const visibles = []
        this.agentes.forEach((a) => {
            const p = this.personajes.get(a.id)
            if (! p || a.estado === 'apagado') return
            visibles.push(p)
            cosas.push({ prof: p.x + p.y + 0.05, p })
        })
        // El director
        cosas.push({ prof: 2.15 + 2.35, director: true })

        cosas.sort((a, c) => a.prof - c.prof)
        for (const c of cosas) {
            if (c.m) this.mueble(c.m)
            else if (c.p) this.personaje(c.p)
            else this.personajeDirector()
        }

        this.efectos()

        // Ampliar sin suavizar
        const ctx = this.ctx
        ctx.imageSmoothingEnabled = false
        ctx.drawImage(this.buf, 0, 0, this.lienzo.width, this.lienzo.height)

        this.textos(visibles)
    }

    caja(m, color = m.color) {
        const { x, y, z, w, d, h } = m
        const b = this.bctx
        poligono(b, [this.iso(x, y + d, z), this.iso(x + w, y + d, z), this.iso(x + w, y + d, z + h), this.iso(x, y + d, z + h)], tono(color, 0.82))
        poligono(b, [this.iso(x + w, y, z), this.iso(x + w, y + d, z), this.iso(x + w, y + d, z + h), this.iso(x + w, y, z + h)], tono(color, 0.66))
        poligono(b, [this.iso(x, y, z + h), this.iso(x + w, y, z + h), this.iso(x + w, y + d, z + h), this.iso(x, y + d, z + h)], color)
    }

    mueble(m) {
        const b = this.bctx

        if (m.tipo === 'monitor') {
            this.caja(m)
            // La pantalla en la cara de enfrente
            const estado = m.estado === 'director' ? 'libre' : this.agentes[m.agente]?.estado ?? 'apagado'
            const { x, y, z, w, d, h } = m
            const yy = y + d + 0.001
            poligono(b, [this.iso(x + 0.08, yy, z + 1.5), this.iso(x + w - 0.08, yy, z + 1.5), this.iso(x + w - 0.08, yy, z + h - 1.5), this.iso(x + 0.08, yy, z + h - 1.5)], C.pantalla[estado] ?? C.pantalla.apagado)

            if (estado === 'trabajando') {
                // Código que corre: líneas verdes que cambian con el tiempo
                const paso = Math.floor(this.t * 6) + m.agente * 5 + Math.round(x * 3)
                for (let k = 0; k < 3; k++) {
                    const largo = 0.2 + ((paso + k * 7) % 5) / 10
                    const zz = z + h - 3 - k * 2
                    poligono(b, [this.iso(x + 0.15, yy, zz), this.iso(x + 0.15 + largo, yy, zz), this.iso(x + 0.15 + largo, yy, zz - 1), this.iso(x + 0.15, yy, zz - 1)], '#4ade80')
                }
            } else if (estado === 'aprobacion' || estado === 'error') {
                const [sx, sy] = this.iso(x + w / 2, yy, z + h / 2)
                b.fillStyle = estado === 'error' ? '#fecaca' : '#fed7aa'
                if (Math.floor(this.t * 2) % 2 === 0) b.fillRect(sx - 1, sy - 2, 2, 3)
            }
            return
        }

        if (m.tipo === 'servidor') {
            this.caja(m)
            const hayError = this.agentes.some(a => a.estado === 'error')
            // Lucecitas en la cara de enfrente
            for (let k = 0; k < 6; k++) {
                const [sx, sy] = this.iso(m.x + 0.2 + (k % 3) * 0.3, m.y + m.d, 8 + Math.floor(k / 3) * 12)
                const prendida = (Math.floor(this.t * 4) + k) % 3 !== 0
                b.fillStyle = hayError ? (prendida ? '#ef4444' : '#7f1d1d') : (prendida ? '#22c55e' : '#14532d')
                b.fillRect(sx, sy, 2, 1)
            }
            return
        }

        if (m.tipo === 'taza') {
            this.caja(m)
            const estado = this.agentes[m.agente]?.estado
            if (estado === 'libre' && ! this.quieto) {
                const [sx, sy] = this.iso(m.x + m.w / 2, m.y + m.d / 2, m.z + m.h + 2)
                const f = Math.floor(this.t * 3) % 3
                b.fillStyle = 'rgba(255,255,255,0.7)'
                b.fillRect(sx - 1 + (f === 1 ? 1 : 0), sy - 2 - f, 1, 2)
                b.fillRect(sx + 1 - (f === 2 ? 1 : 0), sy - 4 - f, 1, 2)
            }
            return
        }

        if (m.tipo === 'vidrio') {
            // Vidrio: solo los bordes y un reflejo, para ver a través
            const { x, y, z, w, d, h } = m
            b.globalAlpha = 0.16
            this.caja(m)
            b.globalAlpha = 1
            // Marco: el borde de arriba, el zócalo y un parante cada dos baldosas
            poligono(b, [this.iso(x + w, y, z + h), this.iso(x + w, y + d, z + h), this.iso(x + w, y + d, z + h - 2), this.iso(x + w, y, z + h - 2)], C.panelBorde)
            poligono(b, [this.iso(x + w, y, z + 3), this.iso(x + w, y + d, z + 3), this.iso(x + w, y + d, z), this.iso(x + w, y, z)], C.panelBorde)
            for (let k = Math.ceil(y); k <= y + d; k += 2) {
                poligono(b, [this.iso(x + w, k, z + h), this.iso(x + w, k + 0.08, z + h), this.iso(x + w, k + 0.08, z), this.iso(x + w, k, z)], C.panelBorde)
            }
            return
        }

        if (m.tipo === 'archivador') {
            this.caja(m)
            for (let k = 0; k < 3; k++) {
                const [sx, sy] = this.iso(m.x + m.w / 2, m.y + m.d, 4 + k * 7)
                b.fillStyle = C.metalOscuro
                b.fillRect(sx - 2, sy, 4, 1)
            }
            return
        }

        this.caja(m)
    }

    efectos() {
        if (this.quieto) return
        const b = this.bctx
        // Humo sobre el servidor cuando alguien falló
        if (this.agentes.some(a => a.estado === 'error')) {
            const m = this.plano.muebles.find(x => x.tipo === 'servidor')
            const [sx, sy] = this.iso(m.x + m.w / 2, m.y + m.d / 2, m.h)
            for (let k = 0; k < 3; k++) {
                const fase = (this.t * 0.8 + k / 3) % 1
                const r = 2 + Math.round(fase * 3)
                b.fillStyle = `rgba(150,150,160,${0.8 - fase * 0.8})`
                b.fillRect(sx - r + (k - 1) * 3, Math.round(sy - 4 - fase * 18) - r, r * 2, r * 2)
            }
        }
    }

    // ─── Personajes ──────────────────────────────────────────────────────────

    personaje(p) {
        const b = this.bctx
        const sentado = ! p.caminando && (p.estado === 'trabajando' || p.estado === 'libre')
        const [sx, sy] = this.iso(p.x, p.y, sentado ? 6 : 0)
        const r = (x, y, w, h, c) => { b.fillStyle = c; b.fillRect(sx + x, sy + y, w, h) }

        if (sentado) {
            // De espaldas, frente a su computador
            const teclea = p.estado === 'trabajando' && ! this.quieto ? Math.floor(this.t * 8 + p.fase) % 2 : 0
            r(-5, -8, 10, 8, p.camisa)
            r(-5, -8, 10, 1, tono(p.camisa, 1.15))
            r(-6, -7 + teclea, 2, 4, tono(p.camisa, 0.8))
            r(4, -7 + (1 - teclea), 2, 4, tono(p.camisa, 0.8))
            r(-3, -15, 6, 7, p.pelo)
            r(-4, -13, 1, 2, p.piel)
            r(3, -13, 1, 2, p.piel)
            r(-1, -9, 2, 1, p.piel)
            this.cajaPersonaje(p, sx, sy - 15, sx, sy)
            return
        }

        // De pie (o caminando), de frente
        const paso = p.caminando && ! this.quieto ? Math.floor(this.t * 8 + p.fase) % 2 : 0
        b.fillStyle = 'rgba(0,0,0,0.18)'
        b.fillRect(sx - 4, sy - 1, 8, 2)
        r(-3, -2 - paso, 3, 2, '#111827')
        r(1, -2 - (1 - paso), 3, 2, '#111827')
        r(-3, -8, 3, 6 - paso, '#1f2937')
        r(1, -8, 3, 6 - (1 - paso), '#1f2937')
        r(-4, -15, 9, 8, p.camisa)
        r(-4, -15, 9, 1, tono(p.camisa, 1.15))

        if (p.estado === 'aprobacion' && ! p.caminando) {
            // Levanta la mano, con el papel en la otra
            const sube = this.quieto ? 0 : Math.floor(this.t * 3 + p.fase) % 2
            r(5, -22 - sube, 2, 8, p.camisa)
            r(5, -24 - sube, 2, 2, p.piel)
            r(-6, -14, 2, 6, p.camisa)
            r(-8, -10, 4, 5, '#f8fafc')
        } else {
            r(-6, -14, 2, 6, p.camisa)
            r(5, -14, 2, 6, p.camisa)
            r(-6, -8, 2, 1, p.piel)
            r(5, -8, 2, 1, p.piel)
            if (p.estado === 'terminado') r(6, -10, 3, 3, '#f8fafc')
        }

        r(-3, -22, 7, 7, p.piel)
        r(-3, -23, 7, 2, p.pelo)
        r(-3, -22, 1, 3, p.pelo)
        r(3, -22, 1, 3, p.pelo)
        r(-1, -19, 1, 1, '#1f2937')
        r(2, -19, 1, 1, '#1f2937')
        if (p.estado === 'error') r(-1, -17, 3, 1, '#7f1d1d')

        this.cajaPersonaje(p, sx, sy - 24, sx, sy)
    }

    personajeDirector() {
        const b = this.bctx
        const [sx, sy] = this.iso(2.15, 2.35, 6)
        const r = (x, y, w, h, c) => { b.fillStyle = c; b.fillRect(sx + x, sy + y, w, h) }
        r(-5, -8, 10, 8, '#1f2937')
        r(-1, -8, 2, 5, '#e5e7eb')
        r(0, -7, 1, 3, '#b91c1c')
        r(-3, -15, 7, 7, '#e0ac80')
        r(-3, -16, 7, 2, '#2b1d14')
        r(-1, -12, 1, 1, '#1f2937')
        r(2, -12, 1, 1, '#1f2937')
        this.dir = { sx, sy: sy - 16 }
    }

    cajaPersonaje(p, sx, top) {
        p.caja = { x0: sx - 8, y0: top - 4, x1: sx + 8, y1: top + 30 }
        p.cabeza = { sx, sy: top }
    }

    // ─── Textos a resolución real ────────────────────────────────────────────

    textos(visibles) {
        this.ocupados = []
        const ctx = this.ctx
        const s = this.escala
        const k = this.dpr
        const fuente = (px, peso = 700) => `${peso} ${Math.round(px * k)}px ui-monospace, SFMono-Regular, Menlo, Consolas, monospace`

        // El nombre de la empresa en la pared del fondo
        if (this.empresa) {
            const { W, X0 } = this.plano
            const [ex, ey] = this.iso((X0 + W) / 2, 0, ALTO_PARED - 6)
            this.letrero(ex * s, ey * s, this.empresa.toUpperCase(), { fuente: fuente(13), fondo: 'rgba(15,23,42,0.88)', texto: '#fde68a', borde: '#fbbf24' })
        }

        // La oficina del director
        const [ox, oy] = this.iso(0, 4.6, 52)
        this.letrero(ox * s, oy * s, 'OFICINA DEL DIRECTOR', { fuente: fuente(9), fondo: 'rgba(30,41,59,0.85)', texto: '#e2e8f0', borde: '#475569' })

        if (this.dir && this.director) {
            this.letrero(this.dir.sx * s, this.dir.sy * s - 6 * k, this.director.toUpperCase(), { fuente: fuente(10), fondo: 'rgba(15,23,42,0.9)', texto: '#e2e8f0', borde: '#64748b' })
        }

        // Cada agente: su nombre, y encima el letrero de estado o el globo de lo que hace
        const ETIQUETA = {
            aprobacion: ['ESPERANDO APROBACIÓN', '#fdba74', '#ef4444'],
            error: ['CON ERROR', '#fca5a5', '#ef4444'],
            terminado: ['LISTO', '#86efac', '#22c55e'],
            libre: ['DISPONIBLE', '#93c5fd', '#3b82f6'],
            trabajando: ['TRABAJANDO', '#86efac', '#22c55e'],
        }

        for (const p of visibles) {
            const a = this.agentes.find(x => x.id === p.id)
            if (! a || ! p.cabeza) continue
            const x = p.cabeza.sx * s
            let y = p.cabeza.sy * s - 6 * k
            const elegido = this.elegido === a.id

            y = this.letrero(x, y, recortar(a.nombre, elegido ? 26 : 14).toUpperCase(), {
                fuente: fuente(10), fondo: 'rgba(15,23,42,0.9)', texto: '#a7f3d0', borde: elegido ? '#facc15' : '#334155',
            })

            const [texto, color, esquina] = ETIQUETA[a.estado] ?? ETIQUETA.libre
            y = this.letrero(x, y - 3 * k, texto, { fuente: fuente(9), fondo: 'rgba(2,6,23,0.85)', texto: color, borde: esquina, esquinas: a.estado === 'aprobacion' || a.estado === 'error' })

            if (a.estado === 'trabajando' && a.actual?.accion) {
                this.globo(x, y - 4 * k, recortar(a.actual.accion, elegido ? 60 : 28), fuente(10, 600))
            }
        }

        // Los de silla vacía (apagados) también se pueden tocar: su caja es el puesto.
        this.cajas = []
        this.agentes.forEach((a, i) => {
            const p = this.personajes.get(a.id)
            if (a.estado !== 'apagado' && p?.caja) {
                this.cajas.push({ id: a.id, ...p.caja })
            } else {
                const { asiento } = this.plano.puestos[i] ?? {}
                if (! asiento) return
                const [sx, sy] = this.iso(asiento.x, asiento.y, 6)
                this.cajas.push({ id: a.id, x0: sx - 14, y0: sy - 26, x1: sx + 14, y1: sy + 6 })
                const [lx, ly] = this.iso(asiento.x, asiento.y - 0.4, 40)
                this.letrero(lx * s, ly * s, recortar(a.nombre, 14).toUpperCase() + ' · ZZZ', {
                    fuente: fuente(9), fondo: 'rgba(15,23,42,0.7)', texto: '#94a3b8', borde: this.elegido === a.id ? '#facc15' : '#334155',
                })
            }
        })
    }

    /**
     * La y más cercana, hacia arriba, donde un rótulo de ese tamaño no tapa a otro ya puesto.
     * Con varios agentes juntos —la fila de espera del director, la zona de café— los nombres
     * se montaban y no se leía ninguno.
     */
    sitioLibre(x0, y0, ancho, alto) {
        const choca = (y) => this.ocupados.some(o => x0 < o.x1 && x0 + ancho > o.x0 && y < o.y1 && y + alto > o.y0)
        let y = y0
        for (let i = 0; i < 6 && choca(y); i++) {
            const tope = Math.min(...this.ocupados.filter(o => x0 < o.x1 && x0 + ancho > o.x0 && y < o.y1 && y + alto > o.y0).map(o => o.y0))
            y = tope - alto - 2 * this.dpr
        }
        this.ocupados.push({ x0, y0: y, x1: x0 + ancho, y1: y + alto })
        return y
    }

    /** Un letrero centrado en x, con su base en y. Devuelve la y de su borde de arriba. */
    letrero(x, y, texto, { fuente, fondo, texto: colorTexto, borde, esquinas = false }) {
        const ctx = this.ctx
        const k = this.dpr
        ctx.font = fuente
        const ancho = ctx.measureText(texto).width + 10 * k
        const alto = parseInt(fuente.match(/(\d+)px/)[1], 10) + 7 * k
        const x0 = Math.round(x - ancho / 2)
        const y0 = this.sitioLibre(x0, Math.round(y - alto), ancho, alto)

        ctx.fillStyle = fondo
        ctx.fillRect(x0, y0, ancho, alto)
        ctx.strokeStyle = borde
        ctx.lineWidth = Math.max(1, k)
        ctx.strokeRect(x0 + 0.5, y0 + 0.5, ancho - 1, alto - 1)

        if (esquinas) {
            // Corchetes en las esquinas, como en los juegos, para lo que pide atención
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

    /** El globo blanco de lo que está haciendo, con su piquito. */
    globo(x, y, texto, fuente) {
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
    }

    // ─── Tocar ────────────────────────────────────────────────────────────────

    /** El agente que hay en ese punto de la pantalla, o null. */
    agenteEn(clienteX, clienteY) {
        const r = this.lienzo.getBoundingClientRect()
        const bx = (clienteX - r.left) * (this.bw / r.width)
        const by = (clienteY - r.top) * (this.bh / r.height)
        // De adelante hacia atrás: el que está más cerca gana
        const candidatos = this.cajas.filter(c => bx >= c.x0 && bx <= c.x1 && by >= c.y0 && by <= c.y1)
        candidatos.sort((a, c) => c.y1 - a.y1)
        return candidatos[0]?.id ?? null
    }
}

function recortar(texto, max) {
    const t = String(texto ?? '')
    return t.length > max ? t.slice(0, max - 1) + '…' : t
}
