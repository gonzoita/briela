<script setup>
import { ref } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'

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

const submit = () => {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    })
}
</script>

<template>
    <Head title="Iniciar sesión" />

    <div class="min-h-screen flex">

        <!-- ── Mitad izquierda ────────────────────────────────────────────── -->
        <div
            class="hidden lg:flex lg:w-1/2 flex-col items-center justify-center relative overflow-hidden p-12"
            style="background-color: var(--marca);"
        >
            <!-- Patrón geométrico decorativo -->
            <svg class="absolute inset-0 w-full h-full opacity-10" xmlns="http://www.w3.org/2000/svg">
                <defs>
                    <pattern id="grid" width="60" height="60" patternUnits="userSpaceOnUse">
                        <path d="M 60 0 L 0 0 0 60" fill="none" stroke="white" stroke-width="1"/>
                    </pattern>
                    <pattern id="circles" width="120" height="120" patternUnits="userSpaceOnUse">
                        <circle cx="60" cy="60" r="40" fill="none" stroke="white" stroke-width="1"/>
                    </pattern>
                </defs>
                <rect width="100%" height="100%" fill="url(#grid)"/>
                <rect width="100%" height="100%" fill="url(#circles)"/>
            </svg>

            <div class="relative z-10 flex flex-col items-center text-center max-w-md">
                <!-- Logo -->
                <div class="bg-white rounded-2xl p-5 mb-8 shadow-xl">
                    <img
                        :src="$page.props.marca.logo"
                        class="h-16 w-auto object-contain"
                        :alt="$page.props.marca.nombre"
                    />
                </div>

                <h1 class="text-3xl font-bold text-white mb-3">
                    Sistema de Gestión Integral
                </h1>
                <p class="text-blue-300 text-lg">
                    Gestiona tu producción de forma eficiente
                </p>
            </div>
        </div>

        <!-- ── Mitad derecha ──────────────────────────────────────────────── -->
        <div class="w-full lg:w-1/2 flex flex-col items-center justify-center bg-white px-8 py-12">
            <div class="w-full max-w-md">

                <!-- Logo mobile -->
                <div class="flex justify-center mb-8 lg:hidden">
                    <img
                        :src="$page.props.marca.logo"
                        class="h-12 w-auto object-contain"
                        :alt="$page.props.marca.nombre"
                    />
                </div>

                <h2 class="text-2xl font-bold mb-1" style="color: var(--marca);">
                    Iniciar sesión
                </h2>
                <p class="text-gray-500 text-sm mb-8">
                    Ingresa tus credenciales para continuar
                </p>

                <!-- Status (password reset, etc.) -->
                <div v-if="status" class="mb-4 text-sm text-green-700 bg-green-50 border border-green-200 rounded-lg px-4 py-3">
                    {{ status }}
                </div>

                <form @submit.prevent="submit" class="space-y-5">

                    <!-- Email -->
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700 mb-1">
                            Correo electrónico
                        </label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-gray-400">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                                </svg>
                            </span>
                            <input
                                id="email"
                                v-model="form.email"
                                type="email"
                                autocomplete="email"
                                autofocus
                                required
                                class="w-full pl-10 pr-4 py-2.5 border rounded-lg text-sm focus:outline-none focus:ring-2 transition-colors"
                                :class="form.errors.email
                                    ? 'border-red-400 focus:ring-red-200'
                                    : 'border-gray-300 focus:ring-blue-200 focus:border-blue-400'"
                                placeholder="usuario@empresa.com"
                            />
                        </div>
                        <p v-if="form.errors.email" class="mt-1 text-xs text-red-600">{{ form.errors.email }}</p>
                    </div>

                    <!-- Password -->
                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700 mb-1">
                            Contraseña
                        </label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-gray-400">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                                </svg>
                            </span>
                            <input
                                id="password"
                                v-model="form.password"
                                :type="mostrarPassword ? 'text' : 'password'"
                                autocomplete="current-password"
                                required
                                class="w-full pl-10 pr-10 py-2.5 border rounded-lg text-sm focus:outline-none focus:ring-2 transition-colors"
                                :class="form.errors.password
                                    ? 'border-red-400 focus:ring-red-200'
                                    : 'border-gray-300 focus:ring-blue-200 focus:border-blue-400'"
                                placeholder="••••••••"
                            />
                            <button
                                type="button"
                                @click="mostrarPassword = !mostrarPassword"
                                class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400 hover:text-gray-600"
                            >
                                <svg v-if="!mostrarPassword" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                </svg>
                                <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
                                </svg>
                            </button>
                        </div>
                        <p v-if="form.errors.password" class="mt-1 text-xs text-red-600">{{ form.errors.password }}</p>
                    </div>

                    <!-- Recordarme -->
                    <div class="flex items-center">
                        <input
                            id="remember"
                            v-model="form.remember"
                            type="checkbox"
                            class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                        />
                        <label for="remember" class="ml-2 text-sm text-gray-600">Recordarme</label>
                    </div>

                    <!-- Botón -->
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="w-full py-2.5 px-4 rounded-lg text-white font-semibold text-sm transition-opacity"
                        :class="form.processing ? 'opacity-60 cursor-not-allowed' : 'hover:opacity-90'"
                        style="background-color: var(--marca);"
                    >
                        <span v-if="!form.processing">Ingresar</span>
                        <span v-else class="flex items-center justify-center gap-2">
                            <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                            </svg>
                            Ingresando...
                        </span>
                    </button>

                </form>

                <!-- Footer -->
                <p class="mt-10 text-center text-xs text-gray-400">
                    © {{ new Date().getFullYear() }} {{ $page.props.marca.nombre }}. Todos los derechos reservados.
                </p>
            </div>
        </div>
    </div>
</template>
