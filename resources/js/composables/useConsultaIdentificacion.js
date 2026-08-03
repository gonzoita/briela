import { ref } from 'vue'

/**
 * Consulta de NIT / cédula para el formulario de clientes.
 *
 * Hace tres cosas de una sola pasada:
 *   - valida el dígito de verificación del NIT,
 *   - avisa si ese número ya existe como cliente,
 *   - trae la razón social del RUES cuando es una empresa.
 *
 * Todo es opcional: si la consulta falla, el formulario sigue igual de usable
 * y el usuario escribe a mano. Nunca bloquea el guardado.
 */
export function useConsultaIdentificacion() {
    const consultando = ref(false)
    const resultado   = ref(null)
    const error       = ref('')

    function limpiar() {
        resultado.value = null
        error.value     = ''
    }

    /**
     * @param {string} numero              lo que el usuario escribió
     * @param {string} tipoIdentificacion  CC, NIT, CE, PA o RUT
     * @param {number|null} ignorarId      el cliente que se está editando
     */
    async function consultar(numero, tipoIdentificacion, ignorarId = null) {
        const limpio = (numero || '').replace(/\D/g, '')

        // Menos de 5 dígitos no es una identificación todavía: no molestamos
        // al usuario con avisos mientras apenas va escribiendo.
        if (limpio.length < 5) {
            limpiar()
            return null
        }

        consultando.value = true
        error.value       = ''

        try {
            const params = new URLSearchParams({
                numero,
                tipo_identificacion: tipoIdentificacion,
            })
            if (ignorarId) params.append('ignorar_id', ignorarId)

            const resp = await fetch(`/clientes/consultar-identificacion?${params}`, {
                headers: { Accept: 'application/json' },
            })

            if (!resp.ok) throw new Error('No se pudo consultar')

            resultado.value = await resp.json()
            return resultado.value
        } catch (e) {
            // Que no se pueda consultar no es un problema del usuario.
            error.value     = 'No se pudo consultar en este momento. Puedes seguir escribiendo los datos a mano.'
            resultado.value = null
            return null
        } finally {
            consultando.value = false
        }
    }

    return { consultando, resultado, error, consultar, limpiar }
}
