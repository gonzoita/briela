import { ref, computed } from 'vue'

/**
 * Seleccionar varios ítems de un listado y publicarlos en el sitio web de una vez.
 *
 * Sirve igual para productos y para ensambles: en WordPress un ensamble es un producto
 * más, y la única diferencia es el `tipo` que se manda. Vive aquí y no en cada pantalla
 * porque el listado de productos y el de ensambles tenían el mismo bloque copiado, y una
 * corrección en uno se olvidaba en el otro.
 *
 * @param {'producto'|'ensamble'} tipo
 * @param {import('vue').Ref<Array<{id:number, publicado_web?:boolean}>>} lista
 *        La copia local del listado. Se marca en el sitio, sin recargar la pantalla.
 */
export function usePublicacionWeb(tipo, lista) {
    const seleccion  = ref(new Set())
    const publicando = ref(false)

    const csrf = () => {
        const c = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))
        return c ? decodeURIComponent(c.split('=')[1]) : ''
    }

    const ids = computed(() => (lista.value ?? []).map(i => i.id))

    const todosMarcados = computed(() =>
        ids.value.length > 0 && ids.value.every(id => seleccion.value.has(id))
    )

    function alternar(id) {
        const s = new Set(seleccion.value)
        s.has(id) ? s.delete(id) : s.add(id)
        seleccion.value = s
    }

    function alternarTodos() {
        seleccion.value = todosMarcados.value ? new Set() : new Set(ids.value)
    }

    function limpiar() {
        seleccion.value = new Set()
    }

    async function publicar(publicarlos) {
        if (! seleccion.value.size || publicando.value) return

        publicando.value = true
        try {
            const res = await fetch('/api/publicacion-web/masivo', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': csrf(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ tipo, ids: [...seleccion.value], publicar: publicarlos }),
            })

            const data = await res.json().catch(() => null)
            if (! res.ok || ! data?.ok) throw new Error(data?.mensaje || `No se pudo guardar (${res.status}).`)

            const fallidos = data.fallidos ?? []

            // Se pinta el resultado al instante: lo que sí se movió queda marcado sin
            // esperar una recarga, y lo que falló se queda como estaba.
            ;(lista.value ?? []).forEach(item => {
                if (seleccion.value.has(item.id) && ! fallidos.some(f => f.id === item.id)) {
                    item.publicado_web = publicarlos
                }
            })

            limpiar()

            // Los que no se pudieron se dicen con nombre y motivo: «3 de 50 no salieron»
            // sin decir cuáles es lo mismo que no decir nada.
            const detalle = fallidos.length
                ? '\n\nNo se pudieron:\n' + fallidos.map(f => `• ${f.nombre}: ${f.motivo}`).join('\n')
                : ''

            alert(data.mensaje + detalle)
        } catch (e) {
            alert(e.message || 'No se pudo publicar.')
        } finally {
            publicando.value = false
        }
    }

    return { seleccion, publicando, todosMarcados, alternar, alternarTodos, limpiar, publicar }
}
