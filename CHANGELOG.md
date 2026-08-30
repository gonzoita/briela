# Historial de versiones de Briela

Las notas de cada versión se le muestran al cliente en el botón de actualizar,
así que se escriben para quien **usa** el sistema, no para quien lo programa.

Formato: [versionado semántico](https://semver.org/lang/es/).

- **Mayor** (1.0.0 → 2.0.0): cambios que exigen intervención o rompen algo.
- **Menor** (1.0.0 → 1.1.0): funcionalidad nueva, compatible.
- **Parche** (1.0.0 → 1.0.1): correcciones.

> Regla del producto: las migraciones de cada versión deben poder correr sobre
> cualquier versión anterior soportada. Ver `docs/BRIELA-PLAN.md` sección 6.3.

## [Sin publicar]

### Agregado
- **Módulo de Calidad** (`/calidad`, con su propio permiso): un tablero con
  todas las unidades ya fabricadas y sin despachar, en fichas grandes con un
  botón por punto de revisión. Se marca de un toque, y «Terminar» cierra la
  unidad completa. Un punto que exige foto abre una ventana para tomarla con
  la cámara o subirla de un archivo, y no se deja marcar sin ella. El número
  de la orden abre la ficha de verificación de esa unidad: las medidas, los
  materiales de la receta y cómo se fabricó, paso por paso y con las fotos
  que dejó el operario.
- Integración con WordPress (plugin "Briela Connect"): los leads que llegan
  por los formularios del sitio web del cliente entran solos al CRM, con el
  canal de origen (utm_source / utm_medium / utm_campaign) de la visita que
  los trajo. Se conecta desde Configuración → Integraciones → WordPress.

### Cambiado
- **Trabajos se ve como Calidad**: el listado dejó de ser una tabla y ahora es
  la misma ficha grande, con un botón por paso. Marcar un paso pasó de ocho
  toques a uno.
- **El menú se pliega** y deja solo la columna de iconos, para ganar ancho en
  las pantallas anchas; la categoría se sigue desplegando al pasar por encima.
  Además está reorganizado —RRHH y Capacitación ahora son «Personal», y
  Auditoría se fue con Informes a «Reportes»— y cada categoría tiene su propio
  icono en vez del engranaje repetido.
- Arranque del proyecto a partir de un ERP interno ya probado (2 ago 2026):
  identidad propia, configuración limpia y salida de las credenciales heredadas.
