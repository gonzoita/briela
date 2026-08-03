/**
 * Color de marca del cliente, ya resuelto a un valor real.
 *
 * Casi toda la interfaz usa `var(--marca)` directamente en CSS. Esto es para lo
 * que no puede: los QR y las etiquetas se dibujan en canvas (bitmap, sin CSS),
 * la barra de carga de Inertia necesita un color literal, y los selectores de
 * color arrancan con un valor concreto.
 *
 * El valor sale del `:root` que imprime app.blade.php a partir de la
 * configuración de la instalación, así que esto devuelve el color de ESE
 * cliente. El respaldo solo aplica si la variable no llegó a imprimirse.
 */
export const COLOR_MARCA_RESPALDO = '#2563EB';

export function colorMarca(porDefecto = COLOR_MARCA_RESPALDO) {
    if (typeof document === 'undefined') return porDefecto;

    const valor = getComputedStyle(document.documentElement)
        .getPropertyValue('--marca')
        .trim();

    return valor || porDefecto;
}
