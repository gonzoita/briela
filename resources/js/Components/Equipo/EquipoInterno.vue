<script setup>
/**
 * El Equipo de IA: agentes internos que trabajan para la empresa y proponen; una persona decide.
 *
 * Es la pestaña «Equipo interno» de la pantalla Agentes. Se monta SOLO cuando se abre la pestaña
 * (`v-if` en quien lo usa), así que nada de aquí cuesta una petición en las demás pantallas.
 *
 * En vivo sin websockets: mientras la pestaña está abierta se pregunta cada 8 s «¿cambió algo?»
 * (una firma barata) y la lista completa solo se vuelve a pedir cuando sí. El temporizador se
 * detiene al salir, y también mientras la pestaña del navegador está oculta.
 */
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { get, post, mensajeDe } from '@/composables/useEquipoIa'
import OficinaAgentes from './OficinaAgentes.vue'
import OficinaEnVivo from './OficinaEnVivo.vue'
import Aprobaciones from './Aprobaciones.vue'
import CargosEquipo from './CargosEquipo.vue'
import ConsumoEquipo from './ConsumoEquipo.vue'
import FormAgente from './FormAgente.vue'
import DetalleEjecucion from './DetalleEjecucion.vue'

const props = defineProps({
    permisos: { type: Object, required: true },
    // La vista con la que abrir (un aviso de la campana puede llevar directo a las aprobaciones).
    vistaInicial: { type: String, default: 'vivo' },
})

const vista     = ref(props.vistaInicial)
const oficina   = ref({ agentes: [], pausado: false, ia_lista: true, pendientes: 0, tablero: null })
const cargando  = ref(true)
const catalogos = ref({ cargos: [], roles: [], herramientas: [], usuarios: [], sedes: [], variables: [] })
const version   = ref(0)           // sube cuando el pulso detecta un cambio
const mensaje   = ref('')
const esperando = ref([])          // agentes a los que se les pidió una corrida
const formulario = ref(null)       // { id } al crear o configurar
const detalle    = ref(null)       // id de la ejecución que se está mirando

let cargadaEn = 0

async function cargarOficina() {
    cargadaEn = Date.now()
    const r = await get('/api/equipo-ia/oficina')
    cargando.value = false
    if (r.ok) oficina.value = r.data
    else mensaje.value = mensajeDe(r)
}

async function cargarCatalogos() {
    if (! props.permisos.gestionar) return
    const r = await get('/api/equipo-ia/catalogos')
    if (r.ok) catalogos.value = r.data
}

// ─── Pulso ───────────────────────────────────────────────────────────────────

let firma = null
let timer = null

let enCurso = 0
let activo = true

async function pulso() {
    // Una pestaña del navegador oculta no necesita estar al día.
    if (typeof document !== 'undefined' && document.hidden) return

    const r = await get('/api/equipo-ia/pulso')
    if (! r.ok) return

    enCurso = r.data.en_curso ?? 0

    if (firma !== null && r.data.firma !== firma) {
        await cargarOficina()
        version.value++
    } else if (vista.value === 'vivo' && Date.now() - cargadaEn > 120000) {
        // Las ventas y las órdenes del tablero cambian sin que cambie ningún agente: con la
        // oficina en vivo abierta se refrescan cada dos minutos.
        await cargarOficina()
    }
    firma = r.data.firma
}

/**
 * Cada 8 s, salvo en la oficina en vivo con alguien corriendo: ahí cada 4 s, para que el globo
 * siga el paso a paso. El pulso es una firma barata; la lista solo se pide si cambió.
 */
function programar() {
    if (! activo) return
    const espera = vista.value === 'vivo' && enCurso > 0 ? 4000 : 8000
    timer = setTimeout(async () => { await pulso(); programar() }, espera)
}

onMounted(async () => {
    await Promise.all([cargarOficina(), cargarCatalogos()])
    await pulso()
    programar()
})

onUnmounted(() => { activo = false; if (timer) clearTimeout(timer) })

// ─── Acciones ────────────────────────────────────────────────────────────────

async function lanzar(agente, probar) {
    mensaje.value = ''
    const r = await post(`/api/equipo-ia/agentes/${agente.id}/${probar ? 'probar' : 'ejecutar'}`)

    if (! r.ok) { mensaje.value = mensajeDe(r); return }

    esperando.value.push(agente.id)
    detalle.value = r.data.ejecucion_id
}

function terminoLaCorrida() {
    esperando.value = []
    cargarOficina()
}

async function pausar(pausado) {
    const r = await post('/api/equipo-ia/pausar', { pausado })
    if (r.ok) cargarOficina()
}

async function verActividad(agente) {
    detalle.value = agente.ultima?.id ?? null
}

function cerrarFormulario() { formulario.value = null }

async function guardado() {
    await Promise.all([cargarOficina(), cargarCatalogos()])
}

function probarDesdeFormulario(id) {
    formulario.value = null
    lanzar({ id }, true)
}

const pestanas = computed(() => [
    ['vivo', 'En vivo', true],
    ['oficina', 'Tarjetas', true],
    ['aprobaciones', 'Aprobaciones', props.permisos.ver || props.permisos.aprobar],
    ['cargos', 'Cargos', props.permisos.gestionar],
    ['consumo', 'Consumo', props.permisos.consumo],
].filter(p => p[2]))
</script>

<template>
    <div class="space-y-4">
        <p class="text-sm text-tinta-400 max-w-3xl">
            Agentes que trabajan para tu equipo: leen los datos con el rol de su cargo, avisan lo importante y dejan
            <strong class="text-tinta-600">propuestas</strong> que tú apruebas. Ninguno cambia nada por su cuenta.
        </p>

        <p v-if="mensaje" class="text-sm rounded-xl border border-borde-aviso-rojo bg-pastel-rojo text-aviso-rojo p-3">{{ mensaje }}</p>

        <!-- El formulario reemplaza la vista mientras se edita -->
        <FormAgente v-if="formulario" :agente-id="formulario.id"
            :cargos="catalogos.cargos" :herramientas="catalogos.herramientas" :usuarios="catalogos.usuarios"
            :sedes="catalogos.sedes" :variables="catalogos.variables" :permisos="permisos"
            @cerrar="cerrarFormulario" @guardado="guardado" @probar="probarDesdeFormulario" />

        <template v-else>
            <div class="flex gap-1 border-b border-linea overflow-x-auto">
                <button v-for="[clave, label] in pestanas" :key="clave" type="button" @click="vista = clave"
                    :class="['px-3.5 py-2 text-sm whitespace-nowrap -mb-px border-b-2 transition-colors',
                        vista === clave ? 'border-[var(--marca)] text-tinta-900 font-semibold' : 'border-transparent text-tinta-400 hover:text-tinta-700']">
                    {{ label }}
                    <span v-if="clave === 'aprobaciones' && oficina.pendientes > 0"
                        class="ml-1 text-[10px] px-1.5 py-0.5 rounded-full bg-pastel-naranja-2 text-aviso-naranja font-semibold">{{ oficina.pendientes }}</span>
                </button>
            </div>

            <OficinaEnVivo v-if="vista === 'vivo'" :agentes="oficina.agentes" :permisos="permisos" :pausado="oficina.pausado"
                :ia-lista="oficina.ia_lista" :cargando="cargando" :esperando="esperando"
                @configurar="a => formulario = { id: a.id }" @nuevo="formulario = { id: null }"
                @ejecutar="a => lanzar(a, false)" @probar="a => lanzar(a, true)" @actividad="verActividad"
                :tablero="oficina.tablero"
                @aprobaciones="vista = 'aprobaciones'" @pausar="pausar" @detalle="id => detalle = id" />

            <OficinaAgentes v-else-if="vista === 'oficina'" :agentes="oficina.agentes" :permisos="permisos" :pausado="oficina.pausado"
                :ia-lista="oficina.ia_lista" :cargando="cargando" :esperando="esperando"
                @configurar="a => formulario = { id: a.id }" @nuevo="formulario = { id: null }"
                @ejecutar="a => lanzar(a, false)" @probar="a => lanzar(a, true)" @actividad="verActividad"
                @aprobaciones="vista = 'aprobaciones'" @pausar="pausar" />

            <Aprobaciones v-else-if="vista === 'aprobaciones'" :permisos="permisos" :version="version" @cambio="cargarOficina" />

            <CargosEquipo v-else-if="vista === 'cargos'" :cargos="catalogos.cargos" :roles="catalogos.roles"
                :herramientas="catalogos.herramientas" :variables="catalogos.variables" @cambio="guardado" />

            <ConsumoEquipo v-else-if="vista === 'consumo'" />
        </template>

        <DetalleEjecucion v-if="detalle" :id="detalle" @cerrar="detalle = null" @terminada="terminoLaCorrida" />
    </div>
</template>
