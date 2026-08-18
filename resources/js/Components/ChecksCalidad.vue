<script setup>
/**
 * Qué revisa calidad de cada unidad, dentro de la ficha del ensamble.
 *
 * Antes calidad era una decisión de una sola pieza sobre la orden entera: aprobada o a
 * reproceso, con una foto y un comentario. En una orden de diez puertas eso no dice nada —no
 * queda registro de qué se revisó, ni de cuál unidad salió mal, ni de qué le faltaba—, y la
 * discusión con el cliente termina siendo la palabra de uno contra la del otro.
 *
 * Esta lista se copia a **cada unidad** al generar su trabajo, y ahí se llena.
 *
 * Muta el arreglo en sitio: el padre pasa el suyo y lo manda tal cual al guardar.
 */
const props = defineProps({
    checks: { type: Array, required: true },
    // Con plantilla, la lista es de la plantilla y la comparten todos sus ensambles.
    compartidos:     { type: Boolean, default: false },
    nombrePlantilla: { type: String, default: '' },
})

function agregar() {
    props.checks.push({
        titulo: '', descripcion: '', orden: props.checks.length,
        exige_foto: false, es_critico: true,
    })
}

function quitar(i) {
    props.checks.splice(i, 1)
    props.checks.forEach((c, idx) => { c.orden = idx })
}

function mover(i, delta) {
    const j = i + delta

    if (j < 0 || j >= props.checks.length) return

    ;[props.checks[i], props.checks[j]] = [props.checks[j], props.checks[i]]
    props.checks.forEach((c, idx) => { c.orden = idx })
}
</script>

<template>
    <div>
        <h2 class="text-sm font-semibold text-tinta-700 uppercase tracking-[0.12em] mb-1">
            <slot name="titulo">Revisión de calidad</slot>
        </h2>

        <p class="text-xs text-tinta-300 mb-3">
            Lo que hay que mirar en cada unidad antes de darla por buena — si trae la llave, si
            le falta un empaque. Quien revisa lo marca desde la hoja de producción, con foto.
        </p>

        <div v-if="compartidos" class="rounded-xl bg-pastel-ambar border border-borde-aviso-ambar px-3 py-2.5 mb-3">
            <p class="text-xs text-aviso-ambar leading-relaxed">
                Esta lista es de la plantilla<span v-if="nombrePlantilla"> «{{ nombrePlantilla }}»</span> y la
                comparten <strong>todos los ensambles que la usan</strong>.
            </p>
        </div>

        <div class="space-y-3">
            <div v-for="(check, i) in checks" :key="i" class="border border-linea rounded-xl p-3">
                <div class="flex items-center gap-2 mb-2">
                    <span class="w-6 h-6 rounded-full bg-tinta-50 text-tinta-500 text-xs font-semibold flex items-center justify-center shrink-0">
                        {{ i + 1 }}
                    </span>
                    <input v-model="check.titulo" type="text" maxlength="150"
                        placeholder="Qué se revisa — «Tiene llave», «Empaque completo»…"
                        class="flex-1 min-w-0 border border-linea rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]" />
                    <button type="button" @click="mover(i, -1)" :disabled="i === 0"
                        class="w-7 h-7 rounded-lg border border-linea text-tinta-400 text-xs disabled:opacity-30 hover:bg-realce transition-colors shrink-0">↑</button>
                    <button type="button" @click="mover(i, 1)" :disabled="i === checks.length - 1"
                        class="w-7 h-7 rounded-lg border border-linea text-tinta-400 text-xs disabled:opacity-30 hover:bg-realce transition-colors shrink-0">↓</button>
                    <button type="button" @click="quitar(i)"
                        class="w-7 h-7 rounded-lg border border-borde-aviso-rojo text-aviso-rojo text-xs hover:bg-pastel-rojo transition-colors shrink-0">×</button>
                </div>

                <textarea v-model="check.descripcion" rows="2" maxlength="2000"
                    placeholder="Cómo se revisa, si hace falta explicarlo (opcional)."
                    class="w-full border border-linea rounded-lg px-3 py-2 text-sm mb-2 focus:outline-none focus:border-[var(--marca)]"></textarea>

                <div class="flex items-center gap-4 flex-wrap">
                    <label class="flex items-center gap-1.5 text-xs cursor-pointer text-tinta-500">
                        <input type="checkbox" v-model="check.exige_foto" class="accent-blue-600" />
                        Exige foto
                    </label>
                    <label class="flex items-center gap-1.5 text-xs cursor-pointer"
                        :class="check.es_critico ? 'text-aviso-rojo font-medium' : 'text-tinta-500'">
                        <input type="checkbox" v-model="check.es_critico" class="accent-red-600" />
                        Crítico — si falla, la unidad no pasa
                    </label>
                </div>
            </div>
        </div>

        <button type="button" @click="agregar"
            class="mt-3 text-sm px-3 py-2 rounded-xl border-2 border-dashed border-tinta-200 text-tinta-400 hover:border-[var(--marca)] hover:text-[var(--marca)] transition-colors">
            + Agregar punto de revisión
        </button>

        <p v-if="! checks.length" class="text-xs text-tinta-300 mt-2">
            Sin puntos, calidad sigue aprobando la orden como hasta ahora: de una sola pieza y
            sin registro de qué se miró.
        </p>
    </div>
</template>
