<script setup>
/**
 * Lo que la tienda en línea muestra de un producto además del nombre: marca, origen,
 * características especiales y si el precio se publica.
 *
 * Es el mismo bloque al crear, al editar y al editar un producto con variantes, que las pasan a
 * todas sus variantes. Escribe directo en el formulario que recibe; las características son pares
 * «nombre: valor» («Material: acero inoxidable»), que en WooCommerce salen como atributos.
 */
const props = defineProps({
    form: { type: Object, required: true },
})

function agregarCaracteristica() {
    props.form.caracteristicas.push({ nombre: '', valor: '' })
}

function quitarCaracteristica(i) {
    props.form.caracteristicas.splice(i, 1)
}

const campo = 'w-full border border-linea rounded-xl px-3 py-2 text-sm bg-superficie focus:outline-none focus:border-[var(--marca)]'
</script>

<template>
    <div class="space-y-3 border-t border-linea pt-4">
        <div>
            <h3 class="text-sm font-semibold text-tinta-900">Para la tienda en línea</h3>
            <p class="text-xs text-tinta-400 mt-0.5">Lo que se muestra en el sitio web y en el catálogo, además del nombre.</p>
        </div>

        <div class="grid sm:grid-cols-2 gap-3">
            <div>
                <label for="producto-marca" class="block text-sm font-medium text-tinta-700 mb-1">Marca</label>
                <input id="producto-marca" v-model="form.marca" type="text" maxlength="120" placeholder="Ej. Acme" :class="campo" />
                <p v-if="form.errors?.marca" class="mt-1 text-xs text-aviso-rojo">{{ form.errors.marca }}</p>
            </div>
            <div>
                <label for="producto-origen" class="block text-sm font-medium text-tinta-700 mb-1">Origen</label>
                <input id="producto-origen" v-model="form.origen" type="text" maxlength="120" placeholder="Ej. Italia" :class="campo" />
                <p v-if="form.errors?.origen" class="mt-1 text-xs text-aviso-rojo">{{ form.errors.origen }}</p>
            </div>
        </div>

        <div>
            <p class="block text-sm font-medium text-tinta-700 mb-1">Características especiales</p>
            <div v-for="(c, i) in form.caracteristicas" :key="i" class="flex items-start gap-2 mb-2">
                <input v-model="c.nombre" type="text" maxlength="60" placeholder="Ej. Material" aria-label="Característica"
                    class="w-2/5 min-w-0 border border-linea rounded-xl px-3 py-2 text-sm bg-superficie focus:outline-none focus:border-[var(--marca)]" />
                <input v-model="c.valor" type="text" maxlength="200" placeholder="Ej. Acero inoxidable" aria-label="Valor"
                    class="flex-1 min-w-0 border border-linea rounded-xl px-3 py-2 text-sm bg-superficie focus:outline-none focus:border-[var(--marca)]" />
                <button type="button" @click="quitarCaracteristica(i)" aria-label="Quitar característica"
                    class="shrink-0 w-9 h-9 rounded-xl text-tinta-400 hover:text-aviso-rojo hover:bg-realce transition-colors">×</button>
            </div>
            <button type="button" @click="agregarCaracteristica" :disabled="form.caracteristicas.length >= 30"
                class="text-xs font-semibold disabled:opacity-40" style="color:var(--marca);">
                + Agregar característica
            </button>
        </div>

        <label class="flex items-start gap-2 cursor-pointer">
            <input v-model="form.publicar_precio" type="checkbox" class="mt-0.5 w-4 h-4 rounded border-tinta-300" />
            <span>
                <span class="block text-sm font-medium text-tinta-700">Publicar el precio en la tienda</span>
                <span class="block text-xs text-tinta-400">Apagado, la tienda muestra «Cotizar». El catálogo para descargar sale siempre sin precios.</span>
            </span>
        </label>
    </div>
</template>
