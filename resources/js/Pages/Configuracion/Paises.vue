<script setup>
import { computed, ref, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { formatPct } from '@/formato'

const props = defineProps({
    pais:     { type: Object, required: true },
    ajustado: { type: Boolean, default: false },
    en_uso:   { type: Array,  default: () => [] },
    paises:   { type: Array,  default: () => [] },
    casa:     { type: String, default: 'CO' },
})

const page = usePage()
const puedeEditar = computed(() => (page.props.auth?.permisosLista ?? []).includes('configuracion.editar'))

// Los países con sede primero; el resto, en orden alfabético.
const opciones = computed(() => [...props.paises].sort((a, b) =>
    (props.en_uso.includes(b.iso) - props.en_uso.includes(a.iso)) || a.nombre.localeCompare(b.nombre)))

function elegir(iso) {
    router.get('/configuracion/paises', { pais: iso }, { preserveScroll: true, preserveState: false })
}

// ─── Impuestos editables ─────────────────────────────────────────────────────
// Las tarifas se escriben separadas por coma: «19, 5, 0».
function aFormulario() {
    return {
        impuestos: props.pais.impuestos.map(i => ({
            clave: i.clave, nombre: i.nombre, tarifasTexto: (i.tarifas ?? []).join(', '),
            defecto: i.defecto ?? '', nota: i.nota ?? '',
        })),
        iva_descontable: !! props.pais.importacion?.iva_descontable,
    }
}

const form = ref(aFormulario())
watch(() => props.pais, () => { form.value = aFormulario() })

const guardando = ref(false)
function guardar() {
    const impuestos = form.value.impuestos.map(i => ({
        clave: i.clave, nombre: i.nombre, nota: i.nota || null,
        defecto: i.defecto === '' ? null : Number(i.defecto),
        tarifas: i.tarifasTexto.split(/[,;\s]+/).filter(Boolean).map(t => Number(t.replace(',', '.'))).filter(n => Number.isFinite(n)),
    }))

    router.put(`/configuracion/paises/${props.pais.iso}`, { impuestos, iva_descontable: form.value.iva_descontable }, {
        preserveScroll: true,
        onStart:  () => { guardando.value = true },
        onFinish: () => { guardando.value = false },
    })
}

function restablecer() {
    if (! confirm(`¿Volver a lo que trae el paquete de ${props.pais.nombre}? Se pierden tus ajustes.`)) return
    router.delete(`/configuracion/paises/${props.pais.iso}`, { preserveScroll: true })
}

const ic = 'w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm focus:ring-4 focus:ring-[var(--marca-suave)] focus:outline-none'
</script>

<template>
    <AppLayout title="Países">
        <div class="max-w-2xl mx-auto space-y-4 pb-8">

            <a href="/configuracion" @click.prevent="router.visit('/configuracion')"
                class="inline-flex items-center gap-1.5 text-sm text-tinta-400 hover:text-tinta-700">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                </svg>
                Configuración
            </a>

            <!-- Selector -->
            <div class="bg-superficie rounded-xl border border-linea p-4 space-y-2">
                <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">País</p>
                <select :value="pais.iso" @change="elegir($event.target.value)" :class="ic" aria-label="País">
                    <option v-for="p in opciones" :key="p.iso" :value="p.iso">
                        {{ p.nombre }}{{ en_uso.includes(p.iso) ? ' · con sede' : '' }}{{ p.paquete ? '' : ' · sin paquete' }}
                    </option>
                </select>
                <p class="text-xs text-tinta-400">
                    El país de cada sede se elige en
                    <a href="/configuracion/sedes" @click.prevent="router.visit('/configuracion/sedes')" class="font-semibold underline underline-offset-2">Sedes</a>.
                    La casa —la sede principal— está en {{ paises.find(p => p.iso === casa)?.nombre }}.
                </p>
            </div>

            <!-- Aviso del paquete -->
            <div v-if="! pais.con_paquete" class="rounded-xl border px-4 py-3 bg-pastel-ambar border-borde-aviso-ambar text-aviso-ambar">
                <p class="text-sm font-semibold">{{ pais.nombre }} todavía no tiene paquete</p>
                <p class="text-xs mt-1">
                    Funciona igual, con identificaciones genéricas. Escribe abajo los impuestos que aplican allí y
                    confírmalos con tu contador.
                </p>
            </div>
            <div v-else-if="! pais.revisado" class="rounded-xl border px-4 py-3 bg-pastel-ambar border-borde-aviso-ambar text-aviso-ambar">
                <p class="text-sm font-semibold">Valores de partida: confírmalos con tu contador</p>
                <p class="text-xs mt-1">
                    Este paquete no ha sido revisado por un contador local. Una tarifa mal puesta termina en una
                    cotización o una factura mal hecha.
                </p>
            </div>

            <!-- Lo que exige -->
            <div class="bg-superficie rounded-xl border border-linea p-4 space-y-3">
                <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Lo que exige {{ pais.nombre }}</p>
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                    <div>
                        <dt class="text-xs text-tinta-400">Documento fiscal</dt>
                        <dd class="text-tinta-900">{{ pais.documento_fiscal }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-tinta-400">Autoridad tributaria</dt>
                        <dd class="text-tinta-900">{{ pais.autoridad ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-tinta-400">Moneda de sus documentos</dt>
                        <dd class="text-tinta-900">{{ pais.moneda }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-tinta-400">Retenciones</dt>
                        <dd class="text-tinta-900">{{ pais.retenciones ? 'Se estiman en la cotización' : 'Briela no las calcula' }}</dd>
                    </div>
                </dl>
                <p v-if="pais.facturacion" class="text-xs text-tinta-400">
                    <span class="font-semibold">Factura legal:</span> {{ pais.facturacion }}
                    Briela prepara los datos; la emisión la hace el proveedor autorizado de cada país.
                </p>
                <p v-for="(n, i) in pais.notas" :key="i" class="text-xs text-tinta-400">{{ n }}</p>
            </div>

            <!-- Identificaciones -->
            <div class="bg-superficie rounded-xl border border-linea p-4">
                <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-2">Documentos de identidad que acepta</p>
                <div class="divide-y divide-separador">
                    <div v-for="i in pais.identificaciones" :key="i.codigo" class="py-1.5 flex flex-wrap justify-between gap-2 text-sm">
                        <span class="text-tinta-700"><span class="font-mono text-xs bg-tinta-100 rounded px-1.5 py-0.5 mr-2">{{ i.codigo }}</span>{{ i.nombre }}</span>
                        <span class="text-xs text-tinta-400">{{ i.juridica ? 'Empresas' : 'Personas' }}{{ i.dv ? ' · con dígito de verificación' : '' }}</span>
                    </div>
                </div>
            </div>

            <!-- Impuestos -->
            <form @submit.prevent="guardar" class="bg-superficie rounded-xl border border-linea p-4 space-y-4">
                <div>
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Impuestos en las cotizaciones</p>
                    <p class="text-xs text-tinta-400 mt-1">
                        Las tarifas que se ofrecen en cada ítem. Si cambian por decreto, se corrigen aquí sin esperar una
                        versión nueva; un paquete actualizado no pisa lo que ajustes.
                    </p>
                </div>

                <div v-for="(i, idx) in form.impuestos" :key="i.clave" class="space-y-2 rounded-xl border border-linea p-3">
                    <div class="grid grid-cols-3 gap-2">
                        <div>
                            <label class="block text-xs text-tinta-400 mb-1">Nombre</label>
                            <input v-model="i.nombre" type="text" maxlength="40" :class="ic" :disabled="! puedeEditar"/>
                        </div>
                        <div class="col-span-2">
                            <label class="block text-xs text-tinta-400 mb-1">Tarifas (%), separadas por coma</label>
                            <input v-model="i.tarifasTexto" type="text" inputmode="decimal" placeholder="19, 5, 0" :class="ic" :disabled="! puedeEditar"/>
                        </div>
                    </div>
                    <p v-if="i.nota" class="text-xs text-tinta-400">{{ i.nota }}</p>
                    <p class="text-[11px] text-tinta-300">
                        Se ofrecen: {{ i.tarifasTexto.split(/[,;\s]+/).filter(Boolean).map(t => formatPct(Number(t.replace(',', '.')))).join('%, ') }}%
                    </p>
                </div>

                <div v-if="pais.otros?.length" class="rounded-xl bg-tinta-50 p-3 space-y-1">
                    <p class="text-xs font-semibold text-tinta-500">Otros tributos que conviene conocer (no se calculan en la cotización)</p>
                    <p v-for="o in pais.otros" :key="o.clave" class="text-xs text-tinta-400">
                        <span class="font-semibold">{{ o.nombre }} {{ formatPct(o.tarifa) }}%:</span> {{ o.nota }}
                    </p>
                </div>

                <label class="flex items-start gap-2">
                    <input v-model="form.iva_descontable" type="checkbox" class="rounded mt-0.5" :disabled="! puedeEditar"/>
                    <span class="text-sm text-tinta-700">
                        El impuesto pagado al importar se recupera (descontable)
                        <span class="block text-xs text-tinta-400">{{ pais.importacion?.nota }} Si no se recupera, se suma al costo de lo importado.</span>
                    </span>
                </label>

                <div v-if="puedeEditar" class="flex flex-wrap gap-2">
                    <button type="submit" :disabled="guardando"
                        class="px-4 py-2 rounded-lg text-sm font-medium text-white disabled:opacity-60" style="background:var(--marca);">
                        {{ guardando ? 'Guardando…' : 'Guardar ajustes' }}
                    </button>
                    <button v-if="ajustado" type="button" @click="restablecer"
                        class="px-4 py-2 rounded-lg text-sm font-medium border border-linea text-tinta-700 hover:bg-realce">
                        Volver a lo del paquete
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
