import { ref } from 'vue'

/**
 * La conversación con el asistente, fuera del componente.
 *
 * Dos razones, y la segunda es la que importa:
 *
 * 1. `AsistenteBurbuja` vive dentro de `AppLayout`, que se destruye y se vuelve a
 *    montar en cada navegación. Con los mensajes adentro, cada clic del menú pedía
 *    `/api/asistente/historial` otra vez para volver a dibujar lo mismo.
 * 2. Ese historial solo se ve si alguien ABRE la burbuja. Pedirlo al montar era
 *    trabajo para el servidor y espera para el usuario a cambio de nada en la
 *    inmensa mayoría de las pantallas, donde nadie la abre.
 *
 * Ahora se pide la primera vez que se abre, y se queda.
 */
export const mensajes          = ref([])
export const cargandoHistorial = ref(false)

let pedido = null

/** Trae el historial una sola vez. Las llamadas siguientes esperan a la primera. */
export function asegurarHistorial() {
    if (pedido) return pedido

    cargandoHistorial.value = true

    pedido = fetch('/api/asistente/historial', {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    })
        .then(r => r.ok ? r.json() : null)
        .then(d => { if (d) mensajes.value = d.mensajes ?? [] })
        // Sin historial se puede seguir conversando: no vale la pena molestar.
        .catch(() => {})
        .finally(() => { cargandoHistorial.value = false })

    return pedido
}

/** Al borrar la conversación, la próxima apertura vuelve a preguntar. */
export function olvidarHistorial() {
    mensajes.value = []
    pedido = null
}
