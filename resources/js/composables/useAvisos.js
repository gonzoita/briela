import { computed, ref } from 'vue'

/**
 * Los contadores del encabezado —campanita, chat y pendientes— en un solo sitio.
 *
 * Viven FUERA de los componentes, a propósito.
 *
 * `AppLayout` no es un layout persistente de Inertia: las 110 pantallas lo escriben
 * en su plantilla, así que cada clic del menú lo destruye y lo vuelve a montar. Con
 * el estado dentro del componente, ese remontaje volvía a pedir al servidor lo mismo
 * que acababa de recibir: `/notificaciones`, `/api/comentarios/pendientes`,
 * `/api/chat/conversaciones`, `/api/chat/grupos` y el historial del asistente. Seis
 * peticiones por navegación que, con las sesiones en archivo, Laravel además atiende
 * EN FILA porque cada una espera el candado de la sesión.
 *
 * Al vivir en el módulo, el dato ya está ahí cuando el layout vuelve a montarse: la
 * campanita aparece con su número puesto, sin parpadeo y sin pedir nada.
 */

export const notificaciones = ref([])
export const notifNoLeidas  = ref(0)
export const pendientes     = ref([])
export const conversaciones = ref([])
export const grupos         = ref([])

/**
 * El número rojo del botón flotante.
 *
 * Vive aquí y no dentro de `ChatBurbuja` porque el botón tiene que poder mostrarlo
 * SIN que el chat esté montado: si el contador saliera del componente, habría que
 * construir sus 600 líneas en cada navegación solo para saber si hay un 3.
 */
export const sinLeerTotal = computed(() =>
    pendientes.value.length
    + conversaciones.value.reduce((s, c) => s + (c.sin_leer || 0), 0)
    + grupos.value.reduce((s, g) => s + (g.sin_leer || 0), 0)
)

const CADA = 60000

let arrancado = false
let timer     = null
let ultimo    = 0

async function pedir(url) {
    try {
        const r = await fetch(url, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
        return r.ok ? await r.json() : null
    } catch {
        // Silencioso a propósito: un contador que no llega no es motivo para
        // interrumpir a quien está trabajando.
        return null
    }
}

export async function cargarNotificaciones() {
    const d = await pedir('/notificaciones')
    if (! d) return
    notificaciones.value = d.notificaciones ?? []
    notifNoLeidas.value  = d.no_leidas ?? 0
}

export async function cargarPendientes() {
    const d = await pedir('/api/comentarios/pendientes')
    if (d) pendientes.value = d.pendientes ?? []
}

export async function cargarConversaciones() {
    const d = await pedir('/api/chat/conversaciones')
    if (d) conversaciones.value = d.conversaciones ?? []
}

export async function cargarGrupos() {
    const d = await pedir('/api/chat/grupos')
    if (d) grupos.value = d.grupos ?? []
}

export function refrescarAvisos() {
    ultimo = Date.now()

    return Promise.all([
        cargarNotificaciones(),
        cargarPendientes(),
        cargarConversaciones(),
        cargarGrupos(),
    ])
}

/**
 * Refresca solo si hace rato de la última vez.
 *
 * Para llamar al cambiar de pantalla sin volver a lo de antes: quien recorre seis
 * pantallas seguidas buscando algo no necesita cuatro consultas de contadores en
 * cada una, y quien deja la pestaña quieta media hora sí quiere el número al día
 * cuando vuelve a moverse.
 */
export function refrescarSiHaceRato(ms = 30000) {
    if (Date.now() - ultimo >= ms) refrescarAvisos()
}

/**
 * Arranca el refresco. Se puede llamar en cada montaje del layout: solo la primera
 * llamada hace algo.
 *
 * El temporizador no se apaga al desmontar. Antes sí —`onUnmounted` limpiaba el
 * suyo—, pero eso solo tenía sentido porque cada componente traía el suyo propio;
 * aquí hay UNO para toda la aplicación, y mientras la aplicación esté abierta hay un
 * encabezado que lo necesita. Al cerrar sesión la página se recarga entera y el
 * módulo muere con ella.
 */
export function iniciarAvisos() {
    if (arrancado) return
    arrancado = true

    refrescarAvisos()
    timer = setInterval(refrescarAvisos, CADA)

    // Una pestaña en segundo plano no necesita contadores frescos, y el navegador
    // acumula los temporizadores dormidos para dispararlos todos juntos al volver.
    //
    // Al volver se refresca CON FRENO, no siempre: `visibilitychange` se dispara con
    // cada cambio de ventana, y quien alterna entre el sistema y el correo lo dispara
    // decenas de veces por hora. Sin el freno, volver a la pestaña costaba cuatro
    // consultas aunque hubieran pasado dos segundos.
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            clearInterval(timer)
            timer = null
        } else if (! timer) {
            refrescarSiHaceRato()
            timer = setInterval(refrescarAvisos, CADA)
        }
    })
}

/**
 * Marca algo como ya hecho para toda la sesión.
 *
 * Existe porque una variable de `<script setup>` NO sirve para esto: vive dentro de
 * la función `setup`, así que nace de nuevo con cada instancia del componente —y el
 * layout se instancia otra vez en cada navegación—. Lo que tiene que pasar una sola
 * vez mientras la pestaña esté abierta se recuerda aquí, en el módulo.
 */
const hechos = new Set()

export function soloUnaVez(clave) {
    if (hechos.has(clave)) return false
    hechos.add(clave)
    return true
}
