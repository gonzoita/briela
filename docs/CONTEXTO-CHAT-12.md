# Contexto para continuar — SGI Interfrigo (handoff chat 12)

> Documento de traspaso entre chats. Objetivo: que el nuevo chat NO tenga que
> re-explorar el código (ahorra tokens). Lee esto + `CLAUDE.md` +
> `docs/manual/00-indice.md` y ya tienes el mapa completo. Solo abre archivos
> puntuales cuando vayas a tocarlos.

Fecha del traspaso: 24 jul 2026.

---

## 1. Qué es el proyecto

ERP interno ("SGI") de **Interfrigo SAS** (Colombia) — fabricante e instalador
de cuartos fríos y puertas refrigeradas. Laravel 13 + PHP 8.3, Vue 3 +
Inertia.js (SPA), Tailwind (mobile-first), Vite 8, MySQL.

- Repo: `github.com/Blueffalo/interfrigo-sgi` · rama `main`
- Local: `C:\laragon\www\interfrigo-sgi` (Laragon, Windows)
- Producción: `https://sgi.interfrigo.com.co` (Hostinger)
- Detalles técnicos completos (stack, DB, reglas de código): ver `CLAUDE.md`.

## 2. Reglas PERMANENTES (nunca romper)

1. **Idioma: español colombiano neutro. PROHIBIDO el voseo.** Nunca "tomá /
   decí / entrá / hablá". Siempre "toma / di / entra / habla". Aplica a UI,
   comentarios de código, mensajes de commit y respuestas de chat.
2. **Sin Ziggy** — URLs son strings hardcodeados en los Vue.
3. **Sin `resolvePageComponent`** — Inertia usa `import.meta.glob` eager.
4. **Sin CSS separado** — el CSS va en el bundle JS de Vite.
5. Vistas = componentes Vue en `resources/js/Pages/`. Nunca Blade para vistas.
6. `Layouts/AppLayout.vue` en todas las páginas autenticadas. Mobile-first.
7. Diego da confianza para dirigir lo técnico ("me dejo guiar por ti"), pero
   se le PREGUNTA en decisiones de negocio/dinero (con AskUserQuestion).

## 3. Cómo se despliega (IMPORTANTE)

- **Deploy automático con GitHub Actions**: cada push a `main` compila y
  despliega solo al servidor (`.github/workflows/deploy.yml`). El servidor
  corre `migrate --force` y limpia cachés solo. Ver
  `docs/manual/deploy-automatico.md`.
- **Diego sube con un script**: `.\subir.ps1 "mensaje"` (en la raíz del repo)
  hace add+commit+pull+push de forma robusta. Debe correrlo con **VS Code
  cerrado** o en PowerShell del sistema (VS Code deja locks de git colgados).
- **El entorno del asistente (sandbox) NO puede hacer git de forma confiable**
  sobre el mount de Windows (locks, no borra archivos). Por eso: el asistente
  edita/crea archivos; **Diego ejecuta el push** con `subir.ps1`. Para BORRAR
  archivos del repo, dar a Diego el comando `git rm ...` (el sandbox no puede
  borrar). Cambios a `.github/workflows/` solo los sube Diego (permiso de
  token).
- Cron del servidor (una vez, ya explicado a Diego): `* * * * * ... php artisan
  schedule:run` — necesario para las tareas programadas (ver abajo).

## 4. Arquitectura — lo que hay que saber de memoria

### Modelos REALES (el sistema vivo)
- **Producción**: `Op` → `OpItem` → `OpItemTrabajo` → `OpItemTrabajoPaso`
  (+ `OpItemTrabajoPasoOperario` pivot). Estados Op: `borrador → confirmada →
  en_produccion → calidad → despachada`, con `reproceso`. Campo
  `calidad_aprobada_at` = candado obligatorio antes de remisión/despacho.
- **Comercial**: `CrmLead` (pipeline) → `Cotizacion` → `CotizacionItem`,
  `CotizacionSeguimiento`, `ComisionVendedor`.
- **Compras/Inventario**: inventario real = `Producto` (con `es_insumo`) +
  `Bodega` + `ProductoStock` + `ProductoMovimiento`. Compras (`SolicitudCompra`,
  `OrdenCompra`) ya usan `Producto` (unificado).
- **Logística**: `Remision` → `RemisionItem`.
- **Capacitación**: `Curso` → `CursoModulo` → `CursoLeccion`; `CursoEvaluacion`
  → `EvaluacionPregunta`/`EvaluacionOpcion`; `Inscripcion`, `EvaluacionIntento`,
  `ProgresoLeccion`, `Certificado`. Estudiantes: `User` (interno) o
  `CapacitacionEstudiante` (externo, guard `estudiante`).
- **RRHH**: `Operario` (+ `user_id`), `OperarioDisciplina`, `OperarioHoraExtra`,
  `OperarioPermiso`, `OperarioHito`, `OperarioBono`, `PuntoColaborador`,
  `NivelColaborador`, `TipoColaborador`, `EstacionTrabajo`.
- **Transversal**: `Notificacion` (campanita), `RegistroActividad` (auditoría),
  `Configuracion` (clave/valor), `Archivo` (morph).

### Código MUERTO — no usar (restos de versión vieja)
- `OrdenProduccion`, `LineaOP`, `ItemOP` (tablas `ordenes_produccion`,
  `lineas_op`) — sistema viejo de "3 líneas". Sin rutas activas.
- Tablas `inventario_items` / `inventario_movimientos` — sistema de stock viejo
  que usaba Compras; ya migrado a `Producto`.
- **GoHighLevel: ELIMINADO por completo** (24 jul 2026). No existe más
  `GhlService`, `GhlController`, `GhlConfiguracion`, `GhlLog`. No reintroducir.

### Servicios clave
- `NotificacionService` — punto único de avisos internos. `crear(...)` y
  `paraRol(...)`. Catálogo de tipos en `NotificacionService::catalogo()`. Canal
  email opcional (SMTP). Cada tipo se apaga con config `notif_{tipo}` (y
  `notif_{tipo}_email`).
- `EvaluacionService` — califica intentos; al aprobar la final: inscripción a
  `aprobado`, genera certificado (PDF+QR) y otorga puntos.
- `PuntosColaboradorService` — gamificación. `otorgarPuntosPorPaso` (idempotente)
  + `revertirPuntosPorPaso` (al desmarcar). Puntos suben por trabajo (dificultad)
  y por cursos (`Curso.puntos_otorga`). La disciplina NO baja puntos.
- `Auditable` (trait) — auto-registra create/update/delete en `registros_actividad`.
- `SmtpConfigService::aplicar()` — carga el SMTP desde Configuracion.

### Portales públicos (sin login)
- `/op/{token}` — seguimiento por QR (token imposible de adivinar).
- `/seguimiento` — buscador por número de OP/serie; **exige apellido o
  documento del cliente** (candado de privacidad).
- `/cotizaciones/{token}/aprobar` — el cliente aprueba/rechaza.
- `/verificar-certificado/{codigo}` — verificación pública de certificados.

### Tareas programadas (scheduler, `routes/console.php`)
- `cotizaciones:marcar-vencidas` (1:00)
- `notificaciones:entregas-proximas` (6:00)
- `notificaciones:cursos-por-vencer` (6:05)
- `notificaciones:recordatorios` (6:10 — cotización sin respuesta, stock bajo,
  saldos vencidos)

## 5. Qué se construyó/mejoró en las sesiones anteriores

Se recorrió y mejoró **todo** módulo por módulo (todo desplegado y documentado
en `docs/manual/`):
- CRM/Cotizaciones: link lead↔cotización, auto-borrador al mover lead, alerta
  y panel de seguimiento, comisión visible, "enviada"/"aprobada"/"vencida"
  automáticas. **Comisión = excedente sobre precio mayorista** (no precio total).
- Producción: control de calidad real (foto+obs+reproceso), calidad obligatoria
  antes de despachar, transición automática en_produccion→calidad, confirmada→
  en_produccion, y "en producción" si hay avance real. Aviso de material faltante.
- Compras/Inventario: **unificado a `Producto`** (antes había 2 inventarios).
- Logística: firma del cliente cierra la entrega; OP se despacha y consume
  inventario al **salir** la remisión (en camino), con parciales.
- Informes: filtros con nombres (no IDs), **totales/promedios**.
- Capacitación: verificación pública de certificados (QR→URL).
- RRHH: disciplina no baja puntos, doble conteo de puntos corregido, **cálculo
  de bono masivo**.
- Auditoría global (`RegistroActividad`).
- **Notificaciones**: campanita (motor + UI en AppLayout) + ~20 tipos por todos
  los módulos + panel de config en Ajustes (switch campanita/email) +
  recordatorios diarios. Ver `docs/manual/notificaciones.md`.
- Deploy automático (GitHub Actions) + `subir.ps1`.
- Seguridad: quitados scripts de prueba (`info.php`, `dbtest.php`, etc.).
- Eliminado GoHighLevel.

## 6. Estado actual y próximos pasos

- **Backlog: vacío.** Todos los módulos revisados; todo documentado.
- **Lo que sigue (lo que Diego quiere ahora):**
  1. **Integración de IA** (Claude API): descripciones automáticas de
     productos/OP, análisis de tiempos de producción, asistente sobre los
     datos, sugerencias de precio/comisión, autorespuestas de RRSS. Definir
     con Diego cuál caso primero.
  2. **Módulo de redes sociales tipo Metricool** (idea de Diego): programar
     posts, bandeja unificada de mensajes, autorespuestas (con IA). ADVERTENCIA
     ya dada a Diego: depende de APIs de terceros (Meta/TikTok/LinkedIn/X) que
     exigen registro y **revisión/aprobación** de la app (trámite lento por
     plataforma), algunas APIs son de pago (X), y hay mantenimiento continuo.
     Enfoque recomendado: usar un **agregador** (Ayrshare de pago, o Postiz
     open-source self-hosted) en vez de integrar cada red por separado. Estaba
     pendiente que Diego eligiera: qué priorizar (publicar / bandeja /
     autorespuestas) y qué redes usa. **Retomar esa decisión.**

## 7. Sobre graphify

El proyecto tiene un knowledge graph en `graphify-out/` (god nodes, comunidades,
relaciones cross-file). Para preguntas del código conviene `graphify query
"<pregunta>"` antes de grep. OJO: el `GRAPH_REPORT.md` es del commit del 17 jul,
anterior a todo lo de arriba, así que para lo nuevo (notificaciones, unificación
de compras, etc.) confía en este documento y en `docs/manual/`, no en el graph.
Correr `graphify update .` tras cambios (sin costo de API).
