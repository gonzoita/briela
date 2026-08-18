# Manual de Briela

Briela es un ERP para **fabricantes por pedido**: empresas que no venden de un catálogo con
precios fijos, sino que cotizan según medidas, fabrican contra la orden y despachan.

Este documento recorre el sistema completo, módulo por módulo. Es la vista de conjunto; cada
módulo tiene además su página con el detalle en [`docs/manual/`](./manual/00-indice.md).

**Actualizado el 15 ago 2026.**

---

## El principio de fondo

Cada acción real dispara sola el siguiente paso. Nadie tiene que acordarse de nada:

- El cliente aprueba la cotización → **nace la orden de producción**.
- Se crea el ítem de la OP → **se generan los trabajos**, uno por unidad física, con sus pasos.
- El operario cierra el último paso → **la unidad entra a bodega** y se descuentan sus materiales.
- Se completan todos los ítems → **la OP pasa sola a calidad**.
- Calidad aprueba → **queda habilitada la remisión** (y sin ese sello, no se puede despachar).
- Se despacha → **sale de inventario**.

Cuando se diseña algo nuevo, la pregunta es siempre la misma: ¿qué paso debería disparar solo?

---

## El recorrido completo, de punta a punta

```
CRM (lead)
   ↓  se le asigna un cliente
COTIZACIÓN  ── el cliente la aprueba (puede hacerlo desde un enlace público)
   ↓
ORDEN DE PRODUCCIÓN
   ↓  al crear cada ítem se generan los trabajos: uno por unidad física
TRABAJOS ── el operario entra por QR, cierra pasos con tiempo y fotos
   ↓  al cerrar el último paso: material descontado + unidad a bodega
CALIDAD ── foto y observaciones. Falla → reproceso. Aprueba → se sella
   ↓
REMISIÓN con firma  →  DESPACHADA  →  sale de la bodega
   ↓
CARTERA (cuotas y pagos) · COMISIÓN del vendedor
```

---

## 1. Comercial

### CRM — pipeline de leads
`/crm`

Tablero por etapas configurables. Cada lead tiene notas, tareas con vencimiento, y su
historial. Desde un lead se crea el cliente y se pasa a cotizar sin perder el hilo.

Hay **formularios públicos** que capturan leads desde el sitio web, con reparto entre
responsables por peso.

→ [CRM — Pipeline de leads](./manual/crm-pipeline.md)

### Clientes
`/clientes`

Cada cliente lleva sus contactos, su segmentación y su historial. La identificación se
valida contra el dígito de verificación del NIT y se puede consultar en RUES.

Se pueden **importar desde CSV**.

→ [Identificación de clientes](./manual/identificacion-clientes.md) ·
[Importar desde CSV](./manual/importar-clientes.md)

### Segmentación y precios por canal
`/administracion/segmentacion`

**La decisión que ordena todo el precio.** La empresa define sus propios canales de precio
—mayorista, distribuidor, precio público, o los que quiera— y marca dos papeles:

- **Canal base**: el piso de utilidad de la empresa. No paga comisión ni admite descuento.
- **Precio público**: el que se usa cuando el cliente no está segmentado, y el del catálogo web.

Cada producto y cada ensamble tiene **un precio por canal**, con su margen, su rango de
comisión y su descuento máximo. Un cliente se cotiza con el canal que le corresponde; si no
tiene ninguno, con el precio público.

→ [Segmentación y precios](./manual/segmentacion-y-precios.md)

### Cotizaciones
`/cotizaciones`

- Cliente nuevo **sin salir de la cotización**, en un modal con toda la información.
- Al buscar un producto se ve **cuántas unidades quedan**, con color: verde disponible, ámbar
  hasta el mínimo, rojo sin stock. Si la cantidad cotizada pasa lo disponible, lo dice.
- **El costo no se ve** sin el permiso «Ver costos»: un vendedor necesita el precio, no el
  costo. El servidor tampoco lo envía.
- La **comisión se negocia con una barra**, entre el mínimo y el máximo del canal. Es un
  porcentaje del precio de venta, y el **Dto. % se llena solo** con lo que el vendedor deja de
  ganar: la empresa gana lo mismo esté la barra donde esté.
- **Condiciones comerciales** editables por cotización, con un texto general por omisión.
- Un **ensamble se puede volver a medir** con el lápiz del ítem: reabre el panel de medidas,
  recalcula y congela la receta nueva, conservando lo negociado.
- El cliente aprueba desde un **enlace público** sin entrar al sistema.

→ [Cotizaciones](./manual/cotizaciones.md) · [Cotización aprobada → OP](./manual/cotizacion-a-op.md)

### Comisiones
`/comisiones`

Lo que se le debe a cada vendedor: un porcentaje del precio de venta de cada ítem, con la
comisión que se negoció ahí. Resumen del mes y detalle por documento, los dos en PDF. El tope de
cada canal sale de su **excedente sobre el canal base** —el piso de utilidad de la empresa— y
ningún canal puede pagar menos plata que el de abajo.

---

## 2. El cotizador de ensambles

Es lo que hace a Briela distinto de un ERP de catálogo.

### Plantillas de ensamble
`/cotizadores/plantillas`

Una plantilla es **la receta genérica** de algo que se fabrica por medidas: qué se pregunta
(ancho, alto, acabado) y qué materiales consume **según esas respuestas**, con fórmulas.

Tres partes: **campos** (lo que se pregunta), **componentes** (lo que consume, con su fórmula)
y **pasos de producción** (cómo se fabrica). El motor de fórmulas es Symfony
ExpressionLanguage — no ejecuta código arbitrario.

### Ensambles
`/ensambles`

Un ensamble es un producto concreto salido de una receta. Dos formas de armarlo:

| Modo | Cuándo |
|---|---|
| **Con plantilla** | Se fabrica por medidas: las fórmulas calculan los materiales |
| **Directo, sin cálculos** | Siempre lleva lo mismo: la lista se escribe a mano, con cantidades exactas |

El directo admite materiales del inventario —que descuentan al fabricar— y **conceptos
libres** como mano de obra o transporte, que suman al costo y no descuentan nada.

**«Cómo se fabrica» es obligatorio.** La ficha pide los pasos que el operario va a marcar, y no
deja guardar sin al menos uno. Arranca con un paso «Fabricación» al 100 % ya escrito, así que
detallarlos es opcional pero tenerlos no. En un ensamble directo los pasos son suyos; en uno con
plantilla son **de la plantilla**, y los comparten todos los ensambles que la usan — la pantalla
lo advierte antes de que alguien los cambie sin saberlo.

Un ensamble tiene referencia, unidad de medida, precios por canal, ficha con IA, publicación
web y duplicado, **igual que un producto**.

**«Se guarda en bodega»**: para lo que se fabrica por adelantado. Al prenderlo, el ensamble
obtiene su ficha de **producto terminado** en el inventario y con ella todo lo que ya existe:
stock por bodega, movimientos, traslados, mínimos y avisos.

Su ficha responde dos preguntas distintas: **cuántas se pueden armar** con el material que hay
—limitado por el que primero se agota, diciendo cuál es y qué falta— y **cuántas hay ya
armadas**, bodega por bodega.

→ [Plantillas de Ensamble](./manual/plantillas-ensamble.md)

---

## 3. Producción

### Órdenes de producción
`/produccion/ops`

Estados: `borrador → confirmada → en_produccion → calidad → despachada`, más `reproceso`
cuando calidad rechaza. El avance se recalcula solo y la OP cambia de estado sola.

`calidad_aprobada_at` es un **candado obligatorio**: sin ese sello no se puede remisionar.

Una OP que ya arrancó **no se puede modificar**: ni cuando está en producción, ni cuando un
operario ya cerró algún paso aunque el estado siga en «confirmada». Fechas, responsable y notas
sí se siguen pudiendo cambiar.

→ [Producción — OP y calidad](./manual/produccion-op.md)

### Trabajos y pasos
`/trabajos` · el operario entra por `/trabajo/{token}`

Un **trabajo es una unidad física**: si la OP pide cinco, hay cinco trabajos. Cada uno tiene
sus pasos, copiados de la plantilla, con peso, dependencias, tiempos, fotos y los operarios
que lo hicieron.

**El último paso es el de entrega y dice a qué bodega llega la unidad.** Al cerrarlo, en la
misma operación: se descuentan los materiales de esa unidad y se registra su entrada. No se
puede entregar dos veces.

→ [Trabajos y pasos](./manual/trabajos-pasos.md)

### Programador de planta y pantalla de planta
`/produccion/programador` · `/planta/{token}`

El programador reparte los pasos entre estaciones de trabajo y tiempos. La pantalla de planta
es una vista sin login, para colgar en un televisor: token propio, regenerable desde
configuración.

---

## 4. Inventario y compras

### Productos
`/productos`

Productos, servicios y **variantes** (un padre con sus hijos; lo que se vende es la variante).
Cada producto lleva su referencia, unidad, categoría, imágenes, descripciones, precios por
canal y **varios proveedores para comparar precio** — con días de entrega, mínimo de compra y
la fecha del precio, porque un precio de hace ocho meses no es un precio.

Se puede **duplicar** e **importar desde CSV**.

→ [Productos e inventario](./manual/productos-inventario.md)

### Stock, bodegas y movimientos
`/compras/inventario`

Stock por bodega, con mínimos y aviso diario de faltantes. Todo movimiento queda registrado:
entradas, salidas, traslados, consumo por fabricación y ajustes.

El stock que se ve es el de **las bodegas de la sede activa**.

### Compras
`/compras/solicitudes` · `/compras/ordenes` · `/compras/proveedores`

Solicitud de compra → aprobación → orden de compra → recepción, que entra el material a
bodega. La OP avisa qué material falta para lo que está pendiente.

→ [Compras, inventario y faltantes](./manual/compras-inventario.md)

### Recetas de corte
`/compras/inventario/recetas-corte`

Para material que se compra en un formato y se usa en otro: una lámina que se corta en piezas.
Se define qué produce y al «construir» descuenta el material grande y entra las piezas.

---

## 5. Logística y financiero

### Remisiones
`/logistica/remisiones`

Despacho con firma. Admite **parciales**. Al despachar sale de la bodega: si la unidad se
fabricó y ya había entrado, sale de ahí; si no, se consumen los materiales.

→ [Logística y despachos](./manual/logistica.md)

### Cartera
`/financiero/cartera`

Lo que deben los clientes. Cuotas de la OP, anticipos y pagos, con su saldo.

---

## 6. Personas

### Colaboradores (RRHH)
`/rrhh/operarios`

Ficha de cada operario, disciplina, horas extra, permisos, bonos y **gamificación**: puntos por
paso completado, niveles y metas. Estaciones de trabajo.

→ [Recursos Humanos](./manual/rrhh.md)

### Capacitación
`/capacitacion`

Cursos con módulos y lecciones, evaluaciones con intentos y nota mínima, certificados con
**código verificable en público** (`/verificar-certificado/{codigo}`). Portal aparte para los
estudiantes.

→ [Capacitación](./manual/capacitacion.md)

### Usuarios, roles y permisos
`/usuarios` · `/administracion/roles`

Catálogo de permisos en código, **roles configurables desde la interfaz**. Cada permiso es
módulo + acción (`cotizaciones.crear`, `costos.ver`). Se pueden dar permisos extra por persona.

→ [Roles y permisos](./manual/roles-permisos.md)

---

## 7. Inteligencia artificial

Todo sale por **un solo punto**: `IaService`. La llave **nunca vive en la instalación del
cliente** — el asistente sale por el proxy del superadmin, que identifica cada instalación por
su serial. Es el modelo de ganancia y la única palanca de licenciamiento efectiva.

Lo que hace hoy:

- **Ficha técnica de productos y ensambles.** Genera tres textos de una vez: la descripción
  corta comercial, el **resumen técnico para cotizaciones** —solo datos, que es lo que se
  imprime debajo del ítem— y la ficha larga en HTML. Usa el perfil de marca de la empresa y
  admite aportes por bloque: descripción, características, ventajas, beneficios, componentes.
- **Recomendar productos.** Lee las descripciones y responde preguntas como «qué me recomiendas
  para guardar temperatura, económico, que sirva para congelación y mida 1,2 × 2,3». Para no
  gastar de más, primero filtra por palabras y medidas en la base y solo le pasa al modelo un
  puñado de candidatos.
- **Asistente con voz**, textos de redes sociales, y ayuda al redactar.

El gasto se mide por instalación, con **tope diario y mensual**, y se concilia contra lo que
informa el proveedor.

→ [Integración de IA](./manual/ia.md)

---

## 8. Transversal

| Módulo | Ruta | Qué hace |
|---|---|---|
| **Dashboard** | `/dashboard` | Lo que hay que mirar hoy |
| **Buscador global** | Ctrl+K | Encuentra cualquier documento y encadena entre módulos |
| **Notificaciones** | la campanita | Punto único de avisos, con su catálogo |
| **Hilos internos** | en cada documento | Comentar sobre una cotización o una OP |
| **Chat** | `/api/chat` | Mensajes directos y grupos entre usuarios |
| **Multimedia** | `/multimedia` | Biblioteca de archivos |
| **Auditoría** | `/auditoria` | Quién hizo qué, cuándo |
| **Informes** | `/informes` | Informes configurables |
| **Sedes** | `/administracion/sedes` | Varias sedes por instalación, con su numeración |
| **Marca** | `/configuracion/marca` | Color, logo, favicon y título |
| **Plantillas PDF** | `/configuracion/plantillas-pdf` | El diseño de cada documento |
| **Unidades de medida** | configuración | La lista editable de unidades |
| **Respaldos** | `/configuracion/backups` | Copias de la base, y antes de cada actualización |

→ [Índice completo del manual](./manual/00-indice.md)

---

## 9. Lo que se publica hacia afuera

**Sin login:**

- `/cotizaciones/{token}/aprobar` — el cliente aprueba su cotización
- `/op/{token}` — seguimiento de una orden
- `/seguimiento` — el cliente consulta con apellido o documento
- `/catalogo/productos/{id}` y `/catalogo/ensambles/{id}`, con su PDF
- `/verificar-certificado/{codigo}`
- `/planta/{token}` — la pantalla de planta

> No existe un índice `/catalogo`: solo fichas individuales.

**Con login:** `/trabajo/{token}`, el QR del operario.

**Al sitio web del cliente:** el plugin **Briela Connect** para WordPress publica productos y
ensambles. El plugin pregunta a la instalación, no al revés.

→ [Publicar en el sitio web](./manual/publicar-en-la-web.md) ·
[Conexiones externas](./manual/conexiones.md)

---

## 10. Cómo se instala y se actualiza

Briela se **instala en el servidor de cada cliente**: una instalación, una base de datos. El
aislamiento es físico, no por `tenant_id`.

- **`public/build/` viaja en el repositorio**, así el cliente instala sin Node ni Vite.
- **Migraciones siempre hacia adelante y nunca destructivas**: cada una debe poder correr sobre
  cualquier versión anterior soportada.
- **Respaldo automático de la base antes de cada actualización.**
- **Nada que dependa del hosting**: no se asume `shell_exec`, ni git, ni `mysqldump`. Se
  verifica y se degrada con elegancia.
- **Nunca se sobreescriben** `.env`, `storage/` ni `public/storage`.

El servidor **jala** los cambios de GitHub —no al revés— y antes de aplicar nada respalda la
base.

→ [Despliegue](./manual/deploy-automatico.md) · [Respaldos](./manual/backups.md) ·
[Operación y montaje](./OPERACION.md)

---

## Para el que va a programar

Este manual es de uso. Las reglas del código, la arquitectura y lo que nunca se rompe están en
[`CLAUDE.md`](../CLAUDE.md) y en [`docs/BRIELA-PLAN.md`](./BRIELA-PLAN.md).
