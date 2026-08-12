<script setup>
import { ref, computed } from 'vue'
import AppLayout from '@/Layouts/AppLayout.vue'
import { colorMarca } from '@/marca'

const TIPOS = [
    { key: 'tipo_contacto',       label: 'Tipos de contacto' },
    { key: 'industria',           label: 'Industrias' },
    { key: 'proceso_seguimiento', label: 'Proceso de seguimiento' },
    { key: 'fuente_contacto',     label: 'Fuentes de contacto' },
]

const opciones = ref({})
const cargando = ref(true)
const abiertos = ref({})
const mensaje  = ref('')
const mensajeEsError = ref(false)

const csrf = () => {
    const c = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))
    return c ? decodeURIComponent(c.split('=')[1]) : ''
}
const headers = () => ({
    'Content-Type': 'application/json',
    Accept: 'application/json',
    'X-XSRF-TOKEN': csrf(),
})

async function cargar() {
    cargando.value = true
    try {
        const r = await fetch('/api/segmentacion-opciones', { headers: headers() })
        opciones.value = await r.json()
    } finally { cargando.value = false }
}
cargar()

// ─── Formulario inline ────────────────────────────────────────────────────────
const formNuevo = ref({})
const editando  = ref({}) // { id, etiqueta, color }

function iniciarNuevo(tipo) {
    formNuevo.value[tipo] = { etiqueta: '', color: colorMarca() }
}

function cancelarNuevo(tipo) {
    delete formNuevo.value[tipo]
}

async function guardarNuevo(tipo) {
    const f = formNuevo.value[tipo]
    if (!f?.etiqueta?.trim()) return
    const r = await fetch('/api/segmentacion-opciones', {
        method: 'POST',
        headers: headers(),
        body: JSON.stringify({ tipo, etiqueta: f.etiqueta, color: f.color }),
    })
    if (r.ok) {
        mensaje.value = 'Opción agregada.'
        setTimeout(() => mensaje.value = '', 2000)
        cancelarNuevo(tipo)
        await cargar()
    }
}

function iniciarEdicion(op) {
    editando.value = { id: op.id, etiqueta: op.etiqueta, color: op.color ?? colorMarca() }
}

function cancelarEdicion() {
    editando.value = {}
}

async function guardarEdicion() {
    const { id, etiqueta, color } = editando.value
    if (!etiqueta?.trim()) return
    const r = await fetch(`/api/segmentacion-opciones/${id}`, {
        method: 'PUT',
        headers: headers(),
        body: JSON.stringify({ etiqueta, color }),
    })
    if (r.ok) {
        mensaje.value = 'Guardado.'
        setTimeout(() => mensaje.value = '', 2000)
        cancelarEdicion()
        await cargar()
    }
}

/**
 * Cambia una de las tres marcas de precio de un tipo de contacto.
 *
 * El servidor rechaza las combinaciones que no tienen sentido —canal base sin precio,
 * por ejemplo— y devuelve el motivo. Ese motivo se muestra tal cual: son decisiones de
 * dinero y el usuario tiene que entender por qué no se pudo, no ver un error genérico.
 */
/**
 * Explica por qué una opción no se puede eliminar, en vez de esconder el botón.
 *
 * Antes el botón simplemente no estaba, y con todos los tipos marcados como canal no había
 * forma de eliminar nada ni de saber por qué.
 */
function avisarNoBorrable(op) {
    const papel = op.es_canal_base ? 'el canal base' : 'el precio público'

    mensaje.value = `«${op.etiqueta}» es ${papel}, y el sistema necesita uno: sin canal base las `
        + 'comisiones salen en cero, y sin precio público el catálogo no sabe qué mostrar. '
        + 'Marca otro canal con esa función y este queda libre.'
    mensajeEsError.value = true
}

/**
 * Sube o baja una opción en su lista.
 *
 * Con flechas y no arrastrando: en un celular arrastrar dentro de una lista que además hace
 * scroll es incómodo y se falla, y aquí el orden decide precios — no es un sitio donde
 * convenga equivocarse por un dedo mal puesto.
 *
 * Se reenumera la lista completa de 1 en 1 al guardar: los números venían con huecos de
 * borrados anteriores, y con huecos «el siguiente» deja de ser predecible.
 */
async function moverOpcion(tipo, indice, direccion) {
    const lista = [...(opciones.value[tipo] ?? [])]
    const destino = indice + direccion

    if (destino < 0 || destino >= lista.length) return

    ;[lista[indice], lista[destino]] = [lista[destino], lista[indice]]

    // Se pinta ya, sin esperar al servidor: mover algo y ver que no se mueve se siente roto.
    opciones.value[tipo] = lista

    const r = await fetch('/api/segmentacion-opciones/reordenar', {
        method: 'POST',
        headers: headers(),
        body: JSON.stringify({ items: lista.map((op, i) => ({ id: op.id, orden: i + 1 })) }),
    })

    if (!r.ok) {
        mensaje.value = 'No se pudo guardar el orden.'
        mensajeEsError.value = true
        await cargar()
        return
    }

    mensaje.value = 'Orden guardado.'
    mensajeEsError.value = false
    setTimeout(() => mensaje.value = '', 1500)
    await cargar()
}

/** El margen con el que este canal nace en un producto nuevo. */
async function cambiarMargen(op, valor) {
    const r = await fetch(`/api/segmentacion-opciones/${op.id}`, {
        method: 'PUT',
        headers: headers(),
        body: JSON.stringify({ margen_sugerido: Number(valor) || 0 }),
    })

    if (!r.ok) {
        const d = await r.json().catch(() => ({}))
        mensaje.value = d.message ?? 'No se pudo guardar el margen.'
        mensajeEsError.value = true
        return
    }

    mensaje.value = 'Margen guardado.'
    mensajeEsError.value = false
    setTimeout(() => mensaje.value = '', 2000)
    await cargar()
}

async function cambiarMarca(op, marca) {
    const r = await fetch(`/api/segmentacion-opciones/${op.id}`, {
        method: 'PUT',
        headers: headers(),
        body: JSON.stringify({ [marca]: !op[marca] }),
    })

    if (!r.ok) {
        const d = await r.json().catch(() => ({}))
        mensaje.value = d.message ?? 'No se pudo cambiar.'
        mensajeEsError.value = true
        return
    }

    mensaje.value = 'Guardado.'
    mensajeEsError.value = false
    setTimeout(() => mensaje.value = '', 2500)
    await cargar()
}

async function eliminar(id, op = null) {
    // Si el canal tiene precios cargados, se van con él. Decirlo antes y con el número:
    // «¿Eliminar esta opción?» no deja ver que se están borrando precios de productos.
    const cuantos = op?.precios_count ?? 0

    const pregunta = cuantos > 0
        ? `«${op.etiqueta}» tiene ${cuantos} precios cargados en productos o ensambles.\n\n`
          + 'Si la eliminas, esos precios se borran y hay que volver a cargarlos. Los clientes '
          + 'que tengan este tipo se quedan sin precio hasta que les asignes otro.\n\n¿Continuar?'
        : '¿Eliminar esta opción?'

    if (!confirm(pregunta)) return

    const r = await fetch(`/api/segmentacion-opciones/${id}`, {
        method: 'DELETE',
        headers: headers(),
    })
    if (r.ok) {
        mensajeEsError.value = false
        mensaje.value = 'Eliminada.'
        setTimeout(() => mensaje.value = '', 2000)
        await cargar()
        return
    }

    // El servidor bloquea las opciones que definen el precio: hay que decir por qué.
    const cuerpo = await r.json().catch(() => ({}))
    mensajeEsError.value = true
    mensaje.value = cuerpo.message ?? 'No se pudo eliminar la opción.'
    setTimeout(() => mensaje.value = '', 6000)
}

function toggleAbierto(key) {
    abiertos.value[key] = !abiertos.value[key]
}

/**
 * Lo que falta para que los precios funcionen.
 *
 * Sin canal base las comisiones salen en cero; sin precio público el catálogo no muestra
 * nada. Ninguna de las dos cosas da error en pantalla, así que hay que decirlo aquí: se
 * descubren en una factura o en una queja de un cliente.
 */
const faltaConfigurar = computed(() => {
    const canales = (opciones.value.tipo_contacto ?? [])
    const falta = []

    if (! canales.some(c => c.define_precio))     falta.push('ningún tipo de contacto define precio, así que ningún cliente puede tener precio')
    if (! canales.some(c => c.es_canal_base))     falta.push('falta el canal base: sin él las comisiones se calculan en cero')
    if (! canales.some(c => c.es_precio_publico)) falta.push('falta el precio público: el catálogo no va a mostrar precio')

    return falta
})
</script>

<template>
    <AppLayout title="Segmentación de Clientes">
        <div class="max-w-3xl mx-auto px-4 py-4">

            <div class="flex items-center gap-3 mb-4">
                <a href="/clientes" class="text-tinta-300 hover:text-tinta-500">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M15 19l-7-7 7-7"/>
                    </svg>
                </a>
                <h1 class="text-xl font-semibold text-tinta-900">Listas de segmentación</h1>
            </div>

            <!-- Toast -->
            <div v-if="mensaje" class="mb-3 px-4 py-2 rounded-xl text-sm font-medium text-white"
                :style="`background:${mensajeEsError ? '#B91C1C' : 'var(--marca)'};`">
                {{ mensaje }}
            </div>

            <div v-if="cargando" class="py-12 text-center text-tinta-300 text-sm">Cargando opciones...</div>

            <div v-else class="space-y-3">
                <div v-for="tipo in TIPOS" :key="tipo.key" class="bg-superficie rounded-xl border border-linea overflow-hidden">

                    <!-- Cabecera acordeón -->
                    <button type="button" @click="toggleAbierto(tipo.key)"
                        class="w-full flex items-center justify-between px-4 py-3 text-left hover:bg-tinta-50 transition-colors">
                        <span class="text-sm font-semibold text-tinta-900">{{ tipo.label }}</span>
                        <div class="flex items-center gap-3">
                            <span class="text-xs text-tinta-300">{{ (opciones[tipo.key] ?? []).length }} opciones</span>
                            <svg class="w-4 h-4 text-tinta-300 transition-transform"
                                :class="abiertos[tipo.key] ? 'rotate-180' : ''"
                                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </div>
                    </button>

                    <!-- Contenido acordeón -->
                    <div v-if="abiertos[tipo.key]" class="border-t border-linea p-4">

                        <!-- Lo que falta para que los precios funcionen. Va arriba y en rojo
                             porque nada de esto da error en pantalla: se descubre cobrando. -->
                        <div v-if="tipo.key === 'tipo_contacto' && faltaConfigurar.length"
                            class="mb-3 px-3 py-2.5 rounded-xl bg-red-50 border border-red-200">
                            <p class="text-xs font-semibold text-red-700 mb-1">Falta configurar</p>
                            <ul class="space-y-0.5">
                                <li v-for="f in faltaConfigurar" :key="f" class="text-xs text-red-700 leading-relaxed">· {{ f }}</li>
                            </ul>
                            <p class="text-xs text-red-600 mt-1.5">
                                Toca «canal base» o «precio público» en la opción que deba serlo. Se marca de una,
                                sin tener que darle «define precio» antes.
                            </p>
                        </div>

                        <!-- El orden de esta lista decide tres cosas, y no es evidente.
                             Vale más decirlo aquí que dejar que se descubra en una factura. -->
                        <div v-if="tipo.key === 'tipo_contacto'"
                            class="mb-3 px-3 py-2.5 rounded-xl bg-tinta-50 border border-linea">
                            <p class="text-xs text-tinta-500 leading-relaxed">
                                <span class="font-semibold text-tinta-700">El orden importa.</span>
                                Va del canal más barato al más caro, y decide tres cosas: qué precio
                                paga un cliente que tenga varios tipos —gana el de más arriba—, hasta
                                dónde puede descontar cada canal —hasta el precio del anterior— y
                                cuánta comisión gana el vendedor, que sube en cada escalón.
                            </p>
                        </div>

                        <!-- Lista de opciones -->
                        <div class="space-y-1.5 mb-3">
                            <div v-for="(op, indice) in (opciones[tipo.key] ?? [])" :key="op.id">
                                <!-- Modo edición inline -->
                                <div v-if="editando.id === op.id"
                                    class="flex items-center gap-2 p-2 rounded-lg border-2" style="border-color:var(--marca); background:var(--pastel-azul);">
                                    <input v-model="editando.etiqueta" type="text"
                                        class="flex-1 text-sm border border-tinta-200 rounded-lg px-2 py-1 focus:outline-none focus:border-[var(--marca)]"
                                        @keyup.enter="guardarEdicion" @keyup.escape="cancelarEdicion" />
                                    <div class="flex items-center gap-1.5 shrink-0">
                                        <div class="w-5 h-5 rounded-full border border-tinta-200 cursor-pointer"
                                            :style="`background:${editando.color ?? 'var(--marca)'};`"
                                            :title="editando.color"/>
                                        <input v-model="editando.color" type="color"
                                            class="w-7 h-7 rounded cursor-pointer border-0 p-0" />
                                    </div>
                                    <button type="button" @click="guardarEdicion"
                                        class="text-xs px-2 py-1 rounded-lg text-white" style="background:var(--marca);">✓</button>
                                    <button type="button" @click="cancelarEdicion"
                                        class="text-xs px-2 py-1 rounded-lg border border-tinta-200 text-tinta-400">✕</button>
                                </div>

                                <!-- Modo visualización.
                                     Dos líneas y no una: con las tres marcas en la misma fila
                                     no cabían los botones de editar y eliminar, y quedaban
                                     fuera de la pantalla. Y dejan de aparecer solo al pasar el
                                     cursor — en un celular eso nunca ocurre. -->
                                <div v-else class="px-2 py-2 rounded-lg hover:bg-tinta-50">
                                    <div class="flex items-center gap-2">
                                        <!-- Subir y bajar. El orden decide qué precio paga un
                                             cliente, así que tiene que poder cambiarse aquí. -->
                                        <div class="flex flex-col shrink-0 -my-1">
                                            <button type="button"
                                                :disabled="indice === 0"
                                                @click="moverOpcion(tipo.key, indice, -1)"
                                                class="px-1 leading-none text-tinta-300 hover:text-tinta-700 disabled:opacity-25 disabled:hover:text-tinta-300"
                                                title="Subir">
                                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7"/>
                                                </svg>
                                            </button>
                                            <button type="button"
                                                :disabled="indice === (opciones[tipo.key] ?? []).length - 1"
                                                @click="moverOpcion(tipo.key, indice, 1)"
                                                class="px-1 leading-none text-tinta-300 hover:text-tinta-700 disabled:opacity-25 disabled:hover:text-tinta-300"
                                                title="Bajar">
                                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                                                </svg>
                                            </button>
                                        </div>
                                        <div class="w-3 h-3 rounded-full shrink-0"
                                            :style="`background:${op.color ?? '#9CA3AF'};`"/>
                                        <span class="text-sm text-tinta-700 flex-1 min-w-0 truncate">{{ op.etiqueta }}</span>
                                        <span class="text-xs text-tinta-300 font-mono hidden sm:block shrink-0">{{ op.valor }}</span>
                                        <div class="flex gap-1 shrink-0">
                                            <button type="button" @click="iniciarEdicion(op)"
                                                class="text-xs px-2 py-1 rounded text-blue-600 hover:bg-blue-50">Editar</button>
                                            <button type="button"
                                                :disabled="op.atada_a_precios"
                                                @click="op.atada_a_precios ? avisarNoBorrable(op) : eliminar(op.id, op)"
                                                class="text-xs px-2 py-1 rounded"
                                                :class="op.atada_a_precios
                                                    ? 'text-tinta-300 cursor-help'
                                                    : 'text-red-500 hover:bg-red-50'"
                                                :title="op.atada_a_precios
                                                    ? 'Define precio, así que no se puede eliminar. Toca para saber por qué.'
                                                    : 'Eliminar esta opción'">
                                                Eliminar
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Las tres marcas de precio, en su propia línea. Solo en
                                         tipos de contacto: industrias o fuentes de contacto no
                                         cobran nada. Son botones y no etiquetas porque ahora los
                                         decide la empresa; antes estaban en el código. -->
                                    <div v-if="tipo.key === 'tipo_contacto'" class="flex items-center flex-wrap gap-1 mt-1.5 ml-5">
                                        <button type="button" @click="cambiarMarca(op, 'define_precio')"
                                            class="text-[10px] px-1.5 py-0.5 rounded-full border transition-colors"
                                            :class="op.define_precio
                                                ? 'bg-amber-50 text-amber-700 border-amber-200'
                                                : 'bg-tinta-50 text-tinta-300 border-linea hover:text-tinta-500'"
                                            :title="op.define_precio
                                                ? 'Tiene su propia lista de precios en productos y ensambles. Toca para quitarlo.'
                                                : 'Toca para darle su propia lista de precios.'">
                                            define precio
                                        </button>

                                        <button type="button" @click="cambiarMarca(op, 'es_canal_base')"
                                            class="text-[10px] px-1.5 py-0.5 rounded-full border transition-colors"
                                            :class="op.es_canal_base
                                                ? 'bg-blue-50 text-blue-700 border-blue-200'
                                                : 'bg-tinta-50 text-tinta-300 border-linea hover:text-tinta-500'"
                                            title="El piso de utilidad de la empresa: no paga comisión al vendedor, y la comisión de los demás canales se calcula contra su precio. Solo uno puede serlo.">
                                            canal base
                                        </button>

                                        <button type="button" @click="cambiarMarca(op, 'es_precio_publico')"
                                            class="text-[10px] px-1.5 py-0.5 rounded-full border transition-colors"
                                            :class="op.es_precio_publico
                                                ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                                                : 'bg-tinta-50 text-tinta-300 border-linea hover:text-tinta-500'"
                                            title="El precio que ve alguien que no ha entrado al sistema, en el catálogo público. Solo uno puede serlo.">
                                            precio público
                                        </button>

                                        <!-- El margen con el que nace este canal en un producto
                                             nuevo. Estaba escrito en el servidor (25/30/35); ahora
                                             lo pone la empresa y se puede cambiar al crear cada
                                             producto. -->
                                        <label v-if="op.define_precio"
                                            class="flex items-center gap-1 text-[10px] text-tinta-400 pl-1">
                                            margen
                                            <input type="number" min="0" max="99" step="0.5"
                                                :value="op.margen_sugerido"
                                                @change="cambiarMargen(op, $event.target.value)"
                                                class="w-14 rounded border border-linea px-1.5 py-0.5 text-[11px] text-right bg-superficie focus:outline-none focus:border-[var(--marca)]" />
                                            %
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <p v-if="!(opciones[tipo.key] ?? []).length" class="text-sm text-tinta-300 text-center py-3">
                                Sin opciones configuradas.
                            </p>
                        </div>

                        <!-- Formulario nueva opción -->
                        <div v-if="formNuevo[tipo.key]"
                            class="flex items-center gap-2 p-2 rounded-lg border-2 border-dashed" style="border-color:var(--marca); background:var(--pastel-azul);">
                            <input v-model="formNuevo[tipo.key].etiqueta" type="text" placeholder="Etiqueta..."
                                class="flex-1 text-sm border border-tinta-200 rounded-lg px-2 py-1 focus:outline-none focus:border-[var(--marca)]"
                                @keyup.enter="guardarNuevo(tipo.key)" @keyup.escape="cancelarNuevo(tipo.key)"
                                autofocus />
                            <input v-model="formNuevo[tipo.key].color" type="color"
                                class="w-7 h-7 rounded cursor-pointer border-0 p-0 shrink-0" />
                            <button type="button" @click="guardarNuevo(tipo.key)"
                                class="text-xs px-3 py-1.5 rounded-lg text-white shrink-0" style="background:var(--marca);">Agregar</button>
                            <button type="button" @click="cancelarNuevo(tipo.key)"
                                class="text-xs px-2 py-1 rounded-lg border border-tinta-200 text-tinta-400 shrink-0">✕</button>
                        </div>

                        <button v-else type="button" @click="iniciarNuevo(tipo.key)"
                            class="w-full mt-1 text-sm py-2 rounded-lg border border-dashed border-tinta-200 text-tinta-300 hover:border-blue-400 hover:text-blue-600 transition-colors">
                            + Agregar opción
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
