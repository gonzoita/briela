/**
 * La conversación con `/api/equipo-ia/*`, en un solo sitio.
 *
 * Devuelve siempre `{ ok, status, data }` y nunca lanza: una pantalla que se cae porque la red
 * parpadeó es peor que una que muestra «no se pudo». Los errores de validación de Laravel (422)
 * llegan en `data.errors`, y `mensajeDe()` los vuelve una frase para mostrar.
 */

const csrf = () => {
    const c = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))
    return c ? decodeURIComponent(c.split('=')[1]) : ''
}

export async function api(metodo, url, cuerpo = null) {
    try {
        const r = await fetch(url, {
            method: metodo,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': csrf(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: cuerpo === null ? undefined : JSON.stringify(cuerpo),
        })

        const data = await r.json().catch(() => null)

        return { ok: r.ok, status: r.status, data }
    } catch {
        return { ok: false, status: 0, data: { message: 'No se pudo conectar con el servidor.' } }
    }
}

export const get  = (url)         => api('GET', url)
export const post = (url, cuerpo) => api('POST', url, cuerpo ?? {})
export const put  = (url, cuerpo) => api('PUT', url, cuerpo ?? {})
export const del  = (url)         => api('DELETE', url)

/** Una frase para mostrarle a la persona, a partir de la respuesta de un error. */
export function mensajeDe(r, porDefecto = 'No se pudo completar la acción.') {
    if (!r) return porDefecto
    if (r.status === 403) return 'No tienes permiso para hacer esto.'
    if (r.status === 404) return 'Esto ya no existe, o el módulo Equipo de IA está apagado.'

    const errores = r.data?.errors
    if (errores) return Object.values(errores).flat().join(' ')

    return r.data?.message || r.data?.mensaje || porDefecto
}

/** La hora relativa en español, sin librerías: «hace 5 min». */
export function hace(iso) {
    if (!iso) return ''
    const s = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 1000))
    if (s < 60) return 'hace un momento'
    if (s < 3600) return `hace ${Math.round(s / 60)} min`
    if (s < 86400) return `hace ${Math.round(s / 3600)} h`
    return `hace ${Math.round(s / 86400)} d`
}

/** Cómo se ve cada estado de la oficina. Los colores son las familias del tema: se leen de día y de noche. */
export const ESTADOS = {
    libre:      { texto: 'Libre',                 caja: 'bg-pastel-azul border-borde-aviso-azul',       chip: 'bg-pastel-azul-2 text-aviso-azul',       icono: '○' },
    trabajando: { texto: 'Trabajando',            caja: 'bg-pastel-ambar border-borde-aviso-ambar',     chip: 'bg-pastel-ambar-2 text-aviso-ambar',     icono: '◔' },
    terminado:  { texto: 'Terminó',               caja: 'bg-pastel-verde border-borde-aviso-verde',     chip: 'bg-pastel-verde-2 text-aviso-verde',     icono: '✓' },
    aprobacion: { texto: 'Necesita aprobación',   caja: 'bg-pastel-naranja border-borde-aviso-naranja', chip: 'bg-pastel-naranja-2 text-aviso-naranja', icono: '!' },
    error:      { texto: 'Con error',             caja: 'bg-pastel-rojo border-borde-aviso-rojo',       chip: 'bg-pastel-rojo-2 text-aviso-rojo',       icono: '×' },
    apagado:    { texto: 'Apagado',               caja: 'bg-superficie border-linea',                   chip: 'bg-tinta-100 text-tinta-400',            icono: '–' },
}
