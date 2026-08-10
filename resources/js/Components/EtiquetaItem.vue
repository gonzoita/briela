<template>
    <!-- Botón impresora -->
    <button
        @click="mostrar = true"
        class="w-7 h-7 flex items-center justify-center rounded-lg border border-linea text-tinta-300 hover:text-[var(--marca)] hover:border-[var(--marca)] hover:bg-blue-50 transition-colors"
        title="Ver etiqueta e imprimir"
    >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 9V2h12v7M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2m-6 0v4H9v-4h6z"/>
        </svg>
    </button>

    <!-- Modal vista previa -->
    <Teleport to="body">
        <div
            v-if="mostrar"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm"
            @click.self="mostrar = false"
        >
            <div class="bg-superficie rounded-2xl shadow-2xl max-w-lg w-full mx-4 overflow-hidden">

                <!-- Header modal -->
                <div class="flex items-center justify-between px-5 py-4 bg-[var(--marca)]">
                    <div>
                        <h3 class="text-white font-semibold">Etiqueta del ítem</h3>
                        <p class="text-white/60 text-xs">{{ item.numero_serie }}</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            @click="imprimir"
                            class="px-3 py-1.5 bg-superficie text-[var(--marca)] rounded-lg text-sm font-semibold hover:bg-blue-50 flex items-center gap-1.5"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 9V2h12v7M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2m-6 0v4H9v-4h6z"/>
                            </svg>
                            Imprimir
                        </button>
                        <button
                            @click="mostrar = false"
                            class="text-white/70 hover:text-white p-1 rounded-lg hover:bg-white/10"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Vista previa etiqueta -->
                <div class="p-6 flex justify-center bg-tinta-50">
                    <div
                        id="etiqueta-imprimir"
                        class="bg-superficie border-2 border-gray-800 rounded-lg overflow-hidden"
                        style="width:378px;height:189px;position:relative;"
                    >
                        <!-- Franja superior azul -->
                        <div style="background:var(--marca);height:32px;display:flex;align-items:center;padding:0 12px;justify-content:space-between;">
                            <span style="color:white;font-weight:bold;font-size:13px;letter-spacing:1px;">{{ ($page.props.marca.nombre || '').toUpperCase() }}</span>
                            <span style="color:rgba(255,255,255,0.7);font-size:10px;">SGI</span>
                        </div>

                        <!-- Cuerpo: QR izquierda + datos derecha -->
                        <div style="display:flex;padding:8px 12px;gap:10px;height:calc(100% - 56px);">
                            <!-- Canvas QR -->
                            <div style="flex-shrink:0;display:flex;align-items:center;">
                                <canvas ref="qrCanvas" width="90" height="90" />
                            </div>

                            <!-- Datos -->
                            <div style="flex:1;min-width:0;display:flex;flex-direction:column;justify-content:center;">
                                <div style="font-size:11px;color:#666;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:2px;">
                                    {{ tipoLabel }}
                                </div>
                                <div style="font-size:13px;font-weight:bold;color:var(--marca);margin-bottom:6px;line-height:1.2;">
                                    {{ descripcionCorta }}
                                </div>
                                <div style="font-size:10px;color:#333;margin-bottom:2px;">
                                    <strong>OP:</strong> {{ op?.numero_op }}
                                </div>
                                <div style="font-size:10px;color:#333;margin-bottom:2px;">
                                    <strong>Serie:</strong>
                                    <span style="font-family:monospace;">{{ item.numero_serie }}</span>
                                </div>
                                <div style="font-size:10px;color:#333;">
                                    <strong>Estado:</strong> {{ estadoLabel }}
                                </div>
                            </div>
                        </div>

                        <!-- Franja inferior -->
                        <div style="background:var(--superficie-2);border-top:1px solid #e2e8f0;height:24px;display:flex;align-items:center;padding:0 12px;justify-content:space-between;">
                            <span style="font-family:monospace;font-size:9px;color:#64748b;">{{ item.numero_serie }}</span>
                            
                        </div>
                    </div>
                </div>

                <div class="px-6 pb-4 text-center">
                    <p class="text-xs text-tinta-300">Tamaño de impresión: 10cm × 5cm</p>
                </div>

            </div>
        </div>
    </Teleport>
</template>

<script setup>
import { ref, computed, watch, nextTick } from 'vue'
import QRCode from 'qrcode'
import { colorMarca } from '@/marca'

const props = defineProps({
    item: Object,
    op:   Object,
})

const mostrar  = ref(false)
const qrCanvas = ref(null)

const tipoLabel = computed(() => ({
    panel:      'Panel frigorífico',
    puerta:     'Puerta refrigerada',
    componente: 'Componente',
}[props.item.tipo] ?? props.item.tipo))

const estadoLabel = computed(() => ({
    en_produccion:    'En producción',
    calidad_aprobada: 'Calidad aprobada',
    empacado:         'Empacado',
    despachado:       'Despachado',
    instalado:        'Instalado',
}[props.item.estado_serie] ?? props.item.estado_serie))

const descripcionCorta = computed(() => {
    const i = props.item
    if (i.tipo === 'panel')
        return `${i.cantidad}x Panel ${i.panel_ancho}×${i.panel_alto}m e:${i.panel_espesor}mm`
    if (i.tipo === 'puerta')
        return `${i.cantidad}x Puerta ${i.puerta_tipo} ${i.puerta_ancho}×${i.puerta_alto}m`
    return `${i.cantidad}x ${i.comp_referencia ?? i.comp_descripcion ?? 'Componente'}`
})

watch(mostrar, async (val) => {
    if (!val) return
    await nextTick()
    await nextTick()
    if (!qrCanvas.value) return
    try {
        await QRCode.toCanvas(qrCanvas.value, props.item.numero_serie ?? props.item.id.toString(), {
            width: 90,
            margin: 1,
            color: { dark: colorMarca(), light: '#FFFFFF' },
        })
    } catch (e) {
        console.error('QR error:', e)
    }
})

function imprimir() {
    const etiqueta = document.getElementById('etiqueta-imprimir')
    if (!etiqueta) return

    const canvas = qrCanvas.value
    const qrDataUrl = canvas ? canvas.toDataURL('image/png') : ''

    const clone = etiqueta.cloneNode(true)
    if (qrDataUrl) {
        const canvasEl = clone.querySelector('canvas')
        if (canvasEl) {
            const img = document.createElement('img')
            img.src = qrDataUrl
            img.width = 90
            img.height = 90
            canvasEl.parentNode.replaceChild(img, canvasEl)
        }
    }

    const ventana = window.open('', '_blank', 'width=500,height=400')
    ventana.document.write(`<!DOCTYPE html>
<html>
<head>
  <title>Etiqueta ${props.item.numero_serie}</title>
  <style>
    * { margin:0; padding:0; box-sizing:border-box; }
    @page { size: 10cm 5cm; margin: 0; }
    body { width:10cm; height:5cm; overflow:hidden; }
  </style>
</head>
<body>
  ${clone.outerHTML}
  <script>window.onload = () => { window.print(); window.close(); }<\/script>
</body>
</html>`)
    ventana.document.close()
}
</script>
