<script setup>
/**
 * Los pasos que va a marcar el operario, dentro de la ficha del ensamble.
 *
 * Existe porque un ensamble se podía guardar sin flujo de producción. La OP nacía con su
 * trabajo vacío: el operario escaneaba el QR y no tenía nada que marcar, el avance se quedaba
 * en cero y la OP quieta en «confirmada» sin que nada explicara por qué. Ahora la ficha los
 * pide al crear, y arranca con un paso ya escrito para que cumplirlo no cueste nada.
 *
 * Muta el arreglo en sitio —el padre pasa el suyo y lo manda tal cual al guardar— y **conserva
 * los campos que no muestra**: objetivo, dificultad, dependencias, imagen y plano se editan en
 * la pantalla de plantillas, y si aquí se perdieran, guardar el precio de un ensamble borraría
 * el plano de sus pasos.
 */
import { computed } from 'vue'

const props = defineProps({
    pasos: { type: Array, required: true },
    // Los pasos de una plantilla los comparten todos los ensambles que la usan. El de un
    // ensamble directo es solo suyo.
    compartidos:  { type: Boolean, default: false },
    nombrePlantilla: { type: String, default: '' },
})

const suma = computed(() =>
    props.pasos.reduce((s, p) => s + (Number(p.peso_porcentaje) || 0), 0)
)

/** El peso reparte el avance de la OP, así que tiene que sumar 100. */
const sumaOk = computed(() => Math.abs(suma.value - 100) < 0.01)

function repartirPesos() {
    if (! props.pasos.length) return

    const parte = Math.floor(100 / props.pasos.length * 100) / 100

    props.pasos.forEach(p => { p.peso_porcentaje = parte })

    // El sobrante de la división se le suma al último: 33,33 tres veces son 99,99.
    const ultimo = props.pasos[props.pasos.length - 1]
    ultimo.peso_porcentaje = parseFloat((parte + (100 - parte * props.pasos.length)).toFixed(2))
}

function agregar() {
    props.pasos.push({
        nombre: '', descripcion: '', peso_porcentaje: 0,
        orden: props.pasos.length, nivel_dificultad: 2, depende_de: [],
        es_paso_final: false, bodega_destino_id: null,
    })

    repartirPesos()
    marcarFinal(props.pasos.length - 1)
}

function quitar(i) {
    props.pasos.splice(i, 1)
    props.pasos.forEach((p, idx) => { p.orden = idx })

    if (props.pasos.length) {
        repartirPesos()

        if (! props.pasos.some(p => p.es_paso_final)) marcarFinal(props.pasos.length - 1)
    }
}

function mover(i, delta) {
    const j = i + delta

    if (j < 0 || j >= props.pasos.length) return

    ;[props.pasos[i], props.pasos[j]] = [props.pasos[j], props.pasos[i]]
    props.pasos.forEach((p, idx) => { p.orden = idx })
}

/**
 * El paso final es uno solo: es el que entrega la unidad a bodega.
 *
 * `EntregaAlmacenService` descuenta los materiales y registra el producto terminado cuando se
 * cierra ese paso. Con dos marcados, la entrega se haría dos veces.
 */
function marcarFinal(i) {
    props.pasos.forEach((p, idx) => { p.es_paso_final = idx === i })
}
</script>

<template>
    <div>
        <div class="flex items-start justify-between gap-3 mb-1 flex-wrap">
            <h2 class="text-sm font-semibold text-tinta-700 uppercase tracking-[0.12em]">
                <slot name="titulo">Cómo se fabrica</slot>
            </h2>
            <button type="button" @click="repartirPesos"
                class="text-xs px-2.5 py-1 rounded-lg border border-linea text-tinta-500 hover:bg-realce transition-colors shrink-0">
                Repartir pesos por igual
            </button>
        </div>

        <p class="text-xs text-tinta-300 mb-3">
            Son los pasos que el operario marca desde su QR, y su peso reparte el avance de la
            orden de producción.
        </p>

        <div v-if="compartidos" class="rounded-xl bg-pastel-ambar border border-borde-aviso-ambar px-3 py-2.5 mb-3">
            <p class="text-xs text-aviso-ambar leading-relaxed">
                Estos pasos son de la plantilla<span v-if="nombrePlantilla"> «{{ nombrePlantilla }}»</span> y los
                comparten <strong>todos los ensambles que la usan</strong>. Lo que cambies aquí les cambia a todos.
            </p>
        </div>

        <div class="space-y-3">
            <div v-for="(paso, i) in pasos" :key="i"
                class="border border-linea rounded-xl p-3">
                <div class="flex items-center gap-2 mb-2">
                    <span class="w-6 h-6 rounded-full bg-tinta-50 text-tinta-500 text-xs font-semibold flex items-center justify-center shrink-0">
                        {{ i + 1 }}
                    </span>
                    <input v-model="paso.nombre" type="text" maxlength="150"
                        placeholder="Nombre del paso — Cortar, Soldar, Pintar…"
                        class="flex-1 min-w-0 border border-linea rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]" />
                    <button type="button" @click="mover(i, -1)" :disabled="i === 0"
                        class="w-7 h-7 rounded-lg border border-linea text-tinta-400 text-xs disabled:opacity-30 hover:bg-realce transition-colors shrink-0">↑</button>
                    <button type="button" @click="mover(i, 1)" :disabled="i === pasos.length - 1"
                        class="w-7 h-7 rounded-lg border border-linea text-tinta-400 text-xs disabled:opacity-30 hover:bg-realce transition-colors shrink-0">↓</button>
                    <button type="button" @click="quitar(i)" :disabled="pasos.length === 1"
                        class="w-7 h-7 rounded-lg border border-borde-aviso-rojo text-aviso-rojo text-xs disabled:opacity-30 hover:bg-pastel-rojo transition-colors shrink-0">×</button>
                </div>

                <textarea v-model="paso.descripcion" rows="2" maxlength="2000"
                    placeholder="Qué hay que hacer en este paso (opcional). El operario lo ve en su pantalla."
                    class="w-full border border-linea rounded-lg px-3 py-2 text-sm mb-2 focus:outline-none focus:border-[var(--marca)]"></textarea>

                <div class="flex items-center gap-3 flex-wrap">
                    <div class="flex items-center gap-2">
                        <label class="text-xs text-tinta-400">Peso</label>
                        <input v-model.number="paso.peso_porcentaje" type="number" min="0" max="100" step="0.01"
                            class="w-20 border border-linea rounded-lg px-2 py-1.5 text-sm text-right focus:outline-none focus:border-[var(--marca)]" />
                        <span class="text-xs text-tinta-300">%</span>
                    </div>
                    <label class="flex items-center gap-1.5 text-xs cursor-pointer"
                        :class="paso.es_paso_final ? 'text-aviso-verde font-semibold' : 'text-tinta-400'">
                        <input type="radio" :checked="paso.es_paso_final" @change="marcarFinal(i)"
                            class="accent-emerald-600" />
                        Paso final — entrega la unidad a bodega
                    </label>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-between gap-3 mt-3 flex-wrap">
            <button type="button" @click="agregar"
                class="text-sm px-3 py-2 rounded-xl border-2 border-dashed border-tinta-200 text-tinta-400 hover:border-[var(--marca)] hover:text-[var(--marca)] transition-colors">
                + Agregar paso
            </button>
            <span class="text-xs" :class="sumaOk ? 'text-tinta-300' : 'text-aviso-ambar'">
                Suma de pesos: {{ Math.round(suma * 100) / 100 }}%<span v-if="! sumaOk"> — debería ser 100</span>
            </span>
        </div>
    </div>
</template>
