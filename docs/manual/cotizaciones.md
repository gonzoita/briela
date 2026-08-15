# Cotizaciones

Ruta: `/cotizaciones`

## Qué es

El listado y editor de cotizaciones comerciales. Cada cotización tiene un
número automático (`COT-{año}-{correlativo}`), ítems (productos, servicios,
ensambles configurados), totales calculados y un estado:

`borrador → enviada → aprobada / rechazada / vencida`

Cuando se aprueba y se genera una Orden de Producción, queda marcada como
`en_produccion` y ya no se puede editar.

## Uso básico

- **Crear**: "Nueva cotización". Si viene generada automáticamente desde un
  lead del CRM, aparece un aviso azul arriba indicando de cuál lead proviene
  y el cliente ya queda preseleccionado.
- **Cambiar estado**: desde el detalle de la cotización.
- **Duplicar**: crea una copia en borrador con los mismos ítems.
- **Generar orden de producción**: desde una cotización aprobada.

## Automatizaciones activas

1. **Generación automática desde CRM** (ver
   [CRM — Pipeline](./crm-pipeline.md)): evita tener que crear la cotización
   a mano después de mover un lead.
2. **Alerta de seguimiento con link directo** *(actualizado 22 jul 2026)*: si
   una cotización lleva **5 días o más** en estado "Enviada" sin respuesta,
   aparece un aviso amarillo en el listado. Antes solo mostraba un número
   ("3 cotizaciones sin respuesta") sin decir cuáles — ahora muestra un chip
   por cada una (número + días) que lleva directo a su detalle al hacer clic.
3. **Comisión siempre visible** *(nuevo, 22 jul 2026)*: en el detalle de la
   cotización hay una tarjeta que muestra el estado de la comisión del
   vendedor (Proyectada / Confirmada / Sin comisión asignada). Antes, si la
   comisión calculada era $0 o la cotización era anterior a esta función, no
   aparecía nada — daba la impresión de que la comisión se había perdido.
   Ahora siempre se ve algún estado, nunca queda en blanco.
4. **Panel de Seguimiento** *(nuevo, 22 jul 2026)*: en el detalle de la
   cotización hay una sección para registrar notas de contacto con el
   cliente (llamadas, correos, visitas) con fecha y quién la escribió. Cada
   nota que se agrega reinicia el contador de "días sin respuesta" — así el
   cálculo de la alerta de seguimiento (punto 2) refleja el último contacto
   real, no solo la última edición de la cotización.
5. **"Enviada" ya no requiere un clic aparte** *(nuevo, 22 jul 2026)*: antes,
   después de copiar el link o mandarlo por WhatsApp desde "Enviar al
   cliente", había que apretar un botón extra ("Marcar como Enviada") para
   que el estado cambiara — un paso que se olvidaba fácil. Ahora, copiar el
   link (desde el botón de arriba o desde el modal) o tocar "Enviar por
   WhatsApp" ya marca la cotización como "Enviada" sola, porque esa acción
   ES el envío. El botón de estado sigue disponible como respaldo manual.
6. **"Aprobada"/"Rechazada" ya son automáticas**: cuando el cliente entra al
   link público y decide, el sistema cambia el estado solo — nadie de
   la empresa tiene que hacerlo a mano.
7. **"Vencida" automática** *(nuevo, 23 jul 2026)*: todos los días a la
   1:00 a.m. corre `cotizaciones:marcar-vencidas`, que pasa a "Vencida"
   cualquier cotización que siga en "Enviada" con la fecha de validez ya
   pasada. Requiere que el cron del servidor esté configurado — ver
   sección de deploy más abajo.

## Qué precio se le muestra a cada cliente *(cambiado 12 ago 2026)*

Al elegir el cliente, la cotización muestra **solo el precio de su canal**.
Los demás no aparecen. Junto al nombre del cliente sale una etiqueta con el
canal que se está usando.

Antes se mostraban los tres precios y se resaltaba el del cliente. Eso pone
el precio mayorista delante de quien está cotizando a un cliente final, y un
clic en la tarjeta equivocada es una venta por debajo.

**Si el cliente no está segmentado no se muestra ningún precio**, y la pantalla
explica qué hacer. Cuál canal le corresponde a cada cliente se decide en
[Segmentación](./segmentacion-y-precios.md).

## La comisión de un ensamble se calcula igual que la de un producto

La comisión se paga sobre el **excedente por encima del precio del canal base**, y ese
precio base tiene que salir del mismo lugar en los dos casos: la fila del canal marcado
como base.

Hasta el 15 ago 2026 el ensamble usaba la columna antigua `precio_mayorista`, calculada
con otro margen. El excedente salía distinto y la comisión de un ensamble no cuadraba con
la de un producto vendido al mismo cliente, aunque los dos se vendieran al mismo precio.

Y un ensamble **medido** llegaba a la cotización sin rango de comisión: el cálculo por
medidas recalcula los precios pero las comisiones no dependen de las medidas —son la
política comercial del ensamble— y no se estaban incluyendo. La barra de negociar no tenía
nada que mover. Ahora se toman de las filas guardadas del ensamble y se mezclan con el
precio recalculado.

## Cómo se calcula la comisión *(corregido 23 jul 2026)*

La comisión del vendedor **no** se calcula sobre el precio de venta
completo del ítem. Se calcula sobre el **excedente** por encima del precio
del canal base:

```
excedente   = precio de venta del ítem − precio del canal base
comisión    = excedente × cantidad × % de comisión aplicado
```

El canal base es la utilidad garantizada e intocable de la empresa —de fábrica
es Mayorista, y se cambia marcándolo en Segmentación. Cualquier venta por
encima de ese valor en los otros canales genera un excedente, que se reparte
entre más utilidad para la empresa y comisión para el vendedor. Por eso **el
canal base nunca genera comisión**: ahí no hay excedente que repartir.

Antes la fórmula usaba el precio de venta completo en vez del excedente,
lo que inflaba muchísimo la comisión mostrada (ej. 28,5% de $1.630.000 =
$464.550, cuando el excedente real sobre el mayorista era mucho menor). El
precio del canal base queda guardado en la cotización al momento de agregar
cada ítem, y no se recalcula después: una comisión que se liquida meses más
tarde tiene que calcularse con el precio que había al vender.

**Dónde se configura el % de comisión por producto/ensamble**: en el
detalle del producto o ensamble → botón "Editar" → sección "Comisión
Vendedor por Canal". Ahí se define el rango mínimo/máximo de comisión para
Distribuidor y Cliente final (Mayorista nunca tiene comisión). El botón
"Sugerir comisiones" calcula un rango automático según el margen
disponible. La vista previa en pesos de esa pantalla también se corrigió
para mostrar el valor sobre el excedente, igual que en la cotización.

## Cron del servidor (una sola vez)

El comando `cotizaciones:marcar-vencidas` (punto 7 arriba) solo corre si el
servidor tiene configurado el scheduler de Laravel. Esto se configura **una
sola vez** por SSH, no en cada deploy:

```bash
crontab -e
```

Y agregar esta línea (ajustar la ruta de PHP si es distinta en Hostinger):

```
* * * * * cd /home/USUARIO/domains/TUDOMINIO/public_html/briela && php artisan schedule:run >> /dev/null 2>&1
```

Para probar que el comando funciona sin esperar al cron:

```bash
php artisan cotizaciones:marcar-vencidas
```

## Pendiente de automatizar (backlog)

- Notificación proactiva (no solo visual al entrar a la página) cuando una
  cotización cumple el umbral de días sin respuesta.

## Crear el cliente sin salir de la cotización

Si el cliente no aparece en el buscador, el botón **+ Cliente** abre un formulario
ahí mismo, con el nombre que ya escribiste. Al guardar queda creado y seleccionado,
sin perder los ítems ni los precios que ya llevabas.

Pide lo mismo que la pantalla completa de clientes, porque es la misma acción: razón
social o nombre, identificación, contacto —**obligatorio para empresas**— y el **tipo
de cliente**. Ese último no es un adorno: de él salen los precios. Si eliges un tipo
que no define precio, el modal te avisa antes de guardar.

## Las condiciones comerciales

El bloque del final de la cotización se edita en cada cotización, y arranca con el
texto general de la empresa.

Para cambiar el general, escribe el texto que quieras y usa **«Usar este texto para
todas las cotizaciones nuevas»**. Pide permiso de configuración, porque cambia cómo
nacen todas. **Las cotizaciones ya hechas no se tocan:** cada una guardó su texto
cuando se creó.

## Cuántas unidades quedan *(nuevo 14 ago 2026)*

Al buscar un producto para cotizar, y al lado de la cantidad de cada ítem, sale
**cuántas unidades hay disponibles**, con color:

| Color | Cuándo |
|---|---|
| 🟢 verde | hay existencias por encima del mínimo |
| 🟡 ámbar | quedan hasta el mínimo definido en el producto |
| 🔴 rojo | «sin stock»: no hay nada que despachar |

El ámbar solo aparece si el producto tiene un **stock mínimo** puesto: sin mínimo no
hay forma de saber si cuatro unidades es poco o es lo normal, y el sistema no lo
inventa. Se pone en el producto, en Inventario.

Si la cantidad que se está cotizando **pasa** lo disponible, la casilla se pinta y
sale «⚠ no alcanza». No bloquea nada: a veces se cotiza a sabiendas de que hay que
fabricar o comprar. Solo evita prometerle al cliente unidades que no existen y que el
faltante aparezca recién en producción.

Antes el buscador mostraba el stock **siempre en verde**, dijera 200 o dijera 1 — y un
número verde se lee como «hay». En la pantalla de cotizar no se mostraba nada: solo el
precio.

Dos cosas que conviene saber:

- El número es el de las **bodegas de la sede activa**, igual que el inventario. Si no
  se puede determinar la sede, cuenta todas: decir «no hay» cuando hay sería peor.
- Al reabrir una cotización guardada se muestra el stock de **hoy**, no el del día en
  que se cotizó. El ítem no guarda stock: es una ayuda de pantalla, y el inventario de
  verdad se comprueba al despachar.
- Un producto marcado como no inventariable no muestra nada… **salvo que tenga
  existencias**. El interruptor de inventario del formulario de productos nace apagado,
  así que hay productos físicos con unidades y la marca en falso; callar ahí escondería
  el dato justo donde hace falta.

## El costo no se ve al cotizar *(nuevo 15 ago 2026)*

Ver el costo es un permiso aparte de ver el producto: **Ver costos** (`costos.ver`), en
Inventario y Compras. Lo tienen el administrador y el jefe de producción; **el vendedor
no**.

Un vendedor necesita el precio para cotizar y no necesita el costo. Tenerlo en la
pantalla de cotizar es la forma más fácil de que el margen de la empresa termine en una
conversación con un cliente.

El servidor **tampoco lo envía** a quien no puede verlo. Esconder la caja y mandar el
número igual lo dejaría a la vista en el código fuente de la página, que es esconderlo
solo de quien no sabe mirar.

## Cuando un ítem deja de poder guardarse

Pasa sin que nadie haga nada raro: se cotiza un producto simple y meses después alguien
le agrega variantes. Ese producto se vuelve **padre**, y lo que se vende es una variante
concreta — así que la cotización que lo tenía deja de poder guardarse. Lo mismo si el
producto se borra.

El aviso sale **dentro del ítem**, en rojo, con el botón «Quitar» al lado. Antes salía
como un mensaje al final de la pantalla que no decía en qué línea: con ocho ítems, quien
lo leía revisaba lo último que había agregado y concluía que el problema era eso.

Al intentar guardar también se avisa antes de mandar el formulario, diciendo el número
del ítem. Quitarlo y agregarlo de nuevo eligiendo la variante conserva el resto de la
cotización.

## Editar un ensamble ya cotizado *(nuevo 15 ago 2026)*

Un ensamble se cotiza con su receta **congelada**: los materiales y el costo que
resultaron de esas medidas ese día. Editarlo no es cambiar un texto — es volver al panel
de medidas, cambiar lo que haga falta y congelar la receta nueva.

El **lápiz** en el ítem de ensamble reabre ese panel con las medidas que tenía, calcula
de una y muestra el precio del canal del cliente. Al confirmar se reemplazan las medidas,
la receta y el precio; se conservan **el ítem, su cantidad y lo que se haya negociado** —
reemplazar el ítem entero le borraría al vendedor el descuento y la comisión que ya había
acordado.

Lo mismo en la orden de producción, con una diferencia importante: **una OP que ya
arrancó no se puede modificar**. El lápiz se apaga cuando la orden está en producción,
calidad, reproceso o despachada, y también cuando un operario ya cerró algún paso aunque
la orden siga en «confirmada» — porque ahí también hay trabajo hecho que proteger.

El candado está en el servidor, no solo en la pantalla: cambiar la receta con pasos
completados dejaría los tiempos y las fotos de los operarios apuntando a algo que ya no es
lo que se fabrica, y si una unidad entró a bodega, cambiarla descuadraría el inventario
hacia atrás. Las fechas, el responsable y las notas de una OP en producción sí se pueden
seguir guardando.

## Qué se imprime debajo de cada ítem

El **resumen técnico** del producto, no su ficha completa. Son dos o tres líneas con
medidas, material y potencia: lo que un cliente necesita leer al lado del precio.

Si un producto todavía no tiene resumen técnico, sale su descripción comercial, que
también es corta. La ficha técnica completa vive en el catálogo y en el sitio web —
antes se imprimía entera aquí, y una cotización de tres ítems salía de cuatro páginas.

En un ensamble a medida, además, se le pegan las medidas de esa configuración.

## Con qué precio se cotiza

Con el del **canal que le corresponde al cliente** por su tipo de contacto. Si tiene
varios tipos, gana el que esté más arriba en la lista de
[Segmentación](./segmentacion-y-precios.md).

**Si no le corresponde ninguno, se usa el precio público** y la etiqueta del canal sale
en ámbar con «por omisión». Nunca se cotiza en cero por falta de segmentación.

Si aun así un ítem entra en cero, es que ese producto no tiene precio cargado para ese
canal —pasa con canales creados después del producto— y el sistema lo dice al agregarlo,
con el nombre del canal que falta.
