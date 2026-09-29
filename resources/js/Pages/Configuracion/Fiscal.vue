<script setup>
import { computed } from 'vue'
import { router, useForm, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import LeerRut from '@/Components/LeerRut.vue'
import DatosFiscales from '@/Components/DatosFiscales.vue'

const props = defineProps({
    empresa:  { type: Object, default: () => ({}) },
    fiscal:   { type: Object, default: () => ({}) },
    catalogo: { type: Array,  default: () => [] },
})

const page = usePage()
const puedeEditar = computed(() => (page.props.auth?.permisosLista ?? []).includes('configuracion.editar'))

const form = useForm({
    empresa: {
        nombre:    props.empresa.nombre ?? '',
        nit:       props.empresa.nit ?? '',
        ciudad:    props.empresa.ciudad ?? '',
        direccion: props.empresa.direccion ?? '',
        telefono:  props.empresa.telefono ?? '',
        email:     props.empresa.email ?? '',
    },
    responsabilidades: props.fiscal.responsabilidades ?? [],
    actividad:         props.fiscal.actividad ?? '',
    uvt:               props.fiscal.uvt ?? '',
    conceptos:         (props.fiscal.conceptos ?? []).map(c => ({ ...c })),
    reteiva_pct:       props.fiscal.reteiva_pct ?? 15,
    reteica_por_mil:   props.fiscal.reteica_por_mil ?? 0,
})

// DatosFiscales trabaja con los nombres de la ficha del cliente; aquí se le presta un
// objeto que lee y escribe en este formulario.
const perfil = {
    get responsabilidades_fiscales() { return form.responsabilidades },
    set responsabilidades_fiscales(v) { form.responsabilidades = v },
    get actividad_economica() { return form.actividad },
    set actividad_economica(v) { form.actividad = v },
}

function usarRut({ datos }) {
    for (const [campo, valor] of Object.entries(datos.empresa ?? {})) {
        if (valor) form.empresa[campo] = valor
    }
    if (datos.responsabilidades?.length) form.responsabilidades = datos.responsabilidades
    if (datos.actividad) form.actividad = datos.actividad
}

function guardar() {
    form.post('/configuracion/fiscal', { preserveScroll: true })
}

const ic = 'w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm focus:ring-4 focus:ring-[var(--marca-suave)] focus:outline-none'
const pesos = (v) => new Intl.NumberFormat('es-CO', { maximumFractionDigits: 0 }).format(Math.round(Number(v) || 0))
</script>

<template>
    <AppLayout title="Perfil fiscal">
        <form @submit.prevent="guardar" class="max-w-2xl mx-auto space-y-4 pb-8">

            <a href="/configuracion" @click.prevent="router.visit('/configuracion')"
                class="inline-flex items-center gap-1.5 text-sm text-tinta-400 hover:text-tinta-700">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                </svg>
                Configuración
            </a>

            <!-- La empresa -->
            <div class="bg-superficie rounded-xl border border-linea p-4 space-y-3">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">La empresa</p>
                        <p class="text-xs text-tinta-400 mt-1">Es lo que sale en el encabezado de cotizaciones, órdenes y remisiones.</p>
                    </div>
                    <LeerRut v-if="puedeEditar" url="/configuracion/fiscal/leer-rut" texto="Llenar con el RUT de la empresa" @leido="usarRut" class="max-w-full"/>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-medium text-tinta-700 mb-1">Razón social</label>
                        <input v-model="form.empresa.nombre" type="text" :class="ic"/>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-tinta-700 mb-1">NIT</label>
                        <input v-model="form.empresa.nit" type="text" :class="ic" placeholder="900123456-7"/>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-tinta-700 mb-1">Ciudad</label>
                        <input v-model="form.empresa.ciudad" type="text" :class="ic"/>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-medium text-tinta-700 mb-1">Dirección</label>
                        <input v-model="form.empresa.direccion" type="text" :class="ic"/>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-tinta-700 mb-1">Teléfono</label>
                        <input v-model="form.empresa.telefono" type="text" :class="ic"/>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-tinta-700 mb-1">Correo</label>
                        <input v-model="form.empresa.email" type="email" :class="ic"/>
                        <p v-if="form.errors['empresa.email']" class="text-xs text-aviso-rojo mt-1">{{ form.errors['empresa.email'] }}</p>
                    </div>
                </div>
            </div>

            <!-- Sus responsabilidades -->
            <div class="bg-superficie rounded-xl border border-linea p-4 space-y-3">
                <div>
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Responsabilidades de la empresa</p>
                    <p class="text-xs text-tinta-400 mt-1">
                        Deciden qué le retienen sus clientes: a una empresa autorretenedora (15) o del
                        régimen simple (47) no le practican retención en la fuente, y a una que no es
                        responsable de IVA (48) no le retienen IVA.
                    </p>
                </div>
                <DatosFiscales :modelo="perfil" :catalogo="catalogo" :con-ica="false"/>
            </div>

            <!-- Reglas de retención -->
            <div class="bg-superficie rounded-xl border border-linea p-4 space-y-4">
                <div>
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Reglas de retención</p>
                    <div class="mt-2 rounded-lg bg-pastel-ambar border border-borde-aviso-ambar px-3 py-2 text-xs text-aviso-ambar">
                        Las tarifas, las bases y la UVT cambian por decreto y cada año. Los valores que trae
                        Briela son solo un punto de partida: confírmalos con tu contador antes de usarlos.
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-tinta-700 mb-1">Valor de la UVT de este año</label>
                    <input v-model="form.uvt" type="number" step="0.01" min="0" :class="ic" placeholder="Ej: 49799"/>
                    <p class="text-xs text-tinta-400 mt-1">
                        Con ella se sabe si una venta pasa la base mínima. Sin ella, se estima como si no
                        hubiera base.
                    </p>
                </div>

                <div class="space-y-2">
                    <p class="text-sm font-medium text-tinta-700">Retención en la fuente</p>
                    <div v-for="c in form.conceptos" :key="c.clave" class="rounded-lg border border-linea p-3">
                        <p class="text-sm font-semibold text-tinta-700 mb-2">{{ c.nombre }}
                            <span class="text-xs font-normal text-tinta-400">— {{ c.clave === 'servicios' ? 'los productos de tipo servicio' : 'todo lo demás' }}</span>
                        </p>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-xs text-tinta-400 mb-1">Tarifa (%)</label>
                                <input v-model.number="c.tarifa" type="number" step="0.01" min="0" max="100" :class="ic"/>
                            </div>
                            <div>
                                <label class="block text-xs text-tinta-400 mb-1">Base mínima (UVT)</label>
                                <input v-model.number="c.base_uvt" type="number" step="0.01" min="0" :class="ic"/>
                            </div>
                        </div>
                        <p v-if="Number(form.uvt) > 0" class="text-xs text-tinta-400 mt-1">
                            Aplica desde ${{ pesos(c.base_uvt * form.uvt) }} antes de IVA.
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-tinta-700 mb-1">Retención de IVA (% del IVA)</label>
                        <input v-model.number="form.reteiva_pct" type="number" step="0.01" min="0" max="100" :class="ic"/>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-tinta-700 mb-1">Retención de ICA (por mil)</label>
                        <input v-model.number="form.reteica_por_mil" type="number" step="0.01" min="0" max="100" :class="ic"/>
                        <p class="text-xs text-tinta-400 mt-1">La de tu actividad en tu municipio. Solo se aplica a los clientes marcados como retenedores de ICA.</p>
                    </div>
                </div>
            </div>

            <div v-if="Object.keys(form.errors).length" class="bg-pastel-rojo border border-borde-aviso-rojo rounded-xl p-3 text-xs text-aviso-rojo">
                <p v-for="(m, k) in form.errors" :key="k">{{ m }}</p>
            </div>

            <button v-if="puedeEditar" type="submit" :disabled="form.processing"
                class="w-full py-2.5 rounded-lg text-sm font-medium text-white disabled:opacity-60" style="background:var(--marca);">
                {{ form.processing ? 'Guardando…' : 'Guardar perfil fiscal' }}
            </button>
        </form>
    </AppLayout>
</template>
