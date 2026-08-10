import { ref, computed, watch } from 'vue'

/**
 * Modo día, modo noche y automático.
 *
 * El automático **no usa la hora del computador**, usa la de la sede: en una
 * empresa con sedes en husos distintos, el turno de la noche empieza a horas
 * distintas, y quien mira el sistema desde un portátil configurado en otro huso
 * vería el tema equivocado. El servidor manda la hora de la sede activa en cada
 * página y esa es la que decide.
 *
 * El tema se aplica en el atributo data-tema del <html>, que es donde el CSS de
 * App\Support\Marca busca los colores del modo oscuro. Cambiarlo es instantáneo:
 * no recarga ni pide nada al servidor.
 */

const CLAVE = 'briela.tema'
const NOCHE_DESDE = 19   // 7 de la noche
const NOCHE_HASTA = 6    // hasta las 6:59

// 'claro' | 'oscuro' | 'automatico'
const preferencia = ref(localStorage.getItem(CLAVE) ?? 'automatico')

// La hora de la sede, que inyecta el servidor. Si no llegó, la del navegador.
const horaSede = ref(null)

function esDeNoche(hora) {
    return hora >= NOCHE_DESDE || hora <= NOCHE_HASTA
}

/** El tema que se está aplicando de verdad, ya resuelto el automático. */
const temaEfectivo = computed(() => {
    if (preferencia.value !== 'automatico') return preferencia.value

    const hora = horaSede.value ?? new Date().getHours()

    return esDeNoche(hora) ? 'oscuro' : 'claro'
})

function aplicar() {
    document.documentElement.setAttribute('data-tema', temaEfectivo.value)
}

watch(temaEfectivo, aplicar, { immediate: true })

export function useTema() {
    return {
        preferencia,
        temaEfectivo,

        opciones: [
            { valor: 'claro',      etiqueta: 'Día',        icono: 'sol' },
            { valor: 'oscuro',     etiqueta: 'Noche',      icono: 'luna' },
            { valor: 'automatico', etiqueta: 'Automático', icono: 'reloj' },
        ],

        elegir(valor) {
            preferencia.value = valor
            localStorage.setItem(CLAVE, valor)
            aplicar()
        },

        /** La llama el layout con la hora que envía el servidor. */
        fijarHoraSede(hora) {
            if (typeof hora === 'number' && hora >= 0 && hora <= 23) {
                horaSede.value = hora
            }
        },

        /** Para explicar en la interfaz por qué el automático eligió lo que eligió. */
        explicacionAutomatico: computed(() => {
            const hora = horaSede.value
            if (hora === null) return 'Según la hora de tu dispositivo'

            return `Según la hora de la sede (${String(hora).padStart(2, '0')}:00)`
        }),
    }
}
