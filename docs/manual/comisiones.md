# Comisiones del vendedor

Ruta: `/comisiones` · Permiso: `comisiones.ver`

Lo que se le debe a cada vendedor por lo que vendió.

## Sobre qué se calcula

**Sobre el excedente por encima del precio del canal base, no sobre la venta completa.**

El canal base es el piso de utilidad de la empresa. Vender a ese precio no genera comisión
porque no hay nada que repartir. Si el canal base de un producto está en $1.000.000 y se vende
por $1.200.000, el excedente es $200.000 — y la comisión es un porcentaje **de eso**.

Ese porcentaje se negocia al cotizar, con la barra del ítem, entre el mínimo y el máximo que
tenga configurado el canal del cliente. Cuanto más descuento da el vendedor, menos comisión le
queda: la barra baja sola cuando sube el descuento.

## El precio base se congela

Cada ítem de la cotización guarda el precio del canal base **del día en que se vendió**. Una
comisión que se liquida meses después tiene que calcularse con ese precio, no con el de hoy: si
el producto subió de precio en el medio, el vendedor no ganó más por eso.

## Lo que se ve

- **Resumen del mes**, con su PDF.
- **Detalle por documento**: qué ítems, a qué precio, qué excedente y cuánto de comisión.

## La escalera entre canales

Al configurar las comisiones de un producto o un ensamble, cada canal debe pagar **al menos la
misma plata** que el anterior. La razón no es solo el incentivo: vender un canal con su
descuento máximo deja exactamente el precio del canal de abajo sin descuento, y la misma venta
no puede pagar dos comisiones distintas.

Como cada canal cobra su porcentaje **sobre su propio excedente**, los porcentajes de dos
canales no se comparan crudos: el piso se convierte por la plata. Si Distribuidor tiene 49.000
de excedente y paga hasta el 50 % —24.500—, un canal con 102.000 de excedente arranca en
24.500, que sobre 102.000 es 24,02 %.

El sistema avisa si la escalera se rompe y tiene un botón, «Sugerir comisiones», que propone un
reparto que la respeta: hasta la mitad del excedente para el vendedor, y hasta el 70 % en el
canal de precio público, porque traer un cliente nuevo cuesta más que atender a uno que ya
compra. Es una sugerencia: los dos campos quedan editables.

> Hasta el 17 ago 2026 esa sugerencia sacaba el porcentaje que el excedente representa **del
> precio** —49.000 de 1.375.000 es 3,56 %— y ese porcentaje se cobraba después otra vez sobre el
> excedente. El vendedor terminaba con 1.137 de los 49.000 que había en juego. Los productos y
> ensambles configurados antes de esa fecha conservan los porcentajes viejos: hay que volver a
> pulsar «Sugerir comisiones» o escribirlos a mano.

Ver [Segmentación y precios](./segmentacion-y-precios.md) y [Cotizaciones](./cotizaciones.md).
