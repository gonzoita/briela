<script setup>
/**
 * Muestra lo que encontramos al consultar un NIT o una cédula:
 * dígito de verificación, cliente duplicado y datos del RUES.
 *
 * No decide nada por el usuario: los datos del RUES solo se aplican si él
 * oprime el botón.
 */
defineProps({
    consultando: { type: Boolean, default: false },
    resultado:   { type: Object,  default: null },
    error:       { type: String,  default: '' },
})

const emit = defineEmits(['usar-rues'])
</script>

<template>
    <div v-if="consultando || resultado || error" class="mt-3 space-y-2">

        <!-- Consultando -->
        <p v-if="consultando" class="text-xs text-tinta-400 flex items-center gap-2">
            <span class="inline-block w-3 h-3 border-2 border-tinta-200 border-t-blue-600 rounded-full animate-spin"></span>
            Verificando identificación...
        </p>

        <!-- No se pudo consultar: informativo, nunca bloquea -->
        <p v-if="error" class="text-xs text-tinta-400 bg-tinta-50 border border-linea rounded-lg px-3 py-2">
            {{ error }}
        </p>

        <template v-if="resultado && !consultando">

            <!--
                Aviso solo cuando el DV que el usuario escribió no coincide
                con el correcto. Ya lo corregimos solos, pero vale avisar:
                casi siempre significa que se equivocó digitando el NIT.
            -->
            <div v-if="resultado.dv_aviso"
                class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                {{ resultado.dv_aviso }} Lo corregimos, pero revisa que el número esté bien.
            </div>

            <!-- Cliente duplicado: lo más importante de todo el panel -->
            <div v-if="resultado.duplicado"
                class="rounded-lg border border-red-200 bg-red-50 px-3 py-2.5 text-xs text-red-800">
                <p class="font-semibold mb-0.5">Este cliente ya existe</p>
                <p>
                    {{ resultado.duplicado.nombre }}
                    <span v-if="resultado.duplicado.sede" class="text-red-600">
                        · sede {{ resultado.duplicado.sede }}
                    </span>
                    <span v-if="!resultado.duplicado.activo" class="text-red-600">· inactivo</span>
                </p>
                <a :href="resultado.duplicado.url"
                    class="inline-block mt-1.5 font-semibold underline hover:no-underline">
                    Abrir el cliente existente
                </a>
            </div>

            <!-- Datos encontrados en el RUES -->
            <div v-if="resultado.rues"
                class="rounded-lg border border-green-200 bg-green-50 px-3 py-2.5 text-xs text-green-900">
                <p class="font-semibold mb-1">Encontrado en el registro mercantil</p>
                <p class="font-medium">{{ resultado.rues.razon_social }}</p>
                <p v-if="resultado.rues.sigla" class="text-green-700">
                    Sigla: {{ resultado.rues.sigla }}
                </p>
                <p v-if="resultado.rues.organizacion" class="text-green-700">
                    {{ resultado.rues.organizacion }}
                </p>
                <p v-if="resultado.rues.camara_comercio" class="text-green-700">
                    Cámara de Comercio de {{ resultado.rues.camara_comercio }}
                </p>
                <p v-if="resultado.rues.representante" class="text-green-700">
                    Representante legal: {{ resultado.rues.representante }}
                </p>

                <!-- La matrícula cancelada o inactiva es una señal de negocio -->
                <p v-if="resultado.rues.estado_matricula"
                    :class="resultado.rues.estado_matricula === 'ACTIVA'
                        ? 'text-green-700 mt-1'
                        : 'mt-1.5 rounded bg-amber-100 text-amber-900 px-2 py-1 font-semibold'">
                    Matrícula: {{ resultado.rues.estado_matricula }}
                    <span v-if="resultado.rues.ultimo_renovado" class="font-normal">
                        · renovada en {{ resultado.rues.ultimo_renovado }}
                    </span>
                </p>

                <button type="button" @click="emit('usar-rues', resultado.rues)"
                    class="mt-2 rounded-lg bg-green-600 px-3 py-1.5 font-semibold text-white hover:bg-green-700">
                    Usar la razón social
                </button>
                <p class="mt-1.5 text-green-600">
                    El registro no publica correo, teléfono ni dirección: eso toca escribirlo.
                </p>
            </div>

            <!-- No estaba en el RUES -->
            <p v-else-if="resultado.rues_aviso && !resultado.duplicado"
                class="text-xs text-tinta-400 bg-tinta-50 border border-linea rounded-lg px-3 py-2">
                {{ resultado.rues_aviso }}
            </p>
        </template>
    </div>
</template>
