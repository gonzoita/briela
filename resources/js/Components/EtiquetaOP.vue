<script setup>
import { ref, onMounted, watch } from 'vue'
import QRCode from 'qrcode'
import { colorMarca } from '@/marca'

const props = defineProps({
    op:   { type: Object, required: true },
    item: { type: Object, required: true },
})

const canvasQR = ref(null)

const renderQR = async () => {
    if (!canvasQR.value || !props.item?.numero_serie) return
    await QRCode.toCanvas(canvasQR.value, `/seguimiento/${props.item.numero_serie}`, {
        width: 80,
        margin: 1,
        color: { dark: colorMarca(), light: '#ffffff' },
    })
}

onMounted(renderQR)
watch(() => props.item?.numero_serie, renderQR)

const imprimir = () => {
    window.print()
}

const descargarPNG = async () => {
    const { default: html2canvas } = await import('html2canvas')
    const el = document.getElementById(`etiqueta-${props.item.id}`)
    if (!el) return
    const canvas = await html2canvas(el, { scale: 3 })
    const link = document.createElement('a')
    link.download = `etiqueta-${props.item.numero_serie}.png`
    link.href = canvas.toDataURL('image/png')
    link.click()
}
</script>

<template>
    <!-- Etiqueta (formato imprimible ~10x5cm) -->
    <div :id="`etiqueta-${item.id}`" class="etiqueta-contenedor">
        <!-- Fila 1: Logo + empresa -->
        <div class="etiqueta-header">
            <img
                :src="$page.props.marca.logo"
                class="etiqueta-logo"
                :alt="$page.props.marca.nombre"
            />
            <span class="etiqueta-empresa">{{ ($page.props.marca.nombre || '').toUpperCase() }}</span>
        </div>

        <!-- Fila 2: OP + cliente -->
        <div class="etiqueta-op">{{ op.numero_op }}</div>
        <div class="etiqueta-cliente">Cliente: {{ op.cliente }}</div>
        <div class="etiqueta-fecha">Fecha: {{ op.fecha_anticipo }}</div>

        <!-- Fila 3: QR + Serie -->
        <div class="etiqueta-body">
            <canvas ref="canvasQR" class="etiqueta-qr" />
            <div class="etiqueta-serie">
                <div class="etiqueta-serie-numero">{{ item.numero_serie }}</div>
                <div class="etiqueta-tipo">{{ item.tipo_label }}</div>
                <div class="etiqueta-resumen">{{ item.resumen }}</div>
            </div>
        </div>
    </div>

    <!-- Botones (se ocultan en impresión) -->
    <div class="etiqueta-acciones no-print">
        <button @click="imprimir" class="btn-etiqueta-print">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                <path stroke-linecap="round" stroke-linejoin="round" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
            </svg>
            Imprimir etiqueta
        </button>
        <button @click="descargarPNG" class="btn-etiqueta-png">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
            </svg>
            Descargar PNG
        </button>
    </div>
</template>

<style>
.etiqueta-contenedor {
    width: 10cm;
    min-height: 5cm;
    border: 1px solid #D1D5DB;
    border-radius: 8px;
    overflow: hidden;
    font-family: Arial, sans-serif;
    background: white;
}

.etiqueta-header {
    background-color: var(--marca);
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 6px 10px;
}

.etiqueta-logo { height: 24px; width: auto; object-fit: contain; }

.etiqueta-empresa {
    color: white;
    font-size: 10px;
    font-weight: bold;
    letter-spacing: 0.5px;
}

.etiqueta-op {
    font-size: 16px;
    font-weight: bold;
    color: var(--marca);
    padding: 6px 10px 2px;
}

.etiqueta-cliente,
.etiqueta-fecha {
    font-size: 10px;
    color: #374151;
    padding: 0 10px;
    line-height: 1.4;
}

.etiqueta-body {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    padding: 8px 10px 10px;
    border-top: 1px solid #E5E7EB;
    margin-top: 6px;
}

.etiqueta-qr { width: 80px; height: 80px; flex-shrink: 0; }

.etiqueta-serie { flex: 1; min-width: 0; }

.etiqueta-serie-numero {
    font-family: 'Courier New', monospace;
    font-size: 11px;
    font-weight: bold;
    color: var(--marca);
    word-break: break-all;
    line-height: 1.3;
}

.etiqueta-tipo {
    font-size: 10px;
    font-weight: 600;
    color: #374151;
    margin-top: 4px;
}

.etiqueta-resumen {
    font-size: 9px;
    color: #6B7280;
    margin-top: 2px;
    line-height: 1.3;
}

.etiqueta-acciones {
    display: flex;
    gap: 8px;
    margin-top: 8px;
}

.btn-etiqueta-print,
.btn-etiqueta-png {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 12px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    border: none;
}

.btn-etiqueta-print {
    background-color: var(--marca);
    color: white;
}

.btn-etiqueta-png {
    background-color: #F3F4F6;
    color: #374151;
}

@media print {
    .no-print { display: none !important; }
    .etiqueta-contenedor {
        width: 10cm;
        min-height: 5cm;
        border: 1px solid #999;
        page-break-inside: avoid;
    }
    body * { visibility: hidden; }
    .etiqueta-contenedor,
    .etiqueta-contenedor * { visibility: visible; }
    .etiqueta-contenedor { position: fixed; top: 0; left: 0; }
}
</style>
