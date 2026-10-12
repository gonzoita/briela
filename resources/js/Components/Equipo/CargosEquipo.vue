<script setup>
/**
 * Los cargos: la plantilla de un puesto. Reúne instrucciones base, el techo de herramientas y el
 * rol de permisos. Se crea una vez y se asigna a varios agentes.
 *
 * Los de fábrica no se editan: se COPIAN y se edita la copia. Así una actualización puede ponerlos
 * al día sin pisar lo que la empresa ajustó.
 */
import { ref, computed } from 'vue'
import { post, put, del, mensajeDe } from '@/composables/useEquipoIa'

const props = defineProps({
    cargos:       { type: Array, default: () => [] },
    roles:        { type: Array, default: () => [] },
    herramientas: { type: Array, default: () => [] },
    variables:    { type: Array, default: () => [] },
})

const emit = defineEmits(['cambio'])

const editando = ref(null)
const error    = ref('')
const aviso    = ref('')
const guardando = ref(false)

const vacio = () => ({
    id: null, nombre: '', descripcion: '', area: '', avatar: '🤖', instrucciones: '', herramientas: [],
    rol_id: '', nivel_modelo: 'economico', max_pasos: 8, max_tokens: 1500,
})

function abrir(c) {
    error.value = ''
    aviso.value = ''
    editando.value = c ? JSON.parse(JSON.stringify({ ...vacio(), ...c, rol_id: c.rol_id ?? '' })) : vacio()
}

const grupos = computed(() => {
    const g = {}
    for (const h of props.herramientas) (g[h.grupo] ??= []).push(h)
    return g
})

function alternar(clave) {
    const l = editando.value.herramientas
    const i = l.indexOf(clave)
    i >= 0 ? l.splice(i, 1) : l.push(clave)
}

async function guardar() {
    guardando.value = true
    error.value = ''

    const cuerpo = { ...editando.value, rol_id: editando.value.rol_id || null }
    const r = cuerpo.id ? await put(`/api/equipo-ia/cargos/${cuerpo.id}`, cuerpo) : await post('/api/equipo-ia/cargos', cuerpo)

    guardando.value = false

    if (! r.ok) { error.value = mensajeDe(r); return }

    editando.value = null
    emit('cambio')
}

async function copiar(c) {
    const r = await post(`/api/equipo-ia/cargos/${c.id}/copiar`)
    if (! r.ok) { aviso.value = mensajeDe(r); return }
    aviso.value = `Copia creada. Edítala para ajustarla a tu empresa.`
    emit('cambio')
}

async function borrar(c) {
    if (! confirm(`¿Eliminar el cargo «${c.nombre}»?`)) return
    const r = await del(`/api/equipo-ia/cargos/${c.id}`)
    aviso.value = r.ok ? 'Cargo eliminado.' : mensajeDe(r)
    if (r.ok) emit('cambio')
}

const AVATARES = ['🤖', '📊', '🏭', '🤝', '🧾', '📦', '🛒', '📣', '🔎', '🧠', '⚙️', '📞']
</script>

<template>
    <div class="space-y-3">
        <div class="flex items-start justify-between gap-3 flex-wrap">
            <p class="text-xs text-tinta-400 max-w-xl">
                Un cargo define qué sabe hacer un agente: sus instrucciones base, las herramientas que puede usar
                y el rol que limita lo que puede leer. Los agentes heredan de su cargo y solo pueden recortarlo.
            </p>
            <button type="button" @click="abrir(null)" class="px-3 py-1.5 rounded-xl text-white text-xs font-semibold" style="background:var(--marca);">
                + Nuevo cargo
            </button>
        </div>

        <p v-if="aviso" class="text-sm rounded-xl border border-borde-aviso-azul bg-pastel-azul text-aviso-azul p-3">{{ aviso }}</p>

        <div class="space-y-2.5">
            <div v-for="c in cargos" :key="c.id" class="bg-superficie border border-linea rounded-2xl p-4 flex items-start justify-between gap-3 flex-wrap">
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-tinta-800">
                        <span aria-hidden="true">{{ c.avatar || '🤖' }}</span> {{ c.nombre }}
                        <span v-if="c.de_fabrica" class="text-[10px] px-1.5 py-0.5 rounded-full ml-1 bg-pastel-violeta-2 text-aviso-violeta font-semibold">de fábrica</span>
                        <span v-if="c.area" class="text-[10px] px-1.5 py-0.5 rounded-full ml-1 bg-tinta-100 text-tinta-500">{{ c.area }}</span>
                    </p>
                    <p v-if="c.descripcion" class="text-xs text-tinta-500 mt-0.5">{{ c.descripcion }}</p>
                    <p class="text-[11px] text-tinta-400 mt-1">
                        {{ c.herramientas.length }} herramientas · rol:
                        <span :class="c.rol && ! c.rol_activo ? 'text-aviso-rojo' : ''">{{ c.rol || 'sin rol' }}<template v-if="c.rol && ! c.rol_activo"> (inactivo)</template></span>
                        · {{ c.agentes }} {{ c.agentes === 1 ? 'agente' : 'agentes' }}
                    </p>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    <button v-if="! c.de_fabrica" type="button" @click="abrir(c)" class="text-xs px-3 py-1.5 rounded-lg border border-linea text-tinta-600 hover:bg-realce transition-colors">Editar</button>
                    <button type="button" @click="copiar(c)" class="text-xs px-3 py-1.5 rounded-lg border border-linea text-tinta-600 hover:bg-realce transition-colors">Copiar</button>
                    <button v-if="! c.de_fabrica" type="button" @click="borrar(c)" class="text-xs px-3 py-1.5 rounded-lg border border-borde-aviso-rojo text-aviso-rojo hover:bg-pastel-rojo transition-colors">Eliminar</button>
                </div>
            </div>
        </div>

        <!-- El formulario -->
        <div v-if="editando" class="bg-superficie border border-linea rounded-2xl p-5 space-y-4">
            <h3 class="text-sm font-semibold text-tinta-700 uppercase tracking-[0.12em]">{{ editando.id ? 'Editar cargo' : 'Nuevo cargo' }}</h3>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs text-tinta-400 mb-1">Nombre</label>
                    <input v-model="editando.nombre" type="text" maxlength="120" placeholder="Jefe de cartera"
                        class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]" />
                </div>
                <div>
                    <label class="block text-xs text-tinta-400 mb-1">Área</label>
                    <input v-model="editando.area" type="text" maxlength="40" placeholder="Finanzas"
                        class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]" />
                </div>
            </div>

            <div>
                <label class="block text-xs text-tinta-400 mb-1">Para qué sirve</label>
                <input v-model="editando.descripcion" type="text" maxlength="300"
                    class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]" />
            </div>

            <div>
                <label class="block text-xs text-tinta-400 mb-1">Personaje</label>
                <div class="flex gap-1.5 flex-wrap">
                    <button v-for="a in AVATARES" :key="a" type="button" @click="editando.avatar = a"
                        :class="['w-9 h-9 rounded-xl text-lg border transition-colors', editando.avatar === a ? 'border-[var(--marca)] bg-realce' : 'border-linea hover:bg-realce']">
                        {{ a }}
                    </button>
                </div>
            </div>

            <div>
                <label class="block text-xs text-tinta-400 mb-1">Rol de permisos</label>
                <select v-model="editando.rol_id" class="w-full border border-linea rounded-xl px-3 py-2 text-sm bg-superficie">
                    <option value="">— elige un rol —</option>
                    <option v-for="r in roles" :key="r.id" :value="r.id">{{ r.nombre }}</option>
                </select>
                <p class="text-[11px] text-tinta-400 mt-1 leading-relaxed">
                    Es el techo de lo que el agente puede <strong>leer</strong>. Dale solo lo que necesita: si el rol no
                    tiene «cartera», el agente no verá la cartera aunque se lo pidas en las instrucciones.
                    Se administra en Configuración → Roles y permisos.
                </p>
            </div>

            <div>
                <label class="block text-xs text-tinta-400 mb-1">Instrucciones base</label>
                <textarea v-model="editando.instrucciones" rows="7" maxlength="8000"
                    placeholder="Qué hace este cargo, cómo trabaja y cómo entrega. Las reglas del sistema (español sin voseo, no inventar datos) ya van fijas."
                    class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]"></textarea>
                <p class="text-[11px] text-tinta-400 mt-1">
                    Variables: <code v-for="v in variables" :key="v" class="mr-1.5">{{ '{' + '{' + v + '}' + '}' }}</code>
                </p>
            </div>

            <div>
                <label class="block text-xs text-tinta-400 mb-1">Herramientas que puede usar (el techo de sus agentes)</label>
                <div v-for="(lista, grupo) in grupos" :key="grupo" class="mb-2.5">
                    <p class="text-[11px] font-semibold text-tinta-500 uppercase tracking-[0.1em] mb-1">{{ grupo }}</p>
                    <div class="space-y-1">
                        <label v-for="h in lista" :key="h.clave" class="flex items-start gap-2 cursor-pointer">
                            <input type="checkbox" :checked="editando.herramientas.includes(h.clave)" @change="alternar(h.clave)" class="accent-blue-600 mt-0.5" />
                            <span class="text-xs text-tinta-600">
                                <code class="text-tinta-800">{{ h.clave }}</code>
                                <span v-if="h.tipo === 'propuesta'" class="ml-1 text-[10px] px-1.5 py-0.5 rounded-full bg-pastel-naranja-2 text-aviso-naranja font-semibold">propone</span>
                                — {{ h.descripcion }}
                            </span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label class="block text-xs text-tinta-400 mb-1">Nivel de modelo sugerido</label>
                    <select v-model="editando.nivel_modelo" class="w-full border border-linea rounded-xl px-3 py-2 text-sm bg-superficie">
                        <option value="economico">Económico</option>
                        <option value="potente">Potente</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-tinta-400 mb-1">Pasos máximos</label>
                    <input v-model.number="editando.max_pasos" type="number" min="1" max="20" step="1"
                        class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]" />
                </div>
                <div>
                    <label class="block text-xs text-tinta-400 mb-1">Tokens por respuesta</label>
                    <input v-model.number="editando.max_tokens" type="number" min="200" max="8000" step="1"
                        class="w-full border border-linea rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-[var(--marca)]" />
                </div>
            </div>

            <p v-if="error" class="text-sm text-aviso-rojo">{{ error }}</p>

            <div class="flex gap-3 pt-2 border-t border-linea">
                <button type="button" @click="editando = null" class="flex-1 py-2.5 rounded-xl border border-linea text-sm text-tinta-500 hover:bg-realce transition-colors">Cancelar</button>
                <button type="button" @click="guardar" :disabled="! editando.nombre || guardando"
                    class="flex-1 py-2.5 rounded-xl text-white text-sm font-semibold disabled:opacity-50" style="background:var(--marca);">
                    {{ guardando ? 'Guardando…' : 'Guardar cargo' }}
                </button>
            </div>
        </div>
    </div>
</template>
