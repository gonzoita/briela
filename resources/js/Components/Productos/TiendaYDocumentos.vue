<script setup>
/**
 * En la ficha del producto: lo que la tienda en línea muestra además del nombre —marca, origen,
 * características, si el precio se publica— y los planos y fichas técnicas.
 *
 * Subir un documento es un formulario aparte del de editar el producto: se adjunta un plano sin
 * tener que abrir y guardar el producto entero.
 */
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'

const props = defineProps({
    producto:    { type: Object, required: true },
    puedeEditar: { type: Boolean, default: false },
})

const ETIQUETA = { plano: 'Plano', ficha_tecnica: 'Ficha técnica' }

const documentos = computed(() => props.producto.documentos ?? [])
const caracteristicas = computed(() => props.producto.caracteristicas ?? [])
const hayDatos = computed(() => props.producto.marca || props.producto.origen || caracteristicas.value.length)

const categoria = ref('ficha_tecnica')
const archivo   = ref(null)
const entrada   = ref(null)
const subiendo  = ref(false)
const error     = ref('')

function elegir(evento) {
    archivo.value = evento.target.files?.[0] ?? null
    error.value = ''
}

function subir() {
    if (! archivo.value) return
    subiendo.value = true
    router.post(`/productos/${props.producto.id}/documentos`, { archivo: archivo.value, categoria: categoria.value }, {
        forceFormData: true,
        preserveScroll: true,
        onError: (e) => { error.value = e.archivo ?? e.categoria ?? 'No se pudo subir.' },
        onSuccess: () => { archivo.value = null; if (entrada.value) entrada.value.value = '' },
        onFinish: () => { subiendo.value = false },
    })
}

function borrar(doc) {
    if (! confirm(`¿Quitar «${doc.nombre}» de este producto?`)) return
    router.delete(`/productos/documentos/${doc.id}`, { preserveScroll: true })
}
</script>

<template>
    <div class="mt-5 bg-superficie rounded-2xl shadow-sm overflow-hidden">
        <div class="px-5 py-3 border-b border-linea flex items-center justify-between gap-2">
            <h3 class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Tienda en línea y documentos</h3>
            <span :class="['text-[11px] font-semibold px-2 py-0.5 rounded-full',
                producto.publicar_precio === false ? 'bg-pastel-ambar-2 text-aviso-ambar' : 'bg-pastel-verde-2 text-aviso-verde']">
                {{ producto.publicar_precio === false ? 'Precio oculto en la tienda' : 'Precio visible en la tienda' }}
            </span>
        </div>

        <div class="p-5 grid gap-5 md:grid-cols-2">
            <!-- Lo que muestra la tienda -->
            <div>
                <dl v-if="hayDatos" class="text-sm space-y-1.5">
                    <div v-if="producto.marca" class="flex gap-2"><dt class="text-tinta-400 w-28 shrink-0">Marca</dt><dd class="text-tinta-900 font-medium">{{ producto.marca }}</dd></div>
                    <div v-if="producto.origen" class="flex gap-2"><dt class="text-tinta-400 w-28 shrink-0">Origen</dt><dd class="text-tinta-900 font-medium">{{ producto.origen }}</dd></div>
                    <div v-for="(c, i) in caracteristicas" :key="i" class="flex gap-2">
                        <dt class="text-tinta-400 w-28 shrink-0 truncate">{{ c.nombre }}</dt><dd class="text-tinta-900">{{ c.valor }}</dd>
                    </div>
                </dl>
                <p v-else class="text-sm text-tinta-400">
                    Sin marca, origen ni características.
                    <a v-if="puedeEditar" :href="`/productos/${producto.id}/editar`" class="underline underline-offset-2" style="color:var(--marca);">Agregarlos</a>
                </p>
            </div>

            <!-- Planos y fichas técnicas -->
            <div>
                <p class="text-sm font-semibold text-tinta-700 mb-2">Planos y fichas técnicas</p>
                <ul v-if="documentos.length" class="divide-y divide-separador border border-linea rounded-xl mb-3">
                    <li v-for="doc in documentos" :key="doc.id" class="flex items-center gap-2 px-3 py-2">
                        <span class="text-[10px] font-semibold uppercase px-1.5 py-0.5 rounded bg-pastel-azul-2 text-aviso-azul shrink-0">{{ doc.extension }}</span>
                        <a :href="doc.url" target="_blank" rel="noopener" class="flex-1 min-w-0 text-sm text-tinta-900 truncate hover:underline">{{ doc.nombre }}</a>
                        <span class="text-[11px] text-tinta-400 shrink-0 hidden sm:inline">{{ ETIQUETA[doc.categoria] }} · {{ doc.tamano }}</span>
                        <button v-if="puedeEditar" type="button" @click="borrar(doc)" aria-label="Quitar documento"
                            class="shrink-0 w-7 h-7 rounded-lg text-tinta-400 hover:text-aviso-rojo hover:bg-realce transition-colors">×</button>
                    </li>
                </ul>
                <p v-else class="text-sm text-tinta-400 mb-3">Todavía no tiene planos ni fichas.</p>

                <div v-if="puedeEditar" class="flex flex-col sm:flex-row gap-2">
                    <select v-model="categoria" aria-label="Tipo de documento"
                        class="border border-linea rounded-xl px-3 py-2 text-sm bg-superficie focus:outline-none focus:border-[var(--marca)]">
                        <option value="ficha_tecnica">Ficha técnica</option>
                        <option value="plano">Plano</option>
                    </select>
                    <input ref="entrada" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.dwg,.dxf" @change="elegir"
                        class="flex-1 min-w-0 text-sm text-tinta-600 file:mr-2 file:px-3 file:py-1.5 file:rounded-lg file:border-0 file:bg-realce file:text-tinta-700" />
                    <button type="button" @click="subir" :disabled="! archivo || subiendo"
                        class="px-4 py-2 rounded-xl text-sm font-semibold text-white disabled:opacity-50" style="background:var(--marca);">
                        {{ subiendo ? 'Subiendo…' : 'Subir' }}
                    </button>
                </div>
                <p v-if="error" class="mt-1 text-xs text-aviso-rojo">{{ error }}</p>
                <p v-if="puedeEditar" class="mt-1 text-[11px] text-tinta-400">PDF, imagen o plano de CAD (DWG, DXF), hasta 20 MB.</p>
            </div>
        </div>
    </div>
</template>
