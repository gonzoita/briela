/**
 * Las clases de color de cada familia, escritas completas.
 *
 * **Por qué un mapa y no `bg-pastel-${color}-2`.** Tailwind genera el CSS leyendo el código
 * fuente en busca de nombres de clase literales: una clase armada en tiempo de ejecución nunca
 * llega al bundle, y el elemento sale sin fondo. No es un error que se vea al escribirlo —el
 * build pasa, la pantalla carga—, solo que las insignias quedan transparentes. Es el mismo
 * patrón que ya usa la pantalla de Redes Sociales.
 *
 * Las seis familias del tema (ver `docs/manual/marca.md`). Cada una trae las cuatro clases que
 * se necesitan juntas: fondo de caja, fondo de insignia, borde y texto.
 */
export const FAMILIAS = {
    azul: {
        fondo: 'bg-pastel-azul', fondo2: 'bg-pastel-azul-2',
        borde: 'border-borde-aviso-azul', texto: 'text-aviso-azul',
    },
    verde: {
        fondo: 'bg-pastel-verde', fondo2: 'bg-pastel-verde-2',
        borde: 'border-borde-aviso-verde', texto: 'text-aviso-verde',
    },
    ambar: {
        fondo: 'bg-pastel-ambar', fondo2: 'bg-pastel-ambar-2',
        borde: 'border-borde-aviso-ambar', texto: 'text-aviso-ambar',
    },
    rojo: {
        fondo: 'bg-pastel-rojo', fondo2: 'bg-pastel-rojo-2',
        borde: 'border-borde-aviso-rojo', texto: 'text-aviso-rojo',
    },
    violeta: {
        fondo: 'bg-pastel-violeta', fondo2: 'bg-pastel-violeta-2',
        borde: 'border-borde-aviso-violeta', texto: 'text-aviso-violeta',
    },
    naranja: {
        fondo: 'bg-pastel-naranja', fondo2: 'bg-pastel-naranja-2',
        borde: 'border-borde-aviso-naranja', texto: 'text-aviso-naranja',
    },
}

/**
 * Las clases de una familia. Una familia que no exista cae en azul, que es neutra: un canal
 * nuevo mal configurado tiene que verse raro, no invisible.
 */
export function familia(nombre) {
    return FAMILIAS[nombre] ?? FAMILIAS.azul
}

/** La insignia de un canal: el fondo-2 y su texto, que es como se pintan todas. */
export function insignia(nombre) {
    const f = familia(nombre)

    return `${f.fondo2} ${f.texto}`
}
