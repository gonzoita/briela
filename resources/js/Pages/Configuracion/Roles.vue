<script setup>
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    roles:             Array,
    catalogo:          Object,
    etiquetasAcciones: Object,
    rolesBase:         Object,
})

const formVacio = {
    nombre: '', descripcion: '', rol_base: 'vendedor',
    todas_las_sedes: false, activo: true, permisos: [],
}

const form     = ref({ ...formVacio, permisos: [] })
const editando = ref(null)
const esSistema = ref(false)

function editar(r) {
    editando.value  = r.id
    esSistema.value = r.es_sistema
    form.value = {
        nombre: r.nombre,
        descripcion: r.descripcion ?? '',
        rol_base: r.rol_base,
        todas_las_sedes: r.todas_las_sedes,
        activo: r.activo,
        permisos: [...r.permisos],
    }
    window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' })
}

function cancelar() {
    editando.value  = null
    esSistema.value = false
    form.value = { ...formVacio, permisos: [] }
}

function tiene(permiso) {
    return form.value.permisos.includes(permiso)
}

function alternar(permiso) {
    const i = form.value.permisos.indexOf(permiso)
    if (i === -1) form.value.permisos.push(permiso)
    else form.value.permisos.splice(i, 1)
}

// Marca o desmarca todas las acciones de un módulo de una vez.
function alternarModulo(modulo, acciones) {
    const todos = acciones.map(a => `${modulo}.${a}`)
    const yaTiene = todos.every(p => form.value.permisos.includes(p))

    form.value.permisos = yaTiene
        ? form.value.permisos.filter(p => !todos.includes(p))
        : [...new Set([...form.value.permisos, ...todos])]
}

function moduloCompleto(modulo, acciones) {
    return acciones.every(a => form.value.permisos.includes(`${modulo}.${a}`))
}

const totalMarcados = computed(() => form.value.permisos.length)

function guardar() {
    if (editando.value) {
        router.put(`/configuracion/roles/${editando.value}`, form.value, {
            preserveScroll: true,
            onSuccess: () => cancelar(),
        })
    } else {
        router.post('/configuracion/roles', form.value, {
            preserveScroll: true,
            onSuccess: () => cancelar(),
        })
    }
}

function eliminar(r) {
    if (r.es_sistema) return
    if (!confirm(`¿Eliminar el rol "${r.nombre}"?`)) return
    router.delete(`/configuracion/roles/${r.id}`, { preserveScroll: true })
}
</script>

<template>
    <AppLayout title="Roles y permisos">
        <div class="max-w-3xl mx-auto">

            <div class="flex items-center gap-3 mb-5">
                <button @click="router.visit('/configuracion')"
                    class="p-2 rounded-xl hover:bg-tinta-100 transition-colors text-tinta-400" title="Volver">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                    </svg>
                </button>
                <h1 class="text-xl font-semibold text-tinta-900">Roles y permisos</h1>
            </div>

            <!--
                La sede no se asigna aquí a propósito. Si estuviera en el rol
                habría que crear "Comercial Bogotá", "Comercial Cali", etc., y
                el número de roles se multiplicaría por cada sede nueva.
            -->
            <div class="mb-5 rounded-xl border border-blue-200 bg-blue-50 p-4">
                <p class="text-sm font-semibold text-blue-900">El rol define QUÉ; el usuario define DÓNDE.</p>
                <p class="text-xs text-blue-800 mt-1.5">
                    Aquí eliges qué puede hacer un rol (ver clientes, crear cotizaciones,
                    aprobar OPs). Las <strong>sedes y bodegas</strong> se asignan a cada persona
                    en <strong>Usuarios</strong>, no al rol.
                </p>
                <p class="text-xs text-blue-800 mt-1.5">
                    Así un solo rol "Comercial" sirve para Bogotá, Cali y Cúcuta: cada vendedor
                    lo usa dentro de las sedes que le marcaste.
                </p>
                <a href="/usuarios" @click.prevent="router.visit('/usuarios')"
                    class="inline-block mt-2 text-xs font-semibold text-blue-900 underline hover:no-underline">
                    Ir a Usuarios para asignar sedes →
                </a>
            </div>

            <!-- Lista de roles -->
            <div class="bg-white rounded-2xl border border-linea overflow-hidden mb-4">
                <div class="px-5 py-3 border-b border-linea">
                    <h2 class="text-sm font-semibold text-tinta-700">Roles</h2>
                    <p class="text-xs text-tinta-300 mt-0.5">
                        Los cuatro roles originales no se pueden eliminar, pero sí ajustar sus permisos.
                    </p>
                </div>

                <div class="divide-y divide-gray-50">
                    <div v-for="r in roles" :key="r.id" class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <p class="text-sm font-medium text-tinta-900 truncate">{{ r.nombre }}</p>
                                    <span v-if="r.es_sistema"
                                        class="text-xs px-2 py-0.5 rounded-full bg-tinta-100 text-tinta-400">Sistema</span>
                                    <span v-if="r.todas_las_sedes"
                                        class="text-xs px-2 py-0.5 rounded-full bg-[var(--marca)] text-white">Todas las sedes</span>
                                </div>
                                <p class="text-xs text-tinta-300 mt-0.5">
                                    Base: {{ rolesBase[r.rol_base] ?? r.rol_base }}
                                    · {{ r.permisos.length }} permiso(s)
                                    · {{ r.usuarios_count }} usuario(s)
                                </p>
                                <p v-if="r.descripcion" class="text-xs text-tinta-300 mt-0.5">{{ r.descripcion }}</p>
                            </div>
                            <span class="text-xs px-2 py-0.5 rounded-full shrink-0"
                                :class="r.activo ? 'bg-green-100 text-green-700' : 'bg-tinta-100 text-tinta-400'">
                                {{ r.activo ? 'Activo' : 'Inactivo' }}
                            </span>
                        </div>
                        <div class="flex items-center gap-3 mt-2">
                            <button @click="editar(r)" class="text-xs text-blue-600 hover:underline">Editar permisos</button>
                            <button v-if="!r.es_sistema" @click="eliminar(r)"
                                class="text-xs text-red-500 hover:underline">Eliminar</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Formulario -->
            <div class="bg-white rounded-2xl border border-linea p-5">
                <h3 class="text-sm font-semibold text-tinta-700 mb-4">
                    {{ editando ? 'Editar rol' : 'Nuevo rol' }}
                </h3>

                <div class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Nombre *</label>
                        <input v-model="form.nombre" type="text" placeholder="Ej: Comercial"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]" />
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Descripción</label>
                        <input v-model="form.descripcion" type="text" placeholder="Para qué sirve este rol"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]" />
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em] mb-1.5">Rol base *</label>
                        <select v-model="form.rol_base" :disabled="esSistema"
                            class="w-full rounded-xl border border-tinta-200 px-3 py-2 text-sm bg-white disabled:bg-tinta-100 focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]">
                            <option v-for="(label, key) in rolesBase" :key="key" :value="key">{{ label }}</option>
                        </select>
                        <p class="text-xs text-tinta-300 mt-1">
                            Define el comportamiento de fondo del rol en el sistema. Los permisos de abajo mandan sobre lo que ve y hace.
                        </p>
                    </div>

                    <div class="flex flex-col gap-2">
                        <div>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input v-model="form.todas_las_sedes" type="checkbox" class="rounded" />
                                <span class="text-sm text-tinta-700">Acceso a todas las sedes</span>
                            </label>
                            <p class="text-xs text-tinta-300 mt-1 ml-6">
                                Marcado, quien tenga este rol ve las tres sedes y puede cambiar
                                entre ellas, sin importar cuáles le marques en Usuarios. Es para
                                gerencia y administración.
                                <br>
                                Sin marcar, cada persona ve solo las sedes que le asignes en su
                                ficha de usuario.
                            </p>
                        </div>
                        <label v-if="!esSistema" class="flex items-center gap-2 cursor-pointer">
                            <input v-model="form.activo" type="checkbox" class="rounded" />
                            <span class="text-sm text-tinta-700">Activo</span>
                        </label>
                    </div>

                    <!-- Permisos -->
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <p class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Permisos</p>
                            <span class="text-xs text-tinta-300">{{ totalMarcados }} marcados</span>
                        </div>

                        <div v-for="(modulos, grupo) in catalogo" :key="grupo" class="mb-4">
                            <p class="text-xs font-semibold text-tinta-500 mb-2">{{ grupo }}</p>

                            <div v-for="(config, modulo) in modulos" :key="modulo"
                                class="rounded-xl bg-tinta-50 p-3 mb-2">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-sm font-medium text-tinta-700">{{ config.label }}</span>
                                    <button type="button" @click="alternarModulo(modulo, config.acciones)"
                                        class="text-xs text-blue-600 hover:underline">
                                        {{ moduloCompleto(modulo, config.acciones) ? 'Quitar todo' : 'Marcar todo' }}
                                    </button>
                                </div>
                                <div class="flex flex-wrap gap-x-4 gap-y-1.5">
                                    <label v-for="a in config.acciones" :key="a"
                                        class="flex items-center gap-1.5 cursor-pointer">
                                        <input type="checkbox" class="rounded"
                                            :checked="tiene(`${modulo}.${a}`)"
                                            @change="alternar(`${modulo}.${a}`)" />
                                        <span class="text-sm text-tinta-500">{{ etiquetasAcciones[a] ?? a }}</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex gap-3 pt-1">
                        <button v-if="editando" @click="cancelar"
                            class="flex-1 py-2.5 rounded-xl border border-linea text-sm text-tinta-500">
                            Cancelar
                        </button>
                        <button @click="guardar" class="flex-1 py-2.5 rounded-xl text-white text-sm font-semibold"
                            style="background:var(--marca);">
                            {{ editando ? 'Guardar cambios' : 'Crear rol' }}
                        </button>
                    </div>
                </div>
            </div>

        </div>
    </AppLayout>
</template>
