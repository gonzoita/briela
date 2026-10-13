/**
 * El SDK de Facebook para JavaScript y el flujo de Embedded Signup de WhatsApp.
 *
 * El SDK se carga **al oprimir el botón**, no al abrir la pantalla: es un script de terceros que
 * rastrea y pesa, y casi nadie conecta WhatsApp en una visita cualquiera. Se carga una sola vez
 * por pestaña (la promesa queda a nivel de módulo, no de componente).
 *
 * **Por qué dos cosas y no una.** Embedded Signup devuelve el `code` por el callback de
 * `FB.login`, y los identificadores de la cuenta (`waba_id`, `phone_number_id`) por un evento
 * `message` de la ventana, que puede llegar antes o después. Hay que esperar las dos.
 *
 * El `code` y los identificadores van al servidor, que canjea el código con el App Secret. Aquí
 * no se guarda nada ni se cree nada: lo que diga este evento lo puede fabricar cualquier script
 * de la página, y por eso el servidor lo contrasta con lo que Meta dice del token.
 */

let sdk = null
let sdkClave = ''

export function cargarSdk({ appId, version }) {
    const clave = `${appId}|${version}`

    if (sdk && sdkClave === clave) return sdk
    sdkClave = clave

    sdk = new Promise((resolver, rechazar) => {
        const iniciar = () => {
            window.FB.init({ appId, autoLogAppEvents: true, xfbml: false, version })
            resolver(window.FB)
        }

        if (window.FB) return iniciar()

        window.fbAsyncInit = iniciar

        const script = document.createElement('script')
        script.src = 'https://connect.facebook.net/es_LA/sdk.js'
        script.async = true
        script.defer = true
        script.crossOrigin = 'anonymous'
        script.onerror = () => {
            sdk = null
            rechazar(new Error('No se pudo cargar el SDK de Facebook. Revisa que ningún bloqueador de contenido lo esté frenando.'))
        }
        document.head.appendChild(script)
    })

    return sdk
}

const ES_DE_FACEBOOK = (origen) => {
    try {
        return new URL(origen).hostname.endsWith('facebook.com')
    } catch {
        return false
    }
}

/**
 * Abre la ventana de Embedded Signup.
 *
 * Devuelve `{ promesa, cancelar }`: `cancelar` quita la escucha de la ventana y se llama al
 * desmontar el componente, porque una escucha sin quitar sobrevive a la pantalla.
 *
 * @param {object}  opciones
 * @param {string}  opciones.appId
 * @param {string}  opciones.configId     la configuración de Embedded Signup (Inicio de sesión para empresas)
 * @param {string}  opciones.version      la misma versión de la Graph API que usa el servidor
 * @param {boolean} opciones.coexistencia vincular un número que sigue en la app WhatsApp Business
 * @returns {{ promesa: Promise<{code: string, waba_id: ?string, phone_number_id: ?string, coexistencia: boolean}>, cancelar: Function }}
 */
export function iniciarEmbeddedSignup({ appId, configId, version, coexistencia }) {
    let escucha = null
    let espera = null
    let limite = null

    const quitar = () => {
        if (escucha) window.removeEventListener('message', escucha)
        escucha = null
        clearTimeout(espera)
        clearTimeout(limite)
    }

    const promesa = new Promise((resolver, rechazar) => {
        let code = null
        let sesion = null
        let evento = null
        let cancelado = false

        const terminar = () => {
            quitar()

            if (cancelado || !code) {
                return rechazar(new Error('Se canceló la vinculación antes de terminar.'))
            }

            resolver({
                code,
                waba_id: sesion?.waba_id ?? null,
                phone_number_id: sesion?.phone_number_id ?? null,
                // El evento dice cómo se vinculó de verdad; lo elegido solo vale si no llegó.
                coexistencia: evento ? evento === 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING' : coexistencia,
            })
        }

        escucha = function alRecibirSesion(mensaje) {
            if (!ES_DE_FACEBOOK(mensaje.origin)) return

            let dato = mensaje.data
            try { if (typeof dato === 'string') dato = JSON.parse(dato) } catch { return }
            if (dato?.type !== 'WA_EMBEDDED_SIGNUP') return

            if (dato.event === 'CANCEL' || dato.event === 'ERROR') {
                cancelado = true
                return
            }

            evento = dato.event
            sesion = dato.data ?? {}

            // Si el código ya llegó, no hay nada más que esperar.
            if (code) terminar()
        }

        window.addEventListener('message', escucha)

        // Si nadie termina, no se deja la escucha puesta para siempre.
        limite = setTimeout(() => { cancelado = true; terminar() }, 10 * 60 * 1000)

        cargarSdk({ appId, version }).then((FB) => {
            FB.login((respuesta) => {
                code = respuesta?.authResponse?.code ?? null

                if (!code) {
                    cancelado = true
                    return terminar()
                }

                // El evento con los identificadores suele llegar antes; si todavía no, se espera
                // un momento y, si no llega, el servidor los averigua con el token.
                if (sesion) return terminar()
                espera = setTimeout(terminar, 4000)
            }, {
                config_id: configId,
                response_type: 'code',
                override_default_response_type: true,
                extras: {
                    setup: {},
                    sessionInfoVersion: '3',
                    // Sin esto la ventana solo ofrece crear una cuenta nueva en la API; con esto
                    // ofrece vincular el número que ya se usa en la app WhatsApp Business.
                    ...(coexistencia ? { featureType: 'whatsapp_business_app_onboarding' } : {}),
                },
            })
        }).catch((e) => { quitar(); rechazar(e) })
    })

    return { promesa, cancelar: quitar }
}
