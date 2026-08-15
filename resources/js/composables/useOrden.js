import { computed } from 'vue'
import { router } from '@inertiajs/vue3'

/**
 * El orden de una lista, desde la pantalla.
 *
 * Vive aparte porque son dieciséis listas y la lógica es la misma en todas: clic en un
 * encabezado ordena por ese campo; clic otra vez le da la vuelta. Escribirlo en cada pantalla
 * era garantizar que dieciséis se comportaran de quince maneras.
 *
 * El orden viaja en la URL, no en memoria: así un enlace a «productos por nombre» se puede
 * compartir, el botón de atrás funciona, y recargar no pierde lo que la persona eligió.
 *
 * @param {string} url        La ruta de la lista, sin parámetros.
 * @param {object} orden      Lo que devolvió `Orden::aplicar()`: { campo, dir }.
 * @param {object} filtros    Los filtros vigentes, para no perderlos al reordenar.
 */
export function useOrden(url, orden, filtros = {}) {
    const campoActual = computed(() => orden?.campo ?? null)
    const dirActual   = computed(() => orden?.dir ?? 'desc')

    /** Cómo está ordenado un campo ahora: 'asc', 'desc', o null si no es el activo. */
    function estadoDe(campo) {
        return campoActual.value === campo ? dirActual.value : null
    }

    function ordenarPor(campo) {
        // El mismo campo dos veces le da la vuelta. Uno nuevo arranca con la dirección que el
        // servidor considere natural, así que no se manda `dir` y él decide.
        const mismo = campoActual.value === campo
        const dir   = mismo ? (dirActual.value === 'asc' ? 'desc' : 'asc') : null

        const datos = { ...limpiar(filtros), orden: campo }

        if (dir) datos.dir = dir

        router.get(url, datos, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        })
    }

    return { campoActual, dirActual, estadoDe, ordenarPor }
}

/** Los filtros vacíos no van en la URL: la ensucian y no filtran nada. */
function limpiar(filtros) {
    return Object.fromEntries(
        Object.entries(filtros ?? {}).filter(([, v]) => v !== null && v !== undefined && v !== '')
    )
}
