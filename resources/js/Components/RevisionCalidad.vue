<script setup>
/**
 * La revisión de calidad de UNA unidad, punto por punto.
 *
 * Cada punto se marca por separado —cumple o falla—, con su observación y sus fotos, y queda
 * firmado por quien lo revisó. Una falla también se guarda: es justo lo que hace falta cuando
 * el cliente reclama, y antes no quedaba registrada en ningún lado.
 *
 * Los mismos endpoints existen bajo dos prefijos —`/trabajos` y `/calidad`— porque quien
 * revisa no siempre puede tocar la producción. Por eso la base es una propiedad: la pantalla
 * dice desde qué módulo se está llamando y el permiso que se exige es el de ese módulo.
 */
import { ref } from 'vue'
import ModalFoto from '@/Components/ModalFoto.vue'

const props = defineProps({
    checks: { type: Array,  default: () => [] },
    base:   { type: String, default: '/trabajos' },
})

const emit = defineEmits(['actualizado'])

const lista     = ref(props.checks.map(c => ({ ...c })))
const guardando = ref(null)
const error     = ref('')

const cabeceras = () => ({
    Accept: 'application/json',
    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
})

async function marcar(check, resultado) {
    // Un punto que exige foto no se puede resolver sin ella, y el servidor lo rechaza igual.
    // Pedirla aquí evita el viaje y, sobre todo, deja la cámara a un toque.
    if (resultado !== 'pendiente' && check.exige_foto && ! check.fotos?.length) {
        pedirFoto(check, resultado)
        return
    }

    guardando.value = check.id
    error.value     = ''

    try {
        const res = await fetch(`${props.base}/checks/${check.id}`, {
            method: 'PATCH',
            headers: { ...cabeceras(), 'Content-Type': 'application/json' },
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

async function subirArchivos(check, archivos) {
    if (! archivos.length) return

    const fd = new FormData()
    archivos.forEach(f => fd.append('fotos[]', f))

    guardando.value = check.id

    try {
        const res = await fetch(`${props.base}/checks/${check.id}/fotos`, {
            method: 'POST',
            headers: cabeceras(),
            credentials: 'same-origin',
            body: fd,
        })

        if (res.ok) reemplazar(await res.json())
        else        error.value = 'No se pudo subir la foto.'
    } catch {
        error.value = 'No se pudo subir la foto.'
    } finally {
        guardando.value = null
    }
}

function alElegirArchivos(check, evento) {
    subirArchivos(check, [...evento.target.files])
    evento.target.value = ''
}

// ── La hoja de la foto: archivo o cámara ──────────────────────────────────────
const modal = ref({ abierto: false, check: null, resultado: null, guardando: false })

function pedirFoto(check, resultado = null) {
    modal.value = { abierto: true, check, resultado, guardando: false }
}

async function confirmarFoto(archivo) {
    const { check, resultado } = modal.value
    modal.value.guardando = true

    await subirArchivos(check, [archivo])

    modal.value = { abierto: false, check: null, resultado: null, guardando: false }

    // Se pidió la foto para poder marcar el punto: ya está, se marca.
    if (resultado) {
        const fresco = lista.value.find(c => c.id === check.id)
        if (fresco?.fotos?.length) marcar(fresco, resultado)
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
                        <button v-if="check.resultado !== 'pendiente'" type="button"
                            @click="marcar(check, 'pendiente')" :disabled="guardando === check.id"
                            class="text-xs px-2.5 py-1.5 rounded-lg border border-linea text-tinta-400 hover:bg-realce transition-colors disabled:opacity-50">
                            Deshacer
                        </button>
                    </div>
                </div>

                <textarea v-model="check.observaciones" rows="2" maxlength="2000"
                    placeholder="Qué se encontró (opcional, y muy útil si falla)."
                    class="w-full border border-linea rounded-lg px-3 py-2 text-sm mt-2 bg-superficie focus:outline-none focus:border-[var(--marca)]"></textarea>

                <div class="flex items-center gap-2 mt-2 flex-wrap">
                    <button type="button" @click="pedirFoto(check)"
                        class="text-xs px-2.5 py-1 rounded-lg border border-linea text-tinta-500 hover:bg-realce transition-colors">
                        Tomar foto
                    </button>
                    <label class="text-xs px-2.5 py-1 rounded-lg border border-linea text-tinta-500 cursor-pointer hover:bg-realce transition-colors">
                        Subir archivo
                        <input type="file" accept="image/*" multiple class="hidden" @change="alElegirArchivos(check, $event)" />
                    </label>
                    <a v-for="(foto, i) in check.fotos" :key="i" :href="foto" target="_blank" rel="noopener">
                        <img :src="foto" alt="Evidencia" class="w-12 h-12 rounded-lg object-cover border border-linea" />
                    </a>
                    <span v-if="check.revisado_por" class="text-xs text-tinta-300 ml-auto">
                        {{ check.revisado_por }} · {{ check.revisado_at }}
                    </span>
                </div>
            </div>
        </div>

        <ModalFoto
            :abierto="modal.abierto"
            :guardando="modal.guardando"
            :titulo="modal.check ? modal.check.titulo : 'Foto de evidencia'"
            :descripcion="modal.check?.descripcion || 'Queda como evidencia de esta unidad.'"
            @confirmar="confirmarFoto"
            @cerrar="modal = { abierto: false, check: null, resultado: null, guardando: false }" />
    </div>
</template>
