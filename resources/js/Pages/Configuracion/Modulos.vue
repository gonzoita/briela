<script setup>
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    modulos:     { type: Array,   default: () => [] },
    cambiado_at: { type: String,  default: null },
    origen:      { type: String,  default: null },
    puedeEditar: { type: Boolean, default: false },
})

const porClave = computed(() => Object.fromEntries(props.modulos.map(m => [m.clave, m])))

const grupos = computed(() => {
    const salida = []
    for (const m of props.modulos) {
        let g = salida.find(x => x.nombre === m.grupo)
        if (! g) salida.push(g = { nombre: m.grupo, modulos: [] })
        g.modulos.push(m)
    }
    return salida
})

/** Los que dependen de este, en cadena: la misma cuenta que hace el servidor, para anunciarla. */
function dependientes(clave) {
    const resultado = []
    const pendientes = [clave]
    while (pendientes.length) {
        const actual = pendientes.shift()
        for (const m of props.modulos) {
            if (m.depende.includes(actual) && ! resultado.includes(m.clave)) {
                resultado.push(m.clave)
                pendientes.push(m.clave)
            }
        }
    }
    return resultado
}

function requeridos(clave) {
    const resultado = []
    const pendientes = [...(porClave.value[clave]?.depende ?? [])]
    while (pendientes.length) {
        const actual = pendientes.shift()
        if (resultado.includes(actual)) continue
        resultado.push(actual)
        pendientes.push(...(porClave.value[actual]?.depende ?? []))
    }
    return resultado
}

const nombres = (claves) => claves.map(c => porClave.value[c]?.label ?? c).join(', ')

// La confirmación va en la misma tarjeta: apagar un módulo cambia cómo trabaja la planta, y eso
// se lee antes de aceptarlo, no en un cuadro del navegador que se cierra con Enter.
const confirmando = ref(null)
const guardando   = ref(null)

function pedir(m) {
    if (! props.puedeEditar || guardando.value) return
    confirmando.value = confirmando.value === m.clave ? null : m.clave
}

function aplicar(m) {
    guardando.value = m.clave
    router.post('/configuracion/modulos', { clave: m.clave, activo: ! m.activo }, {
        preserveScroll: true,
        onFinish: () => { guardando.value = null; confirmando.value = null },
    })
}

const efectoDe = (m) => {
    if (m.activo) {
        return {
            titulo: `Apagar «${m.label}»`,
            arrastra: dependientes(m.clave).filter(c => porClave.value[c]?.activo),
        }
    }
    return {
        titulo: `Encender «${m.label}»`,
        arrastra: requeridos(m.clave).filter(c => ! porClave.value[c]?.activo),
    }
}

const fecha = computed(() => props.cambiado_at
    ? new Date(props.cambiado_at).toLocaleString('es-CO', { dateStyle: 'medium', timeStyle: 'short' })
    : null)
</script>

<template>
    <AppLayout title="Módulos">
        <div class="max-w-3xl mx-auto">

            <div class="flex items-center gap-3 mb-5">
                <button @click="router.visit('/configuracion')"
                    class="p-2 rounded-xl hover:bg-realce transition-all duration-200 active:scale-[0.94] text-tinta-400" title="Volver">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                    </svg>
                </button>
                <h1 class="text-xl font-semibold text-tinta-900">Módulos</h1>
            </div>

            <div class="mb-5 rounded-xl border border-borde-aviso-azul bg-pastel-azul p-4">
                <p class="text-sm font-semibold text-aviso-azul">Apaga lo que la empresa no usa.</p>
                <p class="text-xs text-aviso-azul mt-1.5">
                    Un módulo apagado desaparece del menú y de todas las pantallas, para todos los usuarios.
                    <strong>No se borra ningún dato:</strong> al encenderlo otra vez, todo está donde estaba.
                </p>
                <p class="text-xs text-aviso-azul mt-1.5">
                    El núcleo —Dashboard, Clientes, Productos, Usuarios, Configuración, el asistente y los agentes de IA— no se apaga.
                </p>
                <p v-if="fecha" class="text-xs text-aviso-azul mt-1.5">
                    Último cambio: {{ fecha }}{{ origen === 'superadmin' ? ', desde el panel de Briela' : '' }}.
                </p>
            </div>

            <div v-for="g in grupos" :key="g.nombre" class="mb-5">
                <p class="px-1 mb-2 text-xs font-semibold text-tinta-400">{{ g.nombre }}</p>

                <div class="rounded-2xl border border-linea bg-superficie divide-y divide-separador overflow-hidden">
                    <div v-for="m in g.modulos" :key="m.clave" class="p-4">
                        <div class="flex items-start gap-3">
                            <div class="flex-1 min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="text-sm font-semibold" :class="m.activo ? 'text-tinta-900' : 'text-tinta-400'">{{ m.label }}</p>
                                    <span v-if="m.por_arrastre"
                                        class="text-[11px] font-medium px-2 py-0.5 rounded-full bg-pastel-ambar-2 text-aviso-ambar">
                                        Apagado porque depende de otro
                                    </span>
                                </div>
                                <p v-if="m.depende.length" class="text-xs text-tinta-400 mt-0.5">Necesita: {{ nombres(m.depende) }}</p>
                                <p class="text-xs text-tinta-400 mt-1">{{ m.al_apagar }}</p>
                            </div>

                            <!-- Interruptor. 44 px de ancho: se toca con el dedo. -->
                            <button
                                type="button"
                                role="switch"
                                :aria-checked="m.activo"
                                :aria-label="(m.activo ? 'Apagar ' : 'Encender ') + m.label"
                                :disabled="! puedeEditar || guardando === m.clave"
                                @click="pedir(m)"
                                class="relative shrink-0 w-11 h-6 mt-0.5 rounded-full transition-all duration-200 ease-out active:scale-[0.94]
                                       focus:outline-none focus-visible:ring-1 focus-visible:ring-[var(--marca-borde)] disabled:opacity-50"
                                :class="m.activo ? 'bg-[var(--marca)]' : 'bg-tinta-200'"
                            >
                                <span class="absolute top-0.5 left-0.5 w-5 h-5 rounded-full bg-white shadow-sm transition-transform duration-200"
                                    :class="m.activo ? 'translate-x-5' : ''" />
                            </button>
                        </div>

                        <!-- Confirmación en la tarjeta -->
                        <div v-if="confirmando === m.clave"
                            class="mt-3 rounded-xl border p-3"
                            :class="m.activo ? 'border-borde-aviso-ambar bg-pastel-ambar' : 'border-borde-aviso-verde bg-pastel-verde'">
                            <p class="text-sm font-semibold" :class="m.activo ? 'text-aviso-ambar' : 'text-aviso-verde'">
                                {{ efectoDe(m).titulo }}
                            </p>
                            <p v-if="m.activo" class="text-xs text-aviso-ambar mt-1">{{ m.al_apagar }}</p>
                            <p v-if="efectoDe(m).arrastra.length" class="text-xs mt-1"
                                :class="m.activo ? 'text-aviso-ambar' : 'text-aviso-verde'">
                                {{ m.activo ? 'También se apagan' : 'También se encienden' }}:
                                <strong>{{ nombres(efectoDe(m).arrastra) }}</strong>.
                            </p>
                            <div class="flex gap-2 mt-3">
                                <button type="button" @click="aplicar(m)" :disabled="guardando === m.clave"
                                    class="px-3 py-1.5 rounded-xl text-xs font-semibold text-white transition-all duration-200 active:scale-[0.98] disabled:opacity-50"
                                    :class="m.activo ? 'bg-amber-600' : 'bg-green-600'">
                                    {{ guardando === m.clave ? 'Guardando…' : (m.activo ? 'Sí, apagar' : 'Sí, encender') }}
                                </button>
                                <button type="button" @click="confirmando = null"
                                    class="px-3 py-1.5 rounded-xl text-xs font-medium text-tinta-500 hover:bg-realce transition-all duration-200 active:scale-[0.98]">
                                    Cancelar
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <p v-if="! puedeEditar" class="text-xs text-tinta-400 px-1">
                Para cambiar los módulos hace falta el permiso de editar la configuración.
            </p>
        </div>
    </AppLayout>
</template>
