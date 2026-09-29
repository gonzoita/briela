import { ref, watch, onUnmounted } from 'vue'

/**
 * Pide al servidor las retenciones de una cotización mientras se arma.
 *
 * La cuenta no se repite aquí: vive en RetencionesService, y dos copias de una regla
 * tributaria terminan diciendo cosas distintas. Se pide con una espera de medio segundo
 * desde el último cambio, para que escribir una cantidad no dispare una petición por tecla.
 *
 * @param {() => object} datos  devuelve { cliente_id, iva, items: [{ producto_id, base }] }
 */
export function useRetenciones(datos) {
    const retenciones = ref(null)
    const cargando    = ref(false)
    let espera        = null
    let ultima        = ''

    async function pedir(cuerpo) {
        cargando.value = true
        try {
            const xsrf = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))
            const res  = await fetch('/api/cotizaciones/estimar-retenciones', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': xsrf ? decodeURIComponent(xsrf.split('=')[1]) : '',
                },
                body: cuerpo,
            })
            // Si mientras tanto cambió algo, esta respuesta ya es vieja: se descarta.
            if (res.ok && cuerpo === ultima) retenciones.value = await res.json()
        } catch {
            // Sin conexión no se muestra nada: es una ayuda, no un paso obligatorio.
        } finally {
            if (cuerpo === ultima) cargando.value = false
        }
    }

    watch(() => JSON.stringify(datos()), (cuerpo) => {
        clearTimeout(espera)
        ultima = cuerpo

        const d = JSON.parse(cuerpo)
        if (! d.items?.length) { retenciones.value = null; return }

        espera = setTimeout(() => pedir(cuerpo), 500)
    }, { immediate: true })

    onUnmounted(() => clearTimeout(espera))

    return { retenciones, cargando }
}
