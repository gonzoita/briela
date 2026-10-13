/**
 * La oficina del Equipo de IA.
 *
 * A la izquierda la oficina del director (quien está mirando la pantalla); en el centro un
 * cubículo por agente; al frente el **centro de control**, donde la guía —el agente principal,
 * el del chat— orquesta: de ella salen rayos de luz hacia cada agente que está trabajando. A la
 * derecha el servidor y la zona de café.
 *
 * Quién está dónde lo decide el estado que manda el servidor:
 *   trabajando → en su escritorio, tecleando, con código en la pantalla
 *   libre      → en su escritorio, con el café humeando
 *   aprobacion → camina a la oficina del director y espera con la mano levantada y un papel
 *   error      → de pie junto al servidor, que echa humo
 *   terminado  → en la zona de café, con su taza
 *   apagado    → no está: silla vacía y monitor apagado
 */
import { EscenaPixel, C, CAMISA, ALTO_PARED, tono, aspecto, cuadricula, poligono, recortar } from './motor'
import { dePie, dePieEspalda, sentadoEspalda, sentadoFrente } from './sprites'

function armarPlano(n) {
    const cols = n <= 2 ? 2 : n <= 6 ? 3 : 4
    const filas = Math.max(1, Math.ceil(n / cols))
    const X0 = 6
    const W = X0 + cols * 4 + 4
    const H = Math.max(10, 1 + filas * 4 + 2) + 3

    const puestos = Array.from({ length: n }, (_, i) => {
        const cx = X0 + (i % cols) * 4
        const cy = 1 + Math.floor(i / cols) * 4
        return { cx, cy, asiento: { x: cx + 1.5, y: cy + 1.6 } }
    })

    const muebles = []
    const caja = (x, y, z, w, d, h, color, extra = {}) => muebles.push({ x, y, z, w, d, h, color, ...extra })

    // Oficina del director
    caja(0.15, 0.6, 0, 0.5, 2.4, 34, C.madera, { tipo: 'estante' })
    caja(0.8, 3.0, 0, 2.8, 1.0, 13, C.maderaDirector, { tipo: 'escritorio' })
    caja(1.7, 1.75, 6, 0.9, 0.7, 3, C.sillaDirector, { tipo: 'asiento' })
    caja(1.7, 1.55, 6, 0.9, 0.2, 18, C.sillaDirector, { tipo: 'respaldo' })
    caja(1.0, 3.25, 15, 0.8, 0.12, 9, C.monitor, { tipo: 'monitor', estado: 'director' })
    caja(2.6, 3.4, 13, 0.6, 0.4, 0.6, '#f8fafc', { tipo: 'papeles' })
    caja(1.0, 4.8, 0, 0.7, 0.6, 7, C.silla, { tipo: 'silla' })
    caja(2.9, 4.8, 0, 0.7, 0.6, 7, C.silla, { tipo: 'silla' })
    muebles.push({ tipo: 'planta', x: 4.1, y: 0.5, z: 0, w: 0.55, d: 0.55, h: 0, alto: 2 })

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
        caja(cx + 0.7, cy + 0.35, 13, 0.85, 0.12, 10, C.monitor, { tipo: 'monitor', agente: i })
        caja(cx + 1.65, cy + 0.35, 13, 0.85, 0.12, 10, C.monitor, { tipo: 'monitor', agente: i })
        caja(cx + 1.0, cy + 0.8, 11, 0.9, 0.22, 1, C.teclado, { tipo: 'teclado' })
        caja(cx + 2.0, cy + 0.85, 11, 0.12, 0.16, 1, '#cbd5e1', { tipo: 'raton' })
        caja(cx + 0.35, cy + 0.7, 11, 0.45, 0.3, 0.6, '#f8fafc', { tipo: 'papeles' })
        caja(cx + 2.35, cy + 0.6, 11, 0.25, 0.25, 4, '#f5f5f4', { tipo: 'taza', agente: i })
        caja(cx + 1.2, cy + 1.4, 5, 0.65, 0.6, 2, C.silla, { tipo: 'asiento' })
        caja(cx + 1.2, cy + 2.0, 5, 0.65, 0.1, 11, C.silla, { tipo: 'respaldo' })
    })

    const xa = X0 + cols * 4
    caja(xa + 0.1, 0.15, 0, 0.8, 0.6, 22, C.metal, { tipo: 'archivador' })

    // El centro de control, al frente y al medio de los cubículos
    const control = { x: X0 + cols * 2, y: H - 2.0 }
    caja(control.x - 1.3, H - 2.9, 0, 2.6, 0.7, 14, '#1e293b', { tipo: 'consola' })

    // Servidor y zona de café
    caja(W - 2.0, 0.4, 0, 1.0, 0.8, 38, C.servidor, { tipo: 'servidor' })
    caja(W - 2.8, H - 2.6, 0, 1.4, 0.9, 10, C.madera, { tipo: 'mesa' })
    caja(W - 1.1, H - 3.6, 0, 0.8, 0.6, 16, C.metalOscuro, { tipo: 'cafetera' })
    muebles.push({ tipo: 'planta', x: W - 3.6, y: H - 1.2, z: 0, w: 0.55, d: 0.55, h: 0, alto: 1 })

    const sitios = {
        director: [{ x: 1.7, y: 5.9 }, { x: 3.3, y: 5.9 }, { x: 4.4, y: 4.6 }, { x: 4.4, y: 6.4 }, { x: 0.8, y: 6.6 }, { x: 2.5, y: 7.2 }],
        servidor: [{ x: W - 1.5, y: 2.0 }, { x: W - 2.5, y: 2.0 }, { x: W - 3.5, y: 1.7 }, { x: W - 1.5, y: 3.0 }],
        cafe: [{ x: W - 3.4, y: H - 2.0 }, { x: W - 1.0, y: H - 2.0 }, { x: W - 2.0, y: H - 1.3 }, { x: W - 2.2, y: H - 3.2 }, { x: W - 3.3, y: H - 3.1 }],
        guia: { x: control.x, y: H - 1.5 },
    }

    const libre = cuadricula(W, H, muebles, ['planta'])
    for (const p of puestos) {
        libre[p.cx + 1][p.cy + 1] = true
        libre[p.cx + 1][p.cy + 2] = true
    }
    libre[2][2] = true

    const pasillos = new Set()
    for (let f = 0; f < filas; f++) pasillos.add(1 + f * 4 + 3)

    return { W, H, X0, cols, filas, puestos, muebles, sitios, libre, pasillos, control }
}

const LOOK_DIRECTOR = { piel: '#e0ac80', pelo: '#2b1d14', peinado: 'corto', camisa: '#1f2937', chaqueta: '#1f2937', corbata: '#b91c1c' }
const LOOK_GUIA = { piel: '#f2c9a0', pelo: '#1f1f1f', peinado: 'mono', camisa: '#0e7490', chaqueta: '#312e81', corbata: '#22d3ee', accesorio: 'audifonos', pantalon: '#1e1b4b' }

export class OficinaPixel extends EscenaPixel {
    constructor(lienzo, opciones = {}) {
        super(lienzo, opciones)
        this.agentes = []
        this.empresa = ''
        this.director = ''
        this.guia = { nombre: 'Asistente', ocupada: false }
        this.plano = armarPlano(0)
        this.prepararBufer()
    }

    datos({ agentes, empresa, director, elegido, guia }) {
        const cambio = agentes.length !== this.agentes.length || ! this.plano.puestos.length && agentes.length
        this.agentes = agentes
        this.empresa = empresa || ''
        this.director = director || ''
        this.elegido = elegido ?? null
        if (guia) this.guia = guia

        if (cambio) {
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

    ubicar(a, i) {
        const puesto = this.plano.puestos[i]
        let p = this.personajes.get(a.id)
        if (! p) {
            p = { id: a.id, x: puesto.asiento.x, y: puesto.asiento.y, ruta: [], destino: null, look: aspecto(a.id, CAMISA[a.color]) }
            this.personajes.set(a.id, p)
        }
        p.i = i
        p.estado = a.estado
        p.look.camisa = CAMISA[a.color] ?? p.look.camisa

        const turno = this.agentes.filter((o, k) => k < i && o.estado === a.estado).length
        const s = this.plano.sitios
        const destino = {
            aprobacion: s.director[turno % s.director.length],
            error: s.servidor[turno % s.servidor.length],
            terminado: s.cafe[turno % s.cafe.length],
        }[a.estado] ?? puesto.asiento

        this.llevar(p, destino)
    }

    // ─── El fondo ─────────────────────────────────────────────────────────────

    pintarFondo(ctx) {
        const { W, H, X0, pasillos, cols, control } = this.plano

        this.pintarParedesYPiso(ctx, (gx, gy) => {
            const par = (gx + gy) % 2 === 0
            if (gx < X0 - 1 && gy >= 1 && gy < H - 4 && gx >= 1) return par ? C.alfombra1 : C.alfombra2
            if (gy >= H - 3 && gx >= X0 && gx < X0 + cols * 4) return par ? C.plataforma1 : C.plataforma2
            if (gx >= X0 - 1 && pasillos.has(gy)) return par ? C.pasillo1 : C.pasillo2
            return par ? C.piso1 : C.piso2
        })

        // El borde luminoso del centro de control
        poligono(ctx, [this.iso(X0, H - 3), this.iso(X0 + cols * 4, H - 3), this.iso(X0 + cols * 4, H - 2.94), this.iso(X0, H - 2.94)], C.plataformaLuz)

        for (let x = X0 + 1; x < W - 3; x += 4) this.ventana(ctx, x)

        // Un cuadro en la oficina del director
        poligono(ctx, [this.iso(0, 3.2, 44), this.iso(0, 5.2, 44), this.iso(0, 5.2, 28), this.iso(0, 3.2, 28)], '#e8e2d0')
        poligono(ctx, [this.iso(0, 3.4, 42), this.iso(0, 5.0, 42), this.iso(0, 5.0, 30), this.iso(0, 3.4, 30)], '#7aa874')
        poligono(ctx, [this.iso(0, 3.6, 36), this.iso(0, 4.4, 41), this.iso(0, 4.9, 34), this.iso(0, 4.9, 30), this.iso(0, 3.6, 30)], '#5b8a57')
        // Un reloj en la pared del fondo
        const [rx, ry] = this.iso(X0 - 2.2, 0, 44)
        ctx.fillStyle = '#f8fafc'; ctx.fillRect(rx - 6, ry - 6, 12, 12)
        ctx.fillStyle = '#334155'; ctx.fillRect(rx - 6, ry - 6, 12, 1); ctx.fillRect(rx, ry - 4, 1, 5); ctx.fillRect(rx, ry, 4, 1)
    }

    // ─── Muebles y personajes ────────────────────────────────────────────────

    componer(b) {
        const cosas = this.plano.muebles.map(m => ({
            prof: m.x + m.w / 2 + m.y + m.d / 2 + (m.tipo === 'panel' || m.tipo === 'vidrio' ? -0.6 : 0),
            pintar: () => this.mueble(m),
        }))

        this.agentes.forEach((a) => {
            const p = this.personajes.get(a.id)
            if (! p || a.estado === 'apagado') return
            cosas.push({ prof: p.x + p.y + 0.05, pintar: () => this.agente(p, a) })
        })

        cosas.push({ prof: 2.15 + 2.35, pintar: () => this.directorEnSilla() })

        const g = this.plano.sitios.guia
        cosas.push({ prof: g.x + g.y + 0.1, pintar: () => this.guiaEnConsola() })

        this.pintarOrdenado(cosas)
        this.efectos(b)
    }

    mueble(m) {
        const b = this.bctx

        switch (m.tipo) {
            case 'planta':
                return this.planta(m.x, m.y, m.alto)

            case 'monitor': {
                // Patita, marco y pantalla
                const pata = { x: m.x + m.w / 2 - 0.06, y: m.y + 0.01, z: m.z - 2, w: 0.12, d: 0.1, h: 2 }
                this.caja(pata, '#374151')
                this.caja(m)
                const estado = m.estado === 'director' ? 'libre' : this.agentes[m.agente]?.estado ?? 'apagado'
                this.caraFrente(m, 0.07, m.w - 0.07, 1.2, m.h - 1.2, C.pantalla[estado] ?? C.pantalla.apagado)

                if (estado === 'trabajando') {
                    const paso = Math.floor(this.t * 6) + (m.agente ?? 0) * 5 + Math.round(m.x * 3)
                    for (let k = 0; k < 4; k++) {
                        const largo = 0.15 + ((paso + k * 7) % 5) / 9
                        const z = m.h - 2.6 - k * 1.8
                        const color = k % 3 === 1 ? '#facc15' : '#4ade80'
                        this.caraFrente(m, 0.14 + (k % 2) * 0.08, 0.14 + (k % 2) * 0.08 + largo, z - 0.7, z, color)
                    }
                } else if (estado === 'libre') {
                    // Protector de pantalla: un punto que da vueltas
                    const f = (this.t * 0.6 + m.x) % 1
                    this.caraFrente(m, 0.15 + f * 0.5, 0.25 + f * 0.5, 4, 5, '#93c5fd')
                } else if ((estado === 'aprobacion' || estado === 'error') && Math.floor(this.t * 2) % 2 === 0) {
                    this.caraFrente(m, m.w / 2 - 0.06, m.w / 2 + 0.06, 3, 7, estado === 'error' ? '#fecaca' : '#fed7aa')
                }
                return
            }

            case 'teclado': {
                this.caja(m)
                const [sx, sy] = this.iso(m.x + 0.1, m.y + 0.06, m.z + m.h)
                b.fillStyle = '#6b7280'
                for (let k = 0; k < 6; k++) b.fillRect(sx + k * 5, sy + Math.round(k * 0.5) + 1, 3, 1)
                return
            }

            case 'servidor': {
                this.caja(m)
                const hayError = this.agentes.some(a => a.estado === 'error')
                for (let fila = 0; fila < 5; fila++) {
                    this.caraFrente(m, 0.08, m.w - 0.08, 5 + fila * 6.5, 5 + fila * 6.5 + 0.8, '#111827')
                    for (let k = 0; k < 3; k++) {
                        const prendida = (Math.floor(this.t * 4) + k + fila) % 3 !== 0
                        const color = hayError ? (prendida ? '#ef4444' : '#7f1d1d') : (prendida ? '#22c55e' : '#14532d')
                        this.caraFrente(m, 0.15 + k * 0.12, 0.2 + k * 0.12, 7 + fila * 6.5, 8 + fila * 6.5, color)
                    }
                }
                return
            }

            case 'taza': {
                this.caja(m)
                const estado = this.agentes[m.agente]?.estado
                if (estado === 'libre' && ! this.quieto) {
                    const [sx, sy] = this.iso(m.x + m.w / 2, m.y + m.d / 2, m.z + m.h + 2)
                    const f = Math.floor(this.t * 3) % 3
                    b.fillStyle = 'rgba(255,255,255,0.75)'
                    b.fillRect(sx - 2 + (f === 1 ? 1 : 0), sy - 3 - f * 2, 2, 3)
                    b.fillRect(sx + 1 - (f === 2 ? 1 : 0), sy - 7 - f * 2, 2, 3)
                }
                return
            }

            case 'vidrio': {
                const { x, y, z, w, d, h } = m
                b.globalAlpha = 0.16
                this.caja(m)
                b.globalAlpha = 1
                poligono(b, [this.iso(x + w, y, z + h), this.iso(x + w, y + d, z + h), this.iso(x + w, y + d, z + h - 2), this.iso(x + w, y, z + h - 2)], C.panelBorde)
                poligono(b, [this.iso(x + w, y, z + 3), this.iso(x + w, y + d, z + 3), this.iso(x + w, y + d, z), this.iso(x + w, y, z)], C.panelBorde)
                for (let k = Math.ceil(y); k <= y + d; k += 2) {
                    poligono(b, [this.iso(x + w, k, z + h), this.iso(x + w, k + 0.06, z + h), this.iso(x + w, k + 0.06, z), this.iso(x + w, k, z)], C.panelBorde)
                }
                return
            }

            case 'archivador': {
                this.caja(m)
                for (let k = 0; k < 3; k++) {
                    this.caraFrente(m, 0.05, m.w - 0.05, 1 + k * 7, 1 + k * 7 + 0.6, tono(C.metal, 0.75))
                    this.caraFrente(m, m.w / 2 - 0.08, m.w / 2 + 0.08, 4 + k * 7, 5 + k * 7, '#475569')
                }
                return
            }

            case 'cajonera': {
                this.caja(m)
                for (let k = 0; k < 2; k++) this.caraFrente(m, m.w / 2 - 0.1, m.w / 2 + 0.1, 3 + k * 4, 3.8 + k * 4, '#5b3b1f')
                return
            }

            case 'estante': {
                this.caja(m)
                // Libros en la cara que mira a la oficina (la de x + w)
                const colores = ['#b91c1c', '#1d4ed8', '#047857', '#a16207', '#7c3aed', '#0f766e']
                for (let fila = 0; fila < 3; fila++) {
                    for (let k = 0; k < 8; k++) {
                        const yy = m.y + 0.15 + k * 0.27
                        const z0 = 4 + fila * 10, z1 = z0 + 6 + (k % 3)
                        poligono(b, [this.iso(m.x + m.w + 0.002, yy, z0), this.iso(m.x + m.w + 0.002, yy + 0.2, z0), this.iso(m.x + m.w + 0.002, yy + 0.2, z1), this.iso(m.x + m.w + 0.002, yy, z1)], colores[(k + fila * 2) % colores.length])
                    }
                }
                return
            }

            case 'consola': {
                this.caja(m)
                // Botones y una franja de luz
                this.caraFrente(m, 0.1, m.w - 0.1, m.h - 3, m.h - 2.2, '#38bdf8')
                for (let k = 0; k < 6; k++) {
                    const prendido = (Math.floor(this.t * 3) + k) % 4 !== 0
                    this.caraFrente(m, 0.25 + k * 0.38, 0.4 + k * 0.38, 4, 6, prendido ? ['#22d3ee', '#a78bfa', '#4ade80'][k % 3] : '#334155')
                }
                return
            }

            case 'cafetera': {
                this.caja(m)
                this.caraFrente(m, 0.15, m.w - 0.15, 6, 11, '#111827')
                this.caraFrente(m, 0.3, 0.38, 9, 10, Math.floor(this.t * 2) % 2 ? '#ef4444' : '#7f1d1d')
                return
            }

            default:
                this.caja(m)
        }
    }

    agente(p, a) {
        const sentado = ! p.caminando && (a.estado === 'trabajando' || a.estado === 'libre')
        const [sx, sy] = this.iso(p.x, p.y, sentado ? 5 : 0)
        const r = this.pintor(sx, sy)
        const parpadeo = ! this.quieto && Math.floor(this.t * 2 + p.look.fase) % 9 === 0

        if (sentado) {
            const teclea = a.estado === 'trabajando' && ! this.quieto ? Math.floor(this.t * 8 + p.look.fase) % 2 : 0
            sentadoEspalda(r, p.look, { teclea })
            p.caja = { x0: sx - 12, y0: sy - 34, x1: sx + 12, y1: sy + 4 }
            p.cabeza = { sx, sy: sy - 32 }
            return
        }

        const paso = p.caminando && ! this.quieto ? Math.floor(this.t * 8 + p.look.fase) % 2 : 0
        const pose = {
            caminando: p.caminando, paso, parpadeo,
            mano: a.estado === 'aprobacion' && ! p.caminando,
            saluda: ! this.quieto && Math.floor(this.t * 3 + p.look.fase) % 2 === 0,
            papel: a.estado === 'aprobacion',
            taza: a.estado === 'terminado' && ! p.caminando,
            cara: a.estado === 'error' ? 'triste' : a.estado === 'terminado' ? 'feliz' : 'normal',
        }

        if (p.caminando && p.mira === 'espalda') dePieEspalda(r, p.look, pose)
        else dePie(r, p.look, pose)

        p.caja = { x0: sx - 12, y0: sy - 50, x1: sx + 12, y1: sy + 4 }
        p.cabeza = { sx, sy: sy - 50 }
    }

    directorEnSilla() {
        const [sx, sy] = this.iso(2.15, 2.35, 6)
        sentadoFrente(this.pintor(sx, sy), LOOK_DIRECTOR, { cara: 'feliz', parpadeo: ! this.quieto && Math.floor(this.t * 2) % 11 === 0 })
        this.dir = { sx, sy: sy - 33 }
    }

    guiaEnConsola() {
        const g = this.plano.sitios.guia
        const [sx, sy] = this.iso(g.x, g.y)
        const b = this.bctx

        // Aura en el piso
        if (! this.quieto) {
            const pulso = (Math.sin(this.t * 3) + 1) / 2
            b.globalAlpha = 0.25 + pulso * 0.25
        } else {
            b.globalAlpha = 0.35
        }
        b.fillStyle = '#22d3ee'
        b.fillRect(sx - 14, sy - 2, 28, 1); b.fillRect(sx - 16, sy - 1, 32, 2); b.fillRect(sx - 14, sy + 1, 28, 1)
        b.globalAlpha = 1

        dePie(this.pintor(sx, sy), LOOK_GUIA, {
            tableta: true, cara: 'feliz', parpadeo: ! this.quieto && Math.floor(this.t * 2) % 13 === 0,
        })
        this.guiaPunto = { sx, sy: sy - 50 }
    }

    efectos(b) {
        // El holograma sobre la consola
        const c = this.plano.control
        const [hx, hy] = this.iso(c.x, this.plano.H - 2.6, 30)
        const ancho = 64, alto = 34
        b.globalAlpha = 0.18
        b.fillStyle = '#22d3ee'
        b.fillRect(hx - ancho / 2, hy - alto - 26, ancho, alto)
        b.globalAlpha = 0.85
        b.fillStyle = '#67e8f9'
        b.fillRect(hx - ancho / 2, hy - alto - 26, ancho, 1)
        b.fillRect(hx - ancho / 2, hy - 27, ancho, 1)
        b.fillRect(hx - ancho / 2, hy - alto - 26, 1, alto)
        b.fillRect(hx + ancho / 2 - 1, hy - alto - 26, 1, alto)
        // Barras: una por agente, la altura dice qué tan ocupado está
        const n = Math.max(1, this.agentes.length)
        const anchoBarra = Math.max(2, Math.floor((ancho - 10) / n) - 2)
        this.agentes.forEach((a, i) => {
            const valor = { trabajando: 0.9, aprobacion: 0.7, error: 0.35, terminado: 0.55, libre: 0.25, apagado: 0.08 }[a.estado] ?? 0.2
            const oscila = this.quieto ? 0 : Math.sin(this.t * 4 + i) * (a.estado === 'trabajando' ? 0.08 : 0)
            const h = Math.round((alto - 10) * Math.min(1, valor + oscila))
            b.fillStyle = { trabajando: '#4ade80', aprobacion: '#fb923c', error: '#f87171' }[a.estado] ?? '#a5f3fc'
            b.fillRect(hx - ancho / 2 + 5 + i * (anchoBarra + 2), hy - 31 - h, anchoBarra, h)
        })
        b.globalAlpha = 1
        // Haz de luz de la consola al holograma
        b.globalAlpha = 0.12
        poligono(b, [[hx - 6, hy - 4], [hx + 6, hy - 4], [hx + ancho / 2, hy - 26], [hx - ancho / 2, hy - 26]], '#22d3ee')
        b.globalAlpha = 1

        // Los rayos de la guía hacia cada agente que trabaja, y hacia el director si hay algo por aprobar
        if (this.guiaPunto) {
            const origen = [this.guiaPunto.sx, this.guiaPunto.sy + 10]
            for (const a of this.agentes) {
                const p = this.personajes.get(a.id)
                if (a.estado === 'trabajando' && p?.cabeza) this.rayo(origen, [p.cabeza.sx, p.cabeza.sy + 6])
            }
            if (this.agentes.some(a => a.estado === 'aprobacion') && this.dir) this.rayo(origen, [this.dir.sx, this.dir.sy + 6], '#fdba74')
        }

        // Humo sobre el servidor cuando alguien falló
        if (! this.quieto && this.agentes.some(a => a.estado === 'error')) {
            const m = this.plano.muebles.find(x => x.tipo === 'servidor')
            const [sx, sy] = this.iso(m.x + m.w / 2, m.y + m.d / 2, m.h)
            for (let k = 0; k < 4; k++) {
                const fase = (this.t * 0.7 + k / 4) % 1
                const radio = 3 + Math.round(fase * 6)
                b.fillStyle = `rgba(150,150,160,${0.8 - fase * 0.8})`
                b.fillRect(sx - radio + (k - 1.5) * 5, Math.round(sy - 6 - fase * 34) - radio, radio * 2, radio * 2)
            }
        }
    }

    // ─── Textos ───────────────────────────────────────────────────────────────

    textos() {
        const s = this.escala
        const k = this.dpr
        const { W, X0 } = this.plano

        if (this.empresa) {
            const [ex, ey] = this.iso((X0 + W) / 2, 0, ALTO_PARED - 4)
            this.letrero(ex * s, ey * s, this.empresa.toUpperCase(), { fuente: this.fuente(14), fondo: 'rgba(15,23,42,0.9)', texto: '#fde68a', borde: '#fbbf24' })
        }

        const [ox, oy] = this.iso(0, 4.6, 54)
        this.letrero(ox * s, oy * s, 'OFICINA DEL DIRECTOR', { fuente: this.fuente(9), fondo: 'rgba(30,41,59,0.88)', texto: '#e2e8f0', borde: '#475569' })

        if (this.dir) {
            this.tocable(0, 'director', this.dir.sx - 12, this.dir.sy - 4, this.dir.sx + 12, this.dir.sy + 30)
            if (this.director) {
                this.letrero(this.dir.sx * s, this.dir.sy * s - 6 * k, this.director.toUpperCase(), { fuente: this.fuente(10), fondo: 'rgba(15,23,42,0.9)', texto: '#e2e8f0', borde: '#64748b' })
            }
        }

        // La guía
        if (this.guiaPunto) {
            const gx = this.guiaPunto.sx * s
            let gy = this.guiaPunto.sy * s - 6 * k
            const elegida = this.elegido === 'guia'
            this.tocable('guia', 'guia', this.guiaPunto.sx - 14, this.guiaPunto.sy - 6, this.guiaPunto.sx + 14, this.guiaPunto.sy + 54)
            gy = this.letrero(gx, gy, `${recortar(this.guia.nombre, 18).toUpperCase()} · AGENTE PRINCIPAL`, {
                fuente: this.fuente(10), fondo: 'rgba(30,27,75,0.92)', texto: '#a5f3fc', borde: elegida ? '#facc15' : '#22d3ee',
            })
            const trabajando = this.agentes.filter(a => a.estado === 'trabajando').length
            const pendientes = this.agentes.reduce((n, a) => n + (a.pendientes || 0), 0)
            const dice = this.guia.ocupada
                ? 'Atendiendo una consulta'
                : trabajando || pendientes
                    ? `Coordinando: ${trabajando} trabajando${pendientes ? ` · ${pendientes} por aprobar` : ''}`
                    : `Coordinando a ${this.agentes.length} agentes`
            this.globo(gx, gy - 3 * k, dice, this.fuente(10, 600))
        }

        const ETIQUETA = {
            aprobacion: ['ESPERANDO APROBACIÓN', '#fdba74', '#ef4444'],
            error: ['CON ERROR', '#fca5a5', '#ef4444'],
            terminado: ['LISTO', '#86efac', '#22c55e'],
            libre: ['DISPONIBLE', '#93c5fd', '#3b82f6'],
            trabajando: ['TRABAJANDO', '#86efac', '#22c55e'],
        }

        this.agentes.forEach((a, i) => {
            const p = this.personajes.get(a.id)
            const elegido = this.elegido === a.id

            if (a.estado === 'apagado' || ! p?.cabeza) {
                const { asiento } = this.plano.puestos[i] ?? {}
                if (! asiento) return
                const [sx, sy] = this.iso(asiento.x, asiento.y, 5)
                this.tocable(a.id, 'agente', sx - 20, sy - 40, sx + 20, sy + 8)
                const [lx, ly] = this.iso(asiento.x, asiento.y - 0.4, 44)
                this.letrero(lx * s, ly * s, recortar(a.nombre, 14).toUpperCase() + ' · ZZZ', {
                    fuente: this.fuente(9), fondo: 'rgba(15,23,42,0.72)', texto: '#94a3b8', borde: elegido ? '#facc15' : '#334155',
                })
                return
            }

            this.tocable(a.id, 'agente', p.caja.x0, p.caja.y0, p.caja.x1, p.caja.y1)
            const x = p.cabeza.sx * s
            let y = p.cabeza.sy * s - 6 * k

            y = this.letrero(x, y, recortar(a.nombre, elegido ? 26 : 16).toUpperCase(), {
                fuente: this.fuente(10), fondo: 'rgba(15,23,42,0.9)', texto: '#a7f3d0', borde: elegido ? '#facc15' : '#334155',
            })
            const [texto, color, esquina] = ETIQUETA[a.estado] ?? ETIQUETA.libre
            y = this.letrero(x, y - 3 * k, texto, { fuente: this.fuente(9), fondo: 'rgba(2,6,23,0.86)', texto: color, borde: esquina, esquinas: a.estado === 'aprobacion' || a.estado === 'error' })

            if (a.estado === 'trabajando' && a.actual?.accion) {
                this.globo(x, y - 4 * k, recortar(a.actual.accion, elegido ? 60 : 30))
            }
        })
    }
}
