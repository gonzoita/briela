<script setup>
import { ref, computed } from 'vue'
import { Head, useForm, usePage } from '@inertiajs/vue3'
import LogoBriela from '@/Components/LogoBriela.vue'

defineProps({
    canResetPassword: Boolean,
    status: String,
})

const form = useForm({
    email: '',
    password: '',
    remember: false,
})

const mostrarPassword = ref(false)
const marca = computed(() => usePage().props.marca ?? {})

const submit = () => {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    })
}
</script>

<template>
    <Head title="Entrar" />

    <!--
        Una sola columna centrada, sin ilustración ni patrones de fondo. La versión
        anterior partía la pantalla en dos con un panel de color y una retícula
        decorativa; eso es lo que hacía que se viera genérico. Aquí el peso lo
        llevan el espacio en blanco y la jerarquía tipográfica.
    -->
    <div class="min-h-screen bg-lienzo flex flex-col">
        <div class="flex-1 flex items-center justify-center px-5 py-12">
            <div class="w-full max-w-[380px]">

                <!-- La marca de la empresa, no la del producto. Si no subió logo,
                     su nombre en tipografía se ve mejor que un icono de nadie. -->
                <div class="flex flex-col items-center text-center mb-9">
                    <img
                        v-if="marca.logo_propio"
                        :src="marca.logo"
                        :alt="marca.nombre"
                        class="h-12 w-auto object-contain mb-5"
                    />
                    <span
                        v-else
                        class="mb-5 inline-flex items-center justify-center w-12 h-12 rounded-lg text-lg font-semibold"
                        :style="{ background: 'var(--marca-suave)', color: 'var(--marca)' }"
                    >{{ (marca.nombre || 'B').charAt(0).toUpperCase() }}</span>

                    <h1 class="text-2xl font-semibold text-tinta-900">{{ marca.nombre }}</h1>
                    <p class="text-sm text-tinta-400 mt-1.5">Entra con tu cuenta para continuar</p>
                </div>

                <div v-if="status" class="mb-5 text-sm rounded-lg px-4 py-3 bg-emerald-50 text-emerald-800 border border-emerald-100">
                    {{ status }}
                </div>

                <form @submit.prevent="submit" class="space-y-4">
                    <div>
                        <label for="email" class="block text-sm font-medium text-tinta-700 mb-1.5">Correo</label>
                        <input
                            id="email"
                            v-model="form.email"
                            type="email"
                            autocomplete="email"
                            autofocus
                            required
                            placeholder="tu@empresa.com"
                            class="w-full px-3.5 py-3 rounded-lg border bg-white text-base text-tinta-900 placeholder:text-tinta-300 transition-shadow focus:outline-none focus:ring-4"
                            :class="form.errors.email
                                ? 'border-red-300 focus:ring-red-100'
                                : 'border-tinta-200 focus:border-[var(--marca)] focus:ring-[var(--marca-suave)]'"
                        />
                        <p v-if="form.errors.email" class="mt-1.5 text-xs text-red-600">{{ form.errors.email }}</p>
                    </div>

                    <div>
                        <div class="flex items-baseline justify-between mb-1.5">
                            <label for="password" class="block text-sm font-medium text-tinta-700">Contraseña</label>
                            <a
                                v-if="canResetPassword"
                                href="/forgot-password"
                                class="text-xs text-tinta-400 hover:text-tinta-700 transition-colors"
                            >¿La olvidaste?</a>
                        </div>
                        <div class="relative">
                            <input
                                id="password"
                                v-model="form.password"
                                :type="mostrarPassword ? 'text' : 'password'"
                                autocomplete="current-password"
                                required
                                placeholder="••••••••"
                                class="w-full pl-3.5 pr-11 py-3 rounded-lg border bg-white text-base text-tinta-900 placeholder:text-tinta-300 transition-shadow focus:outline-none focus:ring-4"
                                :class="form.errors.password
                                    ? 'border-red-300 focus:ring-red-100'
                                    : 'border-tinta-200 focus:border-[var(--marca)] focus:ring-[var(--marca-suave)]'"
                            />
                            <button
                                type="button"
                                @click="mostrarPassword = !mostrarPassword"
                                :aria-label="mostrarPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'"
                                class="absolute inset-y-0 right-0 flex items-center px-3.5 text-tinta-300 hover:text-tinta-500 transition-colors"
                            >
                                <svg v-if="!mostrarPassword" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                </svg>
                                <svg v-else class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
                                </svg>
                            </button>
                        </div>
                        <p v-if="form.errors.password" class="mt-1.5 text-xs text-red-600">{{ form.errors.password }}</p>
                    </div>

                    <label class="flex items-center gap-2.5 pt-1 cursor-pointer select-none">
                        <input
                            v-model="form.remember"
                            type="checkbox"
                            class="w-4 h-4 rounded border-tinta-300 focus:ring-2 focus:ring-offset-0"
                            :style="{ color: 'var(--marca)' }"
                        />
                        <span class="text-sm text-tinta-500">Mantener la sesión abierta</span>
                    </label>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="w-full py-3 rounded-lg font-semibold text-base transition-all active:scale-[.99]"
                        :class="form.processing ? 'opacity-60 cursor-not-allowed' : 'hover:brightness-95'"
                        :style="{ background: 'var(--marca)', color: 'var(--marca-texto)', boxShadow: 'var(--sombra-sm)' }"
                    >
                        <span v-if="!form.processing">Entrar</span>
                        <span v-else class="flex items-center justify-center gap-2">
                            <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                            </svg>
                            Entrando…
                        </span>
                    </button>
                </form>
            </div>
        </div>

        <!-- Briela va al pie y discreta: el sistema es de la empresa que lo usa. -->
        <footer class="pb-8 flex flex-col items-center gap-2">
            <span class="text-[11px] uppercase tracking-[0.14em] text-tinta-300">Con la tecnología de</span>
            <LogoBriela :tamano="20" tono="oscuro" />
        </footer>
    </div>
</template>
