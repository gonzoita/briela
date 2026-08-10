<template>
  <div class="bg-superficie rounded-xl border border-linea p-4 mt-4">
    <h3 class="text-base font-semibold text-[var(--marca)] mb-4 flex items-center gap-2">
      <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
      </svg>
      Módulo Financiero
    </h3>

    <!-- Resumen -->
    <div class="grid grid-cols-3 gap-3 mb-5">
      <div class="bg-tinta-50 rounded-lg p-3 text-center">
        <div class="text-xs text-tinta-400 mb-1">Total OP</div>
        <div class="font-semibold text-tinta-900 text-sm">${{ fmt(totalOp) }}</div>
      </div>
      <div class="bg-green-50 rounded-lg p-3 text-center">
        <div class="text-xs text-tinta-400 mb-1">Pagado</div>
        <div class="font-semibold text-green-700 text-sm">${{ fmt(totalPagadoLocal) }}</div>
      </div>
      <div class="bg-red-50 rounded-lg p-3 text-center">
        <div class="text-xs text-tinta-400 mb-1">Saldo</div>
        <div class="font-semibold text-red-700 text-sm">${{ fmt(saldoPendienteLocal) }}</div>
      </div>
    </div>

    <!-- Cuotas -->
    <div class="mb-4">
      <div class="flex justify-between items-center mb-2">
        <span class="text-sm font-semibold text-tinta-700">Cuotas</span>
        <button @click="mostrarFormCuota = !mostrarFormCuota"
          class="text-xs text-[var(--marca)] font-medium flex items-center gap-1">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
          </svg>
          Nueva cuota
        </button>
      </div>

      <!-- Formulario nueva cuota -->
      <div v-if="mostrarFormCuota" class="bg-blue-50 rounded-lg p-3 mb-3 space-y-2">
        <input v-model="formCuota.concepto" placeholder="Concepto (ej: Anticipo 50%)"
          class="w-full border border-tinta-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300" />
        <div class="grid grid-cols-2 gap-2">
          <input v-model="formCuota.valor" type="number" placeholder="Valor"
            class="border border-tinta-200 rounded-lg px-3 py-2 text-sm focus:outline-none" />
          <input v-model="formCuota.fecha_vencimiento" type="date"
            class="border border-tinta-200 rounded-lg px-3 py-2 text-sm focus:outline-none" />
        </div>
        <div class="flex gap-2">
          <button @click="crearCuota" :disabled="guardandoCuota"
            class="bg-[var(--marca)] text-white text-sm px-4 py-2 rounded-lg font-medium disabled:opacity-50">
            {{ guardandoCuota ? 'Guardando...' : 'Guardar' }}
          </button>
          <button @click="mostrarFormCuota = false"
            class="text-sm text-tinta-400 px-4 py-2">
            Cancelar
          </button>
        </div>
      </div>

      <!-- Lista de cuotas -->
      <div v-if="cargando" class="text-sm text-tinta-300 text-center py-4">
        Cargando...
      </div>
      <div v-else-if="!cuotas.length" class="text-sm text-tinta-300 text-center py-4">
        No hay cuotas registradas
      </div>
      <div v-for="cuota in cuotas" :key="cuota.id"
        class="flex items-center justify-between border border-linea rounded-lg px-3 py-2 mb-2">
        <div class="flex items-center gap-2 flex-1 min-w-0">
          <span class="w-2.5 h-2.5 rounded-full flex-shrink-0"
            :class="{
              'bg-green-500': cuota.semaforo === 'verde',
              'bg-yellow-400': cuota.semaforo === 'amarillo',
              'bg-red-500': cuota.semaforo === 'rojo',
              'bg-gray-300': cuota.semaforo === 'gris',
            }">
          </span>
          <div class="min-w-0">
            <div class="flex items-center gap-1.5 flex-wrap">
              <span class="text-sm font-medium text-tinta-900 truncate">{{ cuota.concepto }}</span>
              <span v-if="cuota.es_saldo_automatico"
                class="text-xs bg-tinta-100 text-tinta-400 px-1.5 py-0.5 rounded flex-shrink-0">
                auto
              </span>
            </div>
            <div class="text-xs text-tinta-300">
              {{ cuota.fecha_vencimiento ? 'Vence: ' + fmtFecha(cuota.fecha_vencimiento) : 'Sin fecha' }}
            </div>
          </div>
        </div>
        <div class="text-right ml-2 flex-shrink-0">
          <div class="text-sm font-semibold text-tinta-900">${{ fmt(cuota.valor) }}</div>
          <div class="text-xs" :class="cuota.estado === 'pagado' ? 'text-green-600' : 'text-orange-500'">
            {{ cuota.estado === 'pagado' ? '✓ Pagado' : `Saldo $${fmt(cuota.saldo)}` }}
          </div>
        </div>
        <button v-if="cuota.estado !== 'pagado' && !cuota.es_saldo_automatico" @click="abrirPago(cuota)"
          class="ml-3 bg-green-600 text-white text-xs px-3 py-1.5 rounded-lg font-medium flex-shrink-0">
          Pagar
        </button>
      </div>
    </div>

    <!-- Modal registrar pago -->
    <Teleport to="body">
      <div v-if="cuotaSeleccionada"
        class="fixed inset-0 z-50 bg-black/50 flex items-end sm:items-center justify-center">
        <div class="bg-superficie w-full max-w-md rounded-t-2xl sm:rounded-2xl p-5">
          <h4 class="font-semibold text-tinta-900 mb-4">
            Registrar pago — {{ cuotaSeleccionada.concepto }}
          </h4>
          <div class="space-y-3">
            <div>
              <label class="text-xs text-tinta-400 block mb-1">Valor a pagar</label>
              <input v-model="formPago.valor" type="number"
                :placeholder="`Saldo: $${fmt(cuotaSeleccionada.saldo)}`"
                class="w-full border border-tinta-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300" />
            </div>
            <div>
              <label class="text-xs text-tinta-400 block mb-1">Medio de pago</label>
              <select v-model="formPago.medio_pago"
                class="w-full border border-tinta-200 rounded-lg px-3 py-2 text-sm focus:outline-none">
                <option value="efectivo">Efectivo</option>
                <option value="transferencia">Transferencia</option>
                <option value="cheque">Cheque</option>
              </select>
            </div>
            <div>
              <label class="text-xs text-tinta-400 block mb-1">Fecha de pago</label>
              <input v-model="formPago.fecha_pago" type="date"
                class="w-full border border-tinta-200 rounded-lg px-3 py-2 text-sm focus:outline-none" />
            </div>
            <div>
              <label class="text-xs text-tinta-400 block mb-1">Referencia (opcional)</label>
              <input v-model="formPago.referencia" placeholder="Nº cheque / transferencia"
                class="w-full border border-tinta-200 rounded-lg px-3 py-2 text-sm focus:outline-none" />
            </div>
            <div>
              <label class="text-xs text-tinta-400 block mb-1">Notas (opcional)</label>
              <textarea v-model="formPago.notas" rows="2" placeholder="Observaciones..."
                class="w-full border border-tinta-200 rounded-lg px-3 py-2 text-sm focus:outline-none resize-none"></textarea>
            </div>
          </div>
          <p v-if="errorPago" class="text-red-500 text-xs mt-2">{{ errorPago }}</p>
          <div class="flex gap-2 mt-4">
            <button @click="registrarPago" :disabled="guardandoPago"
              class="flex-1 bg-[var(--marca)] text-white py-2.5 rounded-lg font-medium text-sm disabled:opacity-50">
              {{ guardandoPago ? 'Registrando...' : 'Registrar pago' }}
            </button>
            <button @click="cuotaSeleccionada = null"
              class="px-4 text-tinta-400 text-sm">
              Cancelar
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Historial de pagos -->
    <div v-if="todosLosPagos.length" class="mt-4">
      <div class="text-sm font-semibold text-tinta-700 mb-2">Historial de pagos</div>
      <div v-for="pago in todosLosPagos" :key="pago.id"
        class="flex items-center justify-between border border-linea rounded-lg px-3 py-2 mb-1.5">
        <div class="min-w-0 flex-1">
          <div class="text-sm font-medium text-tinta-900">{{ pago.numero_recibo }}</div>
          <div class="text-xs text-tinta-300">{{ fmtFecha(pago.fecha_pago) }} · {{ pago.medio_pago }}</div>
        </div>
        <div class="flex items-center gap-2 ml-2 flex-shrink-0">
          <span class="text-sm font-semibold text-green-700">${{ fmt(pago.valor) }}</span>
          <a :href="`/financiero/pagos/${pago.id}/pdf`" target="_blank"
            class="text-[var(--marca)] text-xs flex items-center gap-1 hover:underline">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            PDF
          </a>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'

const props = defineProps({
  opId:   { type: Number, required: true },
  totalOp:{ type: Number, default: 0 },
})

const cuotas         = ref([])
const cargando       = ref(false)
const totalOpLocal   = ref(props.totalOp ?? 0)

const todosLosPagos = computed(() =>
  cuotas.value.flatMap(c => (c.pagos ?? []).map(p => ({ ...p, cuota_concepto: c.concepto })))
    .sort((a, b) => a.id - b.id)
)

const totalPagadoLocal    = computed(() => todosLosPagos.value.reduce((s, p) => s + parseFloat(p.valor), 0))
const saldoPendienteLocal = computed(() => totalOpLocal.value - totalPagadoLocal.value)

const mostrarFormCuota = ref(false)
const cuotaSeleccionada = ref(null)
const guardandoCuota = ref(false)
const guardandoPago  = ref(false)
const errorPago      = ref('')

const formCuota = ref({ concepto: '', valor: '', fecha_vencimiento: '' })
const formPago  = ref({ valor: '', medio_pago: 'transferencia', fecha_pago: '', referencia: '', notas: '' })

function fmt(v) {
  return Number(v || 0).toLocaleString('es-CO')
}

function fmtFecha(d) {
  if (!d) return '—'
  const [y, m, dia] = d.split('-')
  return `${dia}/${m}/${y}`
}

function getCookie(name) {
  const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'))
  return match ? decodeURIComponent(match[2]) : ''
}

async function cargar() {
  cargando.value = true
  try {
    const res = await fetch(`/ops/${props.opId}/cuotas`, {
      headers: {
        'Accept': 'application/json',
        'X-XSRF-TOKEN': getCookie('XSRF-TOKEN'),
      },
      credentials: 'same-origin',
    })
    if (!res.ok) {
      console.error('Error cargando cuotas:', res.status, await res.text())
      return
    }
    const data = await res.json()
    cuotas.value       = data.cuotas ?? []
    totalOpLocal.value = data.total_op ?? props.totalOp ?? 0
  } catch (e) {
    console.error('Error fetch cuotas:', e)
  } finally {
    cargando.value = false
  }
}

async function crearCuota() {
  if (!formCuota.value.concepto || !formCuota.value.valor) return
  guardandoCuota.value = true
  try {
    await fetch(`/ops/${props.opId}/cuotas`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': getCookie('XSRF-TOKEN'), 'Accept': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(formCuota.value),
    })
    formCuota.value = { concepto: '', valor: '', fecha_vencimiento: '' }
    mostrarFormCuota.value = false
    await cargar()
  } finally {
    guardandoCuota.value = false
  }
}

function abrirPago(cuota) {
  cuotaSeleccionada.value = cuota
  errorPago.value = ''
  formPago.value = {
    valor:       cuota.saldo,
    medio_pago:  'transferencia',
    fecha_pago:  new Date().toISOString().split('T')[0],
    referencia:  '',
    notas:       '',
  }
}

async function registrarPago() {
  errorPago.value = ''
  if (!formPago.value.valor || parseFloat(formPago.value.valor) <= 0) {
    errorPago.value = 'Ingresa un valor mayor a 0'
    return
  }
  guardandoPago.value = true
  try {
    const res = await fetch(`/ops/${props.opId}/pagos`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': getCookie('XSRF-TOKEN'), 'Accept': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ ...formPago.value, cuota_id: cuotaSeleccionada.value.id }),
    })
    if (!res.ok) {
      const err = await res.json()
      errorPago.value = err.message ?? 'Error al registrar el pago'
      return
    }
    cuotaSeleccionada.value = null
    await cargar()
  } catch {
    errorPago.value = 'Error de conexión'
  } finally {
    guardandoPago.value = false
  }
}

onMounted(cargar)

defineExpose({ cargar })
</script>
