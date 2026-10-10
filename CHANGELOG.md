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
- **Cada movimiento de inventario dice de dónde vino y con qué papel.** En la ficha del producto
  ves, por cada movimiento, el stock antes y después, su origen con enlace (orden de compra, orden
  de producción, ajuste), la factura o remisión con su número y fecha, y las observaciones.
  También las remisiones en las que salió el producto, y el historial completo por tandas.
- **Al recibir una orden de compra se registra la factura o remisión del proveedor**, la fecha de
  llegada y las observaciones, en cada entrega. La ficha de la orden lista todas sus entregas.
- **El ajuste de stock acepta el papel que lo respalda** (factura, remisión u otro).
- **Cargar un proveedor desde su RUT.** En Proveedores, sube el RUT (PDF o foto) y se llenan sus
  datos y responsabilidades tributarias. Avisa si ese proveedor ya existe. En la orden de compra,
  un proveedor que según su RUT no es responsable de IVA arranca sin IVA.
- **La orden de compra lleva el código de cada proveedor.** Un mismo producto lo pueden vender
  varios proveedores con códigos distintos; la orden manda el de quien la recibe, y el PDF lo
  muestra primero. Se configura en la ficha del producto o directamente al armar la orden: si
  escribes un código nuevo, queda guardado para las próximas. Al elegir proveedor, cada línea
  se llena sola con su código y su último precio, y se avisa si ese precio tiene más de 90 días.
- **Historial de precios por proveedor.** Cada vez que una orden se envía, el precio queda
  anotado. El asistente puede comparar proveedores con esos datos y no recomienda un precio viejo.
- **Cada variante puede tener su propia imagen.** Al crear un producto con variantes, o al
  agregar una variante desde la ficha del producto principal, puedes subir su imagen. Si no
  subes ninguna, usa la del producto principal en el buscador de cotizaciones, en su ficha y
  en el catálogo; y si el principal cambia su foto, las variantes que la heredan cambian con él.

### Corregido
- **Ajustar el stock exige permiso.** La ruta del ajuste desde la ficha del producto no pedía
  ninguno: cualquiera que pudiera ver el producto podía mover el inventario. Ahora pide «Stock y
  movimientos: editar», y el botón solo se muestra a quien lo tiene.
- **La referencia de un producto eliminado queda libre.** Antes, si eliminabas un producto y
  volvías a crear otro con la misma referencia, el sistema decía que ya estaba en uso, de algo
  que ya no veías. Ahora la reutilizas sin más. Vale al crear, al editar, en las variantes y al
  importar. Lo eliminado conserva su historial: solo cambia su referencia por «REF~elim123».
- **Si la referencia sí la tiene un producto vivo, el mensaje dice cuál.** Antes decía «El campo
  variantes.0.referencia ya está en uso».

## [1.2.0] — 2026-10-10

### Agregado
- **El correo sale por Briela, firmado y sin caer en spam.** Cuando Briela activa el
  dominio de envío de la instalación, las notificaciones, las cotizaciones y los formularios
  salen desde un subdominio propio verificado, sin configurar nada en el servidor. El SMTP
  propio queda de respaldo.
- **Configuración → Correo**: por dónde sale el correo, cuánto va del mes, un envío de prueba
  y las direcciones a las que ya no se les escribe porque rebotaron o se dieron de baja.
- Las notificaciones del sistema no se cobran nunca.

### Corregido
- **Crear un producto con variantes ya no responde con error.** Un valor de variante largo
  hacía que la referencia generada no cupiera y el guardado fallaba después de crear el
  padre, dejando un producto a medias. Ahora la referencia se acorta sin perder el prefijo
  del padre, y dos variantes nunca comparten referencia.
- **Las referencias automáticas ya no saltan ni chocan.** El contador ya no cuenta las
  variantes, así que los productos nuevos salen en orden (PROD-0001, PROD-0002…), y una
  referencia escrita a mano no bloquea la siguiente.
- **Las variantes tienen los precios de todos los canales**, incluidos los que la empresa
  creó por su cuenta. Antes, una variante podía cotizarse en cero por un canal propio.
- **Agregar una variante desde la ficha de editar ya no deja sus precios en cero.** La
  variante nueva copia los precios del padre.
- **Guardar un producto con proveedor sin todos sus datos ya no falla.** La importación y la
  API mandan solo lo que tienen.

## [1.1.0] — 2026-10-04

### Agregado
- **La TRM del día, sola.** Cada mañana se trae la TRM oficial y el euro, y se ven en
  Configuración → Monedas y TRM. Si el servidor no tiene internet, se puede escribir a mano.
- **Productos que se compran en dólares o euros**: se escribe el costo en esa moneda y el
  costo en pesos —y sus precios— se recalculan solos cada día con la tasa, más un colchón
  configurable. La plantilla de importación trae las columnas nuevas.
- **Cotizaciones en dólares o euros de verdad**: toman la tasa del día y el cliente ve los
  precios en su moneda en la pantalla, en el enlace de aprobación y en el PDF, con la tasa y
  su equivalente en pesos. Se elige si la tasa queda fija o sigue a la TRM hasta la aprobación.
- **Leer el RUT con IA**: se sube el PDF o una foto y se llenan solos los datos del cliente o
  los de la empresa, incluidas sus responsabilidades tributarias.
- **Retenciones estimadas en la cotización**: retención en la fuente, de IVA y de ICA según el
  RUT del cliente y el de la empresa, con el neto a recibir. Las reglas se configuran en
  Configuración → Perfil fiscal.

## [1.0.0] — 2026-09-23

Primera versión instalable de Briela.

### Agregado
- **Eliminar productos en bloque** desde la lista: los que marques, o todos los que
  deja el filtro de una vez. Cada producto se lleva sus variantes. Pide confirmación
  y exige el permiso «Eliminar productos».
- **La plantilla para importar productos trae una columna por cada canal de
  precio** configurado en Segmentación —margen, precio, comisiones y descuento—, y
  se actualiza sola cuando se crea un canal nuevo. También trae el resumen técnico
  para cotizaciones y la referencia y el precio del proveedor.
- **Plantillas PDF con encabezado y pie de página propios** en el modo código: se
  repiten en cada hoja, con la altura que se elija, y el pie puede decir «Página 2
  de 5». También hay salto de página.
- **Generar plantillas con IA, de dos maneras**: pidiéndoselo a Briela, o copiando
  un prompt ya preparado para ChatGPT, Claude o Gemini y pegando lo que devuelvan.
- **Botón «Validar»** en el editor de plantillas: dice qué variables no existen y
  qué bloques quedaron mal cerrados, probando contra el último documento real.
- Las plantillas admiten `{{else}}`, condicionales dentro de condicionales,
  comparaciones (`{{#if op.estado == "despachada"}}`), `{{#each items}}` y más
  formatos: número, porcentaje, fecha y hora.
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

### Corregido
- **Los productos importados desde CSV no tenían precio para cotizar**: la
  importación los guardaba donde la cotización ya no mira. Ahora quedan en su canal,
  y un archivo hecho con la plantilla anterior sigue sirviendo.
- Eliminar un producto ya no deja sus variantes sueltas, y quien no tiene el permiso
  de eliminar productos ya no puede hacerlo.
- **Una plantilla PDF hecha en modo visual salía en blanco** al descargar la
  cotización o la OP, aunque la vista previa se veía bien. Y una con papel de
  etiqueta o ticket fallaba. Ahora el documento sale igual que la vista previa.
- Las remisiones y los recibos de pago **usan su plantilla PDF** si la empresa
  hizo una; antes se diseñaba y nunca se aplicaba.
- En las plantillas, una condición dentro de la tabla de ítems (por ejemplo,
  mostrar la imagen solo si el ítem tiene) nunca se cumplía. Ya funciona.
- Una variable mal escrita ya no aparece como `{{texto}}` en el PDF del cliente.
- El logo de la empresa no salía en los PDF de plantilla. Ya sale.
- **En Trabajos, «Terminada» no respondía** en una unidad ya completa: se tocaba y
  no pasaba nada, que se ve igual que estar roto. Ahora acusa recibo diciendo a qué
  hora salió a Calidad.
- **El botón «Terminar» de Calidad no hacía nada** en las unidades cuyo ensamble no
  tiene lista de revisión cargada — que son casi todas. Esas unidades tampoco
  aparecían en el tablero, así que no había dónde aprobarlas, y sin aprobación no
  se podía remisionar nada. Ahora el tablero muestra todo lo fabricado que falta
  por revisar, con lista o sin ella, y «Terminar» la aprueba de verdad.
- En Trabajos, «Terminar» tampoco hacía nada en una unidad sin pasos de producción:
  ahora lo dice en vez de quedarse mudo. Y si la plantilla no marcó ningún paso
  como final, el último entrega igual.
- Guardar una orden de producción **recreaba sus ítems** y se llevaba por delante
  sus unidades, sus pasos y su revisión de calidad, en silencio. Ya no.
- Se eliminó el módulo suelto de «Plantillas de trabajo», que llevaba sin enlace
  desde que los pasos se cargan en la ficha del ensamble y editaba los mismos datos
  sin las validaciones de la pantalla nueva.

### Cambiado
- **El tablero de Trabajos muestra solo lo que falta por fabricar.** Una unidad
  terminada se va en cuanto pasa a Calidad, diciendo a dónde fue, y **vuelve sola
  si calidad la devuelve a reproceso**. Lo terminado sigue a un toque, en la
  tarjeta «Terminados». Igual en Calidad: lo aprobado sale de la bandeja.
- Las fichas muestran **de qué ítem de la orden son** (`OP-0005-02`). Dos ítems de
  la misma orden daban dos fichas que solo se distinguían leyendo las medidas.
- **Las fechas del proceso se ponen solas.** Cada unidad registra cuándo arrancó
  —la primera vez que se toca uno de sus pasos— y cuándo salió de producción, que
  es la misma hora a la que llegó a Calidad. Las dos se ven en el tablero, junto
  con la hora en que calidad la firmó. Nadie las escribe.
- **Mandar una orden a reproceso ahora hace algo.** Antes solo cambiaba la
  etiqueta: las unidades seguían figurando como terminadas y volver a producción
  dependía de que alguien se acordara. Ahora reabre en Trabajos las unidades que
  calidad rechazó —solo esas—, quedan marcadas «En reproceso», y la orden vuelve
  sola a Calidad cuando planta las rehace.
- **El último paso de producción ahora pregunta las dos bodegas**: a cuál entra el
  ensamble terminado y de cuál salieron los insumos que se gastaron en él. Llegan
  ya elegidas —las de la orden, o las de la unidad anterior— así que casi siempre
  es confirmar y seguir. Y ese paso ya no se puede cerrar si quedan otros pendientes.
- **Se puede remisionar lo que ya pasó calidad, sin esperar al resto de la orden.**
  Si el cliente quiere llevarse tres de las diez puertas y esas tres están
  revisadas, se despachan hoy.
- **Cambiar la cantidad de un ítem crea o elimina las unidades correspondientes**, y
  lo avisa antes de guardar. Nunca elimina una unidad que ya tenga trabajo hecho.
- Los puntos del colaborador se otorgan al cerrar un paso **desde cualquier
  pantalla**, no solo desde el código QR.
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
