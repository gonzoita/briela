<script setup>
/**
 * Selector de unidad de medida, con su propia administración.
 *
 * La lista estaba escrita en el código de las pantallas de producto —dos arreglos, uno en
 * crear y otro en editar— así que un fabricante que mida en rollos o en pulgadas tenía que
 * pedir un cambio de código. Ahora vive en la base y se administra desde el mismo «+» que
 * ya tienen las categorías: nadie debería salir del producto que está creando.
 *
 * La lista se pide al montar y **el valor que ya traiga el ítem se conserva como opción**
 * aunque no esté en la lista: un producto viejo con una unidad que después se borró no
 * debe quedar en blanco al abrirlo.
 */
import { ref, computed, onMounted } from 'vue'
import { usePage } from '@inertiajs/vue3'

const props = defineProps({
    modelValue: { type: String, default: 'unidad' },
    // 'producto' | 'servicio' — las de tipo «ambos» salen en las dos listas.
    tipo:       { type: String, default: 'producto' },
    clase:      { type: [String, Array], default: '' },
})

const emit = defineEmits(['update:modelValue'])

const page = usePage()
const puedeAdministrar = computed(() =>
    (page.props.auth?.permisosLista ?? []).includes('configuracion.editar')
)

const unidades = ref([])
const abierto   = ref(false)
const cargando  = ref(false)
const error     = ref('')

const nueva = ref({ etiqueta: '', clave: '', tipo: 'producto' })
const editando = ref({})   // { [id]: etiqueta }

const csrf = () => {
    const c = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))
    return c ? decodeURIComponent(c.split('=')[1]) : ''
}

async function pedir(url, opciones = {}) {
    const res = await fetch(url, {
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': csrf(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        ...opciones,
    })

    const data = await res.json().catch(() => null)

    if (! res.ok) throw new Error(data?.message || `No se pudo (${res.status}).`)

    return data
}

async function cargar() {
    try {
        unidades.value = await pedir('/api/unidades-medida')
    } catch (e) {
        error.value = e.message
    }
}

onMounted(cargar)

/** Las que sirven para este tipo de ítem, más la que el ítem ya tenga guardada. */
const opciones = computed(() => {
    const lista = unidades.value
        .filter(u => u.activo && (u.tipo === props.tipo || u.tipo === 'ambos'))
        .map(u => ({ clave: u.clave, etiqueta: u.etiqueta }))

    if (props.modelValue && ! lista.some(o => o.clave === props.modelValue)) {
        lista.unshift({ clave: props.modelValue, etiqueta: props.modelValue })
    }

    return lista
})

const delTipo = computed(() =>
    unidades.value.filter(u => u.tipo === props.tipo || u.tipo === 'ambos')
)

async function crear() {
    if (! nueva.value.etiqueta.trim()) return

    cargando.value = true
    error.value = ''
    try {
        const creada = await pedir('/api/unidades-medida', {
            method: 'POST',
            body: JSON.stringify({ ...nueva.value, tipo: nueva.value.tipo || props.tipo }),
        })
        unidades.value.push({ ...creada, productos_count: 0 })
        emit('update:modelValue', creada.clave)
        nueva.value = { etiqueta: '', clave: '', tipo: props.tipo }
    } catch (e) {
        error.value = e.message
    } finally {
        cargando.value = false
    }
}

async function renombrar(unidad) {
    const etiqueta = (editando.value[unidad.id] ?? '').trim()

    if (! etiqueta || etiqueta === unidad.etiqueta) {
        delete editando.value[unidad.id]
        return
    }

    try {
        const actualizada = await pedir(`/api/unidades-medida/${unidad.id}`, {
            method: 'PUT',
            body: JSON.stringify({ etiqueta }),
        })
        Object.assign(unidad, actualizada)
        delete editando.value[unidad.id]
    } catch (e) {
        error.value = e.message
    }
}

async function eliminar(unidad) {
    const usan = unidad.productos_count ?? 0
    const aviso = usan > 0
        ? `«${unidad.etiqueta}» la usan ${usan} producto(s). Ellos la conservan; solo deja de ofrecerse para los nuevos. ¿Eliminarla?`
        : `¿Eliminar «${unidad.etiqueta}»?`

    if (! confirm(aviso)) return

    try {
        await pedir(`/api/unidades-medida/${unidad.id}`, { method: 'DELETE' })
        unidades.value = unidades.value.filter(u => u.id !== unidad.id)
    } catch (e) {
        error.value = e.message
    }
}

/** Mover con flechas: el orden de la lista es el orden del selector. */
async function mover(unidad, delta) {
    const lista = [...unidades.value].sort((a, b) => a.orden - b.orden)
    const i = lista.findIndex(u => u.id === unidad.id)
    const j = i + delta

    if (i < 0 || j < 0 || j >= lista.length) return

    ;[lista[i], lista[j]] = [lista[j], lista[i]]
    lista.forEach((u, idx) => { u.orden = idx + 1 })
    unidades.value = lista

    try {
        await pedir('/api/unidades-medida/reordenar', {
            method: 'POST',
            body: JSON.stringify({ items: lista.map(u => ({ id: u.id, orden: u.orden })) }),
        })
    } catch (e) {
        error.value = e.message
    }
}

const etiquetaTipo = { producto: 'Producto', servicio: 'Servicio', ambos: 'Ambos' }
</script>

<template>
    <div class="flex gap-2">
        <select
            :value="modelValue"
            @change="emit('update:modelValue', $event.target.value)"
            :class="clase"
            class="flex-1"
        >
            <option v-for="o in opciones" :key="o.clave" :value="o.clave">{{ o.etiqueta }}</option>
        </select>
        <button v-if="puedeAdministrar" type="button" @click="abierto = true"
            class="px-3 py-2 rounded-xl border border-linea text-tinta-400 hover:bg-tinta-50 text-sm shrink-0"
            title="Administrar unidades de medida">+</button>
    </div>

    <Teleport to="body">
        <div v-if="abierto" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
            <div class="absolute inset-0 bg-black/40" @click="abierto = false" />

            <div class="relative w-full sm:max-w-lg bg-superficie rounded-t-3xl sm:rounded-2xl shadow-2xl flex flex-col max-h-[90vh]">
                <div class="flex items-center justify-between px-5 py-4 border-b border-linea shrink-0">
                    <h3 class="text-base font-semibold text-tinta-900">Unidades de medida</h3>
                    <button type="button" @click="abierto = false"
                        class="w-8 h-8 flex items-center justify-center rounded-full text-tinta-300 hover:bg-tinta-100 text-lg">✕</button>
                </div>

                <div class="p-5 overflow-y-auto space-y-4">
                    <p v-if="error" class="text-xs px-3 py-2 rounded-xl" style="background:var(--pastel-ambar);color:var(--texto-ambar);">
                        {{ error }}
                    </p>

                    <!-- Nueva -->
                    <div class="space-y-2">
                        <div class="flex gap-2">
                            <input v-model="nueva.etiqueta" placeholder="Etiqueta (ej. Rollos de 50 m)"
                                @keyup.enter="crear"
                                class="flex-1 border border-linea rounded-xl px-3 py-2 text-sm bg-superficie focus:outline-none focus:border-[var(--marca)]" />
                            <select v-model="nueva.tipo"
                                class="border border-linea rounded-xl px-2 py-2 text-sm bg-superficie">
                                <option value="producto">Producto</option>
                                <option value="servicio">Servicio</option>
                                <option value="ambos">Ambos</option>
                            </select>
                        </div>
                        <div class="flex gap-2">
                            <input v-model="nueva.clave" placeholder="Clave corta (opcional: rollo)"
                                class="flex-1 border border-linea rounded-xl px-3 py-2 text-sm bg-superficie font-mono focus:outline-none focus:border-[var(--marca)]" />
                            <button type="button" @click="crear" :disabled="cargando || !nueva.etiqueta.trim()"
                                class="px-4 py-2 rounded-xl text-white text-sm font-medium disabled:opacity-50"
                                style="background:var(--marca);">Agregar</button>
                        </div>
                        <p class="text-xs text-tinta-300">
                            La clave es lo que se guarda en el producto y lo que se lee junto a una
                            cantidad («3 rollo»). Si la dejas vacía se arma sola con la etiqueta, y
                            <strong>no se puede cambiar después</strong>: los productos la guardan.
                        </p>
                    </div>

                    <!-- Lista -->
                    <ul class="divide-y divide-separador border-t border-linea">
                        <li v-for="(u, i) in [...unidades].sort((a,b) => a.orden - b.orden)" :key="u.id"
                            class="flex items-center gap-2 py-2.5">
                            <div class="flex flex-col gap-0.5 shrink-0">
                                <button type="button" @click="mover(u, -1)" :disabled="i === 0"
                                    class="w-5 h-4 flex items-center justify-center text-tinta-300 hover:text-tinta-900 disabled:opacity-20 text-[10px]">▲</button>
                                <button type="button" @click="mover(u, 1)" :disabled="i === unidades.length - 1"
                                    class="w-5 h-4 flex items-center justify-center text-tinta-300 hover:text-tinta-900 disabled:opacity-20 text-[10px]">▼</button>
                            </div>

                            <div class="flex-1 min-w-0">
                                <input
                                    v-if="editando[u.id] !== undefined"
                                    v-model="editando[u.id]"
                                    @keyup.enter="renombrar(u)"
                                    @blur="renombrar(u)"
                                    class="w-full border border-linea rounded-lg px-2 py-1 text-sm bg-superficie"
                                />
                                <p v-else class="text-sm text-tinta-900 truncate">{{ u.etiqueta }}</p>
                                <p class="text-xs text-tinta-300 font-mono">
                                    {{ u.clave }} · {{ etiquetaTipo[u.tipo] }}
                                    <span v-if="u.productos_count"> · {{ u.productos_count }} producto(s)</span>
                                </p>
                            </div>

                            <button type="button" @click="editando[u.id] = u.etiqueta"
                                class="text-xs text-tinta-400 hover:text-tinta-900 underline shrink-0">Editar</button>
                            <button type="button" @click="eliminar(u)"
                                class="text-xs text-red-500 hover:text-red-700 underline shrink-0">Eliminar</button>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </Teleport>
</template>
