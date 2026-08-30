# Calidad

Rutas: `/calidad` (el tablero), `/calidad/unidades/{trabajo}` (la ficha de verificación)

Permiso: `ops.calidad`. Es aparte de `trabajos.ver` a propósito — quien revisa
calidad no siempre puede tocar la producción, y colgar el módulo del permiso de
trabajos obligaba a dar el uno para poder dar el otro.

## Para qué existe

Calidad es **el candado del despacho**: sin su visto no hay remisión. Ese candado
ya existía —`ops.calidad_aprobada_at`— pero se ponía con un botón: una decisión de
una sola pieza sobre la orden entera, con una foto y un comentario. En una orden de
diez puertas eso no dice nada. No queda registro de qué se revisó, ni de cuál unidad
salió mal, ni de qué le faltaba.

Aquí la revisión es **por unidad física y punto por punto**, que es como se revisa
de verdad: con la puerta enfrente.

## De dónde salen los puntos a revisar

De la plantilla, igual que los pasos de producción:

- `checklist_calidad` es la lista modelo. Cuelga del **ensamble** cuando es directo y
  de la **plantilla de ensamble** cuando el ensamble se fabrica por medidas. Se edita
  en el cotizador, junto a los pasos (ver [Plantillas de Ensamble](./plantillas-ensamble.md)).
- `op_item_trabajo_checks` es lo que de verdad se revisó de **una unidad**. Se copia
  de la plantilla al generar el trabajo y **se congela ahí**: cambiar la plantilla
  después no reescribe lo que alguien ya miró.

Cada punto tiene dos marcas que cambian cómo se comporta:

| Marca | Qué hace |
|---|---|
| **Exige foto** | No se puede dar por resuelto sin evidencia. Es el punto que después se discute con el cliente |
| **Crítico** | Si falla, bloquea. Uno no crítico se anota, se guarda, y no impide aprobar |

## El tablero

Una **ficha grande por unidad**, agrupadas por orden. Entra lo que ya se fabricó por
completo —el trabajo llegó al 100 %— de órdenes que todavía no se despachan; una
unidad a medio fabricar no se revisa porque no hay qué mirar.

Cada ficha lleva el número de la orden, el sufijo de la unidad (`−2` cuando la orden
pide varias), la descripción, **las medidas de esa unidad** y un botón grande por cada
punto de revisión. Sin las medidas, cinco fichas del mismo ensamble son cinco fichas
idénticas y no se sabe cuál se tiene en la mano.

- **Un toque** marca el punto como cumplido; otro lo deshace.
- **Pulsación larga** (o clic derecho) lo marca como falla.
- Si el punto **exige foto** y no la tiene, el toque abre la hoja de la foto en vez de
  marcarlo. Ahí se toma con la cámara o se sube un archivo — las dos, porque en planta
  se toma con el celular y en la oficina se sube la que ya alguien mandó.
- **«Terminar»** cierra la unidad completa: los puntos que quedaban sin mirar quedan en
  cumple. Existe porque revisar una puerta es mirarla entera de una vez, y obligar a
  marcar ocho casillas idénticas una por una hace que se marquen sin mirar — justo lo
  contrario de lo que sirve. Los que exigen foto no entran en el atajo: se piden uno por
  uno y después el cierre se completa solo.

El color de la izquierda y la insignia salen de la **fecha de entrega**, no de un campo
de prioridad: vencida en rojo, hoy o dentro de tres días en ámbar. Un campo que alguien
tiene que acordarse de marcar termina vacío en todas las órdenes menos en tres.

## La ficha de verificación

El número de la orden abre la ficha completa de esa unidad. Es para lo que hay que
mirar despacio, y trae todo lo que el tablero no puede:

- las **medidas** de la instancia, con la etiqueta que les puso la plantilla;
- la **receta congelada** —lo que se supone que lleva adentro—;
- **cómo se fabricó**: cada paso con su operario, su tiempo real y **las fotos que dejó**;
- los planos e imágenes del proyecto y las especificaciones tal como se escribieron;
- la lista de puntos con observación, evidencia y quién firmó cada uno.

Desde ahí se marca cumple o falla con la observación escrita, que es lo que hace falta
cuando el cliente reclama.

## Los dos cierres

**El de la unidad** es el botón «Terminar» de su ficha. **El de la orden** es
«Cerrar calidad de la orden», en el encabezado del grupo: sella `calidad_aprobada_at`
y avisa a administración y jefatura que ya se puede remisionar.

El sello **no se puede poner con puntos sin resolver**, y se dice con números cuántos
faltan — en una orden de diez puertas, «falta algo» no le sirve a nadie. Un punto
crítico que falló tampoco deja cerrar: eso se manda a reproceso.

El sello también se pone **solo** cuando la última unidad de la orden queda revisada.
Es el principio del sistema: cada acción real dispara el paso siguiente, y nadie
debería tener que apretar «aprobar» después de haber revisado punto por punto cada
unidad — ese botón terminaría siendo un trámite que se aprieta sin mirar.

Y se retira solo si algo se reabre: decir que una orden está aprobada cuando una de sus
unidades volvió a estar sin revisar es mentir en el único sitio donde no se puede.

## Lo que habilita: la remisión, unidad por unidad

Revisar una unidad la deja **lista para despachar**, sin esperar a las demás. De una orden de
diez puertas, las tres aprobadas se remisionan hoy: es lo que de verdad pasa cuando el cliente
se quiere llevar una parte del pedido. Ver [Logística](./logistica.md).

El sello de la orden (`calidad_aprobada_at`) sigue existiendo y sigue poniéndose solo, pero ya
no es lo que abre el despacho: es el resumen de que no queda nada por revisar.

## Reproceso *(completado el 30 ago 2026)*

«A reproceso» pide el motivo, quita el sello y deja la orden en `reproceso`. Hasta ahora eso
era **cambiar una etiqueta**: la orden decía «reproceso» y en planta no pasaba nada. Las
unidades seguían al 100 %, así que no salían como trabajo pendiente en ningún lado, y volver a
producción dependía de que alguien se acordara.

Ahora se **reabre el paso de entrega de cada unidad que falló**. Con eso la unidad baja del
100 %, vuelve a aparecer en [Trabajos](./trabajos-pasos.md) con su código QR y su insignia
«En reproceso», y sale del listado de lo que se puede despachar — que es lo que de verdad
significa «hay que rehacerla».

**Solo las que fallaron.** Una unidad que pasó la revisión no se toca: rehacer lo que estaba
bien es trabajo inventado. Cuáles son no necesita una columna que lo marque — la revisión ya lo
dice, punto por punto (`Op::unidadesEnReproceso()`). Un campo aparte sería una segunda versión
de la misma verdad, y las dos se separarían el día que alguien corrija una falla sin acordarse
de bajar la bandera.

**Las fallas no se borran.** La observación de qué salió mal es justo lo que quien corrige
necesita leer, y lo que hace falta si el cliente reclama. Calidad las cambia a cumplido cuando
vuelva a mirar la unidad.

**Y la orden vuelve sola a calidad** cuando planta termina de rehacerla: el mismo principio de
siempre, cada acción real dispara el paso siguiente. Antes ese regreso era manual y no había
nada que lo recordara.

> **Lo que el reproceso no hace, a propósito:** no devuelve la unidad de la bodega ni repone su
> material. Si ya se había entregado, la puerta existe en un estante y su material se gastó de
> verdad. Y **lo que consuma la reparación no queda registrado** — eso es un ajuste de
> inventario, y es una decisión de quien cuenta el estante, no un efecto secundario de marcar
> una falla.

## Lo que comparte con Trabajos

El tablero de [Trabajos](./trabajos-pasos.md) usa **la misma ficha**
(`resources/js/Components/FichaProceso.vue`), y es a propósito: quien avanza la
producción y quien la revisa hacen el mismo gesto —mirar la unidad, tocar el paso,
seguir—, así que la pantalla no tiene por qué ser distinta. Escribirla dos veces habría
hecho que se separaran al primer arreglo.
