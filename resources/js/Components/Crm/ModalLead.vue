<script setup>
import { ref, watch } from 'vue'

const props = defineProps({
    etapaIdInicial: { type: Number, default: null },
    etapas:         { type: Array,  default: () => [] },
    usuarios:       { type: Array,  default: () => [] },
    clientes:       { type: Array,  default: () => [] },
})

const emit = defineEmits(['guardado', 'cerrar'])

const guardando = ref(false)
const errores   = ref({})

const form = ref({
    etapa_id:          props.etapaIdInicial ?? props.etapas[0]?.id ?? null,
    titulo:            '',
    nombre_contacto:   '',
    email_contacto:    '',
    telefono_contacto: '',
    empresa_contacto:  '',
    descripcion:       '',
    fuente:            '',
    responsable_id:    null,
    cliente_id:        null,
})

watch(() => props.etapaIdInicial, (v) => {
    if (v) form.value.etapa_id = v
})

const fuentes = ['Instagram', 'Facebook', 'WhatsApp', 'Referido', 'Web', 'Llamada', 'Otro']

function getCookie(name) {
    const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'))
    return match ? decodeURIComponent(match[2]) : ''
}

async function guardar() {
    errores.value = {}
    if (!form.value.titulo.trim()) {
        errores.value.titulo = 'El título es requerido'
        return
    }
    guardando.value = true

    const res = await fetch('/crm/leads', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': getCookie('XSRF-TOKEN') },
        credentials: 'same-origin',
        body: JSON.stringify(form.value),
    })

    guardando.value = false

    if (!res.ok) {
        const data = await res.json()
        errores.value = data.errors ?? {}
        return
    }

    const data = await res.json()
    emit('guardado', data.lead)
}
</script>

<template>
    <teleport to="body">
        <div class="fixed inset-0 z-[60] flex items-end sm:items-center justify-center p-0 sm:p-4"
            style="background: rgba(0,0,0,0.5);"
            @click.self="emit('cerrar')"
        >
            <div class="bg-superficie w-full sm:max-w-lg rounded-t-2xl sm:rounded-2xl shadow-2xl flex flex-col max-h-[90vh]">
                <!-- Header -->
                <div class="flex items-center justify-between px-5 py-4 border-b border-linea shrink-0">
                    <h2 class="text-base font-semibold text-tinta-900">Nueva oportunidad</h2>
                    <button @click="emit('cerrar')" class="w-8 h-8 flex items-center justify-center rounded-xl hover:bg-tinta-100 transition-colors">
                        <svg class="w-5 h-5 text-tinta-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <!-- Body -->
                <div class="overflow-y-auto flex-1 px-5 py-4 space-y-4">

                    <!-- Título -->
                    <div>
                        <label class="block text-xs font-semibold text-tinta-500 mb-1">Título <span class="text-aviso-rojo">*</span></label>
                        <input
                            v-model="form.titulo"
                            type="text"
                            placeholder="Ej: Cuarto frío 20m² Empresa X"
                            class="w-full border border-linea rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]"
                            :class="{ 'border-red-400': errores.titulo }"
                        />
                        <p v-if="errores.titulo" class="text-xs text-aviso-rojo mt-1">{{ errores.titulo }}</p>
                    </div>

                    <!-- Etapa -->
                    <div>
                        <label class="block text-xs font-semibold text-tinta-500 mb-1">Etapa</label>
                        <select v-model="form.etapa_id"
                            class="w-full border border-linea rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)] bg-superficie">
                            <option v-for="e in etapas" :key="e.id" :value="e.id">{{ e.nombre }}</option>
                        </select>
                    </div>

                    <!-- Fila: nombre + empresa -->
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-tinta-500 mb-1">Nombre contacto</label>
                            <input v-model="form.nombre_contacto" type="text"
                                class="w-full border border-linea rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]"/>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-tinta-500 mb-1">Empresa</label>
                            <input v-model="form.empresa_contacto" type="text"
                                class="w-full border border-linea rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]"/>
                        </div>
                    </div>

                    <!-- Fila: email + teléfono -->
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-tinta-500 mb-1">Email</label>
                            <input v-model="form.email_contacto" type="email"
                                class="w-full border border-linea rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]"/>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-tinta-500 mb-1">Teléfono</label>
                            <input v-model="form.telefono_contacto" type="tel"
                                class="w-full border border-linea rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]"/>
                        </div>
                    </div>

                    <!-- Fuente -->
                    <div>
                        <label class="block text-xs font-semibold text-tinta-500 mb-1">Fuente</label>
                        <select v-model="form.fuente"
                            class="w-full border border-linea rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)] bg-superficie">
                            <option value="">Sin especificar</option>
                            <option v-for="f in fuentes" :key="f" :value="f">{{ f }}</option>
                        </select>
                    </div>

                    <!-- Responsable -->
                    <div>
                        <label class="block text-xs font-semibold text-tinta-500 mb-1">Responsable</label>
                        <select v-model="form.responsable_id"
                            class="w-full border border-linea rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)] bg-superficie">
                            <option :value="null">Sin asignar</option>
                            <option v-for="u in usuarios" :key="u.id" :value="u.id">{{ u.name }}</option>
                        </select>
                    </div>

                    <!-- Cliente existente -->
                    <div>
                        <label class="block text-xs font-semibold text-tinta-500 mb-1">Cliente existente (opcional)</label>
                        <select v-model="form.cliente_id"
                            class="w-full border border-linea rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)] bg-superficie">
                            <option :value="null">Ninguno</option>
                            <option v-for="c in clientes" :key="c.id" :value="c.id">{{ c.nombre }}</option>
                        </select>
                    </div>

                    <!-- Descripción -->
                    <div>
                        <label class="block text-xs font-semibold text-tinta-500 mb-1">Descripción</label>
                        <textarea v-model="form.descripcion" rows="3"
                            class="w-full border border-linea rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)] resize-none"
                            placeholder="Detalles del proyecto, necesidades, etc."></textarea>
                    </div>
                </div>

                <!-- Footer -->
                <div class="px-5 py-4 border-t border-linea flex gap-3 shrink-0">
                    <button @click="emit('cerrar')"
                        class="flex-1 py-2.5 rounded-xl text-sm font-semibold text-tinta-500 border border-linea hover:bg-tinta-50 transition-colors">
                        Cancelar
                    </button>
                    <button @click="guardar" :disabled="guardando"
                        class="flex-1 py-2.5 rounded-xl text-sm font-semibold text-white transition-opacity disabled:opacity-60"
                        style="background-color: var(--marca);">
                        {{ guardando ? 'Guardando…' : 'Guardar' }}
                    </button>
                </div>
            </div>
        </div>
    </teleport>
</template>
