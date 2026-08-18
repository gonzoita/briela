<script setup>
/**
 * Los agentes que atienden por fuera: la web y WhatsApp.
 *
 * Lo que se configura aquí no es «un chatbot»: es a quién atiende cada agente y qué puede ver.
 * El perfil decide el catálogo de consultas, y por eso las herramientas cambian con él — un
 * agente público no puede siquiera elegir «la cartera del cliente».
 */
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    agentes:               { type: Array, default: () => [] },
    herramientasPorPerfil: { type: Object, default: () => ({ publico: [], cliente: [] }) },
})

const vacio = () => ({
    id: null, nombre: '', descripcion: '', activo: false, perfil: 'publico',
    canales: ['whatsapp'], herramientas: [], instrucciones: '', saludo: '',
    escalamiento: ['lo_pide', 'no_sabe', 'asesor_asignado'], horario: { desde: '', hasta: '' },
})

const editando = ref(null)

const herramientas = computed(() => props.herramientasPorPerfil[editando.value?.perfil] ?? [])

function abrir(agente) {
    editando.value = agente
        ? JSON.parse(JSON.stringify({ ...vacio(), ...agente }))
        : vacio()
}

/** Al cambiar de perfil, las herramientas del anterior dejan de existir para este agente. */
function cambiarPerfil(perfil) {
    editando.value.perfil = perfil
    editando.value.herramientas = []
}

function alternar(lista, valor) {
    const i = lista.indexOf(valor)
    i >= 0 ? lista.splice(i, 1) : lista.push(valor)
}

function guardar() {
    const datos = { ...editando.value }
    const url   = datos.id ? `/configuracion/agentes/${datos.id}` : '/configuracion/agentes'

    const opciones = { preserveScroll: true, onSuccess: () => { editando.value = null } }

    datos.id ? router.put(url, datos, opciones) : router.post(url, datos, opciones)
}

function borrar(agente) {
    if (! confirm(`¿Eliminar el agente «${agente.nombre}»? Deja de atender de inmediato.`)) return

    router.delete(`/configuracion/agentes/${agente.id}`, { preserveScroll: true })
}

const etiquetaPerfil = (p) => p === 'cliente' ? 'Clientes verificados' : 'Público'

const motivos = [
    ['lo_pide',        'Cuando el cliente pide hablar con una persona'],
    ['no_sabe',        'Cuando no puede resolverlo con lo que tiene'],
    ['fuera_horario',  'Fuera del horario: responde, pero avisa'],
    ['asesor_asignado','Cuando el lead ya tiene un asesor asignado'],
]
</script>

<template>
    <AppLayout title="Agentes">
        <div class="max-w-5xl mx-auto">
            <div class="flex items-start justify-between gap-3 mb-5 flex-wrap">
                <div>
                    <h1 class="text-xl font-semibold text-tinta-900">Agentes</h1>
                    <p class="text-sm text-tinta-400 mt-0.5">
                        Quién atiende por WhatsApp y por la web, y hasta dónde puede llegar.
                    </p>
                </div>
                <button type="button" @click="abrir(null)"
                    class="px-4 py-2 rounded-xl text-white text-sm font-semibold" style="background:var(--marca);">
                    + Nuevo agente
                </button>
            </div>

            <!-- La lista -->
            <div class="space-y-3 mb-4">
                <div v-for="a in agentes" :key="a.id"
                    class="bg-superficie rounded-2xl shadow-sm p-4 flex items-start justify-between gap-3 flex-wrap">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-tinta-800">
                            {{ a.nombre }}
                            <span :class="['text-[10px] px-1.5 py-0.5 rounded-full ml-1',
                                a.activo ? 'bg-pastel-verde text-aviso-verde' : 'bg-tinta-100 text-tinta-400']">
                                {{ a.activo ? 'activo' : 'apagado' }}
                            </span>
                            <span :class="['text-[10px] px-1.5 py-0.5 rounded-full ml-1',
                                a.perfil === 'cliente' ? 'bg-pastel-violeta text-aviso-violeta' : 'bg-pastel-azul text-aviso-azul']">
                                {{ etiquetaPerfil(a.perfil) }}
                            </span>
                        </p>
                        <p v-if="a.descripcion" class="text-xs text-tinta-400 mt-0.5">{{ a.descripcion }}</p>
                        <p class="text-xs text-tinta-300 mt-1">
                            Atiende por {{ a.canales.join(' y ') || '— ningún canal —' }}
                        </p>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <button type="button" @click="abrir(a)"
                            class="text-xs px-3 py-1.5 rounded-lg border border-linea text-tinta-500 hover:bg-realce transition-colors">
                            Configurar
                        </button>
                        <button type="button" @click="borrar(a)"
                            class="text-xs px-3 py-1.5 rounded-lg border border-borde-aviso-rojo text-aviso-rojo hover:bg-pastel-rojo transition-colors">
                            Eliminar
                        </button>
                    </div>
                </div>

                <p v-if="! agentes.length" class="text-sm text-tinta-300 text-center py-8 border border-dashed border-linea rounded-2xl">
                    Todavía no hay agentes. El primero suele ser uno público para WhatsApp.
                </p>
            </div>

            <!-- El formulario -->
            <div v-if="editando" class="bg-superficie rounded-2xl shadow-sm p-5 space-y-4">
                <h2 class="text-sm font-semibold text-tinta-700 uppercase tracking-[0.12em]">
                    {{ editando.id ? 'Configurar agente' : 'Nuevo agente' }}
                </h2>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs text-tinta-400 mb-1">Nombre</label>
                        <input v-model="editando.nombre" type="text" maxlength="120" placeholder="Ana, de ventas"
                            class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]" />
                    </div>
                    <div>
                        <label class="block text-xs text-tinta-400 mb-1">Para qué es</label>
                        <input v-model="editando.descripcion" type="text" maxlength="300" placeholder="Atiende a quien escribe por primera vez"
                            class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]" />
                    </div>
                </div>

                <!-- El perfil manda: decide qué puede consultar -->
                <div>
                    <label class="block text-xs text-tinta-400 mb-1">A quién atiende</label>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <button type="button" @click="cambiarPerfil('publico')"
                            :class="['text-left border rounded-xl p-3 transition-colors',
                                editando.perfil === 'publico' ? 'border-[var(--marca)] bg-realce' : 'border-linea hover:bg-realce']">
                            <p class="text-sm font-semibold text-tinta-800">Público</p>
                            <p class="text-xs text-tinta-400 mt-0.5">
                                A quien no sabemos quién es. Solo ve lo que ya es público: la empresa, el
                                contacto y el catálogo. Ni un dato de ningún cliente.
                            </p>
                        </button>
                        <button type="button" @click="cambiarPerfil('cliente')"
                            :class="['text-left border rounded-xl p-3 transition-colors',
                                editando.perfil === 'cliente' ? 'border-[var(--marca)] bg-realce' : 'border-linea hover:bg-realce']">
                            <p class="text-sm font-semibold text-tinta-800">Clientes verificados</p>
                            <p class="text-xs text-tinta-400 mt-0.5">
                                A quien ya demostró quién es. Ve <strong>solo sus propios datos</strong>: el número
                                reconocido no basta, se le pide una orden suya, su apellido o su documento.
                            </p>
                        </button>
                    </div>
                </div>

                <div>
                    <label class="block text-xs text-tinta-400 mb-1">Por dónde atiende</label>
                    <div class="flex gap-4">
                        <label v-for="c in ['whatsapp', 'web']" :key="c" class="flex items-center gap-1.5 text-sm text-tinta-600 cursor-pointer">
                            <input type="checkbox" :checked="editando.canales.includes(c)"
                                @change="alternar(editando.canales, c)" class="accent-blue-600" />
                            {{ c === 'whatsapp' ? 'WhatsApp' : 'Chat de la web' }}
                        </label>
                    </div>
                </div>

                <div>
                    <label class="block text-xs text-tinta-400 mb-1">Qué puede consultar</label>
                    <div class="space-y-1.5">
                        <label v-for="h in herramientas" :key="h.clave" class="flex items-start gap-2 text-sm text-tinta-600 cursor-pointer">
                            <input type="checkbox" :checked="editando.herramientas.includes(h.clave)"
                                @change="alternar(editando.herramientas, h.clave)" class="accent-blue-600 mt-0.5" />
                            <span class="text-xs">{{ h.label }}</span>
                        </label>
                    </div>
                </div>

                <div>
                    <label class="block text-xs text-tinta-400 mb-1">Cómo debe atender</label>
                    <textarea v-model="editando.instrucciones" rows="4" maxlength="8000"
                        placeholder="El tono, qué preguntar primero, qué no prometer…"
                        class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]"></textarea>
                </div>

                <div>
                    <label class="block text-xs text-tinta-400 mb-1">Cuándo suelta la conversación</label>
                    <div class="space-y-1.5">
                        <label v-for="[clave, texto] in motivos" :key="clave" class="flex items-center gap-2 text-sm text-tinta-600 cursor-pointer">
                            <input type="checkbox" :checked="editando.escalamiento.includes(clave)"
                                @change="alternar(editando.escalamiento, clave)" class="accent-blue-600" />
                            <span class="text-xs">{{ texto }}</span>
                        </label>
                    </div>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs text-tinta-400 mb-1">Atiende desde</label>
                        <input v-model="editando.horario.desde" type="time"
                            class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]" />
                    </div>
                    <div>
                        <label class="block text-xs text-tinta-400 mb-1">Hasta</label>
                        <input v-model="editando.horario.hasta" type="time"
                            class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]" />
                    </div>
                    <div class="flex items-end">
                        <label class="flex items-center gap-2 text-sm text-tinta-600 cursor-pointer pb-2">
                            <input type="checkbox" v-model="editando.activo" class="accent-emerald-600" />
                            Activo
                        </label>
                    </div>
                </div>

                <div class="flex gap-3 pt-2 border-t border-linea">
                    <button type="button" @click="editando = null"
                        class="flex-1 py-2.5 rounded-xl border border-linea text-sm text-tinta-500 hover:bg-realce transition-colors">
                        Cancelar
                    </button>
                    <button type="button" @click="guardar" :disabled="! editando.nombre"
                        class="flex-1 py-2.5 rounded-xl text-white text-sm font-semibold disabled:opacity-50"
                        style="background:var(--marca);">
                        Guardar agente
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
