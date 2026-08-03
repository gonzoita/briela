import { computed } from 'vue'

export const TIPOS_PUERTA = [
    { value: 'BATIENTE_SIMPLE',       label: 'Batiente Simple'        },
    { value: 'BATIENTE_DOBLE',        label: 'Batiente Doble'         },
    { value: 'VAIVEN',                label: 'Vaivén Simple'          },
    { value: 'VAIVEN_DOBLE',          label: 'Vaivén Doble'           },
    { value: 'INSTITUCIONAL',         label: 'Institucional Simple'   },
    { value: 'INSTITUCIONAL_DOBLE',   label: 'Institucional Doble'    },
    { value: 'EMERGENCIA',            label: 'Emergencia Simple'       },
    { value: 'EMERGENCIA_DOBLE',      label: 'Emergencia Doble'       },
    { value: 'CORREDERA',             label: 'Corredera Simple'       },
]

export const NOMBRES_LAMINAS = [
    'LAMINA PREPINTADA',
    'LAMINA DE ACERO 430',
    'LAMINA DE ACERO 304',
    'LAMINA PVC',
    'LAMINA DE ALFAJOR',
]

export const VERSIONES_LAMINA = [
    ['LAMINA PREPINTADA'],
    ['LAMINA PREPINTADA', 'LAMINA DE ALFAJOR'],
    ['LAMINA DE ACERO 430'],
    ['LAMINA DE ACERO 430', 'LAMINA DE ALFAJOR'],
    ['LAMINA DE ACERO 304'],
    ['LAMINA DE ACERO 304', 'LAMINA DE ALFAJOR'],
    ['LAMINA PVC'],
    ['LAMINA PVC', 'LAMINA DE ALFAJOR'],
]

export function redondearCincoMil(v) {
    return Math.ceil(v / 5000) * 5000
}

export function useCalculadorPuertas(params, insumos) {
    const esDoble     = computed(() => params.tipo_puerta.includes('DOBLE'))
    const esCorredera = computed(() => params.tipo_puerta === 'CORREDERA')
    const esVaiven    = computed(() => params.tipo_puerta.startsWith('VAIVEN'))
    const esInstEmerg = computed(() =>
        params.tipo_puerta.startsWith('INSTITUCIONAL') || params.tipo_puerta.startsWith('EMERGENCIA')
    )
    const esBatiente  = computed(() => params.tipo_puerta.startsWith('BATIENTE'))

    const columnaActiva = computed(() => {
        const t = params.tipo_puerta
        const s = params.tipo_sello === 'SUELO' ? 'SUELO' : 'TOPE'
        const c = params.tipo_corredera

        if (t === 'BATIENTE_SIMPLE')     return s === 'SUELO' ? 'M_SUELO'   : 'M_TOPE'
        if (t === 'BATIENTE_DOBLE')      return s === 'SUELO' ? 'M_SUELO_D' : 'M_TOPE_D'
        if (t === 'VAIVEN')              return 'VAIVEN'
        if (t === 'VAIVEN_DOBLE')        return 'VAIVEN_D'
        if (t === 'INSTITUCIONAL')       return 'INST'
        if (t === 'INSTITUCIONAL_DOBLE') return 'INST_D'
        if (t === 'EMERGENCIA')          return 'INST'
        if (t === 'EMERGENCIA_DOBLE')    return 'INST_D'
        if (t === 'CORREDERA')           return c === 'SE12' ? 'SE12' : c === 'SM20' ? 'SM20' : 'P480'
        return 'M_SUELO'
    })

    const errores = computed(() => {
        const e = []
        const a = parseFloat(params.ancho_vano)
        const h = parseFloat(params.alto_vano)
        if (isNaN(a) || a <= 0) e.push('Ancho de vano inválido.')
        if (isNaN(h) || h <= 0) e.push('Alto de vano inválido.')
        if (h > 3.0) e.push('Alto máximo es 3.00 m.')
        if (esBatiente.value && !esDoble.value && a > 1.30) e.push('Ancho máx para puerta simple: 1.30 m.')
        if (esDoble.value && a > 2.60) e.push('Ancho máx para puerta doble: 2.60 m.')
        if (params.tipo_puerta === 'BATIENTE_DOBLE' && params.temperatura === 'BAJA') {
            e.push('Batiente Doble no admite temperatura BAJA.')
        }
        return e
    })

    const desglose = computed(() => {
        if (errores.value.length > 0) return []

        const col   = columnaActiva.value
        const sello = params.tipo_sello

        const lineas = []

        for (const ins of insumos) {
            const formula = ins.formulas?.[col]
            if (!formula || formula.cantidad === 0) continue

            const nombre = ins.nombre

            if (nombre === 'PERFIL PERIMETRAL 70MM' && params.temperatura !== 'MEDIA') continue
            if (nombre === 'PERFIL PERIMETRAL 92MM' && params.temperatura !== 'BAJA')  continue

            if (NOMBRES_LAMINAS.includes(nombre)) continue

            lineas.push({
                id:          ins.id,
                nombre,
                unidad:      ins.unidad,
                cantidad:    formula.cantidad,
                precio_unit: ins.precio_costo,
                subtotal:    formula.cantidad * ins.precio_costo,
            })
        }

        if (params.palanca) {
            const ins = insumos.find(i => i.nombre === 'PALANCA DE MANO 8100')
            if (ins) lineas.push({ id: ins.id, nombre: ins.nombre, unidad: 'UN', cantidad: 1, precio_unit: ins.precio_costo, subtotal: ins.precio_costo })
        }

        if (esInstEmerg.value) {
            const visor40 = insumos.find(i => i.nombre === 'VISOR 40MM')
            if (visor40) lineas.push({ id: visor40.id, nombre: visor40.nombre, unidad: 'UN', cantidad: esDoble.value ? 2 : 1, precio_unit: visor40.precio_costo, subtotal: visor40.precio_costo * (esDoble.value ? 2 : 1) })
        } else if (params.visor && params.temperatura === 'MEDIA') {
            const visor80 = insumos.find(i => i.nombre === 'VISOR 80MM')
            if (visor80) lineas.push({ id: visor80.id, nombre: visor80.nombre, unidad: 'UN', cantidad: 1, precio_unit: visor80.precio_costo, subtotal: visor80.precio_costo })
        }

        if (!params.sin_llave && esBatiente.value) {
            let nombreCerr = null
            if (!esDoble.value && params.temperatura === 'MEDIA') nombreCerr = 'CERRADURA SERIE 5000'
            else if (!esDoble.value && params.temperatura === 'BAJA')  nombreCerr = 'CERRADURA SERIE 7000'
            else if (esDoble.value) nombreCerr = 'CERRADURA SERIE 1800'
            if (nombreCerr) {
                const cerr = insumos.find(i => i.nombre === nombreCerr)
                if (cerr) lineas.push({ id: cerr.id, nombre: cerr.nombre, unidad: 'UN', cantidad: 1, precio_unit: cerr.precio_costo, subtotal: cerr.precio_costo })
            }
        }

        return lineas
    })

    const subtotalComun = computed(() => desglose.value.reduce((s, l) => s + l.subtotal, 0))

    const versionesLamina = computed(() => {
        const col = columnaActiva.value
        return VERSIONES_LAMINA.map(grupo => {
            const label = grupo.join(' + ')
            let costoLamina = 0
            for (const nombre of grupo) {
                const ins = insumos.find(i => i.nombre === nombre)
                if (!ins) continue
                const formula = ins.formulas?.[col]
                const cant = formula?.cantidad ?? 0
                costoLamina += cant * ins.precio_costo
            }

            const costo        = subtotalComun.value + costoLamina
            const mayorista    = redondearCincoMil(costo / (1 - 0.30))
            const distribuidor = redondearCincoMil(costo / (1 - 0.325))
            const clienteFinal = redondearCincoMil(costo / (1 - 0.35))

            return { label, costo, mayorista, distribuidor, clienteFinal }
        })
    })

    return {
        esDoble, esCorredera, esVaiven, esInstEmerg, esBatiente,
        columnaActiva, errores, desglose, subtotalComun, versionesLamina,
    }
}
