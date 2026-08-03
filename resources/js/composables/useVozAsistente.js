/**
 * Voz para el asistente.
 *
 * - Dictado (voz → texto): Web Speech API del navegador. Gratis.
 * - Lectura (texto → voz): dos opciones.
 *     · Voz natural: se genera con IA. Suena humana, consume saldo.
 *     · Voz del navegador: gratis, más robótica. Es el respaldo.
 *
 * Las preferencias (qué voz, si lee sola, si usa la natural) se guardan por
 * usuario en el navegador, para que cada quien tenga la suya sin tocar la
 * configuración de la empresa.
 */
import { onUnmounted, reactive, ref, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { comoTextoPlano } from '@/utils/formatoMensaje'

/**
 * Voces femeninas en español que suelen traer Windows, Android y macOS.
 * La API del navegador no expone el género, así que se detectan por nombre.
 */
const VOCES_FEMENINAS = [
    'sabina', 'helena', 'laura', 'monica', 'mónica', 'paulina', 'esperanza',
    'elena', 'lucia', 'lucía', 'marisol', 'penelope', 'penélope', 'female', 'mujer',
]

/** Voces del generador por IA, con el género indicado. */
export const VOCES_IA = {
    nova:    'Nova — mujer, cálida y cercana',
    shimmer: 'Shimmer — mujer, suave',
    coral:   'Coral — mujer, expresiva',
    sage:    'Sage — mujer, serena',
    alloy:   'Alloy — neutra',
    echo:    'Echo — hombre, grave',
    onyx:    'Onyx — hombre, profunda',
    fable:   'Fable — hombre, narrativa',
}

const CLAVE_PREFS = 'sgi_asistente_voz'

/**
 * Lo único que decide cada usuario es si quiere escuchar las respuestas.
 * Qué voz tiene el asistente es parte de su identidad y se define en
 * Configuración, igual que su nombre.
 */
function cargarPrefs() {
    try {
        const guardado = JSON.parse(localStorage.getItem(CLAVE_PREFS) || '{}')
        return { leerAuto: guardado.leerAuto ?? false }
    } catch (e) {
        return { leerAuto: false }
    }
}

export function useVozAsistente() {
    const page = usePage()
    const Reconocimiento = window.SpeechRecognition || window.webkitSpeechRecognition

    // La empresa habilita la voz natural; el usuario decide si la usa.
    const vozNaturalDisponible = !!page.props.asistente?.voz_natural

    const soportaDictado = !!Reconocimiento
    const soportaVoz = typeof window.speechSynthesis !== 'undefined' || vozNaturalDisponible

    const prefs = reactive(cargarPrefs())

    watch(prefs, (v) => {
        try { localStorage.setItem(CLAVE_PREFS, JSON.stringify(v)) } catch (e) { /* modo privado */ }
    }, { deep: true })

    const dictando = ref(false)
    const hablando = ref(false)
    const errorVoz = ref('')

    let reconocedor = null
    let audio = null

    function getCookie(name) {
        const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'))
        return match ? decodeURIComponent(match[2]) : ''
    }

    // ─── Dictado ──────────────────────────────────────────────────────────────

    /**
     * @param {(texto: string, definitivo: boolean) => void} alReconocer
     */
    function iniciarDictado(alReconocer) {
        if (!soportaDictado || dictando.value) return

        errorVoz.value = ''
        reconocedor = new Reconocimiento()
        reconocedor.lang = 'es-CO'
        reconocedor.continuous = false
        reconocedor.interimResults = true

        reconocedor.onresult = (evento) => {
            let texto = ''
            let definitivo = false

            for (let i = evento.resultIndex; i < evento.results.length; i++) {
                texto += evento.results[i][0].transcript
                if (evento.results[i].isFinal) definitivo = true
            }

            alReconocer(texto, definitivo)
        }

        reconocedor.onerror = (e) => {
            errorVoz.value = e.error === 'not-allowed'
                ? 'No diste permiso para usar el micrófono.'
                : 'No se pudo escuchar. Intenta de nuevo.'
            dictando.value = false
        }

        reconocedor.onend = () => { dictando.value = false }

        try {
            reconocedor.start()
            dictando.value = true
        } catch (e) {
            errorVoz.value = 'No se pudo iniciar el micrófono.'
        }
    }

    function detenerDictado() {
        try { reconocedor?.stop() } catch (e) { /* ya estaba detenido */ }
        dictando.value = false
    }

    // ─── Lectura ──────────────────────────────────────────────────────────────

    async function hablar(texto) {
        detenerVoz()
        errorVoz.value = ''

        const plano = comoTextoPlano(texto)
        if (!plano) return

        if (vozNaturalDisponible) {
            const { ok, error } = await hablarNatural(plano)
            if (ok) return

            errorVoz.value = error || 'La voz natural falló; se usó la del navegador.'
        }

        hablarNavegador(plano)
    }

    /**
     * Voz generada por IA: natural, consume saldo.
     *
     * Se recorta el texto porque generar audio de una respuesta muy larga tarda
     * más de lo que aguanta el servidor y termina fallando. Un informe completo
     * se lee igual: lo importante va al principio.
     */
    async function hablarNatural(texto) {
        try {
            hablando.value = true

            const resp = await fetch('/api/asistente/voz', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': getCookie('XSRF-TOKEN'),
                },
                credentials: 'same-origin',
                // Sin 'voz': el servidor usa la que tenga configurada el asistente.
                body: JSON.stringify({ texto: recortar(texto, 1200) }),
            })

            if (!resp.ok) {
                hablando.value = false

                // Se muestra el motivo real en vez de un fallo mudo.
                let motivo = ''
                try { motivo = (await resp.json())?.error ?? '' } catch (e) { /* no era JSON */ }

                return { ok: false, error: motivo }
            }

            const blob = await resp.blob()
            audio = new Audio(URL.createObjectURL(blob))
            audio.onended = () => { hablando.value = false }
            audio.onerror = () => { hablando.value = false }
            await audio.play()

            return { ok: true }
        } catch (e) {
            hablando.value = false
            return { ok: false, error: '' }
        }
    }

    /** Corta en el punto más cercano para no dejar la frase a medias. */
    function recortar(texto, limite) {
        if (texto.length <= limite) return texto

        const trozo = texto.slice(0, limite)
        const corte = trozo.lastIndexOf('. ')

        return corte > limite * 0.5 ? trozo.slice(0, corte + 1) : trozo
    }

    /** Voz del sistema operativo: gratis, más robótica. */
    function hablarNavegador(texto) {
        if (typeof window.speechSynthesis === 'undefined') return

        const mensaje = new SpeechSynthesisUtterance(texto)
        mensaje.lang = 'es-CO'
        mensaje.rate = 1.05
        mensaje.pitch = 1.1   // un poco más agudo: suena menos plano

        const voces = window.speechSynthesis.getVoices()
            .filter(v => v.lang?.toLowerCase().startsWith('es'))

        const femenina = voces.find(v => VOCES_FEMENINAS.some(n => v.name.toLowerCase().includes(n)))
        const elegida  = femenina || voces[0]
        if (elegida) mensaje.voice = elegida

        mensaje.onend   = () => { hablando.value = false }
        mensaje.onerror = () => { hablando.value = false }

        hablando.value = true
        window.speechSynthesis.speak(mensaje)
    }

    function detenerVoz() {
        if (typeof window.speechSynthesis !== 'undefined') window.speechSynthesis.cancel()

        if (audio) {
            audio.pause()
            audio = null
        }

        hablando.value = false
    }

    // Al cerrar el chat o cambiar de página no debe quedar hablando.
    onUnmounted(() => {
        detenerDictado()
        detenerVoz()
    })

    return {
        soportaDictado, soportaVoz, vozNaturalDisponible,
        prefs,
        dictando, hablando, errorVoz,
        iniciarDictado, detenerDictado,
        hablar, detenerVoz,
    }
}
