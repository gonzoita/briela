<script setup>
import { computed, ref, watch } from 'vue'
import { router, useForm, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import OrdenarLista from '@/Components/OrdenarLista.vue'
import { useOrden } from '@/composables/useOrden'
import { formatCOP } from '@/formato'

// Confirma con el banco que el dinero llegó. Solo lee lo que entra a las cuentas de la empresa:
// no mueve plata. Ver `VerificadorPagosService`.
const props = defineProps({
    movimientos: { type: Object, required: true },
    orden:       { type: Object, default: () => ({}) },
    filtros:     { type: Object, default: () => ({}) },
    resumen:     { type: Object, required: true },
    cuentas:     { type: Array,  default: () => [] },
})

const page = usePage()
const permisos = computed(() => page.props.auth?.permisosLista ?? [])
const puedeEditar  = computed(() => permisos.value.includes('verificador.editar'))
const puedeDeshacer = computed(() => permisos.value.includes('cartera.eliminar'))

const URL = '/financiero/verificador-pagos'

// El orden viaja en la URL y conserva los filtros.
const { ordenarPor } = useOrden(URL, props.orden, props.filtros)
const camposOrden = [
    { campo: 'fecha',  etiqueta: 'Fecha',  texto: false },
    { campo: 'valor',  etiqueta: 'Valor',  texto: false },
    { campo: 'estado', etiqueta: 'Estado' },
]

// ─── Filtros ────────────────────────────────────────────────────────────────
const buscar = ref(props.filtros.buscar ?? '')

const pestanas = [
    { clave: 'por_revisar', etiqueta: 'Por revisar' },
    { clave: 'conciliado',  etiqueta: 'Conciliados' },
    { clave: 'ignorado',    etiqueta: 'Ignorados' },
    { clave: 'todos',       etiqueta: 'Todos' },
]

function filtrar(cambios = {}) {
    const f = { ...props.filtros, buscar: buscar.value, ...cambios }
    Object.keys(f).forEach(k => (f[k] === '' || f[k] == null) && delete f[k])
    router.get(URL, f, { preserveState: true, preserveScroll: true, replace: true })
}

// ─── Formato ────────────────────────────────────────────────────────────────
const fecha = (d) => new Date(`${d}T00:00:00`).toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric' })
const fechaHora = (iso) => iso ? new Date(iso).toLocaleString('es-CO', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : ''

// Familias del tema, no colores fijos: de noche un `bg-green-100` queda ilegible.
const estados = {
    pendiente:  { texto: 'Sin dueño',  clases: 'bg-pastel-rojo-2 text-aviso-rojo' },
    dudoso:     { texto: 'Por decidir', clases: 'bg-pastel-ambar-2 text-aviso-ambar' },
    conciliado: { texto: 'Verificado', clases: 'bg-pastel-verde-2 text-aviso-verde' },
    ignorado:   { texto: 'Ignorado',   clases: 'bg-pastel-azul-2 text-aviso-azul' },
}

// ─── Cuentas ────────────────────────────────────────────────────────────────
const cuentaForm = useForm({ nombre: '', banco: '', ultimos_digitos: '' })
const verCuentaNueva = ref(false)

function guardarCuenta() {
    cuentaForm.post(`${URL}/cuentas`, {
        preserveScroll: true,
        onSuccess: () => { cuentaForm.reset(); verCuentaNueva.value = false },
    })
}

const cuentasActivas = computed(() => props.cuentas.filter(c => c.activa))

// ─── Subir extracto ─────────────────────────────────────────────────────────
const cuentaId   = ref('')
// Con una sola cuenta no hay nada que elegir; y cuando se agrega la primera, el selector no puede
// quedarse vacío (la lista llega de nuevo desde el servidor, el valor inicial ya pasó).
watch(cuentasActivas, (lista) => {
    if (!lista.some(c => c.id === cuentaId.value)) cuentaId.value = lista[0]?.id ?? ''
}, { immediate: true })

const archivo    = ref(null)
const subiendo   = ref(false)
const resultado  = ref(null)
const problema   = ref(null)  // { message, vista_previa } cuando no reconoce las columnas
const columnas   = ref({ fecha: '', valor: '', descripcion: '', referencia: '' })
const entradaArchivo = ref(null)

const xsrf = () => { const c = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN=')); return c ? decodeURIComponent(c.split('=')[1]) : '' }

async function subir() {
    if (!archivo.value || !cuentaId.value) return

    subiendo.value = true
    resultado.value = null

    const datos = new FormData()
    datos.append('cuenta_id', cuentaId.value)
    datos.append('archivo', archivo.value)
    Object.entries(columnas.value).forEach(([k, v]) => { if (problema.value && v !== '') datos.append(`columnas[${k}]`, v) })

    try {
        const r = await fetch(`${URL}/importar`, {
            method: 'POST',
            headers: { 'X-XSRF-TOKEN': xsrf(), 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
            credentials: 'same-origin',
            body: datos,
        })
        const json = await r.json().catch(() => ({}))

        if (r.status === 422 && (json.vista_previa || json.message)) {
            problema.value = { message: json.message ?? 'No pude leer el archivo.', vista_previa: json.vista_previa ?? [] }
            return
        }
        if (!r.ok) {
            problema.value = { message: json.message ?? 'No se pudo subir el extracto.', vista_previa: [] }
            return
        }

        problema.value = null
        resultado.value = json
        archivo.value = null
        if (entradaArchivo.value) entradaArchivo.value.value = ''
        router.reload({ only: ['movimientos', 'resumen'], preserveScroll: true })
    } catch (e) {
        problema.value = { message: 'No hubo conexión con el servidor. Inténtalo de nuevo.', vista_previa: [] }
    } finally {
        subiendo.value = false
    }
}

const encabezadosVista = computed(() => problema.value?.vista_previa?.reduce((a, f) => Math.max(a, f.length), 0) ?? 0)

// ─── Asignar a una cuota ────────────────────────────────────────────────────
const asignando  = ref(null)   // el movimiento
const candidatas = ref([])
const cargandoCandidatas = ref(false)
const filtroCuota = ref('')

async function abrirAsignar(mov) {
    asignando.value = mov
    candidatas.value = []
    filtroCuota.value = ''
    cargandoCandidatas.value = true
    try {
        const r = await fetch(`${URL}/movimientos/${mov.id}/candidatas`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        candidatas.value = r.ok ? await r.json() : []
    } finally {
        cargandoCandidatas.value = false
    }
}

const candidatasVisibles = computed(() => {
    const q = filtroCuota.value.trim().toLowerCase()
    return q ? candidatas.value.filter(c => `${c.op_numero} ${c.cliente} ${c.concepto}`.toLowerCase().includes(q)) : candidatas.value
})

function asignar(cuota) {
    router.post(`${URL}/movimientos/${asignando.value.id}/asignar`, { cuota_id: cuota.id }, {
        preserveScroll: true,
        onSuccess: () => { asignando.value = null },
    })
}

// ─── Ignorar, restaurar, deshacer ───────────────────────────────────────────
function ignorar(mov) {
    const nota = window.prompt('¿Por qué se ignora? (opcional)', '')
    if (nota === null) return
    router.post(`${URL}/movimientos/${mov.id}/ignorar`, { nota }, { preserveScroll: true })
}
const restaurar = (mov) => router.post(`${URL}/movimientos/${mov.id}/restaurar`, {}, { preserveScroll: true })
function deshacer(mov) {
    if (!window.confirm('Se borrará el pago registrado y la cuota volverá a deber su valor. ¿Deshacer la conciliación?')) return
    router.post(`${URL}/movimientos/${mov.id}/deshacer`, {}, { preserveScroll: true })
}

const ic = 'w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm focus:ring-4 focus:ring-[var(--marca-suave)] focus:outline-none'
</script>

<template>
    <AppLayout title="Verificador de pagos">
        <div class="max-w-5xl mx-auto space-y-4 pb-8">

            <div>
                <h1 class="text-xl font-semibold text-tinta-900">Verificador de pagos</h1>
                <p class="text-sm text-tinta-400 mt-1">
                    Confirma con el banco que el dinero de cada cuota llegó, sin tener que revisar la cuenta. Solo
                    lee lo que entra: no mueve ni paga nada.
                </p>
            </div>

            <!-- Resumen -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div class="bg-superficie rounded-xl border border-linea p-3">
                    <p class="text-xs text-tinta-400">Por revisar</p>
                    <p class="text-2xl font-semibold text-tinta-900">{{ resumen.por_revisar }}</p>
                </div>
                <div class="bg-superficie rounded-xl border border-linea p-3">
                    <p class="text-xs text-tinta-400">Por decidir</p>
                    <p class="text-2xl font-semibold" :class="resumen.dudosos ? 'text-aviso-ambar' : 'text-tinta-900'">{{ resumen.dudosos }}</p>
                </div>
                <div class="bg-superficie rounded-xl border border-linea p-3">
                    <p class="text-xs text-tinta-400">Dinero sin dueño</p>
                    <p class="text-lg font-semibold" :class="resumen.sin_dueno_valor ? 'text-aviso-rojo' : 'text-tinta-900'">${{ formatCOP(resumen.sin_dueno_valor) }}</p>
                </div>
                <div class="bg-superficie rounded-xl border border-linea p-3">
                    <p class="text-xs text-tinta-400">Verificados este mes</p>
                    <p class="text-2xl font-semibold text-aviso-verde">{{ resumen.conciliados_mes }}</p>
                </div>
            </div>

            <!-- Subir extracto -->
            <div v-if="puedeEditar" class="bg-superficie rounded-xl border border-linea p-4 space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Subir extracto del banco</p>
                    <button type="button" @click="verCuentaNueva = !verCuentaNueva" class="text-xs font-medium text-aviso-azul hover:underline">
                        {{ verCuentaNueva ? 'Cancelar' : '+ Agregar cuenta' }}
                    </button>
                </div>

                <form v-if="verCuentaNueva" @submit.prevent="guardarCuenta" class="grid grid-cols-1 sm:grid-cols-4 gap-2 items-end">
                    <div class="sm:col-span-2">
                        <label class="block text-xs text-tinta-500 mb-1">Nombre de la cuenta *</label>
                        <input v-model="cuentaForm.nombre" type="text" maxlength="120" placeholder="Bancolombia ahorros principal" :class="ic" required />
                    </div>
                    <div>
                        <label class="block text-xs text-tinta-500 mb-1">Banco</label>
                        <input v-model="cuentaForm.banco" type="text" maxlength="80" :class="ic" />
                    </div>
                    <div>
                        <label class="block text-xs text-tinta-500 mb-1">Últimos dígitos</label>
                        <input v-model="cuentaForm.ultimos_digitos" type="text" maxlength="10" inputmode="numeric" placeholder="4821" :class="ic" />
                    </div>
                    <p v-if="cuentaForm.errors.nombre" class="text-xs text-aviso-rojo sm:col-span-4">{{ cuentaForm.errors.nombre }}</p>
                    <button type="submit" :disabled="cuentaForm.processing" class="sm:col-span-4 text-sm font-semibold px-4 py-2 rounded-lg text-white disabled:opacity-60" style="background:var(--marca);">
                        Guardar cuenta
                    </button>
                    <p class="text-xs text-tinta-400 sm:col-span-4">Solo se guardan los últimos dígitos: el número completo no hace falta.</p>
                </form>

                <p v-if="!cuentasActivas.length && !verCuentaNueva" class="text-sm text-tinta-400">
                    Agrega primero la cuenta donde te pagan tus clientes.
                </p>

                <form v-else-if="cuentasActivas.length" @submit.prevent="subir" class="grid grid-cols-1 sm:grid-cols-3 gap-2 items-end">
                    <div>
                        <label class="block text-xs text-tinta-500 mb-1">Cuenta</label>
                        <select v-model="cuentaId" :class="ic">
                            <option v-for="c in cuentasActivas" :key="c.id" :value="c.id">{{ c.nombre }}{{ c.ultimos_digitos ? ` · ${c.ultimos_digitos}` : '' }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-tinta-500 mb-1">Extracto (.xlsx o .csv)</label>
                        <input ref="entradaArchivo" type="file" accept=".xlsx,.csv,.txt" @change="archivo = $event.target.files[0] ?? null" class="block w-full text-sm text-tinta-500 file:mr-3 file:rounded-lg file:border-0 file:bg-tinta-100 file:px-3 file:py-2 file:text-sm" />
                    </div>
                    <button type="submit" :disabled="!archivo || subiendo" class="text-sm font-semibold px-4 py-2 rounded-lg text-white disabled:opacity-60" style="background:var(--marca);">
                        {{ subiendo ? 'Leyendo…' : 'Subir y verificar' }}
                    </button>
                </form>

                <!-- Resultado -->
                <div v-if="resultado" class="rounded-lg bg-pastel-verde border border-borde-aviso-verde p-3 text-sm text-aviso-verde">
                    <p class="font-semibold">Listo: {{ resultado.nuevos }} abonos nuevos.</p>
                    <ul class="mt-1 text-xs space-y-0.5">
                        <li>{{ resultado.conciliados }} verificados solos contra una cuota.</li>
                        <li v-if="resultado.dudosos">{{ resultado.dudosos }} por decidir: hay más de una explicación posible.</li>
                        <li v-if="resultado.pendientes">{{ resultado.pendientes }} sin dueño todavía.</li>
                        <li v-if="resultado.repetidos">{{ resultado.repetidos }} ya estaban de un extracto anterior.</li>
                        <li v-if="resultado.salidas">{{ resultado.salidas }} salidas de dinero ignoradas: el verificador solo mira lo que entra.</li>
                        <li v-if="resultado.ilegibles">{{ resultado.ilegibles }} filas que no pude leer.</li>
                    </ul>
                </div>

                <!-- No reconoció las columnas -->
                <div v-if="problema" class="rounded-lg bg-pastel-ambar border border-borde-aviso-ambar p-3 space-y-3">
                    <p class="text-sm text-aviso-ambar">{{ problema.message }}</p>
                    <template v-if="problema.vista_previa.length">
                        <div class="overflow-x-auto rounded border border-linea bg-superficie">
                            <table class="text-xs min-w-full">
                                <tbody>
                                    <tr v-for="(fila, i) in problema.vista_previa" :key="i" class="border-b border-separador last:border-0">
                                        <td v-for="n in encabezadosVista" :key="n" class="px-2 py-1 whitespace-nowrap text-tinta-500">{{ fila[n - 1] }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div v-for="campo in [['fecha', 'Columna de la fecha *'], ['valor', 'Columna del valor *'], ['descripcion', 'Columna de la descripción'], ['referencia', 'Columna de la referencia']]" :key="campo[0]">
                                <label class="block text-xs text-tinta-500 mb-1">{{ campo[1] }}</label>
                                <select v-model="columnas[campo[0]]" :class="ic">
                                    <option value="">—</option>
                                    <option v-for="n in encabezadosVista" :key="n" :value="n - 1">Columna {{ n }}</option>
                                </select>
                            </div>
                        </div>
                        <button type="button" @click="subir" :disabled="subiendo || columnas.fecha === '' || columnas.valor === ''" class="text-sm font-semibold px-4 py-2 rounded-lg text-white disabled:opacity-60" style="background:var(--marca);">
                            Volver a intentar con estas columnas
                        </button>
                    </template>
                </div>
            </div>

            <!-- Filtros -->
            <div class="flex flex-wrap items-center gap-2">
                <div class="flex rounded-lg border border-linea overflow-hidden bg-superficie">
                    <button v-for="p in pestanas" :key="p.clave" type="button" @click="filtrar({ estado: p.clave })"
                        class="px-3 py-1.5 text-sm" :class="filtros.estado === p.clave ? 'font-semibold text-white' : 'text-tinta-500 hover:bg-realce'"
                        :style="filtros.estado === p.clave ? 'background:var(--marca);' : ''">
                        {{ p.etiqueta }}
                    </button>
                </div>
                <select :value="filtros.cuenta ?? ''" @change="filtrar({ cuenta: $event.target.value })" class="rounded-lg border border-tinta-200 px-2 py-1.5 text-sm">
                    <option value="">Todas las cuentas</option>
                    <option v-for="c in cuentas" :key="c.id" :value="c.id">{{ c.nombre }}</option>
                </select>
                <input v-model="buscar" @keyup.enter="filtrar()" @blur="filtrar()" type="search" placeholder="Buscar en la descripción o referencia…" class="flex-1 min-w-[12rem] rounded-lg border border-tinta-200 px-3 py-1.5 text-sm" />
                <OrdenarLista :campos="camposOrden" :orden="orden" @ordenar="ordenarPor" />
            </div>

            <!-- Lista -->
            <div v-if="!movimientos.data.length" class="bg-superficie rounded-xl border border-linea p-8 text-center text-sm text-tinta-300">
                <template v-if="filtros.estado === 'por_revisar'">No hay abonos por revisar. Cuando subas un extracto, lo que no se pueda verificar solo aparece aquí.</template>
                <template v-else>No hay abonos con este filtro.</template>
            </div>

            <div v-else class="bg-superficie rounded-xl border border-linea divide-y divide-separador">
                <div v-for="m in movimientos.data" :key="m.id" class="p-4 flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 flex-1 basis-60">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-base font-semibold text-tinta-900">${{ formatCOP(m.valor) }}</span>
                            <span class="inline-flex text-xs font-semibold px-2.5 py-0.5 rounded-full" :class="estados[m.estado]?.clases">{{ estados[m.estado]?.texto }}</span>
                            <span v-if="m.automatico" class="text-xs text-tinta-400">· solo, sin intervención</span>
                        </div>
                        <p class="text-xs text-tinta-400 mt-0.5">{{ fecha(m.fecha) }} · {{ m.cuenta }}</p>
                        <p v-if="m.descripcion" class="text-sm text-tinta-700 mt-1 break-words">{{ m.descripcion }}</p>
                        <p v-if="m.referencia" class="text-xs text-tinta-400 break-words">Ref. {{ m.referencia }}</p>
                        <p v-if="m.contraparte" class="text-xs text-tinta-400 break-words">De: {{ m.contraparte }}</p>
                        <p v-if="m.nota" class="text-xs text-tinta-400 italic">{{ m.nota }}</p>
                        <p v-if="m.pago" class="text-xs text-aviso-verde mt-1">
                            Pago {{ m.pago.recibo }} en la orden
                            <a :href="`/produccion/ops/${m.pago.op_id}`" class="font-medium hover:underline">{{ m.pago.op_numero }}</a>
                            · {{ fechaHora(m.conciliado_at) }}<span v-if="m.conciliado_por"> · {{ m.conciliado_por }}</span>
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2 shrink-0">
                        <template v-if="puedeEditar && (m.estado === 'pendiente' || m.estado === 'dudoso')">
                            <button type="button" @click="abrirAsignar(m)" class="text-sm font-semibold px-3 py-1.5 rounded-lg text-white" style="background:var(--marca);">Asignar a una cuota</button>
                            <button type="button" @click="ignorar(m)" class="text-sm px-3 py-1.5 rounded-lg border border-linea text-tinta-500 hover:bg-realce">Ignorar</button>
                        </template>
                        <button v-if="puedeEditar && m.estado === 'ignorado'" type="button" @click="restaurar(m)" class="text-sm px-3 py-1.5 rounded-lg border border-linea text-tinta-500 hover:bg-realce">Volver a la bandeja</button>
                        <button v-if="puedeDeshacer && m.estado === 'conciliado'" type="button" @click="deshacer(m)" class="text-sm px-3 py-1.5 rounded-lg border border-linea text-aviso-rojo hover:bg-realce">Deshacer</button>
                    </div>
                </div>
            </div>

            <!-- Paginación -->
            <div v-if="movimientos.links && movimientos.last_page > 1" class="flex flex-wrap justify-center gap-1">
                <template v-for="l in movimientos.links" :key="l.label">
                    <button v-if="l.url" type="button" @click="router.get(l.url, {}, { preserveState: true, preserveScroll: true })"
                        class="px-3 py-1 rounded-lg text-sm border border-linea" :class="l.active ? 'font-semibold text-white' : 'text-tinta-500 hover:bg-realce'"
                        :style="l.active ? 'background:var(--marca);' : ''" v-html="l.label" />
                </template>
            </div>
        </div>

        <!-- Asignar -->
        <Teleport to="body">
            <div v-if="asignando" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center">
                <div class="absolute inset-0 bg-black/40" @click="asignando = null" />
                <div class="relative bg-superficie w-full sm:max-w-xl rounded-t-2xl sm:rounded-2xl p-5 max-h-[90vh] overflow-y-auto">
                    <h2 class="text-lg font-semibold text-tinta-900">Asignar ${{ formatCOP(asignando.valor) }} a una cuota</h2>
                    <p class="text-xs text-tinta-400 mb-3">{{ fecha(asignando.fecha) }}<span v-if="asignando.descripcion"> · {{ asignando.descripcion }}</span></p>

                    <input v-model="filtroCuota" type="search" placeholder="Buscar por orden, cliente o concepto…" :class="[ic, 'mb-3']" />

                    <p v-if="cargandoCandidatas" class="text-sm text-tinta-400 py-4 text-center">Buscando cuotas…</p>
                    <p v-else-if="!candidatasVisibles.length" class="text-sm text-tinta-400 py-4 text-center">No hay cuotas pendientes con eso.</p>

                    <ul v-else class="space-y-2">
                        <li v-for="c in candidatasVisibles" :key="c.id" class="border rounded-lg p-3 flex flex-wrap items-center justify-between gap-2"
                            :class="c.sugerida ? 'border-borde-aviso-verde bg-pastel-verde' : 'border-linea'">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-tinta-900">{{ c.op_numero }} · {{ c.cliente ?? 'Sin cliente' }}</p>
                                <p class="text-xs text-tinta-400">{{ c.concepto }}<span v-if="c.vence"> · vence {{ fecha(c.vence) }}</span></p>
                                <p class="text-xs mt-0.5" :class="c.coincide ? 'text-aviso-verde font-semibold' : 'text-tinta-500'">
                                    Debe ${{ formatCOP(c.saldo) }}<span v-if="c.coincide"> · el valor coincide</span><span v-else> · el valor no coincide</span>
                                </p>
                            </div>
                            <button type="button" @click="asignar(c)" class="text-sm font-semibold px-3 py-1.5 rounded-lg text-white shrink-0" style="background:var(--marca);">Asignar</button>
                        </li>
                    </ul>

                    <button type="button" @click="asignando = null" class="mt-4 w-full text-sm text-tinta-500 py-2">Cancelar</button>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
