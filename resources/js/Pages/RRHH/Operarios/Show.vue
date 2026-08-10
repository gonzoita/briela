<script setup>
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    operario:          Object,
    disciplinas:       Array,
    hitos_mes:         Array,
    horas_extras_mes:  Array,
    bono_mes:          Object,
    pasos_mes:         Array,
    historial_bonos:   Array,
    permisos_mes:      Array,
    historial_puntos:  Array,
    mes_actual:        Number,
    anio_actual:       Number,
})

const tab = ref('resumen')
const TABS = [
    { key: 'resumen',    label: 'Resumen'      },
    { key: 'puntos',     label: 'Puntos'       },
    { key: 'documentos', label: 'Documentos'   },
    { key: 'trabajos',   label: 'Trabajos'     },
    { key: 'disciplina', label: 'Disciplina'   },
    { key: 'bonos',      label: 'Bonos/Hitos'  },
    { key: 'extras',     label: 'Extras'       },
]

const NIVEL_COLORS = {
    'Bronce':   '#CD7F32',
    'Plata':    '#C0C0C0',
    'Oro':      '#FFD700',
    'Diamante': '#00BFFF',
}
const NIVEL_ICONOS = {
    'Bronce':   '🥉',
    'Plata':    '🥈',
    'Oro':      '🥇',
    'Diamante': '💎',
}
const nivelColor = computed(() => NIVEL_COLORS[props.operario.nivel] ?? '#6B7280')
const nivelIcono = computed(() => NIVEL_ICONOS[props.operario.nivel] ?? '⭐')

const puntosManualForm = ref({ puntos: 0, concepto: '' })
const guardandoPuntos  = ref(false)
function agregarPuntosManual() {
    guardandoPuntos.value = true
    router.post(`/rrhh/operarios/${props.operario.id}/puntos/manual`, puntosManualForm.value, {
        preserveScroll: true,
        onSuccess: () => { puntosManualForm.value = { puntos: 0, concepto: '' } },
        onFinish: () => { guardandoPuntos.value = false },
    })
}

const DOCS_LISTA = [
    { campo: 'archivo_hoja_vida',              label: 'Hoja de vida'               },
    { campo: 'archivo_cedula',                 label: 'Cédula'                     },
    { campo: 'archivo_eps',                    label: 'Documento EPS'              },
    { campo: 'archivo_pension',                label: 'Documento pensión'          },
    { campo: 'archivo_antecedentes',           label: 'Antecedentes disciplinarios' },
    { campo: 'archivo_certificacion_bancaria', label: 'Certificación bancaria'     },
]

const MESES = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic']
const mesLabel = (m) => MESES[(m ?? 1) - 1]

const formatFecha = (d) => d
    ? new Date(d).toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric' })
    : '—'

// ─── Disciplina
const disciplinaForm = ref({ tipo: 'llamado_atencion', descripcion: '', fecha: '', penalizacion_valor: '' })
const disciplinaErrors = ref({})
function storeDisciplina() {
    router.post(`/rrhh/operarios/${props.operario.id}/disciplina`, disciplinaForm.value, {
        preserveScroll: true,
        onError: (e) => { disciplinaErrors.value = e },
        onSuccess: () => { disciplinaForm.value = { tipo: 'llamado_atencion', descripcion: '', fecha: '', penalizacion_valor: '' } },
    })
}
function firmarDisciplina(d) {
    router.post(`/rrhh/operarios/${props.operario.id}/disciplina/${d.id}/firmar`, {}, { preserveScroll: true })
}

// ─── Hitos
const hitoForm = ref({ nombre: '', tipo: 'manual', meta_valor: 0, meta_tipo: 'pasos', valor_bono: 0, periodo_mes: props.mes_actual, periodo_anio: props.anio_actual })
const hitoErrors = ref({})
function storeHito() {
    router.post(`/rrhh/operarios/${props.operario.id}/hitos`, hitoForm.value, {
        preserveScroll: true,
        onError: (e) => { hitoErrors.value = e },
        onSuccess: () => { hitoForm.value = { nombre: '', tipo: 'manual', meta_valor: 0, meta_tipo: 'pasos', valor_bono: 0, periodo_mes: props.mes_actual, periodo_anio: props.anio_actual } },
    })
}
function calcularBono() {
    router.post(`/rrhh/operarios/${props.operario.id}/calcular-bono`, { mes: props.mes_actual, anio: props.anio_actual }, { preserveScroll: true })
}

// ─── Horas extras
const extraForm = ref({ fecha: '', tipo: 'diurna', horas: 1, observacion: '' })
const extraErrors = ref({})
function storeExtra() {
    router.post(`/rrhh/operarios/${props.operario.id}/horas-extras`, extraForm.value, {
        preserveScroll: true,
        onError: (e) => { extraErrors.value = e },
        onSuccess: () => { extraForm.value = { fecha: '', tipo: 'diurna', horas: 1, observacion: '' } },
    })
}

// ─── Permisos
const permisoForm = ref({ fecha_inicio: '', fecha_fin: '', motivo: '' })
const permisoErrors = ref({})
function storePermiso() {
    router.post(`/rrhh/operarios/${props.operario.id}/permisos`, permisoForm.value, {
        preserveScroll: true,
        onError: (e) => { permisoErrors.value = e },
        onSuccess: () => { permisoForm.value = { fecha_inicio: '', fecha_fin: '', motivo: '' } },
    })
}
</script>

<template>
    <AppLayout :title="operario.nombre">
        <div class="max-w-4xl mx-auto">

            <!-- Header -->
            <div class="flex items-center gap-3 mb-5">
                <a href="/rrhh/operarios" @click.prevent="router.visit('/rrhh/operarios')"
                    class="text-tinta-300 hover:text-tinta-700">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M15 19l-7-7 7-7"/>
                    </svg>
                </a>
                <div class="flex-1 min-w-0">
                    <h1 class="text-xl font-semibold text-tinta-900">{{ operario.nombre }}</h1>
                    <p class="text-xs text-tinta-300">{{ operario.documento }} · {{ operario.especialidad ?? 'Sin especialidad' }}</p>
                </div>
                <span class="text-xs px-2.5 py-1 rounded-full font-semibold"
                    :class="operario.estado === 'activo' ? 'bg-green-100 text-green-700' : 'bg-tinta-100 text-tinta-500'">
                    {{ operario.estado }}
                </span>
                <a :href="`/rrhh/operarios/${operario.id}/editar`"
                    @click.prevent="router.visit(`/rrhh/operarios/${operario.id}/editar`)"
                    class="px-3 py-1.5 rounded-xl border border-tinta-200 text-xs font-medium text-tinta-700 hover:bg-tinta-50">
                    Editar
                </a>
            </div>

            <!-- Tabs -->
            <div class="flex gap-1 bg-tinta-100 p-1 rounded-2xl mb-5 overflow-x-auto">
                <button v-for="t in TABS" :key="t.key"
                    @click="tab = t.key"
                    class="flex-1 min-w-[80px] py-2 px-3 rounded-xl text-xs font-semibold transition-colors whitespace-nowrap"
                    :class="tab === t.key
                        ? 'bg-white text-tinta-900 shadow-sm'
                        : 'text-tinta-400 hover:text-tinta-700'">
                    {{ t.label }}
                </button>
            </div>

            <!-- ─── RESUMEN ─── -->
            <div v-show="tab === 'resumen'" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="bg-white rounded-2xl border border-linea p-5">
                        <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-3">Información</p>
                        <div class="space-y-2 text-sm">
                            <div v-if="operario.cargo" class="flex justify-between">
                                <span class="text-tinta-400">Cargo</span>
                                <span>{{ operario.cargo }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-tinta-400">Teléfono</span>
                                <span>{{ operario.telefono ?? '—' }}</span>
                            </div>
                            <div v-if="operario.email" class="flex justify-between">
                                <span class="text-tinta-400">Email</span>
                                <span class="truncate ml-4 text-right">{{ operario.email }}</span>
                            </div>
                            <div v-if="operario.ciudad" class="flex justify-between">
                                <span class="text-tinta-400">Ciudad</span>
                                <span>{{ operario.ciudad }}</span>
                            </div>
                            <div v-if="operario.fecha_ingreso" class="flex justify-between">
                                <span class="text-tinta-400">Ingreso</span>
                                <span>{{ formatFecha(operario.fecha_ingreso) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-tinta-400">Usuario sistema</span>
                                <span>{{ operario.usuario_nombre ?? '—' }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-2xl border border-linea p-5">
                        <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-3">
                            Bono {{ mesLabel(mes_actual) }} {{ anio_actual }}
                        </p>
                        <div v-if="bono_mes" class="space-y-1.5 text-sm">
                            <div class="flex justify-between">
                                <span class="text-tinta-400">Pasos</span>
                                <span>${{ Number(bono_mes.pasos_valor).toLocaleString('es-CO') }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-tinta-400">Hitos</span>
                                <span>${{ Number(bono_mes.hitos_valor).toLocaleString('es-CO') }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-tinta-400">Extras</span>
                                <span>${{ Number(bono_mes.extras_valor).toLocaleString('es-CO') }}</span>
                            </div>
                            <div class="flex justify-between text-red-600">
                                <span>Penalizaciones</span>
                                <span>-${{ Number(bono_mes.penalizaciones).toLocaleString('es-CO') }}</span>
                            </div>
                            <div class="flex justify-between font-semibold text-base border-t border-linea pt-2 mt-2">
                                <span>Total</span>
                                <span class="text-green-700">${{ Number(bono_mes.total_bono).toLocaleString('es-CO') }}</span>
                            </div>
                        </div>
                        <div v-else class="text-sm text-tinta-300 italic">Sin bono calculado.</div>
                        <button @click="calcularBono"
                            class="mt-4 w-full py-2 rounded-xl text-white text-xs font-semibold"
                            style="background:var(--marca);">
                            Calcular bono del mes
                        </button>
                    </div>
                </div>

                <!-- Historial bonos -->
                <div v-if="historial_bonos.length" class="bg-white rounded-2xl border border-linea overflow-hidden">
                    <div class="px-5 py-3 border-b border-linea">
                        <h2 class="text-sm font-semibold text-tinta-700">Historial de Bonos</h2>
                    </div>
                    <div class="divide-y divide-gray-50">
                        <div v-for="b in historial_bonos" :key="b.id"
                            class="flex items-center justify-between px-5 py-3">
                            <span class="text-sm text-tinta-700">{{ mesLabel(b.periodo_mes) }} {{ b.periodo_anio }}</span>
                            <span class="text-sm font-semibold text-green-700">
                                ${{ Number(b.total_bono).toLocaleString('es-CO') }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ─── PUNTOS ─── -->
            <div v-show="tab === 'puntos'" class="space-y-4">
                <!-- Badge nivel -->
                <div class="flex items-center gap-3 p-4 rounded-2xl border-2"
                    :style="{ borderColor: nivelColor, background: nivelColor + '15' }">
                    <div class="text-4xl leading-none">{{ nivelIcono }}</div>
                    <div class="flex-1">
                        <p class="text-xs text-tinta-400 uppercase tracking-wide">Nivel actual</p>
                        <p class="text-2xl font-semibold" :style="{ color: nivelColor }">
                            {{ operario.nivel ?? 'Bronce' }}
                        </p>
                        <p class="text-sm text-tinta-500">{{ operario.puntos_totales ?? 0 }} puntos acumulados</p>
                    </div>
                </div>

                <!-- Agregar puntos manual -->
                <div class="bg-white rounded-2xl border border-linea p-5">
                    <h3 class="text-sm font-semibold text-tinta-700 mb-4">Ajuste manual de puntos</h3>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">
                                Puntos (positivo = suma, negativo = resta)
                            </label>
                            <input v-model.number="puntosManualForm.puntos" type="number"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Concepto *</label>
                            <input v-model="puntosManualForm.concepto" type="text" placeholder="Motivo del ajuste"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2" />
                        </div>
                        <button @click="agregarPuntosManual" :disabled="guardandoPuntos"
                            class="w-full py-2.5 rounded-xl text-white text-sm font-semibold disabled:opacity-60"
                            style="background:var(--marca);">
                            {{ guardandoPuntos ? 'Guardando…' : 'Aplicar ajuste' }}
                        </button>
                    </div>
                </div>

                <!-- Historial -->
                <div class="bg-white rounded-2xl border border-linea overflow-hidden">
                    <div class="px-5 py-3 border-b border-linea">
                        <h2 class="text-sm font-semibold text-tinta-700">Historial de puntos</h2>
                    </div>
                    <div v-if="!historial_puntos?.length" class="py-10 text-center text-sm text-tinta-300">
                        Sin movimientos de puntos aún.
                    </div>
                    <div v-else class="divide-y divide-gray-50">
                        <div v-for="p in historial_puntos" :key="p.id"
                            class="flex items-center justify-between px-5 py-3 gap-3">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm text-tinta-700 truncate">{{ p.concepto }}</p>
                                <p class="text-xs text-tinta-300 mt-0.5">{{ p.created_at }}</p>
                            </div>
                            <span class="font-semibold text-sm shrink-0"
                                :class="p.puntos > 0 ? 'text-green-600' : 'text-red-500'">
                                {{ p.puntos > 0 ? '+' : '' }}{{ p.puntos }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ─── DOCUMENTOS ─── -->
            <div v-show="tab === 'documentos'" class="space-y-4">
                <!-- Seguridad social -->
                <div class="bg-white rounded-2xl border border-linea p-5">
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-3">Seguridad Social</p>
                    <div class="space-y-3 text-sm">
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <p class="text-xs text-tinta-300">EPS</p>
                                <p class="font-medium">{{ operario.nombre_eps || '—' }}</p>
                                <p class="text-xs text-tinta-300">{{ operario.numero_eps || '' }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-tinta-300">Pensión</p>
                                <p class="font-medium">{{ operario.nombre_pension || '—' }}</p>
                                <p class="text-xs text-tinta-300">{{ operario.numero_pension || '' }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Info bancaria -->
                <div class="bg-white rounded-2xl border border-linea p-5">
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-3">Información Bancaria</p>
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <span class="text-tinta-400">Banco</span>
                            <span>{{ operario.banco || '—' }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-tinta-400">Tipo de cuenta</span>
                            <span class="capitalize">{{ operario.tipo_cuenta || '—' }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-tinta-400">Número</span>
                            <span>{{ operario.numero_cuenta_bancaria || '—' }}</span>
                        </div>
                    </div>
                </div>

                <!-- Archivos -->
                <div class="bg-white rounded-2xl border border-linea overflow-hidden">
                    <div class="px-5 py-3 border-b border-linea flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-tinta-700">Archivos</h2>
                        <a :href="`/rrhh/operarios/${operario.id}/editar`"
                            @click.prevent="router.visit(`/rrhh/operarios/${operario.id}/editar`)"
                            class="text-xs text-blue-600 underline">Gestionar</a>
                    </div>
                    <div class="divide-y divide-gray-50">
                        <div v-for="doc in DOCS_LISTA" :key="doc.campo"
                            class="flex items-center justify-between px-5 py-3">
                            <span class="text-sm text-tinta-700">{{ doc.label }}</span>
                            <span v-if="operario[doc.campo]"
                                class="text-xs text-green-600 font-medium">
                                <a :href="`/storage/${operario[doc.campo]}`" target="_blank" class="underline">Ver</a>
                            </span>
                            <span v-else class="text-xs text-tinta-300">Pendiente</span>
                        </div>
                        <div v-if="operario.archivo_otros?.length" class="px-5 py-3">
                            <p class="text-sm text-tinta-700 mb-1">Otros documentos</p>
                            <div v-for="(d, i) in operario.archivo_otros" :key="i" class="text-xs">
                                <a :href="`/storage/${d.path}`" target="_blank" class="text-blue-600 underline">{{ d.nombre }}</a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Gamificación -->
                <div class="bg-white rounded-2xl border border-linea p-5">
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-3">Gamificación</p>
                    <div class="flex items-center gap-4">
                        <div class="text-center">
                            <p class="text-2xl font-semibold text-blue-600">{{ operario.puntos_totales ?? 0 }}</p>
                            <p class="text-xs text-tinta-300">Puntos totales</p>
                        </div>
                        <div class="text-center">
                            <p class="text-lg font-semibold text-tinta-700">{{ operario.nivel || '—' }}</p>
                            <p class="text-xs text-tinta-300">Nivel</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ─── TRABAJOS ─── -->
            <div v-show="tab === 'trabajos'" class="space-y-4">
                <div class="bg-white rounded-2xl border border-linea overflow-hidden">
                    <div class="px-5 py-3 border-b border-linea">
                        <h2 class="text-sm font-semibold text-tinta-700">
                            Pasos completados — {{ mesLabel(mes_actual) }} {{ anio_actual }}
                        </h2>
                    </div>
                    <div v-if="!pasos_mes.length" class="py-10 text-center text-sm text-tinta-300">
                        Sin pasos completados este mes.
                    </div>
                    <div v-else class="divide-y divide-gray-50">
                        <div v-for="paso in pasos_mes" :key="paso.id" class="px-5 py-3">
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-tinta-900">{{ paso.nombre }}</p>
                                    <p class="text-xs text-tinta-300 mt-0.5 truncate">
                                        OP: {{ paso.trabajo?.op_item?.op?.numero ?? '—' }}
                                    </p>
                                </div>
                                <div class="text-right shrink-0">
                                    <p class="text-xs text-tinta-400">{{ formatFecha(paso.completado_at) }}</p>
                                    <p v-if="paso.tiempo_minutos" class="text-xs text-blue-600 mt-0.5">
                                        {{ paso.tiempo_minutos }} min
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ─── DISCIPLINA ─── -->
            <div v-show="tab === 'disciplina'" class="space-y-4">
                <!-- Lista disciplinas -->
                <div class="bg-white rounded-2xl border border-linea overflow-hidden">
                    <div class="px-5 py-3 border-b border-linea">
                        <h2 class="text-sm font-semibold text-tinta-700">Registros de disciplina</h2>
                    </div>
                    <div v-if="!disciplinas.length" class="py-10 text-center text-sm text-tinta-300">Sin registros.</div>
                    <div v-else class="divide-y divide-gray-50">
                        <div v-for="d in disciplinas" :key="d.id" class="px-5 py-4">
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="text-xs px-2 py-0.5 rounded-full font-semibold"
                                            :class="d.firmado ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'">
                                            {{ d.firmado ? 'Firmado' : 'Pendiente' }}
                                        </span>
                                        <span class="text-xs text-tinta-400">{{ d.tipo_label }}</span>
                                    </div>
                                    <p class="text-sm text-tinta-700">{{ d.descripcion }}</p>
                                    <p class="text-xs text-tinta-300 mt-1">{{ formatFecha(d.fecha) }}</p>
                                </div>
                                <button v-if="!d.firmado"
                                    @click="firmarDisciplina(d)"
                                    class="shrink-0 px-3 py-1.5 rounded-xl border border-tinta-200 text-xs font-medium text-tinta-700 hover:bg-tinta-50">
                                    Firmar
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Nuevo disciplina -->
                <div class="bg-white rounded-2xl border border-linea p-5">
                    <h3 class="text-sm font-semibold text-tinta-700 mb-4">Nuevo registro</h3>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Tipo *</label>
                            <select v-model="disciplinaForm.tipo"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2">
                                <option value="llamado_atencion">Llamado de atención</option>
                                <option value="memorando">Memorando</option>
                                <option value="falla">Falla</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Descripción *</label>
                            <textarea v-model="disciplinaForm.descripcion" rows="2"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 resize-none" />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Fecha *</label>
                                <input v-model="disciplinaForm.fecha" type="date"
                                    class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2" />
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Penalización $</label>
                                <input v-model="disciplinaForm.penalizacion_valor" type="number" min="0"
                                    class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2" />
                            </div>
                        </div>
                        <button @click="storeDisciplina"
                            class="w-full py-2.5 rounded-xl text-white text-sm font-semibold"
                            style="background:var(--marca);">
                            Registrar
                        </button>
                    </div>
                </div>
            </div>

            <!-- ─── BONOS / HITOS ─── -->
            <div v-show="tab === 'bonos'" class="space-y-4">
                <!-- Hitos del mes -->
                <div class="bg-white rounded-2xl border border-linea overflow-hidden">
                    <div class="px-5 py-3 border-b border-linea flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-tinta-700">Hitos — {{ mesLabel(mes_actual) }}</h2>
                        <button @click="calcularBono"
                            class="px-3 py-1.5 rounded-xl text-white text-xs font-semibold"
                            style="background:var(--marca);">
                            Calcular bono
                        </button>
                    </div>
                    <div v-if="!hitos_mes.length" class="py-8 text-center text-sm text-tinta-300">Sin hitos este mes.</div>
                    <div v-else class="divide-y divide-gray-50">
                        <div v-for="h in hitos_mes" :key="h.id" class="flex items-center px-5 py-3 gap-3">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-tinta-900">{{ h.nombre }}</p>
                                <p class="text-xs text-tinta-300">Meta: {{ h.meta_valor }} {{ h.meta_tipo }}</p>
                            </div>
                            <span class="text-xs px-2 py-0.5 rounded-full font-semibold shrink-0"
                                :class="h.cumplido ? 'bg-green-100 text-green-700' : 'bg-tinta-100 text-tinta-400'">
                                {{ h.cumplido ? 'Cumplido' : 'Pendiente' }}
                            </span>
                            <span class="text-xs font-semibold text-tinta-700 shrink-0">
                                ${{ Number(h.valor_bono).toLocaleString('es-CO') }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Agregar hito -->
                <div class="bg-white rounded-2xl border border-linea p-5">
                    <h3 class="text-sm font-semibold text-tinta-700 mb-4">Nuevo hito manual</h3>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Nombre *</label>
                            <input v-model="hitoForm.nombre" type="text"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2" />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Meta tipo</label>
                                <select v-model="hitoForm.meta_tipo"
                                    class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2">
                                    <option value="pasos">Pasos</option>
                                    <option value="tiempo">Tiempo (h)</option>
                                    <option value="ops">OPs</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Meta valor</label>
                                <input v-model.number="hitoForm.meta_valor" type="number" min="0"
                                    class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2" />
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Valor bono ($)</label>
                            <input v-model.number="hitoForm.valor_bono" type="number" min="0"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2" />
                        </div>
                        <button @click="storeHito"
                            class="w-full py-2.5 rounded-xl text-white text-sm font-semibold"
                            style="background:var(--marca);">
                            Agregar hito
                        </button>
                    </div>
                </div>
            </div>

            <!-- ─── EXTRAS Y PERMISOS ─── -->
            <div v-show="tab === 'extras'" class="space-y-4">
                <!-- Horas extras del mes -->
                <div class="bg-white rounded-2xl border border-linea overflow-hidden">
                    <div class="px-5 py-3 border-b border-linea">
                        <h2 class="text-sm font-semibold text-tinta-700">Horas extras — {{ mesLabel(mes_actual) }}</h2>
                    </div>
                    <div v-if="!horas_extras_mes.length" class="py-8 text-center text-sm text-tinta-300">Sin horas extras este mes.</div>
                    <div v-else class="divide-y divide-gray-50">
                        <div v-for="h in horas_extras_mes" :key="h.id" class="flex items-center px-5 py-3 gap-3">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-tinta-900 capitalize">{{ h.tipo }}</p>
                                <p class="text-xs text-tinta-300">{{ formatFecha(h.fecha) }}</p>
                            </div>
                            <span class="text-sm font-semibold text-tinta-700 shrink-0">{{ h.horas }}h</span>
                        </div>
                    </div>
                </div>

                <!-- Agregar horas extras -->
                <div class="bg-white rounded-2xl border border-linea p-5">
                    <h3 class="text-sm font-semibold text-tinta-700 mb-4">Registrar horas extras</h3>
                    <div class="space-y-3">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Fecha *</label>
                                <input v-model="extraForm.fecha" type="date"
                                    class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2" />
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Tipo *</label>
                                <select v-model="extraForm.tipo"
                                    class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2">
                                    <option value="diurna">Diurna</option>
                                    <option value="nocturna">Nocturna</option>
                                    <option value="dominical">Dominical</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Horas *</label>
                            <input v-model.number="extraForm.horas" type="number" min="0.5" max="24" step="0.5"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Observación</label>
                            <input v-model="extraForm.observacion" type="text"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2" />
                        </div>
                        <button @click="storeExtra"
                            class="w-full py-2.5 rounded-xl text-white text-sm font-semibold"
                            style="background:var(--marca);">
                            Registrar horas
                        </button>
                    </div>
                </div>

                <!-- Permisos del mes -->
                <div class="bg-white rounded-2xl border border-linea overflow-hidden">
                    <div class="px-5 py-3 border-b border-linea">
                        <h2 class="text-sm font-semibold text-tinta-700">Permisos — {{ mesLabel(mes_actual) }}</h2>
                    </div>
                    <div v-if="!permisos_mes.length" class="py-6 text-center text-sm text-tinta-300">Sin permisos este mes.</div>
                    <div v-else class="divide-y divide-gray-50">
                        <div v-for="p in permisos_mes" :key="p.id" class="flex items-center px-5 py-3 gap-3">
                            <div class="flex-1 min-w-0">
                                <p class="text-xs text-tinta-700">
                                    {{ formatFecha(p.fecha_inicio) }} → {{ formatFecha(p.fecha_fin) }}
                                </p>
                                <p v-if="p.motivo" class="text-xs text-tinta-300 truncate mt-0.5">{{ p.motivo }}</p>
                            </div>
                            <span class="text-xs px-2 py-0.5 rounded-full bg-blue-100 text-blue-700 shrink-0">
                                {{ p.aprobado ? 'Aprobado' : 'Pendiente' }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Agregar permiso -->
                <div class="bg-white rounded-2xl border border-linea p-5">
                    <h3 class="text-sm font-semibold text-tinta-700 mb-4">Registrar permiso</h3>
                    <div class="space-y-3">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Desde *</label>
                                <input v-model="permisoForm.fecha_inicio" type="date"
                                    class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2" />
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Hasta *</label>
                                <input v-model="permisoForm.fecha_fin" type="date"
                                    class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2" />
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Motivo</label>
                            <input v-model="permisoForm.motivo" type="text"
                                class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-2" />
                        </div>
                        <button @click="storePermiso"
                            class="w-full py-2.5 rounded-xl text-white text-sm font-semibold"
                            style="background:var(--marca);">
                            Registrar permiso
                        </button>
                    </div>
                </div>
            </div>

        </div>
    </AppLayout>
</template>
