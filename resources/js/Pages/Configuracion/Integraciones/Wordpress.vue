<script setup>
import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    url_base:      String,
    configurado:   Boolean,
    token_parcial: { type: String, default: null },
    // Estado del catálogo publicado: cuántos hay, a qué sitio se le avisa y cuándo fue la
    // última vez que el plugin vino a leer.
    catalogo:      { type: Object, default: () => ({ conteo: { productos: 0, ensambles: 0 }, sitio: '', ultima_lectura: null }) },
})

const configurado   = ref(props.configurado)
const tokenParcial  = ref(props.token_parcial)
const tokenCompleto = ref('')   // solo vive en memoria, justo después de generar
const generando     = ref(false)
const error         = ref('')
const copiado        = ref('')

function getCookie(name) {
    const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'))
    return match ? decodeURIComponent(match[2]) : ''
}

async function postJson(url, body = {}) {
    const resp = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': getCookie('XSRF-TOKEN'),
            'Accept': 'application/json',
        },
        credentials: 'same-origin',
        body: JSON.stringify(body),
    })
    return { ok: resp.ok, data: await resp.json() }
}

async function copiar(texto, etiqueta) {
    try {
        await navigator.clipboard.writeText(texto)
        copiado.value = etiqueta
        setTimeout(() => { if (copiado.value === etiqueta) copiado.value = '' }, 2000)
    } catch (e) { /* portapapeles no disponible: el usuario copia a mano */ }
}

async function generarToken() {
    if (configurado.value && !confirm(
        'Ya hay un token activo. Generar uno nuevo desconecta el plugin instalado ' +
        'hasta que pegues el token nuevo en WordPress. ¿Continuar?'
    )) return

    generando.value = true
    error.value = ''

    try {
        const { ok, data } = await postJson('/configuracion/integraciones/wordpress/generar-token')
        if (!ok) { error.value = data.mensaje ?? 'No se pudo generar el token.'; return }

        tokenCompleto.value = data.token
        tokenParcial.value  = data.token_parcial
        configurado.value   = true
    } catch (e) {
        error.value = 'No se pudo conectar con el servidor.'
    } finally {
        generando.value = false
    }
}

function revocarToken() {
    if (!confirm('Esto desconecta el plugin de WordPress instalado en el sitio del cliente. ¿Continuar?')) return

    router.post('/configuracion/integraciones/wordpress/revocar-token', {}, {
        preserveScroll: true,
        onSuccess: () => {
            configurado.value = false
            tokenParcial.value = null
            tokenCompleto.value = ''
        },
    })
}
</script>

<template>
    <AppLayout title="Integraciones — WordPress">
        <div class="max-w-2xl mx-auto">

            <div class="flex items-center gap-3 mb-5">
                <button @click="router.visit('/configuracion')"
                    class="p-2 rounded-xl hover:bg-tinta-100 transition-colors text-tinta-400" title="Volver">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                    </svg>
                </button>
                <h1 class="text-xl font-semibold text-tinta-900">Integración con WordPress</h1>
            </div>

            <p class="text-sm text-tinta-400 mb-5">
                Conecta el sitio de WordPress del cliente con este ERP a través del plugin
                <strong>Briela Connect</strong>: leads del sitio con su canal de origen, directo al CRM.
            </p>

            <!-- Estado + token -->
            <div class="bg-superficie rounded-2xl border border-linea p-5 mb-4">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-sm font-semibold text-tinta-700">Estado de la conexión</h2>
                    <span class="text-xs px-2 py-0.5 rounded-full"
                        :class="configurado ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'">
                        {{ configurado ? 'Token activo' : 'Sin configurar' }}
                    </span>
                </div>

                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">
                            URL del sitio Briela (para pegar en el plugin)
                        </label>
                        <div class="flex gap-2">
                            <input :value="url_base" readonly
                                class="flex-1 min-w-0 px-3 py-2.5 rounded-xl border border-linea bg-tinta-50 text-sm text-tinta-700" />
                            <button @click="copiar(url_base, 'url')"
                                class="px-3 rounded-xl border border-linea text-xs font-semibold text-tinta-500 hover:bg-tinta-50 shrink-0">
                                {{ copiado === 'url' ? 'Copiada' : 'Copiar' }}
                            </button>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">
                            Token de integración (para pegar en el plugin)
                        </label>

                        <!-- Recién generado: se ve completo una sola vez -->
                        <div v-if="tokenCompleto" class="space-y-2">
                            <div class="flex gap-2">
                                <input :value="tokenCompleto" readonly
                                    class="flex-1 min-w-0 px-3 py-2.5 rounded-xl border border-amber-300 bg-amber-50 text-sm font-mono text-tinta-900" />
                                <button @click="copiar(tokenCompleto, 'token')"
                                    class="px-3 rounded-xl border border-linea text-xs font-semibold text-tinta-500 hover:bg-tinta-50 shrink-0">
                                    {{ copiado === 'token' ? 'Copiado' : 'Copiar' }}
                                </button>
                            </div>
                            <p class="text-xs text-amber-600">
                                Cópialo y pégalo en el plugin ahora — al salir de esta pantalla no se vuelve a mostrar completo.
                            </p>
                        </div>

                        <!-- Ya configurado antes, solo se ve el final -->
                        <div v-else-if="tokenParcial"
                            class="px-3 py-2.5 rounded-xl border border-linea bg-tinta-50 text-sm font-mono text-tinta-400">
                            {{ tokenParcial }}
                        </div>

                        <p v-else class="text-xs text-tinta-300">Todavía no se ha generado un token.</p>
                    </div>

                    <p v-if="error" class="text-xs text-red-600">{{ error }}</p>

                    <div class="flex gap-3 pt-1">
                        <button @click="generarToken" :disabled="generando"
                            class="flex-1 py-2.5 rounded-xl text-white text-sm font-semibold disabled:opacity-50"
                            style="background:var(--marca);">
                            {{ generando ? 'Generando…' : (configurado ? 'Generar token nuevo' : 'Generar token') }}
                        </button>
                        <button v-if="configurado" @click="revocarToken"
                            class="px-4 py-2.5 rounded-xl border border-linea text-sm text-tinta-500 hover:bg-tinta-50">
                            Revocar
                        </button>
                    </div>
                </div>
            </div>

            <!-- Catálogo publicado -->
            <div class="bg-superficie rounded-2xl border border-linea p-5">
                <h2 class="text-sm font-semibold text-tinta-700 mb-1">Catálogo en el sitio</h2>
                <p class="text-xs text-tinta-400 mb-4">
                    Qué sale a la web lo decides producto por producto: cada ficha de producto y de
                    ensamble tiene un interruptor <strong>Sitio web</strong>, y en los listados puedes
                    marcar varios y publicarlos de un golpe.
                </p>

                <div class="grid grid-cols-2 gap-3 mb-4">
                    <div class="p-3 rounded-xl" style="background:var(--superficie-2);">
                        <p class="text-2xl font-semibold text-tinta-900">{{ catalogo.conteo.productos }}</p>
                        <p class="text-xs text-tinta-400">producto(s) publicado(s)</p>
                    </div>
                    <div class="p-3 rounded-xl" style="background:var(--superficie-2);">
                        <p class="text-2xl font-semibold text-tinta-900">{{ catalogo.conteo.ensambles }}</p>
                        <p class="text-xs text-tinta-400">ensamble(s) publicado(s)</p>
                    </div>
                </div>

                <dl class="text-xs space-y-2">
                    <div class="flex justify-between gap-3">
                        <dt class="text-tinta-400">Sitio detectado</dt>
                        <dd class="text-tinta-700 truncate">{{ catalogo.sitio || 'Todavía ninguno' }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-tinta-400">El plugin leyó por última vez</dt>
                        <dd class="text-tinta-700">{{ catalogo.ultima_lectura || 'Nunca' }}</dd>
                    </div>
                </dl>

                <p class="text-xs text-tinta-400 mt-4">
                    El sitio pregunta por su cuenta cada hora, y Briela le avisa en el momento en que
                    publicas algo. Si el aviso no llega —el hosting no deja salir peticiones, el sitio
                    está detrás de un cortafuegos— igual se actualiza en la siguiente pasada.
                </p>
            </div>

            <!-- Instrucciones -->
            <div class="bg-superficie rounded-2xl border border-linea p-5">
                <h2 class="text-sm font-semibold text-tinta-700 mb-3">Cómo conectar el plugin</h2>
                <ol class="space-y-2.5 text-sm text-tinta-500 list-decimal list-inside">
                    <li>Instala y activa <strong>Briela Connect</strong> en el WordPress del cliente.</li>
                    <li>Entra a <strong>Ajustes → Briela Connect</strong> dentro de WordPress.</li>
                    <li>Pega la URL y el token generados arriba, y guarda.</li>
                    <li>Los leads que lleguen por los formularios del sitio aparecerán en el CRM con su canal de origen (utm_source / utm_medium / utm_campaign).</li>
                    <li>Publica productos o ensambles desde su ficha, y el sitio crea la suya. Con
                        WooCommerce instalado se crean como productos de la tienda; sin tienda, como
                        fichas del catálogo del plugin.</li>
                </ol>
            </div>

        </div>
    </AppLayout>
</template>
