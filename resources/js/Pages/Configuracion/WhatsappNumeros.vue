<script setup>
import { ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    numeros: Array,
    usuarios: Array,
    conexion: { type: Object, default: () => ({ lista: false, faltantes: [], url_webhook: '' }) },
})

// ── Conexión con Meta ────────────────────────────────────────────────────────
const guiaAbierta = ref(false)
const copiado     = ref('')
const ocupado     = ref('')

const cred = ref({
    id:       props.conexion?.id_actual ?? '',
    secret:   '',
    redirect: props.conexion?.verify_actual ?? '',
})

const pasosMeta = [
    'Entra a developers.facebook.com y abre (o crea) una aplicación tipo Empresa.',
    'Agrégale el producto "WhatsApp".',
    'En WhatsApp → Configuración de la API, copia el "Identificador del número de teléfono" y genera un token de acceso.',
    'Usa un token permanente de un Usuario del Sistema del Business Manager: los temporales vencen en 24 horas.',
    'En WhatsApp → Configuración → Webhooks, pega la URL de devolución de llamada de abajo y el token de verificación que inventes.',
    'Suscribe el webhook al campo "messages" para recibir los mensajes entrantes.',
]

async function copiar(texto, marca) {
    try {
        await navigator.clipboard.writeText(texto)
        copiado.value = marca
        setTimeout(() => { copiado.value = '' }, 2000)
    } catch { /* si el navegador no deja copiar, el texto igual está a la vista */ }
}

function guardarCredenciales() {
    ocupado.value = 'guardar'
    router.post('/configuracion/whatsapp-numeros/credenciales', cred.value, {
        preserveScroll: true,
        onFinish: () => { ocupado.value = ''; cred.value.secret = '' },
    })
}

function probarConexion() {
    ocupado.value = 'probar'
    router.post('/configuracion/whatsapp-numeros/probar', {}, {
        preserveScroll: true,
        onFinish: () => { ocupado.value = '' },
    })
}

function desconectar() {
    if (!confirm('¿Desconectar WhatsApp?\n\nLos números y el historial de conversaciones se conservan. Solo se borran las credenciales, y puedes volver a conectarte cuando quieras.')) return
    ocupado.value = 'desconectar'
    router.post('/configuracion/whatsapp-numeros/desconectar', {}, {
        preserveScroll: true,
        onFinish: () => { ocupado.value = '' },
    })
}

const numeros = ref(props.numeros.map(n => ({ ...n })))

watch(() => props.numeros, (vals) => {
    numeros.value = vals.map(n => ({ ...n }))
}, { deep: true })

const formVacio = { nombre: '', numero_telefono: '', phone_number_id: '', rol: 'asesor', usuario_id: '', activo: true }
const form = ref({ ...formVacio })
const editando = ref(null)

const rolLabel = { central: 'Central', asesor: 'Asesor' }

function editar(n) {
    editando.value = n.id
    form.value = {
        nombre: n.nombre,
        numero_telefono: n.numero_telefono,
        phone_number_id: n.phone_number_id,
        rol: n.rol,
        usuario_id: n.usuario_id ?? '',
        activo: n.activo,
    }
}

function cancelar() {
    editando.value = null
    form.value = { ...formVacio }
}

function guardar() {
    const payload = { ...form.value, usuario_id: form.value.usuario_id || null }
    if (editando.value) {
        router.put(`/configuracion/whatsapp-numeros/${editando.value}`, payload, {
            preserveScroll: true,
            onSuccess: () => cancelar(),
        })
    } else {
        router.post('/configuracion/whatsapp-numeros', payload, {
            preserveScroll: true,
            onSuccess: () => cancelar(),
        })
    }
}

function eliminar(id) {
    if (!confirm('¿Eliminar este número? Si tiene conversaciones asociadas, solo se desactivará.')) return
    router.delete(`/configuracion/whatsapp-numeros/${id}`, { preserveScroll: true })
}
</script>

<template>
    <AppLayout title="Números de WhatsApp">
        <div class="max-w-3xl mx-auto">

            <div class="flex items-center gap-3 mb-5">
                <button
                    @click="router.visit('/configuracion')"
                    class="p-2 rounded-xl hover:bg-gray-100 transition-colors text-gray-500"
                    title="Volver"
                >
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                    </svg>
                </button>
                <h1 class="text-xl font-bold text-gray-900">Números de WhatsApp</h1>
            </div>

            <!-- Conexión con Meta -->
            <div class="bg-white rounded-2xl border border-gray-200 p-5 mb-4">
                <div class="flex items-center gap-2 flex-wrap mb-1">
                    <h2 class="text-sm font-semibold text-gray-700">Conexión con WhatsApp</h2>
                    <span v-if="conexion.lista"
                        class="text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-green-100 text-green-700 leading-none">Conectado</span>
                    <span v-else
                        class="text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-700 leading-none">Sin conectar</span>
                </div>
                <p class="text-xs text-gray-400 mb-3">
                    Sin esta conexión los números de abajo no pueden enviar ni recibir mensajes.
                </p>

                <div v-if="conexion.lista" class="flex flex-wrap gap-2">
                    <button type="button" @click="probarConexion" :disabled="ocupado === 'probar'"
                        class="px-3 py-2 rounded-xl border border-gray-200 text-xs font-semibold text-gray-700 hover:bg-gray-50 disabled:opacity-50">
                        {{ ocupado === 'probar' ? 'Probando...' : 'Probar conexión' }}
                    </button>
                    <button type="button" @click="guiaAbierta = !guiaAbierta"
                        class="px-3 py-2 rounded-xl border border-gray-200 text-xs font-semibold text-gray-700 hover:bg-gray-50">
                        Cambiar credenciales
                    </button>
                    <button type="button" @click="desconectar" :disabled="ocupado === 'desconectar'"
                        class="px-3 py-2 rounded-xl border border-red-200 text-xs font-semibold text-red-600 hover:bg-red-50 disabled:opacity-50">
                        {{ ocupado === 'desconectar' ? 'Desconectando...' : 'Desconectar' }}
                    </button>
                </div>

                <button v-else type="button" @click="guiaAbierta = !guiaAbierta"
                    class="flex items-center gap-2 text-xs font-semibold text-gray-600 hover:text-gray-800">
                    <svg class="w-3.5 h-3.5 transition-transform" :class="guiaAbierta ? 'rotate-90' : ''"
                        fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                    </svg>
                    ¿Primera vez? Cómo conectar WhatsApp
                </button>

                <div v-if="guiaAbierta" class="mt-3 text-xs text-gray-600 leading-relaxed">
                    <div class="mb-3 rounded-lg bg-amber-50 border border-amber-100 p-2.5 text-amber-800">
                        <p class="font-semibold mb-1">Antes de empezar, asegúrate de:</p>
                        <ul class="list-disc list-inside space-y-0.5">
                            <li>Tener una cuenta de WhatsApp Business API en Meta (no sirve WhatsApp normal ni Business de la tienda de apps).</li>
                            <li>Ser administrador del Business Manager donde vive el número.</li>
                        </ul>
                    </div>

                    <p class="mb-1">
                        1. Configura la aplicación en
                        <a href="https://developers.facebook.com" target="_blank" rel="noopener"
                           class="font-semibold underline" style="color:var(--marca);">developers.facebook.com</a>:
                    </p>
                    <ol class="list-decimal list-inside space-y-1 mb-3 pl-1">
                        <li v-for="(paso, i) in pasosMeta" :key="i">{{ paso }}</li>
                    </ol>

                    <p class="mb-1">2. Esta es la <strong>URL de devolución de llamada</strong> del webhook:</p>
                    <div class="flex items-center gap-2 mb-3">
                        <code class="flex-1 min-w-0 truncate bg-gray-50 border border-gray-200 rounded-lg px-2 py-1.5 text-[11px] text-gray-700">{{ conexion.url_webhook }}</code>
                        <button type="button" @click="copiar(conexion.url_webhook, 'webhook')"
                            class="shrink-0 px-2.5 py-1.5 rounded-lg border border-gray-200 text-[11px] font-semibold text-gray-600 hover:bg-gray-50">
                            {{ copiado === 'webhook' ? 'Copiada' : 'Copiar' }}
                        </button>
                    </div>

                    <p class="mb-1.5">3. Pega acá lo que te dio Meta:</p>
                    <div class="space-y-2">
                        <input v-model="cred.id" type="text" placeholder="Identificador del número de teléfono (Phone Number ID)"
                            class="w-full bg-white border border-gray-200 rounded-lg px-2.5 py-2 text-[12px] focus:outline-none focus:border-blue-400" />
                        <input v-model="cred.secret" type="password"
                            :placeholder="conexion.tiene_secreto ? 'Token de acceso (ya hay uno guardado — deja vacío para conservarlo)' : 'Token de acceso permanente'"
                            class="w-full bg-white border border-gray-200 rounded-lg px-2.5 py-2 text-[12px] focus:outline-none focus:border-blue-400" />
                        <input v-model="cred.redirect" type="text" placeholder="Token de verificación del webhook (lo inventas tú)"
                            class="w-full bg-white border border-gray-200 rounded-lg px-2.5 py-2 text-[12px] focus:outline-none focus:border-blue-400" />
                        <button type="button" @click="guardarCredenciales" :disabled="ocupado === 'guardar'"
                            class="w-full py-2 rounded-lg text-[12px] font-semibold text-white disabled:opacity-50"
                            style="background:var(--marca);">
                            {{ ocupado === 'guardar' ? 'Guardando...' : 'Guardar conexión' }}
                        </button>
                    </div>
                    <p class="mt-1.5 text-gray-400">
                        El token se guarda cifrado y no se vuelve a mostrar. El de verificación es una
                        contraseña que inventas y que debe quedar igual acá y en Meta.
                    </p>
                </div>
            </div>

            <!-- Lista -->
            <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden mb-4">
                <div class="px-5 py-3 border-b border-gray-100">
                    <h2 class="text-sm font-semibold text-gray-700">Números registrados</h2>
                    <p class="text-xs text-gray-400 mt-0.5">Cada asesor conserva su app y su historial (modo Coexistencia).</p>
                </div>

                <div v-if="!numeros.length" class="py-10 text-center text-sm text-gray-400">
                    Sin números configurados.
                </div>

                <div class="divide-y divide-gray-50">
                    <div
                        v-for="n in numeros"
                        :key="n.id"
                        class="flex items-center gap-3 px-4 py-3"
                    >
                        <!-- Icono WhatsApp -->
                        <div class="w-9 h-9 rounded-xl flex items-center justify-center shrink-0"
                            :class="n.activo ? 'bg-blue-50' : 'bg-gray-100'"
                        >
                            <svg class="w-4 h-4" :class="n.activo ? 'text-[var(--marca)]' : 'text-gray-400'"
                                fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-6l-4 4v-4z" />
                            </svg>
                        </div>

                        <!-- Info -->
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-800 truncate">{{ n.nombre }}</p>
                            <p class="text-xs text-gray-400 truncate">
                                {{ n.numero_telefono }}
                                <span v-if="n.usuario"> · {{ n.usuario.name }}</span>
                            </p>
                        </div>

                        <!-- Badge rol -->
                        <span
                            class="text-xs px-2 py-0.5 rounded-full shrink-0"
                            :class="n.rol === 'central' ? 'bg-[var(--marca)] text-white font-semibold' : 'bg-gray-100 text-gray-600'"
                        >
                            {{ rolLabel[n.rol] ?? n.rol }}
                        </span>

                        <!-- Badge activo -->
                        <span
                            class="text-xs px-2 py-0.5 rounded-full shrink-0"
                            :class="n.activo ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'"
                        >
                            {{ n.activo ? 'Activo' : 'Inactivo' }}
                        </span>

                        <button @click="editar(n)" class="text-xs text-blue-600 hover:underline shrink-0">Editar</button>
                        <button @click="eliminar(n.id)" class="text-xs text-red-500 hover:underline shrink-0">Eliminar</button>
                    </div>
                </div>
            </div>

            <!-- Formulario crear / editar -->
            <div class="bg-white rounded-2xl border border-gray-200 p-5">
                <h3 class="text-sm font-semibold text-gray-700 mb-4">
                    {{ editando ? 'Editar número' : 'Nuevo número' }}
                </h3>

                <div class="space-y-3">
                    <!-- Nombre -->
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1.5">
                            Nombre *
                        </label>
                        <input
                            v-model="form.nombre"
                            type="text"
                            placeholder="Ej: Renier Dominguez"
                            class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                        />
                    </div>

                    <!-- Número de teléfono -->
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1.5">
                            Número de teléfono *
                        </label>
                        <input
                            v-model="form.numero_telefono"
                            type="text"
                            placeholder="+573001234567"
                            class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                        />
                    </div>

                    <!-- Phone Number ID -->
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1.5">
                            Phone Number ID (Meta) *
                        </label>
                        <input
                            v-model="form.phone_number_id"
                            type="text"
                            placeholder="ID asignado por Meta"
                            class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                        />
                    </div>

                    <!-- Rol -->
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1.5">
                            Rol *
                        </label>
                        <select
                            v-model="form.rol"
                            class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white"
                        >
                            <option value="asesor">Asesor</option>
                            <option value="central">Central</option>
                        </select>
                    </div>

                    <!-- Usuario asociado -->
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1.5">
                            Usuario asociado
                        </label>
                        <select
                            v-model="form.usuario_id"
                            class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white"
                        >
                            <option value="">Sin asignar</option>
                            <option v-for="u in usuarios" :key="u.id" :value="u.id">{{ u.name }}</option>
                        </select>
                    </div>

                    <!-- Activo -->
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input v-model="form.activo" type="checkbox" class="rounded" />
                        <span class="text-sm text-gray-700">Activo</span>
                    </label>

                    <!-- Botones -->
                    <div class="flex gap-3 pt-1">
                        <button
                            v-if="editando"
                            @click="cancelar"
                            class="flex-1 py-2.5 rounded-xl border border-gray-200 text-sm text-gray-600"
                        >
                            Cancelar
                        </button>
                        <button
                            @click="guardar"
                            class="flex-1 py-2.5 rounded-xl text-white text-sm font-semibold"
                            style="background:var(--marca);"
                        >
                            {{ editando ? 'Actualizar' : 'Crear número' }}
                        </button>
                    </div>
                </div>
            </div>

        </div>
    </AppLayout>
</template>
