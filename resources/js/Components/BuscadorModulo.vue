<script setup>
/**
 * El buscador de un módulo: sugiere mientras escribes, y filtra la lista si insistes.
 *
 * Es el mismo motor del buscador global (Ctrl+K) apuntado a un solo tipo. Reusarlo no es
 * ahorro de código: es lo que garantiza que la sugerencia respete los mismos permisos y la
 * misma sede que el listado que está debajo. Un buscador propio por módulo terminaría, tarde o
 * temprano, mostrando algo que el listado esconde.
 *
 * Hace dos cosas distintas con la misma tecla, y esa es la idea:
 *
 * - **Elegir una sugerencia** —clic, o flechas y Enter— abre ese registro directo. Es el caso
 *   frecuente: uno ya sabe cuál quiere.
 * - **Enter sin nada resaltado** filtra el listado con el texto escrito, como toda la vida. Es
 *   el caso de «muéstrame todos los que digan bisagra».
 */
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue'
import { router } from '@inertiajs/vue3'

const props = defineProps({
    modelValue:  { type: String, default: '' },
    // Qué se busca: uno o varios tipos del catálogo del buscador, separados por coma.
    tipos:       { type: String, required: true },
    placeholder: { type: String, default: 'Buscar...' },
    limite:      { type: Number, default: 8 },
})

const emit = defineEmits(['update:modelValue', 'filtrar'])

const termino  = ref(props.modelValue)
const abierto  = ref(false)
const buscando = ref(false)
const buscado  = ref(false)
const grupos   = ref([])
const error    = ref('')
const activo   = ref(-1)        // -1 = nada resaltado, y ahí Enter filtra la lista
const caja     = ref(null)

let temporizador = null
let peticion     = 0

// El padre puede limpiar los filtros: el campo tiene que seguirlo.
watch(() => props.modelValue, (v) => { if (v !== termino.value) termino.value = v })

const colores = {
    azul:   'bg-pastel-azul text-aviso-azul',
    marca:  'bg-[var(--marca-suave)] text-[var(--marca)]',
    morado: 'bg-pastel-violeta text-aviso-violeta',
    indigo: 'bg-pastel-violeta text-aviso-violeta',
    verde:  'bg-pastel-verde text-aviso-verde',
    ambar:  'bg-pastel-ambar text-aviso-ambar',
    gris:   'bg-tinta-100 text-tinta-500',
}

// Lista plana, para moverse con las flechas aunque haya más de un grupo.
const planos = computed(() =>
    grupos.value.flatMap(g => g.resultados.map(r => ({ ...r, etiqueta: g.etiqueta, color: g.color })))
)

const variosGrupos  = computed(() => grupos.value.length > 1)
const sinResultados = computed(() => buscado.value && ! buscando.value && ! error.value && ! planos.value.length)

function alEscribir(e) {
    termino.value = e.target.value
    emit('update:modelValue', termino.value)
    activo.value = -1
    clearTimeout(temporizador)

    if (termino.value.trim().length < 2) {
        grupos.value = []
        buscado.value = false
        error.value = ''
        abierto.value = false

        return
    }

    abierto.value = true
    // Sin esta espera se dispara una consulta por cada tecla.
    temporizador = setTimeout(buscar, 220)
}

async function buscar() {
    const mio = ++peticion
    buscando.value = true

    try {
        const url = `/api/buscar?q=${encodeURIComponent(termino.value.trim())}`
            + `&tipos=${encodeURIComponent(props.tipos)}&limite=${props.limite}`

        const resp = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })

        // Si mientras tanto se escribió más, esta respuesta ya no sirve: sin esto una consulta
        // lenta pisa el resultado de una más nueva.
        if (mio !== peticion) return

        if (! resp.ok) {
            grupos.value = []
            error.value  = `El buscador falló (error ${resp.status}).`

            return
        }

        const datos = await resp.json()

        // El servidor responde 200 aunque algo falle, con el motivo adentro. Un fallo NO se
        // disfraza de «no encontré nada»: eso hace dudar de los datos cuando el problema es
        // del código.
        if (datos.error) {
            grupos.value = []
            error.value  = datos.error

            return
        }

        error.value  = ''
        grupos.value = datos.grupos ?? []
    } catch {
        grupos.value = []
        error.value  = 'No se pudo conectar con el buscador.'
    } finally {
        if (mio === peticion) {
            buscando.value = false
            buscado.value  = true
        }
    }
}

function mover(paso) {
    if (! planos.value.length) return

    abierto.value = true
    const n = planos.value.length
    // Desde -1, bajar lleva al primero y subir al último.
    activo.value = activo.value < 0
        ? (paso > 0 ? 0 : n - 1)
        : (activo.value + paso + n) % n
}

function alEnter() {
    if (abierto.value && activo.value >= 0 && planos.value[activo.value]) {
        ir(planos.value[activo.value])

        return
    }

    // Nada resaltado: filtrar el listado, que es lo de siempre.
    cerrar()
    emit('filtrar')
}

function ir(resultado) {
    cerrar()
    router.visit(resultado.url)
}

function cerrar() {
    abierto.value = false
    activo.value  = -1
}

function alFoco() {
    if (planos.value.length) abierto.value = true
}

function afuera(e) {
    if (caja.value && ! caja.value.contains(e.target)) cerrar()
}

onMounted(() => document.addEventListener('mousedown', afuera))
onBeforeUnmount(() => {
    document.removeEventListener('mousedown', afuera)
    clearTimeout(temporizador)
})
</script>

<template>
    <div ref="caja" class="relative">
        <input
            :value="termino"
            type="text"
            :placeholder="placeholder"
            autocomplete="off"
            class="w-full rounded-xl border border-linea pl-9 pr-3 py-2.5 text-sm bg-superficie focus:outline-none focus:border-[var(--marca)]"
            @input="alEscribir"
            @focus="alFoco"
            @keydown.down.prevent="mover(1)"
            @keydown.up.prevent="mover(-1)"
            @keydown.enter.prevent="alEnter"
            @keydown.esc="cerrar"
        />

        <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-tinta-300 pointer-events-none"
            fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
        </svg>

        <!-- Las sugerencias. En celular ocupan el ancho del campo, que es el ancho de la
             pantalla; en computador quedan pegadas debajo. -->
        <div v-if="abierto"
            class="absolute left-0 right-0 top-full mt-1 z-30 bg-superficie rounded-xl shadow-lg border border-linea overflow-hidden max-h-[60vh] overflow-y-auto">

            <p v-if="buscando && ! planos.length" class="px-4 py-3 text-sm text-tinta-300">Buscando…</p>

            <p v-else-if="error" class="px-4 py-3 text-sm text-aviso-rojo">{{ error }}</p>

            <p v-else-if="sinResultados" class="px-4 py-3 text-sm text-tinta-300">
                Nada con «{{ termino.trim() }}». Presiona Enter para filtrar el listado de todas formas.
            </p>

            <template v-else>
                <template v-for="(g, gi) in grupos" :key="g.tipo">
                    <p v-if="variosGrupos"
                        class="px-4 pt-2 pb-1 text-[11px] font-semibold text-tinta-300 uppercase tracking-[0.12em]"
                        :class="gi > 0 ? 'border-t border-linea mt-1' : ''">
                        {{ g.etiqueta }}
                    </p>

                    <button v-for="r in g.resultados" :key="r.url" type="button"
                        class="w-full flex items-center gap-3 px-4 py-2.5 text-left transition-colors"
                        :class="planos[activo]?.url === r.url ? 'bg-realce' : 'hover:bg-realce'"
                        @click="ir(r)">
                        <span class="w-2 h-2 rounded-full shrink-0"
                            :class="colores[g.color] ?? colores.gris"></span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm text-tinta-800 truncate">{{ r.titulo }}</span>
                            <span v-if="r.detalle" class="block text-xs text-tinta-300 truncate">{{ r.detalle }}</span>
                        </span>
                    </button>
                </template>

                <p class="px-4 py-2 text-[11px] text-tinta-300 border-t border-linea">
                    Enter filtra el listado · ↑ ↓ para elegir
                </p>
            </template>
        </div>
    </div>
</template>
