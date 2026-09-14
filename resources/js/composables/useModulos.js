import { usePage } from '@inertiajs/vue3'

/**
 * Si un módulo está encendido en esta instalación.
 *
 * Casi todo se decide por permisos —un módulo apagado se lleva los suyos—, pero hay pantallas
 * que cambian de forma sin que haya un permiso de por medio: el paso final no pide bodegas si
 * no se lleva inventario. Para esas, esto. La lista sale de `App\Support\Modulos::apagados()`.
 */
export function useModulos() {
    const page = usePage()

    const activo = (clave) => ! (page.props.modulosApagados ?? []).includes(clave)

    return { activo }
}
