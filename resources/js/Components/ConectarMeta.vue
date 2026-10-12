<script setup>
/**
 * «Conectar con Meta»: WhatsApp, Facebook e Instagram desde un solo lugar.
 *
 * **Son dos autorizaciones, no una.** WhatsApp se vincula con Embedded Signup (una ventana de
 * Meta con su propia configuración, que es la que permite la coexistencia con la app WhatsApp
 * Business del celular) y Facebook e Instagram con el inicio de sesión de siempre. Meta no deja
 * mezclar los permisos de páginas e Instagram dentro de la configuración de Embedded Signup, así
 * que un solo botón que lo hiciera todo sería prometer algo que la API rechaza. Lo que sí es uno
 * solo es el lugar y el orden: dos pasos, uno debajo del otro, cada uno con su estado.
 *
 * `modo="whatsapp"` muestra solo el primero (para la pantalla de números de WhatsApp).
 */
import { ref, onUnmounted } from 'vue'
import { router } from '@inertiajs/vue3'
import { iniciarEmbeddedSignup } from '@/composables/useMetaSdk'
import ResultadoPrueba from '@/Components/ResultadoPrueba.vue'

const props = defineProps({
    meta: { type: Object, default: () => ({ app_id: '', config_id: '', version: 'v21.0', listo: false }) },
    modo: { type: String, default: 'todo' },
    // Cuántas páginas de Facebook hay conectadas, para que el paso 2 diga si ya está hecho.
    paginas: { type: Number, default: 0 },
})

const coexistencia = ref(true)
const conectando = ref(false)
const resultado = ref(null)
const configId = ref(props.meta?.config_id ?? '')
const guardandoConfig = ref(false)
const suscribiendo = ref(false)
const suscripcion = ref(null)

let flujo = null

function csrf() {
    return decodeURIComponent(
        document.cookie.split('; ').find(c => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? ''
    )
}

async function postJson(url, cuerpo = {}) {
    try {
        const resp = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-XSRF-TOKEN': csrf() },
            credentials: 'same-origin',
            body: JSON.stringify(cuerpo),
        })
        return await resp.json()
    } catch (e) {
        return { ok: false, mensaje: 'No se pudo completar la llamada: ' + e.message }
    }
}

async function conectarWhatsapp() {
    conectando.value = true
    resultado.value = null

    try {
        flujo = iniciarEmbeddedSignup({
            appId: props.meta.app_id,
            configId: props.meta.config_id,
            version: props.meta.version,
            coexistencia: coexistencia.value,
        })

        const sesion = await flujo.promesa
        const respuesta = await postJson('/configuracion/whatsapp-numeros/embedded-signup', {
            code: sesion.code,
            waba_id: sesion.waba_id,
            phone_number_id: sesion.phone_number_id,
            coexistencia: sesion.coexistencia,
        })

        resultado.value = {
            ok: !!respuesta.ok,
            mensaje: respuesta.mensaje ?? (respuesta.message ?? 'No se pudo vincular el número.'),
            // Lo que se conectó pero quedó a medias va en ámbar: no es un error, pero hay que leerlo.
            aviso: !!respuesta.ok && (respuesta.avisos?.length ?? 0) > 0,
            detalle: respuesta.avisos ?? [],
        }

        if (respuesta.ok) router.reload({ preserveScroll: true })
    } catch (e) {
        resultado.value = { ok: false, mensaje: e.message, detalle: [] }
    } finally {
        conectando.value = false
        flujo = null
    }
}

function conectarFacebook() {
    router.visit('/rrss/cuentas/conectar/meta')
}

function guardarConfig() {
    guardandoConfig.value = true
    router.post('/rrss/cuentas/meta-config', { config_id: configId.value }, {
        preserveScroll: true,
        onFinish: () => { guardandoConfig.value = false },
    })
}

async function suscribirTodo() {
    suscribiendo.value = true
    suscripcion.value = null

    const r = await postJson('/configuracion/whatsapp-numeros/suscribir-webhooks')
    suscripcion.value = { ok: !!r.ok, mensaje: r.mensaje ?? 'No se pudo completar.', detalle: r.detalle ?? [] }
    suscribiendo.value = false
}

// Una ventana a medias no debe dejar su escucha puesta cuando se cambia de pantalla.
onUnmounted(() => flujo?.cancelar())
</script>

<template>
    <div class="bg-superficie rounded-2xl border border-linea p-5 mb-4">
        <h2 class="text-sm font-semibold text-tinta-700">Conectar con Meta</h2>
        <p class="text-xs text-tinta-300 mt-0.5 mb-4">
            Autoriza una vez y el sistema deja listos los webhooks. Son dos pasos porque Meta no
            deja mezclar los permisos de WhatsApp con los de Facebook e Instagram en una sola ventana.
        </p>

        <!-- Paso 1: WhatsApp -->
        <div class="rounded-xl border border-linea p-3">
            <p class="text-sm font-medium text-tinta-900">1. WhatsApp</p>
            <p class="text-xs text-tinta-300 mt-0.5">
                Vincula tu número sin sacarlo de la app WhatsApp Business del celular: lo que escribas
                desde el teléfono aparece aquí, y lo que respondas aquí sale por el mismo número.
            </p>

            <label class="flex items-start gap-2 mt-3 text-xs text-tinta-500 cursor-pointer">
                <input v-model="coexistencia" type="checkbox" class="mt-0.5 rounded border-linea" />
                <span>
                    Mi número ya se usa en la app <strong>WhatsApp Business</strong> (coexistencia).
                    Desmárcalo si es un número nuevo, que no está en ninguna app.
                </span>
            </label>

            <template v-if="meta.listo">
                <button type="button" @click="conectarWhatsapp" :disabled="conectando"
                    class="mt-3 w-full sm:w-auto px-4 py-2.5 rounded-xl text-sm font-semibold text-white disabled:opacity-60"
                    style="background:var(--marca);">
                    {{ conectando ? 'Esperando a Meta...' : 'Conectar WhatsApp' }}
                </button>
            </template>

            <div v-else class="mt-3 rounded-lg bg-pastel-ambar border border-borde-aviso-ambar p-3 text-xs text-aviso-ambar">
                <p class="font-semibold mb-1">Falta preparar la aplicación de Meta</p>
                <p class="mb-2">
                    Se necesitan el identificador y la clave secreta de la aplicación (se cargan en
                    <a href="/rrss/cuentas" class="underline font-semibold">Redes Sociales → Cuentas</a>)
                    y el identificador de la configuración de Embedded Signup que creas en Meta:
                </p>
                <div class="flex gap-2">
                    <input v-model="configId" type="text" inputmode="numeric" placeholder="Config ID de Embedded Signup"
                        class="flex-1 min-w-0 bg-superficie border border-linea rounded-lg px-2.5 py-2 text-[12px] text-tinta-900 focus:outline-none focus:border-[var(--marca)]" />
                    <button type="button" @click="guardarConfig" :disabled="guardandoConfig || !configId.trim()"
                        class="shrink-0 px-3 py-2 rounded-lg text-[12px] font-semibold text-white disabled:opacity-60"
                        style="background:var(--marca);">
                        Guardar
                    </button>
                </div>
            </div>

            <ResultadoPrueba :resultado="resultado" :cargando="false" />
        </div>

        <!-- Paso 2: Facebook e Instagram -->
        <div v-if="modo === 'todo'" class="rounded-xl border border-linea p-3 mt-3">
            <div class="flex items-center gap-2 flex-wrap">
                <p class="text-sm font-medium text-tinta-900">2. Facebook e Instagram</p>
                <span v-if="paginas > 0"
                    class="text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-pastel-verde-2 text-aviso-verde leading-none">
                    {{ paginas }} {{ paginas === 1 ? 'cuenta conectada' : 'cuentas conectadas' }}
                </span>
            </div>
            <p class="text-xs text-tinta-300 mt-0.5">
                Publicar, leer y responder comentarios, y contestar mensajes directos. El sistema
                suscribe cada página a los webhooks solo.
            </p>
            <button type="button" @click="conectarFacebook"
                class="mt-3 w-full sm:w-auto px-4 py-2.5 rounded-xl text-sm font-semibold text-white"
                style="background:var(--marca);">
                {{ paginas > 0 ? 'Reconectar Facebook e Instagram' : 'Conectar Facebook e Instagram' }}
            </button>
        </div>

        <!-- Reparar: cuando se conectó y no llega nada -->
        <div class="mt-4 pt-3 border-t border-linea">
            <button type="button" @click="suscribirTodo" :disabled="suscribiendo"
                class="text-xs font-semibold text-tinta-500 hover:text-tinta-900 disabled:opacity-60">
                {{ suscribiendo ? 'Suscribiendo...' : '¿Conectado pero no llega nada? Volver a suscribir los webhooks' }}
            </button>
            <ResultadoPrueba :resultado="suscripcion" :cargando="false" />
        </div>
    </div>
</template>
