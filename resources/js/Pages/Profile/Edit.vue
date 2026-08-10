<script setup>
import { ref } from 'vue'
import { Head, Link, useForm, usePage } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

defineProps({
    mustVerifyEmail: { type: Boolean, default: false },
    status:          { type: String,  default: '' },
})

const user = usePage().props.auth.user

const formDatos = useForm({
    name:  user.name,
    email: user.email,
})

const formClave = useForm({
    current_password:      '',
    password:              '',
    password_confirmation: '',
})

const confirmandoBorrado = ref(false)
const formBorrar = useForm({ password: '' })

function guardarDatos() {
    formDatos.patch('/profile', { preserveScroll: true })
}

function guardarClave() {
    formClave.put('/password', {
        preserveScroll: true,
        onSuccess: () => formClave.reset(),
        onError: () => {
            if (formClave.errors.password) formClave.reset('password', 'password_confirmation')
            if (formClave.errors.current_password) formClave.reset('current_password')
        },
    })
}

function borrarCuenta() {
    formBorrar.delete('/profile', {
        preserveScroll: true,
        onSuccess: () => { confirmandoBorrado.value = false },
        onFinish:  () => formBorrar.reset(),
    })
}

function ic(extra = '') {
    return `w-full rounded-lg border border-tinta-200 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none ${extra}`
}
</script>

<template>
    <Head title="Mi perfil" />

    <AppLayout title="Mi perfil">
        <div class="max-w-2xl mx-auto space-y-4 pb-8">

            <!-- Quién soy -->
            <div class="bg-white rounded-xl border border-linea p-4">
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 rounded-full bg-[var(--marca)] flex items-center justify-center text-white text-lg font-semibold shrink-0">
                        {{ (user.name || '?').charAt(0).toUpperCase() }}
                    </div>
                    <div class="min-w-0">
                        <p class="font-semibold text-tinta-900 truncate">{{ user.name }}</p>
                        <p class="text-xs text-tinta-400 truncate">{{ user.email }}</p>
                    </div>
                </div>
            </div>

            <!-- Datos de la cuenta -->
            <form @submit.prevent="guardarDatos" class="bg-white rounded-xl border border-linea p-4 space-y-3">
                <div>
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Datos de la cuenta</p>
                    <p class="text-xs text-tinta-400 mt-1">Tu nombre y el correo con el que entras al sistema.</p>
                </div>

                <div>
                    <label class="block text-xs font-medium text-tinta-700 mb-1">Nombre</label>
                    <input v-model="formDatos.name" type="text" :class="ic()" required autocomplete="name"/>
                    <p v-if="formDatos.errors.name" class="text-red-500 text-xs mt-1">{{ formDatos.errors.name }}</p>
                </div>

                <div>
                    <label class="block text-xs font-medium text-tinta-700 mb-1">Correo</label>
                    <input v-model="formDatos.email" type="email" :class="ic()" required autocomplete="username"/>
                    <p v-if="formDatos.errors.email" class="text-red-500 text-xs mt-1">{{ formDatos.errors.email }}</p>
                </div>

                <div v-if="mustVerifyEmail && user.email_verified_at === null"
                    class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                    <p>Tu correo todavía no está verificado.</p>
                    <Link href="/email/verification-notification" method="post" as="button"
                        class="mt-1 font-semibold underline hover:no-underline">
                        Reenviar el correo de verificación
                    </Link>
                    <p v-if="status === 'verification-link-sent'" class="mt-1 font-semibold text-green-700">
                        Te enviamos un enlace nuevo.
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit" :disabled="formDatos.processing"
                        class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50">
                        {{ formDatos.processing ? 'Guardando...' : 'Guardar' }}
                    </button>
                    <Transition enter-active-class="transition" enter-from-class="opacity-0"
                        leave-active-class="transition" leave-to-class="opacity-0">
                        <p v-if="formDatos.recentlySuccessful" class="text-xs text-green-600 font-medium">Guardado.</p>
                    </Transition>
                </div>
            </form>

            <!-- Contraseña -->
            <form @submit.prevent="guardarClave" class="bg-white rounded-xl border border-linea p-4 space-y-3">
                <div>
                    <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Contraseña</p>
                    <p class="text-xs text-tinta-400 mt-1">Usa una contraseña larga que no uses en otro lado.</p>
                </div>

                <div>
                    <label class="block text-xs font-medium text-tinta-700 mb-1">Contraseña actual</label>
                    <input v-model="formClave.current_password" type="password" :class="ic()" autocomplete="current-password"/>
                    <p v-if="formClave.errors.current_password" class="text-red-500 text-xs mt-1">{{ formClave.errors.current_password }}</p>
                </div>

                <div>
                    <label class="block text-xs font-medium text-tinta-700 mb-1">Contraseña nueva</label>
                    <input v-model="formClave.password" type="password" :class="ic()" autocomplete="new-password"/>
                    <p v-if="formClave.errors.password" class="text-red-500 text-xs mt-1">{{ formClave.errors.password }}</p>
                </div>

                <div>
                    <label class="block text-xs font-medium text-tinta-700 mb-1">Repite la contraseña nueva</label>
                    <input v-model="formClave.password_confirmation" type="password" :class="ic()" autocomplete="new-password"/>
                    <p v-if="formClave.errors.password_confirmation" class="text-red-500 text-xs mt-1">{{ formClave.errors.password_confirmation }}</p>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit" :disabled="formClave.processing"
                        class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50">
                        {{ formClave.processing ? 'Guardando...' : 'Cambiar contraseña' }}
                    </button>
                    <Transition enter-active-class="transition" enter-from-class="opacity-0"
                        leave-active-class="transition" leave-to-class="opacity-0">
                        <p v-if="formClave.recentlySuccessful" class="text-xs text-green-600 font-medium">Contraseña actualizada.</p>
                    </Transition>
                </div>
            </form>

            <!-- Eliminar cuenta -->
            <div class="bg-white rounded-xl border border-red-200 p-4 space-y-3">
                <div>
                    <p class="text-xs font-semibold text-red-600 uppercase tracking-[0.12em]">Eliminar mi cuenta</p>
                    <p class="text-xs text-tinta-400 mt-1">
                        Se borra tu acceso y tus datos personales de forma permanente. Antes de
                        hacerlo, descarga lo que necesites conservar.
                    </p>
                </div>

                <button v-if="!confirmandoBorrado" type="button" @click="confirmandoBorrado = true"
                    class="rounded-lg border border-red-300 px-4 py-2 text-sm font-semibold text-red-600 hover:bg-red-50">
                    Eliminar mi cuenta
                </button>

                <div v-else class="space-y-3">
                    <div class="rounded-lg border border-red-200 bg-red-50 px-3 py-2.5 text-xs text-red-800">
                        <p class="font-semibold">Esto no se puede deshacer.</p>
                        <p>Escribe tu contraseña para confirmar.</p>
                    </div>
                    <div>
                        <input v-model="formBorrar.password" type="password" :class="ic()"
                            placeholder="Tu contraseña" @keyup.enter="borrarCuenta"/>
                        <p v-if="formBorrar.errors.password" class="text-red-500 text-xs mt-1">{{ formBorrar.errors.password }}</p>
                    </div>
                    <div class="flex gap-2">
                        <button type="button" @click="confirmandoBorrado = false; formBorrar.reset()"
                            class="rounded-lg border border-tinta-200 px-4 py-2 text-sm font-semibold text-tinta-700 hover:bg-tinta-50">
                            Cancelar
                        </button>
                        <button type="button" @click="borrarCuenta" :disabled="formBorrar.processing"
                            class="rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-50">
                            {{ formBorrar.processing ? 'Eliminando...' : 'Sí, eliminar mi cuenta' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
