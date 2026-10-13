/**
 * La recepción: los agentes de atención a clientes, en vivo.
 *
 * Detrás del mostrador, un puesto por agente de atención (`agentes_ia`), con audífonos y el letrero
 * de los canales que atiende. Cada conversación activa de la bandeja es un cliente que entra por la
 * puerta y se ubica según lo que pasa con ella:
 *
 *   esperando (lo último lo escribió él)   → hace fila frente al agente de su canal
 *   ya le contestaron                      → se sienta en la sala de espera, mirando el celular
 *   con un asesor o escalada a una persona → va al escritorio del asesor humano
 *
 * Cuando una conversación deja de estar activa, su cliente sale por la puerta. Nada aquí escribe
 * ni decide: es la bandeja vista como un lugar.
 */
import { EscenaPixel, C, CAMISA, ALTO_PARED, tono, aspecto, cuadricula, poligono, recortar } from './motor'
import { dePie, dePieEspalda, sentadoFrente } from './sprites'

export const COLOR_RED = {
    whatsapp:  { fondo: '#14532d', texto: '#86efac', borde: '#22c55e', camisa: CAMISA.verde, corto: 'WHATSAPP' },
    instagram: { fondo: '#500724', texto: '#f9a8d4', borde: '#ec4899', camisa: CAMISA.rosa, corto: 'INSTAGRAM' },
    facebook:  { fondo: '#172554', texto: '#93c5fd', borde: '#3b82f6', camisa: CAMISA.azul, corto: 'FACEBOOK' },
    web:       { fondo: '#1e293b', texto: '#cbd5e1', borde: '#64748b', camisa: CAMISA.gris, corto: 'WEB' },
}

const LOOK_ASESOR = { piel: '#c68642', pelo: '#2b1d14', peinado: 'largo', camisa: '#0f766e', pantalon: '#1f2937' }

function armarPlano(n) {
    const puestos = Math.max(1, n)
    const X0 = 2
    const W = Math.max(13, X0 + puestos * 3 + 6)
    const H = 10

    const muebles = []
    const caja = (x, y, z, w, d, h, color, extra = {}) => muebles.push({ x, y, z, w, d, h, color, ...extra })

    const estaciones = Array.from({ length: puestos }, (_, i) => {
        const cx = X0 + i * 3 + 1.5
        caja(cx - 1.5, 2.3, 0, 3, 0.9, 15, C.mostradorFrente, { tipo: 'mostrador' })
        caja(cx + 0.75, 1.35, 14, 0.75, 0.12, 9, C.monitor, { tipo: 'monitor', puesto: i })
        caja(cx - 0.35, 1.25, 0, 0.7, 0.6, 6, C.silla, { tipo: 'silla', puesto: i })
        const fila = [3.7, 4.6, 5.5, 6.4].map((y, k) => ({ x: cx + (k === 3 ? -0.8 : 0), y }))
        return { cx, asiento: { x: cx, y: 1.55 }, fila }
    })

    // El asesor humano, a la derecha del mostrador
    const ax = W - 3.2
    caja(ax - 1.3, 2.3, 0, 2.6, 0.9, 13, C.madera, { tipo: 'escritorio' })
    caja(ax + 0.75, 1.35, 14, 0.75, 0.12, 9, C.monitor, { tipo: 'monitor', puesto: 'asesor' })
    caja(ax - 0.35, 1.25, 0, 0.7, 0.6, 6, C.silla, { tipo: 'silla', puesto: 'asesor' })
    const asesor = { x: ax, y: 1.55, fila: [{ x: ax, y: 3.7 }, { x: ax + 0.9, y: 4.2 }, { x: ax, y: 4.7 }, { x: ax + 0.9, y: 5.2 }] }

    // La sala de espera: un sofá al frente, a la derecha
    const s0 = W - 5
    caja(s0, 7.0, 0, 3.6, 0.25, 15, C.sofa, { tipo: 'respaldo' })
    caja(s0, 7.25, 0, 3.6, 0.85, 6, C.sofa, { tipo: 'cojin' })
    caja(s0 - 1.3, 7.4, 0, 0.9, 0.6, 7, C.maderaOscura, { tipo: 'mesita' })
    const sofa = [0, 1, 2, 3].map(k => ({ x: s0 + 0.5 + k * 0.85, y: 7.65, sentado: true }))
    // Quien no cabe en el sofá espera de pie, al lado
    const dePieSala = [{ x: s0 - 0.3, y: 8.8 }, { x: s0 + 0.6, y: 9.2 }, { x: s0 - 1.2, y: 9.0 }, { x: s0 + 1.6, y: 9.3 }]

    muebles.push({ tipo: 'planta', x: 0.4, y: 0.5, z: 0, w: 0.55, d: 0.55, h: 0, alto: 2 })
    muebles.push({ tipo: 'planta', x: W - 0.9, y: 6.4, z: 0, w: 0.55, d: 0.55, h: 0, alto: 1 })
    caja(W - 1.0, 0.4, 0, 0.6, 0.6, 20, '#e2e8f0', { tipo: 'agua' })

    const puerta = { x: 0.5, y: H - 3 }
    const libre = cuadricula(W, H, muebles, ['planta'])

    return { W, H, X0, estaciones, asesor, sofa, dePieSala, puerta, muebles, libre }
}

/** Un número estable a partir de un texto: el mismo cliente se ve siempre igual. */
function semilla(texto) {
    let h = 7
    for (const ch of String(texto)) h = (h * 31 + ch.charCodeAt(0)) % 100003
    return h
}

export class RecepcionPixel extends EscenaPixel {
    constructor(lienzo, opciones = {}) {
        super(lienzo, opciones)
        this.agentes = []
        this.clientes = []
        this.empresa = ''
        this.asignacion = new Map()
        this.plano = armarPlano(0)
        this.prepararBufer()
    }

    datos({ agentes, clientes, empresa, elegido }) {
        const antes = this.agentes.length
        this.agentes = agentes ?? []
        this.clientes = clientes ?? []
        this.empresa = empresa || ''
        this.elegido = elegido ?? null

        if (this.agentes.length !== antes || ! this.bw) {
            this.plano = armarPlano(this.agentes.length)
            this.personajes.clear()
            this.prepararBufer()
        }

        this.ubicarClientes()
        this.dibujar()
    }

    elegir(id) {
        this.elegido = id
        this.dibujar()
    }

    /** Qué puesto atiende una red: el primer agente encendido que la tenga, o el primero encendido. */
    puestoPara(red) {
        const encendidos = this.agentes.map((a, i) => ({ a, i })).filter(({ a }) => a.activo)
        const exacto = encendidos.find(({ a }) => (a.canales ?? []).includes(red))
        return (exacto ?? encendidos[0])?.i ?? null
    }

    ubicarClientes() {
        const { estaciones, asesor, sofa, dePieSala, puerta } = this.plano
        const filas = estaciones.map(() => [])
        const conAsesor = []
        const sala = []

        // Los más antiguos van adelante en la fila
        const orden = [...this.clientes].sort((a, b) => String(a.cuando).localeCompare(String(b.cuando)))
        this.asignacion = new Map()

        for (const c of orden) {
            if (c.humano || c.escalada) { conAsesor.push(c); continue }
            const puesto = c.esperando ? this.puestoPara(c.red) : null
            if (puesto !== null && filas[puesto].length < estaciones[puesto].fila.length) filas[puesto].push(c)
            else sala.push(c)
        }

        const destinos = new Map()
        filas.forEach((fila, i) => fila.forEach((c, k) => {
            destinos.set(c.clave, { ...estaciones[i].fila[k], mira: 'espalda' })
            this.asignacion.set(c.clave, { lugar: 'fila', puesto: i, turno: k })
        }))
        conAsesor.slice(0, asesor.fila.length).forEach((c, k) => {
            destinos.set(c.clave, { ...asesor.fila[k], mira: c.escalada && ! c.humano ? 'frente' : 'espalda' })
            this.asignacion.set(c.clave, { lugar: 'asesor', turno: k })
        })
        const restoAsesor = conAsesor.slice(asesor.fila.length)
        ;[...sala, ...restoAsesor].forEach((c, k) => {
            const sitio = k < sofa.length ? sofa[k] : dePieSala[(k - sofa.length) % dePieSala.length]
            destinos.set(c.clave, { ...sitio, mira: 'frente' })
            this.asignacion.set(c.clave, { lugar: 'sala', turno: k })
        })

        // Los nuevos entran por la puerta; los que siguen, caminan a su nuevo sitio
        const primeraVez = this.personajes.size === 0
        for (const c of this.clientes) {
            const destino = destinos.get(c.clave)
            if (! destino) continue
            let p = this.personajes.get(c.clave)
            if (! p) {
                const s = semilla(c.clave)
                const look = aspecto(s)
                p = { id: c.clave, x: puerta.x, y: puerta.y, ruta: [], destino: primeraVez ? null : { ...puerta }, look }
                this.personajes.set(c.clave, p)
            }
            p.cliente = c
            p.saliendo = false
            p.alLlegar = null
            p.mirar = destino.mira
            p.sentado = !! destino.sentado
            this.llevar(p, { x: destino.x, y: destino.y })
        }

        // Los que ya no están en la lista salen por la puerta
        for (const [clave, p] of this.personajes) {
            if (destinos.has(clave) || p.saliendo) continue
            if (this.quieto) { this.personajes.delete(clave); continue }
            p.saliendo = true
            p.sentado = false
            p.alLlegar = () => this.personajes.delete(clave)
            this.llevar(p, { ...puerta })
        }
    }

    // ─── El fondo ─────────────────────────────────────────────────────────────

    pintarFondo(ctx) {
        const { W, H, X0, estaciones } = this.plano
        const xFin = X0 + estaciones.length * 3

        this.pintarParedesYPiso(ctx, (gx, gy) => {
            const par = (gx + gy) % 2 === 0
            // Pasillo de la fila frente al mostrador
            if (gy >= 3 && gy < 7 && gx >= X0 && gx < xFin) return par ? C.pasillo1 : C.pasillo2
            // Tapete de la puerta
            if (gx <= 1 && gy >= H - 4 && gy < H - 1) return par ? '#475569' : '#3f4b5c'
            // Alfombra de la sala
            if (gy >= 7 && gx >= W - 6) return par ? C.alfombra1 : C.alfombra2
            return par ? '#e7dcc5' : '#ddd1b8'
        })

        // Ventanas sobre el asesor y la sala
        for (let x = xFin + 0.5; x < W - 1.5; x += 2.6) this.ventana(ctx, x)

        // La puerta de vidrio, en la pared izquierda
        const y0 = H - 4, y1 = H - 2
        poligono(ctx, [this.iso(0, y0, 38), this.iso(0, y1, 38), this.iso(0, y1, 0), this.iso(0, y0, 0)], '#475569')
        poligono(ctx, [this.iso(0, y0 + 0.12, 36), this.iso(0, y1 - 0.12, 36), this.iso(0, y1 - 0.12, 0), this.iso(0, y0 + 0.12, 0)], '#bae6fd')
        poligono(ctx, [this.iso(0, (y0 + y1) / 2 - 0.04, 36), this.iso(0, (y0 + y1) / 2 + 0.04, 36), this.iso(0, (y0 + y1) / 2 + 0.04, 0), this.iso(0, (y0 + y1) / 2 - 0.04, 0)], '#475569')
        poligono(ctx, [this.iso(0, y0 + 0.4, 32), this.iso(0, y0 + 0.6, 32), this.iso(0, y0 + 0.5, 18), this.iso(0, y0 + 0.3, 18)], '#f0f9ff')
        // Letrero de «Bienvenido» sobre la puerta
        poligono(ctx, [this.iso(0, y0 + 0.2, 47), this.iso(0, y1 - 0.2, 47), this.iso(0, y1 - 0.2, 40), this.iso(0, y0 + 0.2, 40)], '#0f172a')
        poligono(ctx, [this.iso(0, y0 + 0.35, 45), this.iso(0, y1 - 0.35, 45), this.iso(0, y1 - 0.35, 44), this.iso(0, y0 + 0.35, 44)], '#4ade80')

        // Franja de marca en la pared del mostrador
        poligono(ctx, [this.iso(X0, 0, 30), this.iso(xFin, 0, 30), this.iso(xFin, 0, 28), this.iso(X0, 0, 28)], '#38bdf8')
    }

    // ─── Muebles y personas ──────────────────────────────────────────────────

    componer(b) {
        const { estaciones, asesor } = this.plano
        const cosas = this.plano.muebles.map(m => ({
            prof: m.x + m.w / 2 + m.y + m.d / 2 + (m.tipo === 'respaldo' ? -0.4 : 0),
            pintar: () => this.mueble(m),
        }))

        estaciones.forEach((e, i) => {
            const a = this.agentes[i]
            if (a?.activo) cosas.push({ prof: e.asiento.x + e.asiento.y + 0.6, pintar: () => this.recepcionista(e, a, i) })
        })
        cosas.push({ prof: asesor.x + asesor.y + 0.6, pintar: () => this.asesorEnSilla() })

        for (const p of this.personajes.values()) {
            const sentado = p.sentado && ! p.ruta?.length
            cosas.push({ prof: p.x + p.y + (sentado ? 0.8 : 0.05), pintar: () => this.cliente(p, sentado) })
        }

        this.pintarOrdenado(cosas)
        this.efectos(b)
    }

    atendiendo(i) {
        for (const [, v] of this.asignacion) if (v.lugar === 'fila' && v.puesto === i) return true
        return false
    }

    mueble(m) {
        const b = this.bctx
        switch (m.tipo) {
            case 'planta':
                return this.planta(m.x, m.y, m.alto)

            case 'mostrador': {
                this.caja(m)
                // Cubierta clara con un borde que sobresale, y una franja de luz al frente
                poligono(b, [this.iso(m.x - 0.05, m.y - 0.05, m.h + 1.5), this.iso(m.x + m.w + 0.05, m.y - 0.05, m.h + 1.5), this.iso(m.x + m.w + 0.05, m.y + m.d + 0.1, m.h + 1.5), this.iso(m.x - 0.05, m.y + m.d + 0.1, m.h + 1.5)], C.mostrador)
                poligono(b, [this.iso(m.x - 0.05, m.y + m.d + 0.1, m.h + 1.5), this.iso(m.x + m.w + 0.05, m.y + m.d + 0.1, m.h + 1.5), this.iso(m.x + m.w + 0.05, m.y + m.d + 0.1, m.h), this.iso(m.x - 0.05, m.y + m.d + 0.1, m.h)], tono(C.mostrador, 0.8))
                this.caraFrente(m, 0.1, m.w - 0.1, 9, 10, '#38bdf8')
                this.caraFrente(m, 0.1, m.w - 0.1, 2, 3, tono(C.mostradorFrente, 0.75))
                return
            }

            case 'monitor': {
                // En un brazo al lado del puesto, de espaldas a la cámara: un led dice si el agente está atendiendo
                this.caja({ x: m.x + m.w / 2 - 0.05, y: m.y + 0.01, z: 0, w: 0.1, d: 0.1, h: m.z }, '#374151')
                this.caja(m)
                const a = typeof m.puesto === 'number' ? this.agentes[m.puesto] : null
                const vivo = m.puesto === 'asesor' || a?.activo
                const ocupado = typeof m.puesto === 'number' && this.atendiendo(m.puesto)
                const brilla = ! this.quieto && ocupado ? Math.floor(this.t * 3) % 2 === 0 : true
                this.caraFrente(m, m.w - 0.18, m.w - 0.1, 1, 2, ! vivo ? '#334155' : ocupado ? (brilla ? '#22d3ee' : '#0e7490') : '#4ade80')
                return
            }

            case 'silla': {
                const a = typeof m.puesto === 'number' ? this.agentes[m.puesto] : null
                // Respaldo detrás de quien se sienta; si el puesto está cerrado, la silla queda sola
                this.caja({ x: m.x, y: m.y + 0.05, z: 6, w: m.w, d: 0.12, h: 14 }, C.silla)
                if (m.puesto === 'asesor' || a?.activo) return
                this.caja({ ...m, h: 2, z: 5 }, C.silla)
                return
            }

            case 'agua': {
                this.caja({ ...m, h: 12 }, '#cbd5e1')
                this.caja({ x: m.x + 0.08, y: m.y + 0.08, z: 12, w: m.w - 0.16, d: m.d - 0.16, h: 9 }, '#7dd3fc')
                this.caraFrente(m, 0.2, 0.3, 8, 9, '#3b82f6')
                this.caraFrente(m, 0.35, 0.45, 8, 9, '#ef4444')
                return
            }

            case 'cojin': {
                this.caja(m)
                for (let k = 1; k < 4; k++) {
                    const x = m.x + k * 0.9
                    poligono(b, [this.iso(x - 0.02, m.y, m.h), this.iso(x + 0.02, m.y, m.h), this.iso(x + 0.02, m.y + m.d, m.h), this.iso(x - 0.02, m.y + m.d, m.h)], tono(m.color, 0.8))
                }
                return
            }

            case 'mesita': {
                this.caja(m)
                this.caja({ x: m.x + 0.15, y: m.y + 0.15, z: m.h, w: 0.4, d: 0.3, h: 0.6 }, '#f8fafc')
                return
            }

            default:
                this.caja(m)
        }
    }

    recepcionista(e, a, i) {
        const [sx, sy] = this.iso(e.asiento.x, e.asiento.y, 6)
        const look = { ...aspecto(a.id * 7 + 3, COLOR_RED[(a.canales ?? [])[0]]?.camisa ?? CAMISA.violeta), accesorio: 'audifonos' }
        const ocupado = this.atendiendo(i)
        sentadoFrente(this.pintor(sx, sy), look, {
            cara: 'feliz',
            teclea: ocupado && ! this.quieto ? Math.floor(this.t * 8 + i) % 2 : 0,
            parpadeo: ! this.quieto && Math.floor(this.t * 2 + i * 3) % 11 === 0,
        })
        e.cabeza = { sx, sy: sy - 33 }
    }

    asesorEnSilla() {
        const { asesor } = this.plano
        const [sx, sy] = this.iso(asesor.x, asesor.y, 6)
        const ocupado = [...this.asignacion.values()].some(v => v.lugar === 'asesor')
        sentadoFrente(this.pintor(sx, sy), LOOK_ASESOR, {
            cara: 'normal',
            teclea: ocupado && ! this.quieto ? Math.floor(this.t * 7) % 2 : 0,
            parpadeo: ! this.quieto && Math.floor(this.t * 2) % 13 === 0,
        })
        this.asesorPunto = { sx, sy: sy - 33 }
    }

    cliente(p, sentado) {
        const z = sentado ? 6 : 0
        const [sx, sy] = this.iso(p.x, p.y, z)
        const r = this.pintor(sx, sy)
        const c = p.cliente
        const parpadeo = ! this.quieto && Math.floor(this.t * 2 + p.look.fase) % 9 === 0

        if (sentado) {
            // En el sofá, mirando el celular
            sentadoFrente(r, p.look, { parpadeo, cara: 'normal' })
            const pan = p.look.pantalon
            r(-7, -2, 6, 4, pan); r(1, -2, 6, 4, pan)
            r(-6, 2, 5, 6, tono(pan, 0.85)); r(1, 2, 5, 6, tono(pan, 0.85))
            r(-7, 8, 6, 2, '#1c1f24'); r(1, 8, 6, 2, '#1c1f24')
            r(-3, -9, 6, 8, '#0f172a'); r(-2, -8, 4, 5, COLOR_RED[c?.red]?.borde ?? '#38bdf8')
            p.caja = { x0: sx - 12, y0: sy - 34, x1: sx + 12, y1: sy + 10 }
            p.cabeza = { sx, sy: sy - 33 }
            return
        }

        const caminando = !! p.ruta?.length
        const paso = caminando && ! this.quieto ? Math.floor(this.t * 8 + p.look.fase) % 2 : 0
        const mira = caminando ? p.mira : p.mirar
        const pose = {
            caminando, paso, parpadeo,
            mano: ! caminando && c?.escalada && ! c?.humano,
            saluda: ! this.quieto && Math.floor(this.t * 3 + p.look.fase) % 2 === 0,
            cara: c?.escalada ? 'triste' : 'normal',
        }
        if (mira === 'espalda') dePieEspalda(r, p.look, pose)
        else dePie(r, p.look, pose)

        p.caja = { x0: sx - 12, y0: sy - 50, x1: sx + 12, y1: sy + 4 }
        p.cabeza = { sx, sy: sy - 50 }
    }

    efectos() {
        // Una línea de puntos de cada agente a quien atiende al frente de su fila
        for (const [clave, v] of this.asignacion) {
            if (v.lugar !== 'fila' || v.turno !== 0) continue
            const e = this.plano.estaciones[v.puesto]
            const p = this.personajes.get(clave)
            if (! e?.cabeza || ! p?.cabeza || p.ruta?.length) continue
            this.rayo([e.cabeza.sx, e.cabeza.sy + 10], [p.cabeza.sx, p.cabeza.sy + 6], COLOR_RED[p.cliente?.red]?.borde ?? '#67e8f9')
        }
    }

    // ─── Textos ───────────────────────────────────────────────────────────────

    textos() {
        const s = this.escala
        const k = this.dpr
        const { X0, estaciones } = this.plano

        const xFin = X0 + estaciones.length * 3
        const [ex, ey] = this.iso((X0 + xFin) / 2, 0, ALTO_PARED - 6)
        this.letrero(ex * s, ey * s, (this.empresa ? this.empresa.toUpperCase() + ' · ' : '') + 'RECEPCIÓN', {
            fuente: this.fuente(13), fondo: 'rgba(15,23,42,0.92)', texto: '#fde68a', borde: '#fbbf24',
        })

        const [px, py] = this.iso(0, this.plano.H - 3, 52)
        this.letrero(px * s, py * s, 'BIENVENIDO', { fuente: this.fuente(8), fondo: 'rgba(15,23,42,0.85)', texto: '#86efac', borde: '#22c55e' })

        if (! this.agentes.length) {
            const [nx, ny] = this.iso(estaciones[0].cx, 1.5, 34)
            this.letrero(nx * s, ny * s, 'SIN AGENTES DE ATENCIÓN', { fuente: this.fuente(9), fondo: 'rgba(15,23,42,0.85)', texto: '#94a3b8', borde: '#475569' })
        }

        this.agentes.forEach((a, i) => {
            const e = estaciones[i]
            const elegido = this.elegido === a.id
            const red = (a.canales ?? [])[0]
            const colores = COLOR_RED[red] ?? COLOR_RED.web

            // Los canales que atiende, en la pared detrás de su puesto
            const [cx, cy] = this.iso(e.cx, 0, 40)
            const canales = (a.canales ?? []).map(c => COLOR_RED[c]?.corto ?? c.toUpperCase()).join(' · ') || 'SIN CANAL'
            this.letrero(cx * s, cy * s, recortar(canales, 22), { fuente: this.fuente(8), fondo: colores.fondo, texto: colores.texto, borde: colores.borde, libre: false })

            if (! a.activo) {
                const [sx, sy] = this.iso(e.asiento.x, e.asiento.y, 6)
                this.tocable(a.id, 'agente', sx - 20, sy - 40, sx + 20, sy + 8)
                const [lx, ly] = this.iso(e.cx, 2.3, 30)
                this.letrero(lx * s, ly * s, recortar(a.nombre, 14).toUpperCase() + ' · CERRADO', {
                    fuente: this.fuente(9), fondo: 'rgba(15,23,42,0.75)', texto: '#94a3b8', borde: elegido ? '#facc15' : '#334155',
                })
                return
            }

            if (! e.cabeza) return
            this.tocable(a.id, 'agente', e.cabeza.sx - 13, e.cabeza.sy - 4, e.cabeza.sx + 13, e.cabeza.sy + 30)
            const x = e.cabeza.sx * s
            let y = e.cabeza.sy * s - 6 * k
            y = this.letrero(x, y, recortar(a.nombre, elegido ? 26 : 16).toUpperCase(), {
                fuente: this.fuente(10), fondo: 'rgba(15,23,42,0.9)', texto: '#a7f3d0', borde: elegido ? '#facc15' : '#334155',
            })

            const turno = [...this.asignacion].find(([, v]) => v.lugar === 'fila' && v.puesto === i && v.turno === 0)
            const enFila = [...this.asignacion.values()].filter(v => v.lugar === 'fila' && v.puesto === i).length
            const [estado, color, borde] = ! a.en_horario
                ? ['FUERA DE HORARIO', '#fcd34d', '#f59e0b']
                : turno ? [`ATENDIENDO${enFila > 1 ? ` · ${enFila - 1} EN FILA` : ''}`, '#67e8f9', '#06b6d4'] : ['DISPONIBLE', '#86efac', '#22c55e']
            y = this.letrero(x, y - 3 * k, estado, { fuente: this.fuente(9), fondo: 'rgba(2,6,23,0.86)', texto: color, borde })

            if (turno) {
                const quien = this.personajes.get(turno[0])?.cliente?.nombre ?? 'un cliente'
                this.globo(x, y - 4 * k, `Respondiendo a ${recortar(quien, 18)}`)
            }
        })

        if (this.asesorPunto) {
            const ocupado = [...this.asignacion.values()].filter(v => v.lugar === 'asesor').length
            const x = this.asesorPunto.sx * s
            const y = this.letrero(x, this.asesorPunto.sy * s - 6 * k, 'ASESOR HUMANO', { fuente: this.fuente(10), fondo: 'rgba(15,23,42,0.9)', texto: '#e2e8f0', borde: '#64748b' })
            this.letrero(x, y - 3 * k, ocupado ? `${ocupado} ${ocupado === 1 ? 'CONVERSACIÓN' : 'CONVERSACIONES'}` : 'SIN PENDIENTES', {
                fuente: this.fuente(9), fondo: 'rgba(2,6,23,0.86)', texto: ocupado ? '#fdba74' : '#94a3b8', borde: ocupado ? '#f97316' : '#334155',
            })
        }

        // Los clientes: su nombre con el color de su red; quien está al frente de una fila dice lo que escribió
        for (const p of this.personajes.values()) {
            const c = p.cliente
            if (! c || ! p.cabeza || p.saliendo) continue
            this.tocable(c.clave, 'cliente', p.caja.x0, p.caja.y0, p.caja.x1, p.caja.y1)
            const colores = COLOR_RED[c.red] ?? COLOR_RED.web
            const elegido = this.elegido === c.clave
            const x = p.cabeza.sx * s
            let y = this.letrero(x, p.cabeza.sy * s - 4 * k, recortar(c.nombre, 14).toUpperCase(), {
                fuente: this.fuente(8), fondo: 'rgba(15,23,42,0.85)', texto: colores.texto, borde: elegido ? '#facc15' : colores.borde,
            })
            const v = this.asignacion.get(c.clave)
            const caminando = !! p.ruta?.length
            if (! caminando && v?.lugar === 'fila' && v.turno === 0 && c.texto) {
                this.globo(x, y - 3 * k, `«${recortar(c.texto, 26)}»`, this.fuente(9, 600))
            } else if (! caminando && c.escalada && ! c.humano) {
                this.globo(x, y - 3 * k, 'Quiero hablar con alguien', this.fuente(9, 600))
            }
        }

    }
}
