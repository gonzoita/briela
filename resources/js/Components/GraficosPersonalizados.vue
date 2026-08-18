<script setup>
/**
 * Los gráficos que la empresa arma para su tablero.
 *
 * Se guarda la **pregunta** —qué fuente, qué se mide, cómo se agrupa, con qué filtros— y los
 * números se calculan al abrir la pantalla. Un gráfico con datos congelados envejece sin avisar,
 * y nadie lo nota hasta que toma una decisión con él.
 *
 * Se dibuja con SVG a mano, como el resto de los gráficos del sistema: no hace falta traer una
 * librería para cuatro formas, y así los colores salen de las variables del tema y funcionan
 * igual de día que de noche.
 */
import { ref, computed, onMounted } from 'vue'
import { router } from '@inertiajs/vue3'
import { formatCOP } from '@/formato'

const props = defineProps({
    // A qué tablero pertenece: cotizaciones, ops, comisiones, alistamiento.
    modulo:        { type: String, required: true },
    puedeGestionar:{ type: Boolean, default: false },
})

const cargando = ref(true)
const graficos = ref([])
const fuentes  = ref([])
const abierto  = ref(false)
const error    = ref('')

const nuevo = ref({ titulo: '', fuente: '', tipo: 'barra', medida: '', dimension: '', filtros: { desde: '', hasta: '' } })

const fuenteElegida = computed(() => fuentes.value.find(f => f.clave === nuevo.value.fuente) ?? null)

async function cargar() {
    cargando.value = true

    try {
        const res  = await fetch(`/api/graficos?modulo=${encodeURIComponent(props.modulo)}`, {
            headers: { Accept: 'application/json' }, credentials: 'same-origin',
        })
        const data = await res.json()
        graficos.value = data.graficos ?? []
        fuentes.value  = data.fuentes ?? []

        if (fuentes.value.length && ! nuevo.value.fuente) elegirFuente(fuentes.value[0].clave)
    } catch {
        error.value = 'No se pudieron cargar los gráficos.'
    } finally {
        cargando.value = false
    }
}

function elegirFuente(clave) {
    nuevo.value.fuente    = clave
    const f               = fuentes.value.find(x => x.clave === clave)
    nuevo.value.medida    = f?.medidas?.[0]?.clave ?? ''
    nuevo.value.dimension = f?.dimensiones?.[0]?.clave ?? ''
}

function guardar() {
    router.post('/api/graficos', { ...nuevo.value, modulo: props.modulo }, {
        preserveScroll: true,
        onSuccess: () => { abierto.value = false; nuevo.value.titulo = ''; cargar() },
        onError: (e) => { error.value = Object.values(e)[0] ?? 'No se pudo guardar.' },
    })
}

function borrar(g) {
    router.delete(`/api/graficos/${g.id}`, { preserveScroll: true, onSuccess: cargar })
}

const fmt = (v, dinero) => dinero ? '$' + formatCOP(v) : new Intl.NumberFormat('es-CO').format(Math.round(v * 100) / 100)

/** La barra más larga manda: todo se mide contra ella. */
const maximo = (g) => Math.max(...g.datos.map(d => Math.abs(d.valor)), 1)

/** La línea, como una polilínea sobre un lienzo de 100×40 para que escale sola. */
function puntos(g) {
    const n = g.datos.length

    if (n < 2) return ''

    const max = maximo(g)

    return g.datos.map((d, i) => `${(i / (n - 1)) * 100},${40 - (Math.abs(d.valor) / max) * 36}`).join(' ')
}

/** La dona: un arco por porción, en trazo, que es la forma más corta de dibujarla. */
function arcos(g) {
    const total = g.datos.reduce((s, d) => s + Math.abs(d.valor), 0) || 1
    let acumulado = 0

    return g.datos.slice(0, 8).map((d, i) => {
        const porcion = Math.abs(d.valor) / total
        const arco    = { etiqueta: d.etiqueta, valor: d.valor, dash: porcion * 100, offset: -acumulado * 100, color: paleta[i % paleta.length] }
        acumulado += porcion

        return arco
    })
}

// Ocho tonos que se distinguen en los dos temas. No son los grises fijos de Tailwind: estos
// son de la paleta de avisos, que ya está pensada para modo día y modo noche.
const paleta = ['var(--marca)', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#0EA5E9', '#F97316', '#14B8A6']

onMounted(cargar)
</script>

<template>
    <div class="bg-superficie rounded-2xl shadow-sm overflow-hidden mb-4">
        <div class="px-5 py-3 border-b border-linea flex items-center justify-between gap-2 flex-wrap">
            <h3 class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Gráficos del tablero</h3>
            <button v-if="puedeGestionar" type="button" @click="abierto = ! abierto"
                class="text-xs text-[var(--marca)] border border-[var(--marca)] rounded-lg px-3 py-1.5 hover:bg-realce transition-colors">
                {{ abierto ? 'Cancelar' : '+ Nuevo gráfico' }}
            </button>
        </div>

        <div class="p-5">
            <p v-if="error" class="text-xs text-aviso-rojo mb-3">{{ error }}</p>

            <!-- El armador -->
            <div v-if="abierto" class="border border-linea rounded-xl p-4 mb-4 space-y-3">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    <div>
                        <label class="block text-xs text-tinta-400 mb-1">Título</label>
                        <input v-model="nuevo.titulo" type="text" maxlength="120" placeholder="Ventas por mes"
                            class="w-full border border-linea rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]" />
                    </div>
                    <div>
                        <label class="block text-xs text-tinta-400 mb-1">Fuente</label>
                        <select :value="nuevo.fuente" @change="elegirFuente($event.target.value)"
                            class="w-full border border-linea rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]">
                            <option v-for="f in fuentes" :key="f.clave" :value="f.clave">{{ f.label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-tinta-400 mb-1">Qué se mide</label>
                        <select v-model="nuevo.medida"
                            class="w-full border border-linea rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]">
                            <option v-for="m in fuenteElegida?.medidas ?? []" :key="m.clave" :value="m.clave">{{ m.label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-tinta-400 mb-1">Agrupado por</label>
                        <select v-model="nuevo.dimension"
                            class="w-full border border-linea rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]">
                            <option value="">Sin agrupar — un solo número</option>
                            <option v-for="d in fuenteElegida?.dimensiones ?? []" :key="d.clave" :value="d.clave">{{ d.label }}</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs text-tinta-400 mb-1">Forma</label>
                        <select v-model="nuevo.tipo"
                            class="w-full border border-linea rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]">
                            <option value="barra">Barras</option>
                            <option value="linea">Línea</option>
                            <option value="dona">Dona</option>
                            <option value="numero">Un número</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-tinta-400 mb-1">Desde</label>
                        <input v-model="nuevo.filtros.desde" type="date"
                            class="w-full border border-linea rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]" />
                    </div>
                    <div>
                        <label class="block text-xs text-tinta-400 mb-1">Hasta</label>
                        <input v-model="nuevo.filtros.hasta" type="date"
                            class="w-full border border-linea rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]" />
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <button type="button" @click="guardar" :disabled="! nuevo.titulo || ! nuevo.fuente"
                        class="text-sm px-4 py-2 rounded-xl text-white disabled:opacity-50" style="background:var(--marca);">
                        Agregar al tablero
                    </button>
                    <span class="text-xs text-tinta-300">Lo que agregues lo ven todos.</span>
                </div>
            </div>

            <p v-if="cargando" class="text-sm text-tinta-300">Calculando…</p>

            <p v-else-if="! graficos.length" class="text-sm text-tinta-300">
                Todavía no hay gráficos en este tablero.<span v-if="puedeGestionar"> Arma el primero con «Nuevo gráfico».</span>
            </p>

            <div v-else class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div v-for="g in graficos" :key="g.id" class="border border-linea rounded-xl p-4">
                    <div class="flex items-start justify-between gap-2 mb-3">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-tinta-800 truncate">{{ g.titulo }}</p>
                            <p class="text-xs text-tinta-300">Total: {{ fmt(g.total, g.dinero) }}</p>
                        </div>
                        <button v-if="puedeGestionar" type="button" @click="borrar(g)"
                            class="text-xs text-tinta-300 hover:text-aviso-rojo shrink-0">Quitar</button>
                    </div>

                    <p v-if="g.aviso" class="text-xs text-aviso-ambar">{{ g.aviso }}</p>
                    <p v-else-if="! g.datos.length" class="text-xs text-tinta-300">Sin datos en ese rango.</p>

                    <!-- Un número -->
                    <p v-else-if="g.tipo === 'numero'" class="text-3xl font-semibold" style="color:var(--marca);">
                        {{ fmt(g.datos[0].valor, g.dinero) }}
                    </p>

                    <!-- Barras: horizontales, que es como se leen las etiquetas largas -->
                    <div v-else-if="g.tipo === 'barra'" class="space-y-1.5">
                        <div v-for="(d, i) in g.datos.slice(0, 12)" :key="i" class="flex items-center gap-2">
                            <span class="text-xs text-tinta-500 w-28 shrink-0 truncate" :title="d.etiqueta">{{ d.etiqueta }}</span>
                            <div class="flex-1 h-3 rounded-full bg-tinta-50 overflow-hidden">
                                <div class="h-full rounded-full transition-all"
                                    :style="`width:${(Math.abs(d.valor) / maximo(g)) * 100}%; background:${paleta[i % paleta.length]};`"></div>
                            </div>
                            <span class="text-xs text-tinta-600 w-24 text-right shrink-0">{{ fmt(d.valor, g.dinero) }}</span>
                        </div>
                    </div>

                    <!-- Línea -->
                    <div v-else-if="g.tipo === 'linea'">
                        <svg viewBox="0 0 100 40" preserveAspectRatio="none" class="w-full h-24">
                            <polyline :points="puntos(g)" fill="none" stroke="var(--marca)" stroke-width="1.5"
                                vector-effect="non-scaling-stroke" stroke-linejoin="round" />
                        </svg>
                        <div class="flex justify-between text-xs text-tinta-300 mt-1">
                            <span>{{ g.datos[0]?.etiqueta }}</span>
                            <span>{{ g.datos[g.datos.length - 1]?.etiqueta }}</span>
                        </div>
                    </div>

                    <!-- Dona -->
                    <div v-else-if="g.tipo === 'dona'" class="flex items-center gap-4 flex-wrap">
                        <svg viewBox="0 0 42 42" class="w-28 h-28 shrink-0 -rotate-90">
                            <circle cx="21" cy="21" r="15.9" fill="none" stroke="var(--tinta-100, #eee)" stroke-width="6" />
                            <circle v-for="(a, i) in arcos(g)" :key="i"
                                cx="21" cy="21" r="15.9" fill="none" :stroke="a.color" stroke-width="6"
                                :stroke-dasharray="`${a.dash} ${100 - a.dash}`" :stroke-dashoffset="a.offset" />
                        </svg>
                        <div class="space-y-1 min-w-0">
                            <div v-for="(a, i) in arcos(g)" :key="i" class="flex items-center gap-2 text-xs">
                                <span class="w-2.5 h-2.5 rounded-full shrink-0" :style="`background:${a.color};`"></span>
                                <span class="text-tinta-500 truncate">{{ a.etiqueta }}</span>
                                <span class="text-tinta-700 ml-auto">{{ fmt(a.valor, g.dinero) }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
