<script setup>
import AppLayout from '@/Layouts/AppLayout.vue'
import { useForm, router } from '@inertiajs/vue3'
import { ref } from 'vue'

const props = defineProps({
    curso: Object,
})

const form = useForm({
    titulo:            props.curso.titulo,
    descripcion:       props.curso.descripcion ?? '',
    categoria:          props.curso.categoria ?? '',
    publico_objetivo:  props.curso.publico_objetivo,
    obligatorio:        props.curso.obligatorio,
    imagen_portada:     null,
    puntos_otorga:      props.curso.puntos_otorga ?? 0,
})

const preview = ref(props.curso.imagen_portada ?? null)

function onImagen(e) {
    const file = e.target.files?.[0]
    form.imagen_portada = file ?? null
    preview.value = file ? URL.createObjectURL(file) : props.curso.imagen_portada
}

function submit() {
    form.put(`/capacitacion/cursos/${props.curso.id}`)
}
</script>

<template>
    <AppLayout title="Editar curso">
        <div class="max-w-2xl mx-auto">

            <div class="flex items-center gap-3 mb-5">
                <button @click="router.visit(`/capacitacion/cursos/${curso.id}`)" class="w-9 h-9 rounded-xl flex items-center justify-center text-gray-500 hover:bg-gray-100 transition-colors shrink-0">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                    </svg>
                </button>
                <h1 class="text-xl font-bold text-gray-900">Editar curso</h1>
            </div>

            <form @submit.prevent="submit" class="space-y-4">
                <div class="bg-white rounded-2xl shadow-sm p-5 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Título</label>
                        <input v-model="form.titulo" type="text" maxlength="200"
                            class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-blue-400"
                            :class="{ 'border-red-400': form.errors.titulo }" />
                        <p v-if="form.errors.titulo" class="text-xs text-red-500 mt-1">{{ form.errors.titulo }}</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Descripción</label>
                        <textarea v-model="form.descripcion" rows="3"
                            class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-blue-400"/>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Categoría</label>
                            <input v-model="form.categoria" type="text" maxlength="100"
                                class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-blue-400" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Público objetivo</label>
                            <select v-model="form.publico_objetivo"
                                class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-blue-400">
                                <option value="todos">Todos</option>
                                <option value="colaborador">Colaboradores</option>
                                <option value="contratista">Contratistas</option>
                                <option value="cliente">Clientes</option>
                            </select>
                        </div>
                    </div>

                    <label class="flex items-center gap-2 cursor-pointer">
                        <input v-model="form.obligatorio" type="checkbox" class="w-4 h-4 rounded" style="accent-color:var(--marca);" />
                        <span class="text-sm text-gray-700">Curso obligatorio (asignación por administrador)</span>
                    </label>

                    <div v-if="form.publico_objetivo === 'colaborador'">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Puntos que otorga al aprobar</label>
                        <input v-model.number="form.puntos_otorga" type="number" min="0"
                            class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-blue-400" />
                        <p class="text-xs text-gray-400 mt-1">Puntos de gamificación que recibe el colaborador al aprobar la evaluación del curso.</p>
                    </div>
                </div>

                <div class="bg-white rounded-2xl shadow-sm p-5">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Imagen de portada</label>
                    <div class="flex items-center gap-3">
                        <div class="w-24 h-16 rounded-xl overflow-hidden bg-gray-50 border border-gray-200 flex items-center justify-center shrink-0">
                            <img v-if="preview" :src="preview" class="w-full h-full object-cover" />
                            <svg v-else class="w-6 h-6 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                            </svg>
                        </div>
                        <div>
                            <label class="cursor-pointer">
                                <input type="file" accept="image/*" class="hidden" @change="onImagen" />
                                <span class="inline-block px-3 py-2 rounded-xl border border-gray-200 text-xs text-gray-600 hover:bg-gray-50">
                                    {{ preview ? 'Cambiar imagen' : 'Subir imagen' }}
                                </span>
                            </label>
                            <p class="text-xs text-gray-400 mt-1.5 leading-snug">
                                Recomendado: <strong>1280 × 720 px</strong> (16:9) · máx 5 MB<br>
                                Se recorta al centro: deja aire en los bordes.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="flex gap-2">
                    <button type="submit" :disabled="form.processing"
                        class="flex-1 py-3 rounded-xl text-sm font-semibold text-white disabled:opacity-50" style="background:var(--marca);">
                        {{ form.processing ? 'Guardando...' : 'Guardar cambios' }}
                    </button>
                </div>
            </form>

        </div>
    </AppLayout>
</template>
