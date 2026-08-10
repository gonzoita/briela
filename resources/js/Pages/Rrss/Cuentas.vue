<script setup>
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

import { ref, reactive } from 'vue'

const props = defineProps({
    cuentas: Array,
    configuracion: { type: Object, default: () => ({}) },
})

const guiaAbierta = ref(false)
const copiado     = ref('')
const guardando   = ref('')

const estado = (red) => props.configuracion?.[red] ?? { lista: true, faltantes: [], url_retorno: '' }

// Formulario de credenciales por red. El secreto arranca vacío a propósito:
// nunca se trae del servidor, y dejarlo en blanco conserva el que ya está.
const form = reactive({})
for (const red of ['meta', 'linkedin', 'google']) {
    form[red] = {
        id:       props.configuracion?.[red]?.id_actual ?? '',
        secret:   '',
        redirect: props.configuracion?.[red]?.url_retorno ?? '',
    }
}

function guardarCredenciales(red) {
    guardando.value = red
    router.post(`/rrss/cuentas/credenciales/${red}`, form[red], {
        preserveScroll: true,
        onFinish: () => { guardando.value = ''; form[red].secret = '' },
    })
}

async function copiar(texto, marca) {
    try {
        await navigator.clipboard.writeText(texto)
        copiado.value = marca
        setTimeout(() => { copiado.value = '' }, 2000)
    } catch { /* si el navegador no deja copiar, el texto igual está a la vista */ }
}

const redes = [
    {
        key: 'meta', label: 'Instagram / Facebook', icon: '📷',
        descripcion: 'Conecta tu página de Facebook (e Instagram, si está ligado a ella). No requiere aprobación de Meta.',
        portal: 'developers.facebook.com',
        portalUrl: 'https://developers.facebook.com',
        requisitos: [
            'Ser administrador de la página de Facebook (no basta con ser editor).',
            'Para Instagram: que la cuenta sea profesional y esté vinculada a esa página.',
        ],
        pasos: [
            'Entra al portal y elige Mis aplicaciones → Crear aplicación.',
            'Escoge el tipo Empresa y ponle un nombre reconocible.',
            'Agrégale el producto "Inicio de sesión con Facebook".',
            'En ese producto → Configuración, pega la URL de retorno de arriba en "URI de redireccionamiento de OAuth válidos" y guarda.',
            'Si vas a publicar en Instagram, agrégale también el producto de Instagram.',
            'Ve a Configuración → Básica y copia el Identificador de la aplicación y la Clave secreta.',
        ],
    },
    {
        key: 'linkedin', label: 'LinkedIn (página de empresa)', icon: '💼',
        descripcion: 'Requiere que LinkedIn haya aprobado el acceso a la Community Management API para esta app.',
        portal: 'developer.linkedin.com',
        portalUrl: 'https://developer.linkedin.com',
        requisitos: [
            'Ser administrador de la página de empresa en LinkedIn.',
            'La aprobación de LinkedIn no es automática: puede tardar días.',
        ],
        pasos: [
            'Entra al portal y crea una app, asociándola a la página de empresa.',
            'En Products, solicita "Community Management API" y espera la aprobación.',
            'En Auth → Redirect URLs, pega la URL de retorno de arriba.',
            'En esa misma pantalla copia el Client ID y el Client Secret.',
        ],
    },
    {
        key: 'google', label: 'Google Business Profile', icon: '📍',
        descripcion: 'Requiere que Google haya aprobado el acceso a la Business Profile API.',
        portal: 'console.cloud.google.com',
        portalUrl: 'https://console.cloud.google.com',
        requisitos: [
            'Administrar la ficha de la empresa en Google Business Profile.',
            'Google revisa la solicitud de acceso y suele tardar unas dos semanas.',
        ],
        pasos: [
            'Crea un proyecto en Google Cloud Console.',
            'Habilita LAS DOS APIs: "My Business Account Management API" y "My Business Business Information API". Si falta una, la conexión falla diciendo que la API no está habilitada.',
            'Configura la pantalla de consentimiento OAuth y crea credenciales de tipo "ID de cliente de OAuth" para aplicación web.',
            'En "URI de redirección autorizados", pega la URL de retorno de arriba.',
            'Copia el ID de cliente y el Secreto del cliente.',
        ],
        aviso: 'Google mantiene la cuota en CERO hasta que aprueba tu solicitud, y mientras tanto la conexión falla con "Quota exceeded" (no es exceso de tráfico). Hay que llenar el formulario de acceso y esperar unas dos semanas.',
        enlace: {
            url: 'https://support.google.com/business/contact/api_default',
            texto: 'Formulario de acceso a Google Business Profile',
        },
    },
]

const redLabel = { instagram: 'Instagram', facebook: 'Facebook', linkedin: 'LinkedIn', google_business: 'Google Business Profile' }

function conectar(red) {
    router.visit(`/rrss/cuentas/conectar/${red}`)
}

function desconectar(c) {
    if (!confirm(`¿Desconectar "${c.nombre_cuenta}"?`)) return
    router.delete(`/rrss/cuentas/${c.id}`, { preserveScroll: true })
}

function reactivar(c) {
    router.post(`/rrss/cuentas/${c.id}/reactivar`, {}, { preserveScroll: true })
}
</script>

<template>
    <AppLayout title="Cuentas de Redes Sociales">
        <div class="max-w-2xl mx-auto px-4 py-4">

            <div class="flex items-center gap-3 mb-5">
                <button @click="router.visit('/rrss')" class="p-2 rounded-xl hover:bg-tinta-100 text-tinta-400">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                    </svg>
                </button>
                <h1 class="text-xl font-semibold text-tinta-900">Cuentas conectadas</h1>
            </div>

            <!-- Conectar nuevas -->
            <div class="bg-superficie rounded-2xl border border-linea p-5 mb-4">
                <h2 class="text-sm font-semibold text-tinta-700 mb-3">Conectar una cuenta</h2>
                <div class="space-y-2">
                    <button v-for="r in redes" :key="r.key" @click="conectar(r.key)"
                        class="w-full flex items-center gap-3 p-3 rounded-xl border border-linea hover:bg-tinta-50 text-left">
                        <span class="text-xl">{{ r.icon }}</span>
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 flex-wrap">
                                <p class="text-sm font-medium text-tinta-900">{{ r.label }}</p>
                                <span v-if="estado(r.key).lista"
                                    class="text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-green-100 text-green-700 leading-none">
                                    Listo para conectar
                                </span>
                                <span v-else
                                    class="text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-700 leading-none">
                                    Falta configurar
                                </span>
                            </div>
                            <p class="text-xs text-tinta-300 mt-0.5">{{ r.descripcion }}</p>
                        </div>
                    </button>
                </div>

                <!-- Guía de configuración inicial (se hace una sola vez) -->
                <div class="mt-4 pt-4 border-t border-linea">
                    <button @click="guiaAbierta = !guiaAbierta"
                        class="flex items-center gap-2 text-xs font-semibold text-tinta-500 hover:text-tinta-900">
                        <svg class="w-3.5 h-3.5 transition-transform" :class="guiaAbierta ? 'rotate-90' : ''"
                            fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                        </svg>
                        ¿Primera vez? Cómo dejar lista una red
                    </button>

                    <div v-if="guiaAbierta" class="mt-3 space-y-4 text-xs text-tinta-500 leading-relaxed">
                        <p>
                            Las redes no permiten publicar desde otro sistema con solo usuario y
                            contraseña: exigen registrar una aplicación una única vez. Después de
                            ese trámite, conectar una cuenta es solo iniciar sesión y autorizar.
                        </p>

                        <div v-for="r in redes" :key="r.key" class="rounded-xl bg-tinta-50 p-3">
                            <p class="font-semibold text-tinta-700 mb-1">{{ r.icon }} {{ r.label }}</p>

                            <p v-if="estado(r.key).lista" class="text-green-700">
                                Ya tiene sus credenciales cargadas. Solo falta oprimir el botón de arriba.
                            </p>

                            <template v-else>
                                <p class="mb-2">
                                    Registra la aplicación en la red y pega aquí lo que te dé.
                                    <strong>No hace falta entrar al servidor</strong>: se guarda desde esta pantalla.
                                </p>

                                <div v-if="r.requisitos?.length" class="mb-3 rounded-lg bg-amber-50 border border-amber-100 p-2.5">
                                    <p class="font-semibold text-amber-800 mb-1">Antes de empezar, asegúrate de:</p>
                                    <ul class="list-disc list-inside space-y-0.5 text-amber-800">
                                        <li v-for="(req, i) in r.requisitos" :key="i">{{ req }}</li>
                                    </ul>
                                </div>

                                <p class="mb-1">
                                    1. Registra la aplicación en
                                    <a :href="r.portalUrl" target="_blank" rel="noopener"
                                       class="font-semibold underline" style="color:var(--marca);">{{ r.portal }}</a>
                                    siguiendo estos pasos:
                                </p>
                                <ol class="list-decimal list-inside space-y-1 mb-2 pl-1">
                                    <li v-for="(paso, i) in r.pasos" :key="i">{{ paso }}</li>
                                </ol>

                                <p v-if="r.enlace" class="mb-2">
                                    <a :href="r.enlace.url" target="_blank" rel="noopener"
                                       class="font-semibold underline" style="color:var(--marca);">{{ r.enlace.texto }}</a>
                                </p>

                                <p v-if="r.aviso" class="mb-3 rounded-lg bg-blue-50 border border-blue-100 p-2.5 text-blue-800">
                                    {{ r.aviso }}
                                </p>

                                <p class="mb-1">2. Cuando te pida la <strong>URL de retorno</strong>, es esta:</p>
                                <div class="flex items-center gap-2 mb-3">
                                    <code class="flex-1 min-w-0 truncate bg-superficie border border-linea rounded-lg px-2 py-1.5 text-[11px] text-tinta-700">{{ estado(r.key).url_retorno }}</code>
                                    <button type="button" @click="copiar(estado(r.key).url_retorno, r.key)"
                                        class="shrink-0 px-2.5 py-1.5 rounded-lg border border-linea bg-superficie text-[11px] font-semibold text-tinta-500 hover:bg-tinta-50">
                                        {{ copiado === r.key ? 'Copiada' : 'Copiar' }}
                                    </button>
                                </div>
                                <p class="mb-1.5 text-tinta-300">Tiene que quedar idéntica, carácter por carácter.</p>

                                <p class="mb-1.5">3. Copia de la red sus credenciales y pégalas acá:</p>
                                <div class="space-y-2">
                                    <input v-model="form[r.key].id" type="text" placeholder="Identificador de la aplicación (App ID)"
                                        class="w-full bg-superficie border border-linea rounded-lg px-2.5 py-2 text-[12px] focus:outline-none focus:border-[var(--marca)]" />
                                    <input v-model="form[r.key].secret" type="password"
                                        :placeholder="estado(r.key).tiene_secreto ? 'Clave secreta (ya hay una guardada — deja vacío para conservarla)' : 'Clave secreta (App Secret)'"
                                        class="w-full bg-superficie border border-linea rounded-lg px-2.5 py-2 text-[12px] focus:outline-none focus:border-[var(--marca)]" />
                                    <button type="button" @click="guardarCredenciales(r.key)" :disabled="guardando === r.key"
                                        class="w-full py-2 rounded-lg text-[12px] font-semibold text-white disabled:opacity-50"
                                        style="background:var(--marca);">
                                        {{ guardando === r.key ? 'Guardando...' : 'Guardar credenciales' }}
                                    </button>
                                </div>
                                <p class="mt-1.5 text-tinta-300">
                                    La clave secreta se guarda cifrada y no se vuelve a mostrar.
                                </p>
                            </template>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Lista de conectadas -->
            <div class="bg-superficie rounded-2xl border border-linea overflow-hidden">
                <div class="px-5 py-3 border-b border-linea">
                    <h2 class="text-sm font-semibold text-tinta-700">Cuentas</h2>
                </div>

                <div v-if="!cuentas.length" class="py-10 text-center text-sm text-tinta-300">
                    Sin cuentas conectadas todavía.
                </div>

                <div class="divide-y divide-gray-50">
                    <div v-for="c in cuentas" :key="c.id" class="flex items-center gap-3 px-4 py-3">
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-tinta-900 truncate">{{ c.nombre_cuenta }}</p>
                            <p class="text-xs text-tinta-300 truncate">
                                {{ redLabel[c.red] ?? c.red }}
                                <span v-if="c.ultima_publicacion_en"> · última pub.: {{ new Date(c.ultima_publicacion_en).toLocaleDateString('es-CO') }}</span>
                            </p>
                            <p v-if="c.ultimo_error" class="text-xs text-red-500 truncate mt-0.5" :title="c.ultimo_error">
                                ⚠ {{ c.ultimo_error }}
                            </p>
                        </div>
                        <span class="text-xs px-2 py-0.5 rounded-full shrink-0"
                            :class="c.activa ? 'bg-green-100 text-green-700' : 'bg-tinta-100 text-tinta-400'">
                            {{ c.activa ? 'Activa' : 'Inactiva' }}
                        </span>
                        <button v-if="c.activa" @click="desconectar(c)" class="text-xs text-red-500 hover:underline shrink-0">Desconectar</button>
                        <button v-else @click="reactivar(c)" class="text-xs text-blue-600 hover:underline shrink-0">Reactivar</button>
                    </div>
                </div>
            </div>

        </div>
    </AppLayout>
</template>
