<template>
  <div class="mt-3">
    <!-- Grid de fotos existentes -->
    <div v-if="fotosLocales.length" class="grid grid-cols-3 gap-2 mb-3">
      <div
        v-for="(foto, idx) in fotosLocales"
        :key="idx"
        class="relative aspect-square rounded-lg overflow-hidden bg-gray-100 group"
      >
        <img
          :src="foto"
          class="w-full h-full object-cover cursor-pointer"
          @click="abrirLightbox(idx)"
        />
        <button
          v-if="editable"
          @click.stop="eliminarFoto(foto)"
          class="absolute top-1 right-1 bg-red-600 rounded-full p-1 opacity-0 group-hover:opacity-100 transition-opacity"
        >
          <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
          </svg>
        </button>
      </div>
    </div>

    <!-- Botón subir -->
    <label
      v-if="editable"
      class="flex items-center gap-2 cursor-pointer text-sm text-[var(--marca)] font-medium"
    >
      <span v-if="subiendo" class="flex items-center gap-1">
        <svg class="animate-spin w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
        </svg>
        Subiendo...
      </span>
      <span v-else class="flex items-center gap-1">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/>
          <path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/>
        </svg>
        Agregar fotos
      </span>
      <input
        type="file"
        accept="image/*"
        multiple
        capture="environment"
        class="hidden"
        @change="subirFotos"
      />
    </label>

    <!-- Lightbox -->
    <Teleport to="body">
      <div
        v-if="lightboxIdx !== null"
        class="fixed inset-0 z-50 bg-black/90 flex items-center justify-center"
        @click.self="lightboxIdx = null"
      >
        <img :src="fotosLocales[lightboxIdx]" class="max-w-full max-h-full object-contain rounded" />
        <button @click="lightboxIdx = null" class="absolute top-4 right-4 text-white">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
          </svg>
        </button>
        <button v-if="lightboxIdx > 0" @click="lightboxIdx--" class="absolute left-4 top-1/2 -translate-y-1/2 text-white">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
          </svg>
        </button>
        <button v-if="lightboxIdx < fotosLocales.length - 1" @click="lightboxIdx++" class="absolute right-4 top-1/2 -translate-y-1/2 text-white">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
          </svg>
        </button>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue'

const props = defineProps({
  pasoId:   { type: Number, required: true },
  fotos:    { type: Array,   default: () => [] },
  editable: { type: Boolean, default: true },
})

const emit = defineEmits(['update:fotos'])

const fotosLocales = ref([...props.fotos])
const subiendo     = ref(false)
const lightboxIdx  = ref(null)

watch(() => props.fotos, (val) => { fotosLocales.value = [...val] })

function getCsrf() {
  const c = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))
  return c ? decodeURIComponent(c.split('=')[1]) : ''
}

async function subirFotos(e) {
  const archivos = Array.from(e.target.files)
  if (!archivos.length) return
  subiendo.value = true
  const fd = new FormData()
  archivos.forEach(f => fd.append('fotos[]', f))
  try {
    const res  = await fetch(`/trabajos/pasos/${props.pasoId}/fotos`, {
      method:      'POST',
      headers:     { 'X-XSRF-TOKEN': getCsrf() },
      credentials: 'same-origin',
      body:        fd,
    })
    const data = await res.json()
    fotosLocales.value = data.fotos
    emit('update:fotos', data.fotos)
  } catch (err) {
    console.error('Error subiendo fotos:', err)
  } finally {
    subiendo.value = false
    e.target.value = ''
  }
}

async function eliminarFoto(path) {
  if (!confirm('¿Eliminar esta foto?')) return
  try {
    const res  = await fetch(`/trabajos/pasos/${props.pasoId}/fotos`, {
      method:      'DELETE',
      headers:     { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': getCsrf() },
      credentials: 'same-origin',
      body:        JSON.stringify({ path }),
    })
    const data = await res.json()
    fotosLocales.value = data.fotos
    emit('update:fotos', data.fotos)
  } catch (err) {
    console.error('Error eliminando foto:', err)
  }
}

function abrirLightbox(idx) {
  lightboxIdx.value = idx
}
</script>
