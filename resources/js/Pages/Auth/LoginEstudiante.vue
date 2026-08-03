<script setup>
import { ref } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'

const form = useForm({
    email: '',
    password: '',
    remember: false,
})

const mostrarPassword = ref(false)

const submit = () => {
    form.post('/portal-capacitacion/login', {
        onFinish: () => form.reset('password'),
    })
}
</script>

<template>
    <Head title="Portal de Capacitación" />

    <div class="min-h-screen flex flex-col items-center justify-center px-4 py-10" style="background-color: #F8F9FA;">
        <div class="w-full max-w-sm">

            <!-- Logo -->
            <div class="flex justify-center mb-6">
                <img
                    src="https://interfrigo.com.co/wp-content/uploads/2024/11/cropped-Diseno-sin-titulo-15.png"
                    class="h-12 w-auto object-contain"
                    alt="Interfrigo"
                />
            </div>

            <div class="bg-white rounded-2xl shadow-sm p-6">
                <h2 class="text-lg font-bold mb-1" style="color:var(--marca);">Portal de Capacitación</h2>
                <p class="text-sm text-gray-500 mb-6">Ingresa con tu cuenta de cliente o contratista</p>

                <form @submit.prevent="submit" class="space-y-4">
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Correo electrónico</label>
                        <input id="email" v-model="form.email" type="email" autocomplete="email" autofocus required
                            class="w-full px-3 py-2.5 border rounded-lg text-sm focus:outline-none focus:ring-2 transition-colors"
                            :class="form.errors.email ? 'border-red-400 focus:ring-red-200' : 'border-gray-300 focus:ring-blue-200 focus:border-blue-400'"
                            placeholder="tucorreo@ejemplo.com" />
                        <p v-if="form.errors.email" class="mt-1 text-xs text-red-600">{{ form.errors.email }}</p>
                    </div>

                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Contraseña</label>
                        <div class="relative">
                            <input id="password" v-model="form.password" :type="mostrarPassword ? 'text' : 'password'"
                                autocomplete="current-password" required
                                class="w-full px-3 py-2.5 pr-10 border rounded-lg text-sm focus:outline-none focus:ring-2 transition-colors"
                                :class="form.errors.password ? 'border-red-400 focus:ring-red-200' : 'border-gray-300 focus:ring-blue-200 focus:border-blue-400'"
                                placeholder="••••••••" />
                            <button type="button" @click="mostrarPassword = !mostrarPassword"
                                class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400 hover:text-gray-600">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                </svg>
                            </button>
                        </div>
                        <p v-if="form.errors.password" class="mt-1 text-xs text-red-600">{{ form.errors.password }}</p>
                    </div>

                    <div class="flex items-center">
                        <input id="remember" v-model="form.remember" type="checkbox"
                            class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500" />
                        <label for="remember" class="ml-2 text-sm text-gray-600">Recordarme</label>
                    </div>

                    <button type="submit" :disabled="form.processing"
                        class="w-full py-2.5 px-4 rounded-lg text-white font-semibold text-sm transition-opacity"
                        :class="form.processing ? 'opacity-60 cursor-not-allowed' : 'hover:opacity-90'"
                        style="background-color: var(--marca);">
                        {{ form.processing ? 'Ingresando...' : 'Ingresar' }}
                    </button>
                </form>
            </div>

            <p class="mt-8 text-center text-xs text-gray-400">
                © 2026 Interfrigo SAS. Todos los derechos reservados.
            </p>
        </div>
    </div>
</template>
