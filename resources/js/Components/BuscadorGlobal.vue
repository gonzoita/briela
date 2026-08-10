<script setup>
/**
 * Buscador global del encabezado.
 *
 * En computador es una barra siempre visible; en celular, una lupa que abre
 * pantalla completa (la barra no cabe al lado del selector de sede).
 *
 * Busca mientras escribes, sin oprimir nada. Si no encuentra nada, ofrece
 * pasarle la pregunta al asistente: la búsqueda literal es instantánea y la
 * IA sirve justo para lo que la literal no alcanza.
 */
import { ref, computed, onMounted, onUnmounted, nextTick, inject } from 'vue'
import { router } from '@inertiajs/vue3'

const abierto   = ref(false)
const termino   = ref('')
const grupos    = ref([])
const buscando  = ref(false)
const buscado   = ref(false)   // ya se hizo al menos una búsqueda con este texto
const error     = ref('')
const activo    = ref(0)       // índice resaltado para navegar con flechas
const campo     = ref(null)

let temporizador = null
let peticion     = 0

// El asistente se inyecta desde AppLayout, si está disponible.
const abrirAsistente = inject('abrirAsistente', null)

const colores = {
    azul:   'bg-blue-50 text-blue-700',
    marca: 'bg-[var(--marca-suave)] text-[var(--marca)]',
    morado: 'bg-violet-50 text-violet-700',
    verde:  'bg-green-50 text-green-700',
    ambar:  'bg-amber-50 text-amber-700',
    gris:   'bg-tinta-100 text-tinta-500',
}

// Lista plana, para poder moverse con las flechas entre grupos distintos.
const planos = computed(() =>
    grupos.value.flatMap(g => g.resultados.map(r => ({ ...r, tipo: g.tipo, color: g.color })))
)

const sinResultados = computed(() =>
    buscado.value && !buscando.value && !error.value
    && termino.value.trim().length >= 2 && planos.value.length === 0
)

function alEscribir() {
    activo.value = 0
    clearTimeout(temporizador)

    if (termino.value.trim().length < 2) {
        grupos.value = []
        buscado.value = false
        error.value = ''
        return
    }

    // Sin esta espera se dispara una consulta por cada tecla.
    temporizador = setTimeout(buscar, 220)
}

async function buscar() {
    const mio = ++peticion
    buscando.value = true

    try {
        const resp = await fetch(`/api/buscar?q=${encodeURIComponent(termino.value.trim())}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })

        // Si mientras tanto se escribió más, esta respuesta ya no sirve:
        // sin esto, una consulta lenta pisa el resultado de una más nueva.
        if (mio !== peticion) return

        // Un fallo del servidor NO se disfraza de "no encontré nada". Antes sí,
        // y eso hizo que un error de SQL se viera como un cliente inexistente.
        if (!resp.ok) {
            grupos.value = []
            error.value  = `El buscador falló (error ${resp.status}). Revisa storage/logs/laravel.log en el servidor.`
            return
        }

        const datos = await resp.json()

        // El servidor responde 200 aunque algo falle, con el motivo adentro.
        if (datos.error) {
            grupos.value = []
            error.value  = datos.error
            return
        }

        error.value  = ''
        grupos.value = datos.grupos ?? []
    } catch (e) {
        grupos.value = []
        error.value  = 'No se pudo conectar con el buscador.'
    } finally {
        if (mio === peticion) {
            buscando.value = false
            buscado.value  = true
        }
    }
}

function ir(resultado) {
    cerrar()
    router.visit(resultado.url)
}

function preguntarleAOfe() {
    const texto = termino.value.trim()
    cerrar()
    if (abrirAsistente) abrirAsistente(texto)
}

async function abrir() {
    abierto.value = true
    await nextTick()
    campo.value?.focus()
}

function cerrar() {
    abierto.value = false
    termino.value = ''
    grupos.value  = []
    buscado.value = false
    activo.value  = 0
}

function conTeclado(e) {
    if (e.key === 'Escape') { cerrar(); return }

    if (!planos.value.length) return

    if (e.key === 'ArrowDown') {
        e.preventDefault()
        activo.value = (activo.value + 1) % planos.value.length
    } else if (e.key === 'ArrowUp') {
        e.preventDefault()
        activo.value = (activo.value - 1 + planos.value.length) % planos.value.length
    } else if (e.key === 'Enter') {
        e.preventDefault()
        ir(planos.value[activo.value])
    }
}

// Ctrl+K / Cmd+K desde cualquier pantalla.
function atajoGlobal(e) {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault()
        abierto.value ? cerrar() : abrir()
    }
}

onMounted(() => document.addEventListener('keydown', atajoGlobal))
onUnmounted(() => {
    document.removeEventListener('keydown', atajoGlobal)
    clearTimeout(temporizador)
})

/** Índice de un resultado dentro de la lista plana, para el resaltado. */
function indicePlano(gi, ri) {
    let n = 0
    for (let i = 0; i < gi; i++) n += grupos.value[i].resultados.length
    return n + ri
}
</script>

<template>
    <div class="relative">
        <!-- Disparador: barra en computador, lupa en celular -->
        <button type="button" @click="abrir"
            class="hidden md:flex items-center gap-2 w-56 lg:w-72 rounded-xl border border-linea bg-tinta-50 px-3 py-1.5 text-sm text-tinta-300 hover:bg-white hover:border-tinta-200 transition-colors">
            <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
            <span class="flex-1 text-left">Buscar...</span>
            <kbd class="hidden lg:inline text-[10px] border border-tinta-200 rounded px-1 py-0.5 text-tinta-300">Ctrl K</kbd>
        </button>

        <button type="button" @click="abrir" class="md:hidden p-2 text-tinta-400" aria-label="Buscar">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
        </button>

        <!-- Panel -->
        <Teleport to="body">
            <div v-if="abierto" class="fixed inset-0 z-[70] flex items-start justify-center"
                @click.self="cerrar">
                <div class="absolute inset-0 bg-black/30"></div>

                <div class="relative w-full md:max-w-2xl md:mt-20 bg-white md:rounded-2xl shadow-xl overflow-hidden h-full md:h-auto md:max-h-[75vh] flex flex-col">

                    <!-- Campo -->
                    <div class="flex items-center gap-2 px-4 py-3 border-b border-linea shrink-0">
                        <svg class="w-5 h-5 text-tinta-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                        <input ref="campo" v-model="termino" type="text"
                            placeholder="Cliente, OP, cotización, remisión, producto, serie..."
                            class="flex-1 text-sm bg-transparent focus:outline-none"
                            @input="alEscribir" @keydown="conTeclado"/>
                        <span v-if="buscando" class="w-4 h-4 border-2 border-linea border-t-blue-600 rounded-full animate-spin shrink-0"></span>
                        <button type="button" @click="cerrar" class="text-tinta-300 hover:text-tinta-500 shrink-0">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    <!-- Resultados -->
                    <div class="flex-1 overflow-y-auto">
                        <div v-if="termino.trim().length < 2" class="px-4 py-8 text-center">
                            <p class="text-sm text-tinta-300">Escribe al menos dos letras.</p>
                            <p class="text-xs text-tinta-200 mt-1">
                                Busca por nombre, NIT, número de documento o número de serie.
                            </p>
                        </div>

                        <div v-for="(g, gi) in grupos" :key="g.tipo" class="py-1">
                            <p class="px-4 py-1.5 text-[11px] font-semibold text-tinta-300 uppercase tracking-[0.12em]">
                                {{ g.etiqueta }}
                            </p>
                            <button v-for="(r, ri) in g.resultados" :key="r.url + ri"
                                type="button" @click="ir(r)"
                                @mouseenter="activo = indicePlano(gi, ri)"
                                class="w-full flex items-center gap-3 px-4 py-2 text-left transition-colors"
                                :class="activo === indicePlano(gi, ri) ? 'bg-tinta-50' : ''">
                                <span class="shrink-0 text-[10px] font-semibold px-1.5 py-0.5 rounded"
                                    :class="colores[g.color] ?? colores.gris">
                                    {{ g.tipo.replace(/_/g, ' ') }}
                                </span>
                                <span class="flex-1 min-w-0">
                                    <span class="block text-sm text-tinta-900 truncate">{{ r.titulo }}</span>
                                    <span v-if="r.detalle" class="block text-xs text-tinta-300 truncate">{{ r.detalle }}</span>
                                </span>
                            </button>
                        </div>

                        <!-- Falla del servidor: se muestra tal cual, no se
                             disfraza de "sin resultados" -->
                        <div v-if="error" class="px-4 py-6 text-center">
                            <p class="text-sm text-red-600">{{ error }}</p>
                        </div>

                        <!-- Nada encontrado: aquí entra la IA -->
                        <div v-if="sinResultados" class="px-4 py-8 text-center">
                            <p class="text-sm text-tinta-400">No encontré nada con “{{ termino }}”.</p>
                            <p class="text-xs text-tinta-300 mt-1">
                                Puede que no exista, que esté en otra sede, o que sea una pregunta
                                y no un nombre.
                            </p>
                            <button v-if="abrirAsistente" type="button" @click="preguntarleAOfe"
                                class="mt-3 rounded-xl px-4 py-2 text-sm font-semibold text-white"
                                style="background: var(--marca);">
                                Preguntarle al asistente
                            </button>
                        </div>
                    </div>

                    <!-- Ayuda del teclado -->
                    <div v-if="planos.length" class="hidden md:flex items-center gap-3 px-4 py-2 border-t border-linea text-[11px] text-tinta-300 shrink-0">
                        <span><kbd class="border border-linea rounded px-1">↑</kbd><kbd class="border border-linea rounded px-1 ml-0.5">↓</kbd> moverse</span>
                        <span><kbd class="border border-linea rounded px-1">Enter</kbd> abrir</span>
                        <span><kbd class="border border-linea rounded px-1">Esc</kbd> cerrar</span>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>
