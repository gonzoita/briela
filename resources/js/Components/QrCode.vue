<script setup>
import { ref, onMounted, watch } from 'vue'
import QRCode from 'qrcode'
import { colorMarca } from '@/marca'

const props = defineProps({
    value: { type: String, required: true },
    size:  { type: Number, default: 200 },
    label: { type: String, default: '' },
})

const canvasRef = ref(null)

const renderQR = async () => {
    if (!canvasRef.value || !props.value) return
    await QRCode.toCanvas(canvasRef.value, props.value, {
        width: props.size,
        margin: 2,
        color: { dark: colorMarca(), light: '#ffffff' },
    })
}

onMounted(renderQR)
watch(() => [props.value, props.size], renderQR)

const descargar = () => {
    if (!canvasRef.value) return
    const link      = document.createElement('a')
    link.download   = `qr-${props.value.replace(/[^a-zA-Z0-9-]/g, '-')}.png`
    link.href       = canvasRef.value.toDataURL('image/png')
    link.click()
}

const imprimir = () => {
    if (!canvasRef.value) return
    const dataUrl = canvasRef.value.toDataURL('image/png')
    const w = window.open('', '_blank', 'width=400,height=500')
    w.document.write(`
        <html><head><title>QR</title>
        <style>
          body { margin: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 100vh; font-family: sans-serif; }
          img { display: block; }
          p { color: var(--texto-3); font-size: 12px; margin-top: 8px; }
          @media print { button { display: none; } }
        </style></head>
        <body>
          <img src="${dataUrl}" width="${props.size}" height="${props.size}" />
          ${props.label ? `<p>${props.label}</p>` : ''}
          <script>window.onload = () => window.print()<\/script>
        </body></html>
    `)
    w.document.close()
}
</script>

<template>
    <div class="flex flex-col items-center gap-2">
        <canvas ref="canvasRef" />
        <p v-if="label" class="text-xs text-tinta-400 text-center">{{ label }}</p>
        <div class="flex gap-2">
            <button
                @click="descargar"
                class="px-3 py-1.5 rounded-lg text-xs font-medium text-white"
                style="background-color: var(--marca);"
            >
                Descargar
            </button>
            <button
                @click="imprimir"
                class="px-3 py-1.5 rounded-lg text-xs font-medium border"
                style="color: var(--marca); border-color: var(--marca);"
            >
                Imprimir
            </button>
        </div>
    </div>
</template>
