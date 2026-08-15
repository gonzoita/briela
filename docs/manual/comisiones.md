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

Al configurar las comisiones de un producto, cada canal debe pagar **al menos** lo que paga el
anterior. Traer un cliente nuevo cuesta más que atender a uno que ya compra, así que el canal
de precio público suele llevar la comisión más alta. El sistema avisa si la escalera se rompe y
tiene un botón que propone un reparto que la respeta.

Ver [Segmentación y precios](./segmentacion-y-precios.md) y [Cotizaciones](./cotizaciones.md).
