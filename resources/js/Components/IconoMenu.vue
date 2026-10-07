<script setup>
/**
 * Los iconos del menú, en un solo lugar.
 *
 * Son de Font Awesome (Solid, versión libre) y se importan uno por uno: el paquete
 * trae más de mil y el bundle solo lleva los que están en este mapa. Van como SVG,
 * no como fuente, así que no hay archivo de CSS ni tipografía que descargar.
 *
 * El `nombre` es el de Font Awesome sin prefijo —«gauge-high», «boxes-stacked»—, igual
 * al que aparece en su catálogo. Un nombre que no esté aquí dibuja el engranaje, para
 * que un ícono olvidado se note sin romper el menú.
 *
 * Tres del diseño original son de la versión Pro y no se pueden usar sin licencia:
 * `sparkles` → `wand-magic-sparkles`, `chart-line-up` → `handshake`,
 * `shield-check` → `shield-halved`.
 *
 * El tamaño lo manda `clase` con clases de texto (`text-sm w-5`): el SVG mide 1em, así que
 * sigue al tamaño de letra, y el ancho fijo centra iconos de proporciones distintas.
 *
 * No se usa el motor de Font Awesome (`fontawesome-svg-core` + su componente de Vue): pesaba
 * 120 KB para hacer lo que hacen tres líneas —cada ícono ya trae su ancho, alto y trazo—.
 */
import { computed } from 'vue'
import {
    faLocationDot, faGaugeHigh, faWandMagicSparkles, faAddressBook, faUserGear,
    faHandshake, faFunnelDollar, faFileInvoiceDollar, faChartPie, faHandHoldingDollar, faRectangleList,
    faBoxesStacked, faBox, faCubesStacked, faWarehouse, faDolly,
    faCartFlatbed, faTruckField, faClipboardQuestion, faFileSignature,
    faIndustry, faClipboardCheck, faListCheck, faCalendarDays, faGears, faShieldHalved, faTruckRampBox, faWallet,
    faScrewdriverWrench, faChartGantt, faServer, faWrench,
    faUsersGear, faIdCardClip, faBookBookmark, faGraduationCap, faChalkboardUser, faEnvelopeOpenText,
    faBullhorn, faShareNodes, faPhotoFilm,
    faSliders, faFileLines, faFingerprint, faGear, faRobot, faFilePdf,
    // La bandeja de mensajes.
    faInbox, faComment, faComments, faPaperclip, faPaperPlane, faMagnifyingGlass,
    faBoxArchive, faArrowLeft, faXmark, faTriangleExclamation, faClock, faUserPlus, faRotateLeft,
} from '@fortawesome/free-solid-svg-icons'
// Los logos de las redes. Un buzón con cinco canales se lee de un vistazo por el logo, no por
// la etiqueta: el paquete se importa ícono por ícono, igual que el de Solid, así que el bundle
// solo lleva estos cuatro.
import {
    faWhatsapp, faInstagram, faFacebook, faFacebookMessenger,
} from '@fortawesome/free-brands-svg-icons'

const props = defineProps({
    nombre: { type: String, required: true },
    clase:  { type: String, default: 'text-sm w-5' },
})

const ICONOS = {
    'location-dot': faLocationDot, 'gauge-high': faGaugeHigh, 'wand-magic-sparkles': faWandMagicSparkles,
    'address-book': faAddressBook, 'user-gear': faUserGear,
    'handshake': faHandshake, 'funnel-dollar': faFunnelDollar, 'file-invoice-dollar': faFileInvoiceDollar,
    'chart-pie': faChartPie, 'hand-holding-dollar': faHandHoldingDollar, 'rectangle-list': faRectangleList,
    'boxes-stacked': faBoxesStacked, 'box': faBox, 'cubes-stacked': faCubesStacked,
    'warehouse': faWarehouse, 'dolly': faDolly,
    'cart-flatbed': faCartFlatbed, 'truck-field': faTruckField,
    'clipboard-question': faClipboardQuestion, 'file-signature': faFileSignature,
    'industry': faIndustry, 'clipboard-check': faClipboardCheck, 'list-check': faListCheck,
    'calendar-days': faCalendarDays, 'gears': faGears, 'shield-halved': faShieldHalved,
    'truck-ramp-box': faTruckRampBox, 'wallet': faWallet,
    'screwdriver-wrench': faScrewdriverWrench, 'chart-gantt': faChartGantt, 'server': faServer, 'wrench': faWrench,
    'users-gear': faUsersGear, 'id-card-clip': faIdCardClip, 'book-bookmark': faBookBookmark,
    'graduation-cap': faGraduationCap, 'chalkboard-user': faChalkboardUser, 'envelope-open-text': faEnvelopeOpenText,
    'bullhorn': faBullhorn, 'share-nodes': faShareNodes, 'photo-film': faPhotoFilm,
    'sliders': faSliders, 'file-lines': faFileLines, 'fingerprint': faFingerprint,
    'gear': faGear, 'robot': faRobot, 'file-pdf': faFilePdf,
    'inbox': faInbox, 'comment': faComment, 'comments': faComments, 'paperclip': faPaperclip,
    'paper-plane': faPaperPlane, 'magnifying-glass': faMagnifyingGlass, 'box-archive': faBoxArchive,
    'arrow-left': faArrowLeft, 'xmark': faXmark, 'triangle-exclamation': faTriangleExclamation,
    'clock': faClock, 'user-plus': faUserPlus, 'rotate-left': faRotateLeft,
    'whatsapp': faWhatsapp, 'instagram': faInstagram,
    'facebook': faFacebook, 'facebook-messenger': faFacebookMessenger,
}

// icon = [ancho, alto, ligaduras, unicode, trazo]; el trazo puede venir partido en capas.
const icono = computed(() => {
    const [ancho, alto, , , trazo] = (ICONOS[props.nombre] ?? faGear).icon

    return { caja: `0 0 ${ancho} ${alto}`, trazos: [].concat(trazo) }
})
</script>

<template>
    <span class="inline-flex items-center justify-center shrink-0 leading-none" :class="clase" aria-hidden="true">
        <svg :viewBox="icono.caja" class="h-[1em] w-auto max-w-full overflow-visible" fill="currentColor">
            <path v-for="(d, i) in icono.trazos" :key="i" :d="d" />
        </svg>
    </span>
</template>
