<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { usePage, router } from '@inertiajs/vue3'
import HiloComentarios from '@/Components/HiloComentarios.vue'

/**
 * Botón flotante del chat interno, hermano del de la IA.
 *
 * Si estás parado en un documento (una OP, una cotización, un cliente), abre
 * su hilo. Si no, muestra lo que tienes pendiente: sin eso, el botón no
 * serviría de nada en el dashboard.
 */
const abierto    = ref(false)
const pendientes = ref([])
const cargando   = ref(false)

const page = usePage()

// Se deduce del URL en vez de pasarlo por props: así el botón vive en el
// layout y funciona en cualquier pantalla sin tocar cada página.
const documento = computed(() => {
    const ruta = page.url || window.location.pathname

    const mapa = [
        [/^\/produccion\/ops\/(\d+)/,   'op',           'Orden de producción'],
        [/^\/cotizaciones\/(\d+)/,      'cotizacion',   'Cotización'],
        [/^\/clientes\/(\d+)/,          'cliente',      'Cliente'],
        [/^\/compras\/ordenes\/(\d+)/,  'orden_compra', 'Orden de compra'],
    ]

    for (const [patron, tipo, etiqueta] of mapa) {
        const m = ruta.match(patron)
        if (m) return { tipo, id: m[1], etiqueta }
    }

    return null
})

const sinLeer = computed(() => pendientes.value.length)

async function cargarPendientes() {
    cargando.value = true
    try {
        const res = await fetch('/api/comentarios/pendientes', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
        if (res.ok) pendientes.value = (await res.json()).pendientes ?? []
    } catch { /* si falla, el botón igual abre: no vale la pena molestar */ }
    finally { cargando.value = false }
}

function alternar() {
    abierto.value = !abierto.value
    if (abierto.value && !documento.value) cargarPendientes()
}

function ir(url) {
    abierto.value = false
    router.visit(url)
}

// Al cambiar de pantalla se recuenta, para que el globito esté al día.
watch(() => page.url, () => { if (!abierto.value) cargarPendientes() })

onMounted(cargarPendientes)

const etiquetaTipo = { solicitud: 'Solicitud', tarea: 'Tarea' }
</script>

<template>
    <!-- Botón flotante -->
    <button
        v-if="!abierto"
        @click="alternar"
        class="burbuja-chat fixed z-40 flex items-center justify-center rounded-full shadow-lg"
        style="background:#0F766E; width:56px; height:56px; right:20px; bottom:152px;"
        title="Chat del equipo"
    >
        <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round"
                d="M17 8h2a2 2 0 012 2v6a2 2 0 01-2 2h-2v4l-4-4H9a1.99 1.99 0 01-1.414-.586m0 0L11 14h4a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2v4l.586-.586z" />
        </svg>

        <span v-if="sinLeer"
            class="globito absolute -top-1 -right-1 min-w-[20px] h-5 px-1 rounded-full bg-red-500 text-white text-[11px] font-bold flex items-center justify-center">
            {{ sinLeer > 9 ? '9+' : sinLeer }}
        </span>
    </button>

    <!-- Panel -->
    <Teleport to="body">
        <div v-if="abierto" class="fixed inset-0 z-50 sm:bg-transparent" style="background:rgba(0,0,0,0.35);"
            @click.self="abierto = false">
            <div class="panel absolute bg-white shadow-2xl flex flex-col
                        inset-x-0 bottom-0 rounded-t-2xl max-h-[85vh]
                        sm:inset-auto sm:right-5 sm:bottom-5 sm:w-[26rem] sm:rounded-2xl sm:border sm:border-gray-200 sm:max-h-[80vh]">

                <!-- Encabezado -->
                <div class="flex items-center gap-2 px-4 py-3 border-b border-gray-100 shrink-0">
                    <div class="w-8 h-8 rounded-xl flex items-center justify-center shrink-0" style="background:#0F766E;">
                        <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M17 8h2a2 2 0 012 2v6a2 2 0 01-2 2h-2v4l-4-4H9a1.99 1.99 0 01-1.414-.586m0 0L11 14h4a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2v4l.586-.586z" />
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-gray-800 leading-tight">Chat del equipo</p>
                        <p class="text-[11px] text-gray-400 truncate">
                            {{ documento ? documento.etiqueta : 'Lo que tienes pendiente' }}
                        </p>
                    </div>
                    <button @click="abierto = false" class="ml-auto p-1.5 rounded-lg hover:bg-gray-100 text-gray-400">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <div class="overflow-y-auto p-3">
                    <!-- Hilo del documento en el que estoy -->
                    <HiloComentarios v-if="documento" :key="documento.tipo + documento.id"
                        :documento="documento.tipo" :id="documento.id" />

                    <!-- Fuera de un documento: mis pendientes -->
                    <template v-else>
                        <p v-if="cargando" class="text-xs text-gray-400 py-6 text-center">Cargando...</p>

                        <div v-else-if="!pendientes.length" class="py-8 text-center">
                            <p class="text-sm text-gray-500">No tienes nada pendiente.</p>
                            <p class="text-xs text-gray-400 mt-1">
                                Abre una orden, cotización o cliente para conversar sobre ella.
                            </p>
                        </div>

                        <ul v-else class="space-y-2">
                            <li v-for="p in pendientes" :key="p.id">
                                <button @click="ir(p.url)"
                                    class="w-full text-left rounded-xl border border-amber-200 bg-amber-50/50 p-3 hover:bg-amber-50 transition-colors">
                                    <div class="flex items-center gap-2 flex-wrap mb-1">
                                        <span class="text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-700 leading-none">
                                            {{ etiquetaTipo[p.tipo] }}
                                        </span>
                                        <span class="text-[11px] text-gray-500">{{ p.documento }}</span>
                                        <span v-if="p.fecha_limite" class="text-[11px] text-gray-500">· antes del {{ p.fecha_limite }}</span>
                                    </div>
                                    <p class="text-sm text-gray-700 line-clamp-2">{{ p.contenido }}</p>
                                    <p class="text-[11px] text-gray-400 mt-1">de {{ p.autor }}</p>
                                </button>
                            </li>
                        </ul>
                    </template>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
/* Animaciones: entrada suave, reacción al tocar y globito que llama la
   atención sin marear. Se respeta a quien pidió menos movimiento. */
.burbuja-chat {
    animation: entrar 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
    transition: transform 0.18s ease, box-shadow 0.18s ease;
}
.burbuja-chat:hover  { transform: scale(1.08); box-shadow: 0 10px 25px rgba(0,0,0,0.25); }
.burbuja-chat:active { transform: scale(0.94); }

.globito { animation: latir 2s ease-in-out infinite; }

.panel { animation: subir 0.25s ease-out; }

@keyframes entrar { from { opacity: 0; transform: scale(0.5) translateY(10px); } to { opacity: 1; transform: scale(1); } }
@keyframes latir  { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.15); } }
@keyframes subir  { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }

@media (prefers-reduced-motion: reduce) {
    .burbuja-chat, .globito, .panel { animation: none; transition: none; }
}
</style>
