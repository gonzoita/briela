# Capacitación (cursos, evaluaciones y certificados)

Rutas: `/capacitacion/cursos` (administración), `/mi-capacitacion`
(colaboradores internos), `/portal-capacitacion` (estudiantes externos:
clientes/contratistas).

## Qué es

Una plataforma de cursos propia. Se arman cursos con **módulos** y
**lecciones**, y evaluaciones (por módulo y/o una final del curso). Los
estudiantes avanzan, presentan las evaluaciones y, al aprobar, reciben un
**certificado con código y QR de verificación**.

Hay dos tipos de estudiante, con su propio acceso:
- **Colaboradores internos** (usuarios del sistema) → "Mi Capacitación".
- **Externos** (clientes, contratistas) → portal aparte, con registro por
  invitación (link con token) y login propio.

## Flujo del estudiante

1. **Inscripción**: a un curso obligatorio lo inscribe la empresa; a uno
   opcional el estudiante se inscribe solo desde el catálogo.
2. **Lecciones**: se marcan como vistas. Un módulo se desbloquea cuando se
   completa el anterior.
3. **Evaluación de módulo** (si el módulo la tiene): aprobarla desbloquea el
   siguiente módulo.
4. **Evaluación final** (si el curso la tiene): solo se habilita al 100% de
   lecciones completadas.
5. **Certificado**: se genera al aprobar la evaluación final.

## Automatizaciones activas (ya estaban bien)

- **Todo el cierre es automático al aprobar la evaluación final**: la
  inscripción pasa a "completado" y luego "aprobado", se genera el
  certificado en PDF (con QR), y —si es un colaborador interno y el curso
  otorga puntos— se le suman los puntos de gamificación automáticamente.
- **Desbloqueo por progreso**: los módulos se desbloquean solos según se
  completan los anteriores; la evaluación final se habilita sola al 100%.
- **Preguntas abiertas → revisión manual**: si una evaluación tiene
  preguntas de texto libre, el intento queda "pendiente de revisión" para
  que un revisor las califique; las de opción múltiple se califican solas.
- **Control de intentos**: si la evaluación tiene un máximo de intentos, el
  sistema lo respeta y bloquea nuevos envíos al agotarlos.

## Verificación pública de certificados *(nuevo, 24 jul 2026)*

Antes, el certificado traía un código y un QR, pero **no existía dónde
verificarlo**: el QR solo contenía el texto del código y no llevaba a
ningún lado. Un certificado que no se puede comprobar no sirve como prueba
ante un cliente o empleador.

Ahora hay una página pública (sin login) en `/verificar-certificado`:
- El QR del certificado apunta directo a ella — se escanea y muestra el
  resultado.
- O se puede entrar y escribir el código a mano.
- Muestra si el certificado es **válido**, a nombre de quién, de qué curso
  y la fecha de emisión. Si el código no existe, lo dice claramente.

Los certificados emitidos desde ahora traen el QR con el enlace; los
anteriores igual se pueden verificar escribiendo su código en la página.

## Pendiente de mejorar (backlog)

- **Aviso de evaluación pendiente de revisión**: cuando un intento con
  preguntas abiertas queda esperando calificación, hoy el revisor tiene que
  entrar a mirar; no le llega un aviso.
- **Recordatorio de curso obligatorio por vencer**: las inscripciones
  obligatorias tienen fecha límite, pero no hay un recordatorio automático
  cuando se acerca el plazo (sería análogo al cron de cotizaciones
  vencidas).
- **Envío del certificado por correo**: hoy el estudiante entra al portal a
  descargarlo; no se le manda por email al aprobar.
