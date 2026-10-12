# Logística y despachos (Remisiones)

Ruta: `/logistica/remisiones`

## Qué es

La remisión es el documento con el que la mercancía sale hacia el cliente.
Puede nacer de una OP (lo normal) o ser manual. Lleva los ítems que se
despachan, los datos del transporte y las firmas de despacho y recibido.

Estados: `borrador → confirmada → en_camino → entregada`, con `anulada`
como salida en cualquier punto antes de entregar.

## Dos listas: por remisionar y creadas *(nuevo, 12 oct 2026)*

La pantalla abre en **«Por remisionar»**: las órdenes con algo listo y todavía sin remisión, con
cuántas unidades tiene lista cada ítem y un botón «Remisionar N unidades» que abre la remisión con
todo precargado. **«Remisiones creadas»** es la lista de siempre, con sus estados.

**Qué está listo para remisionar** —una sola regla, la misma en la lista, en la orden y en el
remisionador—:

- un **producto** sin planta, **alistado o no** *(13 oct 2026)*, menos lo que ya salió: meterlo en
  la remisión lo alista. La pestaña «Por remisionar» sigue mostrando solo lo alistado
  (`OpItem::cantidadLista()`); el remisionador ofrece todo lo despachable
  (`OpItem::cantidadDisponible()`) y marca «Sin alistar» lo que todavía no lo estaba;
- una **unidad** de ensamble —o de un producto que pasó por planta— terminada, **firmada por
  calidad** y sin remisionar;
- los **servicios** nunca: se alistan, pero no viajan.

Las órdenes en **borrador** también se pueden remisionar: la orden arranca sola al hacerlo. Al
crear la remisión se abre la remisión misma *(13 oct 2026)*: lo siguiente es confirmarla y
marcarla en camino.

Antes la lista ofrecía órdenes con unidades sin aprobar —al abrirlas no había nada— y, al entrar
desde la orden, **los productos alistados no aparecían**. El botón de remisión de la orden pedía el
sello de calidad de la orden entera, que una orden de productos nunca tiene, y quedaba bloqueado.

## Remisión parcial: lo que queda pendiente *(15 oct 2026)*

Una remisión puede llevar solo una parte de un ítem: de 15 bisagras, las 10 alistadas. Al
crearla, cada renglón guarda lo que **quedó por entregar** de ese ítem
(`remision_items.cantidad_pendiente`, una foto que no cambia después). La ficha de la remisión y
su PDF lo dicen: «Pendiente por entregar: 5». Al crear la remisión, lo que lleva **ya salió de la
empresa**: sale del inventario en ese momento (ver más abajo).

## Cerrada: entregada y pagada *(15 oct 2026)*

«Entregada» es lo físico: el cliente recibió. La remisión **no está terminada** mientras la orden
tenga saldo: se ve «Entregada — pendiente de pago» con lo que falta verificar. Se pasa sola a
**Cerrada** (`remisiones.cerrada_at`) cuando la orden queda pagada del todo **con pagos
verificados** —por el verificador de pagos con el extracto, o marcados a mano en la cartera—.
Nadie la elige en la pantalla. Si después se borra o se desverifica un pago, vuelve a «Entregada».

- La regla vive en `RemisionService::sincronizarCierre()` y la llaman los pagos (eventos de
  `OpPago`: anotar, verificar, borrar) y la remisión al entregarse.
- Una remisión suelta, sin orden, no tiene nada que cobrar: se cierra al entregarse.
- Una cerrada no se anula ni se borra: ya salió y ya se cobró.

## Firmas *(arregladas el 15 oct 2026)*

La firma de despacho se pone desde que la remisión está **confirmada**; la de recibido, desde que
sale, con el nombre de quien recibe (firmarla con la remisión en camino la marca entregada). Se
dibujan con el dedo o el mouse (`FirmaPad.vue`), o la de despacho con «Usar mi firma», la guardada
en el perfil. Antes el trazo caía lejos del dedo (el lienzo se estiraba sin escalar los puntos), un
recuadro en blanco se guardaba y un error no se mostraba.

El servidor solo acepta una imagen PNG o JPG en base64 y guarda quién firmó el despacho y cuándo.
En el PDF salen las dos; si nadie dibujó la de despacho, sale la del perfil de quien la firmó o la
creó.

## Flujo normal

1. **Crear** desde una OP: se eligen las unidades a despachar. La remisión nace
   en "borrador" y **lo que lleva sale del almacén en ese momento** *(14 oct 2026)*: los productos
   tal cual y los ensambles por sus componentes. Anularla o borrarla lo devuelve. Ver
   `produccion-op.md`, «El inventario sale al remisionar».
2. **Confirmar**: el documento queda listo (ya no se edita).
3. **Marcar en camino**: se registran transportista, celular, placa y costo
   de flete. Queda la fecha de salida.
4. **Entregar**: el cliente firma en el celular al recibir.

## El candado de calidad es por unidad *(cambió el 30 ago 2026)*

Se remisiona **lo que ya pasó calidad**, unidad por unidad. De una orden de diez puertas, las
tres revisadas se despachan hoy y las otras siete siguen su curso: es lo que de verdad pasa en
el mostrador cuando el cliente se quiere llevar una parte del pedido.

Antes el candado era de la orden entera —`calidad_aprobada_at`— y esas tres esperaban a que la
última pasara revisión. Ahora vive en `OpItemTrabajo::disponiblesParaRemision()`: una unidad
está disponible si está armada, sin remisionar, **firmada por calidad**, y sin ninguna falla
crítica abierta.

La firma vale igual para un ensamble con lista de revisión y para uno sin ella — ver
[Calidad](./calidad.md). Que sea una sola regla importa: mientras la disponibilidad se dedujo
de «no le quedan puntos pendientes», las unidades sin lista quedaban fuera y no había forma de
despacharlas.

Si la orden no tiene ninguna unidad lista, el remisionador lo dice con precisión —«hay N
fabricadas pero ninguna pasó calidad»— en vez de mostrar una pantalla vacía.

## Automatizaciones activas

- **Firma del cliente = entrega cerrada** *(nuevo, 23 jul 2026)*: cuando el
  repartidor le pasa el celular al cliente y este firma el "recibido", la
  remisión pasa sola a "entregada" con su fecha y hora — antes había que
  firmar y además apretar el botón "Marcar entregada" por separado, y a
  veces la firma quedaba guardada pero la remisión seguía figurando "en
  camino". El botón manual "Marcar entregada" sigue existiendo como
  respaldo (por si se entrega sin firma).
- **OP despachada al salir** *(corregido el 12 oct 2026)*: la orden pasa sola a "despachada"
  cuando **todo lo que viaja ya salió** —sus remisiones están en camino o entregadas—, no al
  crear la remisión. Antes bastaba con crearla en borrador, y una orden con un servicio incluido
  no llegaba nunca a despachada (se contaba el servicio, que no se remisiona).
- **Los productos salen del inventario al salir la remisión** *(nuevo)*: al pasar a en camino (o
  entregada), cada producto de la remisión se descuenta de la bodega de entrega de la orden (o de
  la principal), con origen «Remisión». Antes un producto despachado **nunca** bajaba del
  inventario. Los ensambles los sigue descontando la orden al despacharse, **una sola vez**
  (`ops.inventario_consumido_at`).
- **Anular devuelve todo**: los ítems vuelven a quedar disponibles, los productos regresan a la
  bodega si ya habían salido (`remisiones.inventario_movido_at` evita moverlos dos veces), y si la
  orden estaba despachada por esta remisión vuelve a **calidad** (o a producción si algo quedó
  pendiente). Un producto repartido en dos remisiones recupera solo lo de la anulada.
- **Estados que no retroceden**: una remisión entregada o anulada ya no cambia, y una que salió
  no se elimina —se anula—. Eliminar una anulada no vuelve a liberar nada.
- **Permisos**: crear pide `remisiones.crear`; editar, cambiar de estado, firmar y eliminar piden
  `remisiones.editar`. Antes todo se podía con `remisiones.ver`.

## Correctamente manual (no son gaps)

Los pasos "en camino" y "entregada" (cuando no hay firma) dependen de
eventos físicos reales — que el camión salga, que el cliente reciba — así
que los confirma una persona. No tiene sentido automatizarlos.

> **Resuelto (23 jul 2026).** Antes, la OP se marcaba "despachada" y se
> descontaba el inventario apenas se **creaba** la remisión (aunque quedara
> en borrador y el camión no hubiera salido). Ya se corrigió: eso ahora
> ocurre cuando la remisión **sale físicamente** (pasa a "en camino"), con
> despachos parciales bien manejados — ver la sección de automatizaciones
> arriba.
