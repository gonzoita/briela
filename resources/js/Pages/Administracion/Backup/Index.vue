<script setup>
import { ref, computed } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    backups:     { type: Array,  default: () => [] },
    db_size:     { type: String, default: '0 MB' },
    automatico:  { type: Object, default: () => ({ hora: '2:00 a.m.', retencion: 30, ultimo: null }) },
    diagnostico: { type: Object, default: () => ({}) },
})

// Solo se muestra el diagnóstico si algo está mal. Cuando funciona, esta
// información no le sirve a nadie y solo ocupa pantalla.
const hayProblema = computed(() =>
    Object.values(props.diagnostico ?? {}).some(d => d && d.ok === false)
)

const etiquetasDiagnostico = {
    carpeta:       'Carpeta de respaldos',
    shell:         'Comandos del sistema',
    mysqldump:     'mysqldump',
    memoria:       'Límite de memoria',
    tiempo_maximo: 'Tiempo máximo de ejecución',
}

// ─── Último backup ────────────────────────────────────────────────────────────
const ultimoBackup = computed(() => props.backups[0]?.fecha ?? 'Ninguno')

// Se da por sano si el último automático tiene menos de 48 horas: uno diario
// puede saltarse una noche por un reinicio sin que sea para alarmarse, pero
// dos noches seguidas ya significa que algo dejó de correr.
const autoSaludable = computed(() =>
    !!props.automatico?.ultimo && props.automatico.ultimo.hace_horas < 48
)

// ─── Descarga backup ─────────────────────────────────────────────────────────
const descargando = ref(false)
function descargarBackup() {
    descargando.value = true
    window.location.href = '/administracion/backup/descargar'
    setTimeout(() => { descargando.value = false }, 3000)
}

// ─── Restaurar ───────────────────────────────────────────────────────────────
const archivoSeleccionado = ref(null)
const nombreArchivo       = ref('')
const restaurando         = ref(false)
const modalConfirm        = ref(false)
const textoConfirm        = ref('')
const confirmOk           = computed(() => textoConfirm.value === 'CONFIRMAR')

const formRestaurar = useForm({ archivo: null })

function seleccionarArchivo(e) {
    const file = e.target.files[0]
    if (!file) return
    archivoSeleccionado.value = file
    nombreArchivo.value       = file.name
    formRestaurar.archivo     = file
}

function abrirModalConfirm() {
    textoConfirm.value = ''
    modalConfirm.value = true
}

function ejecutarRestauraacion() {
    if (!confirmOk.value) return
    restaurando.value  = true
    modalConfirm.value = false
    formRestaurar.post('/administracion/backup/restaurar', {
        forceFormData: true,
        onFinish: () => { restaurando.value = false },
    })
}

// ─── Eliminar backup ─────────────────────────────────────────────────────────
function eliminarBackup(filename) {
    if (!confirm(`¿Eliminar ${filename}?`)) return
    router.delete('/administracion/backup/eliminar', {
        data: { filename },
        preserveScroll: true,
    })
}
</script>

<template>
    <AppLayout title="Backup y Restauración">

        <!-- Spinner de restauración (overlay) -->
        <div
            v-if="restaurando"
            class="fixed inset-0 z-50 flex flex-col items-center justify-center gap-4"
            style="background: rgba(0,0,0,0.6);"
        >
            <div class="w-12 h-12 rounded-full border-4 border-white border-t-transparent animate-spin" />
            <p class="text-white font-semibold text-base text-center px-6">
                Restaurando base de datos...<br>
                <span class="text-sm font-normal opacity-80">No cierres esta ventana</span>
            </p>
        </div>

        <div class="max-w-2xl mx-auto space-y-5 py-2">

            <!-- ── INFO ─────────────────────────────────────────────────────── -->
            <div class="rounded-2xl border border-blue-200 p-5 space-y-3" style="background:#EFF6FF;">
                <div class="flex items-center gap-2 mb-1">
                    <svg class="w-4 h-4 shrink-0" style="color:var(--marca);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <p class="text-sm font-semibold" style="color:var(--marca);">¿Cómo funciona el sistema de backup?</p>
                </div>
                <ul class="space-y-2 text-sm text-blue-900">
                    <li class="flex gap-2">
                        <span class="shrink-0 font-bold">•</span>
                        <span><strong>Backup / Descargar:</strong> genera un archivo <code class="font-mono text-xs bg-blue-100 px-1 rounded">.sql</code> con toda la base de datos MySQL en este momento y lo descarga a tu equipo. Guárdalo en Google Drive, USB o correo.</span>
                    </li>
                    <li class="flex gap-2">
                        <span class="shrink-0 font-bold">•</span>
                        <span><strong>Backups en servidor:</strong> muestra los archivos <code class="font-mono text-xs bg-blue-100 px-1 rounded">.sql</code> generados anteriormente que siguen almacenados en el servidor. Puedes descargarlos individualmente o eliminarlos para liberar espacio.</span>
                    </li>
                    <li class="flex gap-2">
                        <span class="shrink-0 font-bold">•</span>
                        <span><strong>Restaurar:</strong> reemplaza <em>todos</em> los datos actuales con los del archivo <code class="font-mono text-xs bg-blue-100 px-1 rounded">.sql</code> que subas. Úsalo solo para recuperar datos perdidos o revertir un error grave.</span>
                    </li>
                </ul>
                <div class="flex gap-2 p-3 rounded-xl" style="background:#FEF3C7; border:1px solid #FDE68A;">
                    <svg class="w-4 h-4 text-amber-600 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                    <p class="text-xs text-amber-800"><strong>Recomendación:</strong> genera un backup antes de cualquier importación masiva de datos, actualización del sistema o cambio importante en la configuración.</p>
                </div>
            </div>

            <!-- ── RESPALDO AUTOMÁTICO ──────────────────────────────────────── -->
            <div class="bg-white rounded-2xl shadow-sm border p-5"
                :class="autoSaludable ? 'border-green-200' : 'border-amber-200'">
                <div class="flex items-start gap-3">
                    <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0"
                        :class="autoSaludable ? 'bg-green-100' : 'bg-amber-100'">
                        <svg class="w-5 h-5" :class="autoSaludable ? 'text-green-600' : 'text-amber-600'"
                            fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-semibold text-gray-800">
                            Respaldo automático · todos los días a las {{ automatico.hora }}
                        </p>

                        <p v-if="automatico.ultimo" class="text-xs mt-1"
                            :class="autoSaludable ? 'text-green-700' : 'text-amber-700'">
                            Último: {{ automatico.ultimo.fecha }}
                            <span class="opacity-70">(hace {{ automatico.ultimo.hace_horas }} h)</span>
                        </p>
                        <p v-else class="text-xs text-amber-700 mt-1">
                            Todavía no se ha ejecutado ninguno. Si ya pasó de las
                            {{ automatico.hora }} y sigue así, es probable que falte el cron en el
                            servidor.
                        </p>

                        <p v-if="automatico.ultimo && !autoSaludable" class="text-xs text-amber-700 mt-1">
                            Pasaron más de 48 horas desde el último. Revisa que el cron siga
                            corriendo.
                        </p>

                        <p class="text-xs text-gray-400 mt-2">
                            Se conservan {{ automatico.retencion }} días. Los más viejos se borran
                            solos, pero nunca queda la carpeta vacía.
                        </p>
                    </div>
                </div>
            </div>

            <!-- ── ESTADO ───────────────────────────────────────────────────── -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-3">Estado</h2>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <p class="text-xs text-gray-400">Base de datos</p>
                        <p class="text-sm font-semibold text-gray-800 mt-0.5">{{ $page.props.auth?.user?.name ? 'interfrigo_sgi' : '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-400">Tamaño</p>
                        <p class="text-sm font-semibold text-gray-800 mt-0.5">{{ db_size }}</p>
                    </div>
                    <div class="col-span-2">
                        <p class="text-xs text-gray-400">Último backup</p>
                        <p class="text-sm font-semibold text-gray-800 mt-0.5">{{ ultimoBackup }}</p>
                    </div>
                </div>
            </div>

            <!-- ── ERROR AL GENERAR ─────────────────────────────────────────── -->
            <div v-if="$page.props.flash?.error"
                class="bg-red-50 border border-red-200 rounded-2xl p-5">
                <p class="text-sm font-semibold text-red-800">No se pudo generar el respaldo</p>
                <p class="text-xs text-red-700 mt-1">{{ $page.props.flash.error }}</p>
            </div>

            <!-- ── DIAGNÓSTICO (solo si algo falla) ─────────────────────────── -->
            <div v-if="hayProblema" class="bg-white rounded-2xl shadow-sm border border-amber-200 p-5">
                <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-1">Diagnóstico del servidor</h2>
                <p class="text-xs text-gray-500 mb-3">
                    Algo de la configuración del hosting está limitando los respaldos.
                </p>
                <div class="space-y-2">
                    <div v-for="(d, clave) in diagnostico" :key="clave"
                        class="flex items-start gap-2 text-xs">
                        <span class="mt-0.5 shrink-0 w-4 h-4 rounded-full flex items-center justify-center"
                            :class="d.ok ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'">
                            {{ d.ok ? '✓' : '!' }}
                        </span>
                        <div class="flex-1 min-w-0">
                            <span class="font-medium text-gray-700">{{ etiquetasDiagnostico[clave] ?? clave }}:</span>
                            <span class="text-gray-600 ml-1 break-all">{{ d.valor }}</span>
                            <p v-if="d.nota" class="text-gray-400 mt-0.5">{{ d.nota }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── CREAR BACKUP ──────────────────────────────────────────────── -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-3">Crear Backup</h2>
                <button
                    @click="descargarBackup"
                    :disabled="descargando"
                    class="w-full flex items-center justify-center gap-2 py-3 px-4 rounded-xl font-semibold text-white transition-opacity"
                    :class="descargando ? 'opacity-60 cursor-not-allowed' : 'hover:opacity-90'"
                    style="background-color: var(--marca);"
                >
                    <svg v-if="!descargando" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 7C4 5.343 7.582 4 12 4s8 1.343 8 3v2c0 1.657-3.582 3-8 3S4 10.657 4 9V7z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 9v4c0 1.657 3.582 3 8 3s8-1.343 8-3V9" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 13v4c0 1.657 3.582 3 8 3s8-1.343 8-3v-4" />
                    </svg>
                    <div v-else class="w-5 h-5 rounded-full border-2 border-white border-t-transparent animate-spin" />
                    {{ descargando ? 'Generando...' : 'Descargar Backup Ahora' }}
                </button>
                <p class="text-xs text-gray-400 mt-2 text-center">
                    Descarga un archivo .sql con toda la base de datos. Guárdalo en un lugar seguro.
                </p>
            </div>

            <!-- ── RESTAURAR ─────────────────────────────────────────────────── -->
            <div class="bg-white rounded-2xl shadow-sm border border-orange-100 p-5">
                <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-3">Restaurar</h2>

                <!-- Advertencia -->
                <div class="flex gap-3 p-3 rounded-xl mb-4" style="background: #FFF7ED; border: 1px solid #FED7AA;">
                    <svg class="w-5 h-5 text-orange-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <p class="text-sm text-orange-800">
                        <strong>ATENCIÓN:</strong> Restaurar sobreescribe <strong>TODOS</strong> los datos actuales.
                        Esta acción <strong>NO se puede deshacer</strong>.
                    </p>
                </div>

                <!-- Selector de archivo -->
                <label class="block">
                    <div
                        class="flex items-center gap-3 px-4 py-3 rounded-xl border-2 border-dashed cursor-pointer transition-colors"
                        :class="nombreArchivo ? 'border-green-400 bg-green-50' : 'border-gray-200 hover:border-gray-300'"
                    >
                        <svg class="w-5 h-5 shrink-0" :class="nombreArchivo ? 'text-green-600' : 'text-gray-400'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        <span class="text-sm" :class="nombreArchivo ? 'text-green-800 font-medium' : 'text-gray-400'">
                            {{ nombreArchivo || 'Seleccionar archivo .sql' }}
                        </span>
                    </div>
                    <input type="file" accept=".sql" class="hidden" @change="seleccionarArchivo" />
                </label>

                <!-- Error de validación -->
                <p v-if="formRestaurar.errors.archivo" class="mt-2 text-sm text-red-600">
                    {{ formRestaurar.errors.archivo }}
                </p>

                <button
                    @click="abrirModalConfirm"
                    :disabled="!archivoSeleccionado"
                    class="w-full mt-4 flex items-center justify-center gap-2 py-3 px-4 rounded-xl font-semibold text-white transition-all"
                    :class="archivoSeleccionado
                        ? 'bg-orange-500 hover:bg-orange-600'
                        : 'bg-gray-200 text-gray-400 cursor-not-allowed'"
                >
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                    Restaurar Base de Datos
                </button>
            </div>

            <!-- ── BACKUPS EN SERVIDOR ───────────────────────────────────────── -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <h2 class="text-sm font-semibold text-gray-500 uppercase tracking-wide mb-3">
                    Backups guardados en servidor
                </h2>

                <div v-if="backups.length === 0" class="text-center py-8 text-gray-400 text-sm">
                    No hay backups guardados en el servidor.
                </div>

                <div v-else class="space-y-2">
                    <div
                        v-for="backup in backups"
                        :key="backup.filename"
                        class="flex items-center gap-3 px-4 py-3 rounded-xl"
                        style="background: #F8F9FA;"
                    >
                        <svg class="w-5 h-5 text-gray-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-800 truncate">{{ backup.filename }}</p>
                            <p class="text-xs text-gray-400">{{ backup.size }} · {{ backup.fecha }}</p>
                        </div>
                        <button
                            @click="eliminarBackup(backup.filename)"
                            class="w-8 h-8 flex items-center justify-center rounded-lg text-gray-400 hover:text-red-500 hover:bg-red-50 transition-colors shrink-0"
                            title="Eliminar backup"
                        >
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

        </div>

        <!-- ════════════════════════════════════════════════════════════════════
             MODAL CONFIRMACIÓN RESTAURACIÓN
        ═══════════════════════════════════════════════════════════════════ -->
        <teleport to="body">
            <div
                v-if="modalConfirm"
                class="fixed inset-0 z-50 flex items-center justify-center p-4"
                style="background: rgba(0,0,0,0.5);"
                @click.self="modalConfirm = false"
            >
                <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
                    <!-- Header -->
                    <div class="px-6 pt-6 pb-4">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-10 h-10 rounded-xl flex items-center justify-center shrink-0" style="background: #FEF3C7;">
                                <svg class="w-6 h-6 text-orange-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-base font-bold text-gray-900">Confirmar restauración</h3>
                                <p class="text-xs text-gray-400">Esta acción no se puede deshacer</p>
                            </div>
                        </div>

                        <p class="text-sm text-gray-600 mb-1">Vas a restaurar:</p>
                        <p class="text-sm font-semibold text-gray-800 bg-gray-100 px-3 py-2 rounded-lg mb-4 break-all">
                            {{ nombreArchivo }}
                        </p>

                        <p class="text-sm text-gray-700 mb-4">
                            Esto <strong>ELIMINARÁ</strong> todos los datos actuales y los reemplazará con los del backup.
                            ¿Estás completamente seguro?
                        </p>

                        <label class="block">
                            <p class="text-xs font-semibold text-gray-600 mb-1.5">Escribe <strong>CONFIRMAR</strong> para continuar:</p>
                            <input
                                v-model="textoConfirm"
                                type="text"
                                placeholder="CONFIRMAR"
                                class="w-full px-3 py-2.5 rounded-xl border text-sm transition-colors outline-none"
                                :class="confirmOk
                                    ? 'border-green-400 bg-green-50 text-green-800'
                                    : 'border-gray-200 focus:border-orange-400'"
                                autocomplete="off"
                            />
                        </label>
                    </div>

                    <!-- Acciones -->
                    <div class="flex gap-3 px-6 pb-6">
                        <button
                            @click="modalConfirm = false"
                            class="flex-1 py-2.5 rounded-xl text-sm font-semibold text-gray-600 bg-gray-100 hover:bg-gray-200 transition-colors"
                        >
                            Cancelar
                        </button>
                        <button
                            @click="ejecutarRestauraacion"
                            :disabled="!confirmOk"
                            class="flex-1 py-2.5 rounded-xl text-sm font-semibold text-white transition-all"
                            :class="confirmOk ? 'bg-orange-500 hover:bg-orange-600' : 'bg-gray-200 text-gray-400 cursor-not-allowed'"
                        >
                            Restaurar →
                        </button>
                    </div>
                </div>
            </div>
        </teleport>

    </AppLayout>
</template>
