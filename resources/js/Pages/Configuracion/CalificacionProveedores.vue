<script setup>
import { computed } from 'vue'
import { router, useForm, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

// Cuánto pesa cada cosa en la nota de un proveedor. La nota la calcula el sistema con lo que de
// verdad pasó (ver `CalificacionProveedorService`); aquí solo se decide qué importa más.
const props = defineProps({
    ajustes:     { type: Object, required: true },
    componentes: { type: Array,  default: () => [] },
    defectos:    { type: Object, default: () => ({}) },
})

const page = usePage()
const puedeEditar = computed(() => (page.props.auth?.permisosLista ?? []).includes('configuracion.editar'))

const form = useForm({
    pesos:          { ...props.ajustes.pesos },
    gracia_dias:    props.ajustes.gracia_dias,
    muestra_minima: props.ajustes.muestra_minima,
})

const total = computed(() => props.componentes.reduce((t, c) => t + (Number(form.pesos[c.clave]) || 0), 0))
// Lo que cada componente representa en el promedio, para que se vea que 30/25/25/20 son porcentajes.
const parte = (clave) => total.value > 0 ? Math.round(((Number(form.pesos[clave]) || 0) / total.value) * 100) : 0

function restablecer() {
    props.componentes.forEach(c => { form.pesos[c.clave] = c.defecto })
    form.gracia_dias    = props.defectos.gracia_dias
    form.muestra_minima = props.defectos.muestra_minima
}

function guardar() {
    form.post('/configuracion/calificacion-proveedores', { preserveScroll: true })
}

const ic = 'w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm focus:ring-4 focus:ring-[var(--marca-suave)] focus:outline-none'
</script>

<template>
    <AppLayout title="Calificación de proveedores">
        <div class="max-w-2xl mx-auto space-y-4 pb-8">

            <a href="/configuracion" @click.prevent="router.visit('/configuracion')"
                class="inline-flex items-center gap-1.5 text-sm text-tinta-400 hover:text-tinta-700">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                </svg>
                Configuración
            </a>

            <form @submit.prevent="guardar" class="bg-superficie rounded-xl border border-linea p-4 space-y-5">
                <div>
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Qué pesa en la nota</p>
                    <p class="text-xs text-tinta-400 mt-1">
                        La nota de 0 a 100 la calcula el sistema con las órdenes del último año. Aquí decides qué
                        te importa más. Los pesos no tienen que sumar 100: se reparten en proporción.
                    </p>
                </div>

                <div v-for="c in componentes" :key="c.clave">
                    <div class="flex items-baseline justify-between gap-2">
                        <label :for="`peso_${c.clave}`" class="text-sm font-medium text-tinta-700">{{ c.etiqueta }}</label>
                        <span class="text-xs text-tinta-400">{{ parte(c.clave) }}% de la nota · de fábrica {{ c.defecto }}</span>
                    </div>
                    <input :id="`peso_${c.clave}`" v-model.number="form.pesos[c.clave]" type="number" min="0" max="100" step="1"
                        :disabled="!puedeEditar" :class="[ic, 'mt-1']" />
                    <p v-if="form.errors[`pesos.${c.clave}`]" class="text-xs text-aviso-rojo mt-1">{{ form.errors[`pesos.${c.clave}`] }}</p>
                </div>
                <p v-if="form.errors.pesos" class="text-xs text-aviso-rojo">{{ form.errors.pesos }}</p>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-1">
                    <div>
                        <label for="gracia" class="text-sm font-medium text-tinta-700">Días de gracia</label>
                        <input id="gracia" v-model.number="form.gracia_dias" type="number" min="0" max="30" step="1"
                            :disabled="!puedeEditar" :class="[ic, 'mt-1']" />
                        <p class="text-xs text-tinta-400 mt-1">Una entrega que llega tarde dentro de este plazo vale la mitad; pasado el plazo, nada.</p>
                    </div>
                    <div>
                        <label for="muestra" class="text-sm font-medium text-tinta-700">Órdenes mínimas para dar nota</label>
                        <input id="muestra" v-model.number="form.muestra_minima" type="number" min="1" max="50" step="1"
                            :disabled="!puedeEditar" :class="[ic, 'mt-1']" />
                        <p class="text-xs text-tinta-400 mt-1">Con menos órdenes que estas el proveedor sale «Sin calificar» en vez de una nota que no se sostiene.</p>
                    </div>
                </div>

                <div v-if="puedeEditar" class="flex flex-wrap items-center justify-end gap-2 pt-1">
                    <button type="button" @click="restablecer" class="text-sm text-tinta-400 hover:text-tinta-700 px-3 py-2">
                        Volver a los de fábrica
                    </button>
                    <button type="submit" :disabled="form.processing || total <= 0"
                        class="text-sm font-semibold px-4 py-2 rounded-lg text-white disabled:opacity-60" style="background:var(--marca);">
                        {{ form.processing ? 'Guardando…' : 'Guardar' }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
