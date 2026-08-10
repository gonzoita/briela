<script setup>
import { ref } from 'vue'
import { router, Head } from '@inertiajs/vue3'

const props = defineProps({
    codigoBuscado: String,
    certificado:   Object,   // { valido, codigo, estudiante, curso, fecha_emision } | null
    noEncontrado:  Boolean,
})

const codigo = ref(props.codigoBuscado ?? '')

function verificar() {
    if (!codigo.value.trim()) return
    router.visit(`/verificar-certificado/${encodeURIComponent(codigo.value.trim())}`)
}
</script>

<template>
    <Head title="Verificar certificado" />

    <div class="min-h-screen flex flex-col" style="background:#F8F9FA;">
        <header class="flex flex-col items-center pt-12 pb-6 px-6">
            <img
                :src="$page.props.marca.logo"
                class="h-14 w-auto object-contain mb-6"
                :alt="$page.props.marca.nombre"
            />
            <h1 class="text-2xl font-semibold text-tinta-900 text-center">Verificación de certificado</h1>
            <p class="text-tinta-400 text-sm mt-1 text-center">Confirma la autenticidad de un certificado de capacitación</p>
        </header>

        <main class="flex-1 px-6 max-w-md mx-auto w-full">

            <!-- Buscador -->
            <div class="rounded-2xl shadow-sm p-6 bg-superficie mb-4">
                <label class="block text-sm font-medium text-tinta-700 mb-2">Código del certificado</label>
                <input
                    v-model="codigo"
                    type="text"
                    placeholder="CERT-XXXXXXXX"
                    class="w-full rounded-xl border px-4 py-3 text-sm focus:outline-none focus:ring-2 uppercase"
                    style="border-color:#D1D5DB;"
                    @keyup.enter="verificar"
                />
                <button
                    @click="verificar"
                    :disabled="!codigo.trim()"
                    class="mt-4 w-full py-3 rounded-xl font-semibold text-white text-sm disabled:opacity-50"
                    style="background-color:var(--marca);"
                >
                    Verificar
                </button>
            </div>

            <!-- Resultado: válido -->
            <div v-if="certificado?.valido"
                class="rounded-2xl p-6 mb-4"
                style="background:#ECFDF5; border:1px solid #10B981;">
                <div class="flex items-center gap-2 mb-4">
                    <svg class="w-7 h-7" style="color:#065F46;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <p class="text-lg font-semibold" style="color:#065F46;">Certificado válido</p>
                </div>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-tinta-400">Otorgado a</dt>
                        <dd class="font-semibold text-tinta-900 text-right">{{ certificado.estudiante }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-tinta-400">Curso</dt>
                        <dd class="font-semibold text-tinta-900 text-right">{{ certificado.curso }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-tinta-400">Fecha de emisión</dt>
                        <dd class="font-semibold text-tinta-900 text-right">{{ certificado.fecha_emision }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-tinta-400">Código</dt>
                        <dd class="font-mono text-tinta-700 text-right">{{ certificado.codigo }}</dd>
                    </div>
                </dl>
            </div>

            <!-- Resultado: no encontrado -->
            <div v-else-if="noEncontrado"
                class="rounded-2xl p-6 mb-4 text-center"
                style="background:#FEF2F2; border:1px solid #EF4444;">
                <svg class="w-7 h-7 mx-auto mb-2" style="color:#991B1B;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
                <p class="text-base font-semibold" style="color:#991B1B;">Certificado no encontrado</p>
                <p class="text-sm mt-1" style="color:#991B1B;">
                    No existe ningún certificado con el código <strong>{{ codigoBuscado }}</strong>.
                    Verifica que esté bien escrito.
                </p>
            </div>
        </main>

        <footer class="px-6 py-8 text-center">
            <p class="text-xs text-tinta-300">{{ $page.props.marca.nombre }}</p>
        </footer>
    </div>
</template>
