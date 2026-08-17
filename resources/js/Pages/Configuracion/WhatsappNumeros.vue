<script setup>
import { ref, computed, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import ResultadoPrueba from '@/Components/ResultadoPrueba.vue'

const props = defineProps({
    numeros: Array,
    usuarios: Array,
    conexion: { type: Object, default: () => ({ lista: false, url_webhook: '' }) },
    automatizacion: { type: Object, default: () => ({}) },
    etapas: { type: Array, default: () => [] },
    agente: { type: Object, default: () => ({}) },
})

// ── Llamadas que responden ahí mismo ─────────────────────────────────────────
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
        return { ok: false, mensaje: 'No se pudo completar la prueba: ' + e.message }
    }
}

// ── Conexión con Meta ────────────────────────────────────────────────────────
const copiado = ref('')
const ocupado = ref('')
const cambiando = ref(false)   // abre los campos cuando ya está conectado

const cred = ref({
    secret:   '',
    redirect: props.conexion?.verify_actual ?? '',
})

watch(() => props.conexion, (c) => { cred.value.redirect = c?.verify_actual ?? '' })

/**
 * Lo que falta, en el orden en que hay que resolverlo. El App Secret va aparte
 * porque sin él se reciben mensajes igual: solo se pierde la firma.
 */
const chequeos = computed(() => [
    {
        ok: props.conexion.tiene_token,
        texto: 'Token de acceso de Meta',
        falta: 'Se pega en el paso 1.',
    },
    {
        ok: props.conexion.tiene_verify,
        texto: 'Token de verificación del webhook',
        falta: 'Se genera en el paso 2 y se pega igual en Meta.',
    },
    {
        ok: props.conexion.numeros_activos > 0,
        texto: props.conexion.numeros_activos > 0
            ? `${props.conexion.numeros_activos} número${props.conexion.numeros_activos === 1 ? '' : 's'} activo${props.conexion.numeros_activos === 1 ? '' : 's'}`
            : 'Al menos un número activo',
        falta: 'Se agrega abajo, en «Números registrados».',
    },
])

function generarToken() {
    cred.value.redirect = props.conexion.token_sugerido
}

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
        onFinish: () => { ocupado.value = ''; cred.value.secret = ''; cambiando.value = false },
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

// ── Probador: el webhook ─────────────────────────────────────────────────────
const pruebaWebhook = ref(null)

async function probarWebhook() {
    ocupado.value = 'webhook'
    pruebaWebhook.value = null
    pruebaWebhook.value = await postJson('/configuracion/whatsapp-numeros/probar-webhook')
    ocupado.value = ''
}

// ── Probador: el agente de IA ────────────────────────────────────────────────
const mensajePrueba = ref('¿Ustedes qué fabrican y dónde están ubicados?')
const pruebaAgente  = ref(null)
const probandoAgente = ref(false)

async function probarAgente() {
    probandoAgente.value = true
    pruebaAgente.value = null
    pruebaAgente.value = await postJson('/configuracion/whatsapp-numeros/probar-agente', {
        mensaje: mensajePrueba.value,
        // Se prueba con lo que está escrito en pantalla, aunque no se haya
        // guardado: es el momento en que uno está ajustando el texto.
        nombre: agenteForm.value.nombre,
        indicaciones: agenteForm.value.indicaciones,
    })
    probandoAgente.value = false
}

// ── Agente de IA ─────────────────────────────────────────────────────────────
const agenteForm = ref({
    activo:       props.agente?.activo ?? false,
    nombre:       props.agente?.nombre ?? 'Asistente',
    indicaciones: props.agente?.indicaciones ?? '',
})

function guardarAgente() {
    ocupado.value = 'agente'
    router.post('/configuracion/whatsapp-numeros/agente', agenteForm.value, {
        preserveScroll: true,
        onFinish: () => { ocupado.value = '' },
    })
}

// ── Automatización ───────────────────────────────────────────────────────────
const autoAbierta = ref(false)
const auto = ref({
    activo:        props.automatizacion?.activo ?? false,
    avisar:        props.automatizacion?.avisar ?? true,
    responder:     props.automatizacion?.responder ?? false,
    respuestas:    (props.automatizacion?.respuestas ?? []).map(r => ({ ...r })),
    crear_lead:    props.automatizacion?.crear_lead ?? false,
    lead_etapa_id: props.automatizacion?.lead_etapa_id || '',
    asignacion:    props.automatizacion?.asignacion ?? 'fijo',
    responsables:  props.automatizacion?.responsables ?? [],
})

function agregarRespuesta() {
    auto.value.respuestas.push({ palabra_clave: '', mensaje: '' })
}

function quitarRespuesta(i) {
    auto.value.respuestas.splice(i, 1)
}

function guardarAutomatizacion() {
    ocupado.value = 'auto'
    router.post('/configuracion/whatsapp-numeros/automatizacion', auto.value, {
        preserveScroll: true,
        onFinish: () => { ocupado.value = '' },
    })
}

// ── Números ──────────────────────────────────────────────────────────────────
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
    document.getElementById('form-numero')?.scrollIntoView({ behavior: 'smooth', block: 'center' })
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

// ── Probadores por número ────────────────────────────────────────────────────
const pruebasNumero = ref({})   // { [id]: resultado }
const probandoId    = ref(null)
const envioAbierto  = ref(null)
const envio         = ref({ destino: '', texto: '' })

async function probarNumero(n) {
    probandoId.value = n.id
    pruebasNumero.value = { ...pruebasNumero.value, [n.id]: null }
    const r = await postJson(`/configuracion/whatsapp-numeros/${n.id}/probar`)
    pruebasNumero.value = { ...pruebasNumero.value, [n.id]: r }
    probandoId.value = null
}

function abrirEnvio(n) {
    envioAbierto.value = envioAbierto.value === n.id ? null : n.id
    envio.value = { destino: '', texto: '' }
    pruebasNumero.value = { ...pruebasNumero.value, [n.id]: null }
}

async function enviarPrueba(n) {
    probandoId.value = n.id
    pruebasNumero.value = { ...pruebasNumero.value, [n.id]: null }
    const r = await postJson(`/configuracion/whatsapp-numeros/${n.id}/enviar-prueba`, envio.value)
    pruebasNumero.value = { ...pruebasNumero.value, [n.id]: r }
    probandoId.value = null
}
</script>

<template>
    <AppLayout title="Números de WhatsApp">
        <div class="max-w-3xl mx-auto">

            <div class="flex items-center gap-3 mb-5">
                <button
                    @click="router.visit('/configuracion')"
                    class="p-2 rounded-xl hover:bg-tinta-100 transition-colors text-tinta-400"
                    title="Volver"
                >
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                    </svg>
                </button>
                <h1 class="text-xl font-semibold text-tinta-900">Números de WhatsApp</h1>
            </div>

            <!-- ── Conexión con Meta ─────────────────────────────────────── -->
            <div class="bg-superficie rounded-2xl border border-linea p-5 mb-4">
                <div class="flex items-center gap-2 flex-wrap mb-1">
                    <h2 class="text-sm font-semibold text-tinta-700">Conexión con WhatsApp</h2>
                    <span v-if="conexion.lista"
                        class="text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-pastel-verde-2 text-aviso-verde leading-none">Conectado</span>
                    <span v-else
                        class="text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-pastel-ambar-2 text-aviso-ambar leading-none">Sin conectar</span>
                </div>
                <p class="text-xs text-tinta-300 mb-3">
                    Se hace una sola vez. Necesitas una cuenta de WhatsApp Business <strong>API</strong> en Meta:
                    ni WhatsApp normal ni la app «WhatsApp Business» de la tienda sirven.
                </p>

                <!-- Qué falta, en orden -->
                <div class="rounded-xl border border-linea divide-y divide-separador mb-4">
                    <div v-for="(c, i) in chequeos" :key="i" class="flex items-start gap-2.5 px-3 py-2">
                        <span class="mt-0.5 shrink-0 w-4 h-4 rounded-full flex items-center justify-center"
                            :class="c.ok ? 'bg-pastel-verde-2' : 'bg-tinta-100'">
                            <svg v-if="c.ok" class="w-2.5 h-2.5 text-aviso-verde" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                        </span>
                        <div class="min-w-0 text-xs">
                            <p :class="c.ok ? 'text-tinta-700' : 'text-tinta-500 font-medium'">{{ c.texto }}</p>
                            <p v-if="!c.ok" class="text-tinta-300">{{ c.falta }}</p>
                        </div>
                    </div>
                </div>

                <!-- Avisos que no impiden conectar, pero hay que saberlos -->
                <div v-if="!conexion.url_alcanzable"
                    class="rounded-lg bg-pastel-ambar border border-borde-aviso-ambar p-2.5 text-[11px] text-aviso-ambar mb-3 leading-relaxed">
                    <p class="font-semibold">Esta instalación no es alcanzable desde internet.</p>
                    <p>
                        La dirección del webhook es <code>{{ conexion.url_webhook }}</code>, que solo existe dentro
                        de este computador. Meta necesita una dirección pública con HTTPS: configura la conexión
                        desde el servidor donde está instalado el sistema, o usa un túnel mientras pruebas en local.
                    </p>
                </div>

                <div v-if="!conexion.tiene_app_secret"
                    class="rounded-lg bg-pastel-ambar border border-borde-aviso-ambar p-2.5 text-[11px] text-aviso-ambar mb-3 leading-relaxed">
                    <p class="font-semibold">Falta el App Secret de la aplicación de Meta.</p>
                    <p>
                        Sin él, los mensajes entrantes se aceptan sin verificar la firma: cualquiera que sepa la
                        dirección del webhook podría inventar mensajes y meter leads falsos al CRM. Se carga en
                        Marketing → Redes Sociales → Cuentas, y es el de la misma aplicación.
                    </p>
                </div>

                <!-- Los pasos: visibles mientras no esté conectado -->
                <div v-if="!conexion.lista || cambiando" class="space-y-4 text-xs text-tinta-500">

                    <!-- Paso 1 -->
                    <div>
                        <p class="font-semibold text-tinta-700 mb-1">1. El token de acceso</p>
                        <p class="mb-2 leading-relaxed">
                            En
                            <a href="https://developers.facebook.com" target="_blank" rel="noopener"
                               class="font-semibold underline" style="color:var(--marca);">developers.facebook.com</a>,
                            abre o crea una aplicación tipo <strong>Empresa</strong>, agrégale el producto
                            <strong>WhatsApp</strong> y genera un token.
                        </p>
                        <div class="rounded-lg bg-pastel-ambar border border-borde-aviso-ambar p-2 text-aviso-ambar mb-2">
                            Usa un token <strong>permanente</strong>, de un Usuario del Sistema del Business Manager.
                            El que sale en la pantalla de pruebas vence en 24 horas y deja el sistema mudo al día siguiente.
                        </div>
                        <input v-model="cred.secret" type="password"
                            :placeholder="conexion.tiene_secreto ? 'Ya hay un token guardado — deja vacío para conservarlo' : 'Pega aquí el token de acceso permanente'"
                            class="w-full bg-superficie border border-linea rounded-lg px-2.5 py-2 text-[12px] focus:outline-none focus:border-[var(--marca)]" />
                    </div>

                    <!-- Paso 2 -->
                    <div>
                        <p class="font-semibold text-tinta-700 mb-1">2. El webhook, para recibir mensajes</p>
                        <p class="mb-1.5">En Meta, en <em>WhatsApp → Configuración → Webhooks</em>, pega esta dirección:</p>
                        <div class="flex items-center gap-2 mb-2">
                            <code class="flex-1 min-w-0 truncate bg-tinta-50 border border-linea rounded-lg px-2 py-1.5 text-[11px] text-tinta-700">{{ conexion.url_webhook }}</code>
                            <button type="button" @click="copiar(conexion.url_webhook, 'webhook')"
                                class="shrink-0 px-2.5 py-1.5 rounded-lg border border-linea text-[11px] font-semibold text-tinta-500 hover:bg-tinta-50">
                                {{ copiado === 'webhook' ? 'Copiada' : 'Copiar' }}
                            </button>
                        </div>

                        <p class="mb-1.5">Y este token de verificación, que va igual aquí y allá:</p>
                        <div class="flex items-center gap-2 mb-1.5">
                            <input v-model="cred.redirect" type="text" placeholder="Token de verificación"
                                class="flex-1 min-w-0 bg-superficie border border-linea rounded-lg px-2.5 py-2 text-[12px] focus:outline-none focus:border-[var(--marca)]" />
                            <button type="button" @click="generarToken"
                                class="shrink-0 px-2.5 py-2 rounded-lg border border-linea text-[11px] font-semibold text-tinta-500 hover:bg-tinta-50">
                                Generar
                            </button>
                            <button type="button" @click="copiar(cred.redirect, 'verify')" :disabled="!cred.redirect"
                                class="shrink-0 px-2.5 py-2 rounded-lg border border-linea text-[11px] font-semibold text-tinta-500 hover:bg-tinta-50 disabled:opacity-40">
                                {{ copiado === 'verify' ? 'Copiado' : 'Copiar' }}
                            </button>
                        </div>
                        <p class="text-tinta-300 mb-2">
                            Es una contraseña que inventas tú; el botón te da una. Después,
                            <strong>suscribe el webhook al campo «messages»</strong>: sin eso la dirección
                            funciona pero no llega ningún mensaje.
                        </p>
                    </div>

                    <!-- Paso 3 -->
                    <div>
                        <p class="font-semibold text-tinta-700 mb-1">3. El número</p>
                        <p class="leading-relaxed">
                            En <em>WhatsApp → Configuración de la API</em>, copia el
                            <strong>Identificador del número de teléfono</strong> (Phone Number ID) y agrégalo
                            abajo, en «Números registrados». Ahí es donde vive: el token es de la aplicación,
                            el identificador es de cada línea.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2 pt-1">
                        <button type="button" @click="guardarCredenciales" :disabled="ocupado === 'guardar'"
                            class="flex-1 min-w-[10rem] py-2 rounded-lg text-[12px] font-semibold text-white disabled:opacity-50"
                            style="background:var(--marca);">
                            {{ ocupado === 'guardar' ? 'Guardando...' : 'Guardar conexión' }}
                        </button>
                        <button v-if="cambiando" type="button" @click="cambiando = false"
                            class="px-3 py-2 rounded-lg border border-linea text-[12px] font-semibold text-tinta-500 hover:bg-tinta-50">
                            Cancelar
                        </button>
                    </div>
                    <p class="text-tinta-300">
                        El token se guarda cifrado y no se vuelve a mostrar.
                    </p>
                </div>

                <!-- Botonera cuando ya está conectado -->
                <div class="flex flex-wrap gap-2" :class="(!conexion.lista || cambiando) ? 'mt-4 pt-4 border-t border-linea' : ''">
                    <button type="button" @click="probarWebhook" :disabled="ocupado === 'webhook'"
                        class="px-3 py-2 rounded-xl border border-linea text-xs font-semibold text-tinta-700 hover:bg-tinta-50 disabled:opacity-50">
                        {{ ocupado === 'webhook' ? 'Probando...' : 'Probar el webhook' }}
                    </button>
                    <button v-if="conexion.lista && !cambiando" type="button" @click="cambiando = true"
                        class="px-3 py-2 rounded-xl border border-linea text-xs font-semibold text-tinta-700 hover:bg-tinta-50">
                        Cambiar credenciales
                    </button>
                    <button v-if="conexion.tiene_token || conexion.tiene_verify" type="button" @click="desconectar" :disabled="ocupado === 'desconectar'"
                        class="px-3 py-2 rounded-xl border border-borde-aviso-rojo text-xs font-semibold text-aviso-rojo hover:bg-pastel-rojo disabled:opacity-50">
                        {{ ocupado === 'desconectar' ? 'Desconectando...' : 'Desconectar' }}
                    </button>
                </div>

                <ResultadoPrueba :resultado="pruebaWebhook" :cargando="ocupado === 'webhook'" />
            </div>

            <!-- ── Automatización ────────────────────────────────────────── -->
            <div class="bg-superficie rounded-2xl border border-linea p-5 mb-4">
                <button @click="autoAbierta = !autoAbierta" class="w-full flex items-center justify-between text-left">
                    <div>
                        <div class="flex items-center gap-2 flex-wrap">
                            <h2 class="text-sm font-semibold text-tinta-700">Automatización</h2>
                            <span v-if="auto.activo"
                                class="text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-pastel-verde-2 text-aviso-verde leading-none">Activa</span>
                            <span v-else
                                class="text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-tinta-100 text-tinta-400 leading-none">Apagada</span>
                        </div>
                        <p class="text-xs text-tinta-300 mt-0.5">
                            Qué pasa cuando alguien escribe: avisar, responder solo y crear el lead en el CRM.
                        </p>
                    </div>
                    <svg class="w-4 h-4 text-tinta-300 shrink-0 transition-transform" :class="autoAbierta ? 'rotate-90' : ''"
                        fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                    </svg>
                </button>

                <div v-if="autoAbierta" class="mt-4 space-y-4 text-xs">
                    <label class="flex items-start gap-2 cursor-pointer">
                        <input type="checkbox" v-model="auto.activo" class="mt-0.5 rounded" />
                        <span><strong class="text-tinta-700">Activar la automatización.</strong>
                            <span class="text-tinta-300">Si está apagada, nada de lo de abajo ocurre.</span></span>
                    </label>

                    <div class="border-t border-linea pt-3 space-y-3">
                        <label class="flex items-start gap-2 cursor-pointer">
                            <input type="checkbox" v-model="auto.avisar" class="mt-0.5 rounded" />
                            <span><strong class="text-tinta-700">Avisar por la campanita</strong>
                                <span class="text-tinta-300">cuando escriba alguien por primera vez.
                                Si el número tiene dueño, el aviso es solo suyo.</span></span>
                        </label>

                        <label class="flex items-start gap-2 cursor-pointer">
                            <input type="checkbox" v-model="auto.responder" class="mt-0.5 rounded" />
                            <span><strong class="text-tinta-700">Responder automáticamente.</strong></span>
                        </label>

                        <div v-if="auto.responder" class="pl-6 space-y-3">
                            <!-- Agente de IA -->
                            <div class="rounded-lg border border-linea p-3 space-y-2">
                                <label class="flex items-start gap-2 cursor-pointer">
                                    <input type="checkbox" v-model="agenteForm.activo" class="mt-0.5 rounded" />
                                    <span><strong class="text-tinta-700">Que responda el agente de IA.</strong>
                                        <span class="text-tinta-300">Entiende la pregunta en vez de comparar palabras.
                                        Si no logra responder, se usan los mensajes fijos de abajo.</span></span>
                                </label>

                                <template v-if="agenteForm.activo">
                                    <input v-model="agenteForm.nombre" type="text" placeholder="Cómo se presenta (ej. Ofe)"
                                        class="w-full border border-linea rounded-lg px-2 py-1.5 text-[12px] focus:outline-none focus:border-[var(--marca)]" />
                                    <textarea v-model="agenteForm.indicaciones" rows="4"
                                        placeholder="Indicaciones propias del negocio. Ej: «Somos fabricantes, no vendemos al detal. Si preguntan por instalación, aclara que sí la hacemos en todo el país.»"
                                        class="w-full border border-linea rounded-lg px-2 py-1.5 text-[12px] focus:outline-none focus:border-[var(--marca)]"></textarea>
                                    <p class="text-tinta-300">
                                        El agente solo conoce <strong>quién es la empresa, cómo contactarla y qué
                                        vende</strong>. No tiene acceso a datos de ningún cliente, y tiene prohibido
                                        dar precios de productos a la medida o prometer plazos.
                                    </p>
                                </template>

                                <!-- Probador del agente: funciona aunque esté apagado -->
                                <div class="rounded-lg bg-tinta-50 border border-linea p-2.5 space-y-2">
                                    <p class="text-tinta-500">
                                        <strong>Pruébalo antes de encenderlo.</strong> Escribe lo que preguntaría un
                                        cliente y mira qué contestaría. No se manda nada a nadie ni se crea ningún lead,
                                        y se usa lo que está escrito arriba aunque no lo hayas guardado.
                                    </p>
                                    <div class="flex gap-2">
                                        <input v-model="mensajePrueba" type="text" placeholder="Ej: ¿hacen cuartos fríos a la medida?"
                                            @keyup.enter="probarAgente"
                                            class="flex-1 min-w-0 border border-linea rounded-lg px-2 py-1.5 text-[12px] bg-superficie focus:outline-none focus:border-[var(--marca)]" />
                                        <button type="button" @click="probarAgente" :disabled="probandoAgente || !mensajePrueba"
                                            class="shrink-0 px-2.5 py-1.5 rounded-lg border border-linea text-[11px] font-semibold text-tinta-500 hover:bg-superficie disabled:opacity-40">
                                            Probar
                                        </button>
                                    </div>
                                    <ResultadoPrueba :resultado="pruebaAgente" :cargando="probandoAgente" />
                                </div>

                                <button type="button" @click="guardarAgente" :disabled="ocupado === 'agente'"
                                    class="px-2.5 py-1.5 rounded-lg border border-linea text-[11px] font-semibold text-tinta-500 hover:bg-tinta-50 disabled:opacity-50">
                                    {{ ocupado === 'agente' ? 'Guardando...' : 'Guardar agente' }}
                                </button>
                            </div>

                            <p class="text-tinta-300">
                                <strong class="text-tinta-500">Mensajes fijos.</strong>
                                Sin palabra clave, el mensaje es de <strong>bienvenida</strong> y sale solo en el primer
                                contacto. Con palabra clave, sale cada vez que el mensaje la contenga.
                                Solo se envía la primera que coincida.
                            </p>
                            <div v-for="(r, i) in auto.respuestas" :key="i" class="flex gap-2 items-start">
                                <input v-model="r.palabra_clave" type="text" placeholder="palabra clave (opcional)"
                                    class="w-36 shrink-0 border border-linea rounded-lg px-2 py-1.5 text-[12px] focus:outline-none focus:border-[var(--marca)]" />
                                <textarea v-model="r.mensaje" rows="2" placeholder="Mensaje que se envía"
                                    class="flex-1 border border-linea rounded-lg px-2 py-1.5 text-[12px] focus:outline-none focus:border-[var(--marca)]"></textarea>
                                <button type="button" @click="quitarRespuesta(i)"
                                    class="shrink-0 w-7 h-7 rounded-lg text-aviso-rojo hover:bg-pastel-rojo leading-none">✕</button>
                            </div>
                            <button type="button" @click="agregarRespuesta"
                                class="px-2.5 py-1.5 rounded-lg border border-linea text-[11px] font-semibold text-tinta-500 hover:bg-tinta-50">
                                + Agregar respuesta
                            </button>
                        </div>
                    </div>

                    <div class="border-t border-linea pt-3 space-y-3">
                        <label class="flex items-start gap-2 cursor-pointer">
                            <input type="checkbox" v-model="auto.crear_lead" class="mt-0.5 rounded" />
                            <span><strong class="text-tinta-700">Crear el lead en el CRM.</strong>
                                <span class="text-tinta-300">Solo si el número no es de un cliente registrado
                                ni tiene ya un lead abierto, para no llenar el CRM de repetidos.</span></span>
                        </label>

                        <div v-if="auto.crear_lead" class="pl-6 space-y-2">
                            <div>
                                <label class="block text-tinta-400 mb-1">Etapa donde entra</label>
                                <select v-model="auto.lead_etapa_id"
                                    class="w-full border border-linea rounded-lg px-2 py-1.5 text-[12px] focus:outline-none">
                                    <option value="">La primera etapa del pipeline</option>
                                    <option v-for="e in etapas" :key="e.id" :value="e.id">{{ e.nombre }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-tinta-400 mb-1">Cómo se reparte</label>
                                <select v-model="auto.asignacion"
                                    class="w-full border border-linea rounded-lg px-2 py-1.5 text-[12px] focus:outline-none">
                                    <option value="fijo">Siempre al primero de la lista</option>
                                    <option value="round_robin">Rotando entre los seleccionados</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-tinta-400 mb-1">Quiénes reciben</label>
                                <div class="flex flex-wrap gap-1.5">
                                    <label v-for="u in usuarios" :key="u.id"
                                        class="inline-flex items-center gap-1 px-2 py-1 rounded-lg border cursor-pointer text-[11px]"
                                        :class="auto.responsables.includes(u.id) ? 'border-borde-aviso-azul bg-pastel-azul text-aviso-azul' : 'border-linea text-tinta-500'">
                                        <input type="checkbox" :value="u.id" v-model="auto.responsables" class="hidden" />
                                        {{ u.name }}
                                    </label>
                                </div>
                                <p class="text-tinta-300 mt-1">
                                    Este reparto solo se usa cuando el número <strong>no tiene dueño</strong>. Si le
                                    escribieron a la línea de un asesor, el lead es suyo. Y si no eliges a nadie, el
                                    lead se crea sin responsable y el aviso va a los administradores.
                                </p>
                            </div>
                        </div>
                    </div>

                    <button type="button" @click="guardarAutomatizacion" :disabled="ocupado === 'auto'"
                        class="w-full py-2 rounded-xl text-xs font-semibold text-white disabled:opacity-50"
                        style="background:var(--marca);">
                        {{ ocupado === 'auto' ? 'Guardando...' : 'Guardar automatización' }}
                    </button>
                </div>
            </div>

            <!-- ── Lista de números ──────────────────────────────────────── -->
            <div class="bg-superficie rounded-2xl border border-linea overflow-hidden mb-4">
                <div class="px-5 py-3 border-b border-linea">
                    <h2 class="text-sm font-semibold text-tinta-700">Números registrados</h2>
                    <p class="text-xs text-tinta-300 mt-0.5">
                        Quien tenga el número asignado recibe los avisos de esa línea y se queda con sus leads.
                        Cada asesor conserva su app y su historial (modo Coexistencia).
                    </p>
                </div>

                <div v-if="!numeros.length" class="py-10 text-center text-sm text-tinta-300">
                    Sin números configurados.
                </div>

                <div class="divide-y divide-separador">
                    <div v-for="n in numeros" :key="n.id" class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            <!-- Icono WhatsApp -->
                            <div class="w-9 h-9 rounded-xl flex items-center justify-center shrink-0"
                                :class="n.activo ? 'bg-pastel-azul' : 'bg-tinta-100'"
                            >
                                <svg class="w-4 h-4" :class="n.activo ? 'text-[var(--marca)]' : 'text-tinta-300'"
                                    fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-6l-4 4v-4z" />
                                </svg>
                            </div>

                            <!-- Info -->
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-tinta-900 truncate">{{ n.nombre }}</p>
                                <p class="text-xs text-tinta-300 truncate">{{ n.numero_telefono }}</p>
                            </div>

                            <span class="text-xs px-2 py-0.5 rounded-full shrink-0"
                                :class="n.rol === 'central' ? 'bg-[var(--marca)] text-white font-semibold' : 'bg-tinta-100 text-tinta-500'">
                                {{ rolLabel[n.rol] ?? n.rol }}
                            </span>

                            <span class="text-xs px-2 py-0.5 rounded-full shrink-0"
                                :class="n.activo ? 'bg-pastel-verde-2 text-aviso-verde' : 'bg-tinta-100 text-tinta-400'">
                                {{ n.activo ? 'Activo' : 'Inactivo' }}
                            </span>
                        </div>

                        <!-- Dueño: ahora decide quién atiende, así que se ve -->
                        <div class="mt-2 flex items-center gap-2 flex-wrap text-[11px]">
                            <span v-if="n.usuario"
                                class="inline-flex items-center gap-1 px-2 py-0.5 rounded-lg bg-pastel-azul border border-borde-aviso-azul text-aviso-azul">
                                Atiende {{ n.usuario.name }}
                                <span v-if="n.usuario.activo === false" class="opacity-80">(usuario inactivo)</span>
                            </span>
                            <span v-else class="inline-flex items-center gap-1 px-2 py-0.5 rounded-lg bg-tinta-100 text-tinta-400">
                                Sin dueño — los avisos van a todos los vendedores
                            </span>
                        </div>

                        <!-- Probadores del número -->
                        <div class="mt-2 flex items-center gap-3 flex-wrap text-xs">
                            <button @click="probarNumero(n)" :disabled="probandoId === n.id"
                                class="text-aviso-azul hover:underline disabled:opacity-50">Probar</button>
                            <button @click="abrirEnvio(n)" class="text-aviso-azul hover:underline">
                                {{ envioAbierto === n.id ? 'Cerrar envío' : 'Enviar mensaje de prueba' }}
                            </button>
                            <span class="text-tinta-200">·</span>
                            <button @click="editar(n)" class="text-tinta-500 hover:underline">Editar</button>
                            <button @click="eliminar(n.id)" class="text-aviso-rojo hover:underline">Eliminar</button>
                        </div>

                        <!-- Envío real -->
                        <div v-if="envioAbierto === n.id" class="mt-2 rounded-lg border border-linea bg-tinta-50 p-2.5 space-y-2">
                            <p class="text-[11px] text-tinta-500">
                                Esto <strong>sí manda un mensaje de verdad</strong>. Usa un número tuyo, y ten en cuenta
                                que Meta solo deja escribir libremente a quien te haya escrito en las últimas 24 horas.
                            </p>
                            <input v-model="envio.destino" type="text" placeholder="573001234567 (con indicativo, sin signos)"
                                class="w-full border border-linea rounded-lg px-2 py-1.5 text-[12px] bg-superficie focus:outline-none focus:border-[var(--marca)]" />
                            <input v-model="envio.texto" type="text" placeholder="Mensaje (opcional)"
                                class="w-full border border-linea rounded-lg px-2 py-1.5 text-[12px] bg-superficie focus:outline-none focus:border-[var(--marca)]" />
                            <button type="button" @click="enviarPrueba(n)" :disabled="probandoId === n.id || !envio.destino"
                                class="px-2.5 py-1.5 rounded-lg text-[11px] font-semibold text-white disabled:opacity-40"
                                style="background:var(--marca);">
                                {{ probandoId === n.id ? 'Enviando...' : 'Enviar' }}
                            </button>
                        </div>

                        <ResultadoPrueba :resultado="pruebasNumero[n.id]" :cargando="probandoId === n.id" />
                    </div>
                </div>
            </div>

            <!-- ── Formulario crear / editar ─────────────────────────────── -->
            <div id="form-numero" class="bg-superficie rounded-2xl border border-linea p-5">
                <h3 class="text-sm font-semibold text-tinta-700 mb-4">
                    {{ editando ? 'Editar número' : 'Nuevo número' }}
                </h3>

                <div class="space-y-3">
                    <!-- Nombre -->
                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">
                            Nombre *
                        </label>
                        <input
                            v-model="form.nombre"
                            type="text"
                            placeholder="Cómo se identifica dentro del sistema"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]"
                        />
                    </div>

                    <!-- Número de teléfono -->
                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">
                            Número de teléfono *
                        </label>
                        <input
                            v-model="form.numero_telefono"
                            type="text"
                            placeholder="+573001234567"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]"
                        />
                        <p class="text-xs text-tinta-300 mt-1">El número tal como lo ve el cliente.</p>
                    </div>

                    <!-- Phone Number ID -->
                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">
                            Identificador de Meta *
                        </label>
                        <input
                            v-model="form.phone_number_id"
                            type="text"
                            placeholder="Phone Number ID"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]"
                        />
                        <p class="text-xs text-tinta-300 mt-1">
                            En Meta: <em>WhatsApp → Configuración de la API → Identificador del número de teléfono</em>.
                            Es lo que decide desde qué línea sale cada mensaje, así que después de guardarlo
                            conviene darle a «Probar».
                        </p>
                    </div>

                    <!-- Rol -->
                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">
                            Rol *
                        </label>
                        <select
                            v-model="form.rol"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)] bg-superficie"
                        >
                            <option value="asesor">Asesor</option>
                            <option value="central">Central</option>
                        </select>
                    </div>

                    <!-- Usuario asociado -->
                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">
                            Quién atiende este número
                        </label>
                        <select
                            v-model="form.usuario_id"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)] bg-superficie"
                        >
                            <option value="">Nadie en particular</option>
                            <option v-for="u in usuarios" :key="u.id" :value="u.id">
                                {{ u.name }}{{ u.activo === false ? ' (inactivo)' : '' }}
                            </option>
                        </select>
                        <p class="text-xs text-tinta-300 mt-1">
                            Recibe los avisos de esta línea y se queda con los leads que entren por ella.
                            Sin nadie asignado, los avisos van a todos los vendedores y los leads siguen el
                            reparto de la automatización — que es lo normal para el número central.
                        </p>
                    </div>

                    <!-- Activo -->
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input v-model="form.activo" type="checkbox" class="rounded" />
                        <span class="text-sm text-tinta-700">Activo</span>
                    </label>

                    <!-- Botones -->
                    <div class="flex gap-3 pt-1">
                        <button
                            v-if="editando"
                            @click="cancelar"
                            class="flex-1 py-2.5 rounded-xl border border-linea text-sm text-tinta-500"
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
