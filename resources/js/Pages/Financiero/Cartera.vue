<script setup>
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import GraficosPersonalizados from '@/Components/GraficosPersonalizados.vue'

const props = defineProps({
  cuotas: { type: Array, default: () => [] },
})

const filtro = ref('todas') // todas | vencidas | por_vencer

const cuotasFiltradas = computed(() => {
  if (filtro.value === 'vencidas')   return props.cuotas.filter(c => c.semaforo === 'rojo')
  if (filtro.value === 'por_vencer') return props.cuotas.filter(c => c.semaforo === 'amarillo')
  return props.cuotas
})

const totalCartera  = computed(() => props.cuotas.reduce((s, c) => s + c.saldo, 0))
const totalVencido  = computed(() => props.cuotas.filter(c => c.semaforo === 'rojo').reduce((s, c) => s + c.saldo, 0))
const countRojo     = computed(() => props.cuotas.filter(c => c.semaforo === 'rojo').length)
const countAmarillo = computed(() => props.cuotas.filter(c => c.semaforo === 'amarillo').length)

function fmt(v) {
  return Number(v || 0).toLocaleString('es-CO')
}

function fmtFecha(d) {
  if (!d) return '—'
  const [y, m, dia] = d.split('-')
  return `${dia}/${m}/${y}`
}

const semaforoClass = {
  verde:    'bg-green-500',
  amarillo: 'bg-yellow-400',
  rojo:     'bg-red-500',
  gris:     'bg-gray-300',
}
</script>

<template>
  <AppLayout title="Cartera">
    <div class="max-w-4xl mx-auto">

      <!-- Cabecera -->
      <div class="flex items-center gap-3 mb-5">

            <!-- Lo recaudado y la cartera, con los gráficos que la empresa arme. -->
            <GraficosPersonalizados modulo="financiero" :puede-gestionar="$page.props.auth?.permisosLista?.includes('graficos.gestionar') ?? false" />
        <a href="/financiero/cartera" class="text-tinta-300 hover:text-tinta-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
          </svg>
        </a>
        <h1 class="text-xl font-semibold text-tinta-900">Cartera</h1>
      </div>

      <!-- Resumen -->
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
        <div class="bg-superficie rounded-2xl border border-linea p-4 text-center">
          <div class="text-xs text-tinta-400 mb-1">Total en cartera</div>
          <div class="font-semibold text-tinta-900">${{ fmt(totalCartera) }}</div>
        </div>
        <div class="bg-pastel-rojo rounded-2xl border border-borde-aviso-rojo p-4 text-center">
          <div class="text-xs text-tinta-400 mb-1">Vencido</div>
          <div class="font-semibold text-aviso-rojo">${{ fmt(totalVencido) }}</div>
        </div>
        <div class="bg-superficie rounded-2xl border border-linea p-4 text-center">
          <div class="text-xs text-tinta-400 mb-1">Vencidas</div>
          <div class="font-semibold text-aviso-rojo text-lg">{{ countRojo }}</div>
        </div>
        <div class="bg-superficie rounded-2xl border border-linea p-4 text-center">
          <div class="text-xs text-tinta-400 mb-1">Por vencer</div>
          <div class="font-semibold text-aviso-ambar text-lg">{{ countAmarillo }}</div>
        </div>
      </div>

      <!-- Filtros -->
      <div class="flex gap-2 mb-4">
        <button v-for="tab in [
          { key: 'todas',      label: 'Todas' },
          { key: 'vencidas',   label: 'Vencidas' },
          { key: 'por_vencer', label: 'Por vencer' },
        ]" :key="tab.key"
          @click="filtro = tab.key"
          class="px-4 py-1.5 rounded-full text-sm font-medium border transition-colors"
          :class="filtro === tab.key
            ? 'border-[var(--marca)] text-[var(--marca)] bg-pastel-azul'
            : 'border-linea text-tinta-400 hover:bg-tinta-50'">
          {{ tab.label }}
          <span v-if="tab.key === 'vencidas' && countRojo" class="ml-1 bg-pastel-rojo-2 text-aviso-rojo text-xs px-1.5 rounded-full">{{ countRojo }}</span>
          <span v-if="tab.key === 'por_vencer' && countAmarillo" class="ml-1 bg-pastel-ambar-2 text-aviso-ambar text-xs px-1.5 rounded-full">{{ countAmarillo }}</span>
        </button>
      </div>

      <!-- Tabla desktop -->
      <div class="bg-superficie rounded-2xl border border-linea overflow-hidden">
        <div v-if="!cuotasFiltradas.length" class="p-10 text-center text-sm text-tinta-300">
          No hay cuotas pendientes
        </div>

        <div v-else class="hidden md:block overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="bg-tinta-50 border-b border-linea">
                <th class="w-8 px-4 py-2.5"></th>
                <th class="text-left px-4 py-2.5 text-xs font-semibold text-tinta-400 uppercase">Cliente</th>
                <th class="text-left px-3 py-2.5 text-xs font-semibold text-tinta-400 uppercase">OP</th>
                <th class="text-left px-3 py-2.5 text-xs font-semibold text-tinta-400 uppercase">Cuota</th>
                <th class="text-right px-3 py-2.5 text-xs font-semibold text-tinta-400 uppercase">Valor</th>
                <th class="text-right px-3 py-2.5 text-xs font-semibold text-tinta-400 uppercase">Saldo</th>
                <th class="text-left px-3 py-2.5 text-xs font-semibold text-tinta-400 uppercase">Vencimiento</th>
                <th class="w-20 px-3 py-2.5 text-xs font-semibold text-tinta-400 uppercase">Estado</th>
                <th class="w-20"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-separador">
              <tr v-for="c in cuotasFiltradas" :key="c.id" class="hover:bg-tinta-50 transition-colors">
                <td class="px-4 py-3">
                  <span class="w-2.5 h-2.5 rounded-full inline-block" :class="semaforoClass[c.semaforo]"></span>
                </td>
                <td class="px-4 py-3 font-medium text-tinta-900">{{ c.cliente }}</td>
                <td class="px-3 py-3">
                  <span class="font-mono text-xs bg-tinta-100 text-tinta-500 px-2 py-0.5 rounded">{{ c.op_numero }}</span>
                </td>
                <td class="px-3 py-3 text-tinta-500">{{ c.concepto }}</td>
                <td class="px-3 py-3 text-right text-tinta-700">${{ fmt(c.valor) }}</td>
                <td class="px-3 py-3 text-right font-semibold" :class="c.semaforo === 'rojo' ? 'text-aviso-rojo' : 'text-tinta-900'">${{ fmt(c.saldo) }}</td>
                <td class="px-3 py-3 text-tinta-400 text-xs">{{ fmtFecha(c.fecha_vencimiento) }}</td>
                <td class="px-3 py-3">
                  <span class="text-xs px-2 py-0.5 rounded-full font-medium"
                    :class="{
                      'bg-pastel-naranja text-aviso-naranja': c.estado === 'parcial',
                      'bg-tinta-100 text-tinta-400': c.estado === 'pendiente',
                    }">
                    {{ c.estado === 'parcial' ? 'Parcial' : 'Pendiente' }}
                  </span>
                </td>
                <td class="px-3 py-3">
                  <a :href="`/produccion/ops/${c.op_id}`"
                    class="text-xs text-[var(--marca)] hover:underline font-medium">
                    Ver OP
                  </a>
                </td>
              </tr>
            </tbody>
            <tfoot class="bg-tinta-50 border-t border-linea">
              <tr>
                <td colspan="5" class="px-4 py-2.5 text-xs font-semibold text-tinta-500 text-right">Total saldo:</td>
                <td class="px-3 py-2.5 text-right font-semibold text-tinta-900">${{ fmt(cuotasFiltradas.reduce((s, c) => s + c.saldo, 0)) }}</td>
                <td colspan="3"></td>
              </tr>
            </tfoot>
          </table>
        </div>

        <!-- Cards mobile -->
        <div class="md:hidden divide-y divide-linea">
          <div v-for="c in cuotasFiltradas" :key="c.id" class="p-4">
            <div class="flex items-start gap-2.5">
              <span class="w-2.5 h-2.5 rounded-full mt-1.5 flex-shrink-0" :class="semaforoClass[c.semaforo]"></span>
              <div class="flex-1 min-w-0">
                <div class="flex items-center justify-between gap-2">
                  <p class="font-medium text-tinta-900 text-sm truncate">{{ c.cliente }}</p>
                  <span class="font-mono text-xs bg-tinta-100 text-tinta-500 px-1.5 py-0.5 rounded flex-shrink-0">{{ c.op_numero }}</span>
                </div>
                <p class="text-xs text-tinta-400 mt-0.5">{{ c.concepto }}</p>
                <div class="flex items-center justify-between mt-2">
                  <div>
                    <p class="text-xs text-tinta-300">Vence: {{ fmtFecha(c.fecha_vencimiento) }}</p>
                  </div>
                  <div class="text-right">
                    <p class="text-sm font-semibold" :class="c.semaforo === 'rojo' ? 'text-aviso-rojo' : 'text-tinta-900'">${{ fmt(c.saldo) }}</p>
                    <p class="text-xs text-tinta-300">de ${{ fmt(c.valor) }}</p>
                  </div>
                </div>
                <div class="mt-2">
                  <a :href="`/produccion/ops/${c.op_id}`"
                    class="text-xs text-[var(--marca)] font-medium hover:underline">
                    Ver OP →
                  </a>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

    </div>
  </AppLayout>
</template>
