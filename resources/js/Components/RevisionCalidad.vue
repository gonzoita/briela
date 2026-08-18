<script setup>
/**
 * La revisión de calidad de UNA unidad, en la hoja de producción.
 *
 * Cada punto se marca por separado —cumple o falla—, con su observación y sus fotos, y queda
 * firmado por quien lo revisó. Una falla también se guarda: es justo lo que hace falta cuando
 * el cliente reclama, y antes no quedaba registrada en ningún lado.
 */
import { ref } from 'vue'

const props = defineProps({
    checks: { type: Array, default: () => [] },
})

const emit = defineEmits(['actualizado'])

const lista    = ref(props.checks.map(c => ({ ...c })))
const guardando = ref(null)
const error     = ref('')

async function marcar(check, resultado) {
    guardando.value = check.id
    error.value     = ''

    try {
        const res = await fetch(`/trabajos/checks/${check.id}`, {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ resultado, observaciones: check.observaciones ?? '' }),
        })

        const data = await res.json()

        if (! res.ok) {
            error.value = data.message ?? 'No se pudo guardar la revisión.'

            return
        }

        reemplazar(data)
    } catch {
        error.value = 'No se pudo guardar la revisión.'
    } finally {
        guardando.value = null
    }
}

async function subirFotos(check, evento) {
    const archivos = [...evento.target.files]

    if (! archivos.length) return

    const fd = new FormData()
    archivos.forEach(f => fd.append('fotos[]', f))

    guardando.value = check.id

    try {
        const res = await fetch(`/trabajos/checks/${check.id}/fotos`, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            credentials: 'same-origin',
            body: fd,
        })

        if (res.ok) reemplazar(await res.json())
    } finally {
        guardando.value = null
        evento.target.value = ''
    }
}

function reemplazar(fila) {
    const i = lista.value.findIndex(c => c.id === fila.id)

    if (i >= 0) lista.value[i] = { ...lista.value[i], ...fila }

    emit('actualizado', lista.value)
}

const pendientes = () => lista.value.filter(c => c.resultado === 'pendiente').length
const fallas     = () => lista.value.filter(c => c.resultado === 'falla' && c.es_critico).length
</script>

<template>
    <div v-if="lista.length" class="bg-superficie rounded-2xl shadow-sm overflow-hidden">
        <div class="px-5 py-3 border-b border-linea flex items-center justify-between gap-2 flex-wrap">
            <h3 class="text-xs font-semibold text-tinta-400 uppercase tracking-[0.12em]">Revisión de calidad</h3>
            <span class="text-xs" :class="pendientes() || fallas() ? 'text-aviso-ambar' : 'text-aviso-verde'">
                <template v-if="pendientes()">{{ pendientes() }} sin revisar</template>
                <template v-else-if="fallas()">{{ fallas() }} falla(s) crítica(s)</template>
                <template v-else>Todo revisado</template>
            </span>
        </div>

        <p v-if="error" class="mx-5 mt-3 text-xs text-aviso-rojo bg-pastel-rojo border border-borde-aviso-rojo rounded-lg px-3 py-2">
            {{ error }}
        </p>

        <div class="p-5 space-y-3">
            <div v-for="check in lista" :key="check.id"
                class="border rounded-xl p-3"
                :class="check.resultado === 'cumple' ? 'border-borde-aviso-verde bg-pastel-verde'
                    : check.resultado === 'falla' ? 'border-borde-aviso-rojo bg-pastel-rojo' : 'border-linea'">

                <div class="flex items-start justify-between gap-3 flex-wrap">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-tinta-800">
                            {{ check.titulo }}
                            <span v-if="check.es_critico" class="text-[10px] px-1.5 py-0.5 rounded-full bg-pastel-rojo-2 text-aviso-rojo ml-1">crítico</span>
                            <span v-if="check.exige_foto" class="text-[10px] px-1.5 py-0.5 rounded-full bg-pastel-azul text-aviso-azul ml-1">exige foto</span>
                        </p>
                        <p v-if="check.descripcion" class="text-xs text-tinta-400 mt-0.5">{{ check.descripcion }}</p>
                    </div>

                    <div class="flex items-center gap-2 shrink-0">
                        <button type="button" @click="marcar(check, 'cumple')" :disabled="guardando === check.id"
                            :class="['text-xs px-3 py-1.5 rounded-lg border transition-colors disabled:opacity-50',
                                check.resultado === 'cumple'
                                    ? 'bg-emerald-600 border-emerald-600 text-white'
                                    : 'border-borde-aviso-verde text-aviso-verde hover:bg-pastel-verde']">
                            Cumple
                        </button>
                        <button type="button" @click="marcar(check, 'falla')" :disabled="guardando === check.id"
                            :class="['text-xs px-3 py-1.5 rounded-lg border transition-colors disabled:opacity-50',
                                check.resultado === 'falla'
                                    ? 'bg-red-600 border-red-600 text-white'
                                    : 'border-borde-aviso-rojo text-aviso-rojo hover:bg-pastel-rojo']">
                            Falla
                        </button>
                    </div>
                </div>

                <textarea v-model="check.observaciones" rows="2" maxlength="2000"
                    placeholder="Qué se encontró (opcional, y muy útil si falla)."
                    class="w-full border border-linea rounded-lg px-3 py-2 text-sm mt-2 focus:outline-none focus:border-[var(--marca)]"></textarea>

                <div class="flex items-center gap-2 mt-2 flex-wrap">
                    <label class="text-xs px-2.5 py-1 rounded-lg border border-linea text-tinta-500 cursor-pointer hover:bg-realce transition-colors">
                        + Foto
                        <input type="file" accept="image/*" multiple class="hidden" @change="subirFotos(check, $event)" />
                    </label>
                    <img v-for="(foto, i) in check.fotos" :key="i" :src="foto"
                        class="w-12 h-12 rounded-lg object-cover border border-linea" />
                    <span v-if="check.revisado_por" class="text-xs text-tinta-300 ml-auto">
                        {{ check.revisado_por }} · {{ check.revisado_at }}
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>
