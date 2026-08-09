<script setup>
/**
 * La marca de Briela.
 *
 * El monograma es un SVG de formas, no de texto: así se ve idéntico en cualquier
 * dispositivo y aguanta reducirse a 16 píxeles para el favicon. La palabra, en
 * cambio, se dibuja con la tipografía que la empresa eligió en Ajustes, para que
 * la marca del producto y la de la instalación hablen el mismo idioma.
 *
 *   <LogoBriela />                     monograma + palabra
 *   <LogoBriela variante="monograma" /> solo la marca, para el menú colapsado
 *   <LogoBriela tono="claro" />        sobre fondos oscuros
 */
const props = defineProps({
    variante: { type: String, default: 'completo' },  // completo | monograma | palabra
    tono:     { type: String, default: 'marca' },     // marca | claro | oscuro
    tamano:   { type: Number, default: 32 },
})

// El monograma se pinta en un cuadrado de esquinas muy redondeadas, la forma que
// usan los iconos de iOS: a tamaño pequeño se reconoce antes que un símbolo suelto.
const colores = {
    marca:  { fondo: 'var(--marca)', letra: 'var(--marca-texto)', palabra: 'var(--texto)' },
    claro:  { fondo: '#FFFFFF',      letra: 'var(--marca)',       palabra: '#FFFFFF' },
    oscuro: { fondo: 'var(--texto)', letra: '#FFFFFF',            palabra: 'var(--texto)' },
}
</script>

<template>
    <span class="inline-flex items-center" :style="{ gap: tamano * 0.28 + 'px' }">
        <svg
            v-if="variante !== 'palabra'"
            :width="tamano" :height="tamano" viewBox="0 0 100 100"
            fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"
            class="shrink-0"
        >
            <rect width="100" height="100" :rx="tamano <= 20 ? 22 : 26" :fill="colores[tono].fondo"/>
            <!--
                La B con sus dos contrahuecos recortados (fill-rule evenodd). El
                trazo es más grueso abajo que arriba, como en las tipografías con
                contraste: es lo que evita que se vea como una figura geométrica.
            -->
            <path
                fill-rule="evenodd" clip-rule="evenodd" :fill="colores[tono].letra"
                d="M34 24h18.5c10 0 17.7 6.6 17.7 15.4 0 5-2.6 9.3-6.7 11.9 5.4 2.5 8.9 7.4 8.9 13.2 0 9.2-8 15.5-18.6 15.5H34V24Zm10 9.6v12.2h8.2c4.3 0 7.4-2.6 7.4-6.1 0-3.6-3.1-6.1-7.4-6.1H44Zm0 21v12.8h9.4c4.7 0 8-2.7 8-6.4s-3.3-6.4-8-6.4H44Z"
            />
        </svg>

        <span
            v-if="variante !== 'monograma'"
            class="font-semibold leading-none"
            :style="{
                fontSize: tamano * 0.72 + 'px',
                letterSpacing: '-0.02em',
                color: colores[tono].palabra,
            }"
        >Briela</span>
    </span>
</template>
