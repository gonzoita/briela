<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import IconoMenu from '@/Components/IconoMenu.vue'
import ListaConversaciones from '@/Components/Bandeja/ListaConversaciones.vue'
import HiloMensajes from '@/Components/Bandeja/HiloMensajes.vue'
import CajaRespuesta from '@/Components/Bandeja/CajaRespuesta.vue'
import { insignia } from '@/canales'

/**
 * La bandeja: WhatsApp y las redes en una sola pantalla.
 *
 * **Abrir una conversación no navega.** El hilo entra por `fetch`, no por Inertia: `AppLayout`
 * no es persistente, así que cada navegación lo destruye y lo reconstruye con todo lo que
 * lleva dentro, y además volvería a traer la lista para pintar la misma lista. Quien atiende
 * abre cuarenta conversaciones en una mañana; cuarenta reconstrucciones del layout es media
 * mañana mirando parpadeos. Ver «Velocidad: nada cuesta una petición por clic».
 *
 * **En celular es una pantalla a la vez.** La lista ocupa todo; al tocar una conversación el
 * hilo la tapa, con botón de volver. En escritorio van las dos columnas. Es mobile-first de
 * verdad y no una tabla encogida: una bandeja se atiende desde el celular, en la calle.
 */
const props = defineProps({
    conversaciones: { type: Object, required: true },
    contadores:     { type: Object, default: () => ({}) },
    canales:        { type: Array,  default: () => [] },
    filtros:        { type: Object, default: () => ({}) },
    usuarios:       { type: Array,  default: () => [] },
    puede:          { type: Object, default: () => ({ responder: false, asignar: false }) },
    abrir:          { type: String, default: null },
})

// ── Filtros ──────────────────────────────────────────────────────────────────
const buscar   = ref(props.filtros?.buscar ?? '')
const canal    = ref(props.filtros?.canal ?? '')
const estado   = ref(props.filtros?.estado ?? 'activas')
const asignado = ref(props.filtros?.asignado ?? '')

/** Los filtros viajan en la URL: así el enlace se comparte y recargar no pierde lo elegido. */
function aplicarFiltros() {
    router.get('/bandeja', {
        buscar:   buscar.value || undefined,
        canal:    canal.value || undefined,
        estado:   estado.value !== 'activas' ? estado.value : undefined,
        asignado: asignado.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true })
}

let temporizadorBusqueda = null
function buscarConFreno() {
    clearTimeout(temporizadorBusqueda)
    temporizadorBusqueda = setTimeout(aplicarFiltros, 350)
}

watch([canal, estado, asignado], aplicarFiltros)

// ── El hilo abierto ──────────────────────────────────────────────────────────
const abierta      = ref(null)
const hilo         = ref(null)
const cargandoHilo = ref(false)
const enviando     = ref(false)
const error        = ref('')
const caja         = ref(null)

const canalAbierto = computed(() =>
    props.canales.find(c => c.clave === hilo.value?.canal) ?? null
)

const csrf = () => {
    const c = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))
    return c ? decodeURIComponent(c.split('=')[1]) : ''
}

async function api(url, opciones = {}) {
    const respuesta = await fetch(url, {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-XSRF-TOKEN': csrf(),
            'X-Requested-With': 'XMLHttpRequest',
            // Un cuerpo de FormData trae su propio Content-Type con el separador: ponerlo a
            // mano rompe la subida del archivo.
            ...(opciones.body instanceof FormData ? {} : { 'Content-Type': 'application/json' }),
        },
        ...opciones,
    })

    const datos = await respuesta.json().catch(() => ({}))

    if (! respuesta.ok) {
        throw new Error(datos.message || 'No se pudo completar la acción.')
    }

    return datos
}

async function abrirConversacion(clave) {
    abierta.value = clave
    error.value = ''
    cargandoHilo.value = true
    hilo.value = null

    try {
        hilo.value = await api(`/bandeja/${clave}/hilo`)

        // La fila se marca leída sin esperar al servidor: el servidor ya lo hizo al servir el
        // hilo, y volver a pedir la lista para bajar un punto azul sería una petición de más.
        const fila = props.conversaciones.data.find(c => c.clave === clave)
        if (fila?.sin_leer) {
            fila.sin_leer = false
            if (props.contadores.sin_leer > 0) props.contadores.sin_leer--
        }
    } catch (e) {
        error.value = e.message
    } finally {
        cargandoHilo.value = false
    }
}

function cerrarConversacion() {
    abierta.value = null
    hilo.value = null
}

async function enviar({ texto, archivo }) {
    if (! abierta.value) return

    enviando.value = true
    error.value = ''

    try {
        const cuerpo = new FormData()
        if (texto) cuerpo.append('texto', texto)
        if (archivo) cuerpo.append('archivo', archivo)

        const datos = await api(`/bandeja/${abierta.value}/responder`, { method: 'POST', body: cuerpo })

        hilo.value.mensajes.push(datos.mensaje)
        hilo.value.ventana = datos.ventana
        caja.value?.limpiar()
        refrescarFila(texto || '📎 Archivo adjunto')
    } catch (e) {
        // Lo escrito se queda en la caja: un error de Meta no puede llevarse el mensaje que la
        // persona acababa de redactar.
        error.value = e.message
    } finally {
        enviando.value = false
    }
}

async function enviarPlantilla(payload) {
    if (! abierta.value) return

    enviando.value = true
    error.value = ''

    try {
        const datos = await api(`/bandeja/${abierta.value}/plantilla`, {
            method: 'POST',
            body: JSON.stringify(payload),
        })

        if (datos.mensaje) hilo.value.mensajes.push(datos.mensaje)
        hilo.value.ventana = datos.ventana
        caja.value?.cerrarPlantilla()
        refrescarFila(datos.mensaje?.texto ?? 'Plantilla enviada')
    } catch (e) {
        error.value = e.message
    } finally {
        enviando.value = false
    }
}

/** Mueve la fila de la lista sin volver a pedirla: lo que acabamos de mandar ya lo sabemos. */
function refrescarFila(texto) {
    const fila = props.conversaciones.data.find(c => c.clave === abierta.value)

    if (fila) {
        fila.ultimo_texto = 'Tú: ' + texto
        fila.ultimo_mensaje_at = new Date().toISOString()
    }
}

async function asignar(usuarioId) {
    try {
        const datos = await api(`/bandeja/${abierta.value}/asignar`, {
            method: 'POST',
            body: JSON.stringify({ usuario_id: usuarioId || null }),
        })

        hilo.value.cabecera.asignado_a = datos.asignado_a
        hilo.value.cabecera.asignado = datos.asignado

        const fila = props.conversaciones.data.find(c => c.clave === abierta.value)
        if (fila) fila.asignado_a = datos.asignado_a
    } catch (e) {
        error.value = e.message
    }
}

async function archivar() {
    const archivar = ! hilo.value.cabecera.archivada

    try {
        await api(`/bandeja/${abierta.value}/archivar`, {
            method: 'POST',
            body: JSON.stringify({ archivar }),
        })

        hilo.value.cabecera.archivada = archivar
        // Archivar la saca de la vista: hay que volver a pedir la lista, porque la fila ya no
        // pertenece al filtro que se está mirando.
        router.reload({ only: ['conversaciones', 'contadores'], preserveScroll: true })
        if (archivar) cerrarConversacion()
    } catch (e) {
        error.value = e.message
    }
}

// ── Que se sienta vivo ───────────────────────────────────────────────────────
//
// La bandeja es la única pantalla donde lo que importa llega sin que nadie toque nada, así que
// se refresca sola. Un temporizador, y solo mientras la pestaña está al frente: el navegador
// acumula los temporizadores dormidos y los dispara todos juntos al volver.
const CADA = 20000
let temporizador = null

function refrescar() {
    router.reload({ only: ['conversaciones', 'contadores'], preserveScroll: true, preserveState: true })

    // El hilo abierto también: estar leyendo una conversación es cuando más importa ver llegar
    // el mensaje siguiente.
    if (abierta.value && ! enviando.value) {
        api(`/bandeja/${abierta.value}/hilo`)
            .then((datos) => {
                if (datos.mensajes.length !== hilo.value?.mensajes.length) {
                    hilo.value = datos
                }
            })
            .catch(() => { /* un refresco que no llega no interrumpe a quien está escribiendo */ })
    }
}

function arrancar() {
    if (! temporizador) temporizador = setInterval(refrescar, CADA)
}

function detener() {
    clearInterval(temporizador)
    temporizador = null
}

/**
 * Con nombre, no anónima: `removeEventListener` necesita la misma referencia, y una escucha
 * que no se puede quitar retiene esta pantalla completa cada vez que se sale de ella.
 */
function alCambiarVisibilidad() {
    document.hidden ? detener() : (refrescar(), arrancar())
}

onMounted(() => {
    arrancar()
    document.addEventListener('visibilitychange', alCambiarVisibilidad)

    // Se llega desde la campanita con la conversación en la URL.
    if (props.abrir) abrirConversacion(props.abrir)
})

onUnmounted(() => {
    detener()
    clearTimeout(temporizadorBusqueda)
    document.removeEventListener('visibilitychange', alCambiarVisibilidad)
})
</script>

<template>
    <AppLayout title="Bandeja">
        <div class="alto-bandeja flex rounded-2xl border border-linea bg-superficie overflow-hidden">

            <!-- ── Columna izquierda: filtros y lista ───────────────────────── -->
            <aside class="w-full md:w-80 lg:w-96 shrink-0 flex flex-col border-r border-linea"
                :class="abierta ? 'hidden md:flex' : 'flex'">

                <div class="px-3 py-3 border-b border-linea space-y-2.5">
                    <div class="flex items-center gap-2">
                        <h1 class="flex-1 text-base font-semibold text-tinta-900 flex items-center gap-2">
                            <IconoMenu nombre="inbox" clase="text-base w-5" />
                            Bandeja
                        </h1>
                        <span v-if="contadores.sin_leer"
                            class="px-2 py-0.5 rounded-full text-[11px] font-semibold text-white"
                            style="background:var(--marca)">
                            {{ contadores.sin_leer }} sin leer
                        </span>
                    </div>

                    <div class="relative">
                        <span class="absolute left-2.5 top-1/2 -translate-y-1/2 text-tinta-400">
                            <IconoMenu nombre="magnifying-glass" clase="text-xs w-3.5" />
                        </span>
                        <input v-model="buscar" @input="buscarConFreno" type="search"
                            placeholder="Buscar por nombre o número…"
                            class="w-full border border-linea rounded-xl pl-8 pr-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]" />
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <select v-model="canal"
                            class="border border-linea rounded-xl px-2 py-1.5 text-xs focus:outline-none focus:border-[var(--marca)]">
                            <option value="">Todos los canales</option>
                            <option v-for="c in canales" :key="c.clave" :value="c.clave">{{ c.label }}</option>
                        </select>

                        <select v-model="estado"
                            class="border border-linea rounded-xl px-2 py-1.5 text-xs focus:outline-none focus:border-[var(--marca)]">
                            <option value="activas">Activas</option>
                            <option value="sin_leer">Sin leer ({{ contadores.sin_leer ?? 0 }})</option>
                            <option value="archivadas">Archivadas</option>
                            <option value="todas">Todas</option>
                        </select>
                    </div>

                    <select v-model="asignado"
                        class="w-full border border-linea rounded-xl px-2 py-1.5 text-xs focus:outline-none focus:border-[var(--marca)]">
                        <option value="">Atienda quien sea</option>
                        <option value="sin_asignar">Sin dueño ({{ contadores.sin_asignar ?? 0 }})</option>
                        <option v-for="u in usuarios" :key="u.id" :value="String(u.id)">{{ u.nombre }}</option>
                    </select>
                </div>

                <ListaConversaciones :conversaciones="conversaciones" :activa="abierta"
                    @abrir="abrirConversacion" />
            </aside>

            <!-- ── Columna derecha: el hilo ─────────────────────────────────── -->
            <section class="flex-1 min-w-0 flex flex-col" :class="abierta ? 'flex' : 'hidden md:flex'">

                <!-- Sin nada abierto, en escritorio -->
                <div v-if="! abierta" class="flex-1 flex flex-col items-center justify-center text-center px-6 gap-3">
                    <span class="w-14 h-14 rounded-2xl bg-tinta-100 text-tinta-400 flex items-center justify-center">
                        <IconoMenu nombre="comments" clase="text-xl w-7" />
                    </span>
                    <p class="text-sm text-tinta-500 max-w-xs">
                        Elige una conversación para leerla y contestar.
                    </p>
                </div>

                <template v-else>
                    <!-- Cabecera del hilo -->
                    <div class="px-3 md:px-4 py-2.5 border-b border-linea flex items-center gap-2.5">
                        <button type="button" @click="cerrarConversacion"
                            class="md:hidden shrink-0 w-9 h-9 rounded-xl text-tinta-500 hover:bg-realce flex items-center justify-center"
                            title="Volver a la lista">
                            <IconoMenu nombre="arrow-left" clase="text-sm w-4" />
                        </button>

                        <span v-if="canalAbierto"
                            class="shrink-0 w-9 h-9 rounded-xl flex items-center justify-center"
                            :class="insignia(canalAbierto.color)" :title="canalAbierto.label">
                            <IconoMenu :nombre="canalAbierto.icono" clase="text-sm w-4" />
                        </span>

                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold text-tinta-900 truncate">
                                {{ hilo?.contacto ?? '…' }}
                            </p>
                            <p class="text-[11px] text-tinta-400 truncate">
                                {{ hilo?.canal_label }}
                                <template v-if="hilo?.cabecera?.origen"> · {{ hilo.cabecera.origen }}</template>
                                <template v-if="hilo?.cabecera?.agente"> · atendió {{ hilo.cabecera.agente }}</template>
                            </p>
                        </div>

                        <!-- Quién atiende. Tomar una conversación la saca del agente de IA: dos
                             voces en el mismo chat son peores que ninguna. -->
                        <select v-if="puede.asignar && hilo"
                            :value="hilo.cabecera.asignado_a ?? ''"
                            @change="asignar($event.target.value)"
                            class="hidden sm:block max-w-[9rem] border border-linea rounded-lg px-2 py-1.5 text-xs focus:outline-none"
                            title="Quién atiende esta conversación">
                            <option value="">Sin dueño</option>
                            <option v-for="u in usuarios" :key="u.id" :value="u.id">{{ u.nombre }}</option>
                        </select>

                        <button v-if="hilo" type="button" @click="archivar"
                            class="shrink-0 w-9 h-9 rounded-xl text-tinta-500 hover:bg-realce flex items-center justify-center"
                            :title="hilo.cabecera.archivada ? 'Devolver a la bandeja' : 'Archivar'">
                            <IconoMenu :nombre="hilo.cabecera.archivada ? 'rotate-left' : 'box-archive'" clase="text-sm w-4" />
                        </button>
                    </div>

                    <!-- De qué publicación salió el comentario. Sin esto, «¿cuánto vale?» en
                         la bandeja no se puede responder: no se sabe qué foto estaba mirando
                         quien lo escribió. -->
                    <div v-if="hilo?.cabecera?.contexto"
                        class="mx-3 md:mx-4 mt-2 rounded-xl bg-superficie-2 border border-linea px-3 py-2">
                        <p class="text-[11px] font-semibold text-tinta-500">
                            {{ hilo.cabecera.contexto.titulo }}
                        </p>
                        <p v-if="hilo.cabecera.contexto.texto"
                            class="text-xs text-tinta-700 mt-0.5 line-clamp-3">
                            {{ hilo.cabecera.contexto.texto }}
                        </p>
                        <a v-if="hilo.cabecera.contexto.enlace" :href="hilo.cabecera.contexto.enlace"
                            target="_blank" rel="noopener"
                            class="inline-block text-[11px] mt-1 underline" style="color:var(--marca)">
                            Ver la publicación
                        </a>
                    </div>

                    <!-- Lo que ata la conversación al resto del sistema. Sin esto, la bandeja
                         es un chat aparte: con esto, es la puerta de entrada al CRM. -->
                    <div v-if="hilo && (hilo.cabecera.lead_id || hilo.cabecera.cliente_id || hilo.cabecera.enlace_externo)"
                        class="px-3 md:px-4 py-2 border-b border-linea flex items-center gap-2 flex-wrap text-[11px]">
                        <a v-if="hilo.cabecera.cliente_id" :href="`/clientes/${hilo.cabecera.cliente_id}`"
                            class="px-2 py-1 rounded-full bg-pastel-verde-2 text-aviso-verde font-medium">
                            Cliente: {{ hilo.cabecera.cliente ?? 'ver ficha' }}
                        </a>
                        <a v-if="hilo.cabecera.lead_id" href="/crm"
                            class="px-2 py-1 rounded-full bg-pastel-violeta-2 text-aviso-violeta font-medium">
                            Lead en el CRM
                        </a>
                        <a v-if="hilo.cabecera.enlace_externo" :href="hilo.cabecera.enlace_externo"
                            target="_blank" rel="noopener"
                            class="px-2 py-1 rounded-full bg-tinta-100 text-tinta-500 font-medium">
                            Abrir por fuera
                        </a>
                        <span v-if="hilo.cabecera.archivada"
                            class="px-2 py-1 rounded-full bg-tinta-100 text-tinta-500 font-medium">Archivada</span>
                    </div>

                    <HiloMensajes :mensajes="hilo?.mensajes ?? []" :cargando="cargandoHilo" />

                    <p v-if="error"
                        class="mx-3 md:mx-4 mb-2 px-3 py-2 rounded-xl text-xs bg-pastel-rojo border border-borde-aviso-rojo text-aviso-rojo">
                        {{ error }}
                    </p>

                    <CajaRespuesta v-if="hilo" ref="caja"
                        :ventana="hilo.ventana"
                        :plantillas="hilo.plantillas"
                        :adjuntos="canalAbierto?.adjuntos ?? false"
                        :enviando="enviando"
                        :puede="puede.responder"
                        @enviar="enviar"
                        @enviar-plantilla="enviarPlantilla" />
                </template>
            </section>
        </div>
    </AppLayout>
</template>

<style scoped>
/**
 * El alto disponible, descontando lo que ocupan las barras del layout.
 *
 * Son los mismos números de `con-espacio-de-barras` en `app.blade.php` —3,5rem arriba y 5rem
 * abajo en celular; 5rem y 2rem en escritorio— porque esta pantalla tiene que llenar el hueco
 * exacto: una bandeja con la lista cortada a la mitad y la página desplazándose por detrás es
 * imposible de usar. `dvh` y no `vh`: en celular la barra del navegador aparece y desaparece,
 * y con `vh` la caja de escribir queda debajo de ella.
 */
.alto-bandeja {
    height: calc(100dvh - 8.5rem - env(safe-area-inset-top) - env(safe-area-inset-bottom));
    min-height: 22rem;
}

@media (min-width: 768px) {
    .alto-bandeja { height: calc(100dvh - 7rem); }
}
</style>
