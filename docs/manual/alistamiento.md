# Alistamiento

Ruta: `/produccion/alistamiento` · Permisos: `alistamiento.ver`, `alistamiento.alistar`

Lo que hay que dejar listo antes de que salga el camión, de todas las órdenes a la vez.

## Por qué existe

Lo que se despacha no es una orden: son ítems. Cinco bisagras de una y una puerta de otra
salen en el mismo viaje. Hasta el 18 ago 2026 alistar era un botón dentro de cada orden de
producción, así que el almacenista tenía que saber en qué OP estaba cada cosa y entrar una
por una.

## Qué se ve

- **Tablero**: pendientes, alistados, alistados hoy y por despachar. **No cambia con los
  filtros** — un tablero que se mueve al filtrar dice cómo va la búsqueda, no cómo va el día.
- **Filtros**: texto libre sobre el ítem, la serie, el número de OP y el cliente; estado;
  tipo (ensamble, producto, servicio); y plantilla del ensamble.
- **Una fila por ítem**, con su orden, su cliente, su avance de fabricación y su estado.

## Alistar

Un clic por ítem. Se puede devolver a pendiente mientras no haya salido: alistar de más es un
error que se comete, y sin forma de deshacerlo habría que pedir que editen la base. Lo que ya
tiene unidades remisionadas no se devuelve — eso no está en la bodega.

**Lo que queda alistado es exactamente lo que el remisionador deja escoger.**

## Los servicios se alistan pero no viajan

Un servicio —mano de obra, transporte, instalación— se marca como alistado porque alguien
tiene que declarar que está hecho, pero no aparece en la remisión: no hay nada que cargar. La
lista lo dice en la fila para que nadie lo busque después.

## Alistar mueve la orden *(corregido el 12 oct 2026)*

Alistar un ítem **pone al día el estado de la orden**, igual que cerrar el último paso de una
unidad: con el primer ítem listo la orden pasa a **En producción**; con todo listo, a **Calidad**
—y una orden de solo productos se sella sola, porque no tiene unidades que firmar—; desalistar algo
la devuelve a producción. Antes alistar solo marcaba el ítem y la orden seguía donde estaba.

**Un ensamble con unidades no se alista a mano**: queda listo solo cuando sus unidades terminan en
planta, y deja de estarlo si una se reabre. La lista lo dice en la fila en vez de ofrecer el botón.

## Borradores y remisiones *(13 oct 2026)*

La lista trae también los ítems de órdenes en **borrador**: alistar algo es empezar a trabajar la
orden, y la orden arranca sola. Y **alistar ya no es obligatorio para despachar un producto**:
remisionarlo lo alista. Alistar sigue sirviendo para que el almacenista deje constancia de lo que
dejó en el muelle; la pestaña «Por remisionar» de Remisiones muestra solo eso.

Un producto que se procesa en planta se comporta como un ensamble: lo alista la planta al terminar
sus unidades, no la mano.

## Con qué se conecta

- El botón «Marcar alistado» de la orden de producción hace lo mismo, y se queda como atajo
  para quien ya está mirando esa orden.
- [Remisiones](./remisiones.md): al abrir «Nueva remisión» salen las órdenes con algo listo,
  sin tener que buscarlas.
- Un **ensamble** se alista solo cuando su trabajo está terminado; un **producto** no tiene
  trabajos —nadie lo fabrica, sale de bodega— y por eso su estado lo lleva el ítem.
