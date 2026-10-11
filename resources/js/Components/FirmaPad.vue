<script setup>
/**
 * Un recuadro para firmar con el dedo, el lápiz o el mouse, o para subir la imagen de una firma.
 *
 * Lo usan la remisión (despacho y recibido) y el perfil del usuario. La firma de la remisión no
 * funcionaba por tres cosas que aquí no pasan:
 *  - el lienzo medía 280×100 por dentro y la pantalla lo estiraba al ancho de la columna, así
 *    que el trazo caía lejos del dedo. Ahora el lienzo toma el tamaño real que ocupa, por la
 *    densidad de la pantalla, y cada punto se escala;
 *  - los escuchas se ponían una sola vez al montar la página, y si el recuadro aparecía después
 *    nunca respondía. Ahora son del componente y viven lo que vive él;
 *  - se podía guardar un recuadro en blanco. Ahora `vacio` lo dice, y quien lo usa no deja.
 *
 * Emite la firma como `data:image/png;base64,…`, recortada y con fondo transparente.
 */
import { ref, onMounted, onUnmounted, nextTick } from 'vue'

const props = defineProps({
    alto:        { type: Number, default: 140 },
    permitirSubir: { type: Boolean, default: true },
    etiqueta:    { type: String, default: 'Firma aquí' },
})

const emit = defineEmits(['cambio'])

const lienzo  = ref(null)
const vacio   = ref(true)
let ctx       = null
let dibujando = false
let ultimo    = null

function ajustarTamano() {
    const c = lienzo.value
    if (! c) return

    const r     = c.getBoundingClientRect()
    const escala = window.devicePixelRatio || 1

    // Cambiar el tamaño borra el dibujo: se guarda y se vuelve a poner.
    const previo = vacio.value ? null : c.toDataURL()

    c.width  = Math.max(1, Math.round(r.width * escala))
    c.height = Math.max(1, Math.round(props.alto * escala))
    ctx = c.getContext('2d')
    ctx.scale(escala, escala)
    ctx.lineCap   = 'round'
    ctx.lineJoin  = 'round'
    ctx.lineWidth = 2.4
    ctx.strokeStyle = '#1E2A44'

    if (previo) {
        const img = new Image()
        img.onload = () => ctx.drawImage(img, 0, 0, r.width, props.alto)
        img.src = previo
    }
}

function punto(e) {
    const r = lienzo.value.getBoundingClientRect()
    return { x: e.clientX - r.left, y: e.clientY - r.top }
}

function empezar(e) {
    e.preventDefault()
    lienzo.value.setPointerCapture?.(e.pointerId)
    dibujando = true
    ultimo = punto(e)
    // Un toque sin arrastrar también deja marca: un punto.
    ctx.beginPath()
    ctx.arc(ultimo.x, ultimo.y, ctx.lineWidth / 2, 0, Math.PI * 2)
    ctx.fillStyle = ctx.strokeStyle
    ctx.fill()
}

function mover(e) {
    if (! dibujando) return
    e.preventDefault()
    const p = punto(e)
    ctx.beginPath()
    ctx.moveTo(ultimo.x, ultimo.y)
    ctx.lineTo(p.x, p.y)
    ctx.stroke()
    ultimo = p
    if (vacio.value) vacio.value = false
}

function terminar() {
    if (! dibujando) return
    dibujando = false
    vacio.value = false
    emit('cambio', valor())
}

function limpiar() {
    const c = lienzo.value
    if (! c) return
    ctx.save()
    ctx.setTransform(1, 0, 0, 1, 0, 0)
    ctx.clearRect(0, 0, c.width, c.height)
    ctx.restore()
    vacio.value = true
    emit('cambio', null)
}

/**
 * La firma recortada a lo que tiene tinta, en PNG. Un recuadro de 600 px con una firma en una
 * esquina pesaría lo mismo que uno lleno, y en el PDF se vería diminuta.
 */
function valor() {
    const c = lienzo.value
    if (! c || vacio.value) return null

    const { width: w, height: h } = c
    const datos = c.getContext('2d').getImageData(0, 0, w, h).data
    let minX = w, minY = h, maxX = -1, maxY = -1

    for (let y = 0; y < h; y++) {
        for (let x = 0; x < w; x++) {
            if (datos[(y * w + x) * 4 + 3] > 0) {
                if (x < minX) minX = x
                if (x > maxX) maxX = x
                if (y < minY) minY = y
                if (y > maxY) maxY = y
            }
        }
    }

    if (maxX < 0) return null

    const margen = 8
    minX = Math.max(0, minX - margen); minY = Math.max(0, minY - margen)
    maxX = Math.min(w - 1, maxX + margen); maxY = Math.min(h - 1, maxY + margen)

    const salida = document.createElement('canvas')
    salida.width  = maxX - minX + 1
    salida.height = maxY - minY + 1
    salida.getContext('2d').drawImage(c, minX, minY, salida.width, salida.height, 0, 0, salida.width, salida.height)

    return salida.toDataURL('image/png')
}

// Subir la imagen de una firma: se pasa por el lienzo para que salga siempre en PNG y de un
// tamaño razonable, venga de donde venga (una foto del celular pesa varios MB).
const error = ref('')

function subir(e) {
    error.value = ''
    const archivo = e.target.files?.[0]
    e.target.value = ''
    if (! archivo) return

    if (! archivo.type.startsWith('image/')) {
        error.value = 'Sube una imagen: PNG o JPG.'
        return
    }

    const lector = new FileReader()
    lector.onload = () => {
        const img = new Image()
        img.onload = () => {
            limpiar()
            const r = lienzo.value.getBoundingClientRect()
            const proporcion = Math.min(r.width / img.width, props.alto / img.height)
            const ancho = img.width * proporcion
            const alto  = img.height * proporcion
            ctx.drawImage(img, (r.width - ancho) / 2, (props.alto - alto) / 2, ancho, alto)
            vacio.value = false
            emit('cambio', valor())
        }
        img.onerror = () => { error.value = 'No se pudo leer esa imagen.' }
        img.src = lector.result
    }
    lector.readAsDataURL(archivo)
}

function alCambiarTamano() {
    ajustarTamano()
}

onMounted(async () => {
    await nextTick()
    ajustarTamano()
    window.addEventListener('resize', alCambiarTamano)
})

onUnmounted(() => {
    window.removeEventListener('resize', alCambiarTamano)
})

defineExpose({ valor, limpiar, vacio })
</script>

<template>
    <div>
        <div class="relative rounded-xl border-2 border-dashed border-linea bg-white overflow-hidden">
            <canvas ref="lienzo"
                class="block w-full touch-none cursor-crosshair"
                :style="{ height: `${alto}px` }"
                @pointerdown="empezar" @pointermove="mover" @pointerup="terminar"
                @pointercancel="terminar" @pointerleave="terminar"></canvas>
            <!-- El fondo es blanco en los dos temas: la firma se imprime sobre papel. -->
            <p v-if="vacio" class="pointer-events-none absolute inset-0 flex items-center justify-center text-sm text-gray-400 select-none">
                {{ etiqueta }}
            </p>
        </div>
        <div class="mt-2 flex items-center gap-3 flex-wrap">
            <button type="button" @click="limpiar" class="text-xs text-tinta-400 hover:text-tinta-600 underline underline-offset-2">Borrar</button>
            <label v-if="permitirSubir" class="text-xs text-aviso-azul hover:opacity-80 underline underline-offset-2 cursor-pointer">
                Subir imagen de la firma
                <input type="file" accept="image/png,image/jpeg,image/webp" class="hidden" @change="subir" />
            </label>
            <span v-if="error" class="text-xs text-aviso-rojo">{{ error }}</span>
        </div>
    </div>
</template>
