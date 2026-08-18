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

## Con qué se conecta

- El botón «Marcar alistado» de la orden de producción hace lo mismo, y se queda como atajo
  para quien ya está mirando esa orden.
- [Remisiones](./remisiones.md): al abrir «Nueva remisión» salen las órdenes con algo listo,
  sin tener que buscarlas.
- Un **ensamble** se alista solo cuando su trabajo está terminado; un **producto** no tiene
  trabajos —nadie lo fabrica, sale de bodega— y por eso su estado lo lleva el ítem.
