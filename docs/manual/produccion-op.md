# Producción — Orden de Producción (OP)

Ruta: `/produccion/ops`

## Qué es

El sistema real y activo de producción es `Op` + `OpItem` + `OpItemTrabajo`
(con sus pasos). Reemplazó por completo a un sistema anterior basado en
"3 líneas paralelas" (Puertas / Panelería / Almacén) que **ya no está en
uso** — ver la sección de código legacy más abajo.

Estados de una OP: `borrador → confirmada → en_produccion → calidad →
despachada`, con la posibilidad de que calidad la mande a `reproceso` en vez
de aprobarla, y desde ahí vuelva a `en_produccion`.

## Sin cliente no se procesa *(15 oct 2026)*

Una orden sin cliente **no se guarda**. Las viejas que quedaron sin cliente no se confirman, no se
alistan, no se cierran pasos en planta ni se remisionan hasta asignarle uno
(`Op::SIN_CLIENTE`); la ficha lo avisa arriba. Tampoco nace una orden de una cotización sin cliente.

## Automatizaciones activas

1. **Trabajos de producción automáticos** al generar la OP desde una
   cotización (ver [Cotización → OP](./cotizacion-a-op.md)).
2. **Anticipo real** registrado como cuota/pago desde la generación de la OP.
3. **Consumo de inventario automático** al despachar.
4. **Paso a "Calidad" automático** *(nuevo, 22 jul 2026)*: antes, aunque
   producción ya hubiera terminado todos los ítems de la OP, el estado se
   quedaba en "En producción" hasta que alguien entraba manualmente a
   "Cambiar estado" y elegía "Calidad" a mano. Ahora, cada vez que se
   completa el último paso de trabajo de un ítem, el sistema revisa si con
   ese ítem ya quedaron **todos** los de la OP terminados — si es así, la OP
   pasa sola a "Calidad", lista para que jefe de producción/administrador
   revisen. El botón manual de "Cambiar estado" sigue existiendo como
   respaldo, pero ya no debería hacer falta usarlo para este paso.

   **Resuelto el 30 ago 2026.** Este era el pendiente del módulo: el regreso desde
   "Reproceso" era manual porque no había forma de saber qué falló. Ahora sí la hay
   —la revisión de calidad es punto por punto y por unidad—, así que mandar a
   reproceso reabre las unidades que fallaron, y la orden **vuelve sola a "Calidad"**
   cuando planta termina de rehacerlas. Ver [Calidad](./calidad.md).
5. **"Confirmada" pasa directo a "En producción"** *(nuevo, 22 jul 2026)*:
   antes, después de confirmar la OP (con o sin anticipo), había que volver
   a entrar a "Cambiar estado" y elegir "En producción" a mano — un segundo
   clic sin ninguna decisión real detrás. Ahora, apenas se confirma (ya sea
   desde el modal de anticipo o desde "Cambiar estado" directo), la OP pasa
   sola a "En producción". Esto además corrige un problema silencioso: la
   transición automática a "Calidad" (punto 4) solo funciona si la OP está
   en "En producción" — si se quedaba pegada en "Confirmada", nunca iba a
   avanzar aunque toda la producción estuviera lista.
6. **La OP se pone al día sola si ya hay trabajo real** *(nuevo, 23 jul
   2026)*: se detectó que un operario podía estar avanzando pasos de un
   trabajo (43% completado, por ejemplo) mientras la OP seguía mostrando
   "Borrador" en el detalle — el trabajo real en planta no estaba
   actualizando el estado de la OP. Ahora, apenas se registra el primer
   avance real en cualquier trabajo, si la OP seguía en "Borrador" o
   "Confirmada" pasa sola a "En producción".
7. **Progreso general de la OP corregido** *(nuevo, 23 jul 2026)*: el
   porcentaje que se ve arriba del detalle de la OP ("Progreso general de
   la OP") se quedaba pegado en 0% cuando el avance se registraba desde
   Programador/Trabajos (el camino más usado) — solo se actualizaba desde
   un par de pantallas puntuales. Ahora se recalcula cada vez que cambia el
   avance de cualquier trabajo, sin importar desde dónde se registre.
8. **Control de calidad real, obligatorio para despachar**: antes
   el estado "Calidad" era solo una etiqueta sin ningún dato detrás — no
   había dónde registrar fotos, observaciones ni un motivo de rechazo, y
   además el despacho (remisión) no dependía para nada de este paso.
   Ahora, cuando una OP está en estado "Calidad", aparece un panel para
   adjuntar fotos de evidencia (desde el celular se puede elegir entre
   tomar la foto con la cámara o subir una ya existente de la galería, y se
   pueden subir varias de una vez), escribir observaciones (medidas,
   acabado, hermeticidad), y decidir:
   - **Aprobar calidad**: marca `calidad_aprobada_at` — con esto ya se puede
     generar la remisión desde Logística. La OP sigue en estado "Calidad"
     hasta que la remisión la despache de verdad.
   - **Rechazar**: pide motivo obligatorio y manda la OP a `reproceso`. Desde
     ahí producción la retoma y, al terminar, vuelve a pasar por "Calidad".

   Solo administrador y jefe de producción pueden decidir. Es un requisito
   duro: ni el cambio de estado manual a "Despachada" ni la generación de
   la remisión funcionan si la OP no tiene `calidad_aprobada_at`.

## El estado se decide en un solo sitio *(12 oct 2026)*

`Op::sincronizarEstado()` es el punto único del flujo. Lo llaman alistar un ítem (Alistamiento y
el botón de la orden), cerrar o reabrir un paso, firmar calidad y que salga o se anule una
remisión. Reglas:

| Pasa | Cuándo |
|---|---|
| borrador o confirmada → **en producción** | algo queda listo (un producto alistado o remisionado, una unidad terminada) o se avanza un paso en planta |
| → **calidad** | todo listo: productos alistados y todas las unidades terminadas. **Los servicios no esperan** *(13 oct 2026)*: una instalación se hace después de entregar, así que solo cuentan en una orden de solo servicios (`Op::cuentanParaListo()`) |
| se **sella** `calidad_aprobada_at` | todo listo, **todas** las unidades firmadas y nada pendiente; una orden sin unidades, al quedar todo listo |
| calidad → **en producción** | algo vuelve a quedar pendiente (se desalista, se reabre una unidad); el sello se retira |
| → **despachada** | todo lo que viaja ya salió en remisiones en camino o entregadas; una orden de solo servicios, al quedar todo hecha |

El estado del ítem con unidades lo deciden sus unidades (y se corrige si quedó desfasado). Un
borrador sin nada listo no se toca: todavía se está armando. **Pero si alguien ya lo está
trabajando —alista un producto, lo remisiona o avanza un paso— arranca solo** *(13 oct 2026)*, y
como nunca pasó por «Confirmar», se le precargan las dos bodegas con la principal
(`Op::iniciarProduccionSiAplica()`); se pueden cambiar en la orden. Una orden despachada solo vuelve atrás si se anula la
remisión que la despachó. Las órdenes que quedaron atascadas se ponen al día al abrirlas, al
actualizar (`ops:sincronizar-estados` corre en el despliegue y al instalar una versión) o a mano
con ese mismo comando.

## Rechazar en calidad devuelve el trabajo a planta *(13 oct 2026)*

Calidad rechaza desde su módulo o desde el botón de la orden, y los dos caminos hacen lo mismo
(`Op::mandarAReproceso()`): la orden pasa a **Reproceso**, las unidades con una falla marcada se
reabren en planta (su paso final) y pierden la firma, y si ninguna tenía falla se reabren todas
las que no han salido. En una orden de productos, lo alistado que no salió vuelve a pendiente.
Vuelve sola a Calidad cuando se rehace.

Antes el botón de la orden solo cambiaba la etiqueta: las unidades seguían firmadas —se podían
remisionar— y al abrir la orden el estado se recalculaba y volvía solo a «Calidad».

## Solo los ensambles pasan por planta *(14 oct 2026)*

Un **producto** sencillo —una bisagra, un vidrio de catálogo— no tiene unidades, pasos ni revisión
de calidad: **se alista y queda remisionable**. Solo los ensambles nacen con unidades en planta
(`TrabajoAutoGeneratorService::templateDe()` devuelve nada para un producto, y «Procesar en planta»
lo rechaza).

La versión 1.5.0 alcanzó a dejar mandar productos a planta. Al actualizar, `ops:sincronizar-estados`
les quita esas unidades (`quitarUnidadesDeProductos()`): si estaban terminadas el producto queda
alistado, si no, pendiente de alistar; las que ya salieron en una remisión no se tocan.

«Procesar en planta» queda para un ensamble sin unidades o al que hay que cambiarle el flujo antes de
empezar: crea todas las unidades de una vez, con su revisión. «Sacar de planta» las borra mientras
nadie haya avanzado ninguna.

## Cómo se llega a "Despachada"

La orden se despacha cuando **todo lo que viaja ya salió** en remisiones en camino o entregadas
(`Op::sincronizarEstado()`). Despachar ya no mueve inventario: lo remisionado salió del almacén al
**crear** cada remisión (ver «El inventario sale al remisionar»). El cambio manual a «Despachada»
sigue existiendo como respaldo y exige la aprobación de calidad.

## Quién puede ver qué

- Administrador y jefe de producción: ven todas las OPs.
- Vendedor: solo ve las OPs donde es responsable.

## Código legacy — no usar

Estos modelos/controladores existen en el repositorio pero **no tienen
ninguna ruta activa** — son restos de una versión anterior del sistema, de
antes de que se construyera el `Op` actual. No hay que tocarlos ni asumir
que reflejan el estado real de nada:

- `OrdenProduccion` / `LineaOP` (tabla `ordenes_produccion` / `lineas_op`) —
  el sistema viejo de 3 líneas paralelas. Sin controlador, sin rutas.
- `ItemOP` — ítems del sistema viejo.
- `resources/js/Pages/Seguimiento/Show.vue` — pantalla de resultado vieja,
  ya no se usa (ver abajo).

**Nota histórica**: hasta el 22 de julio de 2026, el **Dashboard principal**
también leía de `OrdenProduccion` en vez de `Op` — se corrigió ese día, pero
es un buen ejemplo de por qué es importante no dejar código muerto sin
marcar: nadie notó durante meses que las métricas de inicio no eran reales.


## Las unidades siguen a la cantidad *(nuevo 30 ago 2026)*

Cambiar la cantidad de un ítem **crea o borra unidades**, y la pantalla lo pregunta antes de
guardar: cada unidad es una pieza física con su código QR, sus pasos y su revisión de calidad.

- **Sube de 1 a 3**: se crean las dos que faltan, completas, y las tres pasan a decir «de 3».
- **Baja**: se borran las últimas, y **solo las que nadie tocó**. Si la cantidad nueva queda por
  debajo de las unidades que ya tienen trabajo registrado, se rechaza y se dice cuántas hay en
  producción. Borrar trabajo real no se puede deshacer.
- **Cambia el ensamble**: las unidades se rehacen con los pasos de la receta nueva, y si alguna
  ya tiene avance no se deja: quedaría fabricándose con los pasos de la receta anterior.

Antes esto no pasaba: corregir una OP de 1 a 3 puertas dejaba dos unidades sin trabajo — sin
QR, sin pasos y sin calidad— y nada lo decía.

> **De paso se corrigió algo peor.** El id del ítem no estaba en las reglas de validación, y
> `validate()` devuelve solo lo que valida: cada guardado de una OP **creaba ítems nuevos y
> borraba los viejos**, y con ellos sus unidades, sus pasos y su revisión, en cascada y en
> silencio. Está fijado con una prueba.

## Calidad, ítem por ítem

**El bloque de «Control de calidad» de la orden entera se quitó el 18 ago 2026.** Era una foto,
un comentario y aprobar; en una orden de diez puertas no decía qué se revisó ni cuál salió mal.

Ahora la revisión está **dentro de cada ítem**, con la lista que definió su plantilla, y una
unidad por bloque: de cinco puertas, una puede pasar y otra no, y eso hay que poder decirlo.

**La orden se aprueba sola** cuando no queda ningún punto sin resolver, y el sello se retira si
algo se marca como falla después. Es el principio del sistema: cada acción real dispara el
siguiente paso. Un botón de «aprobar» al final de una revisión punto por punto termina siendo un
trámite que se aprieta sin mirar.

## Calidad, unidad por unidad

Calidad era una decisión de una sola pieza sobre la orden entera: aprobada o a reproceso, con
una foto y un comentario. En una orden de diez puertas eso no dice nada — no queda registro de
qué se revisó, ni de cuál unidad salió mal, ni de qué le faltaba.

Desde el 18 ago 2026, si el ensamble tiene lista de revisión, **cada unidad se revisa punto por
punto** desde su hoja de producción, y la orden no se puede aprobar mientras quede algo sin
resolver. El aviso dice cuántos puntos faltan, que es lo único que sirve cuando son diez
puertas.

Un ensamble sin lista de revisión sigue funcionando como siempre: calidad aprueba la orden
completa. La lista se define en la ficha del ensamble, sección «Revisión de calidad».

**Desde el 30 ago 2026 la revisión tiene su propio módulo**, `/calidad`, con permiso propio
(`ops.calidad`): un tablero de fichas grandes con todas las unidades fabricadas y sin
despachar, para marcar sin entrar orden por orden, y una ficha de verificación por unidad con
toda la información del proyecto. Los dos cierres —el de la unidad y el de la orden— viven
ahí. La hoja de producción sigue teniendo su bloque de revisión: es el mismo dato.

→ [Calidad](./calidad.md)


## Seguimiento público — dos caminos, un solo resultado *(reconstruido 23 jul 2026)*

Hay dos formas de que un cliente consulte el estado de su pedido, y ahora
las dos muestran exactamente la misma información (misma vista
`OpPublica/Show`, mismos datos — ver `Op::datosSeguimientoPublico()`):

1. **`/op/{token_publico}`** — el link directo de los QR impresos en
   etiquetas y PDFs de OP. Es el que hay que compartir con el cliente.
2. **`/seguimiento`** — un buscador manual donde el cliente escribe el
   número de OP (ej. `OP-0017`) o el número de serie de un ítem (ej.
   `IF-2026-045-P-001`), o escanea un QR con la cámara del navegador.
   Antes buscaba contra `OrdenProduccion` (modelo muerto) y nunca
   encontraba nada real — reconstruido para buscar contra `Op`/`OpItem`.

**Candado de privacidad** *(cerrado 24 jul 2026)*: como el número de OP es
secuencial y predecible, `/seguimiento` ahora exige, además del número, un
**dato del cliente** (apellido o número de documento, tal como figura en la
orden). Si no coincide, no muestra nada. Así nadie puede espiar pedidos
ajenos probando números. El link por QR (`/op/{token}`) no pide esto porque
el token ya es imposible de adivinar.

## El inventario sale al remisionar *(14 oct 2026)*

**Lo que se remisiona sale del almacén en el momento de crear la remisión**, y queda en los
movimientos con origen «remisión» (`InventarioRemisionService`, llamado por `RemisionService::crear`):

| Qué viaja | Qué sale del almacén | De qué bodega |
|---|---|---|
| Un **producto** | El producto, la cantidad remisionada | La de entrega de la orden (o la principal) |
| Un **ensamble** | **Sus componentes**: la receta de una unidad × las unidades que viajan | La de material de la orden (o la principal) |
| Un servicio | Nada | — |

Terminar en planta **no mueve inventario** ni pide bodegas: el paso final solo da la unidad por
terminada. Cada unidad sabe en qué remisión salió (`op_item_trabajos.remision_id`).

**Anular o borrar** la remisión devuelve exactamente lo que sacó: se leen sus movimientos y se
revierten, sin recalcular la receta —que pudo cambiar— ni adivinar unidades. Las unidades y los
productos quedan otra vez disponibles para remisionar.

**Si algo no alcanza**, se descuenta lo que hay —el camión ya salió— y llega un aviso
`material_faltante` a administración y jefatura con el detalle de qué faltó y en qué bodega.

Las dos bodegas de la orden siguen exigidas al **confirmar** (o se precargan con la principal si la
orden arranca sola desde borrador), con este sentido: la **de material** es de dónde salen los
componentes de los ensambles; la **de entrega**, de dónde salen los productos.

### Antes de este cambio

Hasta el 13 oct 2026 el material de cada unidad se descontaba al cerrar su paso final y la unidad
entraba a bodega como **producto terminado**; al despachar salía ese producto terminado, y los
productos sueltos bajaban recién cuando la remisión salía. Una unidad que alcanzó a entrar así
(`entregado_at`) sale ahora como ese producto terminado, sin volver a descontar sus componentes.
