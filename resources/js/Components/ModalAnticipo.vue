<template>
  <div class="fixed inset-0 z-50 bg-black/50 flex items-end sm:items-center justify-center">
    <div class="bg-superficie w-full max-w-md rounded-t-2xl sm:rounded-2xl p-5">

      <div class="flex items-center gap-3 mb-4">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-yellow-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
        </svg>
        <h4 class="font-semibold text-tinta-900 text-base">Registrar anticipo para confirmar</h4>
      </div>

      <p class="text-sm text-tinta-400 mb-4">
        Este cliente requiere anticipo. Define la cuota y registra el pago antes de confirmar la OP.
      </p>

      <div class="space-y-3">
        <div>
          <label class="text-xs text-tinta-400 block mb-1">Concepto de la cuota</label>
          <input v-model="form.concepto" placeholder="ej: Anticipo 50%"
            class="w-full border border-tinta-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300"/>
        </div>
        <div class="grid grid-cols-2 gap-2">
          <div>
            <label class="text-xs text-tinta-400 block mb-1">Valor cuota</label>
            <input v-model="form.valor_cuota" type="number" placeholder="0"
              class="w-full border border-tinta-200 rounded-lg px-3 py-2 text-sm focus:outline-none"/>
          </div>
          <div>
            <label class="text-xs text-tinta-400 block mb-1">Valor pagado</label>
            <input v-model="form.valor_pago" type="number" placeholder="0"
              class="w-full border border-tinta-200 rounded-lg px-3 py-2 text-sm focus:outline-none"/>
          </div>
        </div>
        <div>
          <label class="text-xs text-tinta-400 block mb-1">Medio de pago</label>
          <select v-model="form.medio_pago"
            class="w-full border border-tinta-200 rounded-lg px-3 py-2 text-sm focus:outline-none">
            <option value="efectivo">Efectivo</option>
            <option value="transferencia">Transferencia</option>
            <option value="cheque">Cheque</option>
          </select>
        </div>
        <div>
          <label class="text-xs text-tinta-400 block mb-1">Fecha de pago</label>
          <input v-model="form.fecha_pago" type="date"
            class="w-full border border-tinta-200 rounded-lg px-3 py-2 text-sm focus:outline-none"/>
        </div>
        <div>
          <label class="text-xs text-tinta-400 block mb-1">Referencia (opcional)</label>
          <input v-model="form.referencia" placeholder="Nº cheque / transferencia"
            class="w-full border border-tinta-200 rounded-lg px-3 py-2 text-sm focus:outline-none"/>
        </div>
      </div>

      <div v-if="error" class="mt-3 text-sm text-red-600 bg-red-50 rounded-lg px-3 py-2">
        {{ error }}
      </div>

      <div class="flex gap-2 mt-5">
        <button @click="confirmar" :disabled="guardando"
          class="flex-1 bg-[var(--marca)] text-white py-2.5 rounded-lg font-medium text-sm disabled:opacity-60">
          {{ guardando ? 'Guardando...' : 'Registrar y confirmar OP' }}
        </button>
        <button @click="$emit('cancelar')" class="px-4 text-tinta-400 text-sm">
          Cancelar
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'

const props = defineProps({
  opId: { type: Number, required: true },
})
const emit = defineEmits(['confirmada', 'cancelar'])

const guardando = ref(false)
const error = ref('')
const form = ref({
  concepto:    'Anticipo',
  valor_cuota: '',
  valor_pago:  '',
  medio_pago:  'transferencia',
  fecha_pago:  new Date().toISOString().split('T')[0],
  referencia:  '',
})

function getXsrf() {
  const c = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))
  return c ? decodeURIComponent(c.split('=')[1]) : ''
}

async function confirmar() {
  error.value = ''
  if (!form.value.concepto || !form.value.valor_cuota || !form.value.valor_pago) {
    error.value = 'Completa concepto, valor de cuota y valor pagado.'
    return
  }
  if (parseFloat(form.value.valor_pago) <= 0) {
    error.value = 'El valor pagado debe ser mayor a 0.'
    return
  }
  guardando.value = true
  try {
    const headers = {
      'Content-Type': 'application/json',
      'X-XSRF-TOKEN': getXsrf(),
      'Accept': 'application/json',
    }

    // 1. Crear cuota
    const resCuota = await fetch(`/ops/${props.opId}/cuotas`, {
      method: 'POST',
      headers,
      credentials: 'same-origin',
      body: JSON.stringify({
        concepto:          form.value.concepto,
        valor:             form.value.valor_cuota,
        fecha_vencimiento: null,
      }),
    })
    if (!resCuota.ok) throw new Error('Error al crear cuota')
    const dataCuota = await resCuota.json()

    // 2. Registrar pago
    const resPago = await fetch(`/ops/${props.opId}/pagos`, {
      method: 'POST',
      headers,
      credentials: 'same-origin',
      body: JSON.stringify({
        cuota_id:   dataCuota.cuota.id,
        valor:      form.value.valor_pago,
        medio_pago: form.value.medio_pago,
        fecha_pago: form.value.fecha_pago,
        referencia: form.value.referencia,
      }),
    })
    if (!resPago.ok) throw new Error('Error al registrar pago')

    // 3. Cambiar estado a confirmada
    const resEstado = await fetch(`/produccion/ops/${props.opId}/estado`, {
      method: 'POST',
      headers,
      credentials: 'same-origin',
      body: JSON.stringify({ estado: 'confirmada' }),
    })
    if (!resEstado.ok) throw new Error('Error al confirmar la OP')

    emit('confirmada')
  } catch (e) {
    error.value = e.message ?? 'Error al procesar. Intenta de nuevo.'
  } finally {
    guardando.value = false
  }
}
</script>
