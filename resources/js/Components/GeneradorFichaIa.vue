<script setup>
/**
 * Genera la ficha técnica con IA y la deja en los dos campos de descripción.
 *
 * **Un campo por bloque de la ficha, no una caja de «datos en bruto».** Con una sola caja,
 * el modelo tenía que adivinar qué parte del texto era una característica, cuál una ventaja
 * y cuál un beneficio: adivinaba mal, mezclaba beneficios entre las especificaciones y
 * repetía la misma idea tres veces. Si quien escribe ya sabe a qué bloque pertenece cada
 * cosa, decirlo cuesta un rótulo y mejora la ficha completa.
 *
 * Ningún campo es obligatorio, pero alguno tiene que venir: con solo el nombre, lo único
 * que puede hacer la IA es inventar, y eso es justo lo que el prompt prohíbe.
 *
 * Muestra el resultado y espera: nada se escribe en el formulario hasta «Usar esto», y ni
 * siquiera entonces se guarda —eso sigue siendo el botón Guardar de siempre.
 */
import { ref, computed } from 'vue'

const props = defineProps({
    // Lo que ya está en el formulario: nombre, referencia, categoria, unidad, tipo.
    datos:       { type: Object, required: true },
    // Para un ensamble: sus medidas y su receta se leen en el servidor.
    ensambleId:  { type: Number, default: null },
})

const emit = defineEmits(['usar'])

const abierto   = ref(false)
const cargando  = ref(false)
const error     = ref('')
const resultado = ref(null)

/** Un campo por bloque, en el orden en que salen en la ficha. */
const aportes = ref({
    aporte_descripcion:     '',
    aporte_caracteristicas: '',
    aporte_ventajas:        '',
    aporte_beneficios:      '',
    aporte_componentes:     '',
})

const esEnsamble = computed(() => props.datos?.tipo === 'ensamble')

/**
 * Las casillas, con instrucciones concretas. El texto de ayuda importa tanto como el
 * campo: es lo que evita que alguien escriba una ventaja donde va una característica.
 */
const casillas = computed(() => [
    {
        campo: 'aporte_descripcion',
        titulo: 'Qué es y para qué sirve',
        ayuda: 'De esto sale la introducción comercial. Qué problema resuelve, dónde se usa, quién lo usa.',
        ejemplo: 'Puerta rápida para cámara de congelación. Se instala entre el muelle y la cámara, en operaciones con paso continuo de estibas. Evita la pérdida de frío en cada apertura.',
        filas: 3,
    },
    {
        campo: 'aporte_caracteristicas',
        titulo: 'Características técnicas',
        ayuda: 'Medidas, materiales, potencia, voltaje, acabados, normas, temperaturas, capacidades. Como venga, en desorden: la IA las agrupa en subtítulos.',
        ejemplo: '2400x2600 mm · lámina de acero galvanizado cal. 22 · motor 1.5 kW 220V trifásico · velocidad 1.2 m/s · aislamiento poliuretano 40 mm · rango -25 °C a 40 °C · IP65',
        filas: 5,
    },
    {
        campo: 'aporte_ventajas',
        titulo: 'Ventajas frente a otras opciones',
        ayuda: 'Qué tiene este que no tienen las alternativas del mercado. Si lo dejas vacío, la IA las deduce de las características.',
        ejemplo: 'Motor con variador incluido, no opcional. Repuestos en el país. Estructura reforzada en el punto donde fallan las importadas.',
        filas: 3,
    },
    {
        campo: 'aporte_beneficios',
        titulo: 'Beneficios para el cliente',
        ayuda: 'El valor operacional: ahorro, tiempos, mermas, seguridad. Vacío también se deduce.',
        ejemplo: 'Baja el consumo del compresor. Menos producto perdido por rotura de cadena de frío. El operario no baja de la estiba para abrir.',
        filas: 3,
    },
    {
        campo: 'aporte_componentes',
        titulo: 'Componentes y accesorios',
        ayuda: esEnsamble.value
            ? 'Los de la receta del ensamble ya van solos. Agrega aquí lo que no esté ahí: accesorios, opcionales, elementos de instalación.'
            : 'Módulos, accesorios o partes que acompañan al producto. Déjalo vacío si no aplica.',
        ejemplo: 'Botonera de tres posiciones · fotocelda de seguridad · lazo magnético · kit de anclaje',
        filas: 3,
    },
])

const hayAlgo = computed(() => Object.values(aportes.value).some(v => (v || '').trim() !== ''))
const faltaNombre = computed(() => ! (props.datos?.nombre || '').trim())

const csrf = () => {
    const c = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))
    return c ? decodeURIComponent(c.split('=')[1]) : ''
}

async function generar() {
    cargando.value = true
    error.value = ''
    resultado.value = null

    try {
        const res = await fetch('/api/ia/ficha-tecnica', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': csrf(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                ...props.datos,
                ...aportes.value,
                ensamble_id: props.ensambleId,
            }),
        })

        const data = await res.json().catch(() => null)

        if (! res.ok) throw new Error(data?.error || `No se pudo generar (${res.status}).`)

        resultado.value = data
    } catch (e) {
        error.value = e.message || 'No se pudo conectar con la IA.'
    } finally {
        cargando.value = false
    }
}

function usar() {
    emit('usar', {
        descripcion_corta:      resultado.value?.descripcion_corta ?? '',
        descripcion_cotizacion: resultado.value?.descripcion_cotizacion ?? '',
        descripcion_larga:      resultado.value?.ficha_html ?? '',
    })
    abierto.value = false
    resultado.value = null
}

/** Pone el ejemplo en el campo, para que se vea el nivel de detalle que ayuda. */
function usarEjemplo(casilla) {
    if (! (aportes.value[casilla.campo] || '').trim()) {
        aportes.value[casilla.campo] = casilla.ejemplo
    }
}
</script>

<template>
    <button type="button" @click="abierto = true"
        class="text-xs font-semibold text-[var(--marca)] hover:underline inline-flex items-center gap-1">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/>
        </svg>
        Ficha técnica con IA
    </button>

    <Teleport to="body">
        <div v-if="abierto" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
            <div class="absolute inset-0 bg-black/40" @click="abierto = false" />

            <div class="relative w-full sm:max-w-2xl bg-superficie rounded-t-3xl sm:rounded-2xl shadow-2xl flex flex-col max-h-[92vh]">
                <div class="flex items-center justify-between px-5 py-4 border-b border-linea shrink-0">
                    <div class="min-w-0">
                        <h3 class="text-base font-semibold text-tinta-900">Ficha técnica con IA</h3>
                        <p class="text-xs text-tinta-400 truncate">{{ datos.nombre || 'Sin nombre todavía' }}</p>
                    </div>
                    <button type="button" @click="abierto = false"
                        class="w-8 h-8 flex items-center justify-center rounded-full text-tinta-300 hover:bg-tinta-100 text-lg">✕</button>
                </div>

                <div class="p-5 overflow-y-auto space-y-4">
                    <p v-if="faltaNombre" class="text-xs px-3 py-2 rounded-xl"
                        style="background:var(--pastel-ambar);color:var(--texto-ambar);">
                        Ponle nombre al producto antes de generar la ficha: es lo primero que la IA
                        necesita para saber de qué está escribiendo.
                    </p>

                    <p class="text-xs text-tinta-400">
                        Una casilla por bloque de la ficha. Llena las que tengas: lo que dejes vacío,
                        la IA lo deduce de las características técnicas — pero solo lo que se pueda
                        deducir, porque no inventa datos. El nombre, la referencia, la categoría y la
                        unidad se toman del formulario.
                        <template v-if="ensambleId">
                            Las medidas y los componentes de la receta también.
                        </template>
                    </p>

                    <!-- Una casilla por bloque -->
                    <div v-for="c in casillas" :key="c.campo">
                        <div class="flex items-baseline justify-between gap-2 mb-1">
                            <label class="block text-sm font-medium text-tinta-700">{{ c.titulo }}</label>
                            <button type="button" @click="usarEjemplo(c)"
                                class="text-xs text-tinta-300 hover:text-[var(--marca)] hover:underline shrink-0">
                                Ver ejemplo
                            </button>
                        </div>
                        <p class="text-xs text-tinta-400 mb-1.5">{{ c.ayuda }}</p>
                        <textarea v-model="aportes[c.campo]" :rows="c.filas"
                            class="w-full border border-linea rounded-xl px-3 py-2 text-sm bg-superficie focus:outline-none focus:border-[var(--marca)]"></textarea>
                    </div>

                    <button type="button" @click="generar" :disabled="cargando || faltaNombre || !hayAlgo"
                        class="w-full py-2.5 rounded-xl text-white text-sm font-semibold disabled:opacity-50"
                        style="background:var(--marca);">
                        {{ cargando ? 'Redactando la ficha…' : 'Generar ficha' }}
                    </button>

                    <p v-if="!hayAlgo" class="text-xs text-tinta-300 text-center">
                        Escribe al menos una casilla. Con solo el nombre, lo único que puede hacer la
                        IA es inventar.
                    </p>

                    <p v-if="error" class="text-xs px-3 py-2 rounded-xl" style="background:#FEF2F2;color:#B91C1C;">
                        {{ error }}
                    </p>

                    <!-- Resultado -->
                    <div v-if="resultado" class="space-y-3 pt-2 border-t border-linea">
                        <p v-if="resultado.aviso" class="text-xs px-3 py-2 rounded-xl"
                            style="background:var(--pastel-ambar);color:var(--texto-ambar);">
                            {{ resultado.aviso }}
                        </p>

                        <div>
                            <p class="text-xs font-semibold text-tinta-400 uppercase tracking-wide mb-1">
                                Descripción corta ({{ (resultado.descripcion_corta || '').length }} caracteres)
                            </p>
                            <p class="text-sm text-tinta-700 p-3 rounded-xl" style="background:var(--superficie-2);">
                                {{ resultado.descripcion_corta || '— vacía —' }}
                            </p>
                        </div>

                        <div>
                            <p class="text-xs font-semibold text-tinta-400 uppercase tracking-wide mb-1">
                                Resumen técnico para cotizaciones ({{ (resultado.descripcion_cotizacion || '').length }} caracteres)
                            </p>
                            <p class="text-sm text-tinta-700 p-3 rounded-xl" style="background:var(--superficie-2);">
                                {{ resultado.descripcion_cotizacion || '— vacío —' }}
                            </p>
                        </div>

                        <div>
                            <p class="text-xs font-semibold text-tinta-400 uppercase tracking-wide mb-1">Descripción larga</p>
                            <div class="tiptap-content text-sm text-tinta-700 p-3 rounded-xl max-h-72 overflow-y-auto"
                                style="background:var(--superficie-2);" v-html="resultado.ficha_html"></div>
                        </div>

                        <div class="flex gap-2">
                            <button type="button" @click="generar" :disabled="cargando"
                                class="px-4 py-2.5 rounded-xl border border-linea text-sm text-tinta-500 hover:bg-tinta-50 disabled:opacity-50">
                                Otra versión
                            </button>
                            <button type="button" @click="usar"
                                class="flex-1 py-2.5 rounded-xl text-white text-sm font-semibold"
                                style="background:var(--marca);">
                                Usar esto
                            </button>
                        </div>
                        <p class="text-xs text-tinta-300 text-center">
                            «Usar esto» reemplaza lo que haya en los tres campos. No guarda el producto.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
