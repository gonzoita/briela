<script setup>
import { ref } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'

const props = defineProps({
    invitacion: { type: Object, default: null },
    mensaje: { type: String, default: null },
})

const form = useForm({
    nombre: props.invitacion?.nombre_sugerido ?? '',
    password: '',
    password_confirmation: '',
})

const mostrarPassword = ref(false)

const submit = () => {
    form.post(`/capacitacion/invitacion/${props.invitacion.token}`)
}
</script>

<template>
    <Head title="Crear cuenta — Capacitación" />

    <div class="min-h-screen flex flex-col items-center justify-center px-4 py-10" style="background-color: var(--superficie-2);">
        <div class="w-full max-w-sm">

            <!-- Logo -->
            <div class="flex justify-center mb-6">
                <img
                    :src="$page.props.marca.logo"
                    class="h-12 w-auto object-contain"
                    :alt="$page.props.marca.nombre"
                />
            </div>

            <div class="bg-superficie rounded-2xl shadow-sm p-6">

                <!-- Invitación inválida -->
                <div v-if="!invitacion" class="text-center py-4">
                    <div class="w-12 h-12 rounded-full bg-pastel-rojo flex items-center justify-center mx-auto mb-3">
                        <svg class="w-6 h-6 text-aviso-rojo" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </div>
                    <p class="text-sm font-semibold text-tinta-900 mb-1">Invitación no disponible</p>
                    <p class="text-sm text-tinta-400">{{ mensaje }}</p>
                    <a href="/portal-capacitacion/login" class="inline-block mt-4 text-sm font-semibold" style="color:var(--marca);">
                        Ir a iniciar sesión
                    </a>
                </div>

                <!-- Formulario de registro -->
                <template v-else>
                    <h2 class="text-lg font-semibold mb-1" style="color:var(--marca);">Crea tu cuenta</h2>
                    <p class="text-sm text-tinta-400 mb-6">Completa tus datos para acceder al portal de capacitación</p>

                    <form @submit.prevent="submit" class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-tinta-700 mb-1">Correo electrónico</label>
                            <input :value="invitacion.email" type="email" disabled readonly
                                class="w-full px-3 py-2.5 border border-linea rounded-lg text-sm bg-tinta-50 text-tinta-400" />
                        </div>

                        <div>
                            <label for="nombre" class="block text-sm font-medium text-tinta-700 mb-1">Nombre completo</label>
                            <input id="nombre" v-model="form.nombre" type="text" required autofocus
                                class="w-full px-3 py-2.5 border rounded-lg text-sm focus:outline-none focus:ring-2 transition-colors"
                                :class="form.errors.nombre ? 'border-red-400 focus:ring-red-200' : 'border-tinta-200 focus:ring-[var(--marca-suave)] focus:border-[var(--marca)]'"
                                placeholder="Tu nombre" />
                            <p v-if="form.errors.nombre" class="mt-1 text-xs text-aviso-rojo">{{ form.errors.nombre }}</p>
                        </div>

                        <div>
                            <label for="password" class="block text-sm font-medium text-tinta-700 mb-1">Contraseña</label>
                            <div class="relative">
                                <input id="password" v-model="form.password" :type="mostrarPassword ? 'text' : 'password'" required
                                    class="w-full px-3 py-2.5 pr-10 border rounded-lg text-sm focus:outline-none focus:ring-2 transition-colors"
                                    :class="form.errors.password ? 'border-red-400 focus:ring-red-200' : 'border-tinta-200 focus:ring-[var(--marca-suave)] focus:border-[var(--marca)]'"
                                    placeholder="Mínimo 8 caracteres" />
                                <button type="button" @click="mostrarPassword = !mostrarPassword"
                                    class="absolute inset-y-0 right-0 flex items-center pr-3 text-tinta-300 hover:text-tinta-500">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                    </svg>
                                </button>
                            </div>
                            <p v-if="form.errors.password" class="mt-1 text-xs text-aviso-rojo">{{ form.errors.password }}</p>
                        </div>

                        <div>
                            <label for="password_confirmation" class="block text-sm font-medium text-tinta-700 mb-1">Confirmar contraseña</label>
                            <input id="password_confirmation" v-model="form.password_confirmation" type="password" required
                                class="w-full px-3 py-2.5 border border-tinta-200 rounded-lg text-sm focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)] focus:border-[var(--marca)] transition-colors"
                                placeholder="Repite tu contraseña" />
                        </div>

                        <button type="submit" :disabled="form.processing"
                            class="w-full py-2.5 px-4 rounded-lg text-white font-semibold text-sm transition-opacity mt-2"
                            :class="form.processing ? 'opacity-60 cursor-not-allowed' : 'hover:opacity-90'"
                            style="background-color: var(--marca);">
                            {{ form.processing ? 'Creando cuenta...' : 'Crear cuenta y entrar' }}
                        </button>
                    </form>

                    <p class="mt-5 text-center text-xs text-tinta-300">
                        ¿Ya tienes cuenta? <a href="/portal-capacitacion/login" class="font-semibold" style="color:var(--marca);">Inicia sesión</a>
                    </p>
                </template>
            </div>

            <p class="mt-8 text-center text-xs text-tinta-300">
                © {{ new Date().getFullYear() }} {{ $page.props.marca.nombre }}. Todos los derechos reservados.
            </p>
        </div>
    </div>
</template>
