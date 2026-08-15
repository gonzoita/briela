<script setup>
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    secciones:        Array,
    asistente:        Object,
    rolesSugeridos:   { type: Array,  default: () => [] },
    ia:               { type: Object, default: () => ({}) },
    modelosSugeridos: { type: Object, default: () => ({ texto: {}, imagen: {}, voz: {} }) },
    vocesSugeridas:   { type: Object, default: () => ({}) },
    // El prompt con el que se redactan las fichas técnicas de productos y ensambles.
    fichaTecnica:     { type: Object, default: () => ({ prompt: '', prompt_fabrica: '' }) },
})

// ── Voz del asistente ─────────────────────────────────────────────────────────
const vozNatural = ref(!!props.asistente?.voz_natural)
const modeloVoz  = ref(props.asistente?.modelo_voz ?? '')
const vozElegida = ref(props.asistente?.voz ?? 'nova')
const vozInstrucciones = ref(props.asistente?.voz_instrucciones ?? '')

// Si la voz guardada no está en la lista, se edita como texto libre.
const vocesConocidas = Object.keys(props.vocesSugeridas ?? {})
const vozPersonalizada = ref(vocesConocidas.includes(props.asistente?.voz) ? '' : (props.asistente?.voz ?? ''))

/** La que realmente se manda al servidor. */
const vozFinal = () => (vozElegida.value === '__otra' ? vozPersonalizada.value : vozElegida.value)

// Atajos de acento: llenan el campo de arriba con un texto listo.
const acentos = {
    'Colombiano': 'Habla en español colombiano de Bogotá, con el acento y la entonación de allí. '
        + 'Tono cálido, cercano y profesional, como una compañera de trabajo explicando algo. '
        + 'Ritmo conversacional, ni lento ni acelerado. Entonación viva, no monótona.',
    'Latino neutro': 'Habla en español latinoamericano neutro, sin acento regional marcado. '
        + 'Tono profesional y claro, ritmo pausado y buena vocalización.',
    'Mexicano': 'Habla en español mexicano, con la entonación y el ritmo de Ciudad de México. '
        + 'Tono amable y cercano, ritmo conversacional.',
    'Español (España)': 'Habla en español de España, con acento castellano. '
        + 'Tono profesional y directo, ritmo natural.',
    'Formal': 'Habla en español latinoamericano neutro, con tono formal y sobrio. '
        + 'Ritmo pausado, articulación cuidada, sin coloquialismos.',
}

const probandoVoz = ref(false)
const errorVoz    = ref('')

/** Genera y reproduce una muestra con lo que hay en pantalla ahora mismo. */
async function escucharVoz() {
    probandoVoz.value = true
    errorVoz.value    = ''

    try {
        const { ok, data } = await postJson('/api/perfil-marca/probar-voz', {
            voz: vozFinal(),
            modelo_voz: modeloVoz.value,
            instrucciones: vozInstrucciones.value,
        })

        if (!ok) { errorVoz.value = data.error ?? 'No se pudo generar el audio.'; return }

        await new Audio(data.audio).play()
    } catch (e) {
        errorVoz.value = 'No se pudo conectar.'
    } finally {
        probandoVoz.value = false
    }
}

// ── Credencial y modelos de IA ────────────────────────────────────────────────
const iaClave        = ref('')   // vacío = no se cambia
const iaModeloTexto  = ref(props.ia?.modelo_texto ?? '')
const iaModeloImagen = ref(props.ia?.modelo_imagen ?? '')
const iaModeloRapido = ref(props.ia?.modelo_rapido ?? '')
const iaPriorizarVelocidad = ref(props.ia?.priorizar_velocidad ?? true)
const iaMaxTokens = ref(props.ia?.max_tokens ?? 3000)
const probando       = ref(false)
const resultadoPrueba = ref('')

// Filtro de la lista de modelos: OpenRouter ofrece cientos, así que sin buscador
// es inmanejable.
const buscarTexto  = ref('')
const buscarImagen = ref('')

const totalTexto  = computed(() => Object.keys(props.modelosSugeridos?.texto ?? {}).length)
const totalImagen = computed(() => Object.keys(props.modelosSugeridos?.imagen ?? {}).length)

function filtrar(lista, termino) {
    const t = termino.trim().toLowerCase()
    if (!t) return lista
    return Object.fromEntries(
        Object.entries(lista).filter(([id, label]) =>
            id.toLowerCase().includes(t) || String(label).toLowerCase().includes(t)
        )
    )
}

const modelosTextoFiltrados  = computed(() => filtrar(props.modelosSugeridos?.texto  ?? {}, buscarTexto.value))
const modelosImagenFiltrados = computed(() => filtrar(props.modelosSugeridos?.imagen ?? {}, buscarImagen.value))

const buscarVoz = ref('')
const totalVoz  = computed(() => Object.keys(props.modelosSugeridos?.voz ?? {}).length)
const modelosVozFiltrados = computed(() => filtrar(props.modelosSugeridos?.voz ?? {}, buscarVoz.value))

function guardarIa() {
    router.post('/configuracion/perfil-marca/ia', {
        api_key: iaClave.value,
        modelo_texto: iaModeloTexto.value,
        modelo_imagen: iaModeloImagen.value,
        modelo_rapido: iaModeloRapido.value,
        priorizar_velocidad: iaPriorizarVelocidad.value,
        max_tokens: iaMaxTokens.value,
    }, {
        preserveScroll: true,
        onSuccess: () => { iaClave.value = '' },
    })
}

// ── Prompt de la ficha técnica ────────────────────────────────────────────────
// Guardar vacío significa volver al de fábrica, así que «Volver al de fábrica» solo pone
// el texto en pantalla: guardar sigue siendo un acto explícito.
const promptFicha = ref(props.fichaTecnica?.prompt ?? '')

function guardarPromptFicha() {
    router.post('/configuracion/perfil-marca/prompt-ficha', { prompt: promptFicha.value }, {
        preserveScroll: true,
    })
}

function restablecerPromptFicha() {
    promptFicha.value = props.fichaTecnica?.prompt_fabrica ?? ''
}

async function probarIa() {
    probando.value = true
    resultadoPrueba.value = ''

    try {
        const { ok, data } = await postJson('/api/perfil-marca/probar-ia', {})
        resultadoPrueba.value = ok ? 'ok' : (data.error ?? 'Falló la prueba.')
    } catch (e) {
        resultadoPrueba.value = 'No se pudo conectar.'
    } finally {
        probando.value = false
    }
}

const filas = ref(props.secciones.map(s => ({ ...s })))

// ── Asistente (bautizarlo y definir su rol) ───────────────────────────────────
const asistenteNombre = ref(props.asistente?.nombre ?? 'Asistente')
const asistenteRol = ref(props.asistente?.rol ?? '')
const asistentePersonalidad = ref(props.asistente?.personalidad ?? '')

function guardarAsistente() {
    router.post('/configuracion/perfil-marca/asistente', {
        nombre: asistenteNombre.value,
        rol: asistenteRol.value,
        personalidad: asistentePersonalidad.value,
        voz_natural: vozNatural.value,
        voz: vozFinal(),
        modelo_voz: modeloVoz.value,
        voz_instrucciones: vozInstrucciones.value,
    }, { preserveScroll: true })
}

// ── Guardar sección ───────────────────────────────────────────────────────────
function guardar(fila) {
    router.post('/configuracion/perfil-marca', {
        seccion: fila.seccion,
        contenido: fila.contenido,
    }, { preserveScroll: true })
}

function getCookie(name) {
    const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'))
    return match ? decodeURIComponent(match[2]) : ''
}

async function postJson(url, body) {
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

// ── Generar una sección con IA ────────────────────────────────────────────────
const abierto   = ref(null)   // sección con el cuestionario abierto
const respuesta = ref('')
const generando = ref(false)
const errorIa   = ref('')

function abrirPregunta(fila) {
    abierto.value   = abierto.value === fila.seccion ? null : fila.seccion
    respuesta.value = ''
    errorIa.value   = ''
}

async function generar(fila) {
    if (!respuesta.value.trim()) return
    generando.value = true
    errorIa.value   = ''

    try {
        const { ok, data } = await postJson('/api/perfil-marca/generar', {
            seccion: fila.seccion,
            respuesta: respuesta.value,
        })

        if (!ok) { errorIa.value = data.error ?? 'No se pudo generar.'; return }

        // Se propone el texto en el campo; el usuario decide si lo guarda.
        fila.contenido = data.texto
        abierto.value  = null
    } catch (e) {
        errorIa.value = 'No se pudo conectar con la IA.'
    } finally {
        generando.value = false
    }
}

// ── Importar un perfil existente ──────────────────────────────────────────────
const modalImportar = ref(false)
const textoImportar = ref('')
const importando    = ref(false)
const errorImportar = ref('')

async function importar() {
    if (!textoImportar.value.trim()) return
    importando.value = true
    errorImportar.value = ''

    try {
        const { ok, data } = await postJson('/api/perfil-marca/importar', { texto: textoImportar.value })

        if (!ok) { errorImportar.value = data.error ?? 'No se pudo importar.'; return }

        modalImportar.value = false
        textoImportar.value = ''
        router.reload()
    } catch (e) {
        errorImportar.value = 'No se pudo conectar con la IA.'
    } finally {
        importando.value = false
    }
}
</script>

<template>
    <AppLayout title="Perfil de marca">
        <div class="max-w-3xl mx-auto">

            <div class="flex items-center gap-3 mb-5">
                <button @click="router.visit('/configuracion')"
                    class="p-2 rounded-xl hover:bg-tinta-100 transition-colors text-tinta-400" title="Volver">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                    </svg>
                </button>
                <h1 class="text-xl font-semibold text-tinta-900">Perfil de marca</h1>
            </div>

            <!-- Credencial y modelos de IA -->
            <div class="bg-superficie rounded-2xl border border-linea p-5 mb-4">
                <div class="flex items-center justify-between mb-1">
                    <h2 class="text-sm font-semibold text-tinta-700">Conexión con la IA</h2>
                    <span class="text-xs px-2 py-0.5 rounded-full"
                        :class="ia.configurada ? 'bg-pastel-verde-2 text-aviso-verde' : 'bg-pastel-ambar-2 text-aviso-ambar'">
                        {{ ia.configurada ? 'Configurada' : 'Sin configurar' }}
                    </span>
                </div>
                <p class="text-xs text-tinta-300 mb-3">
                    {{ ia.por_proxy
                        ? 'El asistente sale por el servicio de Briela, incluido en la suscripción. Aquí solo eliges los modelos.'
                        : 'Una sola credencial de OpenRouter para textos e imágenes. El saldo se recarga en openrouter.ai.' }}
                </p>

                <div class="space-y-3">
                    <!-- Con serial no hay campo de credencial.
                         El asistente sale por el proxy de Briela y una llave propia se
                         ignoraría. Mostrar el campo haría creer que el consumo se paga
                         por fuera, y llevaría a pegar una credencial que no se usa. -->
                    <div v-if="ia.por_proxy" class="rounded-xl bg-tinta-50 px-3 py-2.5">
                        <p class="text-xs text-tinta-500 leading-relaxed">
                            No hace falta ninguna credencial: el consumo de IA va incluido y se
                            mide por instalación. Si el asistente deja de responder, revisa el
                            estado de la suscripción en
                            <button type="button" @click="router.visit('/administracion/actualizacion')"
                                class="font-semibold underline underline-offset-2" :style="{ color: 'var(--marca)' }">Actualizar el sistema</button>.
                        </p>
                    </div>

                    <div v-else>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">
                            API key de OpenRouter
                        </label>
                        <input v-model="iaClave" type="password" autocomplete="off"
                            :placeholder="ia.clave_parcial ? `Guardada (${ia.clave_parcial}) — escribe una nueva para cambiarla` : 'sk-or-v1-...'"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]" />
                        <p class="text-xs text-tinta-300 mt-1">
                            Déjalo vacío si no la vas a cambiar. No se muestra completa por seguridad.
                        </p>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">
                                Modelo de texto
                                <span v-if="totalTexto" class="normal-case text-tinta-300 font-normal">({{ totalTexto }} disponibles)</span>
                            </label>
                            <input v-model="buscarTexto" type="text" placeholder="Filtrar… ej: claude, gpt, gemini"
                                class="w-full rounded-xl border border-linea px-3 py-1.5 text-xs mb-1.5 focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]" />
                            <select v-if="totalTexto" v-model="iaModeloTexto" size="6"
                                class="w-full rounded-xl border border-tinta-200 px-2 py-1 text-xs bg-superficie focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]">
                                <option v-for="(label, id) in modelosTextoFiltrados" :key="id" :value="id">{{ label }}</option>
                            </select>
                            <input v-else v-model="iaModeloTexto" type="text" placeholder="anthropic/claude-sonnet-5"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]" />
                            <p class="text-xs text-tinta-300 mt-1 truncate">Elegido: {{ iaModeloTexto || '—' }}</p>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">
                                Modelo de imagen
                                <span v-if="totalImagen" class="normal-case text-tinta-300 font-normal">({{ totalImagen }} disponibles)</span>
                            </label>
                            <input v-model="buscarImagen" type="text" placeholder="Filtrar… ej: gpt, gemini, flux"
                                class="w-full rounded-xl border border-linea px-3 py-1.5 text-xs mb-1.5 focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]" />
                            <select v-if="totalImagen" v-model="iaModeloImagen" size="6"
                                class="w-full rounded-xl border border-tinta-200 px-2 py-1 text-xs bg-superficie focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]">
                                <option v-for="(label, id) in modelosImagenFiltrados" :key="id" :value="id">{{ label }}</option>
                            </select>
                            <input v-else v-model="iaModeloImagen" type="text" placeholder="openai/gpt-image-2"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]" />
                            <p class="text-xs text-tinta-300 mt-1 truncate">Elegido: {{ iaModeloImagen || '—' }}</p>
                        </div>
                    </div>

                    <!-- Modelo rápido para tareas internas -->
                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">
                            Modelo rápido (opcional)
                        </label>
                        <input v-model="iaModeloRapido" list="modelos-rapidos" type="text"
                            placeholder="Vacío = usa el de texto"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]" />
                        <datalist id="modelos-rapidos">
                            <option v-for="(label, id) in modelosSugeridos.texto" :key="id" :value="id">{{ label }}</option>
                        </datalist>
                        <p class="text-xs text-tinta-300 mt-1">
                            El asistente hace dos llamadas por pregunta: una para decidir qué consultar
                            y otra para redactar. Poner aquí un modelo rápido y barato acelera la
                            primera sin afectar la calidad de la respuesta.
                        </p>
                    </div>

                    <!-- Cupo de la respuesta -->
                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">
                            Espacio máximo por respuesta
                        </label>
                        <input v-model.number="iaMaxTokens" type="number" min="500" max="16000" step="500"
                            class="w-32 rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]" />
                        <p class="text-xs text-tinta-300 mt-1">
                            Medido en tokens (más o menos 3 de cada 4 palabras). Con 3000 alcanza para
                            un informe de varios temas.
                            <br>
                            Súbelo si el asistente devuelve respuestas vacías o cortadas: los modelos
                            que "razonan" gastan de este mismo cupo antes de empezar a escribir.
                        </p>
                    </div>

                    <!-- Prioridad de velocidad en el enrutamiento -->
                    <div class="rounded-xl border border-linea p-3">
                        <label class="flex items-start gap-2.5 cursor-pointer">
                            <input v-model="iaPriorizarVelocidad" type="checkbox" class="mt-0.5 rounded" />
                            <span>
                                <span class="text-sm font-medium text-tinta-900">Priorizar el proveedor más rápido</span>
                                <span class="block text-xs text-tinta-300 mt-1">
                                    OpenRouter reparte el mismo modelo entre varias empresas y por
                                    defecto elige la más barata, no la más rápida. La diferencia puede
                                    ser del doble de velocidad por unos centavos más. Déjalo marcado
                                    salvo que prefieras ahorrar hasta el último peso.
                                </span>
                            </span>
                        </label>
                    </div>

                    <div class="flex gap-3">
                        <button @click="probarIa" :disabled="probando"
                            class="flex-1 py-2.5 rounded-xl border border-linea text-sm text-tinta-500 disabled:opacity-50">
                            {{ probando ? 'Probando…' : 'Probar conexión' }}
                        </button>
                        <button @click="guardarIa"
                            class="flex-1 py-2.5 rounded-xl text-white text-sm font-semibold" style="background:var(--marca);">
                            Guardar
                        </button>
                    </div>

                    <p v-if="resultadoPrueba === 'ok'" class="text-xs text-aviso-verde">
                        Conexión correcta: la IA respondió.
                    </p>
                    <p v-else-if="resultadoPrueba" class="text-xs text-aviso-rojo">{{ resultadoPrueba }}</p>
                </div>
            </div>

            <!-- Prompt de la ficha técnica -->
            <div class="bg-superficie rounded-2xl border border-linea p-5 mb-4">
                <h2 class="text-sm font-semibold text-tinta-700 mb-1">Cómo redacta las fichas técnicas</h2>
                <p class="text-xs text-tinta-300 mb-3">
                    Las instrucciones con las que la IA arma la ficha de un producto o un ensamble,
                    desde el botón «Ficha técnica con IA» del formulario. Cada rubro describe
                    distinto: si tu ficha necesita otros bloques, cámbialos aquí.
                </p>

                <textarea v-model="promptFicha" rows="14"
                    class="w-full border border-linea rounded-xl px-3 py-2 text-xs font-mono bg-superficie focus:outline-none focus:border-[var(--marca)]"></textarea>

                <p class="text-xs text-tinta-300 mt-2">
                    Tres cosas no se pueden cambiar desde aquí porque son del sistema: el español sin
                    voseo, la prohibición de inventar especificaciones, y el formato con el que la
                    respuesta llega a los dos campos. El tono lo pone tu perfil de marca — no hace
                    falta describirlo otra vez.
                </p>

                <div class="flex gap-3 mt-3">
                    <button @click="restablecerPromptFicha"
                        class="flex-1 py-2.5 rounded-xl border border-linea text-sm text-tinta-500">
                        Volver al de fábrica
                    </button>
                    <button @click="guardarPromptFicha"
                        class="flex-1 py-2.5 rounded-xl text-white text-sm font-semibold" style="background:var(--marca);">
                        Guardar prompt
                    </button>
                </div>
            </div>

            <!-- El asistente -->
            <div class="bg-superficie rounded-2xl border border-linea p-5 mb-4">
                <h2 class="text-sm font-semibold text-tinta-700 mb-1">Tu asistente</h2>
                <p class="text-xs text-tinta-300 mb-3">
                    Ponle el nombre que quieras. Responde usando este perfil de marca.
                </p>
                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Nombre</label>
                        <input v-model="asistenteNombre" type="text" maxlength="40" placeholder="Ej: Frida"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]" />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Rol</label>
                        <textarea v-model="asistenteRol" rows="3" maxlength="1000"
                            placeholder="¿Qué rol cumple? Ej: analista de datos del negocio"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]"></textarea>
                        <div class="flex flex-wrap gap-1.5 mt-2">
                            <button v-for="(r, i) in rolesSugeridos" :key="i" type="button"
                                @click="asistenteRol = r"
                                class="text-xs px-2 py-1 rounded-full border border-linea text-tinta-500 hover:bg-tinta-50">
                                {{ r.split(':')[0] }}
                            </button>
                        </div>
                        <p class="text-xs text-tinta-300 mt-1.5">
                            El rol cambia cómo interpreta y responde. No cambia a qué datos accede:
                            eso lo definen los permisos de cada usuario.
                        </p>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Personalidad (opcional)</label>
                        <textarea v-model="asistentePersonalidad" rows="2" maxlength="500"
                            placeholder="Ej: directa, práctica, con vocabulario técnico de refrigeración"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]"></textarea>
                    </div>
                    <!-- Su voz -->
                    <div class="rounded-xl bg-tinta-50 border border-linea p-3">
                        <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-2">Su voz</p>

                        <label class="flex items-start gap-2 cursor-pointer">
                            <input v-model="vozNatural" type="checkbox" class="rounded mt-0.5" />
                            <span class="text-sm text-tinta-700">
                                Voz natural
                                <span class="block text-xs text-tinta-300">
                                    Genera el audio con IA: suena humana, pero consume saldo. Sin esto
                                    usa la voz del sistema operativo, que es gratis pero robótica.
                                </span>
                            </span>
                        </label>

                        <div v-if="vozNatural" class="mt-3 space-y-3">
                            <div>
                                <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Voz</label>
                                <select v-model="vozElegida"
                                    class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm bg-superficie focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]">
                                    <option v-for="(label, id) in vocesSugeridas" :key="id" :value="id">{{ label }}</option>
                                    <option value="__otra">Otra (escribirla a mano)</option>
                                </select>
                                <input v-if="vozElegida === '__otra'" v-model="vozPersonalizada" type="text"
                                    placeholder="Nombre de la voz según el modelo"
                                    class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm mt-1.5 font-mono focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]" />
                                <p class="text-xs text-tinta-300 mt-1">
                                    Estas voces son de los modelos de OpenAI. Otros modelos tienen las suyas.
                                </p>
                            </div>
                            <!-- Acento y tono: es lo que más cambia el resultado -->
                            <div>
                                <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">
                                    Cómo debe hablar
                                </label>
                                <textarea v-model="vozInstrucciones" rows="4" maxlength="1000"
                                    placeholder="Ej: español colombiano de Bogotá, tono cálido y cercano, ritmo conversacional"
                                    class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]"></textarea>
                                <div class="flex flex-wrap gap-1.5 mt-2">
                                    <button v-for="(texto, nombre) in acentos" :key="nombre" type="button"
                                        @click="vozInstrucciones = texto"
                                        class="text-xs px-2 py-1 rounded-full border border-linea text-tinta-500 hover:bg-tinta-50">
                                        {{ nombre }}
                                    </button>
                                </div>
                                <p class="text-xs text-tinta-300 mt-1.5">
                                    Esto cambia el acento y el tono mucho más que elegir otra voz.
                                    Descríbelo con tus palabras, como se lo explicarías a una persona.
                                </p>
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">
                                    Modelo de voz
                                    <span v-if="totalVoz" class="normal-case text-tinta-300 font-normal">({{ totalVoz }} disponibles)</span>
                                </label>
                                <!-- Siempre editable a mano: la lista de OpenRouter no
                                     siempre trae todos los modelos de voz. -->
                                <input v-model="modeloVoz" type="text"
                                    placeholder="openai/gpt-4o-mini-tts-2025-12-15"
                                    class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]" />

                                <button type="button" @click="modeloVoz = 'openai/gpt-audio-mini'"
                                    class="mt-1.5 text-xs font-semibold text-[var(--marca)] hover:underline">
                                    Usar el modelo recomendado
                                </button>

                                <details v-if="totalVoz" class="mt-2">
                                    <summary class="text-xs text-tinta-400 cursor-pointer">
                                        Ver los {{ totalVoz }} modelos que detecté en tu cuenta
                                    </summary>
                                    <input v-model="buscarVoz" type="text" placeholder="Filtrar…"
                                        class="w-full rounded-xl border border-linea px-3 py-1.5 text-xs my-1.5 focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]" />
                                    <div class="max-h-32 overflow-y-auto rounded-xl border border-linea divide-y divide-separador">
                                        <button v-for="(label, id) in modelosVozFiltrados" :key="id" type="button"
                                            @click="modeloVoz = id"
                                            class="w-full text-left px-2 py-1.5 text-xs hover:bg-tinta-50">
                                            {{ label }} <span class="text-tinta-300">· {{ id }}</span>
                                        </button>
                                    </div>
                                    <p class="text-xs text-tinta-300 mt-1">
                                        Esta lista puede estar incompleta. Si el modelo que buscas no aparece,
                                        escríbelo a mano arriba.
                                    </p>
                                </details>
                            </div>

                            <button type="button" @click="escucharVoz" :disabled="probandoVoz"
                                class="w-full py-2.5 rounded-xl border text-sm font-semibold disabled:opacity-50"
                                style="border-color:var(--marca); color:var(--marca);">
                                {{ probandoVoz ? 'Generando audio…' : 'Escuchar cómo suena' }}
                            </button>
                            <p v-if="errorVoz" class="text-xs text-aviso-rojo">{{ errorVoz }}</p>
                        </div>
                    </div>

                    <button @click="guardarAsistente"
                        class="w-full py-2.5 rounded-xl text-white text-sm font-semibold" style="background:var(--marca);">
                        Guardar asistente
                    </button>
                </div>
            </div>

            <!-- Importar -->
            <div class="flex items-center justify-between mb-3">
                <p class="text-xs text-tinta-300">
                    Cada sección se puede escribir a mano o dejar que la IA la redacte.
                </p>
                <button @click="modalImportar = true"
                    class="text-xs font-semibold text-[var(--marca)] hover:underline shrink-0">
                    Importar desde un documento
                </button>
            </div>

            <!-- Secciones -->
            <div class="space-y-3">
                <div v-for="fila in filas" :key="fila.seccion" class="bg-superficie rounded-2xl border border-linea p-4">
                    <div class="flex items-start justify-between gap-2 mb-2">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <h3 class="text-sm font-semibold text-tinta-900">{{ fila.label }}</h3>
                                <span v-if="!fila.contenido"
                                    class="text-xs px-2 py-0.5 rounded-full bg-pastel-ambar-2 text-aviso-ambar">Vacía</span>
                                <span v-if="fila.generado_ia_at"
                                    class="text-xs px-2 py-0.5 rounded-full bg-tinta-100 text-tinta-400">IA · {{ fila.generado_ia_at }}</span>
                            </div>
                            <p class="text-xs text-tinta-300 mt-0.5">{{ fila.ayuda }}</p>
                        </div>
                        <button @click="abrirPregunta(fila)"
                            class="text-xs font-semibold text-[var(--marca)] hover:underline shrink-0">
                            {{ abierto === fila.seccion ? 'Cerrar' : 'Redactar con IA' }}
                        </button>
                    </div>

                    <!-- Cuestionario guiado -->
                    <div v-if="abierto === fila.seccion" class="rounded-xl bg-tinta-50 border border-linea p-3 mb-2 space-y-2">
                        <p class="text-sm text-tinta-700 font-medium">{{ fila.pregunta }}</p>
                        <textarea v-model="respuesta" rows="3" maxlength="5000"
                            placeholder="Responde con tus palabras, sin preocuparte por la redacción."
                            class="w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]"></textarea>
                        <button @click="generar(fila)" :disabled="generando || !respuesta.trim()"
                            class="w-full py-2 rounded-lg text-white text-sm font-semibold disabled:opacity-50"
                            style="background:var(--marca);">
                            {{ generando ? 'Redactando…' : 'Redactar' }}
                        </button>
                        <p v-if="errorIa" class="text-xs text-aviso-rojo">{{ errorIa }}</p>
                    </div>

                    <textarea v-model="fila.contenido" rows="5" maxlength="20000"
                        class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]"></textarea>

                    <div class="flex justify-end mt-2">
                        <button @click="guardar(fila)"
                            class="px-4 py-2 rounded-xl text-white text-sm font-semibold" style="background:var(--marca);">
                            Guardar
                        </button>
                    </div>
                </div>
            </div>

            <!-- Modal importar -->
            <Teleport to="body">
                <div v-if="modalImportar" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-4"
                    style="background:rgba(0,0,0,0.5);" @click.self="modalImportar = false">
                    <div class="bg-superficie rounded-2xl shadow-xl w-full max-w-lg p-5">
                        <h3 class="text-base font-semibold text-tinta-900 mb-1">Importar perfil de marca</h3>
                        <p class="text-sm text-tinta-400 mb-3">
                            Pega aquí el texto de tu documento de marca. La IA lo reparte en las secciones.
                            Reemplaza lo que ya haya en las secciones que encuentre.
                        </p>
                        <textarea v-model="textoImportar" rows="10" maxlength="60000"
                            placeholder="Pega el contenido del documento…"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm mb-3 focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]"></textarea>
                        <p v-if="errorImportar" class="text-xs text-aviso-rojo mb-3">{{ errorImportar }}</p>
                        <div class="flex gap-3">
                            <button @click="modalImportar = false"
                                class="flex-1 py-2.5 rounded-xl border border-linea text-sm text-tinta-500">
                                Cancelar
                            </button>
                            <button @click="importar" :disabled="importando || !textoImportar.trim()"
                                class="flex-1 py-2.5 rounded-xl text-white text-sm font-semibold disabled:opacity-50"
                                style="background:var(--marca);">
                                {{ importando ? 'Importando…' : 'Importar' }}
                            </button>
                        </div>
                    </div>
                </div>
            </Teleport>

        </div>
    </AppLayout>
</template>
