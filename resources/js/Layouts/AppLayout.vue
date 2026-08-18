<script setup>
import { ref, computed, watch, onMounted, onUnmounted, nextTick, provide } from 'vue'
import { usePage, router } from '@inertiajs/vue3'
import { Head } from '@inertiajs/vue3'
import ModalQR from '@/Components/ModalQR.vue'
import AsistenteBurbuja from '@/Components/AsistenteBurbuja.vue'
import ChatBurbuja from '@/Components/ChatBurbuja.vue'
import BotonesFlotantes from '@/Components/BotonesFlotantes.vue'
import BuscadorGlobal from '@/Components/BuscadorGlobal.vue'
import IconoMenu from '@/Components/IconoMenu.vue'
import AvisoLicencia from '@/Components/AvisoLicencia.vue'
import { useTema } from '@/composables/useTema'

const props = defineProps({
    title: { type: String, default: '' },
})

const emit = defineEmits(['foto-capturada'])

// ─── Auth ─────────────────────────────────────────────────────────────────────
const page     = usePage()

// Logo y nombre salen de Ajustes, no del código: así el sistema se puede
// entregar a otra empresa sin tocar una sola línea.
// Día, noche o automático. En automático manda la hora de la sede, que llega del
// servidor: la del computador de quien mira puede ser de otro huso.
const tema = useTema()
watch(() => page.props.hora?.sede, (h) => tema.fijarHoraSede(h), { immediate: true })

// El logo que toca según el tema. Con una sola versión, un logo de texto oscuro
// desaparece sobre el fondo de noche.
const logoSegunTema = computed(() => {
    const m = page.props.marca ?? {}

    return tema.temaEfectivo.value === 'oscuro' && m.logo_oscuro
        ? m.logo_oscuro
        : m.logo
})

const marca = computed(() => page.props.marca ?? {
    nombre: 'Briela',
    logo:   '/icons/icon-512.png',
})
const user     = computed(() => page.props.auth?.user)
const permisos = computed(() => page.props.auth?.permisos)
const flash    = computed(() => page.props.flash)
const inicial  = computed(() => user.value?.name?.[0]?.toUpperCase() ?? '?')

// Muestra el nombre del rol configurable; si no tiene, el rol histórico.
const rolLabel = computed(() => user.value?.rol_nombre ?? ({
    administrador:   'Administrador',
    jefe_produccion: 'Jefe de Producción',
    vendedor:        'Vendedor',
    operario:        'Operario',
}[user.value?.rol] ?? user.value?.rol))

// ─── Multi-sede ───────────────────────────────────────────────────────────────
const sedesDisponibles = computed(() => page.props.sedes?.disponibles ?? [])
const sedeActivaId     = computed(() => page.props.sedes?.activa_id ?? null)
const puedeTodasSedes  = computed(() => !!page.props.sedes?.puede_todas)
// El selector aparece si hay más de una sede, o si puede ver "todas".
const mostrarSelectorSede = computed(() => sedesDisponibles.value.length > 1 || puedeTodasSedes.value)

function cambiarSede(sedeId) {
    if (sedeId === sedeActivaId.value) return
    router.post('/sede-activa', { sede_id: sedeId }, { preserveScroll: true })
}

// ─── Navegación activa ────────────────────────────────────────────────────────
const currentPath = computed(() => window.location.pathname)
const isActive = (path) => {
    if (path === '/dashboard') return currentPath.value === '/dashboard'
    return currentPath.value.startsWith(path)
}

// ─── Permisos finos ───────────────────────────────────────────────────────────
// El menú se dibuja según lo que el rol del usuario tenga marcado en
// Configuración → Roles y permisos, no según una lista fija por rol.
const permisosLista = computed(() => page.props.auth?.permisosLista ?? [])

// Nombre con el que el administrador bautizó al asistente.
const nombreAsistente = computed(() => page.props.asistente?.nombre || 'Asistente')

const puede = (permiso) => {
    if (!permiso) return true               // ítems visibles para todos
    const lista = permisosLista.value
    if (permiso.includes('.')) return lista.includes(permiso)
    return lista.some(p => p.startsWith(permiso + '.'))   // cualquier acción del módulo
}

// ─── Ítems del menú (sidebar + drawer) ───────────────────────────────────────
const navItems = computed(() => {
    const rol = user.value?.rol

    // Cada grupo se muestra solo si al menos uno de sus ítems es visible.
    const grupos = [
        { label: null, items: [
            { label: 'Dashboard',  href: '/dashboard',  icon: 'home' },
            { label: 'Clientes',   href: '/clientes',   icon: 'clientes',   permiso: 'clientes.ver' },
            { label: 'Multimedia', href: '/multimedia', icon: 'multimedia', permiso: 'multimedia.ver' },
        ]},
        { label: 'Ventas', icon: 'crm', items: [
            { label: 'CRM',          href: '/crm',             icon: 'crm',        permiso: 'crm.ver' },
            { label: 'Reportes',     href: '/crm/reportes',    icon: 'reportes',   permiso: 'crm.ver', sub: true },
            { label: 'Formularios',  href: '/crm/formularios', icon: 'formulario', permiso: 'crm.editar', sub: true },
            { label: 'Cotizaciones', href: '/cotizaciones',    icon: 'cotizacion', permiso: 'cotizaciones.ver' },
            { label: 'Comisiones',   href: '/comisiones',      icon: 'comisiones', permiso: 'comisiones.ver' },
        ]},
        { label: 'Inventario', icon: 'inventario', items: [
            { label: 'Productos',         href: '/productos',              icon: 'productos',   permiso: 'productos.ver' },
            { label: 'Ensambles',         href: '/ensambles',              icon: 'ensamble',    permiso: 'ensambles.ver' },
            { label: 'Stock & Materiales',href: '/inventario',             icon: 'inventario',  permiso: 'inventario.ver', sub: true },
            { label: 'Movimientos',       href: '/inventario/movimientos', icon: 'movimientos', permiso: 'inventario.ver', sub: true },
        ]},
        { label: 'Compras', icon: 'oc', items: [
            { label: 'Proveedores',       href: '/compras/proveedores', icon: 'proveedor', permiso: 'proveedores.ver' },
            { label: 'Solicitudes',       href: '/compras/solicitudes', icon: 'solicitud', permiso: 'solicitudes.ver', sub: true },
            { label: 'Órdenes de Compra', href: '/compras/ordenes',     icon: 'oc',        permiso: 'ordenes.ver',     sub: true },
        ]},
        { label: 'Logística', icon: 'camion', items: [
            { label: 'Remisiones', href: '/logistica/remisiones', icon: 'camion', permiso: 'remisiones.ver' },
        ]},
        { label: 'Financiero', icon: 'cartera', items: [
            { label: 'Cartera', href: '/financiero/cartera', icon: 'cartera', permiso: 'cartera.ver' },
        ]},
        { label: 'Producción', icon: 'clipboard', items: [
            { label: 'Órdenes de Producción', href: '/produccion/ops',         icon: 'clipboard', permiso: 'ops.ver' },
            { label: 'Alistamiento',          href: '/produccion/alistamiento', icon: 'clipboard', permiso: 'alistamiento.ver', sub: true },
            { label: 'Programador',           href: '/produccion/programador', icon: 'calendar',  permiso: 'programador.ver', sub: true },
            { label: 'Trabajos',              href: '/trabajos',               icon: 'trabajos',  permiso: 'trabajos.ver' },
            // Panel personal del operario: no depende de permisos de módulo.
            ...(rol === 'operario' ? [{ label: 'Mi Panel', href: '/mi-panel', icon: 'mi-panel' }] : []),
        ]},
        { label: 'RRHH', icon: 'gear', items: [
            { label: 'Colaboradores', href: '/rrhh/operarios', icon: 'workers', permiso: 'rrhh.ver' },
        ]},
        { label: 'Mantenimiento', icon: 'gear', items: [
            { label: 'Dashboard',      href: '/mantenimiento',                icon: 'wrench',   permiso: 'mantenimiento.ver' },
            { label: 'Equipos',        href: '/mantenimiento/equipos',        icon: 'gear',     permiso: 'mantenimiento.ver' },
            { label: 'Mantenimientos', href: '/mantenimiento/mantenimientos', icon: 'calendar', permiso: 'mantenimiento.ver' },
        ]},
        { label: 'Reportes', icon: 'gear', items: [
            { label: 'Informes', href: '/informes', icon: 'chart', permiso: 'informes.ver' },
        ]},
        { label: 'Capacitación', icon: 'gear', items: [
            // Todos pueden ver sus propios cursos.
            { label: 'Mi Capacitación', href: '/mi-capacitacion',              icon: 'capacitacion' },
            { label: 'Cursos',          href: '/capacitacion/cursos',          icon: 'capacitacion', permiso: 'capacitacion.editar' },
            { label: 'Invitaciones',    href: '/capacitacion/invitaciones',    icon: 'capacitacion', permiso: 'capacitacion.crear', sub: true },
        ]},
        { label: 'Marketing', icon: 'gear', items: [
            { label: 'Redes Sociales', href: '/rrss', icon: 'megaphone', permiso: 'rrss.ver' },
        ]},
        { label: 'Asistente', icon: 'gear', items: [
            // Disponible para todos: responde sobre la marca, no sobre datos.
            { label: nombreAsistente.value, href: '/asistente', icon: 'chat' },
        ]},
        { label: 'Sistema', icon: 'gear', items: [
            { label: 'Configuración',  href: '/configuracion',                icon: 'configurador', permiso: 'configuracion.ver' },
            { label: 'Plantillas PDF', href: '/configuracion/plantillas-pdf', icon: 'pdf',          permiso: 'configuracion.editar' },
            { label: 'Auditoría',      href: '/auditoria',                    icon: 'chart',        permiso: 'auditoria.ver' },
        ]},
    ]

    const secciones = []

    for (const grupo of grupos) {
        const visibles = grupo.items.filter(i => puede(i.permiso))
        if (!visibles.length) continue

        // Los ítems marcados como `sub` cuelgan del anterior: el menú se pliega por rama en
        // vez de mostrar una lista de treinta enlaces seguidos.
        const ramas = []

        for (const item of visibles) {
            if (item.sub && ramas.length) {
                ramas[ramas.length - 1].hijos.push(item)
            } else {
                ramas.push({ ...item, hijos: [] })
            }
        }

        // Una sección sin título —Dashboard, Clientes, Multimedia— es la de arriba y va
        // siempre abierta. Tratarla como sección igual que a las demás deja UN solo camino
        // para dibujar el menú, en vez de dos que hay que mantener a la par.
        secciones.push({ label: grupo.label, icon: grupo.icon ?? 'gear', ramas })
    }

    return secciones
})

// ─── Ramas del menú abiertas ─────────────────────────────────────────────────
// Se recuerda entre visitas: cerrar una rama y encontrarla abierta otra vez en la
// pantalla siguiente es de las cosas que más molestan de un menú.
const ramasAbiertas = ref(new Set(
    JSON.parse(localStorage.getItem('briela.menu.abiertas') ?? '[]')
))

function alternarRama(clave) {
    const abriendo = ! ramasAbiertas.value.has(clave)

    // Acordeón: abrir una categoría cierra las demás. Con seis abiertas a la vez el menú
    // vuelve a ser la lista de treinta enlaces que esto vino a evitar, y hay que desplazar
    // para llegar a Ajustes. Las ramas internas no entran en la regla: son de otra escala.
    if (abriendo && clave.startsWith('seccion:')) {
        ramasAbiertas.value.forEach(c => {
            if (c.startsWith('seccion:')) ramasAbiertas.value.delete(c)
        })
    }

    ramasAbiertas.value.has(clave)
        ? ramasAbiertas.value.delete(clave)
        : ramasAbiertas.value.add(clave)

    // El Set no es reactivo al mutar: se reemplaza para que Vue lo note.
    ramasAbiertas.value = new Set(ramasAbiertas.value)
    localStorage.setItem('briela.menu.abiertas', JSON.stringify([...ramasAbiertas.value]))
}

/**
 * Una sección está abierta si el usuario la abrió, o si la pantalla en la que estás vive
 * dentro. Lo segundo importa: navegar a algo y no ver dónde quedaste es peor que el menú
 * largo que esto vino a resolver.
 *
 * La de arriba —sin título— no se pliega: son tres enlaces y son los de todos los días.
 */
function seccionAbierta(seccion) {
    if (! seccion.label) return true

    return ramasAbiertas.value.has('seccion:' + seccion.label)
        || seccion.ramas.some(r => isActive(r.href) || r.hijos?.some(h => isActive(h.href)))
}

/** Una rama está abierta si el usuario la abrió, o si estás dentro de ella. */
function ramaAbierta(item) {
    return ramasAbiertas.value.has(item.href ?? item.label)
        || item.hijos?.some(h => isActive(h.href))
        || isActive(item.href)
}

// ─── Menú usuario (desktop topbar) ───────────────────────────────────────────
/**
 * Forzar la actualización: borra el service worker y sus cachés, y recarga.
 *
 * Existe porque un PWA puede quedarse con una copia vieja de la aplicación —el service worker
 * sirve los archivos que guardó, y los del despliegue anterior ya no están en el servidor: la
 * pantalla queda en negro o simplemente no aparece lo nuevo—. Sin este botón, la salida es
 * explicarle a alguien cómo se limpia el caché del navegador.
 *
 * Al lado va el número de versión: si el que se ve aquí no es el que sirve el servidor, el
 * navegador tiene una copia vieja, y eso deja de ser una discusión a ciegas.
 */
const actualizando = ref(false)

async function forzarActualizacion() {
    actualizando.value = true

    try {
        if ('serviceWorker' in navigator) {
            const registros = await navigator.serviceWorker.getRegistrations()
            await Promise.all(registros.map(r => r.unregister()))
        }

        if ('caches' in window) {
            const nombres = await caches.keys()
            await Promise.all(nombres.map(n => caches.delete(n)))
        }
    } finally {
        // `reload(true)` ya no hace nada en los navegadores modernos: se le agrega un
        // parámetro para que la navegación no salga del caché del navegador.
        window.location.replace(window.location.pathname + '?v=' + Date.now())
    }
}

const menuUsuario = ref(false)
const cerrarSesion = () => router.post('/logout')
const irPerfil    = () => { menuUsuario.value = false; drawerAbierto.value = false; router.visit('/profile') }

// ─── Notificaciones (campanita) ──────────────────────────────────────────────
const menuNotif      = ref(false)
const notificaciones = ref([])
const notifNoLeidas  = ref(0)
let   _notifTimer    = null

async function cargarNotificaciones() {
    try {
        const r = await fetch('/notificaciones', { headers: { Accept: 'application/json' } })
        if (!r.ok) return
        const data = await r.json()
        notificaciones.value = data.notificaciones ?? []
        notifNoLeidas.value  = data.no_leidas ?? 0
    } catch { /* silencioso */ }
}

function xsrfNotif() {
    const c = document.cookie.split('; ').find(r => r.startsWith('XSRF-TOKEN='))
    return c ? decodeURIComponent(c.split('=')[1]) : ''
}

async function abrirNotif(n) {
    if (!n.leida) {
        n.leida = true
        notifNoLeidas.value = Math.max(0, notifNoLeidas.value - 1)
        fetch(`/notificaciones/${n.id}/leer`, {
            method: 'POST',
            headers: { 'X-XSRF-TOKEN': xsrfNotif(), Accept: 'application/json' },
        }).catch(() => {})
    }
    if (n.url) { menuNotif.value = false; router.visit(n.url) }
}

async function marcarTodasLeidas() {
    notificaciones.value.forEach(n => n.leida = true)
    notifNoLeidas.value = 0
    await fetch('/notificaciones/leer-todas', {
        method: 'POST',
        headers: { 'X-XSRF-TOKEN': xsrfNotif(), Accept: 'application/json' },
    }).catch(() => {})
}

onMounted(() => {
    cargarNotificaciones()
    // Revisa si hay avisos nuevos cada 60s mientras la app esté abierta.
    _notifTimer = setInterval(cargarNotificaciones, 60000)
})
onUnmounted(() => { if (_notifTimer) clearInterval(_notifTimer) })

// ─── Drawer mobile ───────────────────────────────────────────────────────────
const drawerAbierto = ref(false)
const toggleDrawer  = () => { drawerAbierto.value = !drawerAbierto.value }

const navegar = (href) => {
    drawerAbierto.value = false
    router.visit(href)
}

// ─── Provide cámara a páginas hijas ──────────────────────────────────────────
let _fotoCallback = null
// Puente para que el buscador pueda pasarle la pregunta al asistente cuando
// no encuentra nada. La búsqueda literal es instantánea; la IA es el respaldo
// para lo que la literal no alcanza.
const asistenteRef = ref(null)
const chatRef      = ref(null)

provide('abrirAsistente', (texto = '') => {
    asistenteRef.value?.abrirCon(texto)
})

provide('abrirCamara', (callback = null) => {
    _fotoCallback = callback
    abrirCamara()
})

// ─── Modal Cámara ─────────────────────────────────────────────────────────────
const modalCamara  = ref(false)
const videoRef     = ref(null)
const canvasRef    = ref(null)
const fotoPreview  = ref(null)
const streamActivo = ref(null)

const abrirCamara = async () => {
    fotoPreview.value = null
    modalCamara.value = true
    await nextTick()
    try {
        const stream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 720 } },
        })
        streamActivo.value = stream
        if (videoRef.value) { videoRef.value.srcObject = stream; videoRef.value.play() }
    } catch (e) {
        console.error('Cámara no disponible:', e)
        modalCamara.value = false
    }
}

const detenerCamara = () => {
    streamActivo.value?.getTracks().forEach(t => t.stop())
    streamActivo.value = null
}

const cerrarCamara = () => {
    detenerCamara()
    fotoPreview.value = null
    modalCamara.value = false
}

const capturarFoto = () => {
    const v = videoRef.value, c = canvasRef.value
    if (!v || !c) return
    c.width = v.videoWidth; c.height = v.videoHeight
    c.getContext('2d').drawImage(v, 0, 0)
    fotoPreview.value = c.toDataURL('image/jpeg', 0.85)
    detenerCamara()
}

const usarFoto = () => {
    if (_fotoCallback) { _fotoCallback(fotoPreview.value); _fotoCallback = null }
    emit('foto-capturada', fotoPreview.value)
    cerrarCamara()
}

const repetirFoto = async () => {
    fotoPreview.value = null
    await nextTick()
    try {
        const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
        streamActivo.value = stream
        if (videoRef.value) { videoRef.value.srcObject = stream; videoRef.value.play() }
    } catch (e) { console.error(e) }
}

// ─── Modal Lector QR ──────────────────────────────────────────────────────────
const showQR = ref(false)

// ─── PWA — instalación ────────────────────────────────────────────────────────
const pwaPrompt   = ref(null)
const mostrarPWA  = ref(false)
const yaInstalada = ref(false)

async function instalarPWA() {
    if (!pwaPrompt.value) return
    pwaPrompt.value.prompt()
    const { outcome } = await pwaPrompt.value.userChoice
    mostrarPWA.value = false
    pwaPrompt.value  = null
    if (outcome === 'accepted') yaInstalada.value = true
}

function descartarPWA() {
    mostrarPWA.value = false
    localStorage.setItem('pwa-descartado', Date.now().toString())
}

// ─── Toast de notificaciones ─────────────────────────────────────────────────
const toastVisible = ref(false)
const toastMensaje = ref('')
const toastTipo    = ref('success')
let   toastTimer   = null

function mostrarToast(mensaje, tipo = 'success') {
    if (!mensaje) return
    toastMensaje.value = mensaje
    toastTipo.value    = tipo
    toastVisible.value = true
    clearTimeout(toastTimer)
    toastTimer = setTimeout(() => { toastVisible.value = false }, 4000)
}

watch(
    () => page.props.flash,
    (flash) => {
        if (flash?.success) mostrarToast(flash.success, 'success')
        if (flash?.error)   mostrarToast(flash.error,   'error')
        if (flash?.info)    mostrarToast(flash.info,    'info')
    },
    { deep: true, immediate: true }
)

// ─── Notificaciones disciplinas (operarios) ───────────────────────────────────
const modalDisciplinas      = ref(false)
const disciplinasPendientes = ref([])

// ─── Conectividad offline ─────────────────────────────────────────────────────
const estaOnline = ref(true)

onMounted(() => {
    estaOnline.value = navigator.onLine
    window.addEventListener('online',  () => { estaOnline.value = true })
    window.addEventListener('offline', () => { estaOnline.value = false })

    if (window.matchMedia('(display-mode: standalone)').matches) {
        yaInstalada.value = true
        return
    }

    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault()
        pwaPrompt.value = e
        if (!localStorage.getItem('pwa-descartado')) {
            setTimeout(() => { mostrarPWA.value = true }, 3000)
        }
    })

    window.addEventListener('appinstalled', () => {
        mostrarPWA.value  = false
        yaInstalada.value = true
        localStorage.removeItem('pwa-descartado')
    })

    if (user.value?.rol === 'operario') {
        fetch('/rrhh/operarios/mis-notificaciones')
            .then(r => r.json())
            .then(data => {
                if (Array.isArray(data) && data.length) {
                    disciplinasPendientes.value = data
                    modalDisciplinas.value = true
                }
            })
            .catch(() => {})
    }
})

onUnmounted(() => {
    detenerCamara()
})
</script>

<template>
    <Head :title="title" />

    <div class="min-h-screen bg-lienzo">

        <!-- ══════════════════════════════════════════════════════════════════
             DESKTOP — Sidebar fijo izquierdo
        ═══════════════════════════════════════════════════════════════════ -->
        <aside
            class="hidden md:flex fixed top-0 left-0 h-screen w-64 flex-col z-40 bg-superficie border-r border-linea"
        >
            <!-- Logo -->
            <div class="h-16 px-5 flex items-center shrink-0">
                <img
                    v-if="marca.logo_propio"
                    :src="logoSegunTema"
                    class="h-8 w-auto object-contain"
                    :alt="marca.nombre"
                />
                <span v-else class="flex items-center gap-2.5 min-w-0">
                    <span
                        class="w-8 h-8 rounded-lg flex items-center justify-center text-sm font-semibold shrink-0"
                        :style="{ background: 'var(--marca-suave)', color: 'var(--marca)' }"
                    >{{ (marca.nombre || 'B').charAt(0).toUpperCase() }}</span>
                    <span class="text-[15px] font-semibold text-tinta-900 truncate">{{ marca.nombre }}</span>
                </span>
            </div>

            <!-- Navegación -->
            <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-1">
                <template v-for="sec in navItems" :key="sec.label ?? 'inicio'">
                    <!-- El título de la sección la despliega. Es un botón, no un rótulo:
                         Ventas se abre y se cierra, y lo mismo cada categoría. -->
                    <button
                        v-if="sec.label"
                        type="button"
                        @click="alternarRama('seccion:' + sec.label)"
                        class="w-full flex items-center justify-between gap-2 px-3 pt-4 pb-1 group"
                        :aria-expanded="seccionAbierta(sec)"
                    >
                        <span class="flex items-center gap-2.5 min-w-0">
                            <IconoMenu
                                :nombre="sec.icon"
                                clase="w-4 h-4 shrink-0 transition-colors"
                                :class="seccionAbierta(sec) ? 'text-[var(--marca)]' : 'text-tinta-300 group-hover:text-tinta-500'"
                            />
                            <span
                                class="text-[11px] font-semibold uppercase tracking-[0.12em] transition-colors truncate"
                                :class="seccionAbierta(sec) ? 'text-tinta-600' : 'text-tinta-400 group-hover:text-tinta-600'"
                            >{{ sec.label }}</span>
                        </span>
                        <svg
                            class="w-3 h-3 shrink-0 text-tinta-300 transition-transform duration-300"
                            :class="seccionAbierta(sec) ? 'rotate-180' : ''"
                            fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>

                    <!-- El contenido, con la misma animación de las ramas: de 0fr a 1fr, que
                         es la única forma de animar una altura que no se conoce. -->
                    <div
                        class="grid transition-all duration-300 ease-out"
                        :class="seccionAbierta(sec) ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0'"
                    >
                        <div class="overflow-hidden">
                            <div class="space-y-1">
                
                    <!-- Rama del menú -->
                    <div v-for="item in sec.ramas" :key="item.href ?? item.label">
                        <div class="flex items-stretch gap-0.5">
                            <a
                                :href="item.href"
                                class="flex-1 min-w-0 flex items-center gap-3 rounded-lg pl-3 pr-3 py-2 text-sm font-medium transition-colors relative
                                       before:absolute before:left-0 before:top-1.5 before:bottom-1.5 before:w-[3px] before:rounded-full before:transition-colors"
                                :class="isActive(item.href)
                                    ? 'bg-realce text-[var(--marca)] font-semibold before:bg-[var(--marca)]'
                                    : 'text-tinta-500 hover:bg-realce hover:text-tinta-900 before:bg-transparent'"
                                @click.prevent="router.visit(item.href)"
                            >
                                <IconoMenu :nombre="item.icon" clase="w-5 h-5 shrink-0" />
                                <span class="truncate">{{ item.label }}</span>
                            </a>

                            <!-- El botón de plegar va aparte del enlace: así se
                                 puede abrir la rama sin salir de donde estás. -->
                            <button
                                v-if="item.hijos?.length"
                                type="button"
                                @click="alternarRama(item.href ?? item.label)"
                                class="shrink-0 w-7 rounded-lg flex items-center justify-center text-tinta-300 hover:text-tinta-700 hover:bg-realce transition-colors"
                                :aria-expanded="ramaAbierta(item)"
                                :aria-label="ramaAbierta(item) ? 'Plegar ' + item.label : 'Desplegar ' + item.label"
                            >
                                <svg class="w-3.5 h-3.5 transition-transform duration-300"
                                     :class="ramaAbierta(item) ? 'rotate-90' : ''"
                                     fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                                </svg>
                            </button>
                        </div>

                        <!-- Los hijos. La animación va con grid-template-rows de 0fr
                             a 1fr: es la única forma de animar la altura sin conocerla
                             de antemano, y sin el salto que deja un max-height fijo. -->
                        <div
                            v-if="item.hijos?.length"
                            class="grid transition-all duration-300 ease-out"
                            :class="ramaAbierta(item)
                                ? 'grid-rows-[1fr] opacity-100'
                                : 'grid-rows-[0fr] opacity-0'"
                        >
                            <div class="overflow-hidden">
                                <div class="pl-4 mt-0.5 space-y-0.5 border-l border-linea ml-4">
                                    <a
                                        v-for="hijo in item.hijos"
                                        :key="hijo.href"
                                        :href="hijo.href"
                                        class="flex items-center gap-2.5 rounded-lg pl-3 pr-3 py-1.5 text-[13px] transition-colors"
                                        :class="isActive(hijo.href)
                                            ? 'bg-realce text-[var(--marca)] font-semibold'
                                            : 'text-tinta-400 hover:bg-realce hover:text-tinta-900'"
                                        @click.prevent="router.visit(hijo.href)"
                                    >
                                        <IconoMenu :nombre="hijo.icon" clase="w-4 h-4 shrink-0 opacity-70" />
                                        <span class="truncate">{{ hijo.label }}</span>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                            </div>
                        </div>
                    </div>
                </template>
            </nav>

            <!-- Usuario (pie del sidebar) -->
            <div class="px-3 py-3 border-t border-linea shrink-0">
                <div class="flex items-center justify-between gap-2 px-3 pb-2">
                    <span class="text-[10px] text-tinta-300 font-mono" :title="'Versión del frontend cargado'">
                        v{{ $page.props.version_app }}
                    </span>
                    <button
                        type="button"
                        @click="forzarActualizacion"
                        :disabled="actualizando"
                        class="text-[10px] text-tinta-300 hover:text-[var(--marca)] transition-colors disabled:opacity-50"
                        title="Borra la copia guardada en el navegador y vuelve a cargar la versión del servidor"
                    >
                        {{ actualizando ? 'Actualizando…' : 'Forzar actualización' }}
                    </button>
                </div>
                <button
                    @click="irPerfil"
                    class="flex items-center gap-3 w-full px-3 py-2 rounded-lg transition-colors hover:bg-tinta-50"
                >
                    <div
                        class="w-8 h-8 rounded-full flex items-center justify-center text-sm font-semibold shrink-0"
                        :style="{ background: 'var(--marca)', color: 'var(--marca-texto)' }"
                    >
                        {{ inicial }}
                    </div>
                    <div class="min-w-0 text-left">
                        <p class="text-tinta-900 text-sm font-medium truncate">{{ user?.name }}</p>
                        <p class="text-tinta-400 text-xs truncate">{{ rolLabel }}</p>
                    </div>
                </button>
                <!-- Día · Noche · Automático. Tres pastillas en vez de un menú:
                     se ve de un vistazo cuál está puesto y se cambia en un toque. -->
                <div class="mt-2 px-1">
                    <div class="flex items-center gap-0.5 p-0.5 rounded-lg bg-tinta-100">
                        <button
                            v-for="opcion in tema.opciones"
                            :key="opcion.valor"
                            type="button"
                            @click="tema.elegir(opcion.valor)"
                            class="flex-1 flex items-center justify-center gap-1.5 py-1.5 rounded-md text-[11px] font-medium transition-all"
                            :class="tema.preferencia.value === opcion.valor
                                ? 'bg-superficie text-tinta-900 shadow-sm'
                                : 'text-tinta-400 hover:text-tinta-700'"
                            :title="opcion.valor === 'automatico' ? tema.explicacionAutomatico.value : opcion.etiqueta"
                        >
                            <svg v-if="opcion.icono === 'sol'" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <circle cx="12" cy="12" r="4"/>
                                <path stroke-linecap="round" d="M12 3v2m0 14v2M3 12h2m14 0h2M5.6 5.6l1.4 1.4m10 10l1.4 1.4m0-12.8l-1.4 1.4m-10 10l-1.4 1.4"/>
                            </svg>
                            <svg v-else-if="opcion.icono === 'luna'" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 12.8A9 9 0 1111.2 3a7 7 0 009.8 9.8z"/>
                            </svg>
                            <svg v-else class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <circle cx="12" cy="12" r="9"/>
                                <path stroke-linecap="round" d="M12 7.5V12l3 2"/>
                            </svg>
                            <span class="hidden lg:inline">{{ opcion.etiqueta }}</span>
                        </button>
                    </div>
                    <p v-if="tema.preferencia.value === 'automatico'" class="text-[10px] text-tinta-300 mt-1.5 px-1 leading-snug">
                        {{ tema.explicacionAutomatico.value }}
                    </p>
                </div>

                <button
                    @click="cerrarSesion"
                    class="flex items-center gap-3 w-full px-3 py-2 mt-1.5 rounded-lg text-sm text-tinta-400 hover:bg-tinta-50 hover:text-tinta-900 transition-colors"
                >
                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                    </svg>
                    Cerrar sesión
                </button>
            </div>
        </aside>

        <!-- ══════════════════════════════════════════════════════════════════
             DESKTOP — Topbar fijo (al lado del sidebar)
        ═══════════════════════════════════════════════════════════════════ -->
        <header
            class="hidden md:flex fixed top-0 left-64 right-0 z-30 items-center justify-between px-8 border-b border-linea"
            style="height: 64px; background: var(--velo); backdrop-filter: saturate(180%) blur(20px);"
        >
            <!-- Título de la página. Se encoge antes que las acciones: el nombre de un
                 producto largo no tiene por qué empujar el buscador fuera de la barra. -->
            <h1 class="text-[17px] font-semibold text-tinta-900 tracking-[-0.01em] truncate min-w-0 mr-4">{{ title }}</h1>

            <!-- Acciones topbar -->
            <div class="flex items-center gap-3 shrink-0">
                <!-- Buscador global -->
                <BuscadorGlobal />

                <!-- Selector de sede activa -->
                <div v-if="mostrarSelectorSede" class="flex items-center gap-2">
                    <svg class="w-4 h-4 text-tinta-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                    </svg>
                    <select
                        :value="sedeActivaId"
                        @change="cambiarSede(Number($event.target.value))"
                        class="rounded-lg border border-linea px-2 py-1.5 text-sm text-tinta-700 bg-superficie focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]"
                    >
                        <option v-if="puedeTodasSedes" :value="0">Todas las sedes</option>
                        <option v-for="s in sedesDisponibles" :key="s.id" :value="s.id">{{ s.nombre }}</option>
                    </select>
                </div>

                <!-- Botón QR -->
                <button
                    @click="showQR = true"
                    class="w-9 h-9 rounded-xl flex items-center justify-center text-tinta-400 hover:bg-tinta-100 transition-colors"
                    title="Lector QR"
                >
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z" />
                    </svg>
                </button>

                <!-- Botón cámara -->
                <button
                    @click="abrirCamara"
                    class="w-9 h-9 rounded-xl flex items-center justify-center text-tinta-400 hover:bg-tinta-100 transition-colors"
                    title="Cámara"
                >
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                </button>

                <!-- Campanita de notificaciones -->
                <div class="relative">
                    <button
                        @click="menuNotif = !menuNotif; menuNotif && cargarNotificaciones()"
                        class="relative w-9 h-9 rounded-xl flex items-center justify-center text-tinta-400 hover:bg-tinta-100 transition-colors"
                        title="Notificaciones"
                    >
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                        </svg>
                        <span v-if="notifNoLeidas > 0"
                            class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 rounded-full text-[10px] font-semibold text-white flex items-center justify-center"
                            style="background:#EF4444;">
                            {{ notifNoLeidas > 9 ? '9+' : notifNoLeidas }}
                        </span>
                    </button>

                    <!-- Dropdown notificaciones -->
                    <div v-if="menuNotif"
                        class="absolute right-0 top-12 w-80 rounded-xl shadow-xl overflow-hidden z-50"
                        style="background:var(--superficie); border:1px solid var(--borde);">
                        <div class="flex items-center justify-between px-4 py-2.5 border-b border-linea">
                            <span class="text-sm font-semibold text-tinta-900">Notificaciones</span>
                            <button v-if="notifNoLeidas > 0" @click="marcarTodasLeidas"
                                class="text-xs text-aviso-azul hover:underline">Marcar todas leídas</button>
                        </div>
                        <div class="max-h-96 overflow-y-auto">
                            <div v-if="!notificaciones.length" class="px-4 py-8 text-center text-sm text-tinta-300">
                                No tienes notificaciones.
                            </div>
                            <button v-for="n in notificaciones" :key="n.id" @click="abrirNotif(n)"
                                class="flex items-start gap-3 w-full px-4 py-3 text-left hover:bg-tinta-50 border-b border-separador"
                                :style="!n.leida ? 'background:var(--pastel-azul);' : ''">
                                <span class="mt-0.5 w-2 h-2 rounded-full flex-shrink-0"
                                    :style="!n.leida ? 'background:var(--marca);' : 'background:transparent;'" />
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium text-tinta-900">{{ n.titulo }}</span>
                                    <span v-if="n.mensaje" class="block text-xs text-tinta-400 mt-0.5">{{ n.mensaje }}</span>
                                    <span class="block text-[11px] text-tinta-300 mt-1">{{ n.hace }}</span>
                                </span>
                            </button>
                        </div>
                    </div>
                    <div v-if="menuNotif" class="fixed inset-0 z-40" @click="menuNotif = false" />
                </div>

                <!-- Separador -->
                <div class="w-px h-6 bg-tinta-200" />

                <!-- Avatar + menú usuario -->
                <div class="relative">
                    <button
                        @click="menuUsuario = !menuUsuario"
                        class="flex items-center gap-2.5 px-3 py-1.5 rounded-xl hover:bg-tinta-100 transition-colors"
                    >
                        <div
                            class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-semibold"
                            style="background-color: var(--marca); color: white;"
                        >
                            {{ inicial }}
                        </div>
                        <div class="text-left hidden lg:block">
                            <p class="text-sm font-medium text-tinta-900 leading-none">{{ user?.name }}</p>
                            <p class="text-xs text-tinta-300 leading-none mt-0.5">{{ rolLabel }}</p>
                        </div>
                        <svg class="w-4 h-4 text-tinta-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                        </svg>
                    </button>

                    <!-- Dropdown -->
                    <div
                        v-if="menuUsuario"
                        class="absolute right-0 top-12 w-48 rounded-xl shadow-xl overflow-hidden z-50"
                        style="background: var(--superficie); border: 1px solid var(--borde);"
                    >
                        <button @click="irPerfil"
                            class="flex items-center gap-2 w-full px-4 py-3 text-sm text-tinta-700 hover:bg-tinta-50">
                            <svg class="w-4 h-4 text-tinta-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                            </svg>
                            Ver perfil
                        </button>
                        <div class="border-t border-linea" />
                        <button @click="cerrarSesion"
                            class="flex items-center gap-2 w-full px-4 py-3 text-sm text-aviso-rojo hover:bg-pastel-rojo">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                            </svg>
                            Cerrar sesión
                        </button>
                    </div>
                    <div v-if="menuUsuario" class="fixed inset-0 z-40" @click="menuUsuario = false" />
                </div>
            </div>
        </header>

        <!-- ── Indicador offline ─────────────────────────────────────────── -->
        <div
            v-if="!estaOnline"
            class="fixed top-0 left-0 right-0 z-[60] text-white text-center text-xs py-1.5 font-medium"
            style="background-color: #F59E0B;"
        >
            ⚡ Sin conexión — mostrando datos en caché
        </div>

        <!-- ══════════════════════════════════════════════════════════════════
             MOBILE — Header superior fijo
        ═══════════════════════════════════════════════════════════════════ -->
        <!-- La altura suma la zona del sistema. Con viewport-fit=cover y la barra de
             estado translúcida, el contenido se dibuja DEBAJO del reloj y la hora del
             teléfono: sin este espacio, la primera fila del encabezado no se ve. -->
        <header
            class="fixed top-0 left-0 right-0 z-40 flex items-center justify-between px-4 bg-superficie/85 backdrop-blur-xl border-b border-linea md:hidden"
            style="height: calc(3.5rem + env(safe-area-inset-top)); padding-top: env(safe-area-inset-top);"
        >
            <!-- Logo izquierda -->
            <div class="flex items-center gap-2 shrink-0">
                <img
                    v-if="marca.logo_propio"
                    :src="logoSegunTema"
                    :alt="marca.nombre"
                    class="h-8 w-auto object-contain flex-shrink-0"
                    style="max-width: 96px;"
                />
                <span
                    v-else
                    class="w-8 h-8 rounded-lg flex items-center justify-center text-sm font-semibold shrink-0"
                    :style="{ background: 'var(--marca-suave)', color: 'var(--marca)' }"
                >{{ (marca.nombre || 'B').charAt(0).toUpperCase() }}</span>
            </div>
            <!-- Título: en el hueco que quede, no centrado a la fuerza.
                 Estaba con `absolute left-1/2`, y eso centra en la pantalla completa sin
                 saber dónde termina el logo ni dónde empiezan los iconos: un título largo
                 —el nombre de un producto— se montaba encima de la lupa. Como fila flexible
                 que se encoge, no puede pisar a nadie. -->
            <span
                class="flex-1 min-w-0 px-2 text-center text-tinta-900 font-semibold text-sm truncate"
            >
                {{ title }}
            </span>
            <!-- Acciones derecha (buscar + campana + cámara + avatar) -->
            <div class="flex items-center gap-1.5 flex-shrink-0">
                <!-- El buscador se pinta como lupa: la barra no cabe aquí -->
                <div class="w-8 h-8 rounded-full bg-tinta-50 flex items-center justify-center text-tinta-500 [&_button]:!p-0 [&_svg]:!text-tinta-500">
                    <BuscadorGlobal />
                </div>

                <button
                    @click="menuNotif = !menuNotif; menuNotif && cargarNotificaciones()"
                    class="relative w-8 h-8 rounded-full bg-tinta-50 flex items-center justify-center text-tinta-500"
                    title="Notificaciones"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                    </svg>
                    <span v-if="notifNoLeidas > 0"
                        class="absolute -top-1 -right-1 min-w-[16px] h-[16px] px-1 rounded-full text-[9px] font-semibold text-white flex items-center justify-center"
                        style="background:#EF4444;">
                        {{ notifNoLeidas > 9 ? '9+' : notifNoLeidas }}
                    </span>
                </button>
                <button
                    @click="abrirCamara"
                    class="w-8 h-8 rounded-full bg-tinta-50 flex items-center justify-center text-tinta-500"
                    title="Cámara"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                </button>
                <div
                    class="w-8 h-8 rounded-full flex items-center justify-center font-semibold text-sm"
                    :style="{ background: 'var(--marca)', color: 'var(--marca-texto)' }"
                >
                    {{ inicial }}
                </div>
            </div>

            <!-- Panel de notificaciones mobile -->
            <div v-if="menuNotif" class="fixed inset-0 z-[55] md:hidden" @click="menuNotif = false">
                <div class="absolute top-14 left-2 right-2 rounded-xl shadow-xl overflow-hidden"
                    style="background:var(--superficie); border:1px solid var(--borde);" @click.stop>
                    <div class="flex items-center justify-between px-4 py-2.5 border-b border-linea">
                        <span class="text-sm font-semibold text-tinta-900">Notificaciones</span>
                        <button v-if="notifNoLeidas > 0" @click="marcarTodasLeidas"
                            class="text-xs text-aviso-azul">Marcar leídas</button>
                    </div>
                    <div class="max-h-[70vh] overflow-y-auto">
                        <div v-if="!notificaciones.length" class="px-4 py-8 text-center text-sm text-tinta-300">
                            No tienes notificaciones.
                        </div>
                        <button v-for="n in notificaciones" :key="n.id" @click="abrirNotif(n)"
                            class="flex items-start gap-3 w-full px-4 py-3 text-left hover:bg-tinta-50 border-b border-separador"
                            :style="!n.leida ? 'background:var(--pastel-azul);' : ''">
                            <span class="mt-0.5 w-2 h-2 rounded-full flex-shrink-0"
                                :style="!n.leida ? 'background:var(--marca);' : 'background:transparent;'" />
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-medium text-tinta-900">{{ n.titulo }}</span>
                                <span v-if="n.mensaje" class="block text-xs text-tinta-400 mt-0.5">{{ n.mensaje }}</span>
                                <span class="block text-[11px] text-tinta-300 mt-1">{{ n.hace }}</span>
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </header>

        <!-- ══════════════════════════════════════════════════════════════════
             Toast de notificaciones
        ═══════════════════════════════════════════════════════════════════ -->
        <Transition
            enter-active-class="transition duration-300 ease-out"
            enter-from-class="transform translate-y-2 opacity-0"
            enter-to-class="transform translate-y-0 opacity-100"
            leave-active-class="transition duration-200 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="toastVisible"
                class="fixed top-20 right-4 md:right-6 z-50 flex items-start gap-3 px-4 py-3 rounded-xl shadow-lg max-w-sm w-[calc(100vw-2rem)] md:w-auto"
                :class="{
                    'bg-pastel-verde border border-borde-aviso-verde text-aviso-verde': toastTipo === 'success',
                    'bg-pastel-rojo border border-borde-aviso-rojo text-aviso-rojo':       toastTipo === 'error',
                    'bg-pastel-azul border border-borde-aviso-azul text-aviso-azul':    toastTipo === 'info',
                }"
            >
                <!-- Ícono -->
                <svg v-if="toastTipo === 'success'" class="w-5 h-5 text-aviso-verde shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                </svg>
                <svg v-else-if="toastTipo === 'error'" class="w-5 h-5 text-aviso-rojo shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
                <svg v-else class="w-5 h-5 text-aviso-azul shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>

                <!-- Mensaje -->
                <p class="text-sm font-medium flex-1">{{ toastMensaje }}</p>

                <!-- Botón cerrar -->
                <button @click="toastVisible = false" class="shrink-0 opacity-60 hover:opacity-100 transition-opacity">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
        </Transition>

        <!-- ══════════════════════════════════════════════════════════════════
             CONTENIDO PRINCIPAL
        ═══════════════════════════════════════════════════════════════════ -->
        <AvisoLicencia class="md:ml-64 con-espacio-superior" />

        <!-- Los espacios los pone la clase `con-espacio-de-barras`, definida en
             app.blade.php: suma las zonas del sistema del teléfono en móvil y el alto de
             la barra fija en escritorio. En línea no funcionaba — un estilo en línea le
             gana a las clases, y el contenido se metía bajo la barra superior. -->
        <main class="px-4 md:ml-64 md:px-8 con-espacio-de-barras">
            <slot />
        </main>

        <!-- ══════════════════════════════════════════════════════════════════
             MOBILE — Barra de navegación inferior
        ═══════════════════════════════════════════════════════════════════ -->
        <!-- El fondo era `white` fijo, y en modo de noche quedaba una franja blanca al
             pie de la pantalla. Y la altura no contaba la barra de gestos del teléfono,
             así que abajo del último ícono asomaba el fondo de la página. -->
        <nav
            class="md:hidden fixed bottom-0 left-0 right-0 z-40 flex items-start justify-around bg-superficie"
            style="height: calc(64px + env(safe-area-inset-bottom));
                   padding-bottom: env(safe-area-inset-bottom);
                   box-shadow: 0 -1px 8px var(--sombra-barra);"
        >
            <!-- Inicio -->
            <button
                @click="router.visit('/dashboard')"
                class="flex flex-col items-center justify-center gap-0.5 min-w-0 flex-1 py-2"
                :style="{ color: isActive('/dashboard') ? 'var(--marca)' : 'var(--tinta-400)' }"
            >
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                </svg>
                <span style="font-size: 10px;" class="font-medium">Inicio</span>
            </button>

            <!-- Clientes -->
            <button
                @click="router.visit('/clientes')"
                class="flex flex-col items-center justify-center gap-0.5 min-w-0 flex-1 py-2"
                :style="{ color: isActive('/clientes') ? 'var(--marca)' : 'var(--tinta-400)' }"
            >
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-2 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                </svg>
                <span style="font-size: 10px;" class="font-medium">Clientes</span>
            </button>

            <!-- Cotizaciones -->
            <button
                @click="router.visit('/cotizaciones')"
                class="flex flex-col items-center justify-center gap-0.5 min-w-0 flex-1 py-2"
                :style="{ color: isActive('/cotizaciones') ? 'var(--marca)' : 'var(--tinta-400)' }"
            >
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                <span style="font-size: 10px;" class="font-medium">Cotizar</span>
            </button>

            <!-- Productos -->
            <button
                v-if="user?.rol !== 'operario'"
                @click="router.visit('/productos')"
                class="flex flex-col items-center justify-center gap-0.5 min-w-0 flex-1 py-2"
                :style="{ color: isActive('/productos') ? 'var(--marca)' : 'var(--tinta-400)' }"
            >
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7l8 4" />
                </svg>
                <span style="font-size: 10px;" class="font-medium">Productos</span>
            </button>

            <!-- Más (abre drawer) -->
            <button
                @click="toggleDrawer"
                class="flex flex-col items-center justify-center gap-0.5 min-w-0 flex-1 py-2"
                :style="{ color: drawerAbierto ? 'var(--marca)' : 'var(--tinta-400)' }"
            >
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                </svg>
                <span style="font-size: 10px;" class="font-medium">Más</span>
            </button>
        </nav>
    </div>

    <!-- ════════════════════════════════════════════════════════════════════════
         MOBILE — Drawer (menú completo deslizable desde la derecha)
    ═══════════════════════════════════════════════════════════════════════ -->
    <teleport to="body">
        <div v-if="drawerAbierto" class="md:hidden fixed inset-0 z-50 flex justify-end">
            <!-- Overlay oscuro -->
            <div
                class="absolute inset-0"
                style="background: rgba(0,0,0,0.45);"
                @click="drawerAbierto = false"
            />

            <!-- Panel del drawer -->
            <div
                class="relative flex flex-col h-full overflow-y-auto shadow-2xl"
                style="width: 280px; background: var(--superficie);"
            >
                <!-- Cabecera del drawer -->
                <div
                    class="flex items-center justify-between px-5 py-4 shrink-0"
                    style="background-color: var(--marca);"
                >
                    <img
                        :src="marca.logo"
                        class="h-7 w-auto object-contain"
                        :alt="marca.nombre"
                    />
                    <button
                        @click="drawerAbierto = false"
                        class="w-8 h-8 flex items-center justify-center rounded-lg"
                        style="background: rgba(255,255,255,0.15);"
                    >
                        <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <!-- Perfil usuario -->
                <div class="flex items-center gap-3 px-5 py-4 border-b border-linea">
                    <div
                        class="w-10 h-10 rounded-full flex items-center justify-center text-base font-semibold shrink-0"
                        style="background-color: var(--marca); color: white;"
                    >
                        {{ inicial }}
                    </div>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-tinta-900 truncate">{{ user?.name }}</p>
                        <p class="text-xs text-tinta-300 truncate">{{ rolLabel }}</p>
                    </div>
                </div>

                <!-- Selector de sede activa -->
                <div v-if="mostrarSelectorSede" class="px-5 py-3 border-b border-linea">
                    <label class="block text-xs font-semibold text-tinta-300 uppercase tracking-[0.12em] mb-1.5">Sede</label>
                    <select
                        :value="sedeActivaId"
                        @change="cambiarSede(Number($event.target.value))"
                        class="w-full rounded-lg border border-linea px-3 py-2 text-sm text-tinta-700 bg-superficie focus:outline-none focus:ring-4 focus:ring-[var(--marca-suave)]"
                    >
                        <option v-if="puedeTodasSedes" :value="0">Todas las sedes</option>
                        <option v-for="s in sedesDisponibles" :key="s.id" :value="s.id">{{ s.nombre }}</option>
                    </select>
                </div>

                <!-- Ítems de navegación -->
                <nav class="flex-1 px-3 py-3">
                    <p class="text-xs font-semibold text-tinta-300 uppercase tracking-[0.12em] px-3 mb-2">Navegación</p>
                    <template v-for="sec in navItems" :key="sec.label ?? 'inicio'">
                        <!-- Igual que en el escritorio: el título despliega su categoría. -->
                        <button
                            v-if="sec.label"
                            type="button"
                            @click="alternarRama('seccion:' + sec.label)"
                            class="w-full flex items-center justify-between gap-2 px-3 mt-4 mb-1"
                            :aria-expanded="seccionAbierta(sec)"
                        >
                            <span class="flex items-center gap-2.5 min-w-0">
                                <IconoMenu :nombre="sec.icon" clase="w-4 h-4 shrink-0"
                                    :class="seccionAbierta(sec) ? 'text-[var(--marca)]' : 'text-tinta-300'" />
                                <span class="text-xs font-semibold uppercase tracking-[0.12em] truncate"
                                    :class="seccionAbierta(sec) ? 'text-tinta-600' : 'text-tinta-400'">{{ sec.label }}</span>
                            </span>
                            <svg
                                class="w-3 h-3 shrink-0 text-tinta-300 transition-transform duration-300"
                                :class="seccionAbierta(sec) ? 'rotate-180' : ''"
                                fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>

                        <div
                            class="grid transition-all duration-300 ease-out"
                            :class="seccionAbierta(sec) ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0'"
                        >
                            <div class="overflow-hidden">
                    
                        <!-- Rama del menú -->
                        <div v-for="item in sec.ramas" :key="item.href ?? item.label" class="mb-1">
                            <div class="flex items-stretch gap-1">
                                <a
                                    :href="item.href"
                                    class="flex-1 min-w-0 flex items-center gap-3 rounded-lg px-3 py-3 text-sm font-medium transition-colors relative
                                           before:absolute before:left-0 before:top-2 before:bottom-2 before:w-[3px] before:rounded-full before:transition-colors"
                                    :class="isActive(item.href)
                                        ? 'bg-realce text-[var(--marca)] font-semibold before:bg-[var(--marca)]'
                                        : 'text-tinta-700 hover:bg-realce before:bg-transparent'"
                                    @click.prevent="navegar(item.href)"
                                >
                                    <IconoMenu :nombre="item.icon" clase="w-5 h-5 shrink-0" />
                                    <span class="truncate">{{ item.label }}</span>
                                </a>

                                <!-- Área de toque de 44 puntos: es la medida mínima
                                     que recomienda Apple para algo que se pulsa con
                                     el dedo. Un chevron pequeño se falla siempre. -->
                                <button
                                    v-if="item.hijos?.length"
                                    type="button"
                                    @click="alternarRama(item.href ?? item.label)"
                                    class="shrink-0 w-11 rounded-lg flex items-center justify-center text-tinta-300 active:bg-tinta-100 transition-colors"
                                    :aria-expanded="ramaAbierta(item)"
                                    :aria-label="ramaAbierta(item) ? 'Plegar ' + item.label : 'Desplegar ' + item.label"
                                >
                                    <svg class="w-4 h-4 transition-transform duration-300"
                                         :class="ramaAbierta(item) ? 'rotate-90' : ''"
                                         fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                                    </svg>
                                </button>
                            </div>

                            <div
                                v-if="item.hijos?.length"
                                class="grid transition-all duration-300 ease-out"
                                :class="ramaAbierta(item) ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0'"
                            >
                                <div class="overflow-hidden">
                                    <div class="ml-5 pl-3 mt-1 space-y-1 border-l border-linea">
                                        <a
                                            v-for="hijo in item.hijos"
                                            :key="hijo.href"
                                            :href="hijo.href"
                                            class="flex items-center gap-2.5 rounded-lg px-3 py-2.5 text-[13px] transition-colors"
                                            :class="isActive(hijo.href)
                                                ? 'bg-realce text-[var(--marca)] font-semibold'
                                                : 'text-tinta-500 hover:bg-realce'"
                                            @click.prevent="navegar(hijo.href)"
                                        >
                                            <IconoMenu :nombre="hijo.icon" clase="w-4 h-4 shrink-0 opacity-70" />
                                            <span class="truncate">{{ hijo.label }}</span>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                            </div>
                        </div>
                    </template>
                </nav>

                <!-- Acciones al pie del drawer -->
                <!-- El espacio de abajo suma la barra de gestos del teléfono: sin él, el
                     botón de cerrar sesión queda debajo de ella y no se puede tocar. -->
                <div
                    class="px-3 py-4 border-t border-linea space-y-1 shrink-0"
                    style="padding-bottom: calc(1rem + env(safe-area-inset-bottom));"
                >
                    <!-- Día · Noche · Automático.
                         Solo estaban en el menú de escritorio, así que en el teléfono
                         —donde más se necesita, porque es el que se usa de noche— no
                         había forma de cambiarlo. -->
                    <div class="pb-3 mb-1 border-b border-linea">
                        <p class="text-[11px] font-semibold text-tinta-400 uppercase tracking-[0.12em] px-3 mb-2">
                            Apariencia
                        </p>
                        <div class="flex items-center gap-0.5 p-0.5 rounded-xl bg-tinta-100 mx-2">
                            <button
                                v-for="opcion in tema.opciones"
                                :key="'movil-' + opcion.valor"
                                type="button"
                                @click="tema.elegir(opcion.valor)"
                                class="flex-1 flex items-center justify-center gap-1.5 py-2 rounded-lg text-xs font-medium transition-all"
                                :class="tema.preferencia.value === opcion.valor
                                    ? 'bg-superficie text-tinta-900 shadow-sm'
                                    : 'text-tinta-400'"
                            >
                                <svg v-if="opcion.icono === 'sol'" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <circle cx="12" cy="12" r="4"/>
                                    <path stroke-linecap="round" d="M12 3v2m0 14v2M3 12h2m14 0h2M5.6 5.6l1.4 1.4m10 10l1.4 1.4m0-12.8l-1.4 1.4m-10 10l-1.4 1.4"/>
                                </svg>
                                <svg v-else-if="opcion.icono === 'luna'" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 12.8A9 9 0 1111.2 3a7 7 0 009.8 9.8z"/>
                                </svg>
                                <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                    <circle cx="12" cy="12" r="9"/>
                                    <path stroke-linecap="round" d="M12 7.5V12l3 2"/>
                                </svg>
                                {{ opcion.etiqueta }}
                            </button>
                        </div>
                        <p v-if="tema.preferencia.value === 'automatico'" class="text-[10px] text-tinta-300 mt-1.5 px-3 leading-snug">
                            {{ tema.explicacionAutomatico.value }}
                        </p>
                    </div>

                    <button
                        @click="irPerfil"
                        class="flex items-center gap-3 w-full px-3 py-3 rounded-xl text-sm font-medium text-tinta-700 hover:bg-tinta-100 transition-colors"
                    >
                        <svg class="w-5 h-5 text-tinta-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                        </svg>
                        Ver perfil
                    </button>
                    <button
                        @click="cerrarSesion"
                        class="flex items-center gap-3 w-full px-3 py-3 rounded-xl text-sm font-medium text-aviso-rojo hover:bg-pastel-rojo transition-colors"
                    >
                        <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                        </svg>
                        Cerrar sesión
                    </button>
                </div>
            </div>
        </div>
    </teleport>

    <!-- ════════════════════════════════════════════════════════════════════════
         MODAL CÁMARA
    ═══════════════════════════════════════════════════════════════════════ -->
    <teleport to="body">
        <div v-if="modalCamara" class="fixed inset-0 z-50 flex flex-col" style="background: black;">
            <button
                @click="cerrarCamara"
                class="absolute top-4 right-4 z-10 w-10 h-10 rounded-full flex items-center justify-center"
                style="background: rgba(255,255,255,0.2);"
            >
                <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>

            <div v-if="fotoPreview" class="flex flex-col flex-1">
                <img :src="fotoPreview" class="flex-1 object-contain w-full" />
                <div class="flex gap-4 p-6 shrink-0">
                    <button @click="repetirFoto" class="flex-1 py-3 rounded-2xl text-white font-semibold"
                        style="background: rgba(255,255,255,0.15);">Repetir</button>
                    <button @click="usarFoto" class="flex-1 py-3 rounded-2xl font-semibold text-white"
                        style="background-color: var(--marca);">Usar foto</button>
                </div>
            </div>

            <div v-else class="flex flex-col flex-1">
                <video ref="videoRef" class="flex-1 object-cover w-full" autoplay playsinline muted />
                <div class="flex justify-center pb-8 pt-4 shrink-0">
                    <button @click="capturarFoto"
                        class="w-16 h-16 rounded-full flex items-center justify-center border-4 border-superficie"
                        style="background: rgba(255,255,255,0.2);">
                        <div class="w-12 h-12 rounded-full bg-superficie" />
                    </button>
                </div>
            </div>
            <canvas ref="canvasRef" class="hidden" />
        </div>
    </teleport>

    <ModalQR :show="showQR" @close="showQR = false" />

    <!-- Asistente de IA: disponible en cualquier pantalla. Al vivir en el
         layout, la conversación sobrevive al navegar entre módulos. -->
    <AsistenteBurbuja ref="asistenteRef" />
    <ChatBurbuja ref="chatRef" />
    <BotonesFlotantes
        :sin-leer="chatRef?.sinLeer ?? 0"
        @ia="asistenteRef?.abrir()"
        @chat="chatRef?.abrir()"
    />

    <!-- Modal disciplinas pendientes (operarios) -->
    <teleport to="body">
        <div v-if="modalDisciplinas" class="fixed inset-0 z-[70] flex items-center justify-center p-4"
            style="background:rgba(0,0,0,0.55);">
            <div class="bg-superficie rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
                <div class="px-5 py-4 border-b border-linea">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-pastel-rojo-2 flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5 text-aviso-rojo" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-sm font-semibold text-tinta-900">Documentos pendientes de firma</h3>
                            <p class="text-xs text-tinta-400">{{ disciplinasPendientes.length }} documento(s) sin firmar</p>
                        </div>
                    </div>
                </div>
                <div class="px-5 py-3 max-h-56 overflow-y-auto space-y-2">
                    <div v-for="d in disciplinasPendientes" :key="d.id"
                        class="bg-pastel-rojo rounded-xl px-4 py-3 border border-borde-aviso-rojo">
                        <p class="text-xs font-semibold text-aviso-rojo">{{ d.tipo_label }}</p>
                        <p class="text-xs text-tinta-500 mt-0.5">{{ d.descripcion }}</p>
                        <p class="text-xs text-tinta-300 mt-0.5">{{ d.fecha }}</p>
                    </div>
                </div>
                <div class="px-5 py-4 border-t border-linea">
                    <button @click="modalDisciplinas = false"
                        class="w-full py-2.5 rounded-xl text-white text-sm font-semibold"
                        style="background:var(--marca);">
                        Entendido
                    </button>
                </div>
            </div>
        </div>
    </teleport>

    <!-- ════════════════════════════════════════════════════════════════════════
         BANNER INSTALACIÓN PWA
    ═══════════════════════════════════════════════════════════════════════ -->
    <Transition name="slide-up">
        <div
            v-if="mostrarPWA && !yaInstalada"
            class="fixed bottom-20 md:bottom-4 left-4 right-4 md:left-auto md:right-4 md:w-80 z-50"
        >
            <div class="bg-superficie rounded-2xl shadow-2xl border border-linea overflow-hidden">
                <div class="px-4 py-3 flex items-center gap-3" style="background-color: var(--marca);">
                    <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0" style="background: rgba(255,255,255,0.2);">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                        </svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-white font-semibold text-sm">Instalar Briela</p>
                        <p class="text-xs" style="color: rgba(255,255,255,0.7);">Acceso rápido desde tu pantalla de inicio</p>
                    </div>
                    <button
                        @click="descartarPWA"
                        class="p-1 rounded-lg flex-shrink-0 transition-colors"
                        style="color: rgba(255,255,255,0.6);"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
                <div class="px-4 py-3 flex gap-2">
                    <button
                        @click="descartarPWA"
                        class="flex-1 py-2 text-sm font-medium rounded-xl hover:bg-realce transition-colors"
                        style="color: var(--texto-3);"
                    >
                        Ahora no
                    </button>
                    <button
                        @click="instalarPWA"
                        class="flex-1 py-2 text-white text-sm font-semibold rounded-xl transition-colors"
                        style="background-color: var(--marca);"
                    >
                        Instalar
                    </button>
                </div>
            </div>
        </div>
    </Transition>
</template>
