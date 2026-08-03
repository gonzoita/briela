<script setup>
import { ref, computed, watch, onMounted, onUnmounted, nextTick, provide } from 'vue'
import { usePage, router } from '@inertiajs/vue3'
import { Head } from '@inertiajs/vue3'
import ModalQR from '@/Components/ModalQR.vue'
import AsistenteBurbuja from '@/Components/AsistenteBurbuja.vue'
import BuscadorGlobal from '@/Components/BuscadorGlobal.vue'

const props = defineProps({
    title: { type: String, default: '' },
})

const emit = defineEmits(['foto-capturada'])

// ─── Auth ─────────────────────────────────────────────────────────────────────
const page     = usePage()

// Logo y nombre salen de Ajustes, no del código: así el sistema se puede
// entregar a otra empresa sin tocar una sola línea.
const marca = computed(() => page.props.marca ?? {
    nombre: 'Mi empresa',
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
        { label: 'Ventas', items: [
            { label: 'CRM',          href: '/crm',             icon: 'crm',        permiso: 'crm.ver' },
            { label: 'Reportes',     href: '/crm/reportes',    icon: 'reportes',   permiso: 'crm.ver', sub: true },
            { label: 'Formularios',  href: '/crm/formularios', icon: 'formulario', permiso: 'crm.editar', sub: true },
            { label: 'Cotizaciones', href: '/cotizaciones',    icon: 'cotizacion', permiso: 'cotizaciones.ver' },
            { label: 'Comisiones',   href: '/comisiones',      icon: 'comisiones', permiso: 'comisiones.ver' },
        ]},
        { label: 'Inventario', items: [
            { label: 'Productos',         href: '/productos',              icon: 'productos',   permiso: 'productos.ver' },
            { label: 'Ensambles',         href: '/ensambles',              icon: 'ensamble',    permiso: 'ensambles.ver' },
            { label: 'Stock & Materiales',href: '/inventario',             icon: 'inventario',  permiso: 'inventario.ver', sub: true },
            { label: 'Movimientos',       href: '/inventario/movimientos', icon: 'movimientos', permiso: 'inventario.ver', sub: true },
        ]},
        { label: 'Compras', items: [
            { label: 'Proveedores',       href: '/compras/proveedores', icon: 'proveedor', permiso: 'proveedores.ver' },
            { label: 'Solicitudes',       href: '/compras/solicitudes', icon: 'solicitud', permiso: 'solicitudes.ver', sub: true },
            { label: 'Órdenes de Compra', href: '/compras/ordenes',     icon: 'oc',        permiso: 'ordenes.ver',     sub: true },
        ]},
        { label: 'Logística', items: [
            { label: 'Remisiones', href: '/logistica/remisiones', icon: 'camion', permiso: 'remisiones.ver' },
        ]},
        { label: 'Financiero', items: [
            { label: 'Cartera', href: '/financiero/cartera', icon: 'cartera', permiso: 'cartera.ver' },
        ]},
        { label: 'Producción', items: [
            { label: 'Órdenes de Producción', href: '/produccion/ops',         icon: 'clipboard', permiso: 'ops.ver' },
            { label: 'Programador',           href: '/produccion/programador', icon: 'calendar',  permiso: 'programador.ver' },
            { label: 'Trabajos',              href: '/trabajos',               icon: 'trabajos',  permiso: 'trabajos.ver' },
            // Panel personal del operario: no depende de permisos de módulo.
            ...(rol === 'operario' ? [{ label: 'Mi Panel', href: '/mi-panel', icon: 'mi-panel' }] : []),
        ]},
        { label: 'RRHH', items: [
            { label: 'Colaboradores', href: '/rrhh/operarios', icon: 'workers', permiso: 'rrhh.ver' },
        ]},
        { label: 'Mantenimiento', items: [
            { label: 'Dashboard',      href: '/mantenimiento',                icon: 'wrench',   permiso: 'mantenimiento.ver' },
            { label: 'Equipos',        href: '/mantenimiento/equipos',        icon: 'gear',     permiso: 'mantenimiento.ver' },
            { label: 'Mantenimientos', href: '/mantenimiento/mantenimientos', icon: 'calendar', permiso: 'mantenimiento.ver' },
        ]},
        { label: 'Reportes', items: [
            { label: 'Informes', href: '/informes', icon: 'chart', permiso: 'informes.ver' },
        ]},
        { label: 'Capacitación', items: [
            // Todos pueden ver sus propios cursos.
            { label: 'Mi Capacitación', href: '/mi-capacitacion',              icon: 'capacitacion' },
            { label: 'Cursos',          href: '/capacitacion/cursos',          icon: 'capacitacion', permiso: 'capacitacion.editar' },
            { label: 'Invitaciones',    href: '/capacitacion/invitaciones',    icon: 'capacitacion', permiso: 'capacitacion.crear', sub: true },
        ]},
        { label: 'Marketing', items: [
            { label: 'Redes Sociales', href: '/rrss', icon: 'megaphone', permiso: 'rrss.ver' },
        ]},
        { label: 'Asistente', items: [
            // Disponible para todos: responde sobre la marca, no sobre datos.
            { label: nombreAsistente.value, href: '/asistente', icon: 'chat' },
        ]},
        { label: 'Sistema', items: [
            { label: 'Configuración',  href: '/configuracion',                icon: 'configurador', permiso: 'configuracion.ver' },
            { label: 'Plantillas PDF', href: '/configuracion/plantillas-pdf', icon: 'pdf',          permiso: 'configuracion.editar' },
            { label: 'Auditoría',      href: '/auditoria',                    icon: 'chart',        permiso: 'auditoria.ver' },
        ]},
    ]

    const items = []

    for (const grupo of grupos) {
        const visibles = grupo.items.filter(i => puede(i.permiso))
        if (!visibles.length) continue
        if (grupo.label) items.push({ divider: true, label: grupo.label })
        items.push(...visibles)
    }

    return items
})

// ─── Menú usuario (desktop topbar) ───────────────────────────────────────────
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

    <div class="min-h-screen" style="background-color: #F8F9FA;">

        <!-- ══════════════════════════════════════════════════════════════════
             DESKTOP — Sidebar fijo izquierdo
        ═══════════════════════════════════════════════════════════════════ -->
        <aside
            class="hidden md:flex fixed top-0 left-0 h-screen w-64 flex-col z-40"
            style="background-color: var(--marca);"
        >
            <!-- Logo -->
            <div class="px-5 py-5 border-b shrink-0" style="border-color: rgba(255,255,255,0.12);">
                <div class="bg-white rounded-xl px-3 py-2.5 flex items-center justify-center">
                    <img
                        :src="marca.logo"
                        class="h-9 w-auto object-contain"
                        :alt="marca.nombre"
                    />
                </div>
            </div>

            <!-- Navegación -->
            <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-1">
                <template v-for="item in navItems" :key="item.href ?? item.label">
                    <!-- Separador de sección -->
                    <p
                        v-if="item.divider"
                        class="text-xs font-semibold uppercase tracking-wider px-3 pt-4 pb-1"
                        style="color: rgba(255,255,255,0.4);"
                    >
                        {{ item.label }}
                    </p>
                    <!-- Link normal -->
                    <a
                        v-else
                        :href="item.href"
                        class="flex items-center gap-3 rounded-xl transition-colors"
                        :class="[
                            item.sub
                                ? 'pl-7 pr-3 py-2 text-xs font-medium'
                                : 'px-3 py-2.5 text-sm font-medium',
                            isActive(item.href)
                                ? 'bg-white/15 text-white'
                                : 'text-blue-100 hover:bg-white/10 hover:text-white',
                        ]"
                        @click.prevent="router.visit(item.href)"
                    >
                        <span v-if="item.sub" class="w-1 h-1 rounded-full bg-blue-300/60 shrink-0 -ml-1"></span>
                        <!-- home -->
                        <svg v-if="item.icon === 'home'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                        </svg>
                        <!-- clipboard -->
                        <svg v-if="item.icon === 'clipboard'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                        </svg>
                        <!-- multimedia -->
                        <svg v-if="item.icon === 'multimedia'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                        <!-- clientes -->
                        <svg v-if="item.icon === 'clientes'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-2 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                        </svg>
                        <!-- productos -->
                        <svg v-if="item.icon === 'productos'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7l8 4" />
                        </svg>
                        <!-- users -->
                        <svg v-if="item.icon === 'users'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                        </svg>
                        <!-- cotizacion -->
                        <svg v-if="item.icon === 'cotizacion'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        <!-- comisiones -->
                        <svg v-if="item.icon === 'comisiones'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <!-- calculadora -->
                        <svg v-if="item.icon === 'calculadora'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                        </svg>
                        <!-- insumos -->
                        <svg v-if="item.icon === 'insumos'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
                        </svg>
                        <!-- configurador -->
                        <svg v-if="item.icon === 'configurador'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        <!-- ensamble -->
                        <svg v-if="item.icon === 'ensamble'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                        </svg>
                        <!-- database -->
                        <svg v-if="item.icon === 'database'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 7C4 5.343 7.582 4 12 4s8 1.343 8 3v2c0 1.657-3.582 3-8 3S4 10.657 4 9V7z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 9v4c0 1.657 3.582 3 8 3s8-1.343 8-3V9" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 13v4c0 1.657 3.582 3 8 3s8-1.343 8-3v-4" />
                        </svg>
                        <!-- template -->
                        <svg v-if="item.icon === 'template'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                        </svg>
                        <!-- trabajos -->
                        <svg v-if="item.icon === 'trabajos'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4" />
                        </svg>
                        <!-- workers -->
                        <svg v-if="item.icon === 'workers'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                        </svg>
                        <!-- capacitacion -->
                        <svg v-if="item.icon === 'capacitacion'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.422A12.083 12.083 0 0121 15.5V17a2 2 0 01-2 2H5a2 2 0 01-2-2v-1.5c0-.994.212-1.964.582-2.858L12 14z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 14v7" />
                        </svg>
                        <!-- wrench -->
                        <svg v-if="item.icon === 'wrench'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        <!-- gear -->
                        <svg v-if="item.icon === 'gear'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" />
                        </svg>
                        <!-- calendar -->
                        <svg v-if="item.icon === 'calendar'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                        <!-- webhook -->
                        <svg v-if="item.icon === 'webhook'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                        </svg>
                        <!-- mi-panel -->
                        <svg v-if="item.icon === 'mi-panel'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 5a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1V5zm10 0a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1V5zM4 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1v-4zm10 0a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z" />
                        </svg>
                        <!-- chart -->
                        <svg v-if="item.icon === 'chart'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        <!-- megaphone -->
                        <svg v-if="item.icon === 'megaphone'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z" />
                        </svg>
                        <!-- camion -->
                        <svg v-if="item.icon === 'camion'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 17H3V5h12v12H9zm0 0h6m-6 0a2 2 0 104 0m6 0a2 2 0 104 0M15 5h4l2 4v8h-6V5z"/>
                        </svg>
                        <!-- cartera -->
                        <svg v-if="item.icon === 'cartera'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <!-- crm -->
                        <svg v-if="item.icon === 'crm'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2a1 1 0 01-.293.707L13 13.414V19a1 1 0 01-.553.894l-4 2A1 1 0 017 21v-7.586L3.293 6.707A1 1 0 013 6V4z"/>
                        </svg>
                        <!-- chat (asistente IA) -->
                        <svg v-if="item.icon === 'chat'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-6l-4 4v-4z"/>
                        </svg>
                        <!-- reportes -->
                        <svg v-if="item.icon === 'reportes' && !item.sub" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                        </svg>
                        <!-- formulario -->
                        <svg v-if="item.icon === 'formulario' && !item.sub" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
                        </svg>
                        <!-- pdf -->
                        <svg v-if="item.icon === 'pdf'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                        </svg>
                        <!-- inventario -->
                        <svg v-if="item.icon === 'inventario'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                        </svg>
                        <!-- movimientos -->
                        <svg v-if="item.icon === 'movimientos'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 10h16M4 14h16M4 18h16"/>
                        </svg>
                        <!-- proveedor -->
                        <svg v-if="item.icon === 'proveedor'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                        </svg>
                        <!-- solicitud -->
                        <svg v-if="item.icon === 'solicitud'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                        </svg>
                        <!-- oc (orden compra) -->
                        <svg v-if="item.icon === 'oc'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/>
                        </svg>
                        {{ item.label }}
                    </a>
                </template>
            </nav>

            <!-- Usuario (pie del sidebar) -->
            <div class="px-3 py-4 border-t shrink-0" style="border-color: rgba(255,255,255,0.12);">
                <button
                    @click="irPerfil"
                    class="flex items-center gap-3 w-full px-3 py-2.5 rounded-xl transition-colors hover:bg-white/10"
                >
                    <div
                        class="w-8 h-8 rounded-full flex items-center justify-center text-sm font-bold shrink-0"
                        style="background: rgba(255,255,255,0.2); color: white;"
                    >
                        {{ inicial }}
                    </div>
                    <div class="min-w-0 text-left">
                        <p class="text-white text-sm font-medium truncate">{{ user?.name }}</p>
                        <p class="text-blue-300 text-xs truncate">{{ rolLabel }}</p>
                    </div>
                </button>
                <button
                    @click="cerrarSesion"
                    class="flex items-center gap-3 w-full px-3 py-2 mt-1 rounded-xl text-sm text-blue-200 hover:bg-white/10 hover:text-white transition-colors"
                >
                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
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
            class="hidden md:flex fixed top-0 left-64 right-0 z-30 items-center justify-between px-8 shadow-sm"
            style="height: 64px; background: white;"
        >
            <!-- Título de la página -->
            <h1 class="text-lg font-semibold text-gray-800">{{ title }}</h1>

            <!-- Acciones topbar -->
            <div class="flex items-center gap-3">
                <!-- Buscador global -->
                <BuscadorGlobal />

                <!-- Selector de sede activa -->
                <div v-if="mostrarSelectorSede" class="flex items-center gap-2">
                    <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                    </svg>
                    <select
                        :value="sedeActivaId"
                        @change="cambiarSede(Number($event.target.value))"
                        class="rounded-lg border border-gray-200 px-2 py-1.5 text-sm text-gray-700 bg-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                        <option v-if="puedeTodasSedes" :value="0">Todas las sedes</option>
                        <option v-for="s in sedesDisponibles" :key="s.id" :value="s.id">{{ s.nombre }}</option>
                    </select>
                </div>

                <!-- Botón QR -->
                <button
                    @click="showQR = true"
                    class="w-9 h-9 rounded-xl flex items-center justify-center text-gray-500 hover:bg-gray-100 transition-colors"
                    title="Lector QR"
                >
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z" />
                    </svg>
                </button>

                <!-- Botón cámara -->
                <button
                    @click="abrirCamara"
                    class="w-9 h-9 rounded-xl flex items-center justify-center text-gray-500 hover:bg-gray-100 transition-colors"
                    title="Cámara"
                >
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                </button>

                <!-- Campanita de notificaciones -->
                <div class="relative">
                    <button
                        @click="menuNotif = !menuNotif; menuNotif && cargarNotificaciones()"
                        class="relative w-9 h-9 rounded-xl flex items-center justify-center text-gray-500 hover:bg-gray-100 transition-colors"
                        title="Notificaciones"
                    >
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                        </svg>
                        <span v-if="notifNoLeidas > 0"
                            class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 rounded-full text-[10px] font-bold text-white flex items-center justify-center"
                            style="background:#EF4444;">
                            {{ notifNoLeidas > 9 ? '9+' : notifNoLeidas }}
                        </span>
                    </button>

                    <!-- Dropdown notificaciones -->
                    <div v-if="menuNotif"
                        class="absolute right-0 top-12 w-80 rounded-xl shadow-xl overflow-hidden z-50"
                        style="background:white; border:1px solid #E5E7EB;">
                        <div class="flex items-center justify-between px-4 py-2.5 border-b border-gray-100">
                            <span class="text-sm font-semibold text-gray-800">Notificaciones</span>
                            <button v-if="notifNoLeidas > 0" @click="marcarTodasLeidas"
                                class="text-xs text-blue-600 hover:underline">Marcar todas leídas</button>
                        </div>
                        <div class="max-h-96 overflow-y-auto">
                            <div v-if="!notificaciones.length" class="px-4 py-8 text-center text-sm text-gray-400">
                                No tienes notificaciones.
                            </div>
                            <button v-for="n in notificaciones" :key="n.id" @click="abrirNotif(n)"
                                class="flex items-start gap-3 w-full px-4 py-3 text-left hover:bg-gray-50 border-b border-gray-50"
                                :style="!n.leida ? 'background:#EFF6FF;' : ''">
                                <span class="mt-0.5 w-2 h-2 rounded-full flex-shrink-0"
                                    :style="!n.leida ? 'background:var(--marca);' : 'background:transparent;'" />
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium text-gray-800">{{ n.titulo }}</span>
                                    <span v-if="n.mensaje" class="block text-xs text-gray-500 mt-0.5">{{ n.mensaje }}</span>
                                    <span class="block text-[11px] text-gray-400 mt-1">{{ n.hace }}</span>
                                </span>
                            </button>
                        </div>
                    </div>
                    <div v-if="menuNotif" class="fixed inset-0 z-40" @click="menuNotif = false" />
                </div>

                <!-- Separador -->
                <div class="w-px h-6 bg-gray-200" />

                <!-- Avatar + menú usuario -->
                <div class="relative">
                    <button
                        @click="menuUsuario = !menuUsuario"
                        class="flex items-center gap-2.5 px-3 py-1.5 rounded-xl hover:bg-gray-100 transition-colors"
                    >
                        <div
                            class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold"
                            style="background-color: var(--marca); color: white;"
                        >
                            {{ inicial }}
                        </div>
                        <div class="text-left hidden lg:block">
                            <p class="text-sm font-medium text-gray-800 leading-none">{{ user?.name }}</p>
                            <p class="text-xs text-gray-400 leading-none mt-0.5">{{ rolLabel }}</p>
                        </div>
                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                        </svg>
                    </button>

                    <!-- Dropdown -->
                    <div
                        v-if="menuUsuario"
                        class="absolute right-0 top-12 w-48 rounded-xl shadow-xl overflow-hidden z-50"
                        style="background: white; border: 1px solid #E5E7EB;"
                    >
                        <button @click="irPerfil"
                            class="flex items-center gap-2 w-full px-4 py-3 text-sm text-gray-700 hover:bg-gray-50">
                            <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                            </svg>
                            Ver perfil
                        </button>
                        <div class="border-t border-gray-100" />
                        <button @click="cerrarSesion"
                            class="flex items-center gap-2 w-full px-4 py-3 text-sm text-red-600 hover:bg-red-50">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
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
        <header
            class="fixed top-0 left-0 right-0 z-40 flex items-center justify-between px-4 h-14 bg-[var(--marca)] md:hidden"
        >
            <!-- Logo izquierda -->
            <div class="flex items-center gap-2 min-w-0">
                <img
                    :src="marca.logo"
                    :alt="marca.nombre"
                    class="h-8 w-auto object-contain flex-shrink-0"
                    style="max-width: 120px;"
                />
            </div>
            <!-- Título centrado -->
            <span
                class="absolute left-1/2 -translate-x-1/2 text-white font-semibold text-sm truncate max-w-[140px]"
            >
                {{ title }}
            </span>
            <!-- Acciones derecha (buscar + campana + cámara + avatar) -->
            <div class="flex items-center gap-1.5 flex-shrink-0">
                <!-- El buscador se pinta como lupa: la barra no cabe aquí -->
                <div class="w-8 h-8 rounded-full bg-white/20 flex items-center justify-center text-white [&_button]:!p-0 [&_svg]:!text-white">
                    <BuscadorGlobal />
                </div>

                <button
                    @click="menuNotif = !menuNotif; menuNotif && cargarNotificaciones()"
                    class="relative w-8 h-8 rounded-full bg-white/20 flex items-center justify-center"
                    title="Notificaciones"
                >
                    <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
                    </svg>
                    <span v-if="notifNoLeidas > 0"
                        class="absolute -top-1 -right-1 min-w-[16px] h-[16px] px-1 rounded-full text-[9px] font-bold text-white flex items-center justify-center"
                        style="background:#EF4444;">
                        {{ notifNoLeidas > 9 ? '9+' : notifNoLeidas }}
                    </span>
                </button>
                <button
                    @click="abrirCamara"
                    class="w-8 h-8 rounded-full bg-white/20 flex items-center justify-center"
                    title="Cámara"
                >
                    <svg class="w-4 h-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                </button>
                <div class="w-8 h-8 rounded-full bg-white/20 flex items-center justify-center text-white font-bold text-sm">
                    {{ inicial }}
                </div>
            </div>

            <!-- Panel de notificaciones mobile -->
            <div v-if="menuNotif" class="fixed inset-0 z-[55] md:hidden" @click="menuNotif = false">
                <div class="absolute top-14 left-2 right-2 rounded-xl shadow-xl overflow-hidden"
                    style="background:white; border:1px solid #E5E7EB;" @click.stop>
                    <div class="flex items-center justify-between px-4 py-2.5 border-b border-gray-100">
                        <span class="text-sm font-semibold text-gray-800">Notificaciones</span>
                        <button v-if="notifNoLeidas > 0" @click="marcarTodasLeidas"
                            class="text-xs text-blue-600">Marcar leídas</button>
                    </div>
                    <div class="max-h-[70vh] overflow-y-auto">
                        <div v-if="!notificaciones.length" class="px-4 py-8 text-center text-sm text-gray-400">
                            No tienes notificaciones.
                        </div>
                        <button v-for="n in notificaciones" :key="n.id" @click="abrirNotif(n)"
                            class="flex items-start gap-3 w-full px-4 py-3 text-left hover:bg-gray-50 border-b border-gray-50"
                            :style="!n.leida ? 'background:#EFF6FF;' : ''">
                            <span class="mt-0.5 w-2 h-2 rounded-full flex-shrink-0"
                                :style="!n.leida ? 'background:var(--marca);' : 'background:transparent;'" />
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-medium text-gray-800">{{ n.titulo }}</span>
                                <span v-if="n.mensaje" class="block text-xs text-gray-500 mt-0.5">{{ n.mensaje }}</span>
                                <span class="block text-[11px] text-gray-400 mt-1">{{ n.hace }}</span>
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
                    'bg-green-50 border border-green-200 text-green-800': toastTipo === 'success',
                    'bg-red-50 border border-red-200 text-red-800':       toastTipo === 'error',
                    'bg-blue-50 border border-blue-200 text-blue-800':    toastTipo === 'info',
                }"
            >
                <!-- Ícono -->
                <svg v-if="toastTipo === 'success'" class="w-5 h-5 text-green-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                </svg>
                <svg v-else-if="toastTipo === 'error'" class="w-5 h-5 text-red-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
                <svg v-else class="w-5 h-5 text-blue-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>

                <!-- Mensaje -->
                <p class="text-sm font-medium flex-1">{{ toastMensaje }}</p>

                <!-- Botón cerrar -->
                <button @click="toastVisible = false" class="shrink-0 opacity-60 hover:opacity-100 transition-opacity">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
        </Transition>

        <!-- ══════════════════════════════════════════════════════════════════
             CONTENIDO PRINCIPAL
        ═══════════════════════════════════════════════════════════════════ -->
        <main
            class="pt-14 pb-20 px-4
                   md:ml-64 md:pt-20 md:pb-8 md:px-8"
        >
            <slot />
        </main>

        <!-- ══════════════════════════════════════════════════════════════════
             MOBILE — Barra de navegación inferior
        ═══════════════════════════════════════════════════════════════════ -->
        <nav
            class="md:hidden fixed bottom-0 left-0 right-0 z-40 flex items-center justify-around"
            style="height: 64px; background: white; box-shadow: 0 -1px 8px rgba(0,0,0,0.08);"
        >
            <!-- Inicio -->
            <button
                @click="router.visit('/dashboard')"
                class="flex flex-col items-center justify-center gap-0.5 min-w-0 flex-1 py-2"
                :style="{ color: isActive('/dashboard') ? 'var(--marca)' : '#9CA3AF' }"
            >
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                </svg>
                <span style="font-size: 10px;" class="font-medium">Inicio</span>
            </button>

            <!-- Clientes -->
            <button
                @click="router.visit('/clientes')"
                class="flex flex-col items-center justify-center gap-0.5 min-w-0 flex-1 py-2"
                :style="{ color: isActive('/clientes') ? 'var(--marca)' : '#9CA3AF' }"
            >
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-2 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                </svg>
                <span style="font-size: 10px;" class="font-medium">Clientes</span>
            </button>

            <!-- Cotizaciones -->
            <button
                @click="router.visit('/cotizaciones')"
                class="flex flex-col items-center justify-center gap-0.5 min-w-0 flex-1 py-2"
                :style="{ color: isActive('/cotizaciones') ? 'var(--marca)' : '#9CA3AF' }"
            >
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                <span style="font-size: 10px;" class="font-medium">Cotizar</span>
            </button>

            <!-- Productos -->
            <button
                v-if="user?.rol !== 'operario'"
                @click="router.visit('/productos')"
                class="flex flex-col items-center justify-center gap-0.5 min-w-0 flex-1 py-2"
                :style="{ color: isActive('/productos') ? 'var(--marca)' : '#9CA3AF' }"
            >
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7l8 4" />
                </svg>
                <span style="font-size: 10px;" class="font-medium">Productos</span>
            </button>

            <!-- Más (abre drawer) -->
            <button
                @click="toggleDrawer"
                class="flex flex-col items-center justify-center gap-0.5 min-w-0 flex-1 py-2"
                :style="{ color: drawerAbierto ? 'var(--marca)' : '#9CA3AF' }"
            >
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
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
                style="width: 280px; background: white;"
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
                        <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <!-- Perfil usuario -->
                <div class="flex items-center gap-3 px-5 py-4 border-b border-gray-100">
                    <div
                        class="w-10 h-10 rounded-full flex items-center justify-center text-base font-bold shrink-0"
                        style="background-color: var(--marca); color: white;"
                    >
                        {{ inicial }}
                    </div>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-gray-900 truncate">{{ user?.name }}</p>
                        <p class="text-xs text-gray-400 truncate">{{ rolLabel }}</p>
                    </div>
                </div>

                <!-- Selector de sede activa -->
                <div v-if="mostrarSelectorSede" class="px-5 py-3 border-b border-gray-100">
                    <label class="block text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1.5">Sede</label>
                    <select
                        :value="sedeActivaId"
                        @change="cambiarSede(Number($event.target.value))"
                        class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-700 bg-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                        <option v-if="puedeTodasSedes" :value="0">Todas las sedes</option>
                        <option v-for="s in sedesDisponibles" :key="s.id" :value="s.id">{{ s.nombre }}</option>
                    </select>
                </div>

                <!-- Ítems de navegación -->
                <nav class="flex-1 px-3 py-3">
                    <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider px-3 mb-2">Navegación</p>
                    <template v-for="item in navItems" :key="item.href ?? item.label">
                        <!-- Separador de sección -->
                        <p
                            v-if="item.divider"
                            class="text-xs font-semibold text-gray-400 uppercase tracking-wider px-3 mt-4 mb-2"
                        >
                            {{ item.label }}
                        </p>
                        <!-- Link normal -->
                        <a
                            v-else
                            :href="item.href"
                            class="flex items-center gap-3 rounded-xl transition-colors mb-1"
                            :class="[
                                item.sub
                                    ? 'pl-8 pr-3 py-2 text-xs font-medium'
                                    : 'px-3 py-3 text-sm font-medium',
                                isActive(item.href)
                                    ? 'text-white'
                                    : 'text-gray-700 hover:bg-gray-100',
                            ]"
                            :style="isActive(item.href) ? 'background-color: var(--marca);' : ''"
                            @click.prevent="navegar(item.href)"
                        >
                            <span v-if="item.sub" class="w-1.5 h-1.5 rounded-full bg-gray-300 shrink-0 -ml-1"
                                :class="isActive(item.href) ? 'bg-white/60' : 'bg-gray-300'"></span>
                            <svg v-if="item.icon === 'home'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                            </svg>
                            <svg v-if="item.icon === 'clipboard'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                            </svg>
                            <svg v-if="item.icon === 'multimedia'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                            <!-- clientes -->
                            <svg v-if="item.icon === 'clientes'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-2 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                            </svg>
                            <!-- productos -->
                            <svg v-if="item.icon === 'productos'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7l8 4" />
                            </svg>
                            <svg v-if="item.icon === 'users'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                            </svg>
                            <svg v-if="item.icon === 'cotizacion'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            <svg v-if="item.icon === 'comisiones'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <svg v-if="item.icon === 'calculadora'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                            </svg>
                            <svg v-if="item.icon === 'insumos'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
                            </svg>
                            <svg v-if="item.icon === 'configurador'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                            <svg v-if="item.icon === 'ensamble'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                            </svg>
                            <svg v-if="item.icon === 'database'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 7C4 5.343 7.582 4 12 4s8 1.343 8 3v2c0 1.657-3.582 3-8 3S4 10.657 4 9V7z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 9v4c0 1.657 3.582 3 8 3s8-1.343 8-3V9" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 13v4c0 1.657 3.582 3 8 3s8-1.343 8-3v-4" />
                            </svg>
                            <svg v-if="item.icon === 'template'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                            </svg>
                            <svg v-if="item.icon === 'trabajos'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4" />
                            </svg>
                            <svg v-if="item.icon === 'workers'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                            </svg>
                            <svg v-if="item.icon === 'capacitacion'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.422A12.083 12.083 0 0121 15.5V17a2 2 0 01-2 2H5a2 2 0 01-2-2v-1.5c0-.994.212-1.964.582-2.858L12 14z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 14v7" />
                            </svg>
                            <svg v-if="item.icon === 'wrench'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                            <svg v-if="item.icon === 'gear'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" />
                            </svg>
                            <svg v-if="item.icon === 'calendar'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                            </svg>
                            <svg v-if="item.icon === 'webhook'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" />
                            </svg>
                            <!-- mi-panel -->
                            <svg v-if="item.icon === 'mi-panel'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 5a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1V5zm10 0a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1V5zM4 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1v-4zm10 0a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z" />
                            </svg>
                            <!-- inventario -->
                            <svg v-if="item.icon === 'inventario'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                            </svg>
                            <!-- movimientos -->
                            <svg v-if="item.icon === 'movimientos'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 10h16M4 14h16M4 18h16"/>
                            </svg>
                            <!-- chart -->
                            <svg v-if="item.icon === 'chart'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            <!-- megaphone -->
                            <svg v-if="item.icon === 'megaphone'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z" />
                            </svg>
                            <!-- camion -->
                            <svg v-if="item.icon === 'camion'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 17H3V5h12v12H9zm0 0h6m-6 0a2 2 0 104 0m6 0a2 2 0 104 0M15 5h4l2 4v8h-6V5z"/>
                            </svg>
                            <!-- cartera -->
                            <svg v-if="item.icon === 'cartera'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <!-- crm -->
                            <svg v-if="item.icon === 'crm'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2a1 1 0 01-.293.707L13 13.414V19a1 1 0 01-.553.894l-4 2A1 1 0 017 21v-7.586L3.293 6.707A1 1 0 013 6V4z"/>
                            </svg>
                            <!-- chat (asistente IA) -->
                            <svg v-if="item.icon === 'chat'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-6l-4 4v-4z"/>
                            </svg>
                            <!-- reportes -->
                            <svg v-if="item.icon === 'reportes' && !item.sub" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                            </svg>
                            <!-- formulario -->
                            <svg v-if="item.icon === 'formulario' && !item.sub" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
                            </svg>
                            <!-- pdf -->
                            <svg v-if="item.icon === 'pdf'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                            </svg>
                            {{ item.label }}
                        </a>
                    </template>
                </nav>

                <!-- Acciones al pie del drawer -->
                <div class="px-3 py-4 border-t border-gray-100 space-y-1 shrink-0">
                    <button
                        @click="irPerfil"
                        class="flex items-center gap-3 w-full px-3 py-3 rounded-xl text-sm font-medium text-gray-700 hover:bg-gray-100 transition-colors"
                    >
                        <svg class="w-5 h-5 text-gray-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                        </svg>
                        Ver perfil
                    </button>
                    <button
                        @click="cerrarSesion"
                        class="flex items-center gap-3 w-full px-3 py-3 rounded-xl text-sm font-medium text-red-600 hover:bg-red-50 transition-colors"
                    >
                        <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
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
                <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
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
                        class="w-16 h-16 rounded-full flex items-center justify-center border-4 border-white"
                        style="background: rgba(255,255,255,0.2);">
                        <div class="w-12 h-12 rounded-full bg-white" />
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

    <!-- Modal disciplinas pendientes (operarios) -->
    <teleport to="body">
        <div v-if="modalDisciplinas" class="fixed inset-0 z-[70] flex items-center justify-center p-4"
            style="background:rgba(0,0,0,0.55);">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-100">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-sm font-bold text-gray-900">Documentos pendientes de firma</h3>
                            <p class="text-xs text-gray-500">{{ disciplinasPendientes.length }} documento(s) sin firmar</p>
                        </div>
                    </div>
                </div>
                <div class="px-5 py-3 max-h-56 overflow-y-auto space-y-2">
                    <div v-for="d in disciplinasPendientes" :key="d.id"
                        class="bg-red-50 rounded-xl px-4 py-3 border border-red-100">
                        <p class="text-xs font-semibold text-red-700">{{ d.tipo_label }}</p>
                        <p class="text-xs text-gray-600 mt-0.5">{{ d.descripcion }}</p>
                        <p class="text-xs text-gray-400 mt-0.5">{{ d.fecha }}</p>
                    </div>
                </div>
                <div class="px-5 py-4 border-t border-gray-100">
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
            <div class="bg-white rounded-2xl shadow-2xl border border-gray-100 overflow-hidden">
                <div class="px-4 py-3 flex items-center gap-3" style="background-color: var(--marca);">
                    <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0" style="background: rgba(255,255,255,0.2);">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                        </svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-white font-semibold text-sm">Instalar SGI</p>
                        <p class="text-xs" style="color: rgba(255,255,255,0.7);">Acceso rápido desde tu pantalla de inicio</p>
                    </div>
                    <button
                        @click="descartarPWA"
                        class="p-1 rounded-lg flex-shrink-0 transition-colors"
                        style="color: rgba(255,255,255,0.6);"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
                <div class="px-4 py-3 flex gap-2">
                    <button
                        @click="descartarPWA"
                        class="flex-1 py-2 text-sm font-medium rounded-xl hover:bg-gray-50 transition-colors"
                        style="color: #6B7280;"
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
