<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'

const props = defineProps({
    url:      { type: String, required: true },
    modulo:   { type: String, required: true },
    label:    { type: String, default: 'PDF' },
    variante: { type: String, default: 'rojo' }, // rojo | blanco
})

const abierto    = ref(false)
const cargando   = ref(false)
const plantillas = ref([])

const claseBoton = computed(() =>
    props.variante === 'blanco'
        ? 'border-gray-200 text-gray-700 hover:bg-gray-50'
        : 'border-red-200 text-red-600 hover:bg-red-50'
)

const claseDropdown = computed(() =>
    props.variante === 'blanco'
        ? 'border-gray-200 text-gray-500 hover:bg-gray-50'
        : 'border-red-200 text-red-400 hover:bg-red-50'
)

function getCookie(name) {
    const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'))
    return match ? decodeURIComponent(match[2]) : ''
}

async function cargarPlantillas() {
    cargando.value = true
    try {
        const res = await fetch(`/api/pdf-plantillas/${props.modulo}`, {
            headers: {
                'Accept': 'application/json',
                'X-XSRF-TOKEN': getCookie('XSRF-TOKEN'),
            },
            credentials: 'same-origin',
        })
        const data = await res.json()
        plantillas.value = data.plantillas ?? []
    } catch {
        plantillas.value = []
    } finally {
        cargando.value = false
    }
}

function toggleDropdown() {
    if (!abierto.value && !plantillas.value.length) {
        cargarPlantillas()
    }
    abierto.value = !abierto.value
}

function clickFuera(e) {
    if (!e.target.closest('[data-btn-pdf]')) abierto.value = false
}
onMounted(() => document.addEventListener('click', clickFuera))
onUnmounted(() => document.removeEventListener('click', clickFuera))
</script>

<template>
    <div class="relative flex items-center" data-btn-pdf>
        <!-- Botón principal PDF -->
        <a :href="url" target="_blank"
            class="px-3 py-1.5 rounded-l-xl border text-xs font-medium flex items-center gap-1 transition-colors"
            :class="claseBoton"
        >
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round"
                    d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
            </svg>
            {{ label }}
        </a>

        <!-- Botón dropdown ▾ -->
        <button @click.stop="toggleDropdown"
            class="px-1.5 py-1.5 rounded-r-xl border border-l-0 text-xs transition-colors"
            :class="claseDropdown"
        >
            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>

        <!-- Dropdown plantillas -->
        <div v-if="abierto"
            class="absolute top-full left-0 mt-1 bg-white rounded-xl shadow-lg border border-gray-200 z-50 min-w-[200px] py-1"
        >
            <div class="px-3 py-1.5 text-xs text-gray-400 font-semibold uppercase tracking-wide border-b border-gray-100">
                Elegir plantilla
            </div>
            <div v-if="cargando" class="px-3 py-3 text-xs text-gray-400 text-center">
                Cargando...
            </div>
            <div v-else-if="!plantillas.length" class="px-3 py-3 text-xs text-gray-400 text-center">
                Sin plantillas personalizadas
            </div>
            <a v-for="p in plantillas" :key="p.id"
                :href="`${url}?plantilla_id=${p.id}`"
                target="_blank"
                @click="abierto = false"
                class="flex items-center justify-between px-3 py-2 hover:bg-gray-50 text-sm text-gray-700"
            >
                <span>{{ p.nombre }}</span>
                <span v-if="p.es_default"
                    class="text-xs bg-blue-100 text-blue-600 px-1.5 py-0.5 rounded ml-2">
                    Default
                </span>
            </a>
            <div class="border-t border-gray-100 mt-1 pt-1">
                <a href="/configuracion/plantillas-pdf"
                    class="flex items-center gap-1.5 px-3 py-1.5 text-xs text-[var(--marca)] hover:bg-blue-50 transition-colors"
                >
                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                    Gestionar plantillas
                </a>
            </div>
        </div>
    </div>
</template>
